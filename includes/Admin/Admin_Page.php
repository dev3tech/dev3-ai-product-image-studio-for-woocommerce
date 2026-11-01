<?php
/**
 * Admin menu + React app mount.
 *
 * @package Dev3\AiImageStudio
 */

namespace Dev3\AiImageStudio\Admin;

use Dev3\AiImageStudio\Core3\Core\Assets;
use Dev3\AiImageStudio\Core3\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Top-level menu page.
 */
class Admin_Page {

	/**
	 * Hook suffix.
	 *
	 * @var string
	 */
	private $hook = '';

	/**
	 * Registers hooks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( DEV3IS_FILE ), array( $this, 'links' ) );
	}

	/**
	 * Menu.
	 */
	public function menu() {
		$cap        = class_exists( 'WooCommerce' ) ? \Dev3\AiImageStudio\Rest\Rest_Controller::studio_cap() : Capabilities::manage();
		$this->hook = (string) add_menu_page( DEV3IS_NAME, __( 'DEV3 AI Image Studio', 'dev3-ai-product-image-studio-for-woocommerce' ), $cap, DEV3IS_SLUG, array( $this, 'render' ), 'dashicons-format-image', 56 );
	}

	/**
	 * Mount point.
	 */
	public function render() {
		// The h1 names the page for screen readers; wp-header-end keeps admin notices above the app.
		echo '<div class="wrap dev3is-wrap"><h1 class="screen-reader-text">' . esc_html( DEV3IS_NAME ) . '</h1><hr class="wp-header-end"><div id="dev3is-admin-root" class="dev3is-root dev3is-root--app"></div></div>';
	}

	/**
	 * Assets on our page only.
	 *
	 * @param string $hook Hook suffix.
	 */
	public function enqueue( $hook ) {
		if ( $hook === $this->hook ) {
			Assets::enqueue( 'admin' );
		}
	}

	/**
	 * Settings link.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . DEV3IS_SLUG ) ) . '">' . esc_html__( 'Settings', 'dev3-ai-product-image-studio-for-woocommerce' ) . '</a>' );
		return $links;
	}
}
