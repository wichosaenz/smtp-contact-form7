<?php
/**
 * OAuth 2.0 handler for Google Gmail API authentication.
 *
 * Manages the full OAuth 2.0 lifecycle: storing credentials, refreshing
 * access tokens automatically using the refresh token, and providing
 * a valid Bearer token for Gmail API requests.
 *
 * @package CF7_SMTP_Bridge
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CF7_SMTP_OAuth {

	/**
	 * Google OAuth 2.0 token endpoint.
	 *
	 * @var string
	 */
	private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

	/**
	 * Option key for storing OAuth tokens in wp_options.
	 *
	 * @var string
	 */
	public const TOKEN_OPTION = 'cf7_smtp_bridge_oauth_tokens';

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
	 * Get a valid access token, refreshing it automatically if expired.
	 *
	 * @return string|false The access token, or false on failure.
	 */
	public function get_access_token(): string|false {
		$tokens = $this->get_stored_tokens();

		// If we have a valid (non-expired) access token, return it.
		if ( ! empty( $tokens['access_token'] ) && ! $this->is_token_expired( $tokens ) ) {
			return $tokens['access_token'];
		}

		// Try to refresh using the stored refresh token.
		$settings = CF7_SMTP_Settings::get_settings();

		if ( empty( $settings['gmail_refresh_token'] ) ) {
			$this->logger->error( 'OAuth: No refresh token configured. Cannot obtain access token.' );
			return false;
		}

		return $this->refresh_access_token( $settings );
	}

	/**
	 * Refresh the access token using the refresh token.
	 *
	 * @param array $settings Plugin settings containing OAuth credentials.
	 * @return string|false New access token, or false on failure.
	 */
	private function refresh_access_token( array $settings ): string|false {
		$client_id     = $settings['gmail_client_id'] ?? '';
		$client_secret = $settings['gmail_client_secret'] ?? '';
		$refresh_token = $settings['gmail_refresh_token'] ?? '';

		if ( empty( $client_id ) || empty( $client_secret ) || empty( $refresh_token ) ) {
			$this->logger->error( 'OAuth: Missing credentials (client_id, client_secret, or refresh_token).' );
			return false;
		}

		$this->log( 'OAuth: Refreshing access token...' );

		$response = wp_remote_post( self::TOKEN_ENDPOINT, array(
			'timeout' => 30,
			'body'    => array(
				'grant_type'    => 'refresh_token',
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
				'refresh_token' => $refresh_token,
			),
		) );

		if ( is_wp_error( $response ) ) {
			$this->logger->error( 'OAuth: Token refresh HTTP error: ' . $response->get_error_message() );
			return false;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$data        = json_decode( $body, true );

		if ( 200 !== $status_code || empty( $data['access_token'] ) ) {
			$error_desc = $data['error_description'] ?? ( $data['error'] ?? 'Unknown error' );
			$this->logger->error( sprintf(
				'OAuth: Token refresh failed (HTTP %d): %s',
				$status_code,
				$error_desc
			) );
			return false;
		}

		// Store the new tokens.
		$token_data = array(
			'access_token' => $data['access_token'],
			'expires_in'   => (int) ( $data['expires_in'] ?? 3600 ),
			'token_type'   => $data['token_type'] ?? 'Bearer',
			'created_at'   => time(),
		);

		// If Google issues a new refresh token, store it too.
		if ( ! empty( $data['refresh_token'] ) ) {
			$token_data['refresh_token'] = $data['refresh_token'];
		}

		update_option( self::TOKEN_OPTION, $token_data, false );

		$this->log( sprintf(
			'OAuth: Access token refreshed successfully. Expires in %d seconds.',
			$token_data['expires_in']
		) );

		return $token_data['access_token'];
	}

	/**
	 * Check if the stored access token is expired.
	 *
	 * Uses a 5-minute buffer to prevent edge-case failures.
	 *
	 * @param array $tokens Stored token data.
	 * @return bool True if expired or about to expire.
	 */
	private function is_token_expired( array $tokens ): bool {
		$created_at = (int) ( $tokens['created_at'] ?? 0 );
		$expires_in = (int) ( $tokens['expires_in'] ?? 3600 );
		$buffer     = 300; // 5-minute safety buffer.

		return ( time() >= ( $created_at + $expires_in - $buffer ) );
	}

	/**
	 * Get stored OAuth tokens from the database.
	 *
	 * @return array
	 */
	public function get_stored_tokens(): array {
		$tokens = get_option( self::TOKEN_OPTION, array() );

		if ( ! is_array( $tokens ) ) {
			return array();
		}

		return $tokens;
	}

	/**
	 * Clear all stored OAuth tokens.
	 *
	 * @return void
	 */
	public function clear_tokens(): void {
		delete_option( self::TOKEN_OPTION );
		$this->log( 'OAuth: Stored tokens cleared.' );
	}

	/**
	 * Get the authorization URL for the initial OAuth consent flow.
	 *
	 * @param string $client_id    The Google Client ID.
	 * @param string $redirect_uri The redirect URI.
	 * @return string The full authorization URL.
	 */
	public static function get_authorization_url( string $client_id, string $redirect_uri ): string {
		return add_query_arg( array(
			'client_id'     => $client_id,
			'redirect_uri'  => $redirect_uri,
			'response_type' => 'code',
			'scope'         => 'https://www.googleapis.com/auth/gmail.send',
			'access_type'   => 'offline',
			'prompt'        => 'consent',
		), 'https://accounts.google.com/o/oauth2/v2/auth' );
	}

	/**
	 * Exchange an authorization code for tokens.
	 *
	 * @param string $code         The authorization code from Google.
	 * @param string $client_id    Google Client ID.
	 * @param string $client_secret Google Client Secret.
	 * @param string $redirect_uri  The redirect URI used in the auth request.
	 * @return array|false Token data on success, false on failure.
	 */
	public function exchange_code_for_tokens(
		string $code,
		string $client_id,
		string $client_secret,
		string $redirect_uri
	): array|false {
		$this->log( 'OAuth: Exchanging authorization code for tokens...' );

		$response = wp_remote_post( self::TOKEN_ENDPOINT, array(
			'timeout' => 30,
			'body'    => array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
				'redirect_uri'  => $redirect_uri,
			),
		) );

		if ( is_wp_error( $response ) ) {
			$this->logger->error( 'OAuth: Code exchange HTTP error: ' . $response->get_error_message() );
			return false;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$data        = json_decode( $body, true );

		if ( 200 !== $status_code || empty( $data['access_token'] ) ) {
			$error_desc = $data['error_description'] ?? ( $data['error'] ?? 'Unknown error' );
			$this->logger->error( sprintf(
				'OAuth: Code exchange failed (HTTP %d): %s',
				$status_code,
				$error_desc
			) );
			return false;
		}

		$token_data = array(
			'access_token'  => $data['access_token'],
			'expires_in'    => (int) ( $data['expires_in'] ?? 3600 ),
			'token_type'    => $data['token_type'] ?? 'Bearer',
			'refresh_token' => $data['refresh_token'] ?? '',
			'created_at'    => time(),
		);

		update_option( self::TOKEN_OPTION, $token_data, false );

		$this->log( 'OAuth: Tokens obtained and stored successfully.' );

		return $token_data;
	}

	/**
	 * Get a summary of the current token status for display in admin.
	 *
	 * @return array{connected: bool, expires_at: string, has_refresh: bool}
	 */
	public function get_token_status(): array {
		$tokens = $this->get_stored_tokens();

		if ( empty( $tokens['access_token'] ) ) {
			return array(
				'connected'  => false,
				'expires_at' => '',
				'has_refresh' => false,
			);
		}

		$created_at = (int) ( $tokens['created_at'] ?? 0 );
		$expires_in = (int) ( $tokens['expires_in'] ?? 3600 );
		$expires_at = $created_at + $expires_in;
		$is_expired = $this->is_token_expired( $tokens );

		return array(
			'connected'   => ! $is_expired,
			'expires_at'  => wp_date( 'Y-m-d H:i:s', $expires_at ),
			'has_refresh'  => ! empty( $tokens['refresh_token'] ),
			'expired'      => $is_expired,
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
