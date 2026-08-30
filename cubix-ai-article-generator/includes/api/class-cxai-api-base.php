<?php
/**
 * Abstract base class for AI API providers.
 *
 * @package Cubix_AI_Article_Generator
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CXAI_API_Base
 *
 * Providers implement chat(); single-prompt generation derives from it.
 * All HTTP goes through the shared wp_remote_post() wrapper — no cURL and
 * no external HTTP libraries.
 */
abstract class CXAI_API_Base {

	/**
	 * Decrypted API key.
	 *
	 * @var string
	 */
	protected $api_key;

	/**
	 * Model identifier.
	 *
	 * @var string
	 */
	protected $model;

	/**
	 * Sampling temperature (0–1).
	 *
	 * @var float
	 */
	protected $temperature;

	/**
	 * Maximum tokens to generate.
	 *
	 * @var int
	 */
	protected $max_tokens;

	/**
	 * Whether the most recent reply was cut off by the output budget.
	 *
	 * @var bool
	 */
	protected $truncated = false;

	/**
	 * How many times a cut-off answer may be continued automatically.
	 */
	const MAX_CONTINUATIONS = 6;

	/**
	 * Optional system instruction.
	 *
	 * @var string
	 */
	protected $system = '';

	/**
	 * Constructor.
	 *
	 * @param string $api_key Decrypted API key.
	 * @param string $model   Model identifier.
	 * @param array  $args    Optional. temperature, max_tokens, system.
	 */
	public function __construct( $api_key, $model, array $args = array() ) {
		// Keep only printable ASCII: pasted keys often carry a trailing
		// newline, non-breaking space or zero-width character, which makes
		// the Authorization header invalid and silently unauthenticated.
		$this->api_key     = trim( preg_replace( '/[^\x21-\x7E]/', '', (string) $api_key ) );
		$this->model       = $model;
		$this->temperature = isset( $args['temperature'] ) ? (float) $args['temperature'] : 0.7;
		$this->max_tokens  = isset( $args['max_tokens'] ) ? (int) $args['max_tokens'] : 2048;
		$this->system      = isset( $args['system'] ) ? (string) $args['system'] : '';
	}

	/**
	 * Raise the output budget when a request needs a longer answer.
	 *
	 * @param int $tokens Minimum tokens required.
	 * @return void
	 */
	public function ensure_max_tokens( $tokens ) {
		$tokens = (int) $tokens;

		if ( $tokens > $this->max_tokens ) {
			$this->max_tokens = $tokens;
		}
	}

	/**
	 * Provider slug.
	 *
	 * @return string
	 */
	abstract public function get_slug();

	/**
	 * Run a multi-turn chat completion.
	 *
	 * @param array $messages List of ['role' => 'user'|'assistant', 'content' => string].
	 * @return string|WP_Error Assistant reply text on success.
	 */
	abstract public function chat( array $messages );

	/**
	 * Whether this provider works without an API key.
	 *
	 * @return bool
	 */
	public function is_free() {
		return false;
	}

	/**
	 * Set the system instruction.
	 *
	 * @param string $system System prompt.
	 * @return void
	 */
	public function set_system( $system ) {
		$this->system = (string) $system;
	}

	/**
	 * Generate content from a single prompt.
	 *
	 * @param string $prompt User prompt.
	 * @return string|WP_Error
	 */
	public function generate( $prompt ) {
		return $this->complete(
			array(
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			)
		);
	}

	/**
	 * Send a conversation and transparently finish a cut-off answer.
	 *
	 * Every provider stops once the output budget is reached, which leaves
	 * long articles ending mid-sentence. When that happens the reply is
	 * trimmed back to a clean boundary and the model is asked to carry on,
	 * up to a few times, so the caller receives one complete piece.
	 *
	 * @param array $messages Conversation messages.
	 * @return string|WP_Error
	 */
	public function complete( array $messages ) {
		$reply = $this->chat( $messages );

		if ( is_wp_error( $reply ) ) {
			return $reply;
		}

		$full   = (string) $reply;
		$rounds = 0;

		// Some providers report a normal stop even when the budget ran
		// out, so also treat "answer filled the budget" as cut off.
		$capped = $this->truncated || $this->hit_budget( $full );

		while ( $capped && $rounds < self::MAX_CONTINUATIONS ) {
			$rounds++;
			$this->truncated = false;

			$boundary = self::trim_to_boundary( $full );
			$full     = $boundary['text'];

			if ( '' === $full ) {
				break;
			}

			$tail = function_exists( 'mb_substr' ) ? mb_substr( $full, -2200 ) : substr( $full, -2200 );

			$follow   = $messages;
			$follow[] = array(
				'role'    => 'assistant',
				'content' => $tail,
			);
			$follow[] = array(
				'role'    => 'user',
				'content' => 'Continue from exactly where that text stops, without skipping anything. If the last section is unfinished, finish it first, then carry on with the remaining sections in order until the whole piece is complete, including any FAQ or closing section that was requested. Do not repeat sentences already written, do not restate the title or earlier headings, and do not add a new introduction. If the piece is already complete, reply with exactly: DONE',
			);

			$next = $this->chat( $follow );

			if ( is_wp_error( $next ) || '' === trim( (string) $next ) ) {
				break;
			}

			$capped = $this->truncated || $this->hit_budget( (string) $next );

			// The model signals it has nothing left to add.
			if ( preg_match( '/^\[?DONE\]?[.\s]*$/i', trim( (string) $next ) ) ) {
				$capped = false;
				break;
			}

			$next = preg_replace( '/\s*\[?DONE\]?[.\s]*$/i', '', (string) $next );
			$next = self::drop_seam_repeat( $full, (string) $next );

			if ( '' === trim( $next ) ) {
				break;
			}

			$full = rtrim( $full ) . ( $boundary['paragraph'] ? "\n\n" : ' ' ) . ltrim( $next );
		}

		// Report the final state so the caller can warn the user.
		$this->truncated = $capped;

		return $full;
	}

