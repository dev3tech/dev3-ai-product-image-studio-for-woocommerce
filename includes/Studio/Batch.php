<?php
/**
 * Batch mode on core Jobs: one operation (or recipe) over many product images, results go to the review queue.
 *
 * @package Dev3\AiImageStudio
 */

namespace Dev3\AiImageStudio\Studio;

use Dev3\AiImageStudio\Core3\Core\Jobs;
use Dev3\AiImageStudio\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Job type dev3is_batch. Items are "attachment:product:slot" strings.
 */
class Batch {

	const JOB       = 'dev3is_batch';
	const MAX_ITEMS = 2000;
	const EXTRA_OPS = array( 'alt', 'alt_template', 'audit' );

	/**
	 * Registers the handler (every request).
	 */
	public static function register() {
		Jobs::register( self::JOB, array( __CLASS__, 'handle' ) );
	}

	/**
	 * Item key.
	 *
	 * @param array $t Target { attachment_id, product_id, slot }.
	 * @return string
	 */
	public static function key( array $t ) {
		return (int) $t['attachment_id'] . ':' . (int) $t['product_id'] . ':' . (string) $t['slot'];
	}

	/**
	 * Parses an item key.
	 *
	 * @param string $key Key.
	 * @return array|null
	 */
	public static function parse( $key ) {
		if ( ! preg_match( '/^(\d+):(\d+):(featured|gallery|variation:\d+|media)$/', (string) $key, $m ) ) {
			return null;
		}
		return array(
			'attachment_id' => (int) $m[1],
			'product_id'    => (int) $m[2],
			'slot'          => $m[3],
		);
	}

	/**
	 * Resolves the target list from a request.
	 *
	 * @param array $req { targets[], product_ids[], category, filter, scope, all }.
	 * @return string[] Item keys.
	 */
	public static function resolve( array $req ) {
		$scope = isset( $req['scope'] ) && in_array( $req['scope'], Settings::SCOPES, true ) ? $req['scope'] : (string) Settings::get( 'batch_scope', 'featured' );
		$keys  = array();
		if ( ! empty( $req['targets'] ) ) {
			foreach ( (array) $req['targets'] as $key ) {
				$t = self::parse( $key );
				if ( $t && wp_attachment_is_image( $t['attachment_id'] ) ) {
					$keys[] = self::key( $t );
				}
			}
		} else {
			$ids = null;
			if ( ! empty( $req['product_ids'] ) ) {
				$ids = array_map( 'intval', (array) $req['product_ids'] );
			} elseif ( ! empty( $req['category'] ) ) {
				$ids = Library::products_in_category( (int) $req['category'] );
			} elseif ( empty( $req['all'] ) ) {
				return array();
			}
			$targets = Library::targets( $ids, $scope );
			$filter  = isset( $req['filter'] ) && in_array( $req['filter'], Library::FILTERS, true ) ? $req['filter'] : 'all';
			foreach ( $targets as $t ) {
				if ( 'all' !== $filter && 'pending' !== $filter ) {
					$flags = Audit::flags( $t['attachment_id'], 'not_white' === $filter );
					if ( true !== $flags[ $filter ] ) {
						continue;
					}
				}
				$keys[] = self::key( $t );
			}
		}
		$keys = array_values( array_unique( $keys ) );
		// Never re-process drafts (results) themselves.
		$keys = array_values(
			array_filter(
				$keys,
				static function ( $k ) {
					$t = self::parse( $k );
					return ! get_post_meta( $t['attachment_id'], Results::DRAFT, true );
				}
			)
		);
		return array_slice( $keys, 0, self::MAX_ITEMS );
	}

	/**
	 * Creates a batch job.
	 *
	 * @param string[] $keys    Item keys.
	 * @param string   $op      Operation (image op, alt, alt_template, audit) — or '' with a recipe.
	 * @param array    $options Options.
	 * @param string   $recipe  Recipe id.
	 * @return int|\WP_Error Job id.
	 */
	public static function create( array $keys, $op, array $options = array(), $recipe = '' ) {
		if ( '' !== $recipe ) {
			$r = Recipes::recipe( $recipe );
			if ( ! $r ) {
				return new \WP_Error( 'dev3is_bad_recipe', __( 'Recipe not found.', 'dev3-ai-product-image-studio-for-woocommerce' ), array( 'status' => 404 ) );
			}
			$op      = $r['operation'];
			$options = array_merge( (array) $r['options'], $options );
		}
		if ( ! Operations::exists( $op ) && ! in_array( $op, self::EXTRA_OPS, true ) ) {
			return new \WP_Error( 'dev3is_bad_op', __( 'Unknown operation.', 'dev3-ai-product-image-studio-for-woocommerce' ), array( 'status' => 400 ) );
		}
		if ( ! $keys ) {
			return new \WP_Error( 'dev3is_no_targets', __( 'No product images match this selection.', 'dev3-ai-product-image-studio-for-woocommerce' ), array( 'status' => 400 ) );
		}
		if ( 'edit' === $op && empty( $options['instruction'] ) ) {
			return new \WP_Error( 'dev3is_empty_instruction', __( 'Describe the edit you want.', 'dev3-ai-product-image-studio-for-woocommerce' ), array( 'status' => 400 ) );
		}
		$clean = in_array( $op, self::EXTRA_OPS, true ) ? array( 'template' => isset( $options['template'] ) ? mb_substr( sanitize_text_field( (string) $options['template'] ), 0, 200 ) : (string) Settings::get( 'alt_template', '' ) ) : Operations::options( $op, $options );
		$chunk = 'audit' === $op ? 20 : ( in_array( $op, array( 'alt_template', 'square', 'flatten', 'convert', 'local_upscale' ), true ) ? 3 : 1 );
		return Jobs::create(
			self::JOB,
			$keys,
			array(
				'operation' => $op,
				'options'   => $clean,
				'recipe'    => (string) $recipe,
			),
			$chunk
		);
	}

