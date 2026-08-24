<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * Always removes the plugin's own options, transients and scheduled events.
 * Removes generation logs and WebP copies only when the user enabled
 * "Clean up on uninstall". ALT text, titles and captions are never touched.
 *
 * @package AltCraft_AI_Image_SEO
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Cleans up a single site.
 *
 * @return void
 */
function altcraft_ai_uninstall_site() {
	$settings = get_option( 'altcraft_ai_settings', array() );
	$cleanup  = is_array( $settings ) && isset( $settings['delete_data_on_uninstall'] ) && 'yes' === $settings['delete_data_on_uninstall'];

	wp_clear_scheduled_hook( 'altcraft_daily_cron_hook' );
	wp_clear_scheduled_hook( 'altcraft_daily_cron_hook', array( 'follow-up' ) );

	delete_option( 'altcraft_ai_settings' );
	delete_option( 'altcraft_ai_version' );
	delete_option( 'altcraft_ai_last_cron' );
	delete_transient( 'altcraft_ai_stats' );
	delete_transient( 'altcraft_ai_cron_skip' );

	if ( ! $cleanup ) {
		return;
	}

	$uploads = wp_get_upload_dir();
	$basedir = wp_normalize_path( trailingslashit( $uploads['basedir'] ) );
	$guard   = 0;

	do {
		// Always page 1: processed rows lose the meta key and drop out of the result set.
		$query = new WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'posts_per_page'         => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Bounded batch of IDs only.
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'meta_key'               => '_altcraft_webp', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One-off uninstall cleanup.
			)
		);

		foreach ( $query->posts as $attachment_id ) {
			$files = get_post_meta( $attachment_id, '_altcraft_webp', true );
			if ( is_array( $files ) ) {
				foreach ( $files as $relative ) {
					$relative = ltrim( wp_normalize_path( (string) $relative ), '/' );
					if ( '' === $relative || false !== strpos( $relative, '..' ) || '.webp' !== substr( $relative, -5 ) ) {
						continue;
					}
					$path = $basedir . $relative;
					if ( file_exists( $path ) ) {
						wp_delete_file( $path );
					}
				}
			}
			delete_post_meta( $attachment_id, '_altcraft_webp' );
		}

		++$guard;
	} while ( ! empty( $query->posts ) && $guard < 500 );

	delete_post_meta_by_key( '_altcraft_generated' );
	delete_post_meta_by_key( '_altcraft_last_error' );
}

if ( is_multisite() ) {
	$altcraft_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $altcraft_site_ids as $altcraft_site_id ) {
		switch_to_blog( $altcraft_site_id );
		altcraft_ai_uninstall_site();
		restore_current_blog();
	}
} else {
	altcraft_ai_uninstall_site();
}
