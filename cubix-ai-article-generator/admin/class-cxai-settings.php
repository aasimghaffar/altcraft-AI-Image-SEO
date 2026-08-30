<?php
/**
 * Settings screen.
 *
 * Every setting lives on one page, split into tabs that switch instantly
 * in the browser. All panels sit inside a single form, so one Save button
 * commits the lot.
 *
 * @package Cubix_AI_Article_Generator
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CXAI_Settings
 */
class CXAI_Settings {

	/**
	 * Register the option with its sanitize callback.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'cxai_settings_group',
			CXAI_OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
			)
		);
	}

	/**
	 * Sanitize, validate, and encrypt submitted settings.
	 *
	 * Empty API key fields keep the previously stored encrypted key, so
	 * nobody has to re-enter keys just to change an unrelated setting.
	 *
	 * @param mixed $input Raw submitted value.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$existing = CXAI_Options::get_settings();
		$known    = CXAI_Options::get_providers();
		$tones    = CXAI_Options::get_tones();
		$lengths  = CXAI_Options::get_lengths();
		$caps     = CXAI_Options::get_capabilities();

		$clean = array(
			'default_provider' => $existing['default_provider'],
			'post_types'       => array(),
			'providers'        => array(),
			'temperature'      => (float) $existing['temperature'],
			'max_tokens'       => (int) $existing['max_tokens'],
			'default_tone'     => '',
			'default_length'   => '',
			'language'         => '',
			'min_capability'   => 'edit_posts',
			'templates'        => array(),
			'erase_on_delete'  => 0,
		);

		// Default engine.
		if ( isset( $input['default_provider'] ) && isset( $known[ sanitize_key( $input['default_provider'] ) ] ) ) {
			$clean['default_provider'] = sanitize_key( $input['default_provider'] );
		}

		// Generation controls, clamped to safe ranges.
		if ( isset( $input['temperature'] ) ) {
			$clean['temperature'] = max( 0, min( 1, (float) $input['temperature'] ) );
		}

		if ( isset( $input['max_tokens'] ) ) {
			$clean['max_tokens'] = max( 512, min( 16384, absint( $input['max_tokens'] ) ) );
		}

		// Defaults for tone / length / language.
		if ( isset( $input['default_tone'] ) && isset( $tones[ sanitize_key( $input['default_tone'] ) ] ) ) {
			$clean['default_tone'] = sanitize_key( $input['default_tone'] );
		}

		if ( isset( $input['default_length'] ) && isset( $lengths[ sanitize_key( $input['default_length'] ) ] ) ) {
			$clean['default_length'] = sanitize_key( $input['default_length'] );
		}

		if ( isset( $input['language'] ) ) {
			$clean['language'] = sanitize_text_field( wp_unslash( $input['language'] ) );
		}

		// Minimum capability.
		if ( isset( $input['min_capability'] ) && isset( $caps[ sanitize_key( $input['min_capability'] ) ] ) ) {
			$clean['min_capability'] = sanitize_key( $input['min_capability'] );
		}

		$clean['erase_on_delete'] = empty( $input['erase_on_delete'] ) ? 0 : 1;

		// Post types, whitelisted against registered public types.
		$public = get_post_types( array( 'public' => true ), 'names' );

		if ( isset( $input['post_types'] ) && is_array( $input['post_types'] ) ) {
			foreach ( $input['post_types'] as $post_type ) {
				$post_type = sanitize_key( $post_type );

				if ( in_array( $post_type, $public, true ) ) {
					$clean['post_types'][] = $post_type;
				}
			}
		}

		// Prompt templates.
		if ( isset( $input['templates'] ) && is_array( $input['templates'] ) ) {
			foreach ( $input['templates'] as $template ) {
				$label  = isset( $template['label'] ) ? sanitize_text_field( wp_unslash( $template['label'] ) ) : '';
				$prompt = isset( $template['prompt'] ) ? sanitize_textarea_field( wp_unslash( $template['prompt'] ) ) : '';

				if ( '' === $label || '' === $prompt ) {
					continue;
				}

				$clean['templates'][] = array(
					'label'  => $label,
					'prompt' => $prompt,
				);
			}
		}

		// Per-engine key and model.
		foreach ( $known as $slug => $definition ) {
			$submitted_key = isset( $input['providers'][ $slug ]['api_key'] )
				? sanitize_text_field( wp_unslash( $input['providers'][ $slug ]['api_key'] ) )
				: '';

			// Strip anything that is not printable ASCII (stray spaces,
			// newlines and zero-width characters break auth headers).
			$submitted_key = trim( preg_replace( '/[^\x21-\x7E]/', '', $submitted_key ) );

			$remove = ! empty( $input['providers'][ $slug ]['remove'] );

			if ( $remove ) {
				$stored_key = '';
			} elseif ( '' !== $submitted_key ) {
				$stored_key = CXAI_Encryption::encrypt( $submitted_key );
			} else {
				$stored_key = isset( $existing['providers'][ $slug ]['api_key'] )
					? $existing['providers'][ $slug ]['api_key']
					: '';
			}

			$model = isset( $input['providers'][ $slug ]['model'] )
				? sanitize_text_field( $input['providers'][ $slug ]['model'] )
				: '';

			if ( ! array_key_exists( $model, $definition['models'] ) ) {
				$model = CXAI_Options::get_model( $slug );
			}

			$clean['providers'][ $slug ] = array(
				'api_key' => $stored_key,
				'model'   => $model,
			);
		}

		return $clean;
	}

	/**
	 * Render the settings screen.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings  = CXAI_Options::get_settings();
		$providers = CXAI_Options::get_providers();
		$stats     = CXAI_Logger::get_stats();
		?>
		<div class="wrap cx-app">
			<div class="cx-prism-rule" aria-hidden="true"></div>

			<header class="cx-masthead">
				<div class="cx-masthead-brand">
					<?php echo wp_kses( CXAI_Branding::logo( 52 ), CXAI_Branding::svg_kses() ); ?>
					<div>
						<p class="cx-eyebrow"><?php esc_html_e( 'Cubix', 'cubix-ai-article-generator' ); ?></p>
						<h1><?php esc_html_e( 'AI Article Generator', 'cubix-ai-article-generator' ); ?></h1>
					</div>
				</div>

				<div class="cx-masthead-meta">
					<span class="cx-pill cx-pill-version">v<?php echo esc_html( CXAI_VERSION ); ?></span>
					<a class="cx-pill cx-pill-link" href="<?php echo esc_url( admin_url( 'admin.php?page=cxai-studio' ) ); ?>">
						<?php esc_html_e( 'Open AI Studio', 'cubix-ai-article-generator' ); ?>
					</a>
				</div>
			</header>

			<?php settings_errors(); ?>

			<form method="post" action="options.php" class="cx-shell">
				<?php settings_fields( 'cxai_settings_group' ); ?>

				<nav class="cx-rail" role="tablist" aria-label="<?php esc_attr_e( 'Settings sections', 'cubix-ai-article-generator' ); ?>">
					<?php
					$tabs = array(
						'overview'  => array( 'dashicons-chart-area', __( 'Overview', 'cubix-ai-article-generator' ) ),
						'engines'   => array( 'dashicons-superhero-alt', __( 'AI Engines', 'cubix-ai-article-generator' ) ),
						'writing'   => array( 'dashicons-edit-large', __( 'Writing Defaults', 'cubix-ai-article-generator' ) ),
						'placement' => array( 'dashicons-align-wide', __( 'Placement & Access', 'cubix-ai-article-generator' ) ),
						'library'   => array( 'dashicons-book-alt', __( 'Prompt Library', 'cubix-ai-article-generator' ) ),
						'advanced'  => array( 'dashicons-admin-tools', __( 'Advanced', 'cubix-ai-article-generator' ) ),
						'about'     => array( 'dashicons-info-outline', __( 'About', 'cubix-ai-article-generator' ) ),
					);

					$index = 0;

					foreach ( $tabs as $id => $tab ) :
						$index++;
						?>
						<button
							type="button"
							class="cx-rail-tab<?php echo 1 === $index ? ' is-active' : ''; ?>"
							role="tab"
							id="cx-tab-<?php echo esc_attr( $id ); ?>"
							aria-controls="cx-panel-<?php echo esc_attr( $id ); ?>"
							aria-selected="<?php echo 1 === $index ? 'true' : 'false'; ?>"
							data-panel="<?php echo esc_attr( $id ); ?>"
						>
							<span class="dashicons <?php echo esc_attr( $tab[0] ); ?>" aria-hidden="true"></span>
							<span class="cx-rail-label"><?php echo esc_html( $tab[1] ); ?></span>
						</button>
					<?php endforeach; ?>

					<div class="cx-rail-foot">
						<?php submit_button( __( 'Save all settings', 'cubix-ai-article-generator' ), 'primary cx-btn cx-btn-save', 'submit', false ); ?>
					</div>
				</nav>

				<div class="cx-panels">

					<?php $this->panel_overview( $stats, $providers ); ?>
					<?php $this->panel_engines( $providers ); ?>
					<?php $this->panel_writing( $settings ); ?>
					<?php $this->panel_placement( $settings ); ?>
					<?php $this->panel_library(); ?>
					<?php $this->panel_advanced( $settings ); ?>
					<?php $this->panel_about(); ?>

				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * Overview panel — usage statistics and system status.
	 *
	 * @param array $stats     Usage statistics.
	 * @param array $providers Provider definitions.
	 * @return void
	 */
	private function panel_overview( $stats, $providers ) {
		$default = CXAI_Options::get_default_provider();
		$ready   = 0;

		foreach ( $providers as $slug => $definition ) {
			if ( CXAI_Options::is_provider_ready( $slug ) ) {
				$ready++;
			}
		}
		?>
		<section class="cx-panel is-active" id="cx-panel-overview" role="tabpanel" aria-labelledby="cx-tab-overview">
			<div class="cx-panel-head">
				<h2><?php esc_html_e( 'Overview', 'cubix-ai-article-generator' ); ?></h2>
				<p class="cx-lede"><?php esc_html_e( 'Everything the generator has produced on this site, and whether the plumbing is healthy.', 'cubix-ai-article-generator' ); ?></p>
			</div>

			<div class="cx-stats">
				<div class="cx-stat">
					<span class="cx-stat-label"><?php esc_html_e( 'Generations', 'cubix-ai-article-generator' ); ?></span>
					<span class="cx-stat-value"><?php echo esc_html( number_format_i18n( (int) $stats['total'] ) ); ?></span>
					<span class="cx-stat-foot"><?php esc_html_e( 'all time', 'cubix-ai-article-generator' ); ?></span>
				</div>
				<div class="cx-stat">
					<span class="cx-stat-label"><?php esc_html_e( 'Words written', 'cubix-ai-article-generator' ); ?></span>
					<span class="cx-stat-value"><?php echo esc_html( number_format_i18n( (int) $stats['words'] ) ); ?></span>
					<span class="cx-stat-foot"><?php esc_html_e( 'all time', 'cubix-ai-article-generator' ); ?></span>
				</div>
				<div class="cx-stat">
					<span class="cx-stat-label"><?php esc_html_e( 'This month', 'cubix-ai-article-generator' ); ?></span>
					<span class="cx-stat-value"><?php echo esc_html( number_format_i18n( CXAI_Logger::get_month_total() ) ); ?></span>
					<span class="cx-stat-foot"><?php echo esc_html( gmdate( 'F Y' ) ); ?></span>
				</div>
				<div class="cx-stat cx-stat-accent">
					<span class="cx-stat-label"><?php esc_html_e( 'Engines ready', 'cubix-ai-article-generator' ); ?></span>
					<span class="cx-stat-value"><?php echo esc_html( $ready . '/' . count( $providers ) ); ?></span>
					<span class="cx-stat-foot"><?php echo esc_html( isset( $providers[ $default ]['label'] ) ? $providers[ $default ]['label'] : '' ); ?></span>
				</div>
			</div>

			<div class="cx-grid-2">
				<div class="cx-card">
					<h3 class="cx-card-title"><?php esc_html_e( 'Usage by engine', 'cubix-ai-article-generator' ); ?></h3>
					<?php if ( empty( $stats['by_provider'] ) ) : ?>
						<p class="cx-empty"><?php esc_html_e( 'Nothing generated yet. Open a post and try the Cubix panel.', 'cubix-ai-article-generator' ); ?></p>
					<?php else : ?>
						<ul class="cx-bars">
							<?php
							$max = max( array_map( 'intval', $stats['by_provider'] ) );

							foreach ( $stats['by_provider'] as $slug => $count ) :
								$label   = isset( $providers[ $slug ]['label'] ) ? $providers[ $slug ]['label'] : $slug;
								$percent = $max > 0 ? round( ( (int) $count / $max ) * 100 ) : 0;
								?>
								<li>
									<span class="cx-bar-label"><?php echo esc_html( $label ); ?></span>
									<span class="cx-bar-track">
										<span class="cx-bar-fill" style="width:<?php echo esc_attr( $percent ); ?>%"></span>
									</span>
									<span class="cx-bar-value"><?php echo esc_html( number_format_i18n( (int) $count ) ); ?></span>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>

				<div class="cx-card">
					<h3 class="cx-card-title"><?php esc_html_e( 'System status', 'cubix-ai-article-generator' ); ?></h3>
					<ul class="cx-status">
						<?php
						$checks = array(
							array(
								'label' => __( 'PHP version', 'cubix-ai-article-generator' ),
								'value' => PHP_VERSION,
								'ok'    => version_compare( PHP_VERSION, '7.4', '>=' ),
							),
							array(
								'label' => __( 'WordPress version', 'cubix-ai-article-generator' ),
								'value' => get_bloginfo( 'version' ),
								'ok'    => version_compare( get_bloginfo( 'version' ), '5.8', '>=' ),
							),
							array(
								'label' => __( 'Key encryption (OpenSSL)', 'cubix-ai-article-generator' ),
								'value' => CXAI_Encryption::is_available() ? __( 'Enabled', 'cubix-ai-article-generator' ) : __( 'Unavailable', 'cubix-ai-article-generator' ),
								'ok'    => CXAI_Encryption::is_available(),
							),
							array(
								'label' => __( 'Outbound HTTP requests', 'cubix-ai-article-generator' ),
								'value' => ( defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && WP_HTTP_BLOCK_EXTERNAL ) ? __( 'Blocked by wp-config', 'cubix-ai-article-generator' ) : __( 'Allowed', 'cubix-ai-article-generator' ),
								'ok'    => ! ( defined( 'WP_HTTP_BLOCK_EXTERNAL' ) && WP_HTTP_BLOCK_EXTERNAL ),
							),
						);

						foreach ( $checks as $check ) :
							?>
							<li class="<?php echo $check['ok'] ? 'is-ok' : 'is-warn'; ?>">
								<span class="cx-status-dot" aria-hidden="true"></span>
								<span class="cx-status-label"><?php echo esc_html( $check['label'] ); ?></span>
								<span class="cx-status-value"><?php echo esc_html( $check['value'] ); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			</div>
		</section>
		<?php
	}

