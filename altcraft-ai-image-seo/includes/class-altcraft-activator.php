<?php
/**
 * Fired during plugin activation.
 *
 * @package AltCraft_AI_Image_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Activation routines.
 */
class AltCraft_Activator {

	/**
	 * Activates the plugin for one site or, when network-activated, every site.
	 *
	 * @param bool $network_wide Whether the plugin is being network-activated.
	 * @return void
	 */
	public static function activate( $network_wide = false ) {
		if ( $network_wide && is_multisite() ) {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);
			foreach ( $site_ids as $site_id ) {
				switch_to_blog( $site_id );
				self::activate_site();
				restore_current_blog();
			}
			return;
		}

		self::activate_site();
	}

	/**
	 * Per-site activation.
	 *
	 * @return void
	 */
	private static function activate_site() {
		if ( false === get_option( ALTCRAFT_AI_OPTION, false ) ) {
			add_option( ALTCRAFT_AI_OPTION, AltCraft_Settings::defaults(), '', false );
		}

		AltCraft_Settings::flush_cache();
		AltCraft_Cron::maybe_schedule();

		update_option( 'altcraft_ai_version', ALTCRAFT_AI_VERSION, false );
	}
}
