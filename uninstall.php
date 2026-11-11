<?php
/** Remove Variation Import Guard temporary output files on uninstall. */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

( static function () {
	$directory = trailingslashit( get_temp_dir() ) . 'variation-import-guard-' . substr( hash( 'sha256', wp_normalize_path( ABSPATH ) ), 0, 16 );
	wp_clear_scheduled_hook( 'variation_import_guard_cleanup_files' );
	if ( is_dir( $directory ) ) {
		foreach ( glob( trailingslashit( $directory ) . '*' ) as $path ) {
			$name = basename( $path );
			if ( is_file( $path ) && preg_match( '/^[a-f0-9]{48}-(?:source|fixed|report)\.csv$|^[a-f0-9]{48}-(?:variations|metadata)\.tmp$/', $name ) ) {
				wp_delete_file( $path );
			}
		}
	}
} )();
