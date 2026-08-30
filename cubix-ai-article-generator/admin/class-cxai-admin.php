<?php
/**
 * Admin controller — menu registration and asset loading.
 *
 * @package Cubix_AI_Article_Generator
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CXAI_Admin
 */
class CXAI_Admin {

	/**
	 * Studio page slug (also the top-level menu slug).
	 */
	const STUDIO_SLUG = 'cxai-studio';

	/**
	 * Settings page slug.
	 */
	const SETTINGS_SLUG = 'cxai-settings';

	/**
	 * Hook suffixes for our admin pages.
	 *
	 * @var string[]
	 */
	private $page_hooks = array();

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
	 * Constructor.
	 *
	 * @param CXAI_Settings $settings Settings controller.
	 * @param CXAI_Studio   $studio   Studio controller.
	 */
	public function __construct( CXAI_Settings $settings, CXAI_Studio $studio ) {
		$this->settings = $settings;
		$this->studio   = $studio;
	}

	/**
	 * Register the admin menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		$capability = CXAI_Options::get_min_capability();

		$top = add_menu_page(
			__( 'Cubix AI', 'cubix-ai-article-generator' ),
			__( 'Cubix AI', 'cubix-ai-article-generator' ),
			$capability,
			self::STUDIO_SLUG,
			array( $this->studio, 'render_page' ),
			CXAI_Branding::menu_icon(),
			26
		);

		$studio = add_submenu_page(
			self::STUDIO_SLUG,
			__( 'AI Studio', 'cubix-ai-article-generator' ),
			__( 'AI Studio', 'cubix-ai-article-generator' ),
			$capability,
			self::STUDIO_SLUG,
			array( $this->studio, 'render_page' )
		);

		$settings = add_submenu_page(
			self::STUDIO_SLUG,
			__( 'Cubix AI Settings', 'cubix-ai-article-generator' ),
			__( 'Settings', 'cubix-ai-article-generator' ),
			'manage_options',
			self::SETTINGS_SLUG,
			array( $this->settings, 'render_page' )
		);

		$this->page_hooks = array_filter( array( $top, $studio, $settings ) );
	}

	/**
	 * Add a Settings link to the plugin row.
	 *
	 * @param string[] $links Existing action links.
	 * @return string[]
	 */
	public function add_action_links( $links ) {
		$custom = array(
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) ) . '">' . esc_html__( 'Settings', 'cubix-ai-article-generator' ) . '</a>',
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::STUDIO_SLUG ) ) . '">' . esc_html__( 'AI Studio', 'cubix-ai-article-generator' ) . '</a>',
		);

		return array_merge( $custom, $links );
	}

	/**
	 * Enqueue admin assets on plugin screens and enabled editors only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		$is_plugin_page = in_array( $hook_suffix, $this->page_hooks, true );
		$is_editor      = false;

		if ( in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			$screen    = get_current_screen();
			$is_editor = $screen && in_array( $screen->post_type, CXAI_Options::get_enabled_post_types(), true ) && CXAI_Options::current_user_can_use();
		}

		if ( ! $is_plugin_page && ! $is_editor ) {
			return;
		}

		// Version assets by file mtime so browser caches always refresh
		// after an update, even while the plugin version stays the same.
		$css_path = CXAI_PLUGIN_DIR . 'assets/css/admin.css';
		$js_path  = CXAI_PLUGIN_DIR . 'assets/js/admin.js';
		$css_ver  = CXAI_VERSION . '.' . ( file_exists( $css_path ) ? (string) filemtime( $css_path ) : '0' );
		$js_ver   = CXAI_VERSION . '.' . ( file_exists( $js_path ) ? (string) filemtime( $js_path ) : '0' );

		wp_enqueue_style( 'dashicons' );

		wp_enqueue_style(
			'cxai-admin',
			CXAI_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			$css_ver
		);

		wp_enqueue_script(
			'cxai-admin',
			CXAI_PLUGIN_URL . 'assets/js/admin.js',
			array(),
			$js_ver,
			true
		);

		$modes  = CXAI_Options::get_modes();
		$labels = array();

		foreach ( $modes as $slug => $mode ) {
			$labels[ $slug ] = $mode['label'];
		}

		wp_localize_script(
			'cxai-admin',
			'cxaiData',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( CXAI_Ajax::NONCE_ACTION ),
				'modeLabels' => $labels,
				'optionKey'  => CXAI_OPTION_KEY,
				'i18n'       => array(
					'testing'       => __( 'Testing…', 'cubix-ai-article-generator' ),
					'test'          => __( 'Test connection', 'cubix-ai-article-generator' ),
					'generating'    => __( 'Writing…', 'cubix-ai-article-generator' ),
					'generate'      => __( 'Generate', 'cubix-ai-article-generator' ),
					'copied'        => __( 'Copied', 'cubix-ai-article-generator' ),
					'copyFailed'    => __( 'Could not copy — select the text and copy it manually.', 'cubix-ai-article-generator' ),
					'emptyPrompt'   => __( 'Describe what you want first.', 'cubix-ai-article-generator' ),
					'genericError'  => __( 'Something went wrong. Try again.', 'cubix-ai-article-generator' ),
					'replaceAsk'    => __( 'Replace everything in the editor? Choose Cancel to add it to the end instead.', 'cubix-ai-article-generator' ),
					'titleSet'      => __( 'Title updated', 'cubix-ai-article-generator' ),
					'excerptSet'    => __( 'Excerpt updated', 'cubix-ai-article-generator' ),
					'contentSet'    => __( 'Added to the editor', 'cubix-ai-article-generator' ),
					'truncated'     => __( 'This reply hit the "Maximum response length" limit.', 'cubix-ai-article-generator' ),
				'truncatedLink' => __( 'Raise the limit', 'cubix-ai-article-generator' ),
				'settingsUrl'   => admin_url( 'admin.php?page=cxai-settings#writing' ),
				'continueReply' => __( 'Continue this answer', 'cubix-ai-article-generator' ),
				'continuePrompt' => __( 'Continue exactly where you stopped. Do not repeat anything already written.', 'cubix-ai-article-generator' ),
				'requestFailed' => __( 'That request did not go through', 'cubix-ai-article-generator' ),
				'retry'         => __( 'Try again', 'cubix-ai-article-generator' ),
				'importAsk'     => __( 'Import these settings? Your current settings will be replaced (saved API keys are kept).', 'cubix-ai-article-generator' ),
				'deleteChat'    => __( 'Delete this chat', 'cubix-ai-article-generator' ),
				'deleteChatAsk' => __( 'Delete this conversation? This cannot be undone.', 'cubix-ai-article-generator' ),
				'justNow'       => __( 'just now', 'cubix-ai-article-generator' ),
				'clearAsk'      => __( 'Delete your whole chat history? This cannot be undone.', 'cubix-ai-article-generator' ),
					'resetStatsAsk' => __( 'Reset all usage statistics for this site?', 'cubix-ai-article-generator' ),
					'statsReset'    => __( 'Statistics reset.', 'cubix-ai-article-generator' ),
					'exported'      => __( 'Settings copied to your clipboard.', 'cubix-ai-article-generator' ),
					'words'         => __( 'words', 'cubix-ai-article-generator' ),
					'noContext'     => __( 'The editor looks empty — there is nothing to work from yet.', 'cubix-ai-article-generator' ),
					'newTemplate'   => __( 'New template', 'cubix-ai-article-generator' ),
					'removeTpl'     => __( 'Remove template', 'cubix-ai-article-generator' ),
					'labelPh'       => __( 'Button label', 'cubix-ai-article-generator' ),
					'promptPh'      => __( 'Prompt text the button inserts', 'cubix-ai-article-generator' ),
				),
			)
		);
	}
}
