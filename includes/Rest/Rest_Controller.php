<?php
/**
 * Studio REST routes under dev3is/v1.
 *
 * @package Dev3\AiImageStudio
 */

namespace Dev3\AiImageStudio\Rest;

use Dev3\AiImageStudio\Core3\Api\Ai;
use Dev3\AiImageStudio\Core3\Core\Capabilities;
use Dev3\AiImageStudio\Core3\Rest\Base_Controller;
use Dev3\AiImageStudio\Settings;
use Dev3\AiImageStudio\Studio\Alt_Text;
use Dev3\AiImageStudio\Studio\Audit;
use Dev3\AiImageStudio\Studio\Batch;
use Dev3\AiImageStudio\Studio\Image_Tools;
use Dev3\AiImageStudio\Studio\Library;
use Dev3\AiImageStudio\Studio\Operations;
use Dev3\AiImageStudio\Studio\Recipes;
use Dev3\AiImageStudio\Studio\Results;

defined( 'ABSPATH' ) || exit;

/**
 * Routes. Every route needs the wp_rest nonce + the studio capability (manage_woocommerce by default).
 */
class Rest_Controller extends Base_Controller {

	/**
	 * Capability for the studio (filter dev3is_studio_capability).
	 *
	 * @return string
	 */
	public static function studio_cap() {
		/**
		 * Capability needed to use the image studio (settings still need the manage capability).
		 *
		 * @param string $cap Capability.
		 */
		return (string) apply_filters( 'dev3is_studio_capability', 'manage_woocommerce' );
	}

	/**
	 * Whether the current user may use the studio.
	 *
	 * @return bool
	 */
	public static function user_can_studio() {
		return ( current_user_can( self::studio_cap() ) && current_user_can( 'upload_files' ) ) || Capabilities::can_manage();
	}

	/**
	 * Permission callback.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function can_studio( \WP_REST_Request $request ) {
		if ( ! $this->nonce_ok( $request ) ) {
			return new \WP_Error( 'dev3is_bad_nonce', __( 'Your session has expired. Reload the page and try again.', 'dev3-ai-product-image-studio-for-woocommerce' ), array( 'status' => 403 ) );
		}
		if ( ! self::user_can_studio() ) {
			return new \WP_Error( 'dev3is_forbidden', __( 'You do not have permission to do that.', 'dev3-ai-product-image-studio-for-woocommerce' ), array( 'status' => rest_authorization_required_code() ) );
		}
		return true;
	}

	/**
	 * Registers routes.
	 */
	public function register_routes() {
		$perm   = array( $this, 'can_studio' );
		$routes = array(
			'/bootstrap'                   => array( 'GET', 'bootstrap' ),
			'/images'                      => array( 'GET', 'images' ),
			'/images/(?P<id>\d+)'          => array( 'GET', 'image' ),
			'/process'                     => array( 'POST', 'process' ),
			'/alt/suggest'                 => array( 'POST', 'alt_suggest' ),
			'/alt/apply'                   => array( 'POST', 'alt_apply' ),
			'/alt/template'                => array( 'POST', 'alt_template' ),
			'/scenes/ideas'                => array( 'POST', 'scene_ideas' ),
			'/results'                     => array( 'GET', 'results' ),
			'/results/(?P<id>\d+)/approve' => array( 'POST', 'approve' ),
			'/results/(?P<id>\d+)/reject'  => array( 'POST', 'reject' ),
			'/results/(?P<id>\d+)/revert'  => array( 'POST', 'revert' ),
			'/results/bulk'                => array( 'POST', 'bulk' ),
			'/batch'                       => array( 'POST', 'batch' ),
			'/batches'                     => array( 'GET', 'batches' ),
			'/recipes'                     => array( 'POST', 'recipes' ),
			'/recipes/reset'               => array( 'POST', 'recipes_reset' ),
		);
		foreach ( $routes as $route => $def ) {
			register_rest_route(
				self::NS,
				$route,
				array(
					'methods'             => $def[0],
					'callback'            => array( $this, $def[1] ),
					'permission_callback' => $perm,
				)
			);
		}
	}

