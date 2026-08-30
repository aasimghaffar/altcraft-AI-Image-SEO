<?php
/**
 * Provider factory.
 *
 * @package Cubix_AI_Article_Generator
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CXAI_API_Factory
 */
class CXAI_API_Factory {

	/**
	 * Create a configured provider instance.
	 *
	 * @param string $provider       Provider slug.
	 * @param string $override_key   Optional raw key, used to test before saving.
	 * @param string $override_model Optional model ID, used to test the
	 *                               on-screen selection before saving. Must
	 *                               already be validated against the catalog.
	 * @return CXAI_API_Base|WP_Error
	 */
	public static function create( $provider, $override_key = '', $override_model = '' ) {
		$known = CXAI_Options::get_providers();

		if ( ! isset( $known[ $provider ] ) ) {
			return new WP_Error(
				'cxai_unknown_provider',
				__( 'Unknown AI engine.', 'cubix-ai-article-generator' )
			);
		}

		$definition = $known[ $provider ];
		$api_key    = ( '' !== $override_key ) ? $override_key : CXAI_Options::get_api_key( $provider );
		$model      = array_key_exists( $override_model, $definition['models'] )
			? $override_model
			: CXAI_Options::get_model( $provider );
		$args       = array(
			'temperature' => CXAI_Options::get_temperature(),
			'max_tokens'  => CXAI_Options::get_max_tokens(),
		);

		switch ( $provider ) {
			case 'openrouter':
				return new CXAI_OpenRouter( 'openrouter', $definition['endpoint'], $api_key, $model, $args );
			case 'claude':
				return new CXAI_Claude( $api_key, $model, $args );
			case 'gemini':
				return new CXAI_Gemini( $api_key, $model, $args );
		}

		// Everything else speaks the OpenAI Chat Completions dialect.
		if ( ! empty( $definition['endpoint'] ) ) {
			return new CXAI_OpenAI_Compatible( $provider, $definition['endpoint'], $api_key, $model, $args );
		}

		/**
		 * Allow add-ons to supply an instance for a custom provider slug.
		 *
		 * @param CXAI_API_Base|WP_Error $instance Provider instance or error.
		 * @param string                 $provider Provider slug.
		 * @param string                 $api_key  API key.
		 * @param string                 $model    Model identifier.
		 * @param array                  $args     Generation arguments.
		 */
		return apply_filters(
			'cxai_create_provider',
			new WP_Error( 'cxai_unknown_provider', __( 'Unknown AI engine.', 'cubix-ai-article-generator' ) ),
			$provider,
			$api_key,
			$model,
			$args
		);
	}
}
