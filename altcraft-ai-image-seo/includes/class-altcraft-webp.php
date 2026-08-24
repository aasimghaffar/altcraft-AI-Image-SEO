<?php
/**
 * Next-gen WebP copies: generation, optional front-end delivery and cleanup.
 *
 * @package AltCraft_AI_Image_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WebP handler.
 */
class AltCraft_WebP {

	/**
	 * Meta key storing the generated WebP files (paths relative to the uploads directory).
	 */
	const META_KEY = '_altcraft_webp';

	/**
	 * Per-request cache of URL → WebP URL lookups.
	 *
	 * @var array
	 */
	private static $url_cache = array();

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'on_generate_metadata' ), 100, 2 );
		add_action( 'delete_attachment', array( __CLASS__, 'delete_files' ) );

		if ( AltCraft_Settings::is_on( 'enable_webp_delivery' ) && ! is_admin() ) {
			add_action( 'send_headers', array( $this, 'vary_header' ) );
			add_filter( 'wp_get_attachment_image_src', array( $this, 'filter_image_src' ), 20, 2 );
			add_filter( 'wp_calculate_image_srcset', array( $this, 'filter_srcset' ), 20 );
			add_filter( 'wp_content_img_tag', array( $this, 'filter_content_img' ), 20 );
		}
	}

	/**
	 * Whether the server can write WebP files.
	 *
	 * @return bool
	 */
	public static function is_supported() {
		return wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) );
	}

	/**
	 * Creates WebP copies after WordPress has generated the attachment sizes.
	 *
	 * @param array $metadata      Attachment metadata.
	 * @param int   $attachment_id Attachment ID.
	 * @return array Unchanged metadata.
	 */
	public function on_generate_metadata( $metadata, $attachment_id ) {
		if ( AltCraft_Settings::is_on( 'enable_webp_convert' ) && wp_attachment_is_image( $attachment_id ) ) {
			self::convert( $attachment_id, $metadata );
		}
		return $metadata;
	}

	/**
	 * Creates a .webp copy of the original file and each registered size.
	 *
	 * @param int        $attachment_id Attachment ID.
	 * @param array|null $metadata      Attachment metadata (fetched when null).
	 * @return int|WP_Error Number of WebP files that now exist for this attachment.
	 */
	public static function convert( $attachment_id, $metadata = null ) {
		$attachment_id = absint( $attachment_id );

		if ( ! self::is_supported() ) {
			return new WP_Error( 'webp_unsupported', __( 'This server cannot create WebP images (GD/Imagick WebP support is missing).', 'altcraft-ai-image-seo' ) );
		}

		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return new WP_Error( 'file_not_found', __( 'The image file could not be found on the server.', 'altcraft-ai-image-seo' ) );
		}

		$mime = get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) ) {
			return 0;
		}

		if ( null === $metadata ) {
			$metadata = wp_get_attachment_metadata( $attachment_id );
		}

		$settings = AltCraft_Settings::get();
		/**
		 * Filters the WebP quality (40–100).
		 *
		 * @param int $quality Quality.
		 */
		$quality = (int) apply_filters( 'altcraft_ai_webp_quality', (int) $settings['webp_quality'] );
		$quality = max( 40, min( 100, $quality ) );

		$dir       = dirname( $file );
		$sources   = array( $file );
		$uploads   = wp_get_upload_dir();
		$basedir   = wp_normalize_path( trailingslashit( $uploads['basedir'] ) );
		$generated = array();

		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$sources[] = path_join( $dir, $size['file'] );
				}
			}
		}

		foreach ( array_unique( $sources ) as $source ) {
			if ( ! file_exists( $source ) ) {
				continue;
			}

			$target = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $source );
			if ( $target === $source ) {
				continue;
			}

			if ( ! file_exists( $target ) ) {
				$editor = wp_get_image_editor( $source );
				if ( is_wp_error( $editor ) ) {
					continue;
				}
				$editor->set_quality( $quality );
				$saved = $editor->save( $target, 'image/webp' );
				if ( is_wp_error( $saved ) || ! file_exists( $target ) ) {
					continue;
				}
			}

			$relative = str_replace( $basedir, '', wp_normalize_path( $target ) );
			if ( wp_normalize_path( $target ) !== $relative ) {
				$generated[] = $relative;
			}
		}

		if ( $generated ) {
			update_post_meta( $attachment_id, self::META_KEY, array_values( array_unique( $generated ) ) );
		}

		/**
		 * Fires after WebP copies were created.
		 *
		 * @param int   $attachment_id Attachment ID.
		 * @param array $generated     Relative paths of WebP files.
		 */
		do_action( 'altcraft_ai_webp_converted', $attachment_id, $generated );

		return count( $generated );
	}

	/**
	 * Removes WebP copies when the attachment is deleted.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public static function delete_files( $attachment_id ) {
		$files = get_post_meta( $attachment_id, self::META_KEY, true );
		if ( empty( $files ) || ! is_array( $files ) ) {
			return;
		}

		$uploads = wp_get_upload_dir();
		$basedir = wp_normalize_path( trailingslashit( $uploads['basedir'] ) );

		foreach ( $files as $relative ) {
			$relative = ltrim( wp_normalize_path( (string) $relative ), '/' );
			if ( '' === $relative || false !== strpos( $relative, '..' ) || '.webp' !== substr( $relative, -5 ) ) {
				continue;
			}
			$path = $basedir . $relative;
			if ( file_exists( $path ) ) {
				wp_delete_file( $path );
			}
		}

		delete_post_meta( $attachment_id, self::META_KEY );
	}

	/**
	 * Whether the current visitor's browser accepts WebP.
	 *
	 * @return bool
	 */
	public static function browser_accepts_webp() {
		static $accepts = null;

		if ( null === $accepts ) {
			$header  = isset( $_SERVER['HTTP_ACCEPT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) : '';
			$accepts = ( false !== stripos( $header, 'image/webp' ) );

			/**
			 * Filters whether WebP files may be served to the current request.
			 *
			 * @param bool $accepts Whether the Accept header lists image/webp.
			 */
			$accepts = (bool) apply_filters( 'altcraft_ai_serve_webp', $accepts );
		}

		return $accepts;
	}

	/**
	 * Adds a Vary header so caches keep WebP and non-WebP responses apart.
	 *
	 * @return void
	 */
	public function vary_header() {
		if ( ! headers_sent() ) {
			header( 'Vary: Accept', false );
		}
	}

	/**
	 * Swaps the primary image URL for wp_get_attachment_image().
	 *
	 * @param array|false $image         Image data.
	 * @param int         $attachment_id Attachment ID.
	 * @return array|false
	 */
	public function filter_image_src( $image, $attachment_id ) {
		unset( $attachment_id );
		if ( ! is_array( $image ) || empty( $image[0] ) || ! self::browser_accepts_webp() || is_feed() ) {
			return $image;
		}
		$webp = self::webp_url_for( $image[0] );
		if ( $webp ) {
			$image[0] = $webp;
		}
		return $image;
	}

	/**
	 * Swaps srcset candidates.
	 *
	 * @param array $sources Srcset sources.
	 * @return array
	 */
	public function filter_srcset( $sources ) {
		if ( ! is_array( $sources ) || ! self::browser_accepts_webp() || is_feed() ) {
			return $sources;
		}
		foreach ( $sources as $width => $source ) {
			if ( empty( $source['url'] ) ) {
				continue;
			}
			$webp = self::webp_url_for( $source['url'] );
			if ( $webp ) {
				$sources[ $width ]['url'] = $webp;
			}
		}
		return $sources;
	}

	/**
	 * Rewrites JPEG/PNG upload URLs inside content <img> tags.
	 *
	 * @param string $image The <img> tag HTML.
	 * @return string
	 */
	public function filter_content_img( $image ) {
		if ( ! is_string( $image ) || ! self::browser_accepts_webp() || is_feed() ) {
			return $image;
		}

		$uploads = wp_get_upload_dir();
		$baseurl = preg_quote( set_url_scheme( $uploads['baseurl'], 'relative' ), '#' );

		return preg_replace_callback(
			'#(?:(?:https?:)?//[^/\s"\'<>]+)?' . $baseurl . '/[^\s"\'<>,]+\.(?:jpe?g|png)#i',
			static function ( $matches ) {
				$webp = AltCraft_WebP::webp_url_for( $matches[0] );
				return $webp ? $webp : $matches[0];
			},
			$image
		);
	}

	/**
	 * Returns the WebP URL for an upload URL when the file exists, otherwise an empty string.
	 *
	 * @param string $url Image URL.
	 * @return string
	 */
	public static function webp_url_for( $url ) {
		if ( isset( self::$url_cache[ $url ] ) ) {
			return self::$url_cache[ $url ];
		}

		$result = '';

		if ( preg_match( '/\.(jpe?g|png)$/i', $url ) ) {
			$uploads  = wp_get_upload_dir();
			$base_url = set_url_scheme( $uploads['baseurl'], 'relative' );
			$rel_url  = set_url_scheme( $url, 'relative' );

			if ( 0 === strpos( $rel_url, $base_url ) ) {
				$relative = substr( $rel_url, strlen( $base_url ) );
				$relative = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $relative );
				$path     = wp_normalize_path( $uploads['basedir'] . $relative );

				if ( false === strpos( $relative, '..' ) && file_exists( $path ) ) {
					$result = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $url );
				}
			}
		}

		self::$url_cache[ $url ] = $result;

		return $result;
	}
}
