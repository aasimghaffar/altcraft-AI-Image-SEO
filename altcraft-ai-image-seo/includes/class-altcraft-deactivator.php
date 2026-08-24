<?php
/**
 * Fired during plugin deactivation.
 *
 * @package AltCraft_AI_Image_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deactivation routines.
 */
class AltCraft_Deactivator {

	/**
	 * Deactivates the plugin for one site or, when network-deactivated, every site.
	 *
	 * @param bool $network_wide Whether the plugin is being network-deactivated.
	 * @return void
	 */
	public static function deactivate( $network_wide = false ) {
		if ( $network_wide && is_multisite() ) {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);
			foreach ( $site_ids as $site_id ) {
				switch_to_blog( $site_id );
				self::deactivate_site();
				restore_current_blog();
			}
			return;
		}

		self::deactivate_site();
	}

	/**
	 * Per-site deactivation.
	 *
	 * @return void
	 */
	private static function deactivate_site() {
		wp_clear_scheduled_hook( ALTCRAFT_AI_CRON_HOOK );
		delete_transient( 'altcraft_ai_stats' );
		delete_transient( 'altcraft_ai_cron_skip' );
	}
}
