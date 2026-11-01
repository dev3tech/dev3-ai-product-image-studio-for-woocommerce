<?php
/**
 * Recipes (saved operation + options) and lifestyle scene presets.
 *
 * @package Dev3\AiImageStudio
 */

namespace Dev3\AiImageStudio\Studio;

defined( 'ABSPATH' ) || exit;

/**
 * Stored in the dev3is_recipes option.
 */
class Recipes {

	const OPTION = 'dev3is_recipes';

	/**
	 * Built-in scene presets.
	 *
	 * @return array [ { id, label, prompt } ]
	 */
	public static function default_scenes() {
		$scenes = array(
			array(
				'id'     => 'kitchen',
				'label'  => __( 'Kitchen counter', 'dev3-ai-product-image-studio-for-woocommerce' ),
				'prompt' => 'on a bright, clean kitchen countertop of light stone, soft morning window light, blurred modern kitchen in the background',
			),
			array(
				'id'     => 'marble',
				'label'  => __( 'Marble slab', 'dev3-ai-product-image-studio-for-woocommerce' ),
				'prompt' => 'on a white Carrara marble slab with soft diffused daylight and a gentle shadow, minimal luxury styling',
			),
			array(
				'id'     => 'wood',
				'label'  => __( 'Wooden table', 'dev3-ai-product-image-studio-for-woocommerce' ),
				'prompt' => 'on a warm oak wooden table with natural side light and a softly blurred cosy living room behind',
			),
			array(
				'id'     => 'outdoor',
				'label'  => __( 'Outdoor nature', 'dev3-ai-product-image-studio-for-woocommerce' ),
				'prompt' => 'outdoors on a flat rock surrounded by moss and ferns, golden-hour sunlight, shallow depth of field',
			),
			array(
				'id'     => 'studio',
				'label'  => __( 'Studio gradient', 'dev3-ai-product-image-studio-for-woocommerce' ),
				'prompt' => 'on a seamless studio backdrop with a soft pastel gradient, professional three-point lighting and a soft floor reflection',
			),
			array(
				'id'     => 'holiday',
				'label'  => __( 'Holiday gift', 'dev3-ai-product-image-studio-for-woocommerce' ),
				'prompt' => 'on a festive table with wrapped gifts, pine branches and warm fairy lights softly blurred in the background',
			),
			array(
				'id'     => 'bathroom',
				'label'  => __( 'Bathroom shelf', 'dev3-ai-product-image-studio-for-woocommerce' ),
				'prompt' => 'on a clean bathroom shelf next to folded white towels and a small plant, bright spa-like daylight',
			),
			array(
				'id'     => 'desk',
				'label'  => __( 'Work desk', 'dev3-ai-product-image-studio-for-woocommerce' ),
				'prompt' => 'on a tidy modern work desk with a laptop and a notebook softly out of focus, cool natural daylight',
			),
		);
		/**
		 * Built-in lifestyle scene presets.
		 *
		 * @param array $scenes [ { id, label, prompt } ].
		 */
		return (array) apply_filters( 'dev3is_scene_presets', $scenes );
	}

	/**
	 * Built-in recipes.
	 *
	 * @return array [ { id, name, operation, options, builtin } ]
	 */
	public static function default_recipes() {
		$recipes = array(
			array(
				'id'        => 'marketplace-white',
				'name'      => __( 'Marketplace white 1:1', 'dev3-ai-product-image-studio-for-woocommerce' ),
				'operation' => 'packshot',
				'options'   => array(
					'color'  => '#ffffff',
					'fill'   => 85,
					'size'   => 2000,
					'shadow' => true,
				),
			),
			array(
				'id'        => 'transparent-png',
				'name'      => __( 'Transparent PNG', 'dev3-ai-product-image-studio-for-woocommerce' ),
				'operation' => 'remove_bg',
				'options'   => array( 'background' => 'transparent' ),
			),
			array(
				'id'        => 'kitchen-45',
				'name'      => __( 'Kitchen lifestyle 4:5', 'dev3-ai-product-image-studio-for-woocommerce' ),
				'operation' => 'scene',
				'options'   => array(
					'preset'   => 'kitchen',
					'aspect'   => '4:5',
					'variants' => 2,
				),
			),
			array(
				'id'        => 'hd-upscale',
				'name'      => __( 'HD upscale 2x', 'dev3-ai-product-image-studio-for-woocommerce' ),
				'operation' => 'upscale',
				'options'   => array( 'factor' => 2 ),
			),
			array(
				'id'        => 'square-pad',
				'name'      => __( 'Square pad (no AI)', 'dev3-ai-product-image-studio-for-woocommerce' ),
				'operation' => 'square',
				'options'   => array(
					'color' => '#ffffff',
					'fill'  => 90,
				),
			),
		);
		/**
		 * Built-in recipes.
		 *
		 * @param array $recipes Recipes.
		 */
		return (array) apply_filters( 'dev3is_recipes_defaults', $recipes );
	}

