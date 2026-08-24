<?php
/**
 * Admin screens and AJAX endpoints.
 *
 * @package AltCraft_AI_Image_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin functionality.
 */
class AltCraft_Admin {

	/**
	 * Nonce action shared by every AJAX request.
	 */
	const NONCE = 'altcraft_ajax_nonce';

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menus' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . ALTCRAFT_AI_BASENAME, array( $this, 'action_links' ) );

		add_action( 'wp_ajax_altcraft_generate_single_alt', array( $this, 'ajax_generate_single_alt' ) );
		add_action( 'wp_ajax_altcraft_save_inline_seo', array( $this, 'ajax_save_inline_seo' ) );
		add_action( 'wp_ajax_altcraft_fetch_unoptimized_ids', array( $this, 'ajax_fetch_queue' ) );
		add_action( 'wp_ajax_altcraft_process_batch_item', array( $this, 'ajax_process_batch_item' ) );
		add_action( 'wp_ajax_altcraft_test_connection', array( $this, 'ajax_test_connection' ) );
	}

	/**
	 * Adds the top-level menu and its submenus.
	 *
	 * @return void
	 */
	public function register_menus() {
		$icon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#a7aaad" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline><path d="M19 2v4m2-2h-4"></path></svg>';

		add_menu_page(
			__( 'AltCraft AI Image SEO', 'altcraft-ai-image-seo' ),
			__( 'AltCraft AI', 'altcraft-ai-image-seo' ),
			'manage_options',
			'altcraft-ai-settings',
			array( $this, 'render_settings_page' ),
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Inline SVG data URI for the admin menu icon.
			'data:image/svg+xml;base64,' . base64_encode( $icon ),
			57
		);

		add_submenu_page(
			'altcraft-ai-settings',
			__( 'Settings & API – AltCraft AI', 'altcraft-ai-image-seo' ),
			__( 'Settings & API', 'altcraft-ai-image-seo' ),
			'manage_options',
			'altcraft-ai-settings',
			array( $this, 'render_settings_page' )
		);

		add_submenu_page(
			'altcraft-ai-settings',
			__( 'Media SEO Table – AltCraft AI', 'altcraft-ai-image-seo' ),
			__( 'Media SEO Table', 'altcraft-ai-image-seo' ),
			'manage_options',
			'altcraft-ai-media-table',
			array( $this, 'render_media_table_page' )
		);

		add_submenu_page(
			'altcraft-ai-settings',
			__( 'Bulk Scanner – AltCraft AI', 'altcraft-ai-image-seo' ),
			__( 'Bulk Scanner', 'altcraft-ai-image-seo' ),
			'manage_options',
			'altcraft-ai-scanner',
			array( $this, 'render_scanner_page' )
		);
	}

	/**
	 * Registers the settings option.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'altcraft_options_group',
			ALTCRAFT_AI_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'AltCraft_Settings', 'sanitize' ),
				'default'           => AltCraft_Settings::defaults(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Whether the current admin screen needs the plugin assets.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return bool
	 */
	private function needs_assets( $hook ) {
		$hooks = array( 'upload.php', 'post.php', 'post-new.php', 'widgets.php', 'site-editor.php' );

		/**
		 * Filters the admin screens (hook suffixes) that load the AltCraft assets, in addition to the plugin pages.
		 *
		 * @param array $hooks Hook suffixes.
		 */
		$hooks = (array) apply_filters( 'altcraft_ai_admin_asset_hooks', $hooks );

		return ( false !== strpos( (string) $hook, 'altcraft-ai' ) ) || in_array( $hook, $hooks, true );
	}

	/**
	 * Enqueues admin CSS/JS.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( ! $this->needs_assets( $hook ) ) {
			return;
		}

		wp_enqueue_style(
			'altcraft-ai-admin',
			ALTCRAFT_AI_PLUGIN_URL . 'admin/css/altcraft-admin.css',
			array(),
			ALTCRAFT_AI_VERSION
		);

		wp_enqueue_script(
			'altcraft-ai-admin',
			ALTCRAFT_AI_PLUGIN_URL . 'admin/js/altcraft-admin.js',
			array( 'jquery' ),
			ALTCRAFT_AI_VERSION,
			true
		);

		wp_localize_script(
			'altcraft-ai-admin',
			'altcraftData',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( self::NONCE ),
				'canBulk'  => current_user_can( 'manage_options' ),
				'settings' => array(
					'syncTitle'   => AltCraft_Settings::is_on( 'sync_title' ),
					'syncCaption' => AltCraft_Settings::is_on( 'sync_caption' ),
				),
				'i18n'     => array(
					'generating'   => __( 'Generating…', 'altcraft-ai-image-seo' ),
					'generate'     => __( 'Generate', 'altcraft-ai-image-seo' ),
					'regenerate'   => __( 'Regenerate', 'altcraft-ai-image-seo' ),
					'saving'       => __( 'Saving…', 'altcraft-ai-image-seo' ),
					'saved'        => __( 'Saved', 'altcraft-ai-image-seo' ),
					'save'         => __( 'Save', 'altcraft-ai-image-seo' ),
					'retry'        => __( 'Retry', 'altcraft-ai-image-seo' ),
					'done'         => __( 'Done', 'altcraft-ai-image-seo' ),
					'error'        => __( 'Error', 'altcraft-ai-image-seo' ),
					'networkError' => __( 'Network error. Please try again.', 'altcraft-ai-image-seo' ),
					'optimized'    => __( 'Optimized', 'altcraft-ai-image-seo' ),
					'missing'      => __( 'Missing ALT', 'altcraft-ai-image-seo' ),
					'testing'      => __( 'Testing connection…', 'altcraft-ai-image-seo' ),
					'testOk'       => __( 'Connection successful. The model responded.', 'altcraft-ai-image-seo' ),
					'scanning'     => __( 'Scanning the media library…', 'altcraft-ai-image-seo' ),
					/* translators: %d: number of images */
					'found'        => __( 'Found %d image(s) to process.', 'altcraft-ai-image-seo' ),
					'nothingToDo'  => __( 'Nothing to do – every image already has ALT text.', 'altcraft-ai-image-seo' ),
					/* translators: %d: seconds */
					'rateLimited'  => __( 'Rate limit reached – waiting %d seconds before continuing…', 'altcraft-ai-image-seo' ),
					'stopped'      => __( 'Stopped by user.', 'altcraft-ai-image-seo' ),
					'retrying'     => __( 'Retrying once…', 'altcraft-ai-image-seo' ),
					/* translators: 1: processed count, 2: success count, 3: failed count */
					'finished'     => __( 'Finished. Processed %1$d image(s): %2$d optimized, %3$d failed.', 'altcraft-ai-image-seo' ),
					'skipped'      => __( 'Skipped (already has ALT text)', 'altcraft-ai-image-seo' ),
					'start'        => __( 'Start Bulk Optimization', 'altcraft-ai-image-seo' ),
					'stop'         => __( 'Stop', 'altcraft-ai-image-seo' ),
					'confirmAll'   => __( 'This will overwrite the ALT text of EVERY image in your media library. Continue?', 'altcraft-ai-image-seo' ),
					'fatalStop'    => __( 'Stopping: this error will affect every image. Fix it in Settings and start again.', 'altcraft-ai-image-seo' ),
				),
			)
		);
	}

	/**
	 * Adds Settings / Media SEO Table links on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function action_links( $links ) {
		$custom = array(
			'<a href="' . esc_url( admin_url( 'admin.php?page=altcraft-ai-settings' ) ) . '">' . esc_html__( 'Settings', 'altcraft-ai-image-seo' ) . '</a>',
			'<a href="' . esc_url( admin_url( 'admin.php?page=altcraft-ai-media-table' ) ) . '">' . esc_html__( 'Media SEO Table', 'altcraft-ai-image-seo' ) . '</a>',
		);
		return array_merge( $custom, $links );
	}

	/**
	 * Renders the settings page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$options        = AltCraft_Settings::get( true );
		$stats          = AltCraft_Stats::get();
		$webp_supported = AltCraft_WebP::is_supported();
		$capabilities   = AltCraft_Image::server_capabilities();
		$last_cron      = get_option( 'altcraft_ai_last_cron', array() );
		$next_cron      = wp_next_scheduled( ALTCRAFT_AI_CRON_HOOK );
		$has_key        = '' !== AltCraft_Settings::get_api_key();

		require ALTCRAFT_AI_PLUGIN_DIR . 'admin/partials/altcraft-admin-settings.php';
	}

	/**
	 * Renders the media table page.
	 *
	 * @return void
	 */
	public function render_media_table_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$stats   = AltCraft_Stats::get();
		$has_key = '' !== AltCraft_Settings::get_api_key();

		require ALTCRAFT_AI_PLUGIN_DIR . 'admin/partials/altcraft-admin-table.php';
	}

	/**
	 * Renders the bulk scanner page.
	 *
	 * @return void
	 */
	public function render_scanner_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$stats   = AltCraft_Stats::get();
		$has_key = '' !== AltCraft_Settings::get_api_key();

		require ALTCRAFT_AI_PLUGIN_DIR . 'admin/partials/altcraft-admin-scanner.php';
	}

	/**
	 * Reads and validates the attachment ID from the request.
	 *
	 * @return int Attachment ID (0 when invalid).
	 */
	private function request_attachment_id() {
		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( wp_unslash( $_POST['attachment_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified by the calling handler.

		if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
			return 0;
		}

		return $attachment_id;
	}

	/**
	 * Sends a WP_Error as a JSON error response.
	 *
	 * @param WP_Error $error Error.
	 * @return void
	 */
	private function send_error( $error ) {
		$data = $error->get_error_data();
		wp_send_json_error(
			array(
				'code'        => $error->get_error_code(),
				'message'     => $error->get_error_message(),
				'retry_after' => ( is_array( $data ) && ! empty( $data['retry_after'] ) ) ? (int) $data['retry_after'] : 0,
			)
		);
	}

	/**
	 * AJAX: generate ALT text for one image (media table, list column, media modal).
	 *
	 * @return void
	 */
	public function ajax_generate_single_alt() {
		check_ajax_referer( self::NONCE, 'nonce' );

		$attachment_id = $this->request_attachment_id();
		if ( ! $attachment_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid attachment ID.', 'altcraft-ai-image-seo' ) ) );
		}

		if ( ! AltCraft_Media_Hooks::user_can_generate( $attachment_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to edit this image.', 'altcraft-ai-image-seo' ) ), 403 );
		}

		$result = AltCraft_Generator::generate( $attachment_id, array( 'force' => true ) );
		if ( is_wp_error( $result ) ) {
			$this->send_error( $result );
		}

		wp_send_json_success(
			array(
				'id'       => $attachment_id,
				'alt_text' => $result['alt'],
				'title'    => $result['title'],
				'caption'  => $result['caption'],
				'message'  => __( 'ALT text generated.', 'altcraft-ai-image-seo' ),
			)
		);
	}

	/**
	 * AJAX: save inline edits from the media table.
	 *
	 * @return void
	 */
	public function ajax_save_inline_seo() {
		check_ajax_referer( self::NONCE, 'nonce' );

		$attachment_id = $this->request_attachment_id();
		if ( ! $attachment_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid attachment ID.', 'altcraft-ai-image-seo' ) ) );
		}

		if ( ! AltCraft_Media_Hooks::user_can_generate( $attachment_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to edit this image.', 'altcraft-ai-image-seo' ) ), 403 );
		}

		$alt_text = isset( $_POST['alt_text'] ) ? sanitize_text_field( wp_unslash( $_POST['alt_text'] ) ) : '';
		$title    = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$caption  = isset( $_POST['caption'] ) ? sanitize_textarea_field( wp_unslash( $_POST['caption'] ) ) : '';

		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );

		$post_data = array(
			'ID'           => $attachment_id,
			'post_excerpt' => $caption,
		);
		if ( '' !== $title ) {
			$post_data['post_title'] = $title;
		}
		wp_update_post( wp_slash( $post_data ) );

		AltCraft_Stats::invalidate();

		wp_send_json_success(
			array(
				'id'      => $attachment_id,
				'status'  => '' !== trim( $alt_text ) ? 'optimized' : 'missing',
				'message' => __( 'Saved.', 'altcraft-ai-image-seo' ),
			)
		);
	}

	/**
	 * AJAX: returns the next batch of attachment IDs for the bulk scanner.
	 *
	 * @return void
	 */
	public function ajax_fetch_queue() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'altcraft-ai-image-seo' ) ), 403 );
		}

		$mode    = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'missing';
		$mode    = ( 'all' === $mode ) ? 'all' : 'missing';
		$exclude = array();

		if ( isset( $_POST['exclude'] ) ) {
			$raw = wp_unslash( $_POST['exclude'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized as integers below.
			if ( is_array( $raw ) ) {
				$exclude = array_filter( array_map( 'absint', $raw ) );
			} elseif ( is_string( $raw ) && '' !== $raw ) {
				$exclude = array_filter( array_map( 'absint', explode( ',', $raw ) ) );
			}
		}

		$after_id = isset( $_POST['after_id'] ) ? absint( wp_unslash( $_POST['after_id'] ) ) : 0;
		$queue    = AltCraft_Stats::get_queue( $mode, 100, array_slice( $exclude, 0, 2000 ), $after_id );

		wp_send_json_success(
			array(
				'ids'   => $queue['ids'],
				'total' => $queue['total'],
			)
		);
	}

	/**
	 * AJAX: processes one item of the bulk queue.
	 *
	 * @return void
	 */
	public function ajax_process_batch_item() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'altcraft-ai-image-seo' ) ), 403 );
		}

		$attachment_id = $this->request_attachment_id();
		if ( ! $attachment_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid attachment ID.', 'altcraft-ai-image-seo' ) ) );
		}

		$mode   = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'missing';
		$result = AltCraft_Generator::generate( $attachment_id, array( 'force' => ( 'all' === $mode ) ) );

		if ( is_wp_error( $result ) ) {
			// Items without a usable image file are reported as skipped rather than failed.
			if ( in_array( $result->get_error_code(), array( 'no_image_file', 'unreadable_image', 'not_an_image', 'no_context' ), true ) ) {
				wp_send_json_success(
					array(
						'id'      => $attachment_id,
						'skipped' => true,
						'reason'  => $result->get_error_message(),
					)
				);
			}
			$this->send_error( $result );
		}

		wp_send_json_success(
			array(
				'id'       => $attachment_id,
				'alt_text' => $result['alt'],
				'skipped'  => ! empty( $result['skipped'] ),
			)
		);
	}

	/**
	 * AJAX: verifies the API key and model from the (unsaved) settings form.
	 *
	 * @return void
	 */
	public function ajax_test_connection() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'altcraft-ai-image-seo' ) ), 403 );
		}

		$provider = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : 'gemini';
		$provider = array_key_exists( $provider, AltCraft_Settings::providers() ) ? $provider : 'gemini';
		$api_key  = isset( $_POST['api_key'] ) ? preg_replace( '/[^A-Za-z0-9_\-\.]/', '', trim( wp_unslash( $_POST['api_key'] ) ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized with an allow-list regex.
		$model    = isset( $_POST['model'] ) ? preg_replace( '/[^A-Za-z0-9_\-\.:]/', '', sanitize_text_field( wp_unslash( $_POST['model'] ) ) ) : '';

		if ( '' === $api_key ) {
			$api_key = AltCraft_Settings::get_api_key( $provider );
		}
		if ( '' === $model ) {
			$model = AltCraft_Settings::get_model( $provider );
		}

		if ( '' === $api_key ) {
			wp_send_json_error( array( 'message' => __( 'Enter an API key first.', 'altcraft-ai-image-seo' ) ) );
		}

		$result = AltCraft_API::test_connection( $provider, $model, $api_key );
		if ( is_wp_error( $result ) ) {
			$this->send_error( $result );
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: model ID, 2: seconds, 3: description returned by the model */
					__( 'Connection successful – %1$s described a test image in %2$ss: “%3$s”', 'altcraft-ai-image-seo' ),
					$model,
					$result['seconds'],
					$result['alt']
				),
			)
		);
	}
}
