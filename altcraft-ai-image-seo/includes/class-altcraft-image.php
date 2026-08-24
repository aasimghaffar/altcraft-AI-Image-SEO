<?php
/**
 * Prepares image data for the vision APIs.
 *
 * Sends a downscaled copy (never the full original) to keep requests fast,
 * cheap and under provider payload limits.
 *
 * @package AltCraft_AI_Image_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Image payload helper.
 */
class AltCraft_Image {

	/**
	 * Verifies that an attachment has a usable image file on the server.
	 *
	 * @param int  $attachment_id    Attachment ID.
	 * @param bool $require_readable Also require that the file is a decodable raster image
	 *                               (false for filename-only mode, where SVGs etc. are fine).
	 * @return true|WP_Error
	 */
	public static function check_file( $attachment_id, $require_readable = true ) {
		$attachment_id = absint( $attachment_id );
		$file          = $attachment_id ? get_attached_file( $attachment_id ) : '';

		if ( ! $file || ! file_exists( $file ) || filesize( $file ) < 1 ) {
			return new WP_Error(
				'no_image_file',
				__( 'No image uploaded – this media item has no image file on the server, so there is nothing to describe. Upload the image again or delete the item.', 'altcraft-ai-image-seo' )
			);
		}

		if ( $require_readable ) {
			$size = wp_getimagesize( $file );
			if ( ! $size || empty( $size[0] ) || empty( $size[1] ) ) {
				return new WP_Error(
					'unreadable_image',
					__( 'This image file cannot be read by the server – it may be damaged or in an unsupported format such as SVG.', 'altcraft-ai-image-seo' )
				);
			}
		}

		return true;
	}

	/**
	 * Whether ALT text can be generated for this attachment with the current settings.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return true|WP_Error
	 */
	public static function can_generate( $attachment_id ) {
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return new WP_Error( 'not_an_image', __( 'This attachment is not an image.', 'altcraft-ai-image-seo' ) );
		}

		$settings = AltCraft_Settings::get();
		$source   = isset( $settings['generation_source'] ) ? $settings['generation_source'] : 'both';

