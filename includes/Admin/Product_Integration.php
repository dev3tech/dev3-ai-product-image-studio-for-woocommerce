<?php
/**
 * WooCommerce + media library entry points: product metabox, Products bulk action, media field link.
 *
 * @package Dev3\AiImageStudio
 */

namespace Dev3\AiImageStudio\Admin;

use Dev3\AiImageStudio\Rest\Rest_Controller;
use Dev3\AiImageStudio\Studio\Library;
use Dev3\AiImageStudio\Studio\Results;

defined( 'ABSPATH' ) || exit;

/**
 * Admin hooks outside the app page.
 */
class Product_Integration {

	/**
	 * Registers hooks.
	 */
	public function register() {
		add_action( 'add_meta_boxes_product', array( $this, 'metabox' ) );
		add_filter( 'bulk_actions-edit-product', array( $this, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-product', array( $this, 'handle_bulk' ), 10, 3 );
		add_filter( 'attachment_fields_to_edit', array( $this, 'media_field' ), 10, 2 );
		add_filter( 'post_row_actions', array( $this, 'row_action' ), 10, 2 );
	}

	/**
	 * Studio URL.
	 *
	 * @param string $route Hash route.
	 * @return string
	 */
	public static function url( $route = '' ) {
		return admin_url( 'admin.php?page=' . DEV3IS_SLUG ) . ( '' !== $route ? '#/' . ltrim( $route, '/' ) : '' );
	}

	/**
	 * Adds the metabox.
	 */
	public function metabox() {
		if ( ! Rest_Controller::user_can_studio() ) {
			return;
		}
		add_meta_box( 'dev3is-studio', __( 'DEV3 AI Image Studio', 'dev3-ai-product-image-studio-for-woocommerce' ), array( $this, 'render_metabox' ), 'product', 'side', 'low' );
	}

	/**
	 * Metabox body: images with "Open in studio" links + pending count.
	 *
	 * @param \WP_Post $post Product.
	 */
	public function render_metabox( $post ) {
		$targets = Library::targets( array( (int) $post->ID ), 'all' );
		if ( ! $targets ) {
			echo '<p>' . esc_html__( 'Add a product image, then edit it here with DEV3 AI: background removal, pack shots, lifestyle scenes, upscaling and alt text.', 'dev3-ai-product-image-studio-for-woocommerce' ) . '</p>';
			return;
		}
		$pending = Results::pending_for( wp_list_pluck( $targets, 'attachment_id' ) );
		echo '<ul class="dev3is-mb" style="display:grid;grid-template-columns:repeat(4,1fr);gap:6px;margin:0 0 10px;padding:0;list-style:none">';
		foreach ( array_slice( $targets, 0, 12 ) as $t ) {
			$link = self::url( 'studio/' . $t['attachment_id'] . '/' . $t['product_id'] . '/' . $t['slot'] );
			$n    = isset( $pending[ $t['attachment_id'] ] ) ? (int) $pending[ $t['attachment_id'] ] : 0;
			echo '<li style="position:relative"><a href="' . esc_url( $link ) . '" title="' . esc_attr__( 'Open in DEV3 AI Image Studio', 'dev3-ai-product-image-studio-for-woocommerce' ) . '" style="display:block;border:1px solid #dcdcde;border-radius:4px;overflow:hidden">';
			echo wp_get_attachment_image( $t['attachment_id'], array( 80, 80 ), false, array( 'style' => 'display:block;width:100%;height:auto;aspect-ratio:1;object-fit:cover' ) );
			echo '<span class="screen-reader-text">' . esc_html__( 'Open in DEV3 AI Image Studio', 'dev3-ai-product-image-studio-for-woocommerce' ) . '</span></a>';
			if ( $n ) {
				/* translators: %d: pending results */
				echo '<span style="position:absolute;top:-6px;right:-6px;background:#d63638;color:#fff;border-radius:9px;min-width:18px;height:18px;font-size:11px;line-height:18px;text-align:center" aria-label="' . esc_attr( sprintf( _n( '%d result waiting for review', '%d results waiting for review', $n, 'dev3-ai-product-image-studio-for-woocommerce' ), $n ) ) . '">' . (int) $n . '</span>';
			}
			echo '</li>';
		}
		echo '</ul>';
		echo '<p style="margin:0"><a class="button" href="' . esc_url( self::url( 'batch/products/' . (int) $post->ID ) ) . '">' . esc_html__( 'Batch edit all images', 'dev3-ai-product-image-studio-for-woocommerce' ) . '</a></p>';
	}

	/**
	 * Products list bulk action.
	 *
	 * @param array $actions Actions.
	 * @return array
	 */
	public function bulk_actions( $actions ) {
		if ( Rest_Controller::user_can_studio() ) {
			$actions['dev3is_studio'] = __( 'Edit images in DEV3 AI Image Studio', 'dev3-ai-product-image-studio-for-woocommerce' );
		}
		return $actions;
	}

	/**
	 * Redirects the bulk action to the batch screen with the products preselected.
	 *
	 * @param string $redirect Redirect URL.
	 * @param string $action   Action.
	 * @param int[]  $ids      Post ids.
	 * @return string
	 */
	public function handle_bulk( $redirect, $action, $ids ) {
		if ( 'dev3is_studio' !== $action || ! Rest_Controller::user_can_studio() ) {
			return $redirect;
		}
		$ids = array_slice( array_filter( array_map( 'intval', (array) $ids ) ), 0, 500 );
		return self::url( 'batch/products/' . implode( ',', $ids ) );
	}

	/**
	 * Products list row action.
	 *
	 * @param array    $actions Actions.
	 * @param \WP_Post $post    Post.
	 * @return array
	 */
	public function row_action( $actions, $post ) {
		if ( 'product' === $post->post_type && Rest_Controller::user_can_studio() && has_post_thumbnail( $post ) ) {
			$thumb                    = (int) get_post_thumbnail_id( $post );
			$actions['dev3is_studio'] = '<a href="' . esc_url( self::url( 'studio/' . $thumb . '/' . (int) $post->ID . '/featured' ) ) . '">' . esc_html__( 'Image Studio', 'dev3-ai-product-image-studio-for-woocommerce' ) . '</a>';
		}
		return $actions;
	}

	/**
	 * Media modal / attachment edit link.
	 *
	 * @param array    $fields Fields.
	 * @param \WP_Post $post   Attachment.
	 * @return array
	 */
	public function media_field( $fields, $post ) {
		if ( ! wp_attachment_is_image( $post->ID ) || ! Rest_Controller::user_can_studio() ) {
			return $fields;
		}
		$parent                  = (int) $post->post_parent;
		$route                   = 'studio/' . (int) $post->ID . ( $parent && 'product' === get_post_type( $parent ) ? '/' . $parent : '' );
		$fields['dev3is_studio'] = array(
			'label' => __( 'DEV3 AI', 'dev3-ai-product-image-studio-for-woocommerce' ),
			'input' => 'html',
			'html'  => '<a class="button button-small" href="' . esc_url( self::url( $route ) ) . '">' . esc_html__( 'Open in DEV3 AI Image Studio', 'dev3-ai-product-image-studio-for-woocommerce' ) . '</a>',
		);
		return $fields;
	}
}
