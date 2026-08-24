<?php
/**
 * Vision API client for Google Gemini and OpenAI.
 *
 * Both providers are asked for a small JSON object { alt, title, caption } so a single
 * request produces every field the plugin needs.
 *
 * @package AltCraft_AI_Image_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * API client.
 */
class AltCraft_API {

	const GEMINI_ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';
	const OPENAI_ENDPOINT = 'https://api.openai.com/v1/responses';

	/**
	 * 32×32 PNG (a red circle on light grey, 205 bytes) used by the connection test so the whole
	 * vision + structured-output pipeline is exercised without touching the media library.
	 */
	const TEST_IMAGE_PNG = 'iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAIAAAD8GO2jAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAf0lEQVRIx2N8/fo1Ay0BEwONwagFA28BC/FK79raInOVDx8mRhcjMckUzWiSrCFgAR6jibSGiXLT8atkotx0/OoHKJmS6nw8ugbCB+Q5H5fe0bJoUFpAZClGZJkxQEFEniew6hq4SCbVE7jUM5FXyhOvcqBrNDrVyaNFxdC2AADldTfD5hYIHwAAAABJRU5ErkJggg==';

	/**
	 * MIME types each provider accepts as inline image data.
	 *
	 * @param string $provider Provider slug.
	 * @return array
	 */
	public static function allowed_mimes( $provider ) {
		if ( 'openai' === $provider ) {
			return array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' );
		}
		return array( 'image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif' );
	}

	/**
	 * Asks the provider to describe an image.
	 *
	 * @param string     $provider Provider slug (gemini|openai).
	 * @param string     $model    Model ID.
	 * @param string     $api_key  API key.
	 * @param string     $prompt   Instruction prompt.
	 * @param array|null $payload  Image payload from AltCraft_Image::get_payload(), or null for a
	 *                             text-only request (filename-only mode).
	 * @return array|WP_Error Array with alt, title, caption keys.
	 */
	public static function describe_image( $provider, $model, $api_key, $prompt, $payload = null ) {
		if ( 'openai' === $provider ) {
			$text = self::request_openai( $model, $api_key, $prompt, $payload, true, true );
		} else {
			$text = self::request_gemini( $model, $api_key, $prompt, $payload, true, true );
		}

		if ( is_wp_error( $text ) ) {
			return $text;
		}

		return self::parse_result( $text );
	}