	/**
	 * Job handler.
	 *
	 * @param array $items Item keys.
	 * @param array $job   Job.
	 * @return array key => true|WP_Error
	 */
	public static function handle( array $items, array $job ) {
		$op   = isset( $job['args']['operation'] ) ? (string) $job['args']['operation'] : '';
		$opts = isset( $job['args']['options'] ) ? (array) $job['args']['options'] : array();
		$out  = array();
		foreach ( $items as $key ) {
			$t = self::parse( $key );
			if ( ! $t ) {
				$out[ $key ] = new \WP_Error( 'dev3is_bad_item', __( 'Invalid item.', 'dev3-ai-product-image-studio-for-woocommerce' ) );
				continue;
			}
			$ctx = array(
				'job_id'  => (int) $job['id'],
				'user_id' => (int) $job['user_id'],
				'context' => 'job',
			);
			switch ( $op ) {
				case 'audit':
					$white       = Audit::whiteness( $t['attachment_id'], true );
					$out[ $key ] = null === $white ? new \WP_Error( 'dev3is_audit', __( 'The image could not be read.', 'dev3-ai-product-image-studio-for-woocommerce' ) ) : true;
					break;
				case 'alt':
					$res = Alt_Text::suggest( $t['attachment_id'], $t['product_id'], 'job' );
					if ( is_wp_error( $res ) ) {
						$out[ $key ] = $res;
						break;
					}
					$out[ $key ] = self::pending_alt(
						$t,
						$res['draft'],
						$ctx,
						array(
							'model'  => $res['model'],
							'tokens' => $res['tokens'],
						)
					);
					break;
				case 'alt_template':
					$alt         = Alt_Text::template( isset( $opts['template'] ) ? $opts['template'] : '', $t['product_id'], self::position( $t ) );
					$out[ $key ] = '' === $alt ? new \WP_Error( 'dev3is_empty_alt', __( 'The template produced empty text.', 'dev3-ai-product-image-studio-for-woocommerce' ) ) : self::pending_alt( $t, array( 'alt' => $alt ), $ctx, array( 'label' => __( 'Template', 'dev3-ai-product-image-studio-for-woocommerce' ) ) );
					break;
				default:
					$res         = Operations::run( $t['attachment_id'], $t['product_id'], $t['slot'], $op, $opts, $ctx );
					$out[ $key ] = is_wp_error( $res ) ? $res : true;
			}
		}
		return $out;
	}

	/**
	 * 1-based position of an image among its product's images (for the {n} placeholder).
	 *
	 * @param array $t Target.
	 * @return int
	 */
	public static function position( array $t ) {
		if ( ! $t['product_id'] ) {
			return 1;
		}
		$ids = array_values( array_unique( wp_list_pluck( Library::targets( array( $t['product_id'] ), 'all' ), 'attachment_id' ) ) );
		$pos = array_search( (int) $t['attachment_id'], array_map( 'intval', $ids ), true );
		return false === $pos ? 1 : $pos + 1;
	}

	/**
	 * Stores an alt draft as a pending result.
	 *
	 * @param array $t     Target.
	 * @param array $draft Draft fields.
	 * @param array $ctx   Context.
	 * @param array $meta  Payload extras.
	 * @return true|\WP_Error
	 */
	public static function pending_alt( array $t, array $draft, array $ctx, array $meta = array() ) {
		$id = Results::create(
			array(
				'product_id' => $t['product_id'],
				'source_id'  => $t['attachment_id'],
				'kind'       => 'alt',
				'operation'  => 'alt',
				'apply_mode' => 'library',
				'slot'       => $t['slot'],
				'job_id'     => isset( $ctx['job_id'] ) ? (int) $ctx['job_id'] : 0,
				'user_id'    => isset( $ctx['user_id'] ) ? (int) $ctx['user_id'] : get_current_user_id(),
				'payload'    => array_merge(
					$meta,
					array(
						'alt'      => Alt_Text::clean( $draft ),
						'original' => Alt_Text::current( $t['attachment_id'] ),
					)
				),
			)
		);
		return is_wp_error( $id ) ? $id : true;
	}

	/**
	 * Recent batches for the UI.
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public static function recent( $limit = 10 ) {
		$labels = Operations::labels();
		return array_map(
			static function ( $job ) use ( $labels ) {
				$op           = isset( $job['args']['operation'] ) ? $job['args']['operation'] : '';
				$job['label'] = isset( $labels[ $op ] ) ? $labels[ $op ] : $op;
				if ( ! empty( $job['args']['recipe'] ) ) {
					$recipe = Recipes::recipe( $job['args']['recipe'] );
					if ( $recipe ) {
						$job['label'] = $recipe['name'];
					}
				}
				unset( $job['result'] );
				return $job;
			},
			Jobs::recent( self::JOB, $limit )
		);
	}
}
