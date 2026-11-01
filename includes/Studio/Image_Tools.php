<?php
/**
 * Local image processing with GD: chroma key, trim, square canvas, resampling, flatten, encode.
 *
 * Every method works on in-memory GD images and never touches files on disk.
 *
 * @package Dev3\AiImageStudio
 */

namespace Dev3\AiImageStudio\Studio;

defined( 'ABSPATH' ) || exit;

/**
 * Stateless GD helpers.
 */
class Image_Tools {

	/**
	 * The key colour the AI paints behind products for transparent output.
	 */
	const KEY_COLOR = array( 0, 255, 0 );

	/**
	 * Upper bound for any side we create.
	 */
	const HARD_MAX = 4096;

	/**
	 * Whether GD is available.
	 *
	 * @return bool
	 */
	public static function available() {
		return function_exists( 'imagecreatefromstring' ) && function_exists( 'imagecreatetruecolor' );
	}

	/**
	 * Whether WebP can be written.
	 *
	 * @return bool
	 */
	public static function webp_supported() {
		return function_exists( 'imagewebp' );
	}

	/**
	 * Image from bytes.
	 *
	 * @param string $bytes Binary data.
	 * @return \GdImage|resource|\WP_Error
	 */
	public static function from_string( $bytes ) {
		if ( ! self::available() ) {
			return new \WP_Error( 'dev3is_no_gd', __( 'The PHP GD extension is required for image processing.', 'dev3-ai-product-image-studio-for-woocommerce' ), array( 'status' => 500 ) );
		}
		$img = '' === (string) $bytes ? false : @imagecreatefromstring( (string) $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid data returns false.
		if ( ! $img ) {
			return new \WP_Error( 'dev3is_bad_image', __( 'The image data could not be read.', 'dev3-ai-product-image-studio-for-woocommerce' ), array( 'status' => 422 ) );
		}
		return self::truecolor( $img );
	}

	/**
	 * Image from a local file.
	 *
	 * @param string $path File path.
	 * @return \GdImage|resource|\WP_Error
	 */
	public static function from_file( $path ) {
		if ( ! is_readable( $path ) ) {
			return new \WP_Error( 'dev3is_missing_file', __( 'The image file is missing.', 'dev3-ai-product-image-studio-for-woocommerce' ), array( 'status' => 404 ) );
		}
		return self::from_string( (string) file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
	}

	/**
	 * Ensures a true-colour image with alpha support.
	 *
	 * @param \GdImage|resource $img Image.
	 * @return \GdImage|resource
	 */
	public static function truecolor( $img ) {
		if ( ! imageistruecolor( $img ) ) {
			imagepalettetotruecolor( $img );
		}
		imagealphablending( $img, true );
		imagesavealpha( $img, true );
		return $img;
	}

	/**
	 * Blank canvas, filled with a colour or transparent.
	 *
	 * @param int        $w   Width.
	 * @param int        $h   Height.
	 * @param array|null $rgb Fill colour, null for transparent.
	 * @return \GdImage|resource
	 */
	public static function canvas( $w, $h, $rgb = null ) {
		$img = imagecreatetruecolor( max( 1, (int) $w ), max( 1, (int) $h ) );
		imagealphablending( $img, false );
		imagesavealpha( $img, true );
		$fill = null === $rgb ? imagecolorallocatealpha( $img, 0, 0, 0, 127 ) : imagecolorallocatealpha( $img, (int) $rgb[0], (int) $rgb[1], (int) $rgb[2], 0 );
		imagefilledrectangle( $img, 0, 0, (int) $w - 1, (int) $h - 1, $fill );
		imagealphablending( $img, true );
		return $img;
	}

	/**
	 * Hex colour to [r,g,b].
	 *
	 * @param string $hex Hex colour.
	 * @return array
	 */
	public static function rgb( $hex ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( ! preg_match( '/^[0-9a-f]{6}$/i', $hex ) ) {
			return array( 255, 255, 255 );
		}
		return array( hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ) );
	}

	/**
	 * Pixel at x,y as [r,g,b,a] (a: 0 opaque … 127 transparent).
	 *
	 * @param \GdImage|resource $img Image.
	 * @param int               $x   X.
	 * @param int               $y   Y.
	 * @return array
	 */
	public static function pixel( $img, $x, $y ) {
		$c = imagecolorat( $img, (int) $x, (int) $y );
		return array( ( $c >> 16 ) & 0xFF, ( $c >> 8 ) & 0xFF, $c & 0xFF, ( $c >> 24 ) & 0x7F );
	}

	/**
	 * Keys out a solid background colour into alpha (soft edge + colour despill).
	 *
	 * @param \GdImage|resource $img       Image.
	 * @param array             $key       Key colour [r,g,b].
	 * @param int               $tolerance Distance below which a pixel is fully transparent.
	 * @return \GdImage|resource New image with alpha.
	 */
	public static function chroma_key( $img, array $key = self::KEY_COLOR, $tolerance = 0 ) {
		/**
		 * Colour distance (0-441) treated as fully background when keying out the AI's backdrop.
		 *
		 * @param int $tolerance Tolerance.
		 */
		$inner = (int) apply_filters( 'dev3is_chroma_tolerance', $tolerance > 0 ? (int) $tolerance : 110 );
		$outer = $inner + 70;
		$w     = imagesx( $img );
		$h     = imagesy( $img );
		$out   = self::canvas( $w, $h, null );
		imagealphablending( $out, false );
		$green = $key[1] > $key[0] && $key[1] > $key[2];
		for ( $y = 0; $y < $h; $y++ ) {
			for ( $x = 0; $x < $w; $x++ ) {
				list( $r, $g, $b, $a ) = self::pixel( $img, $x, $y );
				$d                     = sqrt( ( $r - $key[0] ) ** 2 + ( $g - $key[1] ) ** 2 + ( $b - $key[2] ) ** 2 );
				if ( $d <= $inner ) {
					$alpha = 127;
				} elseif ( $d >= $outer ) {
					$alpha = $a;
				} else {
					$alpha = max( $a, (int) round( 127 * ( 1 - ( $d - $inner ) / ( $outer - $inner ) ) ) );
				}
				// Despill: edge pixels tinted by the green backdrop lose the excess green.
				if ( $green && $alpha < 127 && $g > max( $r, $b ) && $d < $outer + 60 ) {
					$g = max( $r, $b );
				}
				imagesetpixel( $out, $x, $y, imagecolorallocatealpha( $out, $r, $g, $b, $alpha ) );
			}
		}
		imagealphablending( $out, true );
		return $out;
	}

	/**
	 * Bounding box of the content (pixels that are not background and not transparent).
	 *
	 * @param \GdImage|resource $img       Image.
	 * @param array|null        $bg        Background colour, null = detect from the corners.
	 * @param int               $tolerance Colour distance.
	 * @return array { x, y, w, h } (whole image when nothing is found).
	 */
	public static function content_box( $img, $bg = null, $tolerance = 40 ) {
		$w     = imagesx( $img );
		$h     = imagesy( $img );
		$scale = min( 1, 256 / max( $w, $h ) );
		$sw    = max( 1, (int) round( $w * $scale ) );
		$sh    = max( 1, (int) round( $h * $scale ) );
		$small = self::resample( $img, $sw, $sh );
		if ( null === $bg ) {
			$corner = self::pixel( $small, 0, 0 );
			// Transparent corners: only alpha decides what is content.
			$bg = $corner[3] > 100 ? array( -1000, -1000, -1000 ) : self::corner_color( $small );
		}
		$min_x = $sw;
		$min_y = $sh;
		$max_x = -1;
		$max_y = -1;
		for ( $y = 0; $y < $sh; $y++ ) {
			for ( $x = 0; $x < $sw; $x++ ) {
				list( $r, $g, $b, $a ) = self::pixel( $small, $x, $y );
				if ( $a > 100 ) {
					continue;
				}
				if ( sqrt( ( $r - $bg[0] ) ** 2 + ( $g - $bg[1] ) ** 2 + ( $b - $bg[2] ) ** 2 ) <= $tolerance ) {
					continue;
				}
				$min_x = min( $min_x, $x );
				$min_y = min( $min_y, $y );
				$max_x = max( $max_x, $x );
				$max_y = max( $max_y, $y );
			}
		}
		if ( $max_x < 0 ) {
			return array(
				'x' => 0,
				'y' => 0,
				'w' => $w,
				'h' => $h,
			);
		}
		$x0 = max( 0, (int) floor( $min_x / $scale ) - 1 );
		$y0 = max( 0, (int) floor( $min_y / $scale ) - 1 );
		$x1 = min( $w, (int) ceil( ( $max_x + 1 ) / $scale ) + 1 );
		$y1 = min( $h, (int) ceil( ( $max_y + 1 ) / $scale ) + 1 );
		return array(
			'x' => $x0,
			'y' => $y0,
			'w' => max( 1, $x1 - $x0 ),
			'h' => max( 1, $y1 - $y0 ),
		);
	}

	/**
	 * Average colour of the four corners.
	 *
	 * @param \GdImage|resource $img Image.
	 * @return array [r,g,b]
	 */
	public static function corner_color( $img ) {
		$w   = imagesx( $img ) - 1;
		$h   = imagesy( $img ) - 1;
		$sum = array( 0, 0, 0 );
		foreach ( array( array( 0, 0 ), array( $w, 0 ), array( 0, $h ), array( $w, $h ) ) as $p ) {
			$px = self::pixel( $img, $p[0], $p[1] );
			for ( $i = 0; $i < 3; $i++ ) {
				$sum[ $i ] += $px[ $i ];
			}
		}
		return array( (int) round( $sum[0] / 4 ), (int) round( $sum[1] / 4 ), (int) round( $sum[2] / 4 ) );
	}

	/**
	 * Crops to the content and centres it on a square canvas, scaled so its longest side fills `fill` %.
	 *
	 * @param \GdImage|resource $img  Image.
	 * @param int               $size Canvas side (0 = keep the longest content side / fill).
	 * @param int               $fill Fill percent (40-100).
	 * @param array|null        $bg   Canvas colour, null = transparent.
	 * @return \GdImage|resource
	 */
	public static function square( $img, $size = 0, $fill = 85, $bg = array( 255, 255, 255 ) ) {
		$fill = min( 100, max( 40, (int) $fill ) ) / 100;
		$box  = self::content_box( $img, null );
		$long = max( $box['w'], $box['h'] );
		$size = (int) $size > 0 ? (int) $size : (int) round( $long / $fill );
		$size = min( self::HARD_MAX, max( 64, $size ) );
		$out  = self::canvas( $size, $size, $bg );
		$k    = ( $size * $fill ) / $long;
		$dw   = max( 1, (int) round( $box['w'] * $k ) );
		$dh   = max( 1, (int) round( $box['h'] * $k ) );
		imagealphablending( $out, null !== $bg );
		imagecopyresampled( $out, $img, (int) floor( ( $size - $dw ) / 2 ), (int) floor( ( $size - $dh ) / 2 ), $box['x'], $box['y'], $dw, $dh, $box['w'], $box['h'] );
		imagealphablending( $out, true );
		return $out;
	}

	/**
	 * Resamples to an exact size, keeping alpha.
	 *
	 * @param \GdImage|resource $img Image.
	 * @param int               $w   Width.
	 * @param int               $h   Height.
	 * @return \GdImage|resource
	 */
	public static function resample( $img, $w, $h ) {
		$w   = min( self::HARD_MAX, max( 1, (int) $w ) );
		$h   = min( self::HARD_MAX, max( 1, (int) $h ) );
		$out = self::canvas( $w, $h, null );
		imagealphablending( $out, false );
		imagecopyresampled( $out, $img, 0, 0, 0, 0, $w, $h, imagesx( $img ), imagesy( $img ) );
		imagealphablending( $out, true );
		return $out;
	}

	/**
	 * Upscale target size for a factor or a long edge, capped.
	 *
	 * @param int $w      Current width.
	 * @param int $h      Current height.
	 * @param int $factor 2|4 (0 when long_edge is used).
	 * @param int $long   Target long edge (0 when factor is used).
	 * @param int $max    Cap for the long edge.
	 * @return array [ w, h ]
	 */
	public static function upscale_size( $w, $h, $factor = 2, $long = 0, $max = self::HARD_MAX ) {
		$w     = max( 1, (int) $w );
		$h     = max( 1, (int) $h );
		$max   = min( self::HARD_MAX, max( 64, (int) $max ) );
		$ratio = $long > 0 ? $long / max( $w, $h ) : max( 1, (int) $factor );
		$ratio = min( $ratio, $max / max( $w, $h ) );
		$ratio = max( $ratio, 1 );
		return array( (int) round( $w * $ratio ), (int) round( $h * $ratio ) );
	}

	/**
	 * Upscales with a light sharpen pass.
	 *
	 * @param \GdImage|resource $img    Image.
	 * @param int               $factor Factor.
	 * @param int               $long   Long edge.
	 * @param int               $max    Max long edge.
	 * @return \GdImage|resource
	 */
	public static function upscale( $img, $factor = 2, $long = 0, $max = self::HARD_MAX ) {
		list( $w, $h ) = self::upscale_size( imagesx( $img ), imagesy( $img ), $factor, $long, $max );
		$out           = self::resample( $img, $w, $h );
		if ( function_exists( 'imageconvolution' ) && ! self::has_alpha( $out ) ) {
			imageconvolution(
				$out,
				array(
					array( 0, -1, 0 ),
					array( -1, 12, -1 ),
					array( 0, -1, 0 ),
				),
				8,
				0
			);
		}
		return $out;
	}

	/**
	 * Puts a (possibly transparent) image on a solid colour.
	 *
	 * @param \GdImage|resource $img Image.
	 * @param array             $rgb Colour.
	 * @return \GdImage|resource
	 */
	public static function flatten( $img, array $rgb ) {
		$out = self::canvas( imagesx( $img ), imagesy( $img ), $rgb );
		imagealphablending( $out, true );
		imagecopy( $out, $img, 0, 0, 0, 0, imagesx( $img ), imagesy( $img ) );
		return $out;
	}

	/**
	 * Whether any sampled pixel is (partly) transparent.
	 *
	 * @param \GdImage|resource $img Image.
	 * @return bool
	 */
	public static function has_alpha( $img ) {
		$w    = imagesx( $img );
		$h    = imagesy( $img );
		$step = max( 1, (int) floor( max( $w, $h ) / 64 ) );
		foreach ( range( 0, $h - 1, $step ) as $y ) {
			foreach ( range( 0, $w - 1, $step ) as $x ) {
				if ( ( ( imagecolorat( $img, $x, $y ) >> 24 ) & 0x7F ) > 0 ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Share (0-1) of border pixels that are near white: a cheap "is it on a white background" signal.
	 *
	 * @param \GdImage|resource $img Image.
	 * @return float
	 */
	public static function border_whiteness( $img ) {
		$small = self::resample( $img, 64, 64 );
		$white = 0;
		$total = 0;
		for ( $i = 0; $i < 64; $i++ ) {
			foreach ( array( array( $i, 0 ), array( $i, 63 ), array( 0, $i ), array( 63, $i ) ) as $p ) {
				list( $r, $g, $b, $a ) = self::pixel( $small, $p[0], $p[1] );
				++$total;
				if ( $a > 100 || ( $r >= 235 && $g >= 235 && $b >= 235 ) ) {
					++$white;
				}
			}
		}
		return $total ? round( $white / $total, 3 ) : 0.0;
	}

	/**
	 * Output format for a result.
	 *
	 * @param string            $format auto|png|jpeg|webp.
	 * @param \GdImage|resource $img    Image.
	 * @return string mime
	 */
	public static function pick_mime( $format, $img ) {
		$alpha = self::has_alpha( $img );
		if ( 'webp' === $format && self::webp_supported() ) {
			return 'image/webp';
		}
		if ( 'jpeg' === $format && ! $alpha ) {
			return 'image/jpeg';
		}
		if ( 'auto' === $format ) {
			return $alpha ? 'image/png' : 'image/jpeg';
		}
		return 'image/png';
	}

	/**
	 * Encodes to bytes.
	 *
	 * @param \GdImage|resource $img     Image.
	 * @param string            $mime    Mime.
	 * @param int               $quality JPEG/WebP quality.
	 * @return string
	 */
	public static function encode( $img, $mime, $quality = 90 ) {
		ob_start();
		if ( 'image/jpeg' === $mime ) {
			$flat = self::has_alpha( $img ) ? self::flatten( $img, array( 255, 255, 255 ) ) : $img;
			imagejpeg( $flat, null, (int) $quality );
		} elseif ( 'image/webp' === $mime && self::webp_supported() ) {
			imagesavealpha( $img, true );
			imagewebp( $img, null, (int) $quality );
		} else {
			imagesavealpha( $img, true );
			imagepng( $img, null, 6 );
		}
		return (string) ob_get_clean();
	}
}
