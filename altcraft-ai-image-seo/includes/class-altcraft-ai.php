<?php
/**
 * The core plugin class.
 *
 * @package AltCraft_AI_Image_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads dependencies and registers hooks.
 */
final class AltCraft_AI {

	/**
	 * Singleton instance.
	 *
	 * @var AltCraft_AI|null
	 */
	private static $instance = null;

	/**
	 * Returns the singleton instance.
	 *
	 * @return AltCraft_AI
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->load_dependencies();
		$this->register_hooks();
	}

	/**
	 * Requires every class file.
	 *
	 * @return void
	 */
	private function load_dependencies() {
		$includes = array(
			'includes/class-altcraft-settings.php',
			'includes/class-altcraft-image.php',
			'includes/class-altcraft-context.php',
			'includes/class-altcraft-api.php',
			'includes/class-altcraft-generator.php',
			'includes/class-altcraft-webp.php',
			'includes/class-altcraft-stats.php',
			'includes/class-altcraft-cron.php',
			'includes/class-altcraft-media-hooks.php',
			'admin/class-altcraft-admin.php',
		);

		foreach ( $includes as $file ) {
			require_once ALTCRAFT_AI_PLUGIN_DIR . $file;
		}
	}

	/**
	 * Registers all hooks.
	 *
	 * @return void
	 */
	private function register_hooks() {
		$cron = new AltCraft_Cron();
		$cron->init();

		$stats = new AltCraft_Stats();
		$stats->init();

		$webp = new AltCraft_WebP();
		$webp->init();

		$media = new AltCraft_Media_Hooks();
		$media->init();

		if ( is_admin() ) {
			$admin = new AltCraft_Admin();
			$admin->init();
		}
	}
}
