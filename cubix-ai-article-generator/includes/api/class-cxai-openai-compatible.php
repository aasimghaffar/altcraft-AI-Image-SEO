<?php
/**
 * Generic provider for OpenAI-compatible chat APIs.
 *
 * One class powers OpenAI, Groq, OpenRouter, Mistral, and DeepSeek — they
 * all speak the same Chat Completions dialect and differ only by endpoint.
 *
 * @package Cubix_AI_Article_Generator
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CXAI_OpenAI_Compatible
 */
class CXAI_OpenAI_Compatible extends CXAI_API_Base {

	/**
	 * Provider slug.
	 *
	 * @var string
	 */
	protected $slug;

	/**
	 * Chat Completions endpoint URL.
	 *
	 * @var string
	 */
	protected $endpoint;

	/**
	 * Constructor.
	 *
	 * @param string $slug     Provider slug.
	 * @param string $endpoint Chat Completions endpoint URL.
	 * @param string $api_key  Decrypted API key.
	 * @param string $model    Model identifier.
	 * @param array  $args     Generation arguments.
	 */
	public function __construct( $slug, $endpoint, $api_key, $model, array $args = array() ) {
		parent::__construct( $api_key, $model, $args );

		$this->slug     = sanitize_key( $slug );
		$this->endpoint = $endpoint;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_slug() {
		return $this->slug;
	}

	/**
	 * {@inheritDoc}
	 */
	public function chat( array $messages ) {
		$payload = $this->normalize_messages( $messages );

		if ( '' !== $this->system ) {
			array_unshift(
				$payload,
				array(
					'role'    => 'system',
					'content' => $this->system,
				)
			);
		}

		$headers = array( 'Authorization' => 'Bearer ' . $this->api_key );

		// OpenRouter asks apps to identify themselves; harmless elsewhere.
		$extra = array();

		if ( 'openrouter' === $this->slug ) {
			$headers['HTTP-Referer'] = home_url( '/' );
			$headers['X-Title']      = 'Cubix AI Article Generator';

			// Route to the fastest provider serving the model — free
			// endpoints are often queued behind slower hosts.
			$extra['provider'] = array( 'sort' => 'throughput' );
		}

		$data = $this->request(
			$this->endpoint,
			$headers,
			array_merge(
				array(
					'model'       => $this->model,
					'temperature' => $this->temperature,
					'max_tokens'  => $this->max_tokens,
					'messages'    => $payload,
				),
				$extra
			)
		);

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		// 'length' means the model stopped because the budget ran out.
		$this->truncated = isset( $data['choices'][0]['finish_reason'] )
			&& 'length' === $data['choices'][0]['finish_reason'];

		if ( empty( $data['choices'][0]['message']['content'] ) ) {
			return new WP_Error(
				'cxai_empty_response',
				__( 'The engine returned an empty response. Try again, or pick another model.', 'cubix-ai-article-generator' )
			);
		}

		return (string) $data['choices'][0]['message']['content'];
	}
}
