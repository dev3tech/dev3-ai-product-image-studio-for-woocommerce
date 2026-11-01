<?php
/**
 * Alt text, title and caption: AI vision drafts, templates (no AI) and applying them.
 *
 * @package Dev3\AiImageStudio
 */

namespace Dev3\AiImageStudio\Studio;

use Dev3\AiImageStudio\Core3\Api\Prompts;
use Dev3\AiImageStudio\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Text for product images.
 */
class Alt_Text {

	const ALT_MAX = 125;

	/**
	 * Registers the text prompts (alt text + scene ideas) in the core prompt library.
	 */
	public static function register_prompts() {
		Prompts::register(
			'alt_text',
			array(
				'version'  => '1',
				'task'     => 'alt_text',
				'system'   => 'You write accessible, SEO-friendly image text for an online shop. Describe what is visible: product type, colour, material, angle and setting. Never start with "Image of" or "Picture of". No marketing claims, no emojis.',
				'template' => "Product: {{product}}\nCategory: {{category}}\nAttributes: {{attributes}}\nLanguage: {{language}}\nWrite: alt (max 125 characters, one sentence, no trailing period needed), title (max 70 characters) and caption (max 160 characters, one friendly sentence) for the attached product image.",
				'schema'   => array(
					'type'       => 'OBJECT',
					'properties' => array(
						'alt'     => array(
							'type'      => 'STRING',
							'maxLength' => self::ALT_MAX,
						),
						'title'   => array(
							'type'      => 'STRING',
							'maxLength' => 80,
						),
						'caption' => array(
							'type'      => 'STRING',
							'maxLength' => 200,
						),
					),
					'required'   => array( 'alt', 'title', 'caption' ),
				),
				'profile'  => 'fast',
				'cache'    => WEEK_IN_SECONDS,
			)
		);
		Prompts::register(
			'scene_ideas',
			array(
				'version'  => '1',
				'task'     => 'scene_ideas',
				'system'   => 'You are an e-commerce art director. You suggest photorealistic lifestyle settings for product photos that fit the product and its buyers.',
				'template' => "Product: {{product}}\nCategory: {{category}}\nDescription: {{description}}\nBrand style: {{style}}\nSuggest 4 different lifestyle scene settings for this product. Each: a short label (max 40 characters) and a setting description (max 240 characters) describing surface, surroundings, light and mood, written to complete the sentence \"Place the product ...\".",
				'schema'   => array(
					'type'       => 'OBJECT',
					'properties' => array(
						'ideas' => array(
							'type'     => 'ARRAY',
							'maxItems' => 4,
							'items'    => array(
								'type'       => 'OBJECT',
								'properties' => array(
									'label'  => array(
										'type'      => 'STRING',
										'maxLength' => 40,
									),
									'prompt' => array(
										'type'      => 'STRING',
										'maxLength' => 240,
									),
								),
								'required'   => array( 'label', 'prompt' ),
							),
						),
					),
					'required'   => array( 'ideas' ),
				),
				'profile'  => 'fast',
				'cache'    => DAY_IN_SECONDS,
			)
		);
	}

	/**
	 * Current alt/title/caption.
	 *
	 * @param int $id Attachment id.
	 * @return array
	 */
	public static function current( $id ) {
		return array(
			'alt'     => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'title'   => (string) get_post_field( 'post_title', $id ),
			'caption' => (string) wp_get_attachment_caption( $id ),
		);
	}

	/**
	 * Cleans a draft.
	 *
	 * @param array $fields Raw { alt, title, caption }.
	 * @return array
	 */
	public static function clean( array $fields ) {
		$out = array();
		if ( isset( $fields['alt'] ) ) {
			$out['alt'] = mb_substr( trim( sanitize_text_field( (string) $fields['alt'] ) ), 0, self::ALT_MAX );
		}
		if ( isset( $fields['title'] ) ) {
			$out['title'] = mb_substr( trim( sanitize_text_field( (string) $fields['title'] ) ), 0, 120 );
		}
		if ( isset( $fields['caption'] ) ) {
			$out['caption'] = mb_substr( trim( sanitize_textarea_field( (string) $fields['caption'] ) ), 0, 300 );
		}
		return $out;
	}

