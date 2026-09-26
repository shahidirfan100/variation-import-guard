<?php
/** Private temporary-file handling. */
namespace VariationImportGuard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps uploads outside the public web root where possible and expires them.
 */
final class Storage {
	const TRANSIENT_PREFIX = 'vig_job_';
	const RETENTION = DAY_IN_SECONDS;

	/** Set up private storage and scheduled cleanup. */
	public static function activate() {
		self::ensure_directory();
		if ( ! wp_next_scheduled( 'vig_cleanup_files' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'vig_cleanup_files' );
		}
	}

	/** Stop scheduled cleanup. Files expire and are removed on the next activation. */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'vig_cleanup_files' );
	}

	/** Register the cleanup callback on each plugin load. */
	public static function register_hooks() {
		add_action( 'vig_cleanup_files', array( __CLASS__, 'cleanup' ) );
	}

	/** Return the protected working directory, creating deny rules when needed. */
	public static function ensure_directory() {
		$base = self::directory_path();

		if ( ! wp_mkdir_p( $base ) ) {
			return new \WP_Error( 'vig_storage', __( 'The private working folder could not be created. Check the server temporary directory permissions.', 'variation-import-guard' ) );
		}

		$rules = array(
			'index.php'   => "<?php\n// Silence is golden.\n",
			'.htaccess'   => "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
			'web.config'  => "<?xml version=\"1.0\" encoding=\"UTF-8\"?><configuration><system.webServer><directoryBrowse enabled=\"false\"/><security><authorization><remove users=\"*\" roles=\"\" verbs=\"\"/><add accessType=\"Deny\" users=\"*\"/></authorization></security></system.webServer></configuration>",
		);
		foreach ( $rules as $name => $contents ) {
			$path = trailingslashit( $base ) . $name;
			if ( ! file_exists( $path ) ) {
				file_put_contents( $path, $contents, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents -- Private transient files are created inside uploads after WordPress API directory creation.
			}
		}
		if ( DIRECTORY_SEPARATOR !== '\\' ) {
			chmod( $base, 0700 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Best-effort POSIX hardening; deny rules are also written.
		}
		return $base;
	}

	/** Build a stable, per-site path under the server's temporary directory. */
	public static function directory_path() {
		$site_key = substr( hash( 'sha256', wp_normalize_path( ABSPATH ) ), 0, 16 );
		return trailingslashit( get_temp_dir() ) . 'variation-import-guard-' . $site_key;
	}

	/** Create a cryptographically random job token. */
	public static function token() {
		return bin2hex( random_bytes( 24 ) );
	}

	/** Return a path only for a valid token and one of the plugin's known outputs. */
	public static function job_file( $token, $kind ) {
		if ( ! preg_match( '/^[a-f0-9]{48}$/', (string) $token ) || ! in_array( $kind, array( 'fixed', 'report' ), true ) ) {
			return false;
		}
		$base = self::ensure_directory();
		if ( is_wp_error( $base ) ) {
			return false;
		}
		return trailingslashit( $base ) . $token . '-' . $kind . '.csv';
	}

	/** Remove files whose last-modified time is older than one day. */
	public static function cleanup() {
		$base = self::ensure_directory();
		if ( is_wp_error( $base ) || ! is_dir( $base ) ) {
			return;
		}
		$cutoff = time() - self::RETENTION;
		foreach ( glob( trailingslashit( $base ) . '*' ) as $path ) {
			$name = basename( $path );
			$job_file = preg_match( '/^[a-f0-9]{48}-(?:source|fixed|report)\.csv$|^[a-f0-9]{48}-(?:variations|metadata)\.tmp$/', $name );
			if ( $job_file && is_file( $path ) && filemtime( $path ) < $cutoff ) {
				wp_delete_file( $path );
			}
		}
	}
}
