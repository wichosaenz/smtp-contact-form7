<?php
/**
 * Gmail API Transport — replaces SMTP with Google's REST API.
 *
 * Intercepts wp_mail() calls via the pre_wp_mail filter (WP 5.7+) and sends
 * the message through the Gmail API using OAuth 2.0 Bearer authentication.
 * Falls back to the SMTP transport if Gmail API is not configured.
 *
 * The message is built as a full RFC 2822 MIME string, base64url-encoded,
 * and POSTed to https://gmail.googleapis.com/upload/gmail/v1/users/me/messages/send
 *
 * @package CF7_SMTP_Bridge
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CF7_SMTP_Gmail_API {

	/**
	 * Gmail API send endpoint.
	 *
	 * @var string
	 */
	private const GMAIL_SEND_ENDPOINT = 'https://gmail.googleapis.com/upload/gmail/v1/users/me/messages/send';

	/**
	 * Logger instance.
	 *
	 * @var CF7_SMTP_Logger
	 */
	private CF7_SMTP_Logger $logger;

	/**
	 * OAuth handler.
	 *
	 * @var CF7_SMTP_OAuth
	 */
	private CF7_SMTP_OAuth $oauth;

	/**
	 * Constructor.
	 *
	 * @param CF7_SMTP_Logger $logger Logger instance.
	 * @param CF7_SMTP_OAuth  $oauth  OAuth handler instance.
	 */
	public function __construct( CF7_SMTP_Logger $logger, CF7_SMTP_OAuth $oauth ) {
		$this->logger = $logger;
		$this->oauth  = $oauth;
	}

	/**
	 * Register hooks.
	 *
	 * Uses pre_wp_mail (WP 5.7+) to completely replace the default sending
	 * mechanism when Gmail API transport is selected.
	 *
	 * @return void
	 */
	public function init(): void {
		$settings = CF7_SMTP_Settings::get_settings();

		if ( 'gmail_api' !== ( $settings['transport'] ?? 'smtp' ) ) {
			return;
		}

		add_filter( 'pre_wp_mail', array( $this, 'intercept_wp_mail' ), 10, 2 );
	}

	/**
	 * Intercept wp_mail() and send via Gmail API instead.
	 *
	 * @param null|bool $return Short-circuit return value. Null means not handled.
	 * @param array     $atts   {
	 *     Array of wp_mail() arguments.
	 *
	 *     @type string|string[] $to          Recipients.
	 *     @type string          $subject     Email subject.
	 *     @type string          $message     Email body.
	 *     @type string|string[] $headers     Additional headers.
	 *     @type string|string[] $attachments Paths to files to attach.
	 * }
	 * @return null|bool True on success, false on failure, null to fall through.
	 */
	public function intercept_wp_mail( $return, array $atts ): null|bool {
		$to          = $atts['to'] ?? '';
		$subject     = $atts['subject'] ?? '';
		$message     = $atts['message'] ?? '';
		$headers     = $atts['headers'] ?? '';
		$attachments = $atts['attachments'] ?? array();

		$this->log( 'Gmail API: Intercepting wp_mail() for: ' . ( is_array( $to ) ? implode( ', ', $to ) : $to ) );

		// Obtain a valid access token (auto-refreshes if needed).
		$access_token = $this->oauth->get_access_token();

		if ( false === $access_token ) {
			$this->logger->error( 'Gmail API: Failed to obtain access token. Email NOT sent.' );

			// Fire wp_mail_failed for consistency.
			do_action( 'wp_mail_failed', new \WP_Error(
				'gmail_api_auth_failed',
				__( 'Gmail API: Could not obtain OAuth access token.', 'cf7-smtp-bridge' )
			) );

			return false;
		}

		// Build the MIME message.
		$mime = $this->build_mime_message( $to, $subject, $message, $headers, $attachments );

		if ( false === $mime ) {
			return false;
		}

		// Base64url-encode the MIME message.
		$encoded = $this->base64url_encode( $mime );

		// Send via Gmail API.
		$result = $this->send_via_api( $encoded, $access_token );

		return $result;
	}

	/**
	 * Build a complete RFC 2822 MIME message string.
	 *
	 * @param string|string[] $to          Recipients.
	 * @param string          $subject     Subject line.
	 * @param string          $message     Body content.
	 * @param string|string[] $headers     Additional headers.
	 * @param string|string[] $attachments File paths to attach.
	 * @return string|false MIME message string, or false on failure.
	 */
	private function build_mime_message(
		string|array $to,
		string $subject,
		string $message,
		string|array $headers,
		string|array $attachments
	): string|false {
		$settings = CF7_SMTP_Settings::get_settings();

		// Normalize recipients.
		if ( is_array( $to ) ) {
			$to = implode( ', ', $to );
		}

		// Parse headers.
		$parsed = $this->parse_headers( $headers );

		// Determine content type.
		$content_type = $parsed['content_type'] ?? 'text/plain';
		$charset      = $parsed['charset'] ?? 'UTF-8';

		// From address.
		$from_email = $parsed['from_email'] ?? ( $settings['from_email'] ?: $settings['gmail_sender_email'] );
		$from_name  = $parsed['from_name'] ?? ( $settings['from_name'] ?: get_bloginfo( 'name' ) );

		if ( empty( $from_email ) ) {
			$this->logger->error( 'Gmail API: No from email configured.' );
			return false;
		}

		$from = ! empty( $from_name )
			? sprintf( '=?UTF-8?B?%s?= <%s>', base64_encode( $from_name ), $from_email )
			: $from_email;

		// Build the MIME message.
		$boundary    = 'cf7_smtp_' . wp_generate_password( 24, false );
		$has_attach  = ! empty( $attachments ) && is_array( $attachments );
		$mime_parts  = array();

		// Required headers.
		$mime_parts[] = 'From: ' . $from;
		$mime_parts[] = 'To: ' . $to;
		$mime_parts[] = 'Subject: =?UTF-8?B?' . base64_encode( $subject ) . '?=';
		$mime_parts[] = 'MIME-Version: 1.0';
		$mime_parts[] = 'Date: ' . gmdate( 'r' );
		$mime_parts[] = 'Message-ID: <' . wp_generate_uuid4() . '@' . wp_parse_url( home_url(), PHP_URL_HOST ) . '>';

		// CC / BCC / Reply-To from parsed headers.
		foreach ( array( 'cc', 'bcc', 'reply-to' ) as $header_key ) {
			if ( ! empty( $parsed[ $header_key ] ) ) {
				$header_name  = str_replace( '-', '-', ucwords( $header_key, '-' ) );
				$mime_parts[] = $header_name . ': ' . $parsed[ $header_key ];
			}
		}

		if ( $has_attach ) {
			// Multipart/mixed for attachments.
			$mime_parts[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
			$mime_parts[] = '';
			$mime_parts[] = '--' . $boundary;
			$mime_parts[] = 'Content-Type: ' . $content_type . '; charset=' . $charset;
			$mime_parts[] = 'Content-Transfer-Encoding: base64';
			$mime_parts[] = '';
			$mime_parts[] = chunk_split( base64_encode( $message ) );

			// Attach files.
			$attachments_arr = is_string( $attachments ) ? array( $attachments ) : $attachments;

			foreach ( $attachments_arr as $file_path ) {
				if ( ! is_file( $file_path ) || ! is_readable( $file_path ) ) {
					$this->log( 'Gmail API: Skipping unreadable attachment: ' . $file_path );
					continue;
				}

				$filename  = basename( $file_path );
				$mime_type = wp_check_filetype( $filename )['type'] ?? 'application/octet-stream';
				$content   = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

				if ( false === $content ) {
					continue;
				}

				$mime_parts[] = '--' . $boundary;
				$mime_parts[] = 'Content-Type: ' . $mime_type . '; name="' . $filename . '"';
				$mime_parts[] = 'Content-Disposition: attachment; filename="' . $filename . '"';
				$mime_parts[] = 'Content-Transfer-Encoding: base64';
				$mime_parts[] = '';
				$mime_parts[] = chunk_split( base64_encode( $content ) );
			}

			$mime_parts[] = '--' . $boundary . '--';
		} else {
			// Simple message (no attachments).
			$mime_parts[] = 'Content-Type: ' . $content_type . '; charset=' . $charset;
			$mime_parts[] = 'Content-Transfer-Encoding: base64';
			$mime_parts[] = '';
			$mime_parts[] = chunk_split( base64_encode( $message ) );
		}

		return implode( "\r\n", $mime_parts );
	}

	/**
	 * Parse email headers string/array into structured data.
	 *
	 * @param string|string[] $headers Raw headers.
	 * @return array Parsed headers with keys: content_type, charset, from_email, from_name, cc, bcc, reply-to.
	 */
	private function parse_headers( string|array $headers ): array {
		$parsed = array();

		if ( is_string( $headers ) ) {
			$headers = explode( "\n", str_replace( "\r\n", "\n", $headers ) );
		}

		if ( ! is_array( $headers ) ) {
			return $parsed;
		}

		foreach ( $headers as $header ) {
			$header = trim( $header );

			if ( empty( $header ) ) {
				continue;
			}

			$parts = explode( ':', $header, 2 );

			if ( count( $parts ) < 2 ) {
				continue;
			}

			$name  = strtolower( trim( $parts[0] ) );
			$value = trim( $parts[1] );

			switch ( $name ) {
				case 'content-type':
					if ( str_contains( $value, ';' ) ) {
						$ct_parts = explode( ';', $value );
						$parsed['content_type'] = trim( $ct_parts[0] );

						foreach ( array_slice( $ct_parts, 1 ) as $param ) {
							$param = trim( $param );
							if ( stripos( $param, 'charset=' ) === 0 ) {
								$parsed['charset'] = trim( substr( $param, 8 ), '"' );
							}
						}
					} else {
						$parsed['content_type'] = $value;
					}
					break;

				case 'from':
					if ( preg_match( '/(.+)<(.+)>/', $value, $matches ) ) {
						$parsed['from_name']  = trim( $matches[1], ' "' );
						$parsed['from_email'] = trim( $matches[2] );
					} else {
						$parsed['from_email'] = trim( $value );
					}
					break;

				case 'cc':
				case 'bcc':
				case 'reply-to':
					$parsed[ $name ] = $value;
					break;
			}
		}

		return $parsed;
	}

	/**
	 * Send the encoded message via Gmail REST API.
	 *
	 * @param string $encoded_message Base64url-encoded MIME message.
	 * @param string $access_token    Valid OAuth access token.
	 * @return bool True on success, false on failure.
	 */
	private function send_via_api( string $encoded_message, string $access_token ): bool {
		$this->log( 'Gmail API: Sending message via REST API...' );

		$response = wp_remote_post( self::GMAIL_SEND_ENDPOINT, array(
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $access_token,
				'Content-Type'  => 'message/rfc822',
			),
			'body'    => $this->base64url_decode( $encoded_message ),
		) );

		if ( is_wp_error( $response ) ) {
			$error_msg = 'Gmail API: HTTP error: ' . $response->get_error_message();
			$this->logger->error( $error_msg );

			do_action( 'wp_mail_failed', new \WP_Error(
				'gmail_api_http_error',
				$error_msg
			) );

			return false;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		if ( $status_code >= 200 && $status_code < 300 ) {
			$data       = json_decode( $body, true );
			$message_id = $data['id'] ?? 'unknown';
			$this->log( 'Gmail API: Message sent successfully. Gmail Message ID: ' . $message_id );
			return true;
		}

		// Handle error responses.
		$data       = json_decode( $body, true );
		$error_msg  = $data['error']['message'] ?? $body;
		$error_code = $data['error']['code'] ?? $status_code;

		$this->logger->error( sprintf(
			'Gmail API: Send failed (HTTP %d, code %s): %s',
			$status_code,
			$error_code,
			$error_msg
		) );

		do_action( 'wp_mail_failed', new \WP_Error(
			'gmail_api_send_failed',
			sprintf(
				/* translators: 1: HTTP status code, 2: Error message */
				__( 'Gmail API send failed (HTTP %1$d): %2$s', 'cf7-smtp-bridge' ),
				$status_code,
				$error_msg
			)
		) );

		return false;
	}

	/**
	 * Base64url encode (RFC 4648 Section 5).
	 *
	 * Gmail API requires base64url encoding (no padding, URL-safe chars).
	 *
	 * @param string $data Raw data to encode.
	 * @return string Base64url-encoded string.
	 */
	private function base64url_encode( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * Base64url decode (RFC 4648 Section 5).
	 *
	 * @param string $data Base64url-encoded string.
	 * @return string Decoded data.
	 */
	private function base64url_decode( string $data ): string {
		$padded = $data . str_repeat( '=', ( 4 - strlen( $data ) % 4 ) % 4 );
		return base64_decode( strtr( $padded, '-_', '+/' ) );
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