	/**
	 * Saves alt/title/caption on an attachment.
	 *
	 * @param int   $id     Attachment id.
	 * @param array $fields { alt, title, caption } (missing keys untouched).
	 * @return array Current values.
	 */
	public static function apply( $id, array $fields ) {
		$fields = self::clean( $fields );
		if ( isset( $fields['alt'] ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $fields['alt'] );
		}
		$post = array();
		if ( isset( $fields['title'] ) && '' !== $fields['title'] ) {
			$post['post_title'] = $fields['title'];
		}
		if ( isset( $fields['caption'] ) ) {
			$post['post_excerpt'] = $fields['caption'];
		}
		if ( $post ) {
			$post['ID'] = (int) $id;
			wp_update_post( $post );
		}
		return self::current( $id );
	}

	/**
	 * Product context for prompts and templates.
	 *
	 * @param int $product_id Product.
	 * @return array { product, category, attributes, sku, description, product_obj }
	 */
	public static function product_context( $product_id ) {
		$product = $product_id && function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		if ( ! $product ) {
			return array(
				'product'     => '',
				'category'    => '',
				'attributes'  => '',
				'sku'         => '',
				'description' => '',
				'attrs'       => array(),
			);
		}
		$terms = get_the_terms( $product->get_id(), 'product_cat' );
		$cat   = is_array( $terms ) && $terms ? $terms[0]->name : '';
		$attrs = array();
		foreach ( $product->get_attributes() as $key => $attr ) {
			$values = $attr->is_taxonomy() ? wc_get_product_terms( $product->get_id(), $attr->get_name(), array( 'fields' => 'names' ) ) : $attr->get_options();
			if ( $values ) {
				$attrs[ sanitize_title( $key ) ] = implode( ', ', array_map( 'strval', (array) $values ) );
			}
		}
		$list = array();
		foreach ( $attrs as $k => $v ) {
			$list[] = wc_attribute_label( $k ) . ': ' . $v;
		}
		return array(
			'product'     => $product->get_name(),
			'category'    => $cat,
			'attributes'  => mb_substr( implode( '; ', $list ), 0, 300 ),
			'sku'         => (string) $product->get_sku(),
			'description' => mb_substr( wp_strip_all_tags( $product->get_short_description() ? $product->get_short_description() : $product->get_description() ), 0, 500 ),
			'attrs'       => $attrs,
		);
	}

