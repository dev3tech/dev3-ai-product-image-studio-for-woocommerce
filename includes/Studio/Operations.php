<?php
/**
 * Image operations (AI edits through Ai::image with the product photo as input, and local GD tools).
 * Every run saves NEW draft attachments + pending results; the original file is only read.
 *
 * @package Dev3\AiImageStudio
 */

namespace Dev3\AiImageStudio\Studio;

use Dev3\AiImageStudio\Core3\Api\Ai;
use Dev3\AiImageStudio\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Operation registry + runner.
 */
class Operations {

	const AI    = array( 'remove_bg', 'packshot', 'scene', 'upscale', 'edit' );
	const LOCAL = array( 'square', 'local_upscale', 'flatten', 'convert' );

	/**
	 * Whether an operation exists.
	 *
	 * @param string $op Operation.
	 * @return bool
	 */
	public static function exists( $op ) {
		return in_array( $op, array_merge( self::AI, self::LOCAL ), true );
	}

	/**
	 * Whether an operation needs AI.
	 *
	 * @param string $op Operation.
	 * @return bool
	 */
	public static function is_ai( $op ) {
		return in_array( $op, self::AI, true ) || 'alt' === $op;
	}

	/**
	 * Labels for the UI and attachment titles.
	 *
	 * @return array
	 */
	public static function labels() {
		return array(
			'remove_bg'     => __( 'Background removed', 'dev3-ai-product-image-studio-for-woocommerce' ),
			'packshot'      => __( 'White pack shot', 'dev3-ai-product-image-studio-for-woocommerce' ),
			'scene'         => __( 'Lifestyle scene', 'dev3-ai-product-image-studio-for-woocommerce' ),
			'upscale'       => __( 'AI upscaled', 'dev3-ai-product-image-studio-for-woocommerce' ),
			'edit'          => __( 'AI edit', 'dev3-ai-product-image-studio-for-woocommerce' ),
			'square'        => __( 'Square canvas', 'dev3-ai-product-image-studio-for-woocommerce' ),
			'local_upscale' => __( 'Upscaled', 'dev3-ai-product-image-studio-for-woocommerce' ),
			'flatten'       => __( 'Flattened', 'dev3-ai-product-image-studio-for-woocommerce' ),
			'convert'       => __( 'Converted', 'dev3-ai-product-image-studio-for-woocommerce' ),
			'alt'           => __( 'Alt text', 'dev3-ai-product-image-studio-for-woocommerce' ),
			'alt_template'  => __( 'Alt text (template)', 'dev3-ai-product-image-studio-for-woocommerce' ),
			'audit'         => __( 'Image audit', 'dev3-ai-product-image-studio-for-woocommerce' ),
		);
	}

	/**
	 * Sanitizes options for an operation.
	 *
	 * @param string $op  Operation.
	 * @param array  $raw Raw options.
	 * @return array
	 */
	public static function options( $op, array $raw ) {
		$color = isset( $raw['color'] ) ? sanitize_hex_color( (string) $raw['color'] ) : '';
		$color = $color ? strtolower( $color ) : (string) Settings::get( 'bg_color', '#ffffff' );
		$fill  = isset( $raw['fill'] ) ? min( 100, max( 40, (int) $raw['fill'] ) ) : (int) Settings::get( 'fill_ratio', 85 );
		$size  = isset( $raw['size'] ) ? min( Image_Tools::HARD_MAX, max( 0, (int) $raw['size'] ) ) : 0;
		switch ( $op ) {
			case 'remove_bg':
				$bg = isset( $raw['background'] ) && in_array( $raw['background'], array( 'white', 'color', 'transparent' ), true ) ? $raw['background'] : 'white';
				return array(
					'background' => $bg,
					'color'      => 'white' === $bg ? '#ffffff' : $color,
				);
			case 'packshot':
				return array(
					'color'  => $color,
					'fill'   => $fill,
					'size'   => $size,
					'shadow' => isset( $raw['shadow'] ) ? (bool) $raw['shadow'] : (bool) Settings::get( 'shadow', true ),
				);
			case 'scene':
				$aspect = isset( $raw['aspect'] ) && in_array( $raw['aspect'], array( '1:1', '4:5', '3:4', '4:3', '16:9', '9:16' ), true ) ? $raw['aspect'] : '1:1';
				return array(
					'preset'   => isset( $raw['preset'] ) ? sanitize_key( $raw['preset'] ) : '',
					'prompt'   => isset( $raw['prompt'] ) ? mb_substr( sanitize_textarea_field( (string) $raw['prompt'] ), 0, 500 ) : '',
					'aspect'   => $aspect,
					'variants' => isset( $raw['variants'] ) ? min( 4, max( 1, (int) $raw['variants'] ) ) : 1,
				);
			case 'upscale':
			case 'local_upscale':
				return array(
					'factor' => isset( $raw['factor'] ) && in_array( (int) $raw['factor'], array( 2, 3, 4 ), true ) ? (int) $raw['factor'] : 2,
					'long'   => isset( $raw['long'] ) ? min( Image_Tools::HARD_MAX, max( 0, (int) $raw['long'] ) ) : 0,
				);
			case 'edit':
				return array( 'instruction' => isset( $raw['instruction'] ) ? mb_substr( sanitize_textarea_field( (string) $raw['instruction'] ), 0, 500 ) : '' );
			case 'square':
				return array(
					'color'       => $color,
					'fill'        => $fill,
					'size'        => $size,
					'transparent' => ! empty( $raw['transparent'] ),
				);
			case 'flatten':
				return array( 'color' => $color );
			case 'convert':
				return array(
					'format'  => isset( $raw['format'] ) && in_array( $raw['format'], array( 'png', 'jpeg', 'webp' ), true ) ? $raw['format'] : 'webp',
					'quality' => isset( $raw['quality'] ) ? min( 100, max( 40, (int) $raw['quality'] ) ) : (int) Settings::get( 'jpeg_quality', 90 ),
				);
		}
		return array();
	}

