<?php
/**
 * Uninstall handler for CF7 SMTP Bridge.
 *
 * Removes all plugin options and log files when the plugin is
 * deleted through the WordPress admin.
 *
 * @package CF7_SMTP_Bridge
 * @since   1.0.0
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Remove plugin options.
delete_option( 'cf7_smtp_bridge_settings' );

// Remove log directory.
$upload_dir = wp_upload_dir();
$log_dir    = trailingslashit( $upload_dir['basedir'] ) . 'cf7-smtp-bridge';

if ( is_dir( $log_dir ) ) {
	$files = glob( $log_dir . '/*' );

	if ( is_array( $files ) ) {
		foreach ( $files as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
	}

	rmdir( $log_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}