		return self::check_file( $attachment_id, 'filename' !== $source );
	}

	/**
	 * Builds the payload for an attachment.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $allowed_mimes MIME types accepted by the provider.
	 * @return array|WP_Error {
	 *     @type string $data   Base64 encoded image bytes.
	 *     @type string $mime   MIME type of the encoded bytes.
	 *     @type string $source Description of what was used (for debugging).
	 * }
	 */
	public static function get_payload( $attachment_id, $allowed_mimes = array( 'image/jpeg', 'image/png', 'image/webp' ) ) {
		$attachment_id = absint( $attachment_id );

		if ( ! $attachment_id || ! wp_attachment_is_image( $attachment_id ) ) {
			return new WP_Error( 'not_an_image', __( 'This attachment is not an image.', 'altcraft-ai-image-seo' ) );
		}

		$check = self::check_file( $attachment_id, true );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$file = get_attached_file( $attachment_id );

		/**
		 * Filters the maximum dimension (px) of the image copy sent to the AI provider.
		 *
		 * @param int $max_dimension Default 1024.
		 */
		$max_dimension = (int) apply_filters( 'altcraft_ai_max_image_dimension', 1024 );
		$max_dimension = max( 256, $max_dimension );

		/**
		 * Filters the maximum file size (bytes) that may be sent without re-encoding.
		 *
		 * @param int $max_bytes Default 4 MB.
		 */
		$max_bytes = (int) apply_filters( 'altcraft_ai_max_image_bytes', 4 * MB_IN_BYTES );

		$candidate = self::pick_existing_file( $attachment_id, $file, $max_dimension, $max_bytes, $allowed_mimes );

		if ( $candidate ) {
			$payload = self::encode_file( $candidate );
			if ( $payload ) {
				return $payload;
			}
		}

		$encoded = self::encode_with_editor( $file, $max_dimension );
		if ( ! is_wp_error( $encoded ) ) {
			return $encoded;
		}

		// The editor could not handle this file (memory limit, missing Imagick delegate, odd encoding…).
		// Fall back to the original file, then to the best small thumbnail, before giving up.
		$fallback = self::pick_fallback_file( $attachment_id, $file, $allowed_mimes );
		if ( $fallback ) {
			$payload = self::encode_file( $fallback );
			if ( $payload ) {
				return $payload;
			}
		}

		return $encoded;
	}

	/**
	 * Reads and base64-encodes a chosen file.
	 *
	 * @param array $candidate Candidate array with path, mime and source keys.
	 * @return array|null
	 */
	private static function encode_file( $candidate ) {
		$bytes = self::read_file( $candidate['path'] );
		if ( false === $bytes ) {
			return null;
		}

		return array(
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required transport encoding for the vision APIs.
			'data'   => base64_encode( $bytes ),
			'mime'   => $candidate['mime'],
			'source' => $candidate['source'],
		);
	}

	/**
	 * Last-resort candidates when the image editor fails: the original file (if it is within the
	 * provider's inline limit) or the largest thumbnail that exists, regardless of size.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $file          Original file path.
	 * @param array  $allowed_mimes Accepted MIME types.
	 * @return array|null
	 */
	private static function pick_fallback_file( $attachment_id, $file, $allowed_mimes ) {
		/**
		 * Filters the largest original file (bytes) that may be sent unresized when the editor fails.
		 * Gemini limits inline requests to 20 MB after base64 encoding, so 4 MB keeps even slow hosts
		 * comfortably inside the request timeout.
		 *
		 * @param int $max_bytes Default 4 MB.
		 */
		$max_bytes = (int) apply_filters( 'altcraft_ai_max_fallback_bytes', 4 * MB_IN_BYTES );
		$mime      = wp_get_image_mime( $file );
		$size      = filesize( $file );

		if ( $size && $size <= $max_bytes && in_array( $mime, $allowed_mimes, true ) ) {
			return array(
				'path'   => $file,
				'mime'   => $mime,
				'source' => 'original-fallback',
			);
		}

		$meta = wp_get_attachment_metadata( $attachment_id );
		$dir  = dirname( $file );
		$best = null;

		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size_data ) {
				if ( empty( $size_data['file'] ) ) {
					continue;
				}
				$path = path_join( $dir, $size_data['file'] );
				if ( ! file_exists( $path ) ) {
					continue;
				}
				$size_mime = ! empty( $size_data['mime-type'] ) ? $size_data['mime-type'] : wp_get_image_mime( $path );
				if ( ! in_array( $size_mime, $allowed_mimes, true ) ) {
					continue;
				}
				$dim = max( (int) ( isset( $size_data['width'] ) ? $size_data['width'] : 0 ), (int) ( isset( $size_data['height'] ) ? $size_data['height'] : 0 ) );
				if ( null === $best || $dim > $best['dim'] ) {
					$best = array(
						'path'   => $path,
						'mime'   => $size_mime,
						'dim'    => $dim,
						'source' => 'size-fallback:' . $dim,
					);
				}
			}
		}

		return $best;
	}

	/**
	 * Finds an already-generated file (original or intermediate size) that fits the limits.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $file          Original file path.
	 * @param int    $max_dimension Max width/height.
	 * @param int    $max_bytes     Max file size.
	 * @param array  $allowed_mimes Accepted MIME types.
	 * @return array|null
	 */
	private static function pick_existing_file( $attachment_id, $file, $max_dimension, $max_bytes, $allowed_mimes ) {
		$meta = wp_get_attachment_metadata( $attachment_id );
		$dir  = dirname( $file );
		$best = null;

		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size ) {
				if ( empty( $size['file'] ) || empty( $size['width'] ) || empty( $size['height'] ) ) {
					continue;
				}
				$w = (int) $size['width'];
				$h = (int) $size['height'];
				if ( max( $w, $h ) > $max_dimension || max( $w, $h ) < 400 ) {
					continue;
				}
				$path = path_join( $dir, $size['file'] );
				if ( ! file_exists( $path ) ) {
					continue;
				}
				$mime = ! empty( $size['mime-type'] ) ? $size['mime-type'] : wp_get_image_mime( $path );
				if ( ! in_array( $mime, $allowed_mimes, true ) ) {
					continue;
				}
				if ( null === $best || max( $w, $h ) > $best['dim'] ) {
					$best = array(
						'path'   => $path,
						'mime'   => $mime,
						'dim'    => max( $w, $h ),
						'source' => 'size:' . max( $w, $h ),
					);
				}
			}
		}

		if ( $best ) {
			return $best;
		}

		// Fall back to the original when it is already small enough.
		$width  = ! empty( $meta['width'] ) ? (int) $meta['width'] : 0;
		$height = ! empty( $meta['height'] ) ? (int) $meta['height'] : 0;
		$mime   = wp_get_image_mime( $file );
		$size   = filesize( $file );

		if ( $width && $height && max( $width, $height ) <= $max_dimension && $size && $size <= $max_bytes && in_array( $mime, $allowed_mimes, true ) ) {
			return array(
				'path'   => $file,
				'mime'   => $mime,
				'dim'    => max( $width, $height ),
				'source' => 'original',
			);
		}

		return null;
	}

	/**
	 * Uses the WordPress image editor to create a temporary downscaled JPEG and encodes it.
	 *
	 * @param string $file          Original file.
	 * @param int    $max_dimension Max width/height.
	 * @return array|WP_Error
	 */
	private static function encode_with_editor( $file, $max_dimension ) {
		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) {
			return new WP_Error(
				'unsupported_image',
				sprintf(
					/* translators: %s: the reason reported by the WordPress image editor */
					__( 'The server could not process this image, and it is too large to send as-is. Reason: %s. Try regenerating thumbnails for it or uploading a smaller copy.', 'altcraft-ai-image-seo' ),
					$editor->get_error_message()
				)
			);
		}

		$resized = $editor->resize( $max_dimension, $max_dimension, false );
		if ( is_wp_error( $resized ) ) {
			return $resized;
		}

		$editor->set_quality( 85 );

		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$temp = wp_tempnam( 'altcraft-ai-' );
		if ( ! $temp ) {
			return new WP_Error( 'temp_file', __( 'Could not create a temporary file.', 'altcraft-ai-image-seo' ) );
		}

		$saved = $editor->save( $temp, 'image/jpeg' );
		if ( is_wp_error( $saved ) ) {
			wp_delete_file( $temp );
			return $saved;
		}

		$path  = ! empty( $saved['path'] ) ? $saved['path'] : $temp;
		$bytes = self::read_file( $path );

		wp_delete_file( $path );
		if ( $path !== $temp && file_exists( $temp ) ) {
			wp_delete_file( $temp );
		}

		if ( false === $bytes ) {
			return new WP_Error( 'file_read_error', __( 'Could not read the resized image.', 'altcraft-ai-image-seo' ) );
		}

		return array(
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required transport encoding for the vision APIs.
			'data'   => base64_encode( $bytes ),
			'mime'   => 'image/jpeg',
			'source' => 'editor',
		);
	}

	/**
	 * Summarises what this server can do with images (shown in the settings screen).
	 *
	 * @return array {
	 *     @type string $editor       "GD", "Imagick", both, or an empty string when none is available.
	 *     @type bool   $can_resize   Whether JPEG/PNG images can be resized at all.
	 *     @type bool   $webp         Whether WebP files can be written.
	 *     @type string $memory_limit PHP memory limit as configured.
	 * }
	 */
	public static function server_capabilities() {
		$editors = array();
		if ( class_exists( 'Imagick' ) && extension_loaded( 'imagick' ) ) {
			$editors[] = 'Imagick';
		}
		if ( function_exists( 'gd_info' ) ) {
			$editors[] = 'GD';
		}

		return array(
			'editor'       => implode( ' + ', $editors ),
			'can_resize'   => wp_image_editor_supports( array( 'mime_type' => 'image/png' ) ) && wp_image_editor_supports( array( 'mime_type' => 'image/jpeg' ) ),
			'webp'         => wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ),
			'memory_limit' => (string) ini_get( 'memory_limit' ),
		);
	}

	/**
	 * Reads a local file through the WordPress filesystem API.
	 *
	 * @param string $path Absolute path.
	 * @return string|false
	 */
	public static function read_file( $path ) {
		$filesystem = self::filesystem();
		if ( ! $filesystem ) {
			return false;
		}
		$contents = $filesystem->get_contents( $path );
		return ( false === $contents || '' === $contents ) ? false : $contents;
	}

	/**
	 * Returns a direct filesystem instance for reading local files.
	 *
	 * @return WP_Filesystem_Direct|null
	 */
	public static function filesystem() {
		static $instance = null;

		if ( null !== $instance ) {
			return $instance;
		}

		if ( ! class_exists( 'WP_Filesystem_Direct' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
		}

		$instance = new WP_Filesystem_Direct( false );

		return $instance;
	}
}
