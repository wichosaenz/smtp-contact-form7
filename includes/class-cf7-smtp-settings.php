<?php
/**
 * Admin settings page for SMTP / Gmail API configuration.
 *
 * Registers a settings page under Settings > CF7 SMTP Bridge with fields
 * for transport selection (SMTP or Gmail API), SMTP credentials, Gmail API
 * OAuth 2.0 credentials, and CF7-specific auto-reply / CC options.
 *
 * @package CF7_SMTP_Bridge
 * @since   1.0.0
 * @since   2.0.0 Added Gmail API transport and OAuth 2.0 support.
 * @since   2.1.0 Alias/masked sender support with improved field descriptions.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CF7_SMTP_Settings {

	/**
	 * Option group name for Settings API.
	 *
	 * @var string
	 */
	public const OPTION_GROUP = 'cf7_smtp_bridge_options';

	/**
	 * Option key used in wp_options table.
	 *
	 * @var string
	 */
	public const OPTION_NAME = 'cf7_smtp_bridge_settings';

	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	public const PAGE_SLUG = 'cf7-smtp-bridge';

	/**
	 * Nonce action for the settings form.
	 *
	 * @var string
	 */
	private const NONCE_ACTION = 'cf7_smtp_bridge_save';

	/**
	 * Logger instance.
	 *
	 * @var CF7_SMTP_Logger
	 */
	private CF7_SMTP_Logger $logger;

	/**
	 * OAuth instance.
	 *
	 * @var CF7_SMTP_OAuth|null
	 */
	private ?CF7_SMTP_OAuth $oauth;

	/**
	 * Constructor.
	 *
	 * @param CF7_SMTP_Logger    $logger Logger instance.
	 * @param CF7_SMTP_OAuth|null $oauth  OAuth instance (optional).
	 */
	public function __construct( CF7_SMTP_Logger $logger, ?CF7_SMTP_OAuth $oauth = null ) {
		$this->logger = $logger;
		$this->oauth  = $oauth;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'handle_oauth_callback' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );
		add_action( 'wp_ajax_cf7_smtp_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_cf7_smtp_clear_log', array( $this, 'ajax_clear_log' ) );
		add_action( 'wp_ajax_cf7_smtp_revoke_oauth', array( $this, 'ajax_revoke_oauth' ) );
	}

	/**
	 * Get the default settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_defaults(): array {
		return array(
			// Transport selection.
			'transport'       => 'smtp',

			// SMTP settings.
			'smtp_host'       => '',
			'smtp_port'       => '587',
			'smtp_encryption' => 'tls',
			'smtp_username'   => '',
			'smtp_password'   => '',

			// Gmail API settings.
			'gmail_client_id'     => '',
			'gmail_client_secret' => '',
			'gmail_refresh_token' => '',
			'gmail_sender_email'  => '',

			// Sender identity.
			'from_email'      => '',
			'from_name'       => '',

			// Auto-reply.
			'enable_autoreply'    => '0',
			'autoreply_subject'   => __( 'We have received your message', 'cf7-smtp-bridge' ),
			'autoreply_body'      => __( "Hello [your-name],\n\nThank you for contacting us. We have received your message and will respond shortly.\n\nBest regards.", 'cf7-smtp-bridge' ),

			// CC / BCC.
			'enable_cc'           => '0',
			'cc_email'            => '',
			'cc_type'             => 'bcc',

			// Logging.
			'enable_logging'      => '1',
		);
	}

	/**
	 * Retrieve plugin settings merged with defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_settings(): array {
		$saved = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		$settings = wp_parse_args( $saved, self::get_defaults() );

		// Decrypt sensitive fields on read.
		if ( ! empty( $settings['smtp_password'] ) ) {
			$settings['smtp_password'] = CF7_SMTP_Encryption::decrypt( $settings['smtp_password'] );
		}
		if ( ! empty( $settings['gmail_client_secret'] ) ) {
			$settings['gmail_client_secret'] = CF7_SMTP_Encryption::decrypt( $settings['gmail_client_secret'] );
		}
		if ( ! empty( $settings['gmail_refresh_token'] ) ) {
			$settings['gmail_refresh_token'] = CF7_SMTP_Encryption::decrypt( $settings['gmail_refresh_token'] );
		}

		return $settings;
	}

	/**
	 * Add the settings page under the Settings menu.
	 *
	 * @return void
	 */
	public function add_settings_page(): void {
		add_options_page(
			__( 'CF7 SMTP Bridge', 'cf7-smtp-bridge' ),
			__( 'CF7 SMTP Bridge', 'cf7-smtp-bridge' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register settings, sections, and fields via Settings API.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
			)
		);

		// ── Transport Section ─────────────────────────────────────────
		add_settings_section(
			'cf7_smtp_section_transport',
			__( 'Mail Transport', 'cf7-smtp-bridge' ),
			array( $this, 'render_section_transport' ),
			self::PAGE_SLUG
		);

		$this->add_field( 'transport', __( 'Transport Method', 'cf7-smtp-bridge' ), 'render_field_transport', 'cf7_smtp_section_transport' );

		// ── SMTP Section ──────────────────────────────────────────────
		add_settings_section(
			'cf7_smtp_section_smtp',
			__( 'SMTP Server Configuration', 'cf7-smtp-bridge' ),
			array( $this, 'render_section_smtp' ),
			self::PAGE_SLUG
		);

		$this->add_field( 'smtp_host', __( 'SMTP Host', 'cf7-smtp-bridge' ), 'render_field_text', 'cf7_smtp_section_smtp', array(
			'placeholder' => 'smtp.gmail.com',
			'description' => __( 'SMTP server address (e.g., smtp.gmail.com).', 'cf7-smtp-bridge' ),
		) );

		$this->add_field( 'smtp_port', __( 'SMTP Port', 'cf7-smtp-bridge' ), 'render_field_text', 'cf7_smtp_section_smtp', array(
			'placeholder' => '587',
			'description' => __( 'Common ports: 587 (TLS), 465 (SSL), 25 (none).', 'cf7-smtp-bridge' ),
			'type'        => 'number',
		) );

		$this->add_field( 'smtp_encryption', __( 'Encryption', 'cf7-smtp-bridge' ), 'render_field_select', 'cf7_smtp_section_smtp', array(
			'options' => array(
				'tls'  => 'TLS',
				'ssl'  => 'SSL',
				'none' => __( 'None', 'cf7-smtp-bridge' ),
			),
		) );

		$this->add_field( 'smtp_username', __( 'Username', 'cf7-smtp-bridge' ), 'render_field_text', 'cf7_smtp_section_smtp', array(
			'placeholder' => 'user@example.com',
			'description' => __( 'SMTP authentication username (usually your email).', 'cf7-smtp-bridge' ),
		) );

		$this->add_field( 'smtp_password', __( 'Password', 'cf7-smtp-bridge' ), 'render_field_password', 'cf7_smtp_section_smtp', array(
			'description' => __( 'Stored with AES-256 encryption using your WordPress security keys.', 'cf7-smtp-bridge' ),
		) );

		// ── Gmail API Section ─────────────────────────────────────────
		add_settings_section(
			'cf7_smtp_section_gmail',
			__( 'Gmail API Configuration (OAuth 2.0)', 'cf7-smtp-bridge' ),
			array( $this, 'render_section_gmail' ),
			self::PAGE_SLUG
		);

		$this->add_field( 'gmail_client_id', __( 'Client ID', 'cf7-smtp-bridge' ), 'render_field_text', 'cf7_smtp_section_gmail', array(
			'placeholder' => 'xxxxx.apps.googleusercontent.com',
			'description' => __( 'OAuth 2.0 Client ID from Google Cloud Console.', 'cf7-smtp-bridge' ),
			'class'       => 'large-text',
		) );

		$this->add_field( 'gmail_client_secret', __( 'Client Secret', 'cf7-smtp-bridge' ), 'render_field_password', 'cf7_smtp_section_gmail', array(
			'description' => __( 'OAuth 2.0 Client Secret. Stored encrypted with AES-256.', 'cf7-smtp-bridge' ),
		) );

		$this->add_field( 'gmail_refresh_token', __( 'Refresh Token', 'cf7-smtp-bridge' ), 'render_field_password', 'cf7_smtp_section_gmail', array(
			'description' => __( 'Persistent refresh token. You can paste it manually or authorize below.', 'cf7-smtp-bridge' ),
		) );

		$this->add_field( 'gmail_sender_email', __( 'Sender Email (or Alias)', 'cf7-smtp-bridge' ), 'render_field_text', 'cf7_smtp_section_gmail', array(
			'placeholder' => 'expansion@theeverestgroup.mx',
			'description' => __( 'The email address used in the From header. You can enter an alias (e.g., expansion@yourdomain.com) instead of the primary authenticated account. The alias must be previously configured in your Google Workspace account under Settings > Accounts > "Send mail as".', 'cf7-smtp-bridge' ),
			'type'        => 'email',
		) );

		$this->add_field( 'gmail_oauth_status', __( 'OAuth Status', 'cf7-smtp-bridge' ), 'render_field_oauth_status', 'cf7_smtp_section_gmail' );

		// ── From Section ──────────────────────────────────────────────
		add_settings_section(
			'cf7_smtp_section_from',
			__( 'Sender Identity', 'cf7-smtp-bridge' ),
			array( $this, 'render_section_from' ),
			self::PAGE_SLUG
		);

		$this->add_field( 'from_email', __( 'From Email', 'cf7-smtp-bridge' ), 'render_field_text', 'cf7_smtp_section_from', array(
			'placeholder' => 'noreply@yourdomain.com',
			'description' => __( 'The "From" address for outgoing emails. Leave blank to use transport default.', 'cf7-smtp-bridge' ),
			'type'        => 'email',
		) );

		$this->add_field( 'from_name', __( 'From Name', 'cf7-smtp-bridge' ), 'render_field_text', 'cf7_smtp_section_from', array(
			'placeholder' => get_bloginfo( 'name' ),
			'description' => __( 'The sender name. Leave blank to use the site name.', 'cf7-smtp-bridge' ),
		) );

		// ── CF7 Auto-Reply Section ────────────────────────────────────
		add_settings_section(
			'cf7_smtp_section_autoreply',
			__( 'Auto-Reply (Confirmation Email)', 'cf7-smtp-bridge' ),
			array( $this, 'render_section_autoreply' ),
			self::PAGE_SLUG
		);

		$this->add_field( 'enable_autoreply', __( 'Enable Auto-Reply', 'cf7-smtp-bridge' ), 'render_field_checkbox', 'cf7_smtp_section_autoreply', array(
			'label' => __( 'Send a confirmation email to the user after form submission.', 'cf7-smtp-bridge' ),
		) );

		$this->add_field( 'autoreply_subject', __( 'Subject', 'cf7-smtp-bridge' ), 'render_field_text', 'cf7_smtp_section_autoreply', array(
			'description' => __( 'You can use CF7 mail-tags like [your-name].', 'cf7-smtp-bridge' ),
			'class'       => 'large-text',
		) );

		$this->add_field( 'autoreply_body', __( 'Body', 'cf7-smtp-bridge' ), 'render_field_textarea', 'cf7_smtp_section_autoreply', array(
			'description' => __( 'Supports CF7 mail-tags: [your-name], [your-email], [your-subject], [your-message], etc.', 'cf7-smtp-bridge' ),
		) );

		// ── CC / BCC Section ──────────────────────────────────────────
		add_settings_section(
			'cf7_smtp_section_cc',
			__( 'Internal Notification (CC/BCC)', 'cf7-smtp-bridge' ),
			array( $this, 'render_section_cc' ),
			self::PAGE_SLUG
		);

		$this->add_field( 'enable_cc', __( 'Enable CC/BCC', 'cf7-smtp-bridge' ), 'render_field_checkbox', 'cf7_smtp_section_cc', array(
			'label' => __( 'Send a copy of every CF7 submission to an internal address.', 'cf7-smtp-bridge' ),
		) );

		$this->add_field( 'cc_email', __( 'Notification Email', 'cf7-smtp-bridge' ), 'render_field_text', 'cf7_smtp_section_cc', array(
			'placeholder' => get_option( 'admin_email' ),
			'description' => __( 'The address that receives the copy. Defaults to admin email.', 'cf7-smtp-bridge' ),
			'type'        => 'email',
		) );

		$this->add_field( 'cc_type', __( 'Copy Type', 'cf7-smtp-bridge' ), 'render_field_select', 'cf7_smtp_section_cc', array(
			'options' => array(
				'bcc' => __( 'BCC (hidden)', 'cf7-smtp-bridge' ),
				'cc'  => __( 'CC (visible)', 'cf7-smtp-bridge' ),
			),
		) );

		// ── Logging Section ───────────────────────────────────────────
		add_settings_section(
			'cf7_smtp_section_logging',
			__( 'Debug Log', 'cf7-smtp-bridge' ),
			array( $this, 'render_section_logging' ),
			self::PAGE_SLUG
		);

		$this->add_field( 'enable_logging', __( 'Enable Logging', 'cf7-smtp-bridge' ), 'render_field_checkbox', 'cf7_smtp_section_logging', array(
			'label' => __( 'Log mail transport events and errors for debugging.', 'cf7-smtp-bridge' ),
		) );
	}

	/**
	 * Enqueue admin styles on the plugin page only.
	 *
	 * @param string $hook The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_styles( string $hook ): void {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'cf7-smtp-bridge-admin',
			plugin_dir_url( CF7_SMTP_BRIDGE_FILE ) . 'assets/css/admin.css',
			array(),
			CF7_SMTP_BRIDGE_VERSION
		);
	}

	/**
	 * Shortcut to register a settings field.
	 *
	 * @param string $id       Field ID (also the settings key).
	 * @param string $title    Field label.
	 * @param string $callback Render method name.
	 * @param string $section  Section ID.
	 * @param array  $args     Extra arguments for the callback.
	 * @return void
	 */
	private function add_field( string $id, string $title, string $callback, string $section, array $args = array() ): void {
		$args['id'] = $id;

		add_settings_field(
			$id,
			$title,
			array( $this, $callback ),
			self::PAGE_SLUG,
			$section,
			$args
		);
	}

	// ─── Section Descriptions ──────────────────────────────────────

	/**
	 * Render Transport section description.
	 *
	 * @return void
	 */
	public function render_section_transport(): void {
		echo '<p>' . esc_html__( 'Choose how WordPress sends emails. Gmail API uses OAuth 2.0 and bypasses SMTP entirely. SMTP uses traditional server authentication.', 'cf7-smtp-bridge' ) . '</p>';
	}

	/**
	 * Render SMTP section description.
	 *
	 * @return void
	 */
	public function render_section_smtp(): void {
		$settings = self::get_settings();
		$class    = 'gmail_api' === $settings['transport'] ? ' style="opacity:0.5;"' : '';
		echo '<p' . $class . '>' . esc_html__( 'Configure the SMTP server that will be used to send all WordPress emails.', 'cf7-smtp-bridge' ) . '</p>';
	}

	/**
	 * Render Gmail API section description.
	 *
	 * @return void
	 */
	public function render_section_gmail(): void {
		$settings = self::get_settings();
		$class    = 'smtp' === $settings['transport'] ? ' style="opacity:0.5;"' : '';
		echo '<p' . $class . '>' . esc_html__( 'Configure Gmail API with OAuth 2.0 for reliable transactional email delivery via Google. Requires a project in Google Cloud Console with the Gmail API enabled.', 'cf7-smtp-bridge' ) . '</p>';
	}

	/**
	 * Render From section description.
	 *
	 * @return void
	 */
	public function render_section_from(): void {
		echo '<p>' . esc_html__( 'Customize the sender name and email address for outgoing messages.', 'cf7-smtp-bridge' ) . '</p>';
	}

	/**
	 * Render auto-reply section description.
	 *
	 * @return void
	 */
	public function render_section_autoreply(): void {
		echo '<p>' . esc_html__( 'Automatically send a confirmation email to the visitor who submitted a Contact Form 7 form. The form must contain a field named [your-email].', 'cf7-smtp-bridge' ) . '</p>';
	}

	/**
	 * Render CC section description.
	 *
	 * @return void
	 */
	public function render_section_cc(): void {
		echo '<p>' . esc_html__( 'Send an automatic copy of every CF7 submission to an internal team address.', 'cf7-smtp-bridge' ) . '</p>';
	}

	/**
	 * Render logging section description.
	 *
	 * @return void
	 */
	public function render_section_logging(): void {
		echo '<p>' . esc_html__( 'Enable detailed logging to help troubleshoot mail transport issues.', 'cf7-smtp-bridge' ) . '</p>';
	}

	// ─── Field Renderers ───────────────────────────────────────────

	/**
	 * Render the transport method selector (radio buttons).
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public function render_field_transport( array $args ): void {
		$settings  = self::get_settings();
		$transport = $settings['transport'] ?? 'smtp';
		$name      = self::OPTION_NAME . '[transport]';
		?>
		<fieldset>
			<label style="display:block; margin-bottom:8px;">
				<input type="radio" name="<?php echo esc_attr( $name ); ?>" value="smtp"
					<?php checked( $transport, 'smtp' ); ?> />
				<strong><?php esc_html_e( 'SMTP', 'cf7-smtp-bridge' ); ?></strong>
				&mdash; <?php esc_html_e( 'Traditional SMTP server (PHPMailer).', 'cf7-smtp-bridge' ); ?>
			</label>
			<label style="display:block; margin-bottom:8px;">
				<input type="radio" name="<?php echo esc_attr( $name ); ?>" value="gmail_api"
					<?php checked( $transport, 'gmail_api' ); ?> />
				<strong><?php esc_html_e( 'Gmail API', 'cf7-smtp-bridge' ); ?></strong>
				&mdash; <?php esc_html_e( 'Google Gmail REST API with OAuth 2.0 (recommended).', 'cf7-smtp-bridge' ); ?>
			</label>
		</fieldset>
		<p class="description">
			<?php esc_html_e( 'Gmail API is recommended for reliability and avoids SMTP port blocking.', 'cf7-smtp-bridge' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the OAuth status indicator and authorize/revoke buttons.
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public function render_field_oauth_status( array $args ): void {
		$settings = self::get_settings();
		$status   = null;

		if ( $this->oauth ) {
			$status = $this->oauth->get_token_status();
		}

		if ( $status && $status['connected'] ) : ?>
			<span class="cf7-smtp-oauth-badge cf7-smtp-oauth-connected">
				&#10004; <?php esc_html_e( 'Connected', 'cf7-smtp-bridge' ); ?>
			</span>
			<span class="description" style="margin-left:10px;">
				<?php
				printf(
					/* translators: %s: token expiration date */
					esc_html__( 'Token expires: %s (auto-renews)', 'cf7-smtp-bridge' ),
					esc_html( $status['expires_at'] )
				);
				?>
			</span>
			<br /><br />
			<button type="button" id="cf7-smtp-revoke-oauth" class="button button-link-delete">
				<?php esc_html_e( 'Revoke Authorization', 'cf7-smtp-bridge' ); ?>
			</button>
		<?php elseif ( $status && $status['expired'] ?? false ) : ?>
			<span class="cf7-smtp-oauth-badge cf7-smtp-oauth-expired">
				&#9888; <?php esc_html_e( 'Token expired — will auto-refresh on next send', 'cf7-smtp-bridge' ); ?>
			</span>
		<?php else : ?>
			<span class="cf7-smtp-oauth-badge cf7-smtp-oauth-disconnected">
				&#10060; <?php esc_html_e( 'Not connected', 'cf7-smtp-bridge' ); ?>
			</span>
			<?php
			// Show authorize button if client_id is configured.
			if ( ! empty( $settings['gmail_client_id'] ) ) :
				$redirect_uri = admin_url( 'options-general.php?page=' . self::PAGE_SLUG );
				$auth_url     = CF7_SMTP_OAuth::get_authorization_url( $settings['gmail_client_id'], $redirect_uri );
				?>
				<br /><br />
				<a href="<?php echo esc_url( $auth_url ); ?>" class="button button-primary">
					<?php esc_html_e( 'Authorize with Google', 'cf7-smtp-bridge' ); ?>
				</a>
				<p class="description">
					<?php esc_html_e( 'Opens the Google consent screen. After approval, you will be redirected back here and the tokens will be saved automatically.', 'cf7-smtp-bridge' ); ?>
				</p>
			<?php else : ?>
				<p class="description">
					<?php esc_html_e( 'Enter your Client ID and Client Secret above, save settings, then click "Authorize with Google".', 'cf7-smtp-bridge' ); ?>
				</p>
			<?php endif; ?>
		<?php endif;
	}

	/**
	 * Render a text input field.
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public function render_field_text( array $args ): void {
		$settings    = self::get_settings();
		$id          = $args['id'];
		$value       = $settings[ $id ] ?? '';
		$placeholder = $args['placeholder'] ?? '';
		$description = $args['description'] ?? '';
		$type        = $args['type'] ?? 'text';
		$class       = $args['class'] ?? 'regular-text';

		printf(
			'<input type="%s" id="%s" name="%s[%s]" value="%s" placeholder="%s" class="%s" />',
			esc_attr( $type ),
			esc_attr( $id ),
			esc_attr( self::OPTION_NAME ),
			esc_attr( $id ),
			esc_attr( $value ),
			esc_attr( $placeholder ),
			esc_attr( $class )
		);

		if ( $description ) {
			printf( '<p class="description">%s</p>', esc_html( $description ) );
		}
	}

	/**
	 * Render a password input field (value is never output to HTML).
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public function render_field_password( array $args ): void {
		$settings    = self::get_settings();
		$id          = $args['id'];
		$has_value   = ! empty( $settings[ $id ] );
		$description = $args['description'] ?? '';

		printf(
			'<input type="password" id="%s" name="%s[%s]" value="" placeholder="%s" class="regular-text" autocomplete="new-password" />',
			esc_attr( $id ),
			esc_attr( self::OPTION_NAME ),
			esc_attr( $id ),
			$has_value ? esc_attr__( '••••••••  (saved — leave blank to keep)', 'cf7-smtp-bridge' ) : ''
		);

		if ( 'smtp_password' === $id && ! CF7_SMTP_Encryption::is_openssl_available() ) {
			echo '<p class="description" style="color:#d63638;">';
			esc_html_e( 'Warning: OpenSSL is not available. Secrets will be stored with base64 encoding only (not encrypted).', 'cf7-smtp-bridge' );
			echo '</p>';
		}

		if ( $description ) {
			printf( '<p class="description">%s</p>', esc_html( $description ) );
		}
	}

	/**
	 * Render a select dropdown field.
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public function render_field_select( array $args ): void {
		$settings = self::get_settings();
		$id       = $args['id'];
		$value    = $settings[ $id ] ?? '';
		$options  = $args['options'] ?? array();

		printf(
			'<select id="%s" name="%s[%s]">',
			esc_attr( $id ),
			esc_attr( self::OPTION_NAME ),
			esc_attr( $id )
		);

		foreach ( $options as $key => $label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $key ),
				selected( $value, $key, false ),
				esc_html( $label )
			);
		}

		echo '</select>';
	}

	/**
	 * Render a checkbox field.
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public function render_field_checkbox( array $args ): void {
		$settings = self::get_settings();
		$id       = $args['id'];
		$value    = $settings[ $id ] ?? '0';
		$label    = $args['label'] ?? '';

		printf(
			'<label><input type="checkbox" id="%s" name="%s[%s]" value="1" %s /> %s</label>',
			esc_attr( $id ),
			esc_attr( self::OPTION_NAME ),
			esc_attr( $id ),
			checked( '1', $value, false ),
			esc_html( $label )
		);
	}

	/**
	 * Render a textarea field.
	 *
	 * @param array $args Field arguments.
	 * @return void
	 */
	public function render_field_textarea( array $args ): void {
		$settings    = self::get_settings();
		$id          = $args['id'];
		$value       = $settings[ $id ] ?? '';
		$description = $args['description'] ?? '';

		printf(
			'<textarea id="%s" name="%s[%s]" rows="8" class="large-text code">%s</textarea>',
			esc_attr( $id ),
			esc_attr( self::OPTION_NAME ),
			esc_attr( $id ),
			esc_textarea( $value )
		);

		if ( $description ) {
			printf( '<p class="description">%s</p>', esc_html( $description ) );
		}
	}

	// ─── Sanitization ──────────────────────────────────────────────

	/**
	 * Sanitize all settings before saving.
	 *
	 * @param array|null $input Raw form input.
	 * @return array Sanitized settings.
	 */
	public function sanitize_settings( ?array $input ): array {
		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$existing  = get_option( self::OPTION_NAME, array() );
		$sanitized = array();

		// Transport.
		$sanitized['transport'] = in_array( ( $input['transport'] ?? '' ), array( 'smtp', 'gmail_api' ), true )
			? $input['transport']
			: 'smtp';

		// SMTP fields.
		$sanitized['smtp_host']       = sanitize_text_field( $input['smtp_host'] ?? '' );
		$sanitized['smtp_port']       = absint( $input['smtp_port'] ?? 587 );
		$sanitized['smtp_encryption'] = in_array( ( $input['smtp_encryption'] ?? '' ), array( 'tls', 'ssl', 'none' ), true )
			? $input['smtp_encryption']
			: 'tls';
		$sanitized['smtp_username']   = sanitize_email( $input['smtp_username'] ?? '' );

		// SMTP Password: encrypt before saving; keep existing if left blank.
		$raw_password = $input['smtp_password'] ?? '';
		if ( '' !== $raw_password ) {
			$sanitized['smtp_password'] = CF7_SMTP_Encryption::encrypt( $raw_password );
		} else {
			$sanitized['smtp_password'] = $existing['smtp_password'] ?? '';
		}

		// Gmail API fields.
		$sanitized['gmail_client_id'] = sanitize_text_field( $input['gmail_client_id'] ?? '' );

		$raw_secret = $input['gmail_client_secret'] ?? '';
		if ( '' !== $raw_secret ) {
			$sanitized['gmail_client_secret'] = CF7_SMTP_Encryption::encrypt( $raw_secret );
		} else {
			$sanitized['gmail_client_secret'] = $existing['gmail_client_secret'] ?? '';
		}

		$raw_refresh = $input['gmail_refresh_token'] ?? '';
		if ( '' !== $raw_refresh ) {
			$sanitized['gmail_refresh_token'] = CF7_SMTP_Encryption::encrypt( $raw_refresh );
		} else {
			$sanitized['gmail_refresh_token'] = $existing['gmail_refresh_token'] ?? '';
		}

		$sanitized['gmail_sender_email'] = sanitize_email( $input['gmail_sender_email'] ?? '' );

		// Sender identity.
		$sanitized['from_email'] = sanitize_email( $input['from_email'] ?? '' );
		$sanitized['from_name']  = sanitize_text_field( $input['from_name'] ?? '' );

		// Auto-reply.
		$sanitized['enable_autoreply']  = ! empty( $input['enable_autoreply'] ) ? '1' : '0';
		$sanitized['autoreply_subject'] = sanitize_text_field( $input['autoreply_subject'] ?? '' );
		$sanitized['autoreply_body']    = sanitize_textarea_field( $input['autoreply_body'] ?? '' );

		// CC / BCC.
		$sanitized['enable_cc'] = ! empty( $input['enable_cc'] ) ? '1' : '0';
		$sanitized['cc_email']  = sanitize_email( $input['cc_email'] ?? '' );
		$sanitized['cc_type']   = in_array( ( $input['cc_type'] ?? '' ), array( 'cc', 'bcc' ), true )
			? $input['cc_type']
			: 'bcc';

		// Logging.
		$sanitized['enable_logging'] = ! empty( $input['enable_logging'] ) ? '1' : '0';

		return $sanitized;
	}

	// ─── OAuth Callback Handler ────────────────────────────────────

	/**
	 * Handle the OAuth callback from Google after user authorization.
	 *
	 * Intercepts the admin page load when a 'code' parameter is present.
	 *
	 * @return void
	 */
	public function handle_oauth_callback(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- OAuth callback from Google, no nonce possible.
		if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] ) {
			return;
		}

		if ( ! isset( $_GET['code'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! $this->oauth ) {
			return;
		}

		$code     = sanitize_text_field( wp_unslash( $_GET['code'] ) );
		$settings = self::get_settings();

		$redirect_uri = admin_url( 'options-general.php?page=' . self::PAGE_SLUG );

		$tokens = $this->oauth->exchange_code_for_tokens(
			$code,
			$settings['gmail_client_id'],
			$settings['gmail_client_secret'],
			$redirect_uri
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( false !== $tokens && ! empty( $tokens['refresh_token'] ) ) {
			// Save the refresh token into settings for persistence.
			$raw_settings = get_option( self::OPTION_NAME, array() );
			$raw_settings['gmail_refresh_token'] = CF7_SMTP_Encryption::encrypt( $tokens['refresh_token'] );
			update_option( self::OPTION_NAME, $raw_settings );

			add_settings_error(
				self::OPTION_GROUP,
				'oauth_success',
				__( 'Google OAuth authorization successful! Tokens have been saved.', 'cf7-smtp-bridge' ),
				'success'
			);
		} else {
			add_settings_error(
				self::OPTION_GROUP,
				'oauth_failed',
				__( 'Google OAuth authorization failed. Check the debug log for details.', 'cf7-smtp-bridge' ),
				'error'
			);
		}

		// Redirect to remove 'code' from URL and prevent re-processing.
		$clean_url = admin_url( 'options-general.php?page=' . self::PAGE_SLUG . '&settings-updated=true' );
		wp_safe_redirect( $clean_url );
		exit;
	}

	// ─── Page Renderer ─────────────────────────────────────────────

	/**
	 * Render the settings page HTML.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = self::get_settings();
		?>
		<div class="wrap cf7-smtp-wrap">
			<h1>
				<?php esc_html_e( 'CF7 SMTP Bridge Settings', 'cf7-smtp-bridge' ); ?>
				<span class="cf7-smtp-version">v<?php echo esc_html( CF7_SMTP_BRIDGE_VERSION ); ?></span>
			</h1>

			<?php
			// Show which transport is active.
			$active_transport = 'gmail_api' === $settings['transport']
				? __( 'Gmail API (OAuth 2.0)', 'cf7-smtp-bridge' )
				: __( 'SMTP', 'cf7-smtp-bridge' );
			?>
			<div class="cf7-smtp-active-transport">
				<?php
				printf(
					/* translators: %s: active transport name */
					esc_html__( 'Active transport: %s', 'cf7-smtp-bridge' ),
					'<strong>' . esc_html( $active_transport ) . '</strong>'
				);
				?>
			</div>

			<?php settings_errors(); ?>

			<form method="post" action="options.php">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button( __( 'Save Settings', 'cf7-smtp-bridge' ) );
				?>
			</form>

			<hr />

			<!-- Test Connection Button -->
			<h2><?php esc_html_e( 'Test Mail Transport', 'cf7-smtp-bridge' ); ?></h2>
			<p class="description">
				<?php
				printf(
					/* translators: %s: transport name */
					esc_html__( 'Send a test email using the current transport (%s) to the admin address.', 'cf7-smtp-bridge' ),
					esc_html( $active_transport )
				);
				?>
			</p>
			<p>
				<button type="button" id="cf7-smtp-test-btn" class="button button-secondary">
					<?php esc_html_e( 'Send Test Email', 'cf7-smtp-bridge' ); ?>
				</button>
				<span id="cf7-smtp-test-result" style="margin-left:10px;"></span>
			</p>

			<?php if ( '1' === $settings['enable_logging'] ) : ?>
				<hr />

				<h2><?php esc_html_e( 'Recent Log Entries', 'cf7-smtp-bridge' ); ?></h2>
				<p>
					<button type="button" id="cf7-smtp-clear-log" class="button button-link-delete">
						<?php esc_html_e( 'Clear Log', 'cf7-smtp-bridge' ); ?>
					</button>
				</p>
				<pre class="cf7-smtp-log"><?php echo esc_html( $this->logger->read( 50 ) ); ?></pre>
			<?php endif; ?>
		</div>

		<script>
		(function(){
			/* ── Test Email ────────────────────────────────── */
			var testBtn   = document.getElementById('cf7-smtp-test-btn');
			var testResult = document.getElementById('cf7-smtp-test-result');

			if (testBtn) {
				testBtn.addEventListener('click', function(){
					testBtn.disabled = true;
					testResult.textContent = '<?php echo esc_js( __( 'Sending...', 'cf7-smtp-bridge' ) ); ?>';

					var xhr = new XMLHttpRequest();
					xhr.open('POST', '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>');
					xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
					xhr.onload = function(){
						testBtn.disabled = false;
						try {
							var res = JSON.parse(xhr.responseText);
							testResult.textContent = res.data || '';
							testResult.style.color = res.success ? '#00a32a' : '#d63638';
						} catch(e) {
							testResult.textContent = '<?php echo esc_js( __( 'Unexpected response.', 'cf7-smtp-bridge' ) ); ?>';
							testResult.style.color = '#d63638';
						}
					};
					xhr.onerror = function(){
						testBtn.disabled = false;
						testResult.textContent = '<?php echo esc_js( __( 'Network error.', 'cf7-smtp-bridge' ) ); ?>';
						testResult.style.color = '#d63638';
					};
					xhr.send('action=cf7_smtp_test_connection&_wpnonce=<?php echo esc_js( wp_create_nonce( 'cf7_smtp_test' ) ); ?>');
				});
			}

			/* ── Clear Log ─────────────────────────────────── */
			var clearBtn = document.getElementById('cf7-smtp-clear-log');
			if (clearBtn) {
				clearBtn.addEventListener('click', function(){
					if (!confirm('<?php echo esc_js( __( 'Clear the entire log?', 'cf7-smtp-bridge' ) ); ?>')) return;

					var xhr = new XMLHttpRequest();
					xhr.open('POST', '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>');
					xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
					xhr.onload = function(){
						var pre = document.querySelector('.cf7-smtp-log');
						if (pre) pre.textContent = '';
					};
					xhr.send('action=cf7_smtp_clear_log&_wpnonce=<?php echo esc_js( wp_create_nonce( 'cf7_smtp_clear_log' ) ); ?>');
				});
			}

			/* ── Revoke OAuth ──────────────────────────────── */
			var revokeBtn = document.getElementById('cf7-smtp-revoke-oauth');
			if (revokeBtn) {
				revokeBtn.addEventListener('click', function(){
					if (!confirm('<?php echo esc_js( __( 'Revoke the stored OAuth tokens? You will need to re-authorize.', 'cf7-smtp-bridge' ) ); ?>')) return;

					revokeBtn.disabled = true;

					var xhr = new XMLHttpRequest();
					xhr.open('POST', '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>');
					xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
					xhr.onload = function(){ location.reload(); };
					xhr.send('action=cf7_smtp_revoke_oauth&_wpnonce=<?php echo esc_js( wp_create_nonce( 'cf7_smtp_revoke_oauth' ) ); ?>');
				});
			}

			/* ── Transport Visibility Toggle ───────────────── */
			var transportRadios = document.querySelectorAll('input[name="<?php echo esc_js( self::OPTION_NAME ); ?>[transport]"]');
			function toggleSections() {
				var val = document.querySelector('input[name="<?php echo esc_js( self::OPTION_NAME ); ?>[transport]"]:checked');
				if (!val) return;
				var isGmail = val.value === 'gmail_api';

				var smtpSection = document.getElementById('cf7_smtp_section_smtp');
				var gmailSection = document.getElementById('cf7_smtp_section_gmail');

				// Find parent <table> elements for each section.
				if (smtpSection) {
					var smtpTable = smtpSection.nextElementSibling;
					if (smtpTable) smtpTable.style.opacity = isGmail ? '0.4' : '1';
					smtpSection.style.opacity = isGmail ? '0.5' : '1';
				}
				if (gmailSection) {
					var gmailTable = gmailSection.nextElementSibling;
					if (gmailTable) gmailTable.style.opacity = isGmail ? '1' : '0.4';
					gmailSection.style.opacity = isGmail ? '1' : '0.5';
				}
			}

			transportRadios.forEach(function(r) {
				r.addEventListener('change', toggleSections);
			});

			toggleSections();
		})();
		</script>
		<?php
	}

	// ─── AJAX Handlers ─────────────────────────────────────────────

	/**
	 * AJAX handler: send a test email.
	 *
	 * @return void
	 */
	public function ajax_test_connection(): void {
		check_ajax_referer( 'cf7_smtp_test', '_wpnonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized.', 'cf7-smtp-bridge' ) );
		}

		$settings  = self::get_settings();
		$transport = 'gmail_api' === $settings['transport'] ? 'Gmail API' : 'SMTP';
		$to        = get_option( 'admin_email' );
		$subject   = sprintf(
			/* translators: %s: transport name */
			__( '[CF7 SMTP Bridge] Test Email via %s', 'cf7-smtp-bridge' ),
			$transport
		);
		$body    = sprintf(
			/* translators: %s: transport name */
			__( 'This is a test email from CF7 SMTP Bridge using the %s transport. If you see this, your settings are working correctly.', 'cf7-smtp-bridge' ),
			$transport
		);
		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

		$result = wp_mail( $to, $subject, $body, $headers );

		if ( $result ) {
			wp_send_json_success(
				/* translators: 1: admin email, 2: transport name */
				sprintf( __( 'Test email sent successfully to %1$s via %2$s.', 'cf7-smtp-bridge' ), $to, $transport )
			);
		} else {
			wp_send_json_error(
				/* translators: %s: transport name */
				sprintf( __( 'Failed to send test email via %s. Check the debug log for details.', 'cf7-smtp-bridge' ), $transport )
			);
		}
	}

	/**
	 * AJAX handler: clear the log file.
	 *
	 * @return void
	 */
	public function ajax_clear_log(): void {
		check_ajax_referer( 'cf7_smtp_clear_log', '_wpnonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized.', 'cf7-smtp-bridge' ) );
		}

		$this->logger->clear();
		wp_send_json_success();
	}

	/**
	 * AJAX handler: revoke OAuth tokens.
	 *
	 * @return void
	 */
	public function ajax_revoke_oauth(): void {
		check_ajax_referer( 'cf7_smtp_revoke_oauth', '_wpnonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized.', 'cf7-smtp-bridge' ) );
		}

		if ( $this->oauth ) {
			$this->oauth->clear_tokens();
		}

		// Also clear refresh token from settings.
		$raw_settings = get_option( self::OPTION_NAME, array() );
		$raw_settings['gmail_refresh_token'] = '';
		update_option( self::OPTION_NAME, $raw_settings );

		wp_send_json_success();
	}
}
