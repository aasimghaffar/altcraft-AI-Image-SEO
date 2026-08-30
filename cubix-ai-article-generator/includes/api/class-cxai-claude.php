<?php
/**
 * Anthropic Claude provider.
 *
 * @package Cubix_AI_Article_Generator
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CXAI_Claude
 */
class CXAI_Claude extends CXAI_API_Base {

	/**
	 * Messages API endpoint.
	 */
	const ENDPOINT = 'https://api.anthropic.com/v1/messages';

	/**
	 * API version header value.
	 */
	const API_VERSION = '2023-06-01';

	/**
	 * {@inheritDoc}
	 */
	public function get_slug() {
		return 'claude';
	}

	/**
	 * {@inheritDoc}
	 */
	public function chat( array $messages ) {
		$body = array(
			'model'       => $this->model,
			'temperature' => $this->temperature,
			'max_tokens'  => $this->max_tokens,
			'messages'    => $this->normalize_messages( $messages ),
		);

		// Claude takes the system prompt as a top-level parameter.
		if ( '' !== $this->system ) {
			$body['system'] = $this->system;
		}

		$data = $this->request(
			self::ENDPOINT,
			array(
				'x-api-key'         => $this->api_key,
				'anthropic-version' => self::API_VERSION,
			),
			$body
		);

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$this->truncated = isset( $data['stop_reason'] ) && 'max_tokens' === $data['stop_reason'];

		if ( empty( $data['content'][0]['text'] ) ) {
			return new WP_Error(
				'cxai_empty_response',
				__( 'Claude returned an empty response.', 'cubix-ai-article-generator' )
			);
		}

		return (string) $data['content'][0]['text'];
	}
}
