<?php
/**
 * Bootstrap and wiring.
 *
 * @package Dev3\AiImageStudio
 */

namespace Dev3\AiImageStudio;

use Dev3\AiImageStudio\Core3\Core\Assets;
use Dev3\AiImageStudio\Core3\Core\Privacy;
use Dev3\AiImageStudio\Core3\Core\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton entry point.
 */
final class Plugin {

	const CRON_HOOK = 'dev3is_daily';

	/**
	 * Instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Boots once.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	/**
	 * Activation.
	 */
	public static function activate() {
		self::register_schema();
		Schema::install( DEV3IS_DB_VERSION );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Deactivation.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Plugin tables / settings defaults are registered here so activation and runtime agree.
	 */
	public static function register_schema() {
		if ( ! has_filter( 'dev3is_settings_defaults', array( Settings::class, 'defaults' ) ) ) {
			add_filter( 'dev3is_settings_defaults', array( Settings::class, 'defaults' ) );
			add_filter( 'dev3is_settings_sanitize', array( Settings::class, 'sanitize' ), 10, 3 );
			add_filter( 'dev3is_schema_sql', array( Studio\Results::class, 'schema' ), 10, 2 );
		}
	}

	/**
	 * Hooks.
	 */
	private function boot() {
		add_action( 'init', array( $this, 'textdomain' ), 1 );
		self::register_schema();
		add_action( 'before_woocommerce_init', array( $this, 'declare_compat' ) );
		add_action( 'admin_init', static fn() => Schema::maybe_install( DEV3IS_DB_VERSION ) );
		add_action( self::CRON_HOOK, array( Schema::class, 'prune_usage' ) );
		add_action( 'rest_api_init', array( $this, 'rest' ) );
		add_action( 'init', array( Studio\Alt_Text::class, 'register_prompts' ), 5 );
		Privacy::init();
		// Background job types must be registered on every request (the runner looks them up).
		Studio\Batch::register();
		add_action( 'init', static fn() => Assets::register( array( 'admin' ) ) );
		add_filter( 'ajax_query_attachments_args', array( Studio\Results::class, 'hide_drafts_ajax' ) );
		add_action( 'pre_get_posts', array( Studio\Results::class, 'hide_drafts_list' ) );
		( new Frontend\Compare() )->register();
		if ( is_admin() ) {
			( new Admin\Admin_Page() )->register();
		}
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'needs_woo' ) );
			return;
		}
		if ( is_admin() ) {
			( new Admin\Product_Integration() )->register();
		}
	}

	/**
	 * Translations (on init, as WP 6.7 expects).
	 */
	public function textdomain() {
		load_plugin_textdomain( 'dev3-ai-product-image-studio-for-woocommerce', false, dirname( plugin_basename( DEV3IS_FILE ) ) . '/languages' );
	}

	/**
	 * HPOS + Cart/Checkout blocks compatibility (the plugin never reads or writes orders or the cart).
	 */
	public function declare_compat() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', DEV3IS_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', DEV3IS_FILE, true );
		}
	}

	/**
	 * Admin notice when WooCommerce is missing.
	 */
	public function needs_woo() {
		if ( current_user_can( 'activate_plugins' ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'DEV3 AI Product Image Studio needs WooCommerce to be active.', 'dev3-ai-product-image-studio-for-woocommerce' ) . '</p></div>';
		}
	}

	/**
	 * REST routes.
	 */
	public function rest() {
		( new Core3\Rest\Core_Controller() )->register_routes();
		( new Rest\Rest_Controller() )->register_routes();
	}
}