	/**
	 * Prompt for an AI operation.
	 *
	 * @param string $op      Operation.
	 * @param array  $o       Options (sanitized).
	 * @param int    $product Product id.
	 * @return string
	 */
	public static function prompt( $op, array $o, $product = 0 ) {
		$name  = $product ? wp_strip_all_tags( (string) get_post_field( 'post_title', $product ) ) : '';
		$about = '' !== $name ? sprintf( 'The product is: "%s". ', mb_substr( $name, 0, 120 ) ) : '';
		$keep  = 'Keep the product exactly as it is: the same shape, proportions, colours, materials, label text and logos. Do not add, remove or redraw any part of the product, and keep the whole product in frame. ';
		switch ( $op ) {
			case 'remove_bg':
				if ( 'transparent' === $o['background'] ) {
					$bg = 'Place it on a perfectly flat, uniform pure chroma-key green background (#00FF00) that will be keyed out. No shadow, no gradient, no floor line, no green reflections or green tint on the product. ';
				} else {
					$bg = sprintf( 'Place it on a perfectly flat, uniform solid background of colour %s. No shadow, no gradient, no floor line. ', strtoupper( $o['color'] ) );
				}
				$prompt = 'Remove the background from this product photo. ' . $about . $keep . $bg . 'Return only the edited image.';
				break;
			case 'packshot':
				$prompt = 'Turn this photo into a professional e-commerce pack shot. ' . $about . $keep
					. sprintf( 'Show the product centred and upright on a seamless pure %s background (%s) with even, soft studio lighting', '#ffffff' === $o['color'] ? 'white' : 'solid colour', strtoupper( $o['color'] ) )
					. ( $o['shadow'] ? ', and a subtle, soft natural contact shadow directly beneath it. ' : ', with no shadow. ' )
					. 'Remove hands, props, packaging clutter and reflections that are not part of the product. Correct the white balance and exposure. Return only the edited image.';
				break;
			case 'scene':
				$scene  = '' !== $o['prompt'] ? $o['prompt'] : Recipes::scene_prompt( $o['preset'] );
				$style  = trim( (string) Settings::get( 'brand_style', '' ) );
				$prompt = 'Create a photorealistic lifestyle product photograph using this exact product. ' . $about . $keep
					. 'Setting: ' . ( '' !== $scene ? $scene : 'a clean, bright, modern interior' ) . '. '
					. ( '' !== $style ? 'Brand style: ' . $style . '. ' : '' )
					. 'Make the scale, perspective, lighting and shadows physically consistent with the setting. The product is the clear focus and fully visible. No text, captions, watermarks or extra logos. Return only the image.';
				break;
			case 'upscale':
				$prompt = 'Enhance this product photo at the highest resolution you can produce: restore fine detail and texture, remove noise, blur, compression artefacts and banding, and sharpen edges. Do not change the composition, framing, crop, colours, background or any content. ' . $about . 'Return only the enhanced image.';
				break;
			default:
				$prompt = 'Edit this product photo. Instruction: ' . ( '' !== $o['instruction'] ? $o['instruction'] : 'clean up the photo' ) . '. ' . $about
					. 'Change nothing else: keep the product identity, shape, label text and logos, and the framing unless the instruction asks otherwise. Return only the edited image.';
		}
		/**
		 * Prompt sent to the image model for an operation.
		 *
		 * @param string $prompt  Prompt.
		 * @param string $op      Operation.
		 * @param array  $options Options.
		 * @param int    $product Product id.
		 */
		return (string) apply_filters( 'dev3is_operation_prompt', $prompt, $op, $o, (int) $product );
	}

