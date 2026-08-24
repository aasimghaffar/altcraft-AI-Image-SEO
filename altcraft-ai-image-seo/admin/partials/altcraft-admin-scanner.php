<?php
/**
 * Bulk Scanner view.
 *
 * Variables provided by AltCraft_Admin::render_scanner_page(): $stats, $has_key.
 *
 * @package AltCraft_AI_Image_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap altcraft-wrap">

	<div class="altcraft-header">
		<div class="altcraft-brand">
			<span class="altcraft-logo" aria-hidden="true"><span class="dashicons dashicons-performance"></span></span>
			<div>
				<h1><?php esc_html_e( 'Bulk Vision Scanner', 'altcraft-ai-image-seo' ); ?></h1>
				<p class="altcraft-subline"><?php esc_html_e( 'Writes ALT text for your whole media library, one image at a time, without leaving this page.', 'altcraft-ai-image-seo' ); ?></p>
			</div>
		</div>
		<div class="altcraft-header-actions">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=altcraft-ai-settings' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'Settings', 'altcraft-ai-image-seo' ); ?></a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=altcraft-ai-media-table' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'Media SEO Table', 'altcraft-ai-image-seo' ); ?></a>
		</div>
	</div>

	<?php if ( ! $has_key ) : ?>
		<div class="notice notice-warning altcraft-notice">
			<p>
				<?php esc_html_e( 'Add an API key before running the scanner.', 'altcraft-ai-image-seo' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=altcraft-ai-settings' ) ); ?>"><?php esc_html_e( 'Open settings', 'altcraft-ai-image-seo' ); ?></a>
			</p>
		</div>
	<?php endif; ?>

	<div class="altcraft-stats" role="list">
		<div class="altcraft-stat" role="listitem">
			<span class="altcraft-stat-label"><?php esc_html_e( 'Images in library', 'altcraft-ai-image-seo' ); ?></span>
			<span class="altcraft-stat-value"><?php echo esc_html( number_format_i18n( $stats['total'] ) ); ?></span>
		</div>
		<div class="altcraft-stat" role="listitem">
			<span class="altcraft-stat-label"><?php esc_html_e( 'Missing ALT text', 'altcraft-ai-image-seo' ); ?></span>
			<span class="altcraft-stat-value <?php echo $stats['missing'] > 0 ? 'altcraft-bad' : 'altcraft-good'; ?>" id="altcraft-stat-missing"><?php echo esc_html( number_format_i18n( $stats['missing'] ) ); ?></span>
		</div>
		<div class="altcraft-stat" role="listitem">
			<span class="altcraft-stat-label"><?php esc_html_e( 'ALT coverage', 'altcraft-ai-image-seo' ); ?></span>
			<span class="altcraft-stat-value"><?php echo esc_html( $stats['percent'] ); ?>%</span>
		</div>
	</div>

	<div class="altcraft-card">
		<div class="altcraft-card-head">
			<h2><?php esc_html_e( 'Start a scan', 'altcraft-ai-image-seo' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Keep this tab open while the scan runs. You can stop at any time; progress is saved image by image.', 'altcraft-ai-image-seo' ); ?></p>
		</div>

		<fieldset class="altcraft-scan-modes">
			<legend class="screen-reader-text"><?php esc_html_e( 'Scan mode', 'altcraft-ai-image-seo' ); ?></legend>
			<label class="altcraft-radio">
				<input type="radio" name="altcraft_scan_mode" value="missing" checked>
				<span>
					<strong><?php esc_html_e( 'Only images without ALT text', 'altcraft-ai-image-seo' ); ?></strong>
					<span class="altcraft-muted"><?php esc_html_e( 'Recommended. Manual ALT text is left untouched.', 'altcraft-ai-image-seo' ); ?></span>
				</span>
			</label>
			<label class="altcraft-radio">
				<input type="radio" name="altcraft_scan_mode" value="all">
				<span>
					<strong><?php esc_html_e( 'Every image (rewrite existing ALT text)', 'altcraft-ai-image-seo' ); ?></strong>
					<span class="altcraft-muted"><?php esc_html_e( 'Useful after changing the language or style. Uses one API request per image.', 'altcraft-ai-image-seo' ); ?></span>
				</span>
			</label>
		</fieldset>

		<div class="altcraft-scan-actions">
			<button type="button" id="altcraft-bulk-start-btn" class="button button-primary button-hero altcraft-btn-brand" <?php disabled( ! $has_key ); ?>><?php esc_html_e( 'Start Bulk Optimization', 'altcraft-ai-image-seo' ); ?></button>
			<button type="button" id="altcraft-bulk-stop-btn" class="button button-hero" hidden><?php esc_html_e( 'Stop', 'altcraft-ai-image-seo' ); ?></button>
		</div>

		<div id="altcraft-progress-box" class="altcraft-progress" hidden>
			<div class="altcraft-progress-header">
				<strong id="altcraft-progress-title"><?php esc_html_e( 'Processing…', 'altcraft-ai-image-seo' ); ?></strong>
				<span id="altcraft-progress-percent">0%</span>
			</div>
			<div class="altcraft-progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="altcraft-progress-bar">
				<div id="altcraft-progress-bar-fill" class="altcraft-progress-fill" style="width:0%"></div>
			</div>
			<div class="altcraft-counters">
				<span><?php esc_html_e( 'Processed', 'altcraft-ai-image-seo' ); ?> <strong id="altcraft-count-done">0</strong></span>
				<span><?php esc_html_e( 'Optimized', 'altcraft-ai-image-seo' ); ?> <strong id="altcraft-count-ok" class="altcraft-good">0</strong></span>
				<span><?php esc_html_e( 'Skipped', 'altcraft-ai-image-seo' ); ?> <strong id="altcraft-count-skip">0</strong></span>
				<span><?php esc_html_e( 'Failed', 'altcraft-ai-image-seo' ); ?> <strong id="altcraft-count-fail" class="altcraft-bad">0</strong></span>
			</div>
			<div id="altcraft-progress-logs" class="altcraft-log" aria-live="polite"></div>
		</div>
	</div>
</div>
