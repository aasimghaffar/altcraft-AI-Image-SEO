<?php
/**
 * Post editor meta box.
 *
 * Renders the generation panel on the post types selected in settings.
 * Nothing is saved server-side by the box itself — content is inserted
 * into the editor client-side, so there is no save_post handler.
 *
 * @package Cubix_AI_Article_Generator
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CXAI_Metabox
 */
class CXAI_Metabox {

	/**
	 * Register the meta box on enabled post types only.
	 *
	 * @return void
	 */
	public function register_meta_box() {
		$post_types = CXAI_Options::get_enabled_post_types();

		if ( empty( $post_types ) || ! CXAI_Options::current_user_can_use() ) {
			return;
		}

		add_meta_box(
			'cxai_generator',
			__( 'Cubix AI Article Generator', 'cubix-ai-article-generator' ),
			array( $this, 'render' ),
			$post_types,
			'normal',
			'high'
		);
	}

	/**
	 * Render the meta box markup.
	 *
	 * @param WP_Post $post Current post object.
	 * @return void
	 */
	public function render( $post ) {
		if ( ! current_user_can( 'edit_post', $post->ID ) || ! CXAI_Options::current_user_can_use() ) {
			return;
		}

		$settings  = CXAI_Options::get_settings();
		$providers = CXAI_Options::get_providers();
		$default   = CXAI_Options::get_default_provider();
		$modes     = CXAI_Options::get_modes();
		$history   = CXAI_Logger::get_history( get_current_user_id() );

		// Group modes for the optgroup dropdown.
		$grouped = array();

		foreach ( $modes as $slug => $mode ) {
			$grouped[ $mode['group'] ][ $slug ] = $mode['label'];
		}
		?>
		<div class="cx-box" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
			<div class="cx-box-bar">
				<span class="cx-box-mark">
					<?php echo wp_kses( CXAI_Branding::logo( 26 ), CXAI_Branding::svg_kses() ); ?>
					<span class="cx-eyebrow"><?php esc_html_e( 'Cubix AI', 'cubix-ai-article-generator' ); ?></span>
				</span>

				<span class="cx-box-bar-right">
					<?php if ( ! empty( $history ) ) : ?>
						<button type="button" class="cx-btn cx-btn-ghost cx-btn-sm" id="cx-history-toggle">
							<span class="dashicons dashicons-backup" aria-hidden="true"></span>
							<?php esc_html_e( 'Recent', 'cubix-ai-article-generator' ); ?>
						</button>
					<?php endif; ?>
					<a class="cx-btn cx-btn-ghost cx-btn-sm" href="<?php echo esc_url( admin_url( 'admin.php?page=cxai-studio' ) ); ?>" target="_blank" rel="noopener">
						<span class="dashicons dashicons-format-chat" aria-hidden="true"></span>
						<?php esc_html_e( 'Studio', 'cubix-ai-article-generator' ); ?>
					</a>
				</span>
			</div>

			<?php if ( ! empty( $history ) ) : ?>
				<div class="cx-history cx-hidden" id="cx-history">
					<?php foreach ( $history as $entry ) : ?>
						<button
							type="button"
							class="cx-history-item"
							data-prompt="<?php echo esc_attr( $entry['prompt'] ); ?>"
							data-mode="<?php echo esc_attr( $entry['mode'] ); ?>"
						>
							<span class="cx-history-mode"><?php echo esc_html( isset( $modes[ $entry['mode'] ] ) ? $modes[ $entry['mode'] ]['label'] : $entry['mode'] ); ?></span>
							<span class="cx-history-prompt"><?php echo esc_html( wp_trim_words( $entry['prompt'], 12 ) ); ?></span>
							<span class="cx-history-time"><?php echo esc_html( human_time_diff( $entry['time'] ) ); ?></span>
						</button>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<div class="cx-chips">
				<?php foreach ( CXAI_Options::get_templates() as $template ) : ?>
					<button type="button" class="cx-chip" data-prompt="<?php echo esc_attr( $template['prompt'] ); ?>">
						<?php echo esc_html( $template['label'] ); ?>
					</button>
				<?php endforeach; ?>
			</div>

			<div class="cx-prompt-wrap">
				<label class="screen-reader-text" for="cx-prompt"><?php esc_html_e( 'Prompt', 'cubix-ai-article-generator' ); ?></label>
				<textarea
					id="cx-prompt"
					class="cx-prompt"
					rows="3"
					placeholder="<?php esc_attr_e( 'Describe what you want. For example: a beginner-friendly guide to composting in small apartments…', 'cubix-ai-article-generator' ); ?>"
				></textarea>
				<span class="cx-prompt-count" id="cx-prompt-count">0</span>
			</div>

			<div class="cx-controls">
				<label class="cx-field">
					<span class="cx-field-label"><?php esc_html_e( 'Task', 'cubix-ai-article-generator' ); ?></span>
					<select id="cx-mode" class="cx-input">
						<?php foreach ( $grouped as $group => $items ) : ?>
							<optgroup label="<?php echo esc_attr( $group ); ?>">
								<?php foreach ( $items as $slug => $label ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>" data-context="<?php echo ! empty( $modes[ $slug ]['needs_context'] ) ? '1' : '0'; ?>">
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</optgroup>
						<?php endforeach; ?>
					</select>
				</label>

				<label class="cx-field">
					<span class="cx-field-label"><?php esc_html_e( 'Tone', 'cubix-ai-article-generator' ); ?></span>
					<select id="cx-tone" class="cx-input">
						<?php foreach ( CXAI_Options::get_tones() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['default_tone'], $value ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>

				<label class="cx-field">
					<span class="cx-field-label"><?php esc_html_e( 'Length', 'cubix-ai-article-generator' ); ?></span>
					<select id="cx-length" class="cx-input">
						<?php foreach ( CXAI_Options::get_lengths() as $value => $length ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['default_length'], $value ); ?>>
								<?php echo esc_html( $length['label'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>

				<label class="cx-field">
					<span class="cx-field-label"><?php esc_html_e( 'Engine', 'cubix-ai-article-generator' ); ?></span>
					<select id="cx-engine" class="cx-input">
						<?php foreach ( $providers as $slug => $definition ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $default, $slug ); ?> <?php disabled( ! CXAI_Options::is_provider_ready( $slug ) ); ?>>
								<?php echo esc_html( $definition['label'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>

				<button type="button" class="cx-btn cx-btn-primary cx-btn-generate" id="cx-generate">
					<span class="cx-btn-spark" aria-hidden="true"></span>
					<span class="cx-btn-text"><?php esc_html_e( 'Generate', 'cubix-ai-article-generator' ); ?></span>
				</button>
			</div>

			<label class="cx-check cx-context-check">
				<input type="checkbox" id="cx-use-context" />
				<span><?php esc_html_e( 'Send the current post content as context', 'cubix-ai-article-generator' ); ?></span>
			</label>

			<div class="cx-alert cx-hidden" id="cx-error" role="alert"></div>

			<div class="cx-output cx-hidden" id="cx-output">
				<div class="cx-output-head">
					<span class="cx-output-meta">
						<span class="cx-tag cx-tag-ok" id="cx-output-mode"></span>
						<span class="cx-output-count" id="cx-output-count"></span>
					</span>
					<span class="cx-output-actions">
						<button type="button" class="cx-btn cx-btn-ghost cx-btn-sm" id="cx-regenerate">
							<span class="dashicons dashicons-update" aria-hidden="true"></span>
							<?php esc_html_e( 'Regenerate', 'cubix-ai-article-generator' ); ?>
						</button>
						<button type="button" class="cx-btn cx-btn-ghost cx-btn-sm" id="cx-expand">
							<span class="dashicons dashicons-editor-expand" aria-hidden="true"></span>
							<?php esc_html_e( 'Expand', 'cubix-ai-article-generator' ); ?>
						</button>
						<button type="button" class="cx-btn cx-btn-ghost cx-btn-sm" id="cx-copy">
							<span class="dashicons dashicons-clipboard" aria-hidden="true"></span>
							<?php esc_html_e( 'Copy', 'cubix-ai-article-generator' ); ?>
						</button>
						<button type="button" class="cx-btn cx-btn-primary cx-btn-sm" id="cx-use">
							<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
							<?php esc_html_e( 'Use it', 'cubix-ai-article-generator' ); ?>
						</button>
					</span>
				</div>
				<p class="cx-length-note cx-hidden" id="cx-length-note">
					<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
					<span>
						<?php esc_html_e( 'This answer reached the output limit even after continuing.', 'cubix-ai-article-generator' ); ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=cxai-settings#writing' ) ); ?>" target="_blank" rel="noopener">
							<?php esc_html_e( 'Raise "Maximum response length"', 'cubix-ai-article-generator' ); ?>
						</a>
						<?php esc_html_e( 'for pieces this long.', 'cubix-ai-article-generator' ); ?>
					</span>
				</p>

				<div class="cx-result-wrap">
					<div class="cx-output-body" id="cx-result" tabindex="0"></div>
					<div class="cx-result-fade">
						<button type="button" class="cx-result-more" id="cx-result-more">
							<span class="dashicons dashicons-editor-expand" aria-hidden="true"></span>
							<?php esc_html_e( 'Show full response', 'cubix-ai-article-generator' ); ?>
						</button>
					</div>
				</div>
			</div>

			<!-- Expanded reading view. -->
			<div class="cx-modal cx-hidden" id="cx-modal" role="dialog" aria-modal="true" aria-labelledby="cx-modal-title">
				<div class="cx-modal-veil" data-cx-close="1"></div>
				<div class="cx-modal-box">
					<div class="cx-modal-head">
						<h2 id="cx-modal-title"><?php esc_html_e( 'Generated content', 'cubix-ai-article-generator' ); ?></h2>
						<button type="button" class="cx-modal-x" data-cx-close="1" aria-label="<?php esc_attr_e( 'Close', 'cubix-ai-article-generator' ); ?>">
							<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
						</button>
					</div>
					<div class="cx-modal-body" id="cx-modal-body"></div>
				</div>
			</div>
		</div>
		<?php
	}
}
