<?php
/**
 * Uninstall cleanup.
 *
 * Runs only when the plugin is deleted through the WordPress admin.
 * Respects the "erase on delete" setting: when the site owner turned it
 * off, everything is left in place for a future reinstall.
 *
 * @package Cubix_AI_Article_Generator
 */

// Exit if not called by WordPress during uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Delete all plugin data for the current site (if allowed).
 *
 * @return void
 */
function cxai_uninstall_site() {
	$settings = get_option( 'cxai_settings' );

	// Honour the opt-out: keep data unless erase_on_delete is enabled.
	if ( is_array( $settings ) && empty( $settings['erase_on_delete'] ) ) {
		return;
	}

	delete_option( 'cxai_settings' );
	delete_option( 'cxai_stats' );
	delete_metadata( 'user', 0, 'cxai_chat_history', '', true );
	delete_metadata( 'user', 0, 'cxai_chats', '', true );
	delete_metadata( 'user', 0, 'cxai_active_chat', '', true );
	delete_metadata( 'user', 0, 'cxai_history', '', true );
}

cxai_uninstall_site();

// Multisite: remove data from every site.
if ( is_multisite() ) {
	$cxai_site_ids = get_sites( array( 'fields' => 'ids' ) );

	foreach ( $cxai_site_ids as $cxai_site_id ) {
		switch_to_blog( $cxai_site_id );
		cxai_uninstall_site();
		restore_current_blog();
	}
}
