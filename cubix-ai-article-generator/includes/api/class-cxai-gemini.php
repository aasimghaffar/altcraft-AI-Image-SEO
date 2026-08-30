<?php
/**
 * Google Gemini provider.
 *
 * @package Cubix_AI_Article_Generator
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CXAI_Gemini
 */
class CXAI_Gemini extends CXAI_API_Base {

	/**
	 * Base endpoint. The model name is appended per request.
	 */
	const ENDPOINT_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';

	/**
	 * {@inheritDoc}
	 */
	public function get_slug() {
		return 'gemini';
	}

	/**
	 * {@inheritDoc}
	 */
	public function chat( array $messages ) {
		$contents = array();

		foreach ( $this->normalize_messages( $messages ) as $message ) {
			$contents[] = array(
				'role'  => ( 'assistant' === $message['role'] ) ? 'model' : 'user',
				'parts' => array( array( 'text' => $message['content'] ) ),
			);
		}

		$body = array(
			'contents'         => $contents,
			'generationConfig' => array(
				'temperature'     => $this->temperature,
				// Newer Gemini models spend "thinking" tokens before any
				// visible text, so enforce a sensible floor.
				'maxOutputTokens' => max( 1024, $this->max_tokens ),
			),
		);

		if ( '' !== $this->system ) {
			$body['systemInstruction'] = array(
				'parts' => array( array( 'text' => $this->system ) ),
			);
		}

		$data = $this->request(
			self::ENDPOINT_BASE . rawurlencode( $this->model ) . ':generateContent',
			array( 'x-goog-api-key' => $this->api_key ), // Header, never a query string, so keys stay out of server logs.
			$body
		);

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$this->truncated = isset( $data['candidates'][0]['finishReason'] )
			&& 'MAX_TOKENS' === $data['candidates'][0]['finishReason'];

		// The reply text can sit in any part; collect them all.
		$text = '';

		if ( ! empty( $data['candidates'][0]['content']['parts'] ) && is_array( $data['candidates'][0]['content']['parts'] ) ) {
			foreach ( $data['candidates'][0]['content']['parts'] as $part ) {
				if ( isset( $part['text'] ) ) {
					$text .= $part['text'];
				}
			}
		}

		if ( '' === trim( $text ) ) {
			$reason = isset( $data['candidates'][0]['finishReason'] ) ? (string) $data['candidates'][0]['finishReason'] : '';

			if ( 'MAX_TOKENS' === $reason ) {
				return new WP_Error(
					'cxai_empty_response',
					__( 'Gemini ran out of output budget before writing anything. Raise "Maximum response length" on the Writing Defaults tab.', 'cubix-ai-article-generator' )
				);
			}

			if ( 'SAFETY' === $reason || 'PROHIBITED_CONTENT' === $reason ) {
				return new WP_Error(
					'cxai_empty_response',
					__( 'Gemini declined this prompt for safety reasons. Try rephrasing it.', 'cubix-ai-article-generator' )
				);
			}

			return new WP_Error(
				'cxai_empty_response',
				__( 'Gemini returned an empty response. Try a different Gemini model from the AI Engines tab.', 'cubix-ai-article-generator' )
			);
		}

		return $text;
	}
}