	/**
	 * Rough check for an answer that filled its output budget.
	 *
	 * English averages about four characters per token, so a reply that
	 * reaches most of the budget almost certainly stopped because of it.
	 *
	 * @param string $text Reply text.
	 * @return bool
	 */
	protected function hit_budget( $text ) {
		if ( $this->max_tokens < 1 ) {
			return false;
		}

		$length = function_exists( 'mb_strlen' ) ? mb_strlen( (string) $text ) : strlen( (string) $text );

		return ( $length / 4 ) >= ( $this->max_tokens * 0.85 );
	}

	/**
	 * Trim text back to the last clean sentence or paragraph break.
	 *
	 * @param string $text Possibly mid-sentence text.
	 * @return array{text: string, paragraph: bool}
	 */
	protected static function trim_to_boundary( $text ) {
		$text = (string) $text;

		$paragraph_at = strrpos( $text, "\n" );
		$paragraph_at = ( false === $paragraph_at ) ? 0 : $paragraph_at;
		$sentence_at  = 0;

		if ( preg_match_all( '/[.!?\x{2026}]["\')\]]?(?:\s|$)/u', $text, $matches, PREG_OFFSET_CAPTURE ) ) {
			$last        = end( $matches[0] );
			$sentence_at = (int) $last[1] + strlen( $last[0] );
		}

		$cut = max( $paragraph_at, $sentence_at );

		// No sentence or paragraph boundary at all — keep what we have.
		if ( $cut < 1 ) {
			return array(
				'text'      => rtrim( $text ),
				'paragraph' => false,
			);
		}

		return array(
			'text'      => rtrim( substr( $text, 0, $cut ) ),
			// Rejoin on a new line when the cut lands on a line break, so
			// lists and headings do not run into the continuation.
			'paragraph' => ( $paragraph_at > 0 && ( $paragraph_at + 1 ) >= $cut ),
		);
	}

	/**
	 * Remove an opening line the model repeated from the previous chunk.
	 *
	 * Models often restate the last heading or lead-in when continuing,
	 * which would otherwise appear twice in the stitched result.
	 *
	 * @param string $existing Text so far.
	 * @param string $addition Continuation text.
	 * @return string
	 */
	protected static function drop_seam_repeat( $existing, $addition ) {
		$tail_lines = preg_split( '/\n/', rtrim( $existing ) );
		$tail_lines = array_slice( (array) $tail_lines, -3 );
		$tail_lines = array_map( 'trim', $tail_lines );

		$lines = preg_split( '/\n/', ltrim( $addition ) );

		while ( ! empty( $lines ) ) {
			$first = trim( $lines[0] );

			if ( '' === $first ) {
				array_shift( $lines );
				continue;
			}

			if ( ! in_array( $first, $tail_lines, true ) ) {
				break;
			}

			array_shift( $lines );
		}

		return implode( "\n", (array) $lines );
	}

	/**
	 * Whether the last reply hit the output budget.
	 *
	 * @return bool
	 */
	public function was_truncated() {
		return (bool) $this->truncated;
	}

