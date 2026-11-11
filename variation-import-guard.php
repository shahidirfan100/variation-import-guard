<?php
/**
 * Plugin Name:       Variation Import Guard
 * Description:       Check and repair WooCommerce variation CSV relationships before importing products.
 * Author:            Shahid Irfan
 * Author URI:        https://profiles.wordpress.org/shahidirfan100/
 * Version:           1.0.2
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       variation-import-guard
 *
 * @package VariationImportGuard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'VARIATION_IMPORT_GUARD_VERSION', '1.0.2' );
define( 'VARIATION_IMPORT_GUARD_FILE', __FILE__ );
define( 'VARIATION_IMPORT_GUARD_DIR', plugin_dir_path( __FILE__ ) );
define( 'VARIATION_IMPORT_GUARD_URL', plugin_dir_url( __FILE__ ) );
define( 'VARIATION_IMPORT_GUARD_SLUG', 'variation-import-guard' );

require_once VARIATION_IMPORT_GUARD_DIR . 'includes/class-storage.php';
require_once VARIATION_IMPORT_GUARD_DIR . 'includes/class-csv-preflight.php';
require_once VARIATION_IMPORT_GUARD_DIR . 'includes/class-admin.php';

register_activation_hook( __FILE__, array( 'VariationImportGuard\\Storage', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'VariationImportGuard\\Storage', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		if ( class_exists( 'Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
		( new \VariationImportGuard\Admin() )->register();
	}
);
