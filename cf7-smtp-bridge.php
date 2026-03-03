<?php
/**
 * Plugin Name: CF7 SMTP Bridge
 * Plugin URI:  https://github.com/wichosaenz/smtp-contact-form7
 * Description: Advanced mail transport bridge for WordPress and Contact Form 7. Supports SMTP and Gmail API (OAuth 2.0) with auto-reply confirmations and CC/BCC internal notifications.
 * Version:     2.1.0
 * Requires at least: 6.0
 * Requires PHP: 8.2
 * Author:      The Everest Group
 * Author URI:  https://github.com/wichosaenz
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: cf7-smtp-bridge
 * Domain Path: /languages
 *
 * @package CF7_SMTP_Bridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin version constant.
 *
 * @var string
 */
define( 'CF7_SMTP_BRIDGE_VERSION', '2.1.0' );

/**
 * Plugin file path constant.
 *
 * @var string
 */
define( 'CF7_SMTP_BRIDGE_FILE', __FILE__ );

/**
 * Plugin directory path constant.
 *
 * @var string
 */
define( 'CF7_SMTP_BRIDGE_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Main plugin class — singleton that bootstraps all components.
 *
 * @since 1.0.0
 * @since 2.0.0 Added Gmail API transport and OAuth 2.0 support.
 * @since 2.1.0 Alias/masked sender support for Gmail API.
 */
final class CF7_SMTP_Bridge {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Logger instance shared across components.
	 *
	 * @var CF7_SMTP_Logger
	 */
	private CF7_SMTP_Logger $logger;

	/**
	 * OAuth handler instance.
	 *
	 * @var CF7_SMTP_OAuth
	 */
	private CF7_SMTP_OAuth $oauth;

	/**
	 * Get the singleton instance.
	 *
	 * @return self
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor — private to enforce singleton.
	 */
	private function __construct() {
		$this->load_dependencies();

		$this->logger = new CF7_SMTP_Logger();
		$this->oauth  = new CF7_SMTP_OAuth( $this->logger );

		$this->init_components();
		$this->register_activation_hooks();
	}

	/**
	 * Include all class files.
	 *
	 * @return void
	 */
	private function load_dependencies(): void {
		$includes = CF7_SMTP_BRIDGE_DIR . 'includes/';

		require_once $includes . 'class-cf7-smtp-encryption.php';
		require_once $includes . 'class-cf7-smtp-logger.php';
		require_once $includes . 'class-cf7-smtp-oauth.php';
		require_once $includes . 'class-cf7-smtp-settings.php';
		require_once $includes . 'class-cf7-smtp-mailer.php';
		require_once $includes . 'class-cf7-smtp-gmail-api.php';
		require_once $includes . 'class-cf7-smtp-cf7-integration.php';
	}

	/**
	 * Initialize plugin components.
	 *
	 * @return void
	 */
	private function init_components(): void {
		// Admin settings page.
		if ( is_admin() ) {
			$settings = new CF7_SMTP_Settings( $this->logger, $this->oauth );
			$settings->init();
		}

		// Initialize the correct transport based on settings.
		$plugin_settings = CF7_SMTP_Settings::get_settings();

		if ( 'gmail_api' === ( $plugin_settings['transport'] ?? 'smtp' ) ) {
			// Gmail API transport (replaces wp_mail via pre_wp_mail).
			$gmail_api = new CF7_SMTP_Gmail_API( $this->logger, $this->oauth );
			$gmail_api->init();
		} else {
			// Legacy SMTP transport (reconfigures PHPMailer).
			$mailer = new CF7_SMTP_Mailer( $this->logger );
			$mailer->init();
		}

		// CF7 integration — works with either transport.
		$cf7_integration = new CF7_SMTP_CF7_Integration( $this->logger );
		$cf7_integration->init();
	}

	/**
	 * Register activation and deactivation hooks.
	 *
	 * @return void
	 */
	private function register_activation_hooks(): void {
		register_activation_hook( CF7_SMTP_BRIDGE_FILE, array( $this, 'activate' ) );
		register_deactivation_hook( CF7_SMTP_BRIDGE_FILE, array( $this, 'deactivate' ) );
	}

	/**
	 * Plugin activation callback.
	 *
	 * @return void
	 */
	public function activate(): void {
		// Set default options if they don't exist.
		if ( false === get_option( CF7_SMTP_Settings::OPTION_NAME ) ) {
			add_option( CF7_SMTP_Settings::OPTION_NAME, CF7_SMTP_Settings::get_defaults() );
		}

		$this->logger->info( 'CF7 SMTP Bridge v' . CF7_SMTP_BRIDGE_VERSION . ' activated.' );
	}

	/**
	 * Plugin deactivation callback.
	 *
	 * @return void
	 */
	public function deactivate(): void {
		$this->logger->info( 'CF7 SMTP Bridge deactivated.' );
	}

	/**
	 * Prevent cloning.
	 */
	private function __clone() {}

	/**
	 * Prevent unserialization.
	 *
	 * @throws \LogicException Always.
	 */
	public function __wakeup(): void {
		throw new \LogicException( 'Cannot unserialize singleton.' );
	}
}

/**
 * Add a "Settings" link on the Plugins page.
 *
 * @param array $links Existing plugin action links.
 * @return array Modified links.
 */
function cf7_smtp_bridge_action_links( array $links ): array {
	$settings_link = sprintf(
		'<a href="%s">%s</a>',
		esc_url( admin_url( 'options-general.php?page=' . CF7_SMTP_Settings::PAGE_SLUG ) ),
		esc_html__( 'Settings', 'cf7-smtp-bridge' )
	);

	array_unshift( $links, $settings_link );

	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'cf7_smtp_bridge_action_links' );

/**
 * Boot the plugin.
 *
 * @return CF7_SMTP_Bridge
 */
function cf7_smtp_bridge(): CF7_SMTP_Bridge {
	return CF7_SMTP_Bridge::get_instance();
}

// Initialize.
cf7_smtp_bridge();
