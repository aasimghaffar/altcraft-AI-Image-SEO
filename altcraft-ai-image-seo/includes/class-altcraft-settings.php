<?php
/**
 * Plugin settings: defaults, choices, access and sanitization.
 *
 * @package AltCraft_AI_Image_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central settings handler.
 */
class AltCraft_Settings {

	/**
	 * Cached settings for the current request.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'api_provider'             => 'gemini',
			'gemini_api_key'           => '',
			'openai_api_key'           => '',
			'gemini_model'             => 'gemini-3.5-flash-lite',
			'gemini_model_custom'      => '',
			'openai_model'             => 'gpt-5.6-terra',
			'openai_model_custom'      => '',
			'auto_on_upload'           => 'yes',
			'overwrite_existing'       => 'no',
			'sync_title'               => 'yes',
			'sync_caption'             => 'no',
			'generation_source'        => 'both',
			'output_language'          => 'English',
			'alt_style'                => 'concise_seo',
			'brand_keywords'           => '',
			'enable_woo_focus'         => 'yes',
			'enable_nightly_cron'      => 'yes',
			'cron_batch_size'          => 10,
			'enable_webp_convert'      => 'yes',
			'webp_quality'             => 82,
			'enable_webp_delivery'     => 'no',
			'request_timeout'          => 60,
			'delete_data_on_uninstall' => 'no',
		);
	}

	/**
	 * Returns the saved settings merged with defaults.
	 *
	 * @param bool $fresh Bypass the request cache.
	 * @return array
	 */
	public static function get( $fresh = false ) {
		if ( null !== self::$cache && ! $fresh ) {
			return self::$cache;
		}

		$saved = get_option( ALTCRAFT_AI_OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		// One-time migration from the pre-release single "api_key" field.
		if ( ! empty( $saved['api_key'] ) ) {
			$provider = ( isset( $saved['api_provider'] ) && 'openai' === $saved['api_provider'] ) ? 'openai' : 'gemini';
			if ( empty( $saved[ $provider . '_api_key' ] ) ) {
				$saved[ $provider . '_api_key' ] = $saved['api_key'];
			}
			unset( $saved['api_key'] );
			update_option( ALTCRAFT_AI_OPTION, $saved );
		}

		self::$cache = wp_parse_args( $saved, self::defaults() );

		return self::$cache;
	}

	/**
	 * Clears the request cache (used after saving).
	 *
	 * @return void
	 */
	public static function flush_cache() {
		self::$cache = null;
	}

	/**
	 * Convenience boolean accessor for yes/no settings.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public static function is_on( $key ) {
		$settings = self::get();
		return isset( $settings[ $key ] ) && 'yes' === $settings[ $key ];
	}

	/**
	 * Returns the API key for the active (or given) provider.
	 *
	 * @param string $provider Optional provider slug.
	 * @return string
	 */
	public static function get_api_key( $provider = '' ) {
		$settings = self::get();
		if ( '' === $provider ) {
			$provider = $settings['api_provider'];
		}
		$key = isset( $settings[ $provider . '_api_key' ] ) ? $settings[ $provider . '_api_key' ] : '';

		/**
		 * Filters the API key used for a provider. Allows keys to be defined in wp-config.php.
		 *
		 * @param string $key      API key.
		 * @param string $provider Provider slug (gemini|openai).
		 */
		return (string) apply_filters( 'altcraft_ai_api_key', $key, $provider );
	}

	/**
	 * Returns the effective model ID for the active (or given) provider.
	 *
	 * @param string $provider Optional provider slug.
	 * @return string
	 */
	public static function get_model( $provider = '' ) {
		$settings = self::get();
		if ( '' === $provider ) {
			$provider = $settings['api_provider'];
		}

		$model = isset( $settings[ $provider . '_model' ] ) ? $settings[ $provider . '_model' ] : '';
		if ( 'custom' === $model ) {
			$model = isset( $settings[ $provider . '_model_custom' ] ) ? $settings[ $provider . '_model_custom' ] : '';
		}
		if ( '' === $model ) {
			$defaults = self::defaults();
			$model    = $defaults[ $provider . '_model' ];
		}

		/**
		 * Filters the model ID sent to the provider.
		 *
		 * @param string $model    Model ID.
		 * @param string $provider Provider slug.
		 */
		return (string) apply_filters( 'altcraft_ai_model', $model, $provider );
	}

	/**
	 * Supported providers.
	 *
	 * @return array
	 */
	public static function providers() {
		return array(
			'gemini' => __( 'Google Gemini', 'altcraft-ai-image-seo' ),
			'openai' => __( 'OpenAI', 'altcraft-ai-image-seo' ),
		);
	}

	/**
	 * Model choices per provider. The list is filterable so it can be extended when new models ship.
	 *
	 * @param string $provider Provider slug.
	 * @return array Model ID => label.
	 */
	public static function model_choices( $provider ) {
		$choices = array(
			'gemini' => array(
				'gemini-3.5-flash-lite' => __( 'Gemini 3.5 Flash-Lite (fastest, lowest cost – recommended)', 'altcraft-ai-image-seo' ),
				'gemini-3.5-flash'      => __( 'Gemini 3.5 Flash', 'altcraft-ai-image-seo' ),
				'gemini-3.6-flash'      => __( 'Gemini 3.6 Flash', 'altcraft-ai-image-seo' ),
				'gemini-3.7-flash'      => __( 'Gemini 3.7 Flash (latest, most capable Flash)', 'altcraft-ai-image-seo' ),
				'gemini-3.1-flash-lite' => __( 'Gemini 3.1 Flash-Lite', 'altcraft-ai-image-seo' ),
				'gemini-2.5-flash-lite' => __( 'Gemini 2.5 Flash-Lite (legacy)', 'altcraft-ai-image-seo' ),
				'gemini-2.5-flash'      => __( 'Gemini 2.5 Flash (legacy)', 'altcraft-ai-image-seo' ),
				'gemini-flash-latest'   => __( 'gemini-flash-latest (always the newest Flash)', 'altcraft-ai-image-seo' ),
				'custom'                => __( 'Custom model ID…', 'altcraft-ai-image-seo' ),
			),
			'openai' => array(
				'gpt-5.6-terra' => __( 'GPT-5.6 Terra (balanced – recommended)', 'altcraft-ai-image-seo' ),
				'gpt-5.6-luna'  => __( 'GPT-5.6 Luna (efficient, high volume)', 'altcraft-ai-image-seo' ),
				'gpt-5.6-sol'   => __( 'GPT-5.6 Sol (flagship)', 'altcraft-ai-image-seo' ),
				'gpt-5-mini'    => __( 'GPT-5 mini (legacy)', 'altcraft-ai-image-seo' ),
				'gpt-4.1-mini'  => __( 'GPT-4.1 mini (legacy)', 'altcraft-ai-image-seo' ),
				'gpt-4o-mini'   => __( 'GPT-4o mini (legacy)', 'altcraft-ai-image-seo' ),
				'custom'        => __( 'Custom model ID…', 'altcraft-ai-image-seo' ),
			),
		);

		$list = isset( $choices[ $provider ] ) ? $choices[ $provider ] : array();

		/**
		 * Filters the selectable models for a provider.
		 *
		 * @param array  $list     Model ID => label.
		 * @param string $provider Provider slug.
		 */
		return (array) apply_filters( 'altcraft_ai_model_choices', $list, $provider );
	}

	/**
	 * Output languages.
	 *
	 * @return array Value => label.
	 */
	public static function languages() {
		return array(
			'English'    => 'English',
			'Spanish'    => 'Spanish (Español)',
			'French'     => 'French (Français)',
			'German'     => 'German (Deutsch)',
			'Italian'    => 'Italian (Italiano)',
			'Portuguese' => 'Portuguese (Português)',
			'Dutch'      => 'Dutch (Nederlands)',
			'Swedish'    => 'Swedish (Svenska)',
			'Danish'     => 'Danish (Dansk)',
			'Norwegian'  => 'Norwegian (Norsk)',
			'Finnish'    => 'Finnish (Suomi)',
			'Polish'     => 'Polish (Polski)',
			'Czech'      => 'Czech (Čeština)',
			'Romanian'   => 'Romanian (Română)',
			'Hungarian'  => 'Hungarian (Magyar)',
			'Greek'      => 'Greek (Ελληνικά)',
			'Turkish'    => 'Turkish (Türkçe)',
			'Russian'    => 'Russian (Русский)',
			'Ukrainian'  => 'Ukrainian (Українська)',
			'Arabic'     => 'Arabic (العربية)',
			'Hebrew'     => 'Hebrew (עברית)',
			'Hindi'      => 'Hindi (हिन्दी)',
			'Urdu'       => 'Urdu (اردو)',
			'Bengali'    => 'Bengali (বাংলা)',
			'Indonesian' => 'Indonesian (Bahasa Indonesia)',
			'Malay'      => 'Malay (Bahasa Melayu)',
			'Vietnamese' => 'Vietnamese (Tiếng Việt)',
			'Thai'       => 'Thai (ไทย)',
			'Japanese'   => 'Japanese (日本語)',
			'Korean'     => 'Korean (한국어)',
			'Chinese'    => 'Chinese, Simplified (简体中文)',
		);
	}

	/**
	 * What the AI is given to work with.
	 *
	 * @return array Value => label.
	 */
	public static function generation_sources() {
		return array(
			'both'     => __( 'Image and filename (recommended)', 'altcraft-ai-image-seo' ),
			'image'    => __( 'Image only – ignore the filename', 'altcraft-ai-image-seo' ),
			'filename' => __( 'Filename only – never send the image', 'altcraft-ai-image-seo' ),
		);
	}

	/**
	 * ALT text styles.
	 *
	 * @return array Value => label.
	 */
	public static function alt_styles() {
		return array(
			'concise_seo'  => __( 'Concise SEO (max 125 characters, natural keywords)', 'altcraft-ai-image-seo' ),
			'descriptive'  => __( 'Descriptive accessibility (max 150 characters, screen-reader first)', 'altcraft-ai-image-seo' ),
			'keyword_rich' => __( 'Keyword focused (product / brand terms up front)', 'altcraft-ai-image-seo' ),
		);
	}

	/**
	 * Sanitizes settings submitted through the Settings API.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$existing = self::get( true );

		if ( ! current_user_can( 'manage_options' ) ) {
			return $existing;
		}

		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$defaults = self::defaults();
		$output   = array();

		$provider               = isset( $input['api_provider'] ) ? sanitize_key( $input['api_provider'] ) : $defaults['api_provider'];
		$output['api_provider'] = array_key_exists( $provider, self::providers() ) ? $provider : $defaults['api_provider'];

		foreach ( array( 'gemini', 'openai' ) as $slug ) {
			// API keys: strip whitespace only – never run them through HTML filters.
			$key                               = isset( $input[ $slug . '_api_key' ] ) ? trim( (string) $input[ $slug . '_api_key' ] ) : '';
			$output[ $slug . '_api_key' ]      = preg_replace( '/[^A-Za-z0-9_\-\.]/', '', $key );
			$model                             = isset( $input[ $slug . '_model' ] ) ? sanitize_text_field( $input[ $slug . '_model' ] ) : $defaults[ $slug . '_model' ];
			$output[ $slug . '_model' ]        = array_key_exists( $model, self::model_choices( $slug ) ) ? $model : $defaults[ $slug . '_model' ];
			$custom                            = isset( $input[ $slug . '_model_custom' ] ) ? sanitize_text_field( $input[ $slug . '_model_custom' ] ) : '';
			$output[ $slug . '_model_custom' ] = preg_replace( '/[^A-Za-z0-9_\-\.:]/', '', $custom );

			if ( 'custom' === $output[ $slug . '_model' ] && '' === $output[ $slug . '_model_custom' ] ) {
				$output[ $slug . '_model' ] = $defaults[ $slug . '_model' ];
			}
		}

		$checkboxes = array(
			'auto_on_upload',
			'overwrite_existing',
			'sync_title',
			'sync_caption',
			'enable_woo_focus',
			'enable_nightly_cron',
			'enable_webp_convert',
			'enable_webp_delivery',
			'delete_data_on_uninstall',
		);
		foreach ( $checkboxes as $box ) {
			$output[ $box ] = ( isset( $input[ $box ] ) && 'yes' === $input[ $box ] ) ? 'yes' : 'no';
		}

		$language                  = isset( $input['output_language'] ) ? sanitize_text_field( $input['output_language'] ) : $defaults['output_language'];
		$output['output_language'] = array_key_exists( $language, self::languages() ) ? $language : $defaults['output_language'];

		$source                      = isset( $input['generation_source'] ) ? sanitize_key( $input['generation_source'] ) : $defaults['generation_source'];
		$output['generation_source'] = array_key_exists( $source, self::generation_sources() ) ? $source : $defaults['generation_source'];

		$style               = isset( $input['alt_style'] ) ? sanitize_key( $input['alt_style'] ) : $defaults['alt_style'];
		$output['alt_style'] = array_key_exists( $style, self::alt_styles() ) ? $style : $defaults['alt_style'];

		$output['brand_keywords'] = isset( $input['brand_keywords'] ) ? sanitize_text_field( $input['brand_keywords'] ) : '';
		$output['brand_keywords'] = mb_substr( $output['brand_keywords'], 0, 200 );

		$batch                     = isset( $input['cron_batch_size'] ) ? absint( $input['cron_batch_size'] ) : $defaults['cron_batch_size'];
		$output['cron_batch_size'] = max( 1, min( 50, $batch ) );

		$quality                = isset( $input['webp_quality'] ) ? absint( $input['webp_quality'] ) : $defaults['webp_quality'];
		$output['webp_quality'] = max( 40, min( 100, $quality ) );

		$timeout                   = isset( $input['request_timeout'] ) ? absint( $input['request_timeout'] ) : $defaults['request_timeout'];
		$output['request_timeout'] = max( 20, min( 180, $timeout ) );

		self::flush_cache();

		return $output;
	}
}