	/**
	 * Verifies credentials, model access and vision support by describing a tiny embedded test image
	 * through the exact pipeline used for real images (image + JSON schema).
	 *
	 * @param string $provider Provider slug.
	 * @param string $model    Model ID.
	 * @param string $api_key  API key.
	 * @return array|WP_Error Array with "alt" (what the model saw) and "seconds" (round-trip time).
	 */
	public static function test_connection( $provider, $model, $api_key ) {
		$prompt  = 'You are testing an image description service. Describe this small test image in one short sentence. Also give a short title and a one sentence caption. Respond with a JSON object with the keys "alt", "title" and "caption".';
		$payload = array(
			'data'   => self::TEST_IMAGE_PNG,
			'mime'   => 'image/png',
			'source' => 'test',
		);
		$start   = microtime( true );

		$result = self::describe_image( $provider, $model, $api_key, $prompt, $payload );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'alt'     => $result['alt'],
			'seconds' => round( microtime( true ) - $start, 1 ),
		);
	}

	/**
	 * JSON schema shared by both providers.
	 *
	 * @param bool $gemini Whether to return the Gemini (OpenAPI subset, upper-case types) variant.
	 * @return array
	 */
	private static function schema( $gemini = false ) {
		$string = $gemini ? 'STRING' : 'string';
		$object = $gemini ? 'OBJECT' : 'object';

		$schema = array(
			'type'       => $object,
			'properties' => array(
				'alt'     => array(
					'type'        => $string,
					'description' => 'The image ALT text. One natural sentence fragment, no quotes, no "image of".',
				),
				'title'   => array(
					'type'        => $string,
					'description' => 'A short human readable image title, 3 to 8 words, Title Case, no file extension.',
				),
				'caption' => array(
					'type'        => $string,
					'description' => 'One descriptive sentence suitable as a visible caption.',
				),
			),
			'required'   => array( 'alt', 'title', 'caption' ),
		);

		if ( ! $gemini ) {
			$schema['additionalProperties'] = false;
		}

		return $schema;
	}

	/**
	 * Common HTTP arguments.
	 *
	 * @param array $headers Request headers.
	 * @param array $body    Request body (will be JSON encoded).
	 * @return array
	 */
	private static function http_args( $headers, $body ) {
		$settings = AltCraft_Settings::get();
		$default  = isset( $settings['request_timeout'] ) ? (int) $settings['request_timeout'] : 60;

		/**
		 * Filters the HTTP timeout (seconds) for provider requests.
		 *
		 * @param int $timeout Value from the settings (default 60).
		 */
		$timeout = (int) apply_filters( 'altcraft_ai_request_timeout', $default );

		return array(
			'timeout'    => max( 10, min( 300, $timeout ) ),
			'headers'    => array_merge(
				array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				$headers
			),
			'body'       => wp_json_encode( $body ),
			'user-agent' => 'AltCraft-AI-Image-SEO/' . ALTCRAFT_AI_VERSION . ' (WordPress; ' . home_url( '/' ) . ')',
		);
	}

	/**
	 * Calls the Gemini generateContent endpoint.
	 *
	 * @param string     $model         Model ID.
	 * @param string     $api_key       API key.
	 * @param string     $prompt        Prompt text.
	 * @param array|null $payload       Image payload or null for text-only.
	 * @param bool       $with_thinking Whether to send a thinking configuration (retried without on HTTP 400).
	 * @param bool       $json          Whether to request the structured JSON result.
	 * @return string|WP_Error Raw text returned by the model.
	 */
	private static function request_gemini( $model, $api_key, $prompt, $payload = null, $with_thinking = true, $json = true ) {
		$parts = array( array( 'text' => $prompt ) );

		if ( $payload ) {
			$parts[] = array(
				'inlineData' => array(
					'mimeType' => $payload['mime'],
					'data'     => $payload['data'],
				),
			);
		}

		$generation_config = array(
			'maxOutputTokens' => 1024,
		);

		if ( $json ) {
			$generation_config['responseMimeType'] = 'application/json';
			$generation_config['responseSchema']   = self::schema( true );
		}

		if ( $with_thinking ) {
			$thinking = self::gemini_thinking_config( $model );
			if ( $thinking ) {
				$generation_config['thinkingConfig'] = $thinking;
			}
		}

		$body = array(
			'contents'         => array(
				array(
					'role'  => 'user',
					'parts' => $parts,
				),
			),
			'generationConfig' => $generation_config,
		);

		/**
		 * Filters the Gemini request body before it is sent.
		 *
		 * @param array  $body  Request body.
		 * @param string $model Model ID.
		 */
		$body = apply_filters( 'altcraft_ai_gemini_request_body', $body, $model );

		$endpoint = sprintf( self::GEMINI_ENDPOINT, rawurlencode( $model ) );
		$response = wp_remote_post( $endpoint, self::http_args( array( 'x-goog-api-key' => $api_key ), $body ) );

		if ( is_wp_error( $response ) ) {
			return self::transport_error( $response, $payload );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 400 === $code && $with_thinking && ! empty( $generation_config['thinkingConfig'] ) ) {
			// The model may not accept this thinking configuration – retry once without it.
			return self::request_gemini( $model, $api_key, $prompt, $payload, false, $json );
		}

		if ( 200 !== $code ) {
			return self::http_error( 'gemini', $code, $data, $response );
		}

		if ( ! empty( $data['promptFeedback']['blockReason'] ) ) {
			return new WP_Error( 'blocked', __( 'The provider blocked this image because of its safety filters.', 'altcraft-ai-image-seo' ) );
		}

		$candidate = isset( $data['candidates'][0] ) ? $data['candidates'][0] : array();
		$text      = '';

		if ( ! empty( $candidate['content']['parts'] ) && is_array( $candidate['content']['parts'] ) ) {
			foreach ( $candidate['content']['parts'] as $part ) {
				if ( ! empty( $part['thought'] ) ) {
					continue;
				}
				if ( isset( $part['text'] ) ) {
					$text .= $part['text'];
				}
			}
		}

		$text = trim( $text );

		if ( '' === $text ) {
			$reason = isset( $candidate['finishReason'] ) ? (string) $candidate['finishReason'] : '';
			if ( 'SAFETY' === $reason || 'PROHIBITED_CONTENT' === $reason || 'BLOCKLIST' === $reason ) {
				return new WP_Error( 'blocked', __( 'The provider blocked this image because of its safety filters.', 'altcraft-ai-image-seo' ) );
			}
			return new WP_Error( 'empty_response', __( 'The model returned an empty response. Try again or choose another model.', 'altcraft-ai-image-seo' ) );
		}

		return $text;
	}

	/**
	 * Returns a thinking configuration suitable for the model family (keeps requests fast and cheap).
	 *
	 * @param string $model Model ID.
	 * @return array|null
	 */
	private static function gemini_thinking_config( $model ) {
		$config = null;

		if ( 0 === strpos( $model, 'gemini-2.5-flash' ) ) {
			$config = array( 'thinkingBudget' => 0 );
		} elseif ( 0 === strpos( $model, 'gemini-3' ) ) {
			$config = array( 'thinkingLevel' => 'low' );
		}

		/**
		 * Filters the Gemini thinking configuration. Return null to omit it.
		 *
		 * @param array|null $config Thinking config.
		 * @param string     $model  Model ID.
		 */
		return apply_filters( 'altcraft_ai_gemini_thinking_config', $config, $model );
	}

	/**
	 * Calls the OpenAI Responses endpoint.
	 *
	 * @param string     $model          Model ID.
	 * @param string     $api_key        API key.
	 * @param string     $prompt         Prompt text.
	 * @param array|null $payload        Image payload or null for text-only.
	 * @param bool       $with_reasoning Whether to send a reasoning effort (retried without on HTTP 400).
	 * @param bool       $json           Whether to request the structured JSON result.
	 * @return string|WP_Error Raw text returned by the model.
	 */
	private static function request_openai( $model, $api_key, $prompt, $payload = null, $with_reasoning = true, $json = true ) {
		$content = array(
			array(
				'type' => 'input_text',
				'text' => $prompt,
			),
		);

		if ( $payload ) {
			$content[] = array(
				'type'      => 'input_image',
				'image_url' => 'data:' . $payload['mime'] . ';base64,' . $payload['data'],
				/**
				 * Filters the OpenAI image detail level (low|high|auto).
				 *
				 * @param string $detail Default "high".
				 */
				'detail'    => apply_filters( 'altcraft_ai_openai_image_detail', 'high' ),
			);
		}

		$body = array(
			'model'             => $model,
			'input'             => array(
				array(
					'role'    => 'user',
					'content' => $content,
				),
			),
			'max_output_tokens' => 1024,
			'store'             => false,
		);

		if ( $json ) {
			$body['text'] = array(
				'format' => array(
					'type'   => 'json_schema',
					'name'   => 'altcraft_image_seo',
					'strict' => true,
					'schema' => self::schema( false ),
				),
			);
		}

		$sent_reasoning = false;
		if ( $with_reasoning && self::openai_is_reasoning_model( $model ) ) {
			$body['reasoning'] = array( 'effort' => 'low' );
			$sent_reasoning    = true;
		}

		/**
		 * Filters the OpenAI request body before it is sent.
		 *
		 * @param array  $body  Request body.
		 * @param string $model Model ID.
		 */
		$body = apply_filters( 'altcraft_ai_openai_request_body', $body, $model );

		$response = wp_remote_post( self::OPENAI_ENDPOINT, self::http_args( array( 'Authorization' => 'Bearer ' . $api_key ), $body ) );

		if ( is_wp_error( $response ) ) {
			return self::transport_error( $response, $payload );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 400 === $code && $sent_reasoning ) {
			// Non-reasoning models reject the reasoning parameter – retry once without it.
			return self::request_openai( $model, $api_key, $prompt, $payload, false, $json );
		}

		if ( 200 !== $code ) {
			return self::http_error( 'openai', $code, $data, $response );
		}

		$text = '';
		if ( ! empty( $data['output'] ) && is_array( $data['output'] ) ) {
			foreach ( $data['output'] as $item ) {
				if ( empty( $item['type'] ) || 'message' !== $item['type'] || empty( $item['content'] ) ) {
					continue;
				}
				foreach ( $item['content'] as $chunk ) {
					if ( isset( $chunk['type'] ) && 'refusal' === $chunk['type'] ) {
						return new WP_Error( 'blocked', __( 'The provider refused to describe this image.', 'altcraft-ai-image-seo' ) );
					}
					if ( isset( $chunk['type'], $chunk['text'] ) && 'output_text' === $chunk['type'] ) {
						$text .= $chunk['text'];
					}
				}
			}
		}

		$text = trim( $text );

		if ( '' === $text ) {
			if ( isset( $data['status'] ) && 'incomplete' === $data['status'] ) {
				return new WP_Error( 'incomplete', __( 'The model stopped before finishing (token limit). Try another model.', 'altcraft-ai-image-seo' ) );
			}
			return new WP_Error( 'empty_response', __( 'The model returned an empty response. Try again or choose another model.', 'altcraft-ai-image-seo' ) );
		}

		return $text;
	}

	/**
	 * Whether an OpenAI model accepts the reasoning parameter.
	 *
	 * @param string $model Model ID.
	 * @return bool
	 */
	private static function openai_is_reasoning_model( $model ) {
		$is = ( 0 === strpos( $model, 'gpt-5' ) || preg_match( '/^o\d/', $model ) );

		/**
		 * Filters whether a reasoning effort is sent for the model.
		 *
		 * @param bool   $is    Whether the model supports reasoning effort.
		 * @param string $model Model ID.
		 */
		return (bool) apply_filters( 'altcraft_ai_openai_is_reasoning_model', $is, $model );
	}

	/**
	 * Converts the model text into the alt/title/caption array.
	 *
	 * @param string $text Raw text (ideally JSON).
	 * @return array|WP_Error
	 */
	private static function parse_result( $text ) {
		$clean = trim( $text );
		$clean = preg_replace( '/^```(?:json)?\s*/i', '', $clean );
		$clean = preg_replace( '/\s*```$/', '', $clean );

		$decoded = json_decode( $clean, true );

		if ( ! is_array( $decoded ) ) {
			// Try to isolate a JSON object embedded in prose.
			if ( preg_match( '/\{.*\}/s', $clean, $m ) ) {
				$decoded = json_decode( $m[0], true );
			}
		}

		if ( ! is_array( $decoded ) ) {
			// Fall back to using the plain text as ALT text.
			$decoded = array( 'alt' => $clean );
		}

		$result = array(
			'alt'     => isset( $decoded['alt'] ) ? sanitize_text_field( (string) $decoded['alt'] ) : '',
			'title'   => isset( $decoded['title'] ) ? sanitize_text_field( (string) $decoded['title'] ) : '',
			'caption' => isset( $decoded['caption'] ) ? sanitize_text_field( (string) $decoded['caption'] ) : '',
		);

		foreach ( $result as $key => $value ) {
			$result[ $key ] = trim( $value, " \t\n\r\0\x0B\"'`" );
		}

		if ( '' === $result['alt'] ) {
			return new WP_Error( 'empty_response', __( 'The model did not return any ALT text.', 'altcraft-ai-image-seo' ) );
		}

		return $result;
	}

	/**
	 * Wraps a transport level error. Timeouts get an actionable explanation that includes the
	 * size of the image that was being uploaded.
	 *
	 * @param WP_Error   $error   Error from wp_remote_post().
	 * @param array|null $payload Image payload that was being sent, if any.
	 * @return WP_Error
	 */
	private static function transport_error( $error, $payload = null ) {
		$message    = $error->get_error_message();
		$is_timeout = ( false !== stripos( $message, 'timed out' ) || false !== stripos( $message, 'error 28' ) || false !== stripos( $message, 'timeout' ) );

		if ( ! $is_timeout ) {
			return new WP_Error(
				'http_request_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Could not reach the AI provider: %s', 'altcraft-ai-image-seo' ),
					$message
				)
			);
		}

		$args = self::http_args( array(), array() );
		$text = sprintf(
			/* translators: %d: number of seconds */
			__( 'The AI provider did not respond within %d seconds.', 'altcraft-ai-image-seo' ),
			(int) $args['timeout']
		);

		if ( $payload && ! empty( $payload['data'] ) ) {
			$bytes = (int) floor( strlen( $payload['data'] ) * 3 / 4 );
			$text .= ' ' . sprintf(
				/* translators: %s: file size, e.g. 8.2 MB */
				__( 'The image sent was %s', 'altcraft-ai-image-seo' ),
				size_format( $bytes, 1 )
			);
			if ( isset( $payload['source'] ) && 0 === strpos( $payload['source'], 'original' ) ) {
				$text .= ' ' . __( '(the original file was used because this server could not create a smaller copy)', 'altcraft-ai-image-seo' );
			}
			$text .= '. ' . __( 'Large uploads from slow hosting are the usual cause. Raise the request timeout under AltCraft AI → Settings → Advanced, regenerate thumbnails for this image so a smaller copy can be sent, or upload a smaller version.', 'altcraft-ai-image-seo' );
		} else {
			$text .= ' ' . __( 'Check that your server can reach the provider and raise the request timeout under AltCraft AI → Settings → Advanced.', 'altcraft-ai-image-seo' );
		}

		return new WP_Error( 'timeout', $text, array( 'retry_after' => 5 ) );
	}

	/**
	 * Maps an HTTP error response to a WP_Error with a helpful message.
	 *
	 * @param string $provider Provider slug.
	 * @param int    $code     HTTP status code.
	 * @param mixed  $data     Decoded body.
	 * @param array  $response Full response (for headers).
	 * @return WP_Error
	 */
	private static function http_error( $provider, $code, $data, $response ) {
		$message = '';
		if ( is_array( $data ) && ! empty( $data['error'] ) ) {
			if ( is_array( $data['error'] ) && ! empty( $data['error']['message'] ) ) {
				$message = (string) $data['error']['message'];
			} elseif ( is_string( $data['error'] ) ) {
				$message = $data['error'];
			}
		}
		$message = sanitize_text_field( mb_substr( $message, 0, 300 ) );
		$label   = ( 'openai' === $provider ) ? 'OpenAI' : 'Gemini';

		switch ( true ) {
			case 401 === $code || 403 === $code:
				$text = sprintf(
					/* translators: %s: provider name */
					__( '%s rejected the API key. Check that the key is correct and has access to the selected model.', 'altcraft-ai-image-seo' ),
					$label
				);
				$slug = 'auth';
				break;
			case 404 === $code:
				$text = sprintf(
					/* translators: %s: provider name */
					__( '%s could not find the selected model. It may have been retired – choose another model in the settings.', 'altcraft-ai-image-seo' ),
					$label
				);
				$slug = 'model_not_found';
				break;
			case 429 === $code:
				$text = sprintf(
					/* translators: %s: provider name */
					__( '%s rate limit or quota reached. The scanner will wait and retry automatically.', 'altcraft-ai-image-seo' ),
					$label
				);
				$slug = 'rate_limited';
				break;
			case $code >= 500:
				$text = sprintf(
					/* translators: %s: provider name */
					__( '%s is temporarily unavailable. Please try again in a moment.', 'altcraft-ai-image-seo' ),
					$label
				);
				$slug = 'provider_error';
				break;
			default:
				$text = sprintf(
					/* translators: 1: provider name, 2: HTTP status code */
					__( '%1$s returned an error (HTTP %2$d).', 'altcraft-ai-image-seo' ),
					$label,
					$code
				);
				$slug = 'bad_request';
		}

		if ( '' !== $message ) {
			$text .= ' ' . $message;
		}

		$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
		if ( is_array( $retry_after ) ) {
			$retry_after = reset( $retry_after );
		}
		$retry_after = (int) $retry_after;

		return new WP_Error(
			$slug,
			$text,
			array(
				'status'      => $code,
				'retry_after' => $retry_after > 0 ? min( $retry_after, 120 ) : 0,
			)
		);
	}
}