	/**
	 * AI draft (nothing is saved).
	 *
	 * @param int    $id         Attachment id.
	 * @param int    $product_id Product.
	 * @param string $context    Usage context.
	 * @return array|\WP_Error { draft, original, model, tokens, cached }
	 */
	public static function suggest( $id, $product_id = 0, $context = 'admin' ) {
		if ( ! wp_attachment_is_image( $id ) ) {
			return new \WP_Error( 'dev3is_not_image', __( 'That attachment is not an image.', 'dev3-ai-product-image-studio-for-woocommerce' ), array( 'status' => 400 ) );
		}
		$input = Operations::input( $id );
		if ( is_wp_error( $input ) ) {
			return $input;
		}
		$ctx    = self::product_context( $product_id );
		$lang   = trim( (string) Settings::get( 'alt_language', '' ) );
		$result = Prompts::run(
			'alt_text',
			array(
				'product'    => '' !== $ctx['product'] ? $ctx['product'] : (string) get_post_field( 'post_title', $id ),
				'category'   => $ctx['category'],
				'attributes' => $ctx['attributes'],
				'language'   => '' !== $lang ? $lang : get_bloginfo( 'language' ),
			),
			$context,
			array(
				'images' => array(
					array(
						'mime' => $input['mime'],
						'data' => $input['data'],
					),
				),
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$data = isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
		return array(
			'draft'    => array_merge(
				array(
					'alt'     => '',
					'title'   => '',
					'caption' => '',
				),
				self::clean( $data )
			),
			'original' => self::current( $id ),
			'model'    => (string) $result['model'],
			'tokens'   => isset( $result['usage']['total'] ) ? (int) $result['usage']['total'] : 0,
			'cached'   => ! empty( $result['cached'] ),
		);
	}

	/**
	 * AI scene ideas for a product.
	 *
	 * @param int $product_id Product.
	 * @return array|\WP_Error { ideas, model, tokens }
	 */
	public static function scene_ideas( $product_id ) {
		$ctx = self::product_context( $product_id );
		if ( '' === $ctx['product'] ) {
			return new \WP_Error( 'dev3is_no_product', __( 'Pick a product first.', 'dev3-ai-product-image-studio-for-woocommerce' ), array( 'status' => 400 ) );
		}
		$result = Prompts::run(
			'scene_ideas',
			array(
				'product'     => $ctx['product'],
				'category'    => $ctx['category'],
				'description' => $ctx['description'],
				'style'       => (string) Settings::get( 'brand_style', '' ),
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$ideas = array();
		foreach ( isset( $result['data']['ideas'] ) ? (array) $result['data']['ideas'] : array() as $idea ) {
			$label  = mb_substr( sanitize_text_field( (string) ( $idea['label'] ?? '' ) ), 0, 40 );
			$prompt = mb_substr( sanitize_textarea_field( (string) ( $idea['prompt'] ?? '' ) ), 0, 240 );
			if ( '' !== $label && '' !== $prompt ) {
				$ideas[] = compact( 'label', 'prompt' );
			}
		}
		return array(
			'ideas'  => array_slice( $ideas, 0, 4 ),
			'model'  => (string) $result['model'],
			'tokens' => isset( $result['usage']['total'] ) ? (int) $result['usage']['total'] : 0,
		);
	}

	/**
	 * Fills an alt template (no AI): {product} {category} {sku} {site} {n} {attribute:pa_color}.
	 *
	 * @param string $template   Template.
	 * @param int    $product_id Product.
	 * @param int    $n          Image number within the product.
	 * @return string
	 */
	public static function template( $template, $product_id, $n = 1 ) {
		$ctx  = self::product_context( $product_id );
		$vars = array(
			'product'  => $ctx['product'],
			'category' => $ctx['category'],
			'sku'      => $ctx['sku'],
			'site'     => get_bloginfo( 'name' ),
			'n'        => (string) (int) $n,
		);
		/**
		 * Placeholder values for alt text templates.
		 *
		 * @param array $vars       name => value.
		 * @param int   $product_id Product.
		 */
		$vars = (array) apply_filters( 'dev3is_alt_template_vars', $vars, $product_id );
		$out  = preg_replace_callback(
			'/\{(attribute:)?([a-z0-9_-]+)\}/i',
			static function ( $m ) use ( $vars, $ctx ) {
				if ( '' !== $m[1] ) {
					$key = sanitize_title( $m[2] );
					return isset( $ctx['attrs'][ $key ] ) ? $ctx['attrs'][ $key ] : ( isset( $ctx['attrs'][ 'pa_' . $key ] ) ? $ctx['attrs'][ 'pa_' . $key ] : '' );
				}
				return isset( $vars[ $m[2] ] ) ? (string) $vars[ $m[2] ] : '';
			},
			(string) $template
		);
		// Tidy separators left by empty placeholders ("Mug - " → "Mug").
		$out = preg_replace( '/\s*([-|,·])\s*(?=$|[-|,·])/u', '', (string) $out );
		$out = trim( preg_replace( '/\s{2,}/', ' ', (string) $out ), " \t-|,·" );
		return mb_substr( sanitize_text_field( $out ), 0, self::ALT_MAX );
	}
}
