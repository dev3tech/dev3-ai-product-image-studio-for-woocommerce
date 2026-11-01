<?php
/**
 * Product image library: every featured, gallery and variation image of every product.
 *
 * @package Dev3\AiImageStudio
 */

namespace Dev3\AiImageStudio\Studio;

defined( 'ABSPATH' ) || exit;

/**
 * Enumerates and filters product images.
 */
class Library {

	const FILTERS = array( 'all', 'missing_alt', 'low_res', 'not_white', 'not_square', 'pending' );

	/**
	 * Uncached GD audits allowed per request (the rest count as unknown until audited).
	 *
	 * @var int
	 */
	public static $audit_budget = 150;

	/**
	 * All (attachment, product, slot) rows, optionally limited to some products.
	 *
	 * @param int[]|null $product_ids Product ids (parents) or null for all.
	 * @param string     $scope       featured|gallery|variations|all.
	 * @return array [ { attachment_id, product_id, slot } ]
	 */
	public static function targets( $product_ids = null, $scope = 'all' ) {
		global $wpdb;
		$posts = $wpdb->posts;
		$meta  = $wpdb->postmeta;
		$where = '';
		if ( is_array( $product_ids ) ) {
			$product_ids = array_values( array_filter( array_map( 'intval', $product_ids ) ) );
			if ( ! $product_ids ) {
				return array();
			}
			$list  = implode( ',', $product_ids );
			$where = " AND ( p.ID IN ({$list}) OR ( p.post_type = 'product_variation' AND p.post_parent IN ({$list}) ) )";
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ids are cast to int above.
		$rows = $wpdb->get_results( "SELECT p.ID, p.post_type, p.post_parent, m.meta_key, m.meta_value FROM {$posts} p INNER JOIN {$meta} m ON m.post_id = p.ID WHERE p.post_type IN ('product','product_variation') AND p.post_status NOT IN ('trash','auto-draft') AND m.meta_key IN ('_thumbnail_id','_product_image_gallery') AND m.meta_value <> '' {$where} ORDER BY p.post_parent ASC, p.ID ASC", ARRAY_A );
		$out  = array();
		$seen = array();
		foreach ( (array) $rows as $row ) {
			$is_var = 'product_variation' === $row['post_type'];
			if ( $is_var ) {
				if ( ! in_array( $scope, array( 'variations', 'all' ), true ) || '_thumbnail_id' !== $row['meta_key'] ) {
					continue;
				}
				$ids  = array( (int) $row['meta_value'] );
				$slot = 'variation:' . (int) $row['ID'];
				$pid  = (int) $row['post_parent'];
			} elseif ( '_thumbnail_id' === $row['meta_key'] ) {
				if ( ! in_array( $scope, array( 'featured', 'all' ), true ) ) {
					continue;
				}
				$ids  = array( (int) $row['meta_value'] );
				$slot = 'featured';
				$pid  = (int) $row['ID'];
			} else {
				if ( ! in_array( $scope, array( 'gallery', 'all' ), true ) ) {
					continue;
				}
				$ids  = array_filter( array_map( 'intval', explode( ',', (string) $row['meta_value'] ) ) );
				$slot = 'gallery';
				$pid  = (int) $row['ID'];
			}
			foreach ( $ids as $aid ) {
				$key = $aid . ':' . $pid . ':' . $slot;
				if ( $aid <= 0 || isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;
				$out[]        = array(
					'attachment_id' => $aid,
					'product_id'    => $pid,
					'slot'          => $slot,
				);
			}
		}
		$order = array(
			'featured' => 0,
			'gallery'  => 1,
		);
		usort(
			$out,
			static function ( $a, $b ) use ( $order ) {
				if ( $a['product_id'] !== $b['product_id'] ) {
					return $a['product_id'] <=> $b['product_id'];
				}
				$oa = isset( $order[ $a['slot'] ] ) ? $order[ $a['slot'] ] : 2;
				$ob = isset( $order[ $b['slot'] ] ) ? $order[ $b['slot'] ] : 2;
				return $oa <=> $ob;
			}
		);
		return array_values(
			array_filter(
				$out,
				static function ( $t ) {
					return 'attachment' === get_post_type( $t['attachment_id'] ) && wp_attachment_is_image( $t['attachment_id'] );
				}
			)
		);
	}

	/**
	 * Product ids in a category (including children).
	 *
	 * @param int $term_id Term id.
	 * @return int[]
	 */
	public static function products_in_category( $term_id ) {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => 'product',
					'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
					'fields'         => 'ids',
					'posts_per_page' => -1,
					'no_found_rows'  => true,
					'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
						array(
							'taxonomy'         => 'product_cat',
							'field'            => 'term_id',
							'terms'            => (int) $term_id,
							'include_children' => true,
						),
					),
				)
			)
		);
	}

	/**
	 * Product ids whose title or SKU matches.
	 *
	 * @param string $search Search.
	 * @return int[]
	 */
	public static function search_products( $search ) {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( $search ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_title LIKE %s UNION SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE m.meta_key = '_sku' AND m.meta_value LIKE %s AND p.post_type IN ('product','product_variation')", $like, $like ) );
		$out = array();
		foreach ( (array) $ids as $id ) {
			$post  = get_post( (int) $id );
			$out[] = $post && 'product_variation' === $post->post_type ? (int) $post->post_parent : (int) $id;
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Paged, filtered library rows.
	 *
	 * @param array $args { filter, search, category, page, per_page, scope }.
	 * @return array { rows, total, page, per_page }
	 */
	public static function query( array $args = array() ) {
		$filter   = isset( $args['filter'] ) && in_array( $args['filter'], self::FILTERS, true ) ? $args['filter'] : 'all';
		$search   = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';
		$category = isset( $args['category'] ) ? (int) $args['category'] : 0;
		$scope    = isset( $args['scope'] ) && in_array( $args['scope'], array( 'featured', 'gallery', 'variations', 'all' ), true ) ? $args['scope'] : 'all';
		$per      = min( 100, max( 1, isset( $args['per_page'] ) ? (int) $args['per_page'] : 20 ) );
		$page     = max( 1, isset( $args['page'] ) ? (int) $args['page'] : 1 );

		$ids = null;
		if ( $category ) {
			$ids = self::products_in_category( $category );
		}
		if ( '' !== $search ) {
			$found = self::search_products( $search );
			$ids   = null === $ids ? $found : array_values( array_intersect( $ids, $found ) );
		}
		$targets = self::targets( $ids, $scope );
		if ( 'all' !== $filter ) {
			$pending = 'pending' === $filter ? Results::pending_for( wp_list_pluck( $targets, 'attachment_id' ) ) : array();
			$budget  = self::$audit_budget;
			$targets = array_values(
				array_filter(
					$targets,
					static function ( $t ) use ( $filter, $pending, &$budget ) {
						if ( 'pending' === $filter ) {
							return ! empty( $pending[ $t['attachment_id'] ] );
						}
						$compute = 'not_white' === $filter && $budget > 0;
						if ( $compute && null === Audit::whiteness( $t['attachment_id'], false ) ) {
							--$budget;
						}
						$flags = Audit::flags( $t['attachment_id'], $compute );
						return true === $flags[ $filter ];
					}
				)
			);
		}
		$total = count( $targets );
		$slice = array_slice( $targets, ( $page - 1 ) * $per, $per );
		$pend  = Results::pending_for( wp_list_pluck( $slice, 'attachment_id' ) );
		return array(
			'rows'     => array_map(
				static function ( $t ) use ( $pend ) {
					return self::row( $t, isset( $pend[ $t['attachment_id'] ] ) ? $pend[ $t['attachment_id'] ] : 0 );
				},
				$slice
			),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per,
		);
	}

	/**
	 * One library row.
	 *
	 * @param array $t       Target.
	 * @param int   $pending Pending results.
	 * @return array
	 */
	public static function row( array $t, $pending = 0 ) {
		$image   = Results::image( $t['attachment_id'] );
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $t['product_id'] ) : null;
		$slot    = $t['slot'];
		$label   = 'featured' === $slot ? __( 'Featured', 'dev3-ai-product-image-studio-for-woocommerce' ) : __( 'Gallery', 'dev3-ai-product-image-studio-for-woocommerce' );
		if ( 0 === strpos( $slot, 'variation:' ) ) {
			$variation = function_exists( 'wc_get_product' ) ? wc_get_product( (int) substr( $slot, 10 ) ) : null;
			$label     = $variation ? wp_strip_all_tags( wc_get_formatted_variation( $variation, true, false ) ) : __( 'Variation', 'dev3-ai-product-image-studio-for-woocommerce' );
			/* translators: %s: variation attributes */
			$label = sprintf( __( 'Variation: %s', 'dev3-ai-product-image-studio-for-woocommerce' ), $label );
		}
		return array(
			'key'          => $t['attachment_id'] . ':' . $t['product_id'] . ':' . $slot,
			'attachment'   => $image,
			'product_id'   => (int) $t['product_id'],
			'product_name' => $product ? $product->get_name() : get_the_title( $t['product_id'] ),
			'sku'          => $product ? (string) $product->get_sku() : '',
			'edit_url'     => get_edit_post_link( $t['product_id'], 'raw' ),
			'slot'         => $slot,
			'slot_label'   => $label,
			'flags'        => Audit::flags( $t['attachment_id'], false ),
			'pending'      => (int) $pending,
		);
	}

	/**
	 * Dashboard numbers.
	 *
	 * @return array
	 */
	public static function stats() {
		$targets = self::targets( null, 'all' );
		$unique  = array_values( array_unique( wp_list_pluck( $targets, 'attachment_id' ) ) );
		$stats   = array(
			'images'      => count( $unique ),
			'products'    => count( array_unique( wp_list_pluck( $targets, 'product_id' ) ) ),
			'missing_alt' => 0,
			'low_res'     => 0,
			'not_square'  => 0,
			'not_white'   => 0,
			'unaudited'   => 0,
		);
		foreach ( $unique as $id ) {
			$flags = Audit::flags( $id, false );
			foreach ( array( 'missing_alt', 'low_res', 'not_square' ) as $k ) {
				$stats[ $k ] += (int) $flags[ $k ];
			}
			if ( null === $flags['not_white'] ) {
				++$stats['unaudited'];
			} else {
				$stats['not_white'] += (int) $flags['not_white'];
			}
		}
		return $stats;
	}
}
