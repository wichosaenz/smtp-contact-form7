<?php
/**
 * SMTP Mailer — intercepts PHPMailer via the phpmailer_init action.
 *
 * Reconfigures the WordPress PHPMailer instance to use the SMTP credentials
 * stored in the plugin settings. Also overrides the From email/name when
 * configured and registers a failure listener for logging.
 *
 * @package CF7_SMTP_Bridge
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CF7_SMTP_Mailer {

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
		add_action( 'phpmailer_init', array( $this, 'configure_phpmailer' ), 10, 1 );
		add_action( 'wp_mail_failed', array( $this, 'log_mail_failure' ), 10, 1 );

		// Override From headers globally when configured.
		add_filter( 'wp_mail_from', array( $this, 'override_from_email' ) );
		add_filter( 'wp_mail_from_name', array( $this, 'override_from_name' ) );
	}

	/**
	 * Reconfigure the PHPMailer instance to use SMTP.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer The PHPMailer instance.
	 * @return void
	 */
	public function configure_phpmailer( $phpmailer ): void {
		$settings = CF7_SMTP_Settings::get_settings();

		// Bail if no host is configured.
		if ( empty( $settings['smtp_host'] ) ) {
			return;
		}

		$phpmailer->isSMTP();
		$phpmailer->Host       = $settings['smtp_host'];
		$phpmailer->Port       = (int) $settings['smtp_port'];
		$phpmailer->SMTPAuth   = true;
		$phpmailer->Username   = $settings['smtp_username'];
		$phpmailer->Password   = $settings['smtp_password'];

		// Encryption.
		if ( 'none' === $settings['smtp_encryption'] ) {
			$phpmailer->SMTPSecure  = '';
			$phpmailer->SMTPAutoTLS = false;
		} else {
			$phpmailer->SMTPSecure = $settings['smtp_encryption'];
		}

		// Debug output when logging is enabled (captured to error_log).
		if ( '1' === $settings['enable_logging'] ) {
			$phpmailer->SMTPDebug = 2;
			$phpmailer->Debugoutput = function ( $str, $level ) {
				$this->logger->info( 'PHPMailer [' . $level . ']: ' . trim( $str ) );
			};
		}

		$this->log( 'SMTP configured: ' . $settings['smtp_host'] . ':' . $settings['smtp_port'] . ' (' . $settings['smtp_encryption'] . ')' );
	}

	/**
	 * Override the From email address globally.
	 *
	 * @param string $from_email Default from email.
	 * @return string
	 */
	public function override_from_email( string $from_email ): string {
		$settings = CF7_SMTP_Settings::get_settings();

		if ( ! empty( $settings['from_email'] ) ) {
			return $settings['from_email'];
		}

		return $from_email;
	}

	/**
	 * Override the From name globally.
	 *
	 * @param string $from_name Default from name.
	 * @return string
	 */
	public function override_from_name( string $from_name ): string {
		$settings = CF7_SMTP_Settings::get_settings();

		if ( ! empty( $settings['from_name'] ) ) {
			return $settings['from_name'];
		}

		return $from_name;
	}

	/**
	 * Log wp_mail failures.
	 *
	 * @param \WP_Error $wp_error The error object.
	 * @return void
	 */
	public function log_mail_failure( $wp_error ): void {
		if ( ! is_wp_error( $wp_error ) ) {
			return;
		}

		$this->logger->error(
			'wp_mail failed: ' . $wp_error->get_error_message()
			. ' | Data: ' . wp_json_encode( $wp_error->get_error_data() )
		);
	}

	/**
	 * Write a log entry when logging is enabled.
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
