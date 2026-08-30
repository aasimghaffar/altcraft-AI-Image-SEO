<?php
/**
 * OpenRouter provider.
 *
 * Extends the generic OpenAI-compatible client with one crucial ability:
 * OpenRouter's set of free models rotates constantly, so instead of
 * hardcoding a slug that will eventually vanish, the "Auto — free models"
 * option queries OpenRouter's live model list, picks a currently-free
 * model, and caches the choice for a few hours. When a cached choice
 * stops working, it re-resolves once and retries automatically.
 *
 * @package Cubix_AI_Article_Generator
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CXAI_OpenRouter
 */
class CXAI_OpenRouter extends CXAI_OpenAI_Compatible {

	/**
	 * Pseudo-model that triggers live free-model resolution.
	 */
	const AUTO_FREE = 'auto-free';

	/**
	 * Live model list endpoint.
	 */
	const MODELS_ENDPOINT = 'https://openrouter.ai/api/v1/models';

	/**
	 * Transient key caching the resolved free model.
	 */
	const CACHE_KEY = 'cxai_openrouter_free_model';

	/**
	 * {@inheritDoc}
	 */
	public function chat( array $messages ) {
		if ( self::AUTO_FREE !== $this->model ) {
			return parent::chat( $messages );
		}

		$resolved = $this->resolve_free_model();

		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$this->model = $resolved;
		$result      = parent::chat( $messages );

		// The cached free model may have been retired since we cached it —
		// clear the cache, resolve fresh, and retry once.
		if ( is_wp_error( $result ) && false !== stripos( $result->get_error_message(), 'model' ) ) {
			delete_transient( self::CACHE_KEY );
			$resolved = $this->resolve_free_model();

			if ( ! is_wp_error( $resolved ) ) {
				$this->model = $resolved;
				$result      = parent::chat( $messages );
			}
		}

		$this->model = self::AUTO_FREE;

		return $result;
	}

	/**
	 * Find a model that is free right now.
	 *
	 * Preference order favours larger instruct models; the result is
	 * cached for six hours to avoid hitting the list endpoint per request.
	 *
	 * @return string|WP_Error Model slug on success.
	 */
	private function resolve_free_model() {
		$cached = get_transient( self::CACHE_KEY );

		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$response = wp_remote_get(
			self::MODELS_ENDPOINT,
			array(
				'timeout' => 30,
				'headers' => array( 'Authorization' => 'Bearer ' . $this->api_key ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'cxai_http_error',
				sprintf(
					/* translators: %s: transport error message. */
					__( 'Could not fetch OpenRouter model list: %s', 'cubix-ai-article-generator' ),
					$response->get_error_message()
				)
			);
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) || empty( $data['data'] ) || ! is_array( $data['data'] ) ) {
			return new WP_Error(
				'cxai_bad_response',
				__( 'OpenRouter returned an unexpected model list.', 'cubix-ai-article-generator' )
			);
		}

		$free = array();

		foreach ( $data['data'] as $model ) {
			if ( empty( $model['id'] ) ) {
				continue;
			}

			$id = (string) $model['id'];

			// Free models carry the :free suffix and zero pricing.
			$prompt_price = isset( $model['pricing']['prompt'] ) ? (float) $model['pricing']['prompt'] : 1;

			if ( ':free' === substr( $id, -5 ) && 0.0 === $prompt_price ) {
				$free[] = $id;
			}
		}

		if ( empty( $free ) ) {
			return new WP_Error(
				'cxai_no_free_models',
				__( 'OpenRouter has no free models available to this key right now. Add credits on openrouter.ai, or use Groq / Gemini instead.', 'cubix-ai-article-generator' )
			);
		}

		// Prefer well-known strong families, otherwise take the first.
		$chosen = $free[0];

		foreach ( array( 'llama-3.1-8b', 'mistral-7b', 'gemma', 'qwen', 'llama-3.3', 'deepseek' ) as $family ) {
			foreach ( $free as $id ) {
				if ( false !== stripos( $id, $family ) ) {
					$chosen = $id;
					break 2;
				}
			}
		}

		set_transient( self::CACHE_KEY, $chosen, 12 * HOUR_IN_SECONDS );

		return $chosen;
	}
}
