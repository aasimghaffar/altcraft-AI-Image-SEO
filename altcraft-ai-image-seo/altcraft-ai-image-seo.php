<?php
/**
 * Plugin bootstrap file.
 *
 * @link              https://cubixsol.com/products/
 * @since             1.0.0
 * @package           AltCraft_AI_Image_SEO
 *
 * @wordpress-plugin
 * Plugin Name:       AltCraft AI – Image SEO & Auto Alt Text Generator
 * Plugin URI:        https://cubixsol.com/products/
 * Description:       AI-written image ALT text, titles and captions (Google Gemini or OpenAI) with a Media SEO table, bulk scanner, nightly cron, WebP copies and WooCommerce context.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Cubixsol
 * Author URI:        https://cubixsol.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       altcraft-ai-image-seo
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ALTCRAFT_AI_VERSION', '1.0.0' );
define( 'ALTCRAFT_AI_PLUGIN_FILE', __FILE__ );
define( 'ALTCRAFT_AI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ALTCRAFT_AI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ALTCRAFT_AI_BASENAME', plugin_basename( __FILE__ ) );
define( 'ALTCRAFT_AI_OPTION', 'altcraft_ai_settings' );
define( 'ALTCRAFT_AI_CRON_HOOK', 'altcraft_daily_cron_hook' );

/**
 * Runs on plugin activation.
 *
 * @param bool $network_wide Whether the plugin is being network-activated.
 * @return void
 */
function altcraft_ai_activate( $network_wide ) {
	require_once ALTCRAFT_AI_PLUGIN_DIR . 'includes/class-altcraft-settings.php';
	require_once ALTCRAFT_AI_PLUGIN_DIR . 'includes/class-altcraft-cron.php';
	require_once ALTCRAFT_AI_PLUGIN_DIR . 'includes/class-altcraft-activator.php';
	AltCraft_Activator::activate( $network_wide );
}

/**
 * Runs on plugin deactivation.
 *
 * @param bool $network_wide Whether the plugin is being network-deactivated.
 * @return void
 */
function altcraft_ai_deactivate( $network_wide ) {
	require_once ALTCRAFT_AI_PLUGIN_DIR . 'includes/class-altcraft-deactivator.php';
	AltCraft_Deactivator::deactivate( $network_wide );
}

register_activation_hook( __FILE__, 'altcraft_ai_activate' );
register_deactivation_hook( __FILE__, 'altcraft_ai_deactivate' );

require_once ALTCRAFT_AI_PLUGIN_DIR . 'includes/class-altcraft-ai.php';

/**
 * Boots the plugin.
 *
 * @return AltCraft_AI
 */
function altcraft_ai() {
	return AltCraft_AI::instance();
}

altcraft_ai();
