<?php
/**
 * Results: every AI/local output is a NEW draft attachment (or an alt-text draft) waiting for approval.
 * Originals are never modified; approving swaps attachment ids in the product, reverting swaps them back.
 *
 * @package Dev3\AiImageStudio
 */

namespace Dev3\AiImageStudio\Studio;

use Dev3\AiImageStudio\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Repository + approval workflow for {prefix}dev3is_results.
 */
class Results {

	const STATUSES = array( 'pending', 'approved', 'rejected', 'reverted', 'failed' );
	const MODES    = array( 'replace', 'add', 'library' );
	const DRAFT    = '_dev3is_draft';
	const SOURCE   = '_dev3is_source';

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'dev3is_results';
	}

	/**
	 * CREATE TABLE for dbDelta (hooked to dev3is_schema_sql).
	 *
	 * @param array  $sql     Statements.
	 * @param string $charset Charset collate.
	 * @return array
	 */
	public static function schema( $sql, $charset ) {
		$table = self::table();
		$sql[] = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			product_id bigint(20) unsigned NOT NULL DEFAULT 0,
			source_id bigint(20) unsigned NOT NULL DEFAULT 0,
			result_id bigint(20) unsigned NOT NULL DEFAULT 0,
			kind varchar(10) NOT NULL DEFAULT 'image',
			operation varchar(30) NOT NULL DEFAULT '',
			status varchar(12) NOT NULL DEFAULT 'pending',
			apply_mode varchar(10) NOT NULL DEFAULT 'replace',
			slot varchar(40) NOT NULL DEFAULT '',
			payload longtext NULL,
			job_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
			decided_at datetime NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY product_id (product_id),
			KEY source_id (source_id),
			KEY job_id (job_id)
		) {$charset};";
		return $sql;
	}

	/**
	 * Inserts a result row.
	 *
	 * @param array $data Fields.
	 * @return int|\WP_Error
	 */
	public static function create( array $data ) {
		global $wpdb;
		$data += array(
			'product_id' => 0,
			'source_id'  => 0,
			'result_id'  => 0,
			'kind'       => 'image',
			'operation'  => '',
			'status'     => 'pending',
			'apply_mode' => (string) Settings::get( 'default_mode', 'replace' ),
			'slot'       => '',
			'payload'    => array(),
			'job_id'     => 0,
			'user_id'    => get_current_user_id(),
		);
		$ok    = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			self::table(),
			array(
				'product_id' => (int) $data['product_id'],
				'source_id'  => (int) $data['source_id'],
				'result_id'  => (int) $data['result_id'],
				'kind'       => 'alt' === $data['kind'] ? 'alt' : 'image',
				'operation'  => substr( sanitize_key( $data['operation'] ), 0, 30 ),
				'status'     => in_array( $data['status'], self::STATUSES, true ) ? $data['status'] : 'pending',
				'apply_mode' => in_array( $data['apply_mode'], self::MODES, true ) ? $data['apply_mode'] : 'replace',
				'slot'       => substr( (string) $data['slot'], 0, 40 ),
				'payload'    => wp_json_encode( (array) $data['payload'] ),
				'job_id'     => (int) $data['job_id'],
				'user_id'    => (int) $data['user_id'],
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' )
		);
		if ( ! $ok ) {
			return new \WP_Error( 'dev3is_db', __( 'The result could not be saved.', 'dev3-ai-product-image-studio-for-woocommerce' ), array( 'status' => 500 ) );
		}
		$id = (int) $wpdb->insert_id;
		if ( $data['result_id'] ) {
			update_post_meta( (int) $data['result_id'], '_dev3is_result', $id );
		}
		/**
		 * A result (draft) was created.
		 *
		 * @param array $result Result (see Results::get()).
		 */
		do_action( 'dev3is_result_created', self::get( $id ) );
		return $id;
	}

	/**
	 * Raw row.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	private static function row( $id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Updates columns.
	 *
	 * @param int   $id   Id.
	 * @param array $data Columns.
	 */
	private static function update( $id, array $data ) {
		global $wpdb;
		if ( isset( $data['payload'] ) && is_array( $data['payload'] ) ) {
			$data['payload'] = wp_json_encode( $data['payload'] );
		}
		$wpdb->update( self::table(), $data, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Result for the API.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public static function get( $id ) {
		$row = self::row( $id );
		return $row ? self::shape( $row ) : null;
	}

	/**
	 * Shapes a row.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	public static function shape( array $row ) {
		$payload = json_decode( (string) $row['payload'], true );
		$payload = is_array( $payload ) ? $payload : array();
		$product = (int) $row['product_id'] ? get_post( (int) $row['product_id'] ) : null;
		return array(
			'id'           => (int) $row['id'],
			'product_id'   => (int) $row['product_id'],
			'product_name' => $product ? html_entity_decode( get_the_title( $product ), ENT_QUOTES, 'UTF-8' ) : '',
			'source_id'    => (int) $row['source_id'],
			'result_id'    => (int) $row['result_id'],
			'kind'         => $row['kind'],
			'operation'    => $row['operation'],
			'status'       => $row['status'],
			'apply_mode'   => $row['apply_mode'],
			'slot'         => $row['slot'],
			'job_id'       => (int) $row['job_id'],
			'user_id'      => (int) $row['user_id'],
			'created_at'   => mysql_to_rfc3339( $row['created_at'] ),
			'decided_at'   => $row['decided_at'] ? mysql_to_rfc3339( $row['decided_at'] ) : null,
			'before'       => self::image( (int) $row['source_id'] ),
			'after'        => (int) $row['result_id'] ? self::image( (int) $row['result_id'] ) : null,
			'alt'          => isset( $payload['alt'] ) ? $payload['alt'] : null,
			'meta'         => array_intersect_key( $payload, array_flip( array( 'model', 'tokens', 'ms', 'label', 'options', 'note', 'prompt', 'variant' ) ) ),
		);
	}

	/**
	 * Image info for the UI.
	 *
	 * @param int $id Attachment id.
	 * @return array|null
	 */
	public static function image( $id ) {
		if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
			return null;
		}
		$full  = wp_get_attachment_image_src( $id, 'full' );
		$large = wp_get_attachment_image_src( $id, 'large' );
		$thumb = wp_get_attachment_image_src( $id, 'medium' );
		$file  = (string) get_attached_file( $id );
		return array(
			'id'       => (int) $id,
			'url'      => $full ? $full[0] : '',
			'large'    => $large ? $large[0] : ( $full ? $full[0] : '' ),
			'thumb'    => $thumb ? $thumb[0] : ( $full ? $full[0] : '' ),
			'width'    => $full ? (int) $full[1] : 0,
			'height'   => $full ? (int) $full[2] : 0,
			'mime'     => (string) get_post_mime_type( $id ),
			'filesize' => is_readable( $file ) ? (int) filesize( $file ) : 0,
			'alt'      => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'title'    => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
			'caption'  => (string) wp_get_attachment_caption( $id ),
		);
	}

	/**
	 * Lists results.
	 *
	 * @param array $args { status, operation, product_id, source_id, job_id, kind, page, per_page }.
	 * @return array { rows, total, counts }
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$table  = self::table();
		$where  = array( '1 = %d' );
		$params = array( 1 );
		foreach ( array( 'status', 'operation', 'kind' ) as $col ) {
			if ( ! empty( $args[ $col ] ) && 'all' !== $args[ $col ] ) {
				$where[]  = "{$col} = %s";
				$params[] = (string) $args[ $col ];
			}
		}
		foreach ( array( 'product_id', 'source_id', 'job_id' ) as $col ) {
			if ( ! empty( $args[ $col ] ) ) {
				$where[]  = "{$col} = %d";
				$params[] = (int) $args[ $col ];
			}
		}
		$per   = min( 100, max( 1, isset( $args['per_page'] ) ? (int) $args['per_page'] : 20 ) );
		$page  = max( 1, isset( $args['page'] ) ? (int) $args['page'] : 1 );
		$sql_w = implode( ' AND ', $where );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$sql_w}", $params ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$sql_w} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $params, array( $per, ( $page - 1 ) * $per ) ) ), ARRAY_A );
		// phpcs:enable
		return array(
			'rows'  => array_map( array( __CLASS__, 'shape' ), (array) $rows ),
			'total' => $total,
		);
	}

	/**
	 * Counts by status.
	 *
	 * @return array status => n
	 */
	public static function counts() {
		global $wpdb;
		$table = self::table();
		$out   = array_fill_keys( self::STATUSES, 0 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A ) as $row ) {
			$out[ $row['status'] ] = (int) $row['n'];
		}
		return $out;
	}

	/**
	 * Pending result counts per source attachment.
	 *
	 * @param int[] $ids Attachment ids.
	 * @return array id => n
	 */
	public static function pending_for( array $ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		$table = self::table();
		$in    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT source_id, COUNT(*) AS n FROM {$table} WHERE status = 'pending' AND source_id IN ({$in}) GROUP BY source_id", $ids ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['source_id'] ] = (int) $row['n'];
		}
		return $out;
	}

	/**
	 * Saves bytes as a new draft attachment next to (never over) the original.
	 *
	 * @param string $bytes      Binary.
	 * @param string $mime       Mime.
	 * @param int    $product_id Product.
	 * @param int    $source_id  Original attachment.
	 * @param string $operation  Operation id.
	 * @param string $label      Human label ("White background").
	 * @return int|\WP_Error Attachment id.
	 */
	public static function save_attachment( $bytes, $mime, $product_id, $source_id, $operation, $label ) {
		$ext = array(
			'image/png'  => 'png',
			'image/jpeg' => 'jpg',
			'image/webp' => 'webp',
		);
		if ( ! isset( $ext[ $mime ] ) || '' === (string) $bytes ) {
			return new \WP_Error( 'dev3is_bad_image', __( 'The result image is invalid.', 'dev3-ai-product-image-studio-for-woocommerce' ), array( 'status' => 422 ) );
		}
		$product = $product_id ? get_post_field( 'post_title', $product_id ) : get_post_field( 'post_title', $source_id );
		$base    = sanitize_title( $product ? $product : 'product-image' );
		$base    = substr( $base, 0, 60 ) . '-' . str_replace( '_', '-', sanitize_key( $operation ) );
		/**
		 * File name (without extension) for a new result image.
		 *
		 * @param string $base       Name.
		 * @param int    $product_id Product.
		 * @param string $operation  Operation.
		 */
		$base   = sanitize_file_name( (string) apply_filters( 'dev3is_result_filename', $base, $product_id, $operation ) );
		$upload = wp_upload_bits( $base . '.' . $ext[ $mime ], null, $bytes );
		if ( ! empty( $upload['error'] ) ) {
			return new \WP_Error( 'dev3is_upload', (string) $upload['error'], array( 'status' => 500 ) );
		}
		$id = wp_insert_attachment(
			array(
				'post_mime_type' => $mime,
				/* translators: 1: product name, 2: operation label */
				'post_title'     => sanitize_text_field( sprintf( __( '%1$s - %2$s', 'dev3-ai-product-image-studio-for-woocommerce' ), $product ? $product : __( 'Image', 'dev3-ai-product-image-studio-for-woocommerce' ), $label ) ),
				'post_status'    => 'inherit',
			),
			$upload['file'],
			(int) $product_id,
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );
		update_post_meta( $id, self::DRAFT, 1 );
		update_post_meta( $id, self::SOURCE, (int) $source_id );
		Audit::whiteness( (int) $id, true );
		$alt = (string) get_post_meta( $source_id, '_wp_attachment_image_alt', true );
		if ( '' !== $alt ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		}
		return (int) $id;
	}

	/**
	 * Places (featured / gallery index / variation) where an attachment is used by a product.
	 *
	 * @param int $product_id Product.
	 * @param int $attachment Attachment id.
	 * @return array [ { type: featured|gallery|variation, id, index } ]
	 */
	public static function places( $product_id, $attachment ) {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		if ( ! $product ) {
			return array();
		}
		$out = array();
		if ( (int) $product->get_image_id() === (int) $attachment ) {
			$out[] = array(
				'type' => 'featured',
				'id'   => (int) $product_id,
			);
		}
		foreach ( array_values( array_map( 'intval', $product->get_gallery_image_ids() ) ) as $i => $gid ) {
			if ( $gid === (int) $attachment ) {
				$out[] = array(
					'type'  => 'gallery',
					'id'    => (int) $product_id,
					'index' => $i,
				);
			}
		}
		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $vid ) {
				if ( (int) get_post_thumbnail_id( $vid ) === (int) $attachment ) {
					$out[] = array(
						'type' => 'variation',
						'id'   => (int) $vid,
					);
				}
			}
		}
		return $out;
	}

	/**
	 * Swaps $from for $to in the given places.
	 *
	 * @param int   $product_id Product.
	 * @param array $places     From places().
	 * @param int   $from       Attachment now in place.
	 * @param int   $to         New attachment.
	 */
	private static function swap( $product_id, array $places, $from, $to ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}
		$gallery = array_values( array_map( 'intval', $product->get_gallery_image_ids() ) );
		$changed = false;
		foreach ( $places as $place ) {
			if ( 'featured' === $place['type'] && (int) $product->get_image_id() === (int) $from ) {
				$product->set_image_id( (int) $to );
				$changed = true;
			} elseif ( 'gallery' === $place['type'] ) {
				$index = isset( $gallery[ $place['index'] ] ) && $gallery[ $place['index'] ] === (int) $from ? $place['index'] : array_search( (int) $from, $gallery, true );
				if ( false !== $index ) {
					$gallery[ $index ] = (int) $to;
					$changed           = true;
				}
			} elseif ( 'variation' === $place['type'] ) {
				$variation = wc_get_product( $place['id'] );
				if ( $variation && (int) $variation->get_image_id() === (int) $from ) {
					$variation->set_image_id( (int) $to );
					$variation->save();
				}
			}
		}
		if ( $changed ) {
			$product->set_gallery_image_ids( $gallery );
			$product->save();
		}
	}

	/**
	 * Approves a result.
	 *
	 * @param int    $id   Result id.
	 * @param string $mode replace|add|library (image results).
	 * @param array  $alt  Edited alt fields for alt results { alt, title, caption }.
	 * @return array|\WP_Error Result.
	 */
	public static function approve( $id, $mode = '', array $alt = array() ) {
		$row = self::row( $id );
		if ( ! $row ) {
			return new \WP_Error( 'dev3is_not_found', __( 'Result not found.', 'dev3-ai-product-image-studio-for-woocommerce' ), array( 'status' => 404 ) );
		}
		if ( 'pending' !== $row['status'] ) {
			return new \WP_Error( 'dev3is_decided', __( 'This result was already reviewed.', 'dev3-ai-product-image-studio-for-woocommerce' ), array( 'status' => 409 ) );
		}
		$payload = (array) json_decode( (string) $row['payload'], true );
		$source  = (int) $row['source_id'];
		if ( 'alt' === $row['kind'] ) {
			$draft               = array_merge( isset( $payload['alt'] ) ? (array) $payload['alt'] : array(), $alt );
			$payload['previous'] = Alt_Text::current( $source );
			Alt_Text::apply( $source, $draft );
			$payload['alt'] = Alt_Text::current( $source );
			$mode           = 'library';
		} else {
			$mode   = in_array( $mode, self::MODES, true ) ? $mode : $row['apply_mode'];
			$result = (int) $row['result_id'];
			if ( ! $result || 'attachment' !== get_post_type( $result ) ) {
				return new \WP_Error( 'dev3is_missing', __( 'The result image no longer exists.', 'dev3-ai-product-image-studio-for-woocommerce' ), array( 'status' => 410 ) );
			}
			$product_id = (int) $row['product_id'];
			if ( 'replace' === $mode ) {
				$places = $product_id ? self::places( $product_id, $source ) : array();
				if ( ! $places ) {
					return new \WP_Error( 'dev3is_not_in_product', __( 'The original image is no longer used by this product. Approve it with "Add to gallery" or "Keep in library" instead.', 'dev3-ai-product-image-studio-for-woocommerce' ), array( 'status' => 409 ) );
				}
				self::swap( $product_id, $places, $source, $result );
				$payload['places'] = $places;
			} elseif ( 'add' === $mode && $product_id && function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( $product_id );
				if ( $product ) {
					$gallery = array_map( 'intval', $product->get_gallery_image_ids() );
					if ( ! in_array( $result, $gallery, true ) ) {
						$gallery[] = $result;
						$product->set_gallery_image_ids( $gallery );
						$product->save();
					}
				}
			}
			delete_post_meta( $result, self::DRAFT );
		}
		self::update(
			$id,
			array(
				'status'     => 'approved',
				'apply_mode' => $mode,
				'payload'    => $payload,
				'decided_at' => current_time( 'mysql', true ),
			)
		);
		$shaped = self::get( $id );
		/**
		 * A result was approved and applied.
		 *
		 * @param array  $result Result.
		 * @param string $mode   replace|add|library.
		 */
		do_action( 'dev3is_result_approved', $shaped, $mode );
		return $shaped;
	}

	/**
	 * Rejects a result (deletes the draft file unless "keep rejected" is on).
	 *
	 * @param int $id Result id.
	 * @return array|\WP_Error
	 */
	public static function reject( $id ) {
		$row = self::row( $id );
		if ( ! $row ) {
			return new \WP_Error( 'dev3is_not_found', __( 'Result not found.', 'dev3-ai-product-image-studio-for-woocommerce' ), array( 'status' => 404 ) );
		}
		if ( 'pending' !== $row['status'] ) {
			return new \WP_Error( 'dev3is_decided', __( 'This result was already reviewed.', 'dev3-ai-product-image-studio-for-woocommerce' ), array( 'status' => 409 ) );
		}
		$result = (int) $row['result_id'];
		if ( $result && get_post_meta( $result, self::DRAFT, true ) && ! Settings::get( 'keep_rejected', false ) ) {
			wp_delete_attachment( $result, true );
		}
		self::update(
			$id,
			array(
				'status'     => 'rejected',
				'decided_at' => current_time( 'mysql', true ),
			)
		);
		$shaped = self::get( $id );
		/**
		 * A result was rejected.
		 *
		 * @param array $result Result.
		 */
		do_action( 'dev3is_result_rejected', $shaped );
		return $shaped;
	}

	/**
	 * Reverts an approved result: the original goes back where the result is now.
	 *
	 * @param int $id Result id.
	 * @return array|\WP_Error
	 */
	public static function revert( $id ) {
		$row = self::row( $id );
		if ( ! $row || 'approved' !== $row['status'] ) {
			return new \WP_Error( 'dev3is_not_approved', __( 'Only approved results can be reverted.', 'dev3-ai-product-image-studio-for-woocommerce' ), array( 'status' => 409 ) );
		}
		$payload = (array) json_decode( (string) $row['payload'], true );
		$source  = (int) $row['source_id'];
		$result  = (int) $row['result_id'];
		$pid     = (int) $row['product_id'];
		if ( 'alt' === $row['kind'] ) {
			Alt_Text::apply( $source, isset( $payload['previous'] ) ? (array) $payload['previous'] : array() );
		} elseif ( 'replace' === $row['apply_mode'] && $pid ) {
			$places = self::places( $pid, $result );
			if ( $places ) {
				self::swap( $pid, $places, $result, $source );
			}
		} elseif ( 'add' === $row['apply_mode'] && $pid ) {
			$product = wc_get_product( $pid );
			if ( $product ) {
				$product->set_gallery_image_ids( array_values( array_diff( array_map( 'intval', $product->get_gallery_image_ids() ), array( $result ) ) ) );
				$product->save();
			}
		}
		self::update(
			$id,
			array(
				'status'     => 'reverted',
				'decided_at' => current_time( 'mysql', true ),
			)
		);
		$shaped = self::get( $id );
		/**
		 * An approved result was reverted.
		 *
		 * @param array $result Result.
		 */
		do_action( 'dev3is_result_reverted', $shaped );
		return $shaped;
	}

	/**
	 * Hides pending drafts from the media library grid.
	 *
	 * @param array $query Query args.
	 * @return array
	 */
	public static function hide_drafts_ajax( $query ) {
		if ( ! Settings::get( 'hide_drafts', true ) ) {
			return $query;
		}
		$meta                = isset( $query['meta_query'] ) && is_array( $query['meta_query'] ) ? $query['meta_query'] : array();
		$meta[]              = array(
			'key'     => self::DRAFT,
			'compare' => 'NOT EXISTS',
		);
		$query['meta_query'] = $meta; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		return $query;
	}

	/**
	 * Hides pending drafts from the media list view.
	 *
	 * @param \WP_Query $query Query.
	 */
	public static function hide_drafts_list( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || 'attachment' !== $query->get( 'post_type' ) || ! Settings::get( 'hide_drafts', true ) ) {
			return;
		}
		global $pagenow;
		if ( 'upload.php' !== $pagenow ) {
			return;
		}
		$meta   = (array) $query->get( 'meta_query' );
		$meta[] = array(
			'key'     => self::DRAFT,
			'compare' => 'NOT EXISTS',
		);
		$query->set( 'meta_query', $meta );
	}
}