	/**
	 * GET /bootstrap.
	 *
	 * @return \WP_REST_Response
	 */
	public function bootstrap() {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'number'     => 200,
			)
		);
		$keys  = array( 'bg_color', 'output_format', 'jpeg_quality', 'fill_ratio', 'shadow', 'low_res', 'max_side', 'brand_style', 'alt_template', 'alt_language', 'hide_drafts', 'keep_rejected', 'batch_scope', 'default_mode' );
		$conf  = array();
		foreach ( $keys as $k ) {
			$conf[ $k ] = Settings::get( $k );
		}
		return $this->respond(
			array(
				'settings'   => $conf,
				'labels'     => Operations::labels(),
				'ai_ops'     => array_merge( Operations::AI, array( 'alt' ) ),
				'stats'      => Library::stats(),
				'counts'     => Results::counts(),
				'recipes'    => Recipes::all(),
				'ai'         => Ai::status(),
				'gd'         => Image_Tools::available(),
				'webp'       => Image_Tools::webp_supported(),
				'canManage'  => Capabilities::can_manage(),
				'categories' => is_wp_error( $terms ) ? array() : array_map(
					static function ( $t ) {
						return array(
							'value' => (int) $t->term_id,
							'label' => html_entity_decode( $t->name, ENT_QUOTES ) . ' (' . (int) $t->count . ')',
						);
					},
					$terms
				),
				'recent'     => Results::query( array( 'per_page' => 6 ) )['rows'],
			)
		);
	}

	/**
	 * GET /images.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function images( \WP_REST_Request $request ) {
		return $this->respond(
			Library::query(
				array(
					'filter'   => sanitize_key( (string) $request['filter'] ),
					'search'   => sanitize_text_field( (string) $request['search'] ),
					'category' => (int) $request['category'],
					'scope'    => sanitize_key( (string) $request['scope'] ),
					'page'     => (int) $request['page'],
					'per_page' => (int) $request['per_page'],
				)
			)
		);
	}

	/**
	 * GET /images/<id>?product_id=&slot=.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function image( \WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( ! wp_attachment_is_image( $id ) ) {
			return $this->respond( new \WP_Error( 'dev3is_not_found', __( 'Image not found.', 'dev3-ai-product-image-studio-for-woocommerce' ), array( 'status' => 404 ) ) );
		}
		$product = (int) $request['product_id'];
		if ( ! $product ) {
			$parent  = (int) wp_get_post_parent_id( $id );
			$product = $parent && 'product' === get_post_type( $parent ) ? $parent : 0;
		}
		$slot = sanitize_text_field( (string) $request['slot'] );
		if ( '' === $slot && $product ) {
			$places = Results::places( $product, $id );
			$slot   = $places ? ( 'variation' === $places[0]['type'] ? 'variation:' . $places[0]['id'] : $places[0]['type'] ) : 'media';
		}
		$row            = Library::row(
			array(
				'attachment_id' => $id,
				'product_id'    => $product,
				'slot'          => '' === $slot ? 'media' : $slot,
			)
		);
		$row['flags']   = Audit::flags( $id, true );
		$row['draft']   = (bool) get_post_meta( $id, Results::DRAFT, true );
		$row['history'] = Results::query(
			array(
				'source_id' => $id,
				'status'    => 'all',
				'per_page'  => 50,
			)
		)['rows'];
		return $this->respond( $row );
	}

	/**
	 * POST /process { attachment_id, product_id, slot, operation, options, recipe }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function process( \WP_REST_Request $request ) {
		$op      = sanitize_key( (string) $request['operation'] );
		$options = is_array( $request['options'] ) ? $request['options'] : array();
		$recipe  = sanitize_title( (string) $request['recipe'] );
		if ( '' !== $recipe ) {
			$r = Recipes::recipe( $recipe );
			if ( ! $r ) {
				return $this->respond( new \WP_Error( 'dev3is_bad_recipe', __( 'Recipe not found.', 'dev3-ai-product-image-studio-for-woocommerce' ), array( 'status' => 404 ) ) );
			}
			$op      = $r['operation'];
			$options = array_merge( (array) $r['options'], $options );
		}
		$attachment = (int) $request['attachment_id'];
		if ( get_post_meta( $attachment, Results::DRAFT, true ) ) {
			return $this->respond( new \WP_Error( 'dev3is_is_draft', __( 'Approve this result first, then edit it further.', 'dev3-ai-product-image-studio-for-woocommerce' ), array( 'status' => 409 ) ) );
		}
		$rows = Operations::run( $attachment, (int) $request['product_id'], sanitize_text_field( (string) $request['slot'] ), $op, $options );
		return $this->respond( is_wp_error( $rows ) ? $rows : array( 'results' => $rows ), 201 );
	}

	/**
	 * POST /alt/suggest { attachment_id, product_id }: AI draft only.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function alt_suggest( \WP_REST_Request $request ) {
		return $this->respond( Alt_Text::suggest( (int) $request['attachment_id'], (int) $request['product_id'] ) );
	}

	/**
	 * POST /alt/apply { attachment_id, product_id, slot, alt, title, caption, source }: saves (admin accepted).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function alt_apply( \WP_REST_Request $request ) {
		$id = (int) $request['attachment_id'];
		if ( ! wp_attachment_is_image( $id ) ) {
			return $this->respond( new \WP_Error( 'dev3is_not_image', __( 'That attachment is not an image.', 'dev3-ai-product-image-studio-for-woocommerce' ), array( 'status' => 400 ) ) );
		}
		$fields = Alt_Text::clean( array_intersect_key( (array) $request->get_params(), array_flip( array( 'alt', 'title', 'caption' ) ) ) );
		$result = Results::create(
			array(
				'product_id' => (int) $request['product_id'],
				'source_id'  => $id,
				'kind'       => 'alt',
				'operation'  => 'alt',
				'apply_mode' => 'library',
				'slot'       => sanitize_text_field( (string) $request['slot'] ),
				'payload'    => array(
					'alt'   => $fields,
					'label' => 'ai' === $request['source'] ? 'DEV3 AI' : __( 'Manual', 'dev3-ai-product-image-studio-for-woocommerce' ),
				),
			)
		);
		if ( is_wp_error( $result ) ) {
			return $this->respond( $result );
		}
		$approved = Results::approve( $result, 'library', $fields );
		return $this->respond( is_wp_error( $approved ) ? $approved : array( 'current' => Alt_Text::current( $id ) ) );
	}

	/**
	 * POST /alt/template { targets[], template, apply }: preview, or apply directly (no AI).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function alt_template( \WP_REST_Request $request ) {
		$template = mb_substr( sanitize_text_field( (string) $request['template'] ), 0, 200 );
		if ( '' === trim( $template ) ) {
			return $this->respond( new \WP_Error( 'dev3is_empty_template', __( 'Enter a template, e.g. {product} - {category}.', 'dev3-ai-product-image-studio-for-woocommerce' ), array( 'status' => 400 ) ) );
		}
		$keys = array_slice( Batch::resolve( array( 'targets' => (array) $request['targets'] ) ), 0, 200 );
		$rows = array();
		foreach ( $keys as $key ) {
			$t   = Batch::parse( $key );
			$alt = Alt_Text::template( $template, $t['product_id'], Batch::position( $t ) );
			$row = array(
				'key'     => $key,
				'current' => (string) get_post_meta( $t['attachment_id'], '_wp_attachment_image_alt', true ),
				'alt'     => $alt,
			);
			if ( $request['apply'] && '' !== $alt ) {
				$id = Results::create(
					array(
						'product_id' => $t['product_id'],
						'source_id'  => $t['attachment_id'],
						'kind'       => 'alt',
						'operation'  => 'alt',
						'apply_mode' => 'library',
						'slot'       => $t['slot'],
						'payload'    => array(
							'alt'   => array( 'alt' => $alt ),
							'label' => __( 'Template', 'dev3-ai-product-image-studio-for-woocommerce' ),
						),
					)
				);
				if ( ! is_wp_error( $id ) ) {
					Results::approve( $id, 'library' );
				}
			}
			$rows[] = $row;
		}
		return $this->respond(
			array(
				'rows'    => $rows,
				'applied' => (bool) $request['apply'],
			)
		);
	}

	/**
	 * POST /scenes/ideas { product_id }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function scene_ideas( \WP_REST_Request $request ) {
		return $this->respond( Alt_Text::scene_ideas( (int) $request['product_id'] ) );
	}

	/**
	 * GET /results.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function results( \WP_REST_Request $request ) {
		$status         = sanitize_key( (string) $request['status'] );
		$data           = Results::query(
			array(
				'status'     => '' === $status ? 'pending' : $status,
				'operation'  => sanitize_key( (string) $request['operation'] ),
				'kind'       => sanitize_key( (string) $request['kind'] ),
				'product_id' => (int) $request['product_id'],
				'source_id'  => (int) $request['source_id'],
				'job_id'     => (int) $request['job_id'],
				'page'       => (int) $request['page'],
				'per_page'   => (int) $request['per_page'],
			)
		);
		$data['counts'] = Results::counts();
		return $this->respond( $data );
	}

	/**
	 * POST /results/<id>/approve { mode, alt, title, caption }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function approve( \WP_REST_Request $request ) {
		$alt = array_intersect_key( (array) $request->get_params(), array_flip( array( 'alt', 'title', 'caption' ) ) );
		return $this->respond( Results::approve( (int) $request['id'], sanitize_key( (string) $request['mode'] ), $alt ) );
	}

	/**
	 * POST /results/<id>/reject.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function reject( \WP_REST_Request $request ) {
		return $this->respond( Results::reject( (int) $request['id'] ) );
	}

	/**
	 * POST /results/<id>/revert.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function revert( \WP_REST_Request $request ) {
		return $this->respond( Results::revert( (int) $request['id'] ) );
	}

	/**
	 * POST /results/bulk { ids[], action: approve|reject, mode }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function bulk( \WP_REST_Request $request ) {
		$action = (string) $request['action'];
		if ( ! in_array( $action, array( 'approve', 'reject' ), true ) ) {
			return $this->respond( new \WP_Error( 'dev3is_bad_action', __( 'Unknown bulk action.', 'dev3-ai-product-image-studio-for-woocommerce' ), array( 'status' => 400 ) ) );
		}
		$done   = 0;
		$errors = array();
		foreach ( array_slice( array_map( 'intval', (array) $request['ids'] ), 0, 200 ) as $id ) {
			$res = 'approve' === $action ? Results::approve( $id, sanitize_key( (string) $request['mode'] ) ) : Results::reject( $id );
			if ( is_wp_error( $res ) ) {
				$errors[] = array(
					'id'      => $id,
					'message' => $res->get_error_message(),
				);
			} else {
				++$done;
			}
		}
		return $this->respond(
			array(
				'done'   => $done,
				'errors' => $errors,
				'counts' => Results::counts(),
			)
		);
	}

	/**
	 * POST /batch { targets[] | product_ids[] | category | all, filter, scope, operation | recipe, options, dry_run }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function batch( \WP_REST_Request $request ) {
		$keys   = Batch::resolve(
			array(
				'targets'     => (array) $request['targets'],
				'product_ids' => (array) $request['product_ids'],
				'category'    => (int) $request['category'],
				'all'         => (bool) $request['all'],
				'filter'      => sanitize_key( (string) $request['filter'] ),
				'scope'       => sanitize_key( (string) $request['scope'] ),
			)
		);
		$op     = sanitize_key( (string) $request['operation'] );
		$recipe = sanitize_title( (string) $request['recipe'] );
		if ( $request['dry_run'] ) {
			$real = '' !== $recipe && Recipes::recipe( $recipe ) ? Recipes::recipe( $recipe )['operation'] : $op;
			$per  = 'scene' === $real ? max( 1, (int) ( is_array( $request['options'] ) && isset( $request['options']['variants'] ) ? $request['options']['variants'] : 1 ) ) : 1;
			return $this->respond(
				array(
					'count'    => count( $keys ),
					'ai_calls' => Operations::is_ai( $real ) ? count( $keys ) * $per : 0,
				)
			);
		}
		$job = Batch::create( $keys, $op, is_array( $request['options'] ) ? $request['options'] : array(), $recipe );
		if ( is_wp_error( $job ) ) {
			return $this->respond( $job );
		}
		return $this->respond( \Dev3\AiImageStudio\Core3\Core\Jobs::get( $job ), 201 );
	}

	/**
	 * GET /batches.
	 *
	 * @return \WP_REST_Response
	 */
	public function batches() {
		return $this->respond( Batch::recent( 15 ) );
	}

	/**
	 * POST /recipes { recipes?, scenes? }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function recipes( \WP_REST_Request $request ) {
		$input = array();
		foreach ( array( 'recipes', 'scenes' ) as $k ) {
			if ( null !== $request[ $k ] ) {
				$input[ $k ] = (array) $request[ $k ];
			}
		}
		return $this->respond( Recipes::save( $input ) );
	}

	/**
	 * POST /recipes/reset.
	 *
	 * @return \WP_REST_Response
	 */
	public function recipes_reset() {
		return $this->respond( Recipes::reset() );
	}
}
