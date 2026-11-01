<?php
/**
 * Plugin-specific settings (shared AI settings live in Core3\Core\Settings_Store).
 *
 * @package Dev3\AiImageStudio
 */

namespace Dev3\AiImageStudio;

use Dev3\AiImageStudio\Core3\Core\Settings_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Hooked into dev3is_settings_defaults / dev3is_settings_sanitize.
 */
class Settings {

	const FORMATS = array( 'auto', 'png', 'jpeg', 'webp' );
	const SCOPES  = array( 'featured', 'gallery', 'variations', 'all' );

	/**
	 * Adds plugin defaults.
	 *
	 * @param array $defaults Shared defaults.
	 * @return array
	 */
	public static function defaults( $defaults ) {
		return array_merge(
			$defaults,
			array(
				'bg_color'      => '#ffffff',
				'output_format' => 'auto',
				'jpeg_quality'  => 90,
				'fill_ratio'    => 85,
				'shadow'        => true,
				'low_res'       => 800,
				'max_side'      => 2048,
				'brand_style'   => '',
				'alt_template'  => '{product} - {category}',
				'alt_language'  => '',
				'hide_drafts'   => true,
				'keep_rejected' => false,
				'batch_scope'   => 'featured',
				'default_mode'  => 'replace',
			)
		);
	}

	/**
	 * Sanitizes plugin keys.
	 *
	 * @param array $out     Sanitized so far.
	 * @param array $input   Raw input.
	 * @param array $current Current.
	 * @return array
	 */
	public static function sanitize( $out, $input, $current ) {
		unset( $current );
		if ( isset( $input['bg_color'] ) ) {
			$color = sanitize_hex_color( (string) $input['bg_color'] );
			if ( $color ) {
				$out['bg_color'] = strtolower( $color );
			}
		}
		if ( isset( $input['output_format'] ) && in_array( $input['output_format'], self::FORMATS, true ) ) {
			$out['output_format'] = $input['output_format'];
		}
		if ( isset( $input['jpeg_quality'] ) ) {
			$out['jpeg_quality'] = min( 100, max( 40, (int) $input['jpeg_quality'] ) );
		}
		if ( isset( $input['fill_ratio'] ) ) {
			$out['fill_ratio'] = min( 100, max( 40, (int) $input['fill_ratio'] ) );
		}
		if ( isset( $input['low_res'] ) ) {
			$out['low_res'] = min( 4000, max( 100, (int) $input['low_res'] ) );
		}
		if ( isset( $input['max_side'] ) ) {
			$out['max_side'] = min( 4096, max( 512, (int) $input['max_side'] ) );
		}
		foreach ( array( 'shadow', 'hide_drafts', 'keep_rejected' ) as $flag ) {
			if ( isset( $input[ $flag ] ) ) {
				$out[ $flag ] = (bool) $input[ $flag ];
			}
		}
		if ( isset( $input['brand_style'] ) ) {
			$out['brand_style'] = mb_substr( sanitize_textarea_field( wp_unslash( (string) $input['brand_style'] ) ), 0, 400 );
		}
		if ( isset( $input['alt_template'] ) ) {
			$out['alt_template'] = mb_substr( sanitize_text_field( wp_unslash( (string) $input['alt_template'] ) ), 0, 200 );
		}
		if ( isset( $input['alt_language'] ) ) {
			$out['alt_language'] = mb_substr( sanitize_text_field( wp_unslash( (string) $input['alt_language'] ) ), 0, 40 );
		}
		if ( isset( $input['batch_scope'] ) && in_array( $input['batch_scope'], self::SCOPES, true ) ) {
			$out['batch_scope'] = $input['batch_scope'];
		}
		if ( isset( $input['default_mode'] ) && in_array( $input['default_mode'], array( 'replace', 'add', 'library' ), true ) ) {
			$out['default_mode'] = $input['default_mode'];
		}
		return $out;
	}

	/**
	 * Shortcut to one setting.
	 *
	 * @param string $key      Key.
	 * @param mixed  $fallback Fallback.
	 * @return mixed
	 */
	public static function get( $key, $fallback = null ) {
		return Settings_Store::get( $key, $fallback );
	}
}
