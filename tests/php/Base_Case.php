<?php
/**
 * Shared set-up: clean settings, fake Gemini over pre_http_request, image + product factories.
 *
 * @package Dev3\AiImageStudio
 */

use Dev3\AiImageStudio\Core3\Api\Ai;
use Dev3\AiImageStudio\Core3\Core\Cache;
use Dev3\AiImageStudio\Core3\Core\Schema;
use Dev3\AiImageStudio\Core3\Core\Settings_Store;
use Dev3\AiImageStudio\Core3\Data\Usage_Repository;
use Dev3\AiImageStudio\Studio\Image_Tools;

abstract class Dev3is_Base_Case extends WP_UnitTestCase {

	/**
	 * Captured requests.
	 *
	 * @var array
	 */
	protected $requests = array();

	/**
	 * Response queue (each [ status, body ]); the last one repeats.
	 *
	 * @var array
	 */
	protected $responses = array();

	public function set_up() {
		parent::set_up();
		Schema::install( DEV3IS_DB_VERSION );
		update_option( Settings_Store::OPTION, array() );
		Settings_Store::flush();
		Settings_Store::update( array( 'api_key' => 'AIza-test-key-000000000000' ) );
		Usage_Repository::flush();
		Cache::reset_memo();
		Ai::$client = null;
		delete_option( 'dev3is_recipes' );
		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}dev3is_results" ); // phpcs:ignore
		$this->requests  = array();
		$this->responses = array();
		add_filter( 'dev3is_retry_delay_ms', '__return_zero' );
		add_filter( 'dev3is_jobs_use_action_scheduler', '__return_false' );
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
		\Dev3\AiImageStudio\Studio\Alt_Text::register_prompts();
		do_action( 'rest_api_init' );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ), 10 );
		remove_filter( 'dev3is_jobs_use_action_scheduler', '__return_false' );
		parent::tear_down();
	}

	/**
	 * Fake Gemini.
	 *
	 * @param mixed  $pre  Pre.
	 * @param array  $args Args.
	 * @param string $url  URL.
	 * @return array|WP_Error
	 */
	public function fake_http( $pre, $args, $url ) {
		if ( false === strpos( $url, 'generativelanguage' ) ) {
			return $pre;
		}
		$this->requests[] = array(
			'url'  => $url,
			'body' => json_decode( (string) $args['body'], true ),
		);
		if ( ! $this->responses ) {
			return new WP_Error( 'http_blocked', 'No fake response' );
		}
		$reply = count( $this->responses ) > 1 ? array_shift( $this->responses ) : $this->responses[0];
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $reply[1] ),
			'response' => array(
				'code'    => $reply[0],
				'message' => 'x',
			),
			'cookies'  => array(),
		);
	}

	/**
	 * Gemini image reply.
	 *
	 * @param string $bytes Image bytes.
	 * @param string $mime  Mime.
	 * @return array
	 */
	protected function image_reply( $bytes, $mime = 'image/png' ) {
		return array(
			200,
			array(
				'candidates'    => array(
					array(
						'content'      => array(
							'parts' => array(
								array( 'text' => 'Here is the edit.' ),
								array(
									'inlineData' => array(
										'mimeType' => $mime,
										'data'     => base64_encode( $bytes ), // phpcs:ignore
									),
								),
							),
						),
						'finishReason' => 'STOP',
					),
				),
				'usageMetadata' => array(
					'promptTokenCount'     => 300,
					'candidatesTokenCount' => 1290,
					'totalTokenCount'      => 1590,
				),
			),
		);
	}

	/**
	 * Gemini JSON reply.
	 *
	 * @param array $data Data.
	 * @return array
	 */
	protected function json_reply( array $data ) {
		return array(
			200,
			array(
				'candidates'    => array(
					array(
						'content'      => array( 'parts' => array( array( 'text' => wp_json_encode( $data ) ) ) ),
						'finishReason' => 'STOP',
					),
				),
				'usageMetadata' => array(
					'promptTokenCount'     => 100,
					'candidatesTokenCount' => 40,
					'totalTokenCount'      => 140,
				),
			),
		);
	}

	/**
	 * PNG bytes: a coloured rectangle on a background.
	 *
	 * @param int   $w  Width.
	 * @param int   $h  Height.
	 * @param array $bg Background.
	 * @param array $fg Foreground.
	 * @return string
	 */
	protected function png( $w = 400, $h = 300, $bg = array( 200, 200, 200 ), $fg = array( 30, 60, 200 ) ) {
		$img = Image_Tools::canvas( $w, $h, $bg );
		imagefilledrectangle( $img, (int) ( $w * 0.3 ), (int) ( $h * 0.3 ), (int) ( $w * 0.7 ), (int) ( $h * 0.7 ), imagecolorallocate( $img, $fg[0], $fg[1], $fg[2] ) );
		return Image_Tools::encode( $img, 'image/png' );
	}

	/**
	 * Attachment from bytes.
	 *
	 * @param string $bytes  Bytes.
	 * @param int    $parent Parent.
	 * @param string $name   File name.
	 * @return int
	 */
	protected function attachment( $bytes, $parent = 0, $name = 'photo.png' ) {
		$upload = wp_upload_bits( wp_generate_password( 6, false ) . '-' . $name, null, $bytes );
		$id     = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => 'Photo',
				'post_status'    => 'inherit',
			),
			$upload['file'],
			$parent
		);
		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );
		return (int) $id;
	}

	/**
	 * Simple product with a featured image and two gallery images.
	 *
	 * @param string $name Name.
	 * @return array { product, featured, gallery[] }
	 */
	protected function product( $name = 'Blue Mug' ) {
		$p = new WC_Product_Simple();
		$p->set_name( $name );
		$p->set_status( 'publish' );
		$p->set_regular_price( '10' );
		$p->set_sku( 'SKU-' . wp_generate_password( 4, false ) );
		$p->save();
		$featured = $this->attachment( $this->png(), $p->get_id() );
		$g1       = $this->attachment( $this->png( 300, 300, array( 255, 255, 255 ) ), $p->get_id() );
		$g2       = $this->attachment( $this->png( 900, 900, array( 255, 255, 255 ) ), $p->get_id() );
		$p->set_image_id( $featured );
		$p->set_gallery_image_ids( array( $g1, $g2 ) );
		$p->save();
		return array(
			'product'  => wc_get_product( $p->get_id() ),
			'featured' => $featured,
			'gallery'  => array( $g1, $g2 ),
		);
	}

	/**
	 * REST call with a nonce.
	 *
	 * @param string $method Method.
	 * @param string $route  Route.
	 * @param array  $params Params.
	 * @param bool   $nonce  Send nonce.
	 * @return WP_REST_Response
	 */
	protected function call( $method, $route, array $params = array(), $nonce = true ) {
		$req = new WP_REST_Request( $method, '/dev3is/v1/' . ltrim( $route, '/' ) );
		if ( $nonce ) {
			$req->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		}
		foreach ( $params as $k => $v ) {
			$req->set_param( $k, $v );
		}
		return rest_do_request( $req );
	}

	/**
	 * Logs in as a role.
	 *
	 * @param string $role Role.
	 * @return int
	 */
	protected function login( $role = 'administrator' ) {
		$id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $id );
		return $id;
	}
}
