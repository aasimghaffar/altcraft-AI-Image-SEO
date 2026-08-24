<?php
/**
 * Settings page view.
 *
 * Variables provided by AltCraft_Admin::render_settings_page():
 * $options, $stats, $webp_supported, $last_cron, $next_cron, $has_key.
 *
 * @package AltCraft_AI_Image_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$altcraft_provider = $options['api_provider'];
?>
<div class="wrap altcraft-wrap">

	<div class="altcraft-header">
		<div class="altcraft-brand">
			<span class="altcraft-logo" aria-hidden="true"><span class="dashicons dashicons-format-image"></span></span>
			<div>
				<h1><?php esc_html_e( 'AltCraft AI – Image SEO & Auto Alt Text', 'altcraft-ai-image-seo' ); ?></h1>
				<p class="altcraft-subline">
					<?php
					printf(
						/* translators: %s: plugin version */
						esc_html__( 'Version %s by Cubixsol', 'altcraft-ai-image-seo' ),
						esc_html( ALTCRAFT_AI_VERSION )
					);
					?>
				</p>
			</div>
		</div>
		<div class="altcraft-header-actions">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=altcraft-ai-media-table' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'Media SEO Table', 'altcraft-ai-image-seo' ); ?></a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=altcraft-ai-scanner' ) ); ?>" class="button button-primary altcraft-btn-brand"><?php esc_html_e( 'Bulk Scanner', 'altcraft-ai-image-seo' ); ?></a>
		</div>
	</div>

	<?php settings_errors(); ?>

	<?php if ( ! $has_key ) : ?>
		<div class="notice notice-warning altcraft-notice">
			<p><?php esc_html_e( 'Add your Google Gemini or OpenAI API key below and save the settings to start generating ALT text.', 'altcraft-ai-image-seo' ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( ! $capabilities['can_resize'] ) : ?>
		<div class="notice notice-error altcraft-notice">
			<p><?php esc_html_e( 'This server has no working image editor (PHP GD or Imagick), so WordPress cannot create thumbnails. The plugin will send original files to the AI, which is slow and may time out for large images. Ask your host to enable GD or Imagick.', 'altcraft-ai-image-seo' ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( 'yes' === $options['enable_webp_convert'] && ! $webp_supported ) : ?>
		<div class="notice notice-error altcraft-notice">
			<p><?php esc_html_e( 'WebP conversion is enabled but this server cannot create WebP images (PHP GD/Imagick without WebP support). Ask your host to enable it or turn the option off.', 'altcraft-ai-image-seo' ); ?></p>
		</div>
	<?php endif; ?>

	<div class="altcraft-stats" role="list">
		<div class="altcraft-stat" role="listitem">
			<span class="altcraft-stat-label"><?php esc_html_e( 'Images in library', 'altcraft-ai-image-seo' ); ?></span>
			<span class="altcraft-stat-value"><?php echo esc_html( number_format_i18n( $stats['total'] ) ); ?></span>
		</div>
		<div class="altcraft-stat" role="listitem">
			<span class="altcraft-stat-label"><?php esc_html_e( 'With ALT text', 'altcraft-ai-image-seo' ); ?></span>
			<span class="altcraft-stat-value altcraft-good"><?php echo esc_html( number_format_i18n( $stats['optimized'] ) ); ?></span>
		</div>
		<div class="altcraft-stat" role="listitem">
			<span class="altcraft-stat-label"><?php esc_html_e( 'Missing ALT text', 'altcraft-ai-image-seo' ); ?></span>
			<span class="altcraft-stat-value <?php echo $stats['missing'] > 0 ? 'altcraft-bad' : 'altcraft-good'; ?>"><?php echo esc_html( number_format_i18n( $stats['missing'] ) ); ?></span>
		</div>
		<div class="altcraft-stat" role="listitem">
			<span class="altcraft-stat-label"><?php esc_html_e( 'ALT coverage', 'altcraft-ai-image-seo' ); ?></span>
			<span class="altcraft-stat-value"><?php echo esc_html( $stats['percent'] ); ?>%</span>
			<span class="altcraft-meter" aria-hidden="true"><span class="altcraft-meter-fill" style="width:<?php echo esc_attr( $stats['percent'] ); ?>%"></span></span>
		</div>
	</div>

	<div class="altcraft-layout">
		<div class="altcraft-main">

			<nav class="altcraft-tabs" aria-label="<?php esc_attr_e( 'Settings sections', 'altcraft-ai-image-seo' ); ?>">
				<a href="#tab-api" class="altcraft-tab active" data-tab="tab-api"><span class="dashicons dashicons-admin-network"></span><?php esc_html_e( 'AI Provider', 'altcraft-ai-image-seo' ); ?></a>
				<a href="#tab-rules" class="altcraft-tab" data-tab="tab-rules"><span class="dashicons dashicons-admin-settings"></span><?php esc_html_e( 'Automation & SEO Rules', 'altcraft-ai-image-seo' ); ?></a>
				<a href="#tab-webp" class="altcraft-tab" data-tab="tab-webp"><span class="dashicons dashicons-performance"></span><?php esc_html_e( 'WebP', 'altcraft-ai-image-seo' ); ?></a>
				<a href="#tab-woo" class="altcraft-tab" data-tab="tab-woo"><span class="dashicons dashicons-cart"></span><?php esc_html_e( 'WooCommerce', 'altcraft-ai-image-seo' ); ?></a>
				<a href="#tab-advanced" class="altcraft-tab" data-tab="tab-advanced"><span class="dashicons dashicons-admin-tools"></span><?php esc_html_e( 'Advanced', 'altcraft-ai-image-seo' ); ?></a>
			</nav>

			<form method="post" action="options.php" id="altcraft-settings-form">
				<?php settings_fields( 'altcraft_options_group' ); ?>

				<!-- AI provider -->
				<div id="tab-api" class="altcraft-tab-panel active">
					<div class="altcraft-card">
						<div class="altcraft-card-head">
							<h2><?php esc_html_e( 'AI vision provider', 'altcraft-ai-image-seo' ); ?></h2>
							<p class="description"><?php esc_html_e( 'A downscaled copy of each image (max 1024px) is sent to the provider you choose. Nothing is sent until you add a key.', 'altcraft-ai-image-seo' ); ?></p>
						</div>

						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="altcraft-provider"><?php esc_html_e( 'Provider', 'altcraft-ai-image-seo' ); ?></label></th>
								<td>
									<select name="altcraft_ai_settings[api_provider]" id="altcraft-provider" class="regular-text altcraft-provider-select">
										<?php foreach ( AltCraft_Settings::providers() as $altcraft_slug => $altcraft_label ) : ?>
											<option value="<?php echo esc_attr( $altcraft_slug ); ?>" <?php selected( $altcraft_provider, $altcraft_slug ); ?>><?php echo esc_html( $altcraft_label ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
						</table>

						<?php foreach ( AltCraft_Settings::providers() as $altcraft_slug => $altcraft_label ) : ?>
							<?php
							$altcraft_key_field   = $altcraft_slug . '_api_key';
							$altcraft_model_field = $altcraft_slug . '_model';
							$altcraft_custom      = $altcraft_slug . '_model_custom';
							$altcraft_hidden      = ( $altcraft_provider !== $altcraft_slug );
							?>
							<div class="altcraft-provider-block" data-provider="<?php echo esc_attr( $altcraft_slug ); ?>" <?php echo $altcraft_hidden ? 'hidden' : ''; ?>>
								<h3><?php echo esc_html( $altcraft_label ); ?></h3>
								<table class="form-table" role="presentation">
									<tr>
										<th scope="row"><label for="altcraft-<?php echo esc_attr( $altcraft_key_field ); ?>"><?php esc_html_e( 'API key', 'altcraft-ai-image-seo' ); ?></label></th>
										<td>
											<div class="altcraft-key-row">
												<input type="password" id="altcraft-<?php echo esc_attr( $altcraft_key_field ); ?>" name="altcraft_ai_settings[<?php echo esc_attr( $altcraft_key_field ); ?>]" value="<?php echo esc_attr( $options[ $altcraft_key_field ] ); ?>" class="regular-text altcraft-key-input" autocomplete="off" spellcheck="false">
												<button type="button" class="button altcraft-toggle-key" aria-label="<?php esc_attr_e( 'Show or hide the API key', 'altcraft-ai-image-seo' ); ?>"><span class="dashicons dashicons-visibility"></span></button>
											</div>
											<p class="description">
												<?php if ( 'gemini' === $altcraft_slug ) : ?>
													<?php esc_html_e( 'Create a key in Google AI Studio (aistudio.google.com/apikey). Free tier keys work.', 'altcraft-ai-image-seo' ); ?>
												<?php else : ?>
													<?php esc_html_e( 'Create a key at platform.openai.com/api-keys. Usage is billed to your OpenAI account.', 'altcraft-ai-image-seo' ); ?>
												<?php endif; ?>
											</p>
										</td>
									</tr>
									<tr>
										<th scope="row"><label for="altcraft-<?php echo esc_attr( $altcraft_model_field ); ?>"><?php esc_html_e( 'Model', 'altcraft-ai-image-seo' ); ?></label></th>
										<td>
											<select id="altcraft-<?php echo esc_attr( $altcraft_model_field ); ?>" name="altcraft_ai_settings[<?php echo esc_attr( $altcraft_model_field ); ?>]" class="regular-text altcraft-model-select">
												<?php foreach ( AltCraft_Settings::model_choices( $altcraft_slug ) as $altcraft_model_id => $altcraft_model_label ) : ?>
													<option value="<?php echo esc_attr( $altcraft_model_id ); ?>" <?php selected( $options[ $altcraft_model_field ], $altcraft_model_id ); ?>><?php echo esc_html( $altcraft_model_label ); ?></option>
												<?php endforeach; ?>
											</select>
											<input type="text" name="altcraft_ai_settings[<?php echo esc_attr( $altcraft_custom ); ?>]" value="<?php echo esc_attr( $options[ $altcraft_custom ] ); ?>" class="regular-text altcraft-model-custom" placeholder="<?php esc_attr_e( 'Enter a model ID', 'altcraft-ai-image-seo' ); ?>" <?php echo 'custom' === $options[ $altcraft_model_field ] ? '' : 'hidden'; ?>>
											<p class="description"><?php esc_html_e( 'Providers retire models regularly. If generation stops working with a "model not found" error, pick a newer model here.', 'altcraft-ai-image-seo' ); ?></p>
										</td>
									</tr>
								</table>
							</div>
						<?php endforeach; ?>

						<div class="altcraft-test-row">
							<button type="button" class="button button-secondary" id="altcraft-test-connection"><?php esc_html_e( 'Test connection', 'altcraft-ai-image-seo' ); ?></button>
							<span id="altcraft-test-status" class="altcraft-inline-status" aria-live="polite"></span>
							<span class="altcraft-muted altcraft-test-hint"><?php esc_html_e( 'Sends a tiny built-in test image through the full pipeline, exactly like a real generation.', 'altcraft-ai-image-seo' ); ?></span>
						</div>
					</div>
				</div>

				<!-- Automation & SEO rules -->
				<div id="tab-rules" class="altcraft-tab-panel">
					<div class="altcraft-card">
						<div class="altcraft-card-head">
							<h2><?php esc_html_e( 'Automation & SEO rules', 'altcraft-ai-image-seo' ); ?></h2>
							<p class="description"><?php esc_html_e( 'Control when ALT text is generated and how it is written.', 'altcraft-ai-image-seo' ); ?></p>
						</div>

						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="altcraft-source"><?php esc_html_e( 'What the AI looks at', 'altcraft-ai-image-seo' ); ?></label></th>
								<td>
									<select id="altcraft-source" name="altcraft_ai_settings[generation_source]" class="regular-text">
										<?php foreach ( AltCraft_Settings::generation_sources() as $altcraft_source => $altcraft_source_label ) : ?>
											<option value="<?php echo esc_attr( $altcraft_source ); ?>" <?php selected( $options['generation_source'], $altcraft_source ); ?>><?php echo esc_html( $altcraft_source_label ); ?></option>
										<?php endforeach; ?>
									</select>
									<p class="description"><?php esc_html_e( 'Image and filename: the AI sees a small copy of the image and gets the filename as a hint – most accurate. Image only: the filename is ignored (use when filenames are misleading). Filename only: the image is never sent; the AI writes from the filename and the related post/product – fastest and cheapest, but it can only guess what the picture shows, and images with camera-style names (IMG_1234) that are not attached to a post or product are skipped.', 'altcraft-ai-image-seo' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Generate on upload', 'altcraft-ai-image-seo' ); ?></th>
								<td>
									<label class="altcraft-switch">
										<input type="checkbox" name="altcraft_ai_settings[auto_on_upload]" value="yes" <?php checked( $options['auto_on_upload'], 'yes' ); ?>>
										<span class="altcraft-slider" aria-hidden="true"></span>
										<span class="altcraft-switch-text"><?php esc_html_e( 'Write ALT text for every new image upload', 'altcraft-ai-image-seo' ); ?></span>
									</label>
									<p class="description"><?php esc_html_e( 'Runs during the upload, so the ALT text is already there when you insert the image (adds a few seconds per upload).', 'altcraft-ai-image-seo' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Overwrite existing ALT', 'altcraft-ai-image-seo' ); ?></th>
								<td>
									<label class="altcraft-switch">
										<input type="checkbox" name="altcraft_ai_settings[overwrite_existing]" value="yes" <?php checked( $options['overwrite_existing'], 'yes' ); ?>>
										<span class="altcraft-slider" aria-hidden="true"></span>
										<span class="altcraft-switch-text"><?php esc_html_e( 'Replace ALT text that was already written manually', 'altcraft-ai-image-seo' ); ?></span>
									</label>
									<p class="description"><?php esc_html_e( 'Applies to automatic generation (upload and nightly cron). The Generate buttons always regenerate.', 'altcraft-ai-image-seo' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Also update', 'altcraft-ai-image-seo' ); ?></th>
								<td>
									<label class="altcraft-switch">
										<input type="checkbox" name="altcraft_ai_settings[sync_title]" value="yes" <?php checked( $options['sync_title'], 'yes' ); ?>>
										<span class="altcraft-slider" aria-hidden="true"></span>
										<span class="altcraft-switch-text"><?php esc_html_e( 'Image title', 'altcraft-ai-image-seo' ); ?></span>
									</label>
									<br>
									<label class="altcraft-switch">
										<input type="checkbox" name="altcraft_ai_settings[sync_caption]" value="yes" <?php checked( $options['sync_caption'], 'yes' ); ?>>
										<span class="altcraft-slider" aria-hidden="true"></span>
										<span class="altcraft-switch-text"><?php esc_html_e( 'Image caption', 'altcraft-ai-image-seo' ); ?></span>
									</label>
									<p class="description"><?php esc_html_e( 'The AI writes a short title and a one-sentence caption in the same request – no extra cost.', 'altcraft-ai-image-seo' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="altcraft-language"><?php esc_html_e( 'Output language', 'altcraft-ai-image-seo' ); ?></label></th>
								<td>
									<select id="altcraft-language" name="altcraft_ai_settings[output_language]" class="regular-text">
										<?php foreach ( AltCraft_Settings::languages() as $altcraft_lang => $altcraft_lang_label ) : ?>
											<option value="<?php echo esc_attr( $altcraft_lang ); ?>" <?php selected( $options['output_language'], $altcraft_lang ); ?>><?php echo esc_html( $altcraft_lang_label ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="altcraft-style"><?php esc_html_e( 'ALT text style', 'altcraft-ai-image-seo' ); ?></label></th>
								<td>
									<select id="altcraft-style" name="altcraft_ai_settings[alt_style]" class="regular-text">
										<?php foreach ( AltCraft_Settings::alt_styles() as $altcraft_style => $altcraft_style_label ) : ?>
											<option value="<?php echo esc_attr( $altcraft_style ); ?>" <?php selected( $options['alt_style'], $altcraft_style ); ?>><?php echo esc_html( $altcraft_style_label ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Nightly background scan', 'altcraft-ai-image-seo' ); ?></th>
								<td>
									<label class="altcraft-switch">
										<input type="checkbox" name="altcraft_ai_settings[enable_nightly_cron]" value="yes" <?php checked( $options['enable_nightly_cron'], 'yes' ); ?>>
										<span class="altcraft-slider" aria-hidden="true"></span>
										<span class="altcraft-switch-text"><?php esc_html_e( 'Fix images without ALT text every night (WP-Cron)', 'altcraft-ai-image-seo' ); ?></span>
									</label>
									<p class="description">
										<label for="altcraft-batch"><?php esc_html_e( 'Images per run:', 'altcraft-ai-image-seo' ); ?></label>
										<input type="number" id="altcraft-batch" name="altcraft_ai_settings[cron_batch_size]" value="<?php echo esc_attr( $options['cron_batch_size'] ); ?>" min="1" max="50" class="small-text">
										<?php esc_html_e( 'Runs continue in follow-up batches until everything is covered. Keep this low on shared hosting.', 'altcraft-ai-image-seo' ); ?>
									</p>
									<?php if ( $next_cron ) : ?>
										<p class="description">
											<?php
											printf(
												/* translators: %s: date and time */
												esc_html__( 'Next run: %s', 'altcraft-ai-image-seo' ),
												esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next_cron ) )
											);
											?>
										</p>
									<?php endif; ?>
									<?php if ( ! empty( $last_cron['time'] ) ) : ?>
										<p class="description">
											<?php
											printf(
												/* translators: 1: date and time, 2: processed count, 3: optimized count, 4: remaining count */
												esc_html__( 'Last run: %1$s – %2$d processed, %3$d optimized, %4$d remaining.', 'altcraft-ai-image-seo' ),
												esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $last_cron['time'] ) ),
												(int) $last_cron['processed'],
												(int) $last_cron['success'],
												(int) $last_cron['remaining']
											);
											?>
										</p>
									<?php endif; ?>
								</td>
							</tr>
						</table>
					</div>
				</div>

				<!-- WebP -->
				<div id="tab-webp" class="altcraft-tab-panel">
					<div class="altcraft-card">
						<div class="altcraft-card-head">
							<h2><?php esc_html_e( 'Next-gen WebP copies', 'altcraft-ai-image-seo' ); ?></h2>
							<p class="description"><?php esc_html_e( 'Creates a lighter .webp copy of every JPEG/PNG (original and all thumbnail sizes). Your original files are never modified.', 'altcraft-ai-image-seo' ); ?></p>
						</div>

						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><?php esc_html_e( 'Create WebP copies', 'altcraft-ai-image-seo' ); ?></th>
								<td>
									<label class="altcraft-switch">
										<input type="checkbox" name="altcraft_ai_settings[enable_webp_convert]" value="yes" <?php checked( $options['enable_webp_convert'], 'yes' ); ?>>
										<span class="altcraft-slider" aria-hidden="true"></span>
										<span class="altcraft-switch-text"><?php esc_html_e( 'On upload and whenever ALT text is generated', 'altcraft-ai-image-seo' ); ?></span>
									</label>
									<p class="description">
										<?php if ( $webp_supported ) : ?>
											<span class="altcraft-good"><?php esc_html_e( 'Your server supports WebP.', 'altcraft-ai-image-seo' ); ?></span>
										<?php else : ?>
											<span class="altcraft-bad"><?php esc_html_e( 'Your server cannot create WebP images.', 'altcraft-ai-image-seo' ); ?></span>
										<?php endif; ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="altcraft-webp-quality"><?php esc_html_e( 'WebP quality', 'altcraft-ai-image-seo' ); ?></label></th>
								<td>
									<input type="number" id="altcraft-webp-quality" name="altcraft_ai_settings[webp_quality]" value="<?php echo esc_attr( $options['webp_quality'] ); ?>" min="40" max="100" class="small-text">
									<p class="description"><?php esc_html_e( '82 is a good balance between size and quality.', 'altcraft-ai-image-seo' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Serve WebP to visitors', 'altcraft-ai-image-seo' ); ?></th>
								<td>
									<label class="altcraft-switch">
										<input type="checkbox" name="altcraft_ai_settings[enable_webp_delivery]" value="yes" <?php checked( $options['enable_webp_delivery'], 'yes' ); ?>>
										<span class="altcraft-slider" aria-hidden="true"></span>
										<span class="altcraft-switch-text"><?php esc_html_e( 'Swap image URLs for the .webp copy when the browser supports it', 'altcraft-ai-image-seo' ); ?></span>
									</label>
									<p class="description"><?php esc_html_e( 'Applies to featured images, image blocks and srcset on the front end. If you use a full-page cache or CDN, make sure it varies the cache by the Accept header, or leave this off and let your CDN handle WebP.', 'altcraft-ai-image-seo' ); ?></p>
								</td>
							</tr>
						</table>
					</div>
				</div>

				<!-- WooCommerce -->
				<div id="tab-woo" class="altcraft-tab-panel">
					<div class="altcraft-card">
						<div class="altcraft-card-head">
							<h2><?php esc_html_e( 'WooCommerce product context', 'altcraft-ai-image-seo' ); ?></h2>
							<p class="description"><?php esc_html_e( 'Product images get better ALT text when the AI knows the product name, categories and your brand.', 'altcraft-ai-image-seo' ); ?></p>
						</div>

						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><?php esc_html_e( 'Use product context', 'altcraft-ai-image-seo' ); ?></th>
								<td>
									<label class="altcraft-switch">
										<input type="checkbox" name="altcraft_ai_settings[enable_woo_focus]" value="yes" <?php checked( $options['enable_woo_focus'], 'yes' ); ?>>
										<span class="altcraft-slider" aria-hidden="true"></span>
										<span class="altcraft-switch-text"><?php esc_html_e( 'Send the product title, categories and SKU with product images', 'altcraft-ai-image-seo' ); ?></span>
									</label>
									<p class="description"><?php esc_html_e( 'Works for featured images and gallery images. Also used for images attached to regular posts and pages.', 'altcraft-ai-image-seo' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="altcraft-brand"><?php esc_html_e( 'Store / brand keywords', 'altcraft-ai-image-seo' ); ?></label></th>
								<td>
									<input type="text" id="altcraft-brand" name="altcraft_ai_settings[brand_keywords]" value="<?php echo esc_attr( $options['brand_keywords'] ); ?>" class="large-text" placeholder="<?php esc_attr_e( 'e.g. MyStore, handcrafted, organic cotton', 'altcraft-ai-image-seo' ); ?>">
									<p class="description"><?php esc_html_e( 'Comma-separated. Used only where they fit naturally – never stuffed into every image.', 'altcraft-ai-image-seo' ); ?></p>
								</td>
							</tr>
						</table>
					</div>
				</div>

				<!-- Advanced -->
				<div id="tab-advanced" class="altcraft-tab-panel">
					<div class="altcraft-card">
						<div class="altcraft-card-head">
							<h2><?php esc_html_e( 'Advanced', 'altcraft-ai-image-seo' ); ?></h2>
						</div>

						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="altcraft-timeout"><?php esc_html_e( 'Request timeout', 'altcraft-ai-image-seo' ); ?></label></th>
								<td>
									<input type="number" id="altcraft-timeout" name="altcraft_ai_settings[request_timeout]" value="<?php echo esc_attr( $options['request_timeout'] ); ?>" min="20" max="180" class="small-text"> <?php esc_html_e( 'seconds', 'altcraft-ai-image-seo' ); ?>
									<p class="description"><?php esc_html_e( 'How long to wait for the AI provider per image (20–180). Raise it if you see "did not respond within" errors on large images or slow hosting. Some hosts cut off any request after 60–90 seconds regardless of this value.', 'altcraft-ai-image-seo' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Clean up on uninstall', 'altcraft-ai-image-seo' ); ?></th>
								<td>
									<label class="altcraft-switch">
										<input type="checkbox" name="altcraft_ai_settings[delete_data_on_uninstall]" value="yes" <?php checked( $options['delete_data_on_uninstall'], 'yes' ); ?>>
										<span class="altcraft-slider" aria-hidden="true"></span>
										<span class="altcraft-switch-text"><?php esc_html_e( 'Delete plugin settings, generation logs and WebP copies when the plugin is uninstalled', 'altcraft-ai-image-seo' ); ?></span>
									</label>
									<p class="description"><?php esc_html_e( 'ALT text, titles and captions stay in your media library either way.', 'altcraft-ai-image-seo' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Server capabilities', 'altcraft-ai-image-seo' ); ?></th>
								<td>
									<p class="description">
										<?php
										printf(
											/* translators: 1: image editor name(s) or "none", 2: yes/no, 3: PHP memory limit */
											esc_html__( 'Image editor: %1$s · WebP output: %2$s · PHP memory limit: %3$s', 'altcraft-ai-image-seo' ),
											esc_html( '' !== $capabilities['editor'] ? $capabilities['editor'] : __( 'none', 'altcraft-ai-image-seo' ) ),
											esc_html( $capabilities['webp'] ? __( 'yes', 'altcraft-ai-image-seo' ) : __( 'no', 'altcraft-ai-image-seo' ) ),
											esc_html( $capabilities['memory_limit'] )
										);
										?>
									</p>
									<p class="description"><?php esc_html_e( 'The AI receives a downscaled copy (max 1024 px) when the editor can create one; otherwise the original is sent as long as it is under 4 MB (altcraft_ai_max_fallback_bytes filter).', 'altcraft-ai-image-seo' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Developer hooks', 'altcraft-ai-image-seo' ); ?></th>
								<td>
									<p class="description"><?php esc_html_e( 'Filters: altcraft_ai_prompt, altcraft_ai_result, altcraft_ai_context, altcraft_ai_model, altcraft_ai_api_key, altcraft_ai_max_image_dimension, altcraft_ai_serve_webp. Action: altcraft_ai_after_generate.', 'altcraft-ai-image-seo' ); ?></p>
								</td>
							</tr>
						</table>
					</div>
				</div>

				<div class="altcraft-submit-row">
					<?php submit_button( __( 'Save settings', 'altcraft-ai-image-seo' ), 'primary large altcraft-btn-brand', 'submit', false ); ?>
				</div>
			</form>
		</div>

		<aside class="altcraft-sidebar">
			<div class="altcraft-card">
				<h3><?php esc_html_e( 'Optimize existing images', 'altcraft-ai-image-seo' ); ?></h3>
				<p class="description">
					<?php
					printf(
						/* translators: %s: number of images */
						esc_html( _n( '%s image is missing ALT text. The bulk scanner fixes them in one go.', '%s images are missing ALT text. The bulk scanner fixes them in one go.', $stats['missing'], 'altcraft-ai-image-seo' ) ),
						esc_html( number_format_i18n( $stats['missing'] ) )
					);
					?>
				</p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=altcraft-ai-scanner' ) ); ?>" class="button altcraft-btn-brand altcraft-btn-block"><?php esc_html_e( 'Open Bulk Scanner', 'altcraft-ai-image-seo' ); ?></a>
			</div>

			<div class="altcraft-card">
				<h3><?php esc_html_e( 'What gets sent to the AI provider?', 'altcraft-ai-image-seo' ); ?></h3>
				<p class="description"><?php esc_html_e( 'A resized copy of the image (unless you choose "Filename only"), plus the related product/post title, categories, your brand keywords and the filename. Nothing else – no user data, no site URL in the prompt.', 'altcraft-ai-image-seo' ); ?></p>
				<p class="description"><?php esc_html_e( 'Requests are covered by the terms and privacy policy of the provider you select (Google or OpenAI).', 'altcraft-ai-image-seo' ); ?></p>
			</div>

			<div class="altcraft-card">
				<h3><?php esc_html_e( 'Crafted by Cubixsol', 'altcraft-ai-image-seo' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Found a bug or have an idea? We read every support thread.', 'altcraft-ai-image-seo' ); ?></p>
				<a href="https://cubixsol.com/products/" target="_blank" rel="noopener noreferrer" class="button altcraft-btn-block"><?php esc_html_e( 'More plugins by Cubixsol', 'altcraft-ai-image-seo' ); ?></a>
			</div>
		</aside>
	</div>
</div>
