<?php
/**
 * "Before & After Image" block + [dev3is_compare] shortcode (no AI on the front end).
 *
 * @package Dev3\AiImageStudio
 */

namespace Dev3\AiImageStudio\Frontend;

use Dev3\AiImageStudio\Core3\Core\Assets;

defined( 'ABSPATH' ) || exit;

/**
 * Compare slider markup + assets (loaded only when used).
 */
class Compare {

	const BLOCK = 'dev3is/compare';

	/**
	 * Registers hooks.
	 */
	public function register() {
		add_shortcode( 'dev3is_compare', array( $this, 'shortcode' ) );
		add_action( 'init', array( $this, 'block' ), 20 );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	/**
	 * Front-end assets (registered, enqueued on render).
	 */
	public function register_assets() {
		if ( ! wp_script_is( 'dev3is-compare', 'registered' ) && is_readable( DEV3IS_PATH . 'build/compare.asset.php' ) ) {
			$asset = include DEV3IS_PATH . 'build/compare.asset.php';
			wp_register_script( 'dev3is-compare', DEV3IS_URL . 'build/compare.js', array(), $asset['version'], true );
			wp_register_style( 'dev3is-compare', DEV3IS_URL . 'build/compare.css', array(), $asset['version'] );
		}
	}

	/**
	 * Block registration (editor script built from src/entries/blocks.js).
	 */
	public function block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		$this->register_assets();
		$args = array(
			'api_version'     => 2,
			'title'           => __( 'Before & After Image', 'dev3-ai-product-image-studio-for-woocommerce' ),
			'category'        => 'media',
			'icon'            => 'image-flip-horizontal',
			'render_callback' => array( $this, 'render_block' ),
			'attributes'      => array(
				'before'      => array(
					'type'    => 'number',
					'default' => 0,
				),
				'after'       => array(
					'type'    => 'number',
					'default' => 0,
				),
				'position'    => array(
					'type'    => 'number',
					'default' => 50,
				),
				'beforeLabel' => array(
					'type'    => 'string',
					'default' => '',
				),
				'afterLabel'  => array(
					'type'    => 'string',
					'default' => '',
				),
				'caption'     => array(
					'type'    => 'string',
					'default' => '',
				),
			),
			'supports'        => array(
				'align' => array( 'wide', 'full', 'center' ),
			),
		);
		if ( is_readable( DEV3IS_PATH . 'build/blocks.asset.php' ) ) {
			Assets::register( array( 'blocks' ) );
			$args['editor_script'] = Assets::handle( 'blocks' );
		}
		register_block_type( self::BLOCK, $args );
	}

	/**
	 * Block render.
	 *
	 * @param array $attrs Attributes.
	 * @return string
	 */
	public function render_block( $attrs ) {
		return self::render(
			array(
				'before'       => isset( $attrs['before'] ) ? (int) $attrs['before'] : 0,
				'after'        => isset( $attrs['after'] ) ? (int) $attrs['after'] : 0,
				'position'     => isset( $attrs['position'] ) ? (int) $attrs['position'] : 50,
				'before_label' => isset( $attrs['beforeLabel'] ) ? (string) $attrs['beforeLabel'] : '',
				'after_label'  => isset( $attrs['afterLabel'] ) ? (string) $attrs['afterLabel'] : '',
				'caption'      => isset( $attrs['caption'] ) ? (string) $attrs['caption'] : '',
				'align'        => isset( $attrs['align'] ) ? (string) $attrs['align'] : '',
			)
		);
	}

	/**
	 * Shortcode: [dev3is_compare before="12" after="34" position="50" before_label="" after_label="" caption=""].
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'before'       => 0,
				'after'        => 0,
				'position'     => 50,
				'before_label' => '',
				'after_label'  => '',
				'caption'      => '',
			),
			$atts,
			'dev3is_compare'
		);
		return self::render( $atts );
	}

	/**
	 * Markup. Works without JS at the initial position; the range input gives drag, touch and keyboard.
	 *
	 * @param array $a Args.
	 * @return string
	 */
	public static function render( array $a ) {
		$before = (int) $a['before'];
		$after  = (int) $a['after'];
		if ( ! wp_attachment_is_image( $before ) || ! wp_attachment_is_image( $after ) ) {
			return current_user_can( 'edit_posts' ) ? '<p class="dev3is-compare-missing">' . esc_html__( 'Pick a "before" and an "after" image for the comparison.', 'dev3-ai-product-image-studio-for-woocommerce' ) . '</p>' : '';
		}
		wp_enqueue_script( 'dev3is-compare' );
		wp_enqueue_style( 'dev3is-compare' );
		$pos       = min( 100, max( 0, (int) $a['position'] ) );
		$b_label   = '' !== trim( (string) $a['before_label'] ) ? (string) $a['before_label'] : __( 'Before', 'dev3-ai-product-image-studio-for-woocommerce' );
		$a_label   = '' !== trim( (string) $a['after_label'] ) ? (string) $a['after_label'] : __( 'After', 'dev3-ai-product-image-studio-for-woocommerce' );
		$size      = wp_get_attachment_image_src( $after, 'large' );
		$ratio     = $size && $size[2] ? round( $size[1] / $size[2], 4 ) : 1;
		$align     = isset( $a['align'] ) && in_array( $a['align'], array( 'wide', 'full', 'center' ), true ) ? ' align' . $a['align'] : '';
		$img_attrs = array(
			'loading'  => 'lazy',
			'decoding' => 'async',
			'sizes'    => '(max-width: 800px) 100vw, 800px',
		);
		$html      = '<figure class="dev3is-compare' . esc_attr( $align ) . '" style="--dev3is-pos:' . esc_attr( (string) $pos ) . '%;--dev3is-ratio:' . esc_attr( (string) $ratio ) . '">';
		$html     .= '<div class="dev3is-compare__frame">';
		$html     .= wp_get_attachment_image( $after, 'large', false, $img_attrs + array( 'class' => 'dev3is-compare__img dev3is-compare__img--after' ) );
		$html     .= '<div class="dev3is-compare__before">' . wp_get_attachment_image( $before, 'large', false, $img_attrs + array( 'class' => 'dev3is-compare__img dev3is-compare__img--before' ) ) . '</div>';
		$html     .= '<span class="dev3is-compare__tag dev3is-compare__tag--before" aria-hidden="true">' . esc_html( $b_label ) . '</span>';
		$html     .= '<span class="dev3is-compare__tag dev3is-compare__tag--after" aria-hidden="true">' . esc_html( $a_label ) . '</span>';
		$html     .= '<span class="dev3is-compare__handle" aria-hidden="true"><span class="dev3is-compare__knob"></span></span>';
		/* translators: 1: before label, 2: after label */
		$html .= '<input class="dev3is-compare__range" type="range" min="0" max="100" step="1" value="' . esc_attr( (string) $pos ) . '" aria-label="' . esc_attr( sprintf( __( 'Drag to compare %1$s and %2$s', 'dev3-ai-product-image-studio-for-woocommerce' ), $b_label, $a_label ) ) . '">';
		$html .= '</div>';
		if ( '' !== trim( (string) $a['caption'] ) ) {
			$html .= '<figcaption class="dev3is-compare__caption">' . esc_html( (string) $a['caption'] ) . '</figcaption>';
		}
		return $html . '</figure>';
	}
}