	/**
	 * Send a minimal request to verify the key / endpoint works.
	 *
	 * @return true|WP_Error
	 */
	public function test_connection() {
		// Thinking models (e.g. newer Gemini) spend tokens before emitting
		// text, so a tiny budget would come back empty.
		$this->max_tokens = 512;
		$result           = $this->generate( 'Reply with the single word: OK' );

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Whether a key has been configured (always true for free providers).
	 *
	 * @return bool
	 */
	public function has_key() {
		return $this->is_free() || '' !== trim( (string) $this->api_key );
	}

	/**
	 * Shared JSON POST helper built on the WP HTTP API.
	 *
	 * @param string $url     Endpoint URL.
	 * @param array  $headers Request headers.
	 * @param array  $body    Request body.
	 * @return array|WP_Error Decoded JSON body on success.
	 */
	protected function request( $url, array $headers, array $body ) {
		if ( ! $this->has_key() ) {
			return new WP_Error(
				'cxai_missing_key',
				__( 'No API key configured for this engine. Add one on the AI Engines tab.', 'cubix-ai-article-generator' )
			);
		}

		$args = array(
			'timeout' => 90,
			'headers' => array_merge( array( 'Content-Type' => 'application/json' ), $headers ),
			'body'    => wp_json_encode( $body ),
		);

		$response = wp_remote_post( esc_url_raw( $url ), $args );

		// One quick retry when the model is momentarily overloaded
		// (503 / 529) — demand spikes usually clear within seconds.
		if ( ! is_wp_error( $response ) ) {
			$code = (int) wp_remote_retrieve_response_code( $response );

			if ( 503 === $code || 529 === $code ) {
				sleep( 2 );
				$response = wp_remote_post( esc_url_raw( $url ), $args );
			}
		}

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'cxai_http_error',
				sprintf(
					/* translators: %s: transport error message. */
					__( 'Could not reach the API: %s', 'cubix-ai-article-generator' ),
					$response->get_error_message()
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'cxai_api_error', $this->extract_error_message( $code, $data ) );
		}

		if ( ! is_array( $data ) ) {
			return new WP_Error(
				'cxai_bad_response',
				__( 'The API returned an unexpected response format.', 'cubix-ai-article-generator' )
			);
		}

		return $data;
	}

	/**
	 * Build a human-readable error message from an API error payload.
	 *
	 * @param int        $code HTTP status code.
	 * @param array|null $data Decoded response body.
	 * @return string
	 */
	protected function extract_error_message( $code, $data ) {
		$detail     = '';
		$error_code = '';

		if ( is_array( $data ) ) {
			if ( isset( $data['error']['message'] ) ) {
				$detail = (string) $data['error']['message'];
			} elseif ( isset( $data['message'] ) ) {
				$detail = (string) $data['message'];
			}

			if ( isset( $data['error']['code'] ) ) {
				$error_code = (string) $data['error']['code'];
			} elseif ( isset( $data['error']['type'] ) ) {
				$error_code = (string) $data['error']['type'];
			}
		}

		$haystack = strtolower( $error_code . ' ' . $detail );

		// Billing / quota problems masquerade under several statuses
		// (429, sometimes even 500), so detect them by content first.
		if ( false !== strpos( $haystack, 'insufficient_quota' )
			|| false !== strpos( $haystack, 'exceeded your current quota' )
			|| false !== strpos( $haystack, 'billing' )
			|| false !== strpos( $haystack, 'insufficient balance' )
			|| false !== strpos( $haystack, 'purchase credits' ) ) {
			$hint = __( 'No usable credit on this account.', 'cubix-ai-article-generator' );
		} elseif ( 401 === $code || 403 === $code ) {
			$hint = __( 'The API key was rejected — check it is correct and active.', 'cubix-ai-article-generator' );
		} elseif ( 404 === $code ) {
			$hint = __( 'That model was not found for this key — pick a different model.', 'cubix-ai-article-generator' );
		} elseif ( 429 === $code ) {
			$hint = __( 'Rate limit reached — wait a moment and try again.', 'cubix-ai-article-generator' );
		} elseif ( $code >= 500 ) {
			$hint = __( 'The AI service reported an internal problem — this is usually temporary, but on brand-new unfunded accounts it can also mean billing is not set up.', 'cubix-ai-article-generator' );
		} else {
			$hint = __( 'The request was not accepted.', 'cubix-ai-article-generator' );
		}

		// Always surface the engine's own words, clearly attributed.
		if ( '' !== $detail ) {
			return sprintf(
				/* translators: 1: plain-language hint, 2: raw API message, 3: HTTP status code. */
				__( '%1$s Engine response: "%2$s" (HTTP %3$d)', 'cubix-ai-article-generator' ),
				$hint,
				sanitize_text_field( $detail ),
				$code
			);
		}

		return sprintf(
			/* translators: 1: plain-language hint, 2: HTTP status code. */
			__( '%1$s (HTTP %2$d, no further detail from the engine)', 'cubix-ai-article-generator' ),
			$hint,
			$code
		);
	}

	/**
	 * Normalize a message list to safe role/content pairs.
	 *
	 * @param array $messages Raw messages.
	 * @return array
	 */
	protected function normalize_messages( array $messages ) {
		$clean = array();

		foreach ( $messages as $message ) {
			if ( ! isset( $message['role'], $message['content'] ) ) {
				continue;
			}

			$clean[] = array(
				'role'    => ( 'assistant' === $message['role'] ) ? 'assistant' : 'user',
				'content' => (string) $message['content'],
			);
		}

		return $clean;
	}
}
