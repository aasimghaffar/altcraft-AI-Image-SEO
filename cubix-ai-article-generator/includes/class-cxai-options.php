<?php
/**
 * Central, read-only accessor for plugin settings.
 *
 * Keeps the option shape in one place so every component reads settings
 * the same way. All writes go through the Settings API sanitize callback.
 *
 * @package Cubix_AI_Article_Generator
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CXAI_Options
 */
class CXAI_Options {

	/**
	 * Supported providers and their selectable models.
	 *
	 * 'endpoint' marks OpenAI-compatible Chat Completions APIs handled by
	 * the generic client. 'badge' is a short access hint shown in the UI,
	 * 'key_url' is where to obtain a key.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_providers() {
		$providers = array(
			'groq'       => array(
				'label'    => __( 'Groq', 'cubix-ai-article-generator' ),
				'tagline'  => __( 'Very fast Llama / Qwen / DeepSeek models with a generous everyday free tier — the most dependable free option.', 'cubix-ai-article-generator' ),
				'badge'    => __( 'Free tier', 'cubix-ai-article-generator' ),
				'key_url'  => 'https://console.groq.com/keys',
				'endpoint' => 'https://api.groq.com/openai/v1/chat/completions',
				'models'   => array(
					'llama-3.3-70b-versatile' => 'Llama 3.3 70B',
					'llama-3.1-8b-instant'    => 'Llama 3.1 8B (fastest)',
					'openai/gpt-oss-120b'     => 'GPT-OSS 120B',
					'qwen/qwen3-32b'          => 'Qwen3 32B',
				),
			),
			'openrouter' => array(
				'label'    => __( 'OpenRouter', 'cubix-ai-article-generator' ),
				'tagline'  => __( 'One key for hundreds of models. Free models rotate, so "Auto — free models" finds whatever is free right now, automatically.', 'cubix-ai-article-generator' ),
				'badge'    => __( 'Free models', 'cubix-ai-article-generator' ),
				'key_url'  => 'https://openrouter.ai/keys',
				'endpoint' => 'https://openrouter.ai/api/v1/chat/completions',
				'models'   => array(
					'auto-free'          => __( 'Auto — free models (recommended)', 'cubix-ai-article-generator' ),
					'openrouter/auto'    => __( 'Auto router (needs credits)', 'cubix-ai-article-generator' ),
					'openai/gpt-4o-mini' => 'GPT-4o mini (needs credits)',
				),
			),
			'mistral'    => array(
				'label'    => __( 'Mistral AI', 'cubix-ai-article-generator' ),
				'tagline'  => __( 'La Plateforme has a free experiment tier — sign up, create a key, and use it at no cost within its limits.', 'cubix-ai-article-generator' ),
				'badge'    => __( 'Free tier', 'cubix-ai-article-generator' ),
				'key_url'  => 'https://console.mistral.ai/api-keys',
				'endpoint' => 'https://api.mistral.ai/v1/chat/completions',
				'models'   => array(
					'mistral-small-latest'  => 'Mistral Small',
					'open-mistral-nemo'     => 'Mistral Nemo',
					'mistral-large-latest'  => 'Mistral Large (paid)',
				),
			),
			'openai'     => array(
				'label'    => __( 'OpenAI', 'cubix-ai-article-generator' ),
				'tagline'  => __( 'Your own OpenAI Platform API key (pay as you go).', 'cubix-ai-article-generator' ),
				'key_url'  => 'https://platform.openai.com/api-keys',
				'endpoint' => 'https://api.openai.com/v1/chat/completions',
				'models'   => array(
					'gpt-4o-mini'  => 'GPT-4o mini',
					'gpt-4o'       => 'GPT-4o',
					'gpt-4.1'      => 'GPT-4.1',
					'gpt-4.1-mini' => 'GPT-4.1 mini',
				),
			),
			'claude'     => array(
				'label'   => __( 'Anthropic Claude', 'cubix-ai-article-generator' ),
				'tagline' => __( 'Your own Anthropic Console API key (pay as you go).', 'cubix-ai-article-generator' ),
				'key_url' => 'https://console.anthropic.com/settings/keys',
				'models'  => array(
					'claude-haiku-4-5-20251001' => 'Claude Haiku 4.5 (fast)',
					'claude-sonnet-4-6'         => 'Claude Sonnet 4.6',
					'claude-opus-4-8'           => 'Claude Opus 4.8',
				),
			),
			'gemini'     => array(
				'label'   => __( 'Google Gemini', 'cubix-ai-article-generator' ),
				'tagline' => __( 'Google AI Studio keys include a free usage tier for Gemini models.', 'cubix-ai-article-generator' ),
				'badge'   => __( 'Free tier', 'cubix-ai-article-generator' ),
				'key_url' => 'https://aistudio.google.com/apikey',
				'models'  => array(
					'gemini-3.5-flash'      => 'Gemini 3.5 Flash (recommended)',
					'gemini-3.1-flash-lite' => 'Gemini 3.1 Flash-Lite (fastest)',
				),
			),
			'deepseek'   => array(
				'label'    => __( 'DeepSeek', 'cubix-ai-article-generator' ),
				'tagline'  => __( 'Very low-cost pay-as-you-go models with strong writing quality.', 'cubix-ai-article-generator' ),
				'key_url'  => 'https://platform.deepseek.com/api_keys',
				'endpoint' => 'https://api.deepseek.com/chat/completions',
				'models'   => array(
					'deepseek-chat'     => 'DeepSeek Chat (V3)',
					'deepseek-reasoner' => 'DeepSeek Reasoner (R1)',
				),
			),
		);

		/**
		 * Filter the supported AI providers and their model lists.
		 *
		 * @param array $providers Provider definitions.
		 */
		return apply_filters( 'cxai_providers', $providers );
	}

	/**
	 * Generation modes, grouped for the editor dropdown.
	 *
	 * @return array<string, array{label: string, group: string, needs_context: bool}>
	 */
	public static function get_modes() {
		$modes = array(
			'content'  => array(
				'label'         => __( 'Full content', 'cubix-ai-article-generator' ),
				'group'         => __( 'Write', 'cubix-ai-article-generator' ),
				'needs_context' => false,
			),
			'title'    => array(
				'label'         => __( 'Post title', 'cubix-ai-article-generator' ),
				'group'         => __( 'Write', 'cubix-ai-article-generator' ),
				'needs_context' => false,
			),
			'excerpt'  => array(
				'label'         => __( 'Excerpt / meta description', 'cubix-ai-article-generator' ),
				'group'         => __( 'Write', 'cubix-ai-article-generator' ),
				'needs_context' => false,
			),
			'outline'  => array(
				'label'         => __( 'Article outline', 'cubix-ai-article-generator' ),
				'group'         => __( 'Write', 'cubix-ai-article-generator' ),
				'needs_context' => false,
			),
			'faq'      => array(
				'label'         => __( 'FAQ section', 'cubix-ai-article-generator' ),
				'group'         => __( 'Write', 'cubix-ai-article-generator' ),
				'needs_context' => false,
			),
			'rewrite'  => array(
				'label'         => __( 'Rewrite / improve', 'cubix-ai-article-generator' ),
				'group'         => __( 'Refine', 'cubix-ai-article-generator' ),
				'needs_context' => true,
			),
			'expand'   => array(
				'label'         => __( 'Expand & enrich', 'cubix-ai-article-generator' ),
				'group'         => __( 'Refine', 'cubix-ai-article-generator' ),
				'needs_context' => true,
			),
			'summary'  => array(
				'label'         => __( 'Summarize', 'cubix-ai-article-generator' ),
				'group'         => __( 'Refine', 'cubix-ai-article-generator' ),
				'needs_context' => true,
			),
			'translate' => array(
				'label'         => __( 'Translate', 'cubix-ai-article-generator' ),
				'group'         => __( 'Refine', 'cubix-ai-article-generator' ),
				'needs_context' => true,
			),
			'keywords' => array(
				'label'         => __( 'SEO keywords', 'cubix-ai-article-generator' ),
				'group'         => __( 'Optimise', 'cubix-ai-article-generator' ),
				'needs_context' => false,
			),
			'tags'     => array(
				'label'         => __( 'Tag suggestions', 'cubix-ai-article-generator' ),
				'group'         => __( 'Optimise', 'cubix-ai-article-generator' ),
				'needs_context' => false,
			),
			'cta'      => array(
				'label'         => __( 'Call to action', 'cubix-ai-article-generator' ),
				'group'         => __( 'Optimise', 'cubix-ai-article-generator' ),
				'needs_context' => false,
			),
		);

		/**
		 * Filter the available generation modes.
		 *
		 * @param array $modes Mode definitions.
		 */
		return apply_filters( 'cxai_modes', $modes );
	}

	/**
	 * Writing tones.
	 *
	 * @return array<string, string>
	 */
	public static function get_tones() {
		return array(
			''             => __( 'Default tone', 'cubix-ai-article-generator' ),
			'professional' => __( 'Professional', 'cubix-ai-article-generator' ),
			'conversational' => __( 'Conversational', 'cubix-ai-article-generator' ),
			'friendly'     => __( 'Friendly', 'cubix-ai-article-generator' ),
			'authoritative' => __( 'Authoritative', 'cubix-ai-article-generator' ),
			'persuasive'   => __( 'Persuasive', 'cubix-ai-article-generator' ),
			'informative'  => __( 'Informative', 'cubix-ai-article-generator' ),
			'witty'        => __( 'Witty', 'cubix-ai-article-generator' ),
			'empathetic'   => __( 'Empathetic', 'cubix-ai-article-generator' ),
		);
	}

	/**
	 * Length presets and their approximate word targets.
	 *
	 * @return array<string, array{label: string, words: int}>
	 */
	public static function get_lengths() {
		return array(
			''       => array(
				'label' => __( 'Any length', 'cubix-ai-article-generator' ),
				'words' => 0,
			),
			'xs'     => array(
				'label' => __( 'Very short', 'cubix-ai-article-generator' ),
				'words' => 80,
			),
			'short'  => array(
				'label' => __( 'Short', 'cubix-ai-article-generator' ),
				'words' => 200,
			),
			'medium' => array(
				'label' => __( 'Medium', 'cubix-ai-article-generator' ),
				'words' => 450,
			),
			'long'   => array(
				'label' => __( 'Long', 'cubix-ai-article-generator' ),
				'words' => 900,
			),
			'xl'     => array(
				'label' => __( 'In-depth', 'cubix-ai-article-generator' ),
				'words' => 1500,
			),
		);
	}

	/**
	 * Capabilities that may be chosen as the minimum access level.
	 *
	 * @return array<string, string>
	 */
	public static function get_capabilities() {
		return array(
			'edit_posts'         => __( 'Contributors and above', 'cubix-ai-article-generator' ),
			'publish_posts'      => __( 'Authors and above', 'cubix-ai-article-generator' ),
			'edit_others_posts'  => __( 'Editors and above', 'cubix-ai-article-generator' ),
			'manage_options'     => __( 'Administrators only', 'cubix-ai-article-generator' ),
		);
	}

	/**
	 * Built-in starter prompt templates.
	 *
	 * @return array<int, array{label: string, prompt: string}>
	 */
	public static function get_default_templates() {
		return array(
			array(
				'label'  => __( 'Blog introduction', 'cubix-ai-article-generator' ),
				'prompt' => __( 'Write an engaging blog post introduction about: ', 'cubix-ai-article-generator' ),
			),
			array(
				'label'  => __( 'How-to guide', 'cubix-ai-article-generator' ),
				'prompt' => __( 'Write a clear step-by-step how-to guide about: ', 'cubix-ai-article-generator' ),
			),
			array(
				'label'  => __( 'Listicle', 'cubix-ai-article-generator' ),
				'prompt' => __( 'Write a numbered list article with a short paragraph per item about: ', 'cubix-ai-article-generator' ),
			),
			array(
				'label'  => __( 'Product description', 'cubix-ai-article-generator' ),
				'prompt' => __( 'Write a persuasive product description, including benefits and a closing line, for: ', 'cubix-ai-article-generator' ),
			),
			array(
				'label'  => __( 'Comparison', 'cubix-ai-article-generator' ),
				'prompt' => __( 'Write a balanced comparison covering pros, cons and a verdict for: ', 'cubix-ai-article-generator' ),
			),
			array(
				'label'  => __( 'Case study', 'cubix-ai-article-generator' ),
				'prompt' => __( 'Write a short case study with situation, approach and result about: ', 'cubix-ai-article-generator' ),
			),
		);
	}

	/**
	 * Get the full settings array with defaults applied.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$settings = get_option( CXAI_OPTION_KEY, array() );

		return wp_parse_args(
			is_array( $settings ) ? $settings : array(),
			array(
				'default_provider' => 'groq',
				'post_types'       => array( 'post', 'page' ),
				'providers'        => array(),
				'temperature'      => 0.7,
				'max_tokens'       => 4096,
				'default_tone'     => '',
				'default_length'   => '',
				'language'         => '',
				'min_capability'   => 'edit_posts',
				'templates'        => array(),
				'erase_on_delete'  => 1,
			)
		);
	}

	/**
	 * Get the decrypted API key for a provider.
	 *
	 * @param string $provider Provider slug.
	 * @return string
	 */
	public static function get_api_key( $provider ) {
		$settings = self::get_settings();
		$stored   = isset( $settings['providers'][ $provider ]['api_key'] ) ? $settings['providers'][ $provider ]['api_key'] : '';

		return CXAI_Encryption::decrypt( $stored );
	}

	/**
	 * Get the selected model for a provider, falling back to its first model.
	 *
	 * @param string $provider Provider slug.
	 * @return string
	 */
	public static function get_model( $provider ) {
		$settings  = self::get_settings();
		$providers = self::get_providers();
		$model     = isset( $settings['providers'][ $provider ]['model'] ) ? (string) $settings['providers'][ $provider ]['model'] : null;

		// '' is a valid choice when the provider offers an "Auto" model.
		if ( null === $model || ! array_key_exists( $model, isset( $providers[ $provider ]['models'] ) ? $providers[ $provider ]['models'] : array() ) ) {
			$models = isset( $providers[ $provider ]['models'] ) ? array_keys( $providers[ $provider ]['models'] ) : array();
			$model  = $models ? (string) $models[0] : '';
		}

		return $model;
	}

	/**
	 * Whether a provider is ready to use (free, or has a saved key).
	 *
	 * @param string $provider Provider slug.
	 * @return bool
	 */
	public static function is_provider_ready( $provider ) {
		$providers = self::get_providers();

		if ( ! isset( $providers[ $provider ] ) ) {
			return false;
		}

		return ! empty( $providers[ $provider ]['free'] ) || '' !== self::get_api_key( $provider );
	}

	/**
	 * Get the default provider slug.
	 *
	 * @return string
	 */
	public static function get_default_provider() {
		$settings  = self::get_settings();
		$providers = self::get_providers();

		return isset( $providers[ $settings['default_provider'] ] ) ? $settings['default_provider'] : 'groq';
	}

	/**
	 * Get the sampling temperature (clamped 0–1).
	 *
	 * @return float
	 */
	public static function get_temperature() {
		$settings = self::get_settings();

		return max( 0, min( 1, (float) $settings['temperature'] ) );
	}

	/**
	 * Get the max tokens setting (clamped 128–8192).
	 *
	 * @return int
	 */
	public static function get_max_tokens() {
		$settings = self::get_settings();

		return max( 512, min( 16384, (int) $settings['max_tokens'] ) );
	}

	/**
	 * Minimum capability required to use the generator.
	 *
	 * @return string
	 */
	public static function get_min_capability() {
		$settings = self::get_settings();
		$allowed  = self::get_capabilities();

		return isset( $allowed[ $settings['min_capability'] ] ) ? $settings['min_capability'] : 'edit_posts';
	}

	/**
	 * Whether the current user may use the generator.
	 *
	 * @return bool
	 */
	public static function current_user_can_use() {
		return current_user_can( self::get_min_capability() );
	}

	/**
	 * Prompt templates — custom ones if saved, otherwise the built-ins.
	 *
	 * @return array<int, array{label: string, prompt: string}>
	 */
	public static function get_templates() {
		$settings  = self::get_settings();
		$templates = is_array( $settings['templates'] ) ? $settings['templates'] : array();

		if ( empty( $templates ) ) {
			$templates = self::get_default_templates();
		}

		/**
		 * Filter the prompt templates shown in the editor.
		 *
		 * @param array $templates Template definitions.
		 */
		return apply_filters( 'cxai_prompt_templates', $templates );
	}

	/**
	 * Get the post types the meta box should appear on.
	 *
	 * @return string[]
	 */
	public static function get_enabled_post_types() {
		$settings = self::get_settings();
		$selected = is_array( $settings['post_types'] ) ? $settings['post_types'] : array();
		$public   = get_post_types( array( 'public' => true ), 'names' );

		return array_values( array_intersect( $selected, $public ) );
	}
}
