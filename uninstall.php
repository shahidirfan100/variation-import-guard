<?php
/** Remove Variation Import Guard temporary output files on uninstall. */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$variation_import_guard_directory = trailingslashit( get_temp_dir() ) . 'variation-import-guard-' . substr( hash( 'sha256', wp_normalize_path( ABSPATH ) ), 0, 16 );
wp_clear_scheduled_hook( 'vig_cleanup_files' );
if ( is_dir( $variation_import_guard_directory ) ) {
	foreach ( glob( trailingslashit( $variation_import_guard_directory ) . '*' ) as $variation_import_guard_path ) {
		$variation_import_guard_name = basename( $variation_import_guard_path );
		if ( is_file( $variation_import_guard_path ) && preg_match( '/^[a-f0-9]{48}-(?:source|fixed|report)\.csv$|^[a-f0-9]{48}-(?:variations|metadata)\.tmp$/', $variation_import_guard_name ) ) {
			wp_delete_file( $variation_import_guard_path );
		}
	}
}
