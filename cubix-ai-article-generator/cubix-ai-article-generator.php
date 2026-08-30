<?php
/**
 * Cubix AI Article Generator
 *
 * @package           Cubix_AI_Article_Generator
 * @author            Aasim Ghaffar
 * @copyright         2026 Cubixsol
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       Cubix AI Article Generator
 * Description:       A premium AI writing suite for WordPress — generate titles, content, excerpts, outlines, SEO keywords and more from the post editor, or chat with an AI assistant in your dashboard. Works at zero cost with free-tier engines like Groq and Gemini.
 * Version:           1.0.2
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Cubixsol
 * Author URI:        https://cubixsol.com/
 * Text Domain:       cubix-ai-article-generator
 * Domain Path:       /languages
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin constants.
 */
define( 'CXAI_VERSION', '1.0.2' );
define( 'CXAI_PLUGIN_FILE', __FILE__ );
define( 'CXAI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CXAI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CXAI_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'CXAI_OPTION_KEY', 'cxai_settings' );
define( 'CXAI_STATS_KEY', 'cxai_stats' );

require_once CXAI_PLUGIN_DIR . 'includes/class-cxai-plugin.php';

/**
 * Seed default settings on activation without touching existing ones.
 *
 * @return void
 */
function cxai_activate() {
	if ( false === get_option( CXAI_OPTION_KEY ) ) {
		add_option(
			CXAI_OPTION_KEY,
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
}
register_activation_hook( __FILE__, 'cxai_activate' );

/**
 * Begin plugin execution.
 *
 * @return void
 */
function cxai_run() {
	CXAI_Plugin::get_instance()->run();
}
cxai_run();
