<?php
/**
 * Simple file-based logger for SMTP debugging.
 *
 * Writes log entries to wp-content/cf7-smtp-bridge/debug.log with
 * automatic rotation when the file exceeds a configurable size.
 *
 * @package CF7_SMTP_Bridge
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CF7_SMTP_Logger {

	/**
	 * Maximum log file size in bytes (default 1 MB).
	 *
	 * @var int
	 */
	private const MAX_SIZE = 1048576;

	/**
	 * Full path to the log directory.
	 *
	 * @var string
	 */
	private string $log_dir;

	/**
	 * Full path to the log file.
	 *
	 * @var string
	 */
	private string $log_file;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$upload_dir    = wp_upload_dir();
		$this->log_dir = trailingslashit( $upload_dir['basedir'] ) . 'cf7-smtp-bridge';
		$this->log_file = $this->log_dir . '/debug.log';

		$this->maybe_create_directory();
	}

	/**
	 * Create the log directory and protect it with .htaccess and index.php.
	 *
	 * @return void
	 */
	private function maybe_create_directory(): void {
		if ( is_dir( $this->log_dir ) ) {
			return;
		}

		wp_mkdir_p( $this->log_dir );

		// Protect directory from direct access.
		$htaccess = $this->log_dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Deny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		$index = $this->log_dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}

	/**
	 * Log an informational message.
	 *
	 * @param string $message The message to log.
	 * @return void
	 */
	public function info( string $message ): void {
		$this->write( 'INFO', $message );
	}

	/**
	 * Log an error message.
	 *
	 * @param string $message The error message to log.
	 * @return void
	 */
	public function error( string $message ): void {
		$this->write( 'ERROR', $message );
	}

	/**
	 * Write a log entry.
	 *
	 * @param string $level   Log level (INFO, ERROR).
	 * @param string $message The message.
	 * @return void
	 */
	private function write( string $level, string $message ): void {
		$this->maybe_rotate();

		$timestamp = wp_date( 'Y-m-d H:i:s' );
		$entry     = sprintf( "[%s] [%s] %s\n", $timestamp, $level, $message );

		file_put_contents( $this->log_file, $entry, FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Rotate the log file if it exceeds the maximum size.
	 *
	 * @return void
	 */
	private function maybe_rotate(): void {
		if ( ! file_exists( $this->log_file ) ) {
			return;
		}

		if ( filesize( $this->log_file ) < self::MAX_SIZE ) {
			return;
		}

		$backup = $this->log_dir . '/debug-' . gmdate( 'Y-m-d-His' ) . '.log';
		rename( $this->log_file, $backup ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
	}

	/**
	 * Read the current log contents (last N lines).
	 *
	 * @param int $lines Number of lines to return from the end.
	 * @return string
	 */
	public function read( int $lines = 100 ): string {
		if ( ! file_exists( $this->log_file ) ) {
			return '';
		}

		$all_lines = file( $this->log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );

		if ( false === $all_lines ) {
			return '';
		}

		$tail = array_slice( $all_lines, -$lines );

		return implode( "\n", $tail );
	}

	/**
	 * Clear the current log file.
	 *
	 * @return void
	 */
	public function clear(): void {
		if ( file_exists( $this->log_file ) ) {
			file_put_contents( $this->log_file, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}

	/**
	 * Get the path to the log file.
	 *
	 * @return string
	 */
	public function get_log_path(): string {
		return $this->log_file;
	}
}
