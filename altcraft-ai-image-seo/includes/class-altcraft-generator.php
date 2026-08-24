<?php
/**
 * Orchestrates ALT text generation for an attachment.
 *
 * @package AltCraft_AI_Image_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generation pipeline: context → prompt → API → post-processing → save.
 */
class AltCraft_Generator {

	/**
	 * Meta key storing the last generation timestamp.
	 */
	const META_GENERATED = '_altcraft_generated';

	/**
	 * Meta key storing the last error message (for the media table).
	 */
	const META_LAST_ERROR = '_altcraft_last_error';

	/**
	 * Generates and saves ALT text (plus title/caption when enabled) for an attachment.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $args          Optional. Arguments: force (bool) overwrite existing ALT text even when
	 *                             "overwrite" is disabled, save (bool) persist the result, webp (bool) also
	 *                             create WebP copies when the option is enabled. All default to true except force.
	 * @return array|WP_Error Result with alt, title, caption, saved (bool), skipped (bool).
	 */
	public static function generate( $attachment_id, $args = array() ) {
		$attachment_id = absint( $attachment_id );
		$args          = wp_parse_args(
			$args,
			array(
				'force' => false,
				'save'  => true,
				'webp'  => true,
			)
		);

		if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
			return new WP_Error( 'invalid_attachment', __( 'Invalid attachment.', 'altcraft-ai-image-seo' ) );
		}

		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return new WP_Error( 'not_an_image', __( 'This attachment is not an image.', 'altcraft-ai-image-seo' ) );
		}

		$settings = AltCraft_Settings::get();
		$existing = trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );

		if ( '' !== $existing && ! $args['force'] && 'yes' !== $settings['overwrite_existing'] ) {
			return array(
				'alt'     => $existing,
				'title'   => '',
				'caption' => '',
				'saved'   => false,
				'skipped' => true,
			);
		}

		$provider = $settings['api_provider'];
		$api_key  = AltCraft_Settings::get_api_key( $provider );
		$model    = AltCraft_Settings::get_model( $provider );

		if ( '' === $api_key ) {
			return new WP_Error( 'no_api_key', __( 'Add your AI provider API key in AltCraft AI → Settings before generating ALT text.', 'altcraft-ai-image-seo' ) );
		}

		$source = isset( $settings['generation_source'] ) ? $settings['generation_source'] : 'both';
		if ( ! array_key_exists( $source, AltCraft_Settings::generation_sources() ) ) {
			$source = 'both';
		}

		/**
		 * Filters what the AI is given for this image: "both", "image" or "filename".
		 *
		 * @param string $source        Generation source.
		 * @param int    $attachment_id Attachment ID.
		 */
		$source  = (string) apply_filters( 'altcraft_ai_generation_source', $source, $attachment_id );
		$payload = null;

		$check = AltCraft_Image::check_file( $attachment_id, 'filename' !== $source );
		if ( is_wp_error( $check ) ) {
			self::record_error( $attachment_id, $check );
			return $check;
		}

		if ( 'filename' !== $source ) {
			$payload = AltCraft_Image::get_payload( $attachment_id, AltCraft_API::allowed_mimes( $provider ) );
			if ( is_wp_error( $payload ) ) {
				self::record_error( $attachment_id, $payload );
				return $payload;
			}
		}

		$context           = AltCraft_Context::get( $attachment_id );
		$context['source'] = $source;

		if ( 'image' === $source ) {
			$context['filename'] = '';
		}

		if ( 'filename' === $source && '' === $context['filename'] && '' === $context['parent_title'] ) {
			$error = new WP_Error(
				'no_context',
				__( 'Nothing to work with: the filename has no descriptive words and the image is not attached to a post or product. Rename the file, or switch "What the AI looks at" to include the image.', 'altcraft-ai-image-seo' )
			);
			self::record_error( $attachment_id, $error );
			return $error;
		}

		$prompt = self::build_prompt( $settings, $context, $attachment_id );

		$result = AltCraft_API::describe_image( $provider, $model, $api_key, $prompt, $payload );
		if ( is_wp_error( $result ) ) {
			self::record_error( $attachment_id, $result );
			return $result;
		}

		$result = self::post_process( $result, $settings );

		/**
		 * Filters the generated result before it is saved.
		 *
		 * @param array $result        alt, title, caption.
		 * @param int   $attachment_id Attachment ID.
		 * @param array $context       Context data.
		 */
		$result = apply_filters( 'altcraft_ai_result', $result, $attachment_id, $context );

		$result['saved']   = false;
		$result['skipped'] = false;

		if ( $args['save'] ) {
			self::save( $attachment_id, $result, $settings );
			$result['saved'] = true;

			if ( $args['webp'] && 'yes' === $settings['enable_webp_convert'] ) {
				AltCraft_WebP::convert( $attachment_id );
			}
		}

		return $result;
	}

	/**
	 * Builds the instruction prompt.
	 *
	 * @param array $settings      Plugin settings.
	 * @param array $context       Context data.
	 * @param int   $attachment_id Attachment ID.
	 * @return string
	 */
	public static function build_prompt( $settings, $context, $attachment_id ) {
		$language  = $settings['output_language'];
		$style     = $settings['alt_style'];
		$name_only = ( isset( $context['source'] ) && 'filename' === $context['source'] );

		switch ( $style ) {
			case 'descriptive':
				$alt_rules = 'Write the ALT text for accessibility first: describe what a person who cannot see the image needs to know, in one plain sentence fragment of at most 150 characters.';
				break;
			case 'keyword_rich':
				$alt_rules = 'Write the ALT text for image search: lead with the most specific subject (product, brand or place if visible), stay factual, at most 125 characters.';
				break;
			default:
				$alt_rules = 'Write a concise, natural ALT text of at most 125 characters that describes the image accurately and includes the main subject as a search keyword.';
		}

		$lines   = array();
		$lines[] = 'You are an image SEO and accessibility specialist for a website.';
		if ( $name_only ) {
			$lines[] = 'You cannot see this image. Work only from the filename and the context below: describe the likely subject plainly and keep it generic where the context is thin.';
		}
		$lines[] = $alt_rules;
		$lines[] = $name_only
			? 'Rules for the ALT text: no leading phrases like "image of" or "photo of", no quotation marks, no trailing period, no keyword stuffing, never invent specific visual details (colours, people, text, settings) that the context does not support.'
			: 'Rules for the ALT text: no leading phrases like "image of" or "photo of", no quotation marks, no trailing period, no keyword stuffing, never invent details that are not visible.';
		$lines[] = 'Also write a short title (3 to 8 words, Title Case) and a one sentence caption.';
		$lines[] = sprintf( 'Write everything in %s.', $language );

		$facts = array();
		if ( ! empty( $context['parent_title'] ) ) {
			if ( ! empty( $context['is_product'] ) ) {
				$facts[] = sprintf( 'This image belongs to the product "%s".', $context['parent_title'] );
				if ( ! empty( $context['categories'] ) ) {
					$facts[] = sprintf( 'Product categories: %s.', implode( ', ', array_slice( (array) $context['categories'], 0, 5 ) ) );
				}
				if ( ! empty( $context['sku'] ) ) {
					$facts[] = sprintf( 'Product SKU: %s (do not include the SKU in the ALT text unless it is visible).', $context['sku'] );
				}
			} else {
				$facts[] = sprintf( 'This image is used in the page or post "%s".', $context['parent_title'] );
			}
		}
		if ( ! empty( $context['filename'] ) ) {
			$facts[] = $name_only
				? sprintf( 'Filename: "%s".', $context['filename'] )
				: sprintf( 'Filename hint (may be irrelevant): "%s".', $context['filename'] );
		}

		$brand = trim( (string) $settings['brand_keywords'] );
		if ( '' !== $brand && ( 'yes' === $settings['enable_woo_focus'] || 'keyword_rich' === $style ) ) {
			$facts[] = sprintf( 'Brand or store keywords to include naturally only when relevant: %s.', $brand );
		}

		if ( 'yes' !== $settings['enable_woo_focus'] ) {
			// Product context is disabled: keep only the generic page context and filename.
			$facts = array_values(
				array_filter(
					$facts,
					static function ( $fact ) {
						return 0 !== strpos( $fact, 'This image belongs to the product' ) && 0 !== strpos( $fact, 'Product ' );
					}
				)
			);
		}

		if ( $facts ) {
			$lines[] = 'Context: ' . implode( ' ', $facts );
		}

		$lines[] = 'Respond with a JSON object with the keys "alt", "title" and "caption" and nothing else.';

		$prompt = implode( "\n", $lines );

		/**
		 * Filters the prompt sent to the AI provider.
		 *
		 * @param string $prompt        Prompt text.
		 * @param int    $attachment_id Attachment ID.
		 * @param array  $context       Context data.
		 * @param array  $settings      Plugin settings.
		 */
		return (string) apply_filters( 'altcraft_ai_prompt', $prompt, $attachment_id, $context, $settings );
	}

	/**
	 * Normalizes the model output (length limits, casing, fallbacks).
	 *
	 * @param array $result   Raw result.
	 * @param array $settings Plugin settings.
	 * @return array
	 */
	public static function post_process( $result, $settings ) {
		$alt     = self::tidy( $result['alt'], 'descriptive' === $settings['alt_style'] ? 160 : 140 );
		$title   = self::tidy( isset( $result['title'] ) ? $result['title'] : '', 90 );
		$caption = self::tidy( isset( $result['caption'] ) ? $result['caption'] : '', 300 );

		$alt = preg_replace( '/^(an?\s+)?(image|photo|picture|photograph|illustration|screenshot)\s+(of|showing)\s+/i', '', $alt );
		$alt = rtrim( $alt, '.' );
		if ( '' !== $alt ) {
			$alt = mb_strtoupper( mb_substr( $alt, 0, 1 ) ) . mb_substr( $alt, 1 );
		}

		if ( '' === $title && '' !== $alt ) {
			$title = self::tidy( $alt, 60 );
		}
		$title = rtrim( $title, '.' );

		return array(
			'alt'     => $alt,
			'title'   => $title,
			'caption' => $caption,
		);
	}

	/**
	 * Trims whitespace/quotes and cuts to a maximum length on a word boundary.
	 *
	 * @param string $text   Text.
	 * @param int    $length Max length.
	 * @return string
	 */
	private static function tidy( $text, $length ) {
		$text = trim( (string) $text, " \t\n\r\0\x0B\"'`" );
		$text = preg_replace( '/\s+/', ' ', $text );

		if ( mb_strlen( $text ) > $length ) {
			$text = mb_substr( $text, 0, $length );
			$cut  = mb_strrpos( $text, ' ' );
			if ( false !== $cut && $cut > $length * 0.6 ) {
				$text = mb_substr( $text, 0, $cut );
			}
			$text = rtrim( $text, ' ,;:-' );
		}

		return $text;
	}

	/**
	 * Persists the generated values.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $result        alt, title, caption.
	 * @param array $settings      Plugin settings.
	 * @return void
	 */
	public static function save( $attachment_id, $result, $settings ) {
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $result['alt'] );

		$post_data = array();
		if ( 'yes' === $settings['sync_title'] && '' !== $result['title'] ) {
			$post_data['post_title'] = $result['title'];
		}
		if ( 'yes' === $settings['sync_caption'] && '' !== $result['caption'] ) {
			$post_data['post_excerpt'] = $result['caption'];
		}

		if ( $post_data ) {
			$post_data['ID'] = $attachment_id;
			wp_update_post( wp_slash( $post_data ) );
		}

		update_post_meta( $attachment_id, self::META_GENERATED, time() );
		delete_post_meta( $attachment_id, self::META_LAST_ERROR );

		AltCraft_Stats::invalidate();

		/**
		 * Fires after ALT text has been generated and saved.
		 *
		 * @param int   $attachment_id Attachment ID.
		 * @param array $result        alt, title, caption.
		 */
		do_action( 'altcraft_ai_after_generate', $attachment_id, $result );
	}

	/**
	 * Stores the last error so it can be shown in the media table.
	 *
	 * @param int      $attachment_id Attachment ID.
	 * @param WP_Error $error         Error.
	 * @return void
	 */
	private static function record_error( $attachment_id, $error ) {
		update_post_meta(
			$attachment_id,
			self::META_LAST_ERROR,
			array(
				'time'    => time(),
				'code'    => $error->get_error_code(),
				'message' => mb_substr( $error->get_error_message(), 0, 300 ),
			)
		);
	}
}