	/**
	 * Base64 input for the model (downscaled when large; the original file is only read).
	 *
	 * @param int $id Attachment id.
	 * @return array|\WP_Error { mime, data, width, height }
	 */
	public static function input( $id ) {
		$img = Image_Tools::from_file( (string) get_attached_file( $id ) );
		if ( is_wp_error( $img ) ) {
			return $img;
		}
		/**
		 * Longest side (px) of the image sent to the model.
		 *
		 * @param int $px Pixels.
		 */
		$max = (int) apply_filters( 'dev3is_max_input_side', 1536 );
		$w   = imagesx( $img );
		$h   = imagesy( $img );
		if ( max( $w, $h ) > $max ) {
			$k   = $max / max( $w, $h );
			$img = Image_Tools::resample( $img, (int) round( $w * $k ), (int) round( $h * $k ) );
		}
		$mime = Image_Tools::has_alpha( $img ) ? 'image/png' : 'image/jpeg';
		return array(
			'mime'   => $mime,
			'data'   => base64_encode( Image_Tools::encode( $img, $mime, 92 ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			'width'  => $w,
			'height' => $h,
		);
	}

	/**
	 * Runs an operation on one product image and stores the outputs as pending results.
	 *
	 * @param int    $attachment Original attachment id.
	 * @param int    $product    Product id (0 = media only).
	 * @param string $slot       featured|gallery|variation:<id>.
	 * @param string $op         Operation.
	 * @param array  $raw        Options.
	 * @param array  $ctx        { job_id, user_id, context }.
	 * @return array|\WP_Error Result rows.
	 */
	public static function run( $attachment, $product, $slot, $op, array $raw = array(), array $ctx = array() ) {
		$attachment = (int) $attachment;
		if ( ! self::exists( $op ) ) {
			return new \WP_Error( 'dev3is_bad_op', __( 'Unknown operation.', 'dev3-ai-product-image-studio-for-woocommerce' ), array( 'status' => 400 ) );
		}
		if ( ! wp_attachment_is_image( $attachment ) ) {
			return new \WP_Error( 'dev3is_not_image', __( 'That attachment is not an image.', 'dev3-ai-product-image-studio-for-woocommerce' ), array( 'status' => 400 ) );
		}
		if ( ! Image_Tools::available() ) {
			return new \WP_Error( 'dev3is_no_gd', __( 'The PHP GD extension is required for image processing.', 'dev3-ai-product-image-studio-for-woocommerce' ), array( 'status' => 500 ) );
		}
		$o      = self::options( $op, $raw );
		$labels = self::labels();
		$label  = $labels[ $op ];
		if ( 'remove_bg' === $op && 'transparent' === $o['background'] ) {
			$label = __( 'Transparent background', 'dev3-ai-product-image-studio-for-woocommerce' );
		}
		$ctx += array(
			'job_id'  => 0,
			'user_id' => get_current_user_id(),
			'context' => 'admin',
		);
		if ( 'edit' === $op && '' === $o['instruction'] ) {
			return new \WP_Error( 'dev3is_empty_instruction', __( 'Describe the edit you want.', 'dev3-ai-product-image-studio-for-woocommerce' ), array( 'status' => 400 ) );
		}
		$outputs = in_array( $op, self::AI, true ) ? self::run_ai( $attachment, (int) $product, $op, $o, $ctx['context'] ) : self::run_local( $attachment, $op, $o );
		if ( is_wp_error( $outputs ) ) {
			return $outputs;
		}
		$rows = array();
		foreach ( $outputs as $i => $out ) {
			$format = 'convert' === $op ? $o['format'] : (string) Settings::get( 'output_format', 'auto' );
			$mime   = 'convert' === $op ? ( 'jpeg' === $format ? 'image/jpeg' : ( 'webp' === $format && Image_Tools::webp_supported() ? 'image/webp' : 'image/png' ) ) : Image_Tools::pick_mime( $format, $out['img'] );
			$bytes  = Image_Tools::encode( $out['img'], $mime, 'convert' === $op ? $o['quality'] : (int) Settings::get( 'jpeg_quality', 90 ) );
			$new    = Results::save_attachment( $bytes, $mime, (int) $product, $attachment, $op, $label );
			if ( is_wp_error( $new ) ) {
				return $new;
			}
			$id = Results::create(
				array(
					'product_id' => (int) $product,
					'source_id'  => $attachment,
					'result_id'  => $new,
					'kind'       => 'image',
					'operation'  => $op,
					'apply_mode' => 'scene' === $op ? 'add' : (string) Settings::get( 'default_mode', 'replace' ),
					'slot'       => (string) $slot,
					'job_id'     => (int) $ctx['job_id'],
					'user_id'    => (int) $ctx['user_id'],
					'payload'    => array_merge(
						array(
							'label'   => $label,
							'options' => $o,
							'variant' => $i + 1,
						),
						isset( $out['meta'] ) ? $out['meta'] : array()
					),
				)
			);
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			$rows[] = Results::get( $id );
		}
		return $rows;
	}

	/**
	 * AI run: one image call per variant, then the local finishing step.
	 *
	 * @param int    $attachment Attachment.
	 * @param int    $product    Product.
	 * @param string $op         Operation.
	 * @param array  $o          Options.
	 * @param string $context    Usage context.
	 * @return array|\WP_Error [ { img, meta } ]
	 */
	private static function run_ai( $attachment, $product, $op, array $o, $context ) {
		$input = self::input( $attachment );
		if ( is_wp_error( $input ) ) {
			return $input;
		}
		$prompt = self::prompt( $op, $o, $product );
		$count  = 'scene' === $op ? $o['variants'] : 1;
		$opts   = array(
			'images'  => array(
				array(
					'mime' => $input['mime'],
					'data' => $input['data'],
				),
			),
			'timeout' => 150,
		);
		if ( 'scene' === $op ) {
			$opts['aspect_ratio'] = $o['aspect'];
		}
		if ( function_exists( 'set_time_limit' ) && ! defined( 'WP_TESTS_DOMAIN' ) ) {
			set_time_limit( 60 + 150 * $count ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- image calls are slow.
		}
		list( $src_w, $src_h ) = Audit::size( $attachment );
		$out                   = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$res = Ai::image( 'image_' . $op, $prompt, $opts, $context );
			if ( is_wp_error( $res ) ) {
				if ( $out ) {
					break; // Keep the variants we already have.
				}
				return $res;
			}
			$bytes = base64_decode( (string) $res['data'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			$img   = Image_Tools::from_string( false === $bytes ? '' : $bytes );
			if ( is_wp_error( $img ) ) {
				return $img;
			}
			$img   = self::finish( $op, $o, $img, $src_w ? $src_w : $input['width'], $src_h ? $src_h : $input['height'] );
			$out[] = array(
				'img'  => $img,
				'meta' => array(
					'model'  => (string) $res['model'],
					'tokens' => isset( $res['usage']['total'] ) ? (int) $res['usage']['total'] : 0,
					'ms'     => (int) $res['duration_ms'],
					'prompt' => mb_substr( $prompt, 0, 600 ),
					'note'   => mb_substr( wp_strip_all_tags( (string) $res['text'] ), 0, 300 ),
				),
			);
		}
		return $out;
	}

	/**
	 * Local finishing after the model: key out, square, resample.
	 *
	 * @param string            $op    Operation.
	 * @param array             $o     Options.
	 * @param \GdImage|resource $img   Model output.
	 * @param int               $src_w Original width.
	 * @param int               $src_h Original height.
	 * @return \GdImage|resource
	 */
	public static function finish( $op, array $o, $img, $src_w, $src_h ) {
		switch ( $op ) {
			case 'remove_bg':
				return 'transparent' === $o['background'] ? Image_Tools::chroma_key( $img ) : $img;
			case 'packshot':
				return Image_Tools::square( $img, $o['size'] ? $o['size'] : max( 1024, min( (int) Settings::get( 'max_side', 2048 ), max( $src_w, $src_h ) ) ), $o['fill'], Image_Tools::rgb( $o['color'] ) );
			case 'upscale':
				list( $w, $h ) = Image_Tools::upscale_size( $src_w, $src_h, $o['factor'], $o['long'], (int) Settings::get( 'max_side', 2048 ) );
				// Keep the model's aspect ratio, sized to the requested long edge.
				$k = max( $w, $h ) / max( imagesx( $img ), imagesy( $img ) );
				return Image_Tools::resample( $img, (int) round( imagesx( $img ) * $k ), (int) round( imagesy( $img ) * $k ) );
		}
		return $img;
	}

	/**
	 * Local GD operation.
	 *
	 * @param int    $attachment Attachment.
	 * @param string $op         Operation.
	 * @param array  $o          Options.
	 * @return array|\WP_Error [ { img } ]
	 */
	private static function run_local( $attachment, $op, array $o ) {
		$img = Image_Tools::from_file( (string) get_attached_file( $attachment ) );
		if ( is_wp_error( $img ) ) {
			return $img;
		}
		switch ( $op ) {
			case 'square':
				$img = Image_Tools::square( $img, $o['size'], $o['fill'], $o['transparent'] ? null : Image_Tools::rgb( $o['color'] ) );
				break;
			case 'local_upscale':
				$img = Image_Tools::upscale( $img, $o['factor'], $o['long'], (int) Settings::get( 'max_side', 2048 ) );
				break;
			case 'flatten':
				$img = Image_Tools::flatten( $img, Image_Tools::rgb( $o['color'] ) );
				break;
		}
		return array( array( 'img' => $img ) );
	}
}
