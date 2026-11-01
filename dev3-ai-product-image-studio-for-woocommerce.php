<?php
/**
 * Plugin Name:       DEV3 AI Product Image Studio for WooCommerce
 * Plugin URI:        https://dev3.tech/item/dev3-ai-product-image-studio-for-woocommerce
 * Description:       AI background removal, white-background pack shots, lifestyle scenes, upscaling and alt text for WooCommerce product images, with batch mode and before/after approval.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.0
 * WC tested up to:  10.3
 * Author:            DEV3 Technologies
 * Author URI:        https://dev3.tech
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dev3-ai-product-image-studio-for-woocommerce
 * Domain Path:       /languages
 *
 * @package Dev3\AiImageStudio
 */

defined( 'ABSPATH' ) || exit;

define( 'DEV3IS_VERSION', '1.0.0' );
define( 'DEV3IS_FILE', __FILE__ );
define( 'DEV3IS_PATH', plugin_dir_path( __FILE__ ) );
define( 'DEV3IS_URL', plugin_dir_url( __FILE__ ) );
define( 'DEV3IS_SLUG', 'dev3-ai-product-image-studio-for-woocommerce' );
define( 'DEV3IS_NAME', 'DEV3 AI Product Image Studio for WooCommerce' );
define( 'DEV3IS_MIN_PHP', '7.4' );
define( 'DEV3IS_MIN_WP', '6.0' );
define( 'DEV3IS_DB_VERSION', '1.0.0' );

if ( version_compare( PHP_VERSION, DEV3IS_MIN_PHP, '<' ) ) {
	return;
}

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'Dev3\AiImageStudio\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$file = DEV3IS_PATH . 'includes/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( '\Dev3\AiImageStudio\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\Dev3\AiImageStudio\Plugin', 'deactivate' ) );
add_action( 'plugins_loaded', array( '\Dev3\AiImageStudio\Plugin', 'instance' ) );
