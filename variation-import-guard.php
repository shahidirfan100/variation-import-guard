<?php
/**
 * Plugin Name:       Variation Import Guard
 * Description:       Check and repair WooCommerce variation CSV relationships before importing products.
 * Author:            Shahid Irfan
 * Author URI:        https://profiles.wordpress.org/shahidirfan100/
 * Version:           1.0.0
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

define( 'VIG_VERSION', '1.0.0' );
define( 'VIG_FILE', __FILE__ );
define( 'VIG_DIR', plugin_dir_path( __FILE__ ) );
define( 'VIG_URL', plugin_dir_url( __FILE__ ) );
define( 'VIG_SLUG', 'variation-import-guard' );

require_once VIG_DIR . 'includes/class-storage.php';
require_once VIG_DIR . 'includes/class-csv-preflight.php';
require_once VIG_DIR . 'includes/class-admin.php';

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