	/**
	 * Engines panel.
	 *
	 * @param array $providers Provider definitions.
	 * @return void
	 */
	private function panel_engines( $providers ) {
		$default = CXAI_Options::get_default_provider();
		?>
		<section class="cx-panel" id="cx-panel-engines" role="tabpanel" aria-labelledby="cx-tab-engines" hidden>
			<div class="cx-panel-head">
				<h2><?php esc_html_e( 'AI Engines', 'cubix-ai-article-generator' ); ?></h2>
				<p class="cx-lede"><?php esc_html_e( 'The free engine works with nothing to set up. Engines marked "Free tier" or "Free models" only need a free key from the linked site — the most dependable no-cost options. Keys are encrypted before saving; leave a field blank to keep the saved key.', 'cubix-ai-article-generator' ); ?></p>
			</div>

			<?php if ( ! CXAI_Encryption::is_available() ) : ?>
				<div class="cx-notice cx-notice-warn">
					<?php esc_html_e( 'OpenSSL is not available on this server, so API keys will be stored unencrypted. Ask your host to enable the OpenSSL PHP extension.', 'cubix-ai-article-generator' ); ?>
				</div>
			<?php endif; ?>

			<div class="cx-card cx-default-picker">
				<div class="cx-field cx-field-narrow">
					<label class="cx-field-label" for="cx-default-provider"><?php esc_html_e( 'Default engine', 'cubix-ai-article-generator' ); ?></label>
					<select class="cx-input" id="cx-default-provider" name="<?php echo esc_attr( CXAI_OPTION_KEY ); ?>[default_provider]">
						<?php foreach ( $providers as $slug => $definition ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $default, $slug ); ?>>
								<?php echo esc_html( $definition['label'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<span class="cx-help"><?php esc_html_e( 'Pre-selected in the editor panel and AI Studio. Writers can switch engines per request.', 'cubix-ai-article-generator' ); ?></span>
				</div>
			</div>

			<div class="cx-engines">
				<?php foreach ( $providers as $slug => $definition ) : ?>
					<?php
					$is_free = ! empty( $definition['free'] );
					$key     = CXAI_Options::get_api_key( $slug );
					$ready   = $is_free || '' !== $key;
					$model   = CXAI_Options::get_model( $slug );
					?>
					<div class="cx-engine">
						<div class="cx-engine-head">
							<span class="cx-engine-name">
								<?php echo esc_html( $definition['label'] ); ?>
								<?php if ( ! empty( $definition['badge'] ) ) : ?>
									<span class="cx-tag cx-tag-free"><?php echo esc_html( $definition['badge'] ); ?></span>
								<?php endif; ?>
								<?php if ( ! $is_free && $ready ) : ?>
									<span class="cx-tag cx-tag-ok"><?php esc_html_e( 'Connected', 'cubix-ai-article-generator' ); ?></span>
								<?php endif; ?>
							</span>

							<?php if ( ! empty( $definition['key_url'] ) ) : ?>
								<a class="cx-engine-keylink" href="<?php echo esc_url( $definition['key_url'] ); ?>" target="_blank" rel="noopener noreferrer">
									<?php echo $is_free ? esc_html__( 'Get an optional token', 'cubix-ai-article-generator' ) : esc_html__( 'Get a key', 'cubix-ai-article-generator' ); ?>
									<span class="dashicons dashicons-external" aria-hidden="true"></span>
								</a>
							<?php endif; ?>
						</div>

						<p class="cx-engine-tagline"><?php echo esc_html( $definition['tagline'] ); ?></p>

						<div class="cx-engine-body">
							<div class="cx-field">
								<label class="cx-field-label" for="cx-key-<?php echo esc_attr( $slug ); ?>">
									<?php echo $is_free ? esc_html__( 'Token (optional)', 'cubix-ai-article-generator' ) : esc_html__( 'API key', 'cubix-ai-article-generator' ); ?>
								</label>
								<span class="cx-key-wrap">
									<input
										type="password"
										id="cx-key-<?php echo esc_attr( $slug ); ?>"
										class="cx-input cx-api-key"
										name="<?php echo esc_attr( CXAI_OPTION_KEY ); ?>[providers][<?php echo esc_attr( $slug ); ?>][api_key]"
										value=""
										autocomplete="new-password"
										spellcheck="false"
										placeholder="<?php echo ( '' !== $key ) ? esc_attr( CXAI_Encryption::mask( $key ) ) : ( $is_free ? esc_attr__( 'Not required', 'cubix-ai-article-generator' ) : esc_attr__( 'Paste your API key', 'cubix-ai-article-generator' ) ); ?>"
									/>
									<button type="button" class="cx-key-toggle" aria-label="<?php esc_attr_e( 'Show or hide key', 'cubix-ai-article-generator' ); ?>">
										<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
									</button>
								</span>
							</div>

							<div class="cx-field">
								<label class="cx-field-label" for="cx-model-<?php echo esc_attr( $slug ); ?>"><?php esc_html_e( 'Model', 'cubix-ai-article-generator' ); ?></label>
								<select id="cx-model-<?php echo esc_attr( $slug ); ?>" class="cx-input" name="<?php echo esc_attr( CXAI_OPTION_KEY ); ?>[providers][<?php echo esc_attr( $slug ); ?>][model]">
									<?php foreach ( $definition['models'] as $model_id => $model_label ) : ?>
										<option value="<?php echo esc_attr( $model_id ); ?>" <?php selected( $model, $model_id ); ?>>
											<?php echo esc_html( $model_label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>

							<div class="cx-field cx-field-action">
								<button type="button" class="cx-btn cx-btn-ghost cx-test-key" data-provider="<?php echo esc_attr( $slug ); ?>" <?php disabled( ! $ready ); ?>>
									<?php esc_html_e( 'Test connection', 'cubix-ai-article-generator' ); ?>
								</button>
							</div>
						</div>

						<span class="cx-engine-result" role="status" aria-live="polite"></span>

						<?php if ( '' !== $key ) : ?>
							<label class="cx-engine-remove">
								<input
									type="checkbox"
									name="<?php echo esc_attr( CXAI_OPTION_KEY ); ?>[providers][<?php echo esc_attr( $slug ); ?>][remove]"
									value="1"
								/>
								<span>
									<?php
									echo $is_free
										? esc_html__( 'Delete the saved token when I save', 'cubix-ai-article-generator' )
										: esc_html__( 'Delete the saved key when I save', 'cubix-ai-article-generator' );
									?>
								</span>
							</label>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
		</section>
		<?php
	}

	/**
	 * Writing defaults panel.
	 *
	 * @param array $settings Current settings.
	 * @return void
	 */
	private function panel_writing( $settings ) {
		?>
		<section class="cx-panel" id="cx-panel-writing" role="tabpanel" aria-labelledby="cx-tab-writing" hidden>
			<div class="cx-panel-head">
				<h2><?php esc_html_e( 'Writing Defaults', 'cubix-ai-article-generator' ); ?></h2>
			</div>

			<div class="cx-notice cx-notice-info">
				<?php esc_html_e( 'The starting point for every generation — authors can still override tone, length and engine per request. Tone, length and output language apply to the Cubix AI panel in the post editor only; AI Studio chat is free-form. Creativity and Maximum response length apply everywhere, including AI Studio.', 'cubix-ai-article-generator' ); ?>
			</div>

			<div class="cx-grid-2">
				<div class="cx-card">
					<h3 class="cx-card-title"><?php esc_html_e( 'Voice', 'cubix-ai-article-generator' ); ?></h3>

					<div class="cx-field">
						<label class="cx-field-label" for="cx-default-tone"><?php esc_html_e( 'Default tone', 'cubix-ai-article-generator' ); ?></label>
						<select class="cx-input" id="cx-default-tone" name="<?php echo esc_attr( CXAI_OPTION_KEY ); ?>[default_tone]">
							<?php foreach ( CXAI_Options::get_tones() as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['default_tone'], $value ); ?>>
									<?php echo esc_html( $label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="cx-field">
						<label class="cx-field-label" for="cx-default-length"><?php esc_html_e( 'Default length', 'cubix-ai-article-generator' ); ?></label>
						<select class="cx-input" id="cx-default-length" name="<?php echo esc_attr( CXAI_OPTION_KEY ); ?>[default_length]">
							<?php foreach ( CXAI_Options::get_lengths() as $value => $length ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['default_length'], $value ); ?>>
									<?php echo esc_html( $length['label'] ); ?>
									<?php echo $length['words'] ? esc_html( ' — ~' . $length['words'] . ' ' . __( 'words', 'cubix-ai-article-generator' ) ) : ''; ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="cx-field">
						<label class="cx-field-label" for="cx-language"><?php esc_html_e( 'Output language', 'cubix-ai-article-generator' ); ?></label>
						<input
							type="text"
							class="cx-input"
							id="cx-language"
							name="<?php echo esc_attr( CXAI_OPTION_KEY ); ?>[language]"
							value="<?php echo esc_attr( $settings['language'] ); ?>"
							placeholder="<?php esc_attr_e( 'Leave blank to match your prompt', 'cubix-ai-article-generator' ); ?>"
						/>
						<span class="cx-help"><?php esc_html_e( 'Any language name works — English, Urdu, Spanish, Bahasa Indonesia.', 'cubix-ai-article-generator' ); ?></span>
					</div>
				</div>

				<div class="cx-card">
					<h3 class="cx-card-title"><?php esc_html_e( 'Model behaviour', 'cubix-ai-article-generator' ); ?></h3>

					<div class="cx-field">
						<label class="cx-field-label" for="cx-temperature">
							<?php esc_html_e( 'Creativity', 'cubix-ai-article-generator' ); ?>
							<output class="cx-range-out" id="cx-temperature-out"><?php echo esc_html( CXAI_Options::get_temperature() ); ?></output>
						</label>
						<input
							type="range"
							class="cx-range"
							id="cx-temperature"
							name="<?php echo esc_attr( CXAI_OPTION_KEY ); ?>[temperature]"
							min="0" max="1" step="0.1"
							value="<?php echo esc_attr( CXAI_Options::get_temperature() ); ?>"
						/>
						<span class="cx-range-scale">
							<span><?php esc_html_e( 'Precise', 'cubix-ai-article-generator' ); ?></span>
							<span><?php esc_html_e( 'Inventive', 'cubix-ai-article-generator' ); ?></span>
						</span>
					</div>

					<div class="cx-field">
						<label class="cx-field-label" for="cx-max-tokens"><?php esc_html_e( 'Maximum response length', 'cubix-ai-article-generator' ); ?></label>
						<input
							type="number"
							class="cx-input"
							id="cx-max-tokens"
							name="<?php echo esc_attr( CXAI_OPTION_KEY ); ?>[max_tokens]"
							min="128" max="8192" step="128"
							value="<?php echo esc_attr( CXAI_Options::get_max_tokens() ); ?>"
						/>
						<span class="cx-help"><?php esc_html_e( 'Measured in tokens — roughly three quarters of a word each. Long articles need a high value: at 2048 an answer stops at about 1,500 words. Applies to the editor panel and AI Studio. Higher values cost more on paid engines.', 'cubix-ai-article-generator' ); ?></span>
					</div>
				</div>
			</div>
		</section>
		<?php
	}

	/**
	 * Placement and access panel.
	 *
	 * @param array $settings Current settings.
	 * @return void
	 */
	private function panel_placement( $settings ) {
		$selected = is_array( $settings['post_types'] ) ? $settings['post_types'] : array();
		?>
		<section class="cx-panel" id="cx-panel-placement" role="tabpanel" aria-labelledby="cx-tab-placement" hidden>
			<div class="cx-panel-head">
				<h2><?php esc_html_e( 'Placement & Access', 'cubix-ai-article-generator' ); ?></h2>
				<p class="cx-lede"><?php esc_html_e( 'Where the generator appears, and who is allowed to run it.', 'cubix-ai-article-generator' ); ?></p>
			</div>

			<div class="cx-card">
				<h3 class="cx-card-title"><?php esc_html_e( 'Show the editor panel on', 'cubix-ai-article-generator' ); ?></h3>

				<div class="cx-toggles">
					<?php
					$public_types = get_post_types( array( 'public' => true ), 'objects' );

					foreach ( $public_types as $post_type ) :
						if ( 'attachment' === $post_type->name ) {
							continue;
						}
						?>
						<label class="cx-toggle">
							<input
								type="checkbox"
								name="<?php echo esc_attr( CXAI_OPTION_KEY ); ?>[post_types][]"
								value="<?php echo esc_attr( $post_type->name ); ?>"
								<?php checked( in_array( $post_type->name, $selected, true ) ); ?>
							/>
							<span class="cx-toggle-track" aria-hidden="true"><span class="cx-toggle-knob"></span></span>
							<span class="cx-toggle-text">
								<span class="cx-toggle-name"><?php echo esc_html( $post_type->labels->name ); ?></span>
								<code><?php echo esc_html( $post_type->name ); ?></code>
							</span>
						</label>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="cx-card">
				<h3 class="cx-card-title"><?php esc_html_e( 'Minimum access level', 'cubix-ai-article-generator' ); ?></h3>

				<div class="cx-field cx-field-narrow">
					<label class="cx-field-label" for="cx-min-capability"><?php esc_html_e( 'Who can generate content', 'cubix-ai-article-generator' ); ?></label>
					<select class="cx-input" id="cx-min-capability" name="<?php echo esc_attr( CXAI_OPTION_KEY ); ?>[min_capability]">
						<?php foreach ( CXAI_Options::get_capabilities() as $capability => $label ) : ?>
							<option value="<?php echo esc_attr( $capability ); ?>" <?php selected( $settings['min_capability'], $capability ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<span class="cx-help"><?php esc_html_e( 'Applies to the editor panel, AI Studio, and every AI request. Users still need permission to edit the specific post.', 'cubix-ai-article-generator' ); ?></span>
				</div>
			</div>
		</section>
		<?php
	}

	/**
	 * Prompt library panel.
	 *
	 * @return void
	 */
	private function panel_library() {
		$settings  = CXAI_Options::get_settings();
		$templates = is_array( $settings['templates'] ) ? $settings['templates'] : array();
		$using_defaults = empty( $templates );

		if ( $using_defaults ) {
			$templates = CXAI_Options::get_default_templates();
		}
		?>
		<section class="cx-panel" id="cx-panel-library" role="tabpanel" aria-labelledby="cx-tab-library" hidden>
			<div class="cx-panel-head">
				<h2><?php esc_html_e( 'Prompt Library', 'cubix-ai-article-generator' ); ?></h2>
				<p class="cx-lede"><?php esc_html_e( 'One-click prompt starters shown above the prompt box in the editor. Write them so an author only has to type the topic on the end.', 'cubix-ai-article-generator' ); ?></p>
			</div>

			<div class="cx-card">
				<div class="cx-library" id="cx-library">
					<?php foreach ( $templates as $index => $template ) : ?>
						<div class="cx-library-row">
							<span class="cx-library-handle" aria-hidden="true"><?php echo esc_html( str_pad( (string) ( $index + 1 ), 2, '0', STR_PAD_LEFT ) ); ?></span>
							<input
								type="text"
								class="cx-input cx-library-label"
								name="<?php echo esc_attr( CXAI_OPTION_KEY ); ?>[templates][<?php echo esc_attr( $index ); ?>][label]"
								value="<?php echo esc_attr( $template['label'] ); ?>"
								placeholder="<?php esc_attr_e( 'Button label', 'cubix-ai-article-generator' ); ?>"
							/>
							<input
								type="text"
								class="cx-input cx-library-prompt"
								name="<?php echo esc_attr( CXAI_OPTION_KEY ); ?>[templates][<?php echo esc_attr( $index ); ?>][prompt]"
								value="<?php echo esc_attr( $template['prompt'] ); ?>"
								placeholder="<?php esc_attr_e( 'Prompt text the button inserts', 'cubix-ai-article-generator' ); ?>"
							/>
							<button type="button" class="cx-library-remove" aria-label="<?php esc_attr_e( 'Remove template', 'cubix-ai-article-generator' ); ?>">
								<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
							</button>
						</div>
					<?php endforeach; ?>
				</div>

				<div class="cx-library-actions">
					<button type="button" class="cx-btn cx-btn-ghost" id="cx-library-add">
						<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
						<?php esc_html_e( 'Add template', 'cubix-ai-article-generator' ); ?>
					</button>
					<?php if ( $using_defaults ) : ?>
						<span class="cx-help"><?php esc_html_e( 'These are the built-in starters. Edit or remove any of them and save to make the list your own.', 'cubix-ai-article-generator' ); ?></span>
					<?php endif; ?>
				</div>
			</div>
		</section>
		<?php
	}

	/**
	 * Advanced panel.
	 *
	 * @param array $settings Current settings.
	 * @return void
	 */
	private function panel_advanced( $settings ) {
		?>
		<section class="cx-panel" id="cx-panel-advanced" role="tabpanel" aria-labelledby="cx-tab-advanced" hidden>
			<div class="cx-panel-head">
				<h2><?php esc_html_e( 'Advanced', 'cubix-ai-article-generator' ); ?></h2>
				<p class="cx-lede"><?php esc_html_e( 'Housekeeping, portability, and what happens when the plugin is removed.', 'cubix-ai-article-generator' ); ?></p>
			</div>

			<div class="cx-grid-2">
				<div class="cx-card">
					<h3 class="cx-card-title"><?php esc_html_e( 'Move settings between sites', 'cubix-ai-article-generator' ); ?></h3>
					<p class="cx-help"><?php esc_html_e( 'Export copies every saved setting except your API keys — those never leave this site. Paste an export into the import box on another site to apply it there (its saved keys are kept untouched).', 'cubix-ai-article-generator' ); ?></p>

					<div class="cx-inline-actions">
						<button type="button" class="cx-btn cx-btn-ghost" id="cx-export">
							<span class="dashicons dashicons-download" aria-hidden="true"></span>
							<?php esc_html_e( 'Export settings JSON', 'cubix-ai-article-generator' ); ?>
						</button>
					</div>

					<textarea class="cx-input cx-code" id="cx-export-output" rows="5" readonly aria-label="<?php esc_attr_e( 'Exported settings', 'cubix-ai-article-generator' ); ?>"></textarea>

					<hr class="cx-divider" />

					<textarea class="cx-input cx-code" id="cx-import-input" rows="5" placeholder="<?php esc_attr_e( 'Paste settings JSON from another site here…', 'cubix-ai-article-generator' ); ?>" aria-label="<?php esc_attr_e( 'Settings JSON to import', 'cubix-ai-article-generator' ); ?>"></textarea>

					<div class="cx-inline-actions">
						<button type="button" class="cx-btn cx-btn-ghost" id="cx-import">
							<span class="dashicons dashicons-upload" aria-hidden="true"></span>
							<?php esc_html_e( 'Import settings JSON', 'cubix-ai-article-generator' ); ?>
						</button>
						<span class="cx-engine-result" id="cx-import-result" role="status" aria-live="polite"></span>
					</div>
				</div>

				<div class="cx-card">
					<h3 class="cx-card-title"><?php esc_html_e( 'Maintenance', 'cubix-ai-article-generator' ); ?></h3>

					<div class="cx-inline-actions">
						<button type="button" class="cx-btn cx-btn-ghost cx-btn-danger" id="cx-reset-stats">
							<span class="dashicons dashicons-chart-bar" aria-hidden="true"></span>
							<?php esc_html_e( 'Reset usage statistics', 'cubix-ai-article-generator' ); ?>
						</button>
						<span class="cx-inline-result" id="cx-reset-stats-result" role="status" aria-live="polite"></span>
					</div>

					<label class="cx-check">
						<input
							type="checkbox"
							name="<?php echo esc_attr( CXAI_OPTION_KEY ); ?>[erase_on_delete]"
							value="1"
							<?php checked( ! empty( $settings['erase_on_delete'] ) ); ?>
						/>
						<span><?php esc_html_e( 'Delete all plugin data when the plugin is deleted', 'cubix-ai-article-generator' ); ?></span>
					</label>
					<span class="cx-help"><?php esc_html_e( 'Covers settings, usage statistics, saved chats, and generation history. Turn this off if you plan to reinstall.', 'cubix-ai-article-generator' ); ?></span>
				</div>
			</div>
		</section>
		<?php
	}

	/**
	 * About panel.
	 *
	 * @return void
	 */
	private function panel_about() {
		?>
		<section class="cx-panel" id="cx-panel-about" role="tabpanel" aria-labelledby="cx-tab-about" hidden>
			<div class="cx-panel-head">
				<h2><?php esc_html_e( 'About', 'cubix-ai-article-generator' ); ?></h2>
			</div>

			<div class="cx-about">
				<div class="cx-about-mark">
					<?php echo wp_kses( CXAI_Branding::logo( 96 ), CXAI_Branding::svg_kses() ); ?>
					<p class="cx-eyebrow"><?php esc_html_e( 'Cubix AI Article Generator', 'cubix-ai-article-generator' ); ?></p>
					<p class="cx-about-version">v<?php echo esc_html( CXAI_VERSION ); ?></p>
				</div>

				<div class="cx-card">
					<h3 class="cx-card-title"><?php esc_html_e( 'Data and privacy', 'cubix-ai-article-generator' ); ?></h3>
					<p><?php esc_html_e( 'Nothing is sent anywhere until a logged-in editor presses Generate or sends a chat message. At that point your prompt — and, if you tick the context box, the post content — goes to the engine you selected. No visitor data is ever transmitted, and the plugin collects no analytics of its own.', 'cubix-ai-article-generator' ); ?></p>

					<h3 class="cx-card-title"><?php esc_html_e( 'Hooks for developers', 'cubix-ai-article-generator' ); ?></h3>
					<ul class="cx-hooks">
						<li><code>cxai_providers</code> — <?php esc_html_e( 'add engines or models', 'cubix-ai-article-generator' ); ?></li>
						<li><code>cxai_modes</code> — <?php esc_html_e( 'add generation modes', 'cubix-ai-article-generator' ); ?></li>
						<li><code>cxai_prompt_templates</code> — <?php esc_html_e( 'change the prompt library', 'cubix-ai-article-generator' ); ?></li>
						<li><code>cxai_system_prompt</code> — <?php esc_html_e( 'rewrite the system instruction', 'cubix-ai-article-generator' ); ?></li>
						<li><code>cxai_user_prompt</code> — <?php esc_html_e( 'rewrite the composed prompt', 'cubix-ai-article-generator' ); ?></li>
						<li><code>cxai_generated_content</code> — <?php esc_html_e( 'filter output before it reaches the editor', 'cubix-ai-article-generator' ); ?></li>
						<li><code>cxai_create_provider</code> — <?php esc_html_e( 'register a custom engine class', 'cubix-ai-article-generator' ); ?></li>
					</ul>
				</div>
			</div>
		</section>
		<?php
	}
}
