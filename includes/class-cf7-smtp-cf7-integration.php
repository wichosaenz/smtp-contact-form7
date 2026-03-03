<?php
/**
 * Contact Form 7 integration for auto-reply and CC/BCC.
 *
 * Hooks into wpcf7_before_send_mail to:
 * 1. Add CC or BCC headers to the main CF7 notification email.
 * 2. Send an auto-reply confirmation to the form submitter.
 *
 * @package CF7_SMTP_Bridge
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CF7_SMTP_CF7_Integration {

	/**
	 * Logger instance.
	 *
	 * @var CF7_SMTP_Logger
	 */
	private CF7_SMTP_Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param CF7_SMTP_Logger $logger Logger instance.
	 */
	public function __construct( CF7_SMTP_Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'wpcf7_before_send_mail', array( $this, 'process_submission' ), 10, 3 );
	}

	/**
	 * Process the CF7 submission: add CC/BCC and send auto-reply.
	 *
	 * @param WPCF7_ContactForm $contact_form The CF7 form instance.
	 * @param bool              $abort        Whether to abort sending (passed by reference).
	 * @param WPCF7_Submission  $submission   The submission instance.
	 * @return void
	 */
	public function process_submission( $contact_form, &$abort, $submission ): void {
		$settings = CF7_SMTP_Settings::get_settings();

		if ( ! $submission ) {
			return;
		}

		$posted_data = $submission->get_posted_data();

		// ── Add CC/BCC to the main CF7 mail ───────────────────────
		if ( '1' === $settings['enable_cc'] ) {
			$this->add_copy_header( $contact_form, $settings );
		}

		// ── Send auto-reply to the user ───────────────────────────
		if ( '1' === $settings['enable_autoreply'] ) {
			$this->send_autoreply( $contact_form, $posted_data, $settings );
		}
	}

	/**
	 * Add CC or BCC header to the main CF7 mail properties.
	 *
	 * @param WPCF7_ContactForm $contact_form The form instance.
	 * @param array             $settings     Plugin settings.
	 * @return void
	 */
	private function add_copy_header( $contact_form, array $settings ): void {
		$cc_email = ! empty( $settings['cc_email'] )
			? $settings['cc_email']
			: get_option( 'admin_email' );

		if ( empty( $cc_email ) || ! is_email( $cc_email ) ) {
			return;
		}

		$mail = $contact_form->prop( 'mail' );

		if ( ! is_array( $mail ) ) {
			return;
		}

		$header_type = 'bcc' === $settings['cc_type'] ? 'Bcc' : 'Cc';
		$new_header  = $header_type . ': ' . $cc_email;

		// Append to existing additional_headers.
		$existing_headers = $mail['additional_headers'] ?? '';
		if ( '' !== $existing_headers ) {
			$existing_headers .= "\n";
		}
		$existing_headers .= $new_header;

		$mail['additional_headers'] = $existing_headers;

		$contact_form->set_properties( array( 'mail' => $mail ) );

		$this->log( sprintf( 'Added %s header: %s', $header_type, $cc_email ) );
	}

	/**
	 * Send an auto-reply confirmation email to the form submitter.
	 *
	 * @param WPCF7_ContactForm $contact_form The form instance.
	 * @param array             $posted_data  The submitted form data.
	 * @param array             $settings     Plugin settings.
	 * @return void
	 */
	private function send_autoreply( $contact_form, array $posted_data, array $settings ): void {
		// Extract the user's email from posted data.
		$user_email = $this->get_user_email( $posted_data );

		if ( empty( $user_email ) || ! is_email( $user_email ) ) {
			$this->log( 'Auto-reply skipped: no valid [your-email] field found in submission.' );
			return;
		}

		// Replace CF7 mail-tags in the subject and body.
		$subject = $this->replace_mail_tags( $settings['autoreply_subject'], $posted_data );
		$body    = $this->replace_mail_tags( $settings['autoreply_body'], $posted_data );

		// Build headers.
		$headers = array(
			'Content-Type: text/plain; charset=UTF-8',
		);

		// Use the configured "From" if available.
		$from_email = ! empty( $settings['from_email'] ) ? $settings['from_email'] : '';
		$from_name  = ! empty( $settings['from_name'] ) ? $settings['from_name'] : get_bloginfo( 'name' );

		if ( $from_email ) {
			$headers[] = sprintf( 'From: %s <%s>', $from_name, $from_email );
		}

		$result = wp_mail( $user_email, $subject, $body, $headers );

		if ( $result ) {
			$this->log( 'Auto-reply sent to: ' . $user_email );
		} else {
			$this->logger->error( 'Auto-reply failed for: ' . $user_email );
		}
	}

	/**
	 * Extract the user's email from posted data.
	 *
	 * Looks for common CF7 field names in order of preference.
	 *
	 * @param array $posted_data The posted data.
	 * @return string The email address, or empty string if not found.
	 */
	private function get_user_email( array $posted_data ): string {
		$email_fields = array( 'your-email', 'email', 'your_email', 'user-email', 'user_email' );

		foreach ( $email_fields as $field ) {
			if ( ! empty( $posted_data[ $field ] ) ) {
				$value = is_array( $posted_data[ $field ] ) ? $posted_data[ $field ][0] : $posted_data[ $field ];
				$value = sanitize_email( trim( $value ) );

				if ( is_email( $value ) ) {
					return $value;
				}
			}
		}

		return '';
	}

	/**
	 * Replace CF7-style mail-tags [field-name] with posted data values.
	 *
	 * @param string $text        The text containing mail-tags.
	 * @param array  $posted_data The posted form data.
	 * @return string Text with tags replaced.
	 */
	private function replace_mail_tags( string $text, array $posted_data ): string {
		return preg_replace_callback(
			'/\[([a-zA-Z0-9_\-]+)\]/',
			function ( $matches ) use ( $posted_data ) {
				$tag = $matches[1];

				if ( isset( $posted_data[ $tag ] ) ) {
					$value = $posted_data[ $tag ];
					return is_array( $value ) ? implode( ', ', $value ) : $value;
				}

				// Return the original tag if no matching data.
				return $matches[0];
			},
			$text
		);
	}

	/**
	 * Write to log if logging is enabled.
	 *
	 * @param string $message The message.
	 * @return void
	 */
	private function log( string $message ): void {
		$settings = CF7_SMTP_Settings::get_settings();

		if ( '1' === $settings['enable_logging'] ) {
			$this->logger->info( $message );
		}
	}
}
