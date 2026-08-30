<?php
/**
 * Core plugin bootstrapper.
 *
 * @package Cubix_AI_Article_Generator
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CXAI_Plugin
 *
 * Single entry point that composes the plugin's components.
 */
final class CXAI_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var CXAI_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Admin controller.
	 *
	 * @var CXAI_Admin
	 */
	private $admin;

	/**
	 * Settings screen controller.
	 *
	 * @var CXAI_Settings
	 */
	private $settings;

	/**
	 * Studio screen controller.
	 *
	 * @var CXAI_Studio
	 */
	private $studio;

	/**
	 * Meta box controller.
	 *
	 * @var CXAI_Metabox
	 */
	private $metabox;

	/**
	 * AJAX controller.
	 *
	 * @var CXAI_Ajax
	 */
	private $ajax;

	/**
	 * Retrieve the singleton instance.
	 *
	 * @return CXAI_Plugin
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor. Loads dependencies and instantiates components.
	 */
	private function __construct() {
		$this->load_dependencies();

		$this->settings = new CXAI_Settings();
		$this->studio   = new CXAI_Studio();
		$this->admin    = new CXAI_Admin( $this->settings, $this->studio );
		$this->metabox  = new CXAI_Metabox();
		$this->ajax     = new CXAI_Ajax();
	}

	/**
	 * Require every class file used by the plugin.
	 *
	 * @return void
	 */
	private function load_dependencies() {
		require_once CXAI_PLUGIN_DIR . 'includes/class-cxai-branding.php';
		require_once CXAI_PLUGIN_DIR . 'includes/class-cxai-encryption.php';
		require_once CXAI_PLUGIN_DIR . 'includes/class-cxai-options.php';
		require_once CXAI_PLUGIN_DIR . 'includes/class-cxai-logger.php';
		require_once CXAI_PLUGIN_DIR . 'includes/class-cxai-prompt.php';
		require_once CXAI_PLUGIN_DIR . 'includes/api/class-cxai-api-base.php';
		require_once CXAI_PLUGIN_DIR . 'includes/api/class-cxai-openai-compatible.php';
		require_once CXAI_PLUGIN_DIR . 'includes/api/class-cxai-openrouter.php';
		require_once CXAI_PLUGIN_DIR . 'includes/api/class-cxai-claude.php';
		require_once CXAI_PLUGIN_DIR . 'includes/api/class-cxai-gemini.php';
		require_once CXAI_PLUGIN_DIR . 'includes/api/class-cxai-api-factory.php';
		require_once CXAI_PLUGIN_DIR . 'admin/class-cxai-settings.php';
		require_once CXAI_PLUGIN_DIR . 'admin/class-cxai-studio.php';
		require_once CXAI_PLUGIN_DIR . 'admin/class-cxai-admin.php';
		require_once CXAI_PLUGIN_DIR . 'admin/class-cxai-metabox.php';
		require_once CXAI_PLUGIN_DIR . 'admin/class-cxai-ajax.php';
	}

	/**
	 * Register all hooks with WordPress.
	 *
	 * @return void
	 */
	public function run() {
		// Translations.

		// Admin menu, settings, assets.
		add_action( 'admin_menu', array( $this->admin, 'register_menu' ) );
		add_action( 'admin_init', array( $this->settings, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this->admin, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . CXAI_PLUGIN_BASENAME, array( $this->admin, 'add_action_links' ) );

		// Editor panel.
		add_action( 'add_meta_boxes', array( $this->metabox, 'register_meta_box' ) );

		// AJAX endpoints (logged-in users only — no nopriv variants on purpose).
		add_action( 'wp_ajax_cxai_test_key', array( $this->ajax, 'handle_test_key' ) );
		add_action( 'wp_ajax_cxai_generate', array( $this->ajax, 'handle_generate' ) );
		add_action( 'wp_ajax_cxai_chat', array( $this->ajax, 'handle_chat' ) );
		add_action( 'wp_ajax_cxai_open_chat', array( $this->ajax, 'handle_open_chat' ) );
		add_action( 'wp_ajax_cxai_delete_chat', array( $this->ajax, 'handle_delete_chat' ) );
		add_action( 'wp_ajax_cxai_clear_chat', array( $this->ajax, 'handle_clear_chat' ) );
		add_action( 'wp_ajax_cxai_export_settings', array( $this->ajax, 'handle_export_settings' ) );
		add_action( 'wp_ajax_cxai_import_settings', array( $this->ajax, 'handle_import_settings' ) );
		add_action( 'wp_ajax_cxai_reset_stats', array( $this->ajax, 'handle_reset_stats' ) );
	}

	/**
	 * Prevent cloning.
	 */
	private function __clone() {}

	/**
	 * Prevent unserializing.
	 *
	 * @throws Exception Always.
	 */
	public function __wakeup() {
		throw new Exception( 'Cannot unserialize singleton.' );
	}
}
