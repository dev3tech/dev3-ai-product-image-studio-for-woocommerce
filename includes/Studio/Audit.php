<?php
/**
 * Image audit: resolution, square, white background, alt text. Cached per file version.
 *
 * @package Dev3\AiImageStudio
 */

namespace Dev3\AiImageStudio\Studio;

use Dev3\AiImageStudio\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Cheap checks, the GD one cached in attachment meta.
 */
class Audit {

	const META = '_dev3is_audit';

	/**
	 * Low-resolution threshold (shortest side, px).
	 *
	 * @return int
	 */
	public static function low_res() {
		/**
		 * Shortest side (px) under which a product image counts as low resolution.
		 *
		 * @param int $px Pixels.
		 */
		return (int) apply_filters( 'dev3is_audit_low_res', (int) Settings::get( 'low_res', 800 ) );
	}

	/**
	 * Width/height from metadata.
	 *
	 * @param int $id Attachment id.
	 * @return array [ w, h ]
	 */
	public static function size( $id ) {
		$meta = wp_get_attachment_metadata( $id );
		return array( isset( $meta['width'] ) ? (int) $meta['width'] : 0, isset( $meta['height'] ) ? (int) $meta['height'] : 0 );
	}

	/**
	 * Smallest local file good enough for the whiteness check (medium, else the original).
	 *
	 * @param int $id Attachment id.
	 * @return string
	 */
	public static function sample_file( $id ) {
		$file = (string) get_attached_file( $id );
		$mid  = image_get_intermediate_size( $id, 'medium' );
		if ( $mid && ! empty( $mid['path'] ) ) {
			$uploads = wp_get_upload_dir();
			$path    = trailingslashit( $uploads['basedir'] ) . $mid['path'];
			if ( is_readable( $path ) ) {
				return $path;
			}
		}
		return $file;
	}

	/**
	 * Border whiteness 0-1 (cached), or null when it cannot be measured.
	 *
	 * @param int  $id      Attachment id.
	 * @param bool $compute Compute when not cached.
	 * @return float|null
	 */
	public static function whiteness( $id, $compute = true ) {
		$file   = (string) get_attached_file( $id );
		$stamp  = is_readable( $file ) ? (string) filemtime( $file ) . ':' . (string) filesize( $file ) : '';
		$cached = get_post_meta( $id, self::META, true );
		if ( is_array( $cached ) && isset( $cached['stamp'], $cached['white'] ) && $cached['stamp'] === $stamp ) {
			return (float) $cached['white'];
		}
		if ( ! $compute || '' === $stamp || ! Image_Tools::available() ) {
			return null;
		}
		$img = Image_Tools::from_file( self::sample_file( $id ) );
		if ( is_wp_error( $img ) ) {
			return null;
		}
		$white = Image_Tools::border_whiteness( $img );
		update_post_meta(
			$id,
			self::META,
			array(
				'stamp' => $stamp,
				'white' => $white,
			)
		);
		return $white;
	}

	/**
	 * Flags for one attachment.
	 *
	 * @param int  $id      Attachment id.
	 * @param bool $compute Run the GD check when not cached.
	 * @return array { width, height, missing_alt, low_res, not_square, not_white (bool|null), whiteness, score }
	 */
	public static function flags( $id, $compute = true ) {
		list( $w, $h )  = self::size( $id );
		$alt            = trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) );
		$white          = self::whiteness( $id, $compute );
		$flags          = array(
			'width'       => $w,
			'height'      => $h,
			'missing_alt' => '' === $alt,
			'low_res'     => $w > 0 && min( $w, $h ) < self::low_res(),
			'not_square'  => $w > 0 && abs( $w - $h ) / max( $w, $h ) > 0.02,
			'not_white'   => null === $white ? null : $white < 0.9,
			'whiteness'   => $white,
		);
		$issues         = (int) $flags['missing_alt'] + (int) $flags['low_res'] + (int) $flags['not_square'] + (int) ( true === $flags['not_white'] );
		$flags['score'] = max( 0, 100 - 25 * $issues );
		return $flags;
	}
}