	/**
	 * Stored data merged with defaults.
	 *
	 * @return array { recipes, scenes }
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		return array(
			'recipes' => isset( $stored['recipes'] ) && is_array( $stored['recipes'] ) ? $stored['recipes'] : self::default_recipes(),
			'scenes'  => isset( $stored['scenes'] ) && is_array( $stored['scenes'] ) ? $stored['scenes'] : self::default_scenes(),
		);
	}

	/**
	 * One recipe.
	 *
	 * @param string $id Id.
	 * @return array|null
	 */
	public static function recipe( $id ) {
		foreach ( self::all()['recipes'] as $recipe ) {
			if ( $recipe['id'] === $id ) {
				return $recipe;
			}
		}
		return null;
	}

	/**
	 * Scene prompt by preset id.
	 *
	 * @param string $id Id.
	 * @return string
	 */
	public static function scene_prompt( $id ) {
		foreach ( self::all()['scenes'] as $scene ) {
			if ( $scene['id'] === $id ) {
				return (string) $scene['prompt'];
			}
		}
		return '';
	}

	/**
	 * Validates and saves.
	 *
	 * @param array $input { recipes?, scenes? }.
	 * @return array|\WP_Error Saved data.
	 */
	public static function save( array $input ) {
		$data = self::all();
		if ( isset( $input['recipes'] ) ) {
			$recipes = array();
			$ids     = array();
			foreach ( array_slice( (array) $input['recipes'], 0, 50 ) as $raw ) {
				$raw  = (array) $raw;
				$op   = isset( $raw['operation'] ) ? sanitize_key( $raw['operation'] ) : '';
				$name = isset( $raw['name'] ) ? mb_substr( sanitize_text_field( (string) $raw['name'] ), 0, 60 ) : '';
				if ( '' === $name || ! Operations::exists( $op ) ) {
					return new \WP_Error( 'dev3is_bad_recipe', __( 'Every recipe needs a name and a valid operation.', 'dev3-ai-product-image-studio-for-woocommerce' ), array( 'status' => 400 ) );
				}
				$id = isset( $raw['id'] ) && '' !== sanitize_title( (string) $raw['id'] ) ? sanitize_title( (string) $raw['id'] ) : sanitize_title( $name );
				while ( isset( $ids[ $id ] ) ) {
					$id .= '-2';
				}
				$ids[ $id ] = true;
				$recipes[]  = array(
					'id'        => substr( $id, 0, 60 ),
					'name'      => $name,
					'operation' => $op,
					'options'   => Operations::options( $op, isset( $raw['options'] ) ? (array) $raw['options'] : array() ),
				);
			}
			$data['recipes'] = $recipes;
		}
		if ( isset( $input['scenes'] ) ) {
			$scenes = array();
			foreach ( array_slice( (array) $input['scenes'], 0, 40 ) as $raw ) {
				$raw    = (array) $raw;
				$label  = isset( $raw['label'] ) ? mb_substr( sanitize_text_field( (string) $raw['label'] ), 0, 60 ) : '';
				$prompt = isset( $raw['prompt'] ) ? mb_substr( sanitize_textarea_field( (string) $raw['prompt'] ), 0, 500 ) : '';
				if ( '' === $label || '' === $prompt ) {
					return new \WP_Error( 'dev3is_bad_scene', __( 'Every scene needs a name and a description.', 'dev3-ai-product-image-studio-for-woocommerce' ), array( 'status' => 400 ) );
				}
				$scenes[] = array(
					'id'     => isset( $raw['id'] ) && '' !== sanitize_key( $raw['id'] ) ? substr( sanitize_key( $raw['id'] ), 0, 40 ) : substr( sanitize_key( sanitize_title( $label ) ), 0, 40 ),
					'label'  => $label,
					'prompt' => $prompt,
				);
			}
			$data['scenes'] = $scenes;
		}
		update_option( self::OPTION, $data, false );
		return self::all();
	}

	/**
	 * Restores the built-ins.
	 *
	 * @return array
	 */
	public static function reset() {
		delete_option( self::OPTION );
		return self::all();
	}
}
