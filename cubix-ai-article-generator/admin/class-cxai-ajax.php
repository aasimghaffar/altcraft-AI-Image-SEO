<?php
/**
 * AJAX endpoints.
 *
 * Every endpoint is admin-only (wp_ajax_*, never nopriv), verifies the
 * shared nonce delivered by wp_localize_script(), and enforces the
 * capability appropriate to the action.
 *
 * @package Cubix_AI_Article_Generator
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CXAI_Ajax
 */
class CXAI_Ajax {

	/**
	 * Nonce action shared by all plugin AJAX calls.
	 */
	const NONCE_ACTION = 'cxai_admin_nonce';

	/**
	 * Require the configured minimum capability.
	 *
	 * @return void
	 */
	private function require_access() {
		if ( ! CXAI_Options::current_user_can_use() ) {
			wp_send_json_error(
				array( 'message' => __( 'Your account does not have access to the AI generator.', 'cubix-ai-article-generator' ) ),
				403
			);
		}
	}

	/**
	 * Test an engine connection from the settings screen.
	 *
	 * @return void
	 */
	public function handle_test_key() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You are not allowed to manage these settings.', 'cubix-ai-article-generator' ) ),
				403
			);
		}

		$provider = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';
		$raw_key  = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
		$model    = isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : '';

		$client = CXAI_API_Factory::create( $provider, $raw_key, $model );

		if ( is_wp_error( $client ) ) {
			wp_send_json_error( array( 'message' => $client->get_error_message() ), 400 );
		}

		if ( ! $client->has_key() ) {
			wp_send_json_error(
				array( 'message' => __( 'Enter a key, or save one, before testing.', 'cubix-ai-article-generator' ) ),
				400
			);
		}

		$result = $client->test_connection();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success(
			array( 'message' => __( 'Connected — this engine is ready.', 'cubix-ai-article-generator' ) )
		);
	}

	/**
	 * Generate content from the editor panel.
	 *
	 * @return void
	 */
	public function handle_generate() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_access();

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You are not allowed to edit this post.', 'cubix-ai-article-generator' ) ),
				403
			);
		}

		$prompt   = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';
		$provider = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : CXAI_Options::get_default_provider();
		$mode     = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'content';
		$tone     = isset( $_POST['tone'] ) ? sanitize_key( wp_unslash( $_POST['tone'] ) ) : '';
		$length   = isset( $_POST['length'] ) ? sanitize_key( wp_unslash( $_POST['length'] ) ) : '';
		$context  = isset( $_POST['context'] ) ? sanitize_textarea_field( wp_unslash( $_POST['context'] ) ) : '';

		// Whitelist every selection against the canonical lists.
		$modes  = CXAI_Options::get_modes();
		$mode   = isset( $modes[ $mode ] ) ? $mode : 'content';
		$tone   = array_key_exists( $tone, CXAI_Options::get_tones() ) ? $tone : '';
		$length = array_key_exists( $length, CXAI_Options::get_lengths() ) ? $length : '';

		$needs_context = ! empty( $modes[ $mode ]['needs_context'] );

		if ( '' === trim( $prompt ) && ! $needs_context ) {
			wp_send_json_error(
				array( 'message' => __( 'Add a prompt describing what you want.', 'cubix-ai-article-generator' ) ),
				400
			);
		}

		if ( $needs_context && '' === trim( $context ) ) {
			wp_send_json_error(
				array( 'message' => __( 'This task works on your existing draft — tick the context box, or write something in the editor first.', 'cubix-ai-article-generator' ) ),
				400
			);
		}

		$settings = CXAI_Options::get_settings();

		$system = CXAI_Prompt::system( $mode, $tone, $length, $settings['language'] );
		$user   = CXAI_Prompt::user( $prompt, $mode, $context );

		/**
		 * Filter the system instruction.
		 *
		 * @param string $system   System instruction.
		 * @param string $mode     Generation mode.
		 * @param string $provider Provider slug.
		 */
		$system = apply_filters( 'cxai_system_prompt', $system, $mode, $provider );

		/**
		 * Filter the composed user prompt.
		 *
		 * @param string $user     Composed prompt.
		 * @param string $mode     Generation mode.
		 * @param int    $post_id  Post ID.
		 * @param string $provider Provider slug.
		 */
		$user = apply_filters( 'cxai_user_prompt', $user, $mode, $post_id, $provider );

		$client = CXAI_API_Factory::create( $provider );

		if ( is_wp_error( $client ) ) {
			CXAI_Studio::append_error( $user_id, $chat_id, $client->get_error_message(), $message );
			wp_send_json_error( array( 'message' => $client->get_error_message() ), 400 );
		}

		$client->set_system( $system );

		// English averages ~1.4 tokens per word; add headroom for markup so
		// a 900-word target is never truncated by a small default budget.
		$lengths = CXAI_Options::get_lengths();

		if ( isset( $lengths[ $length ]['words'] ) && $lengths[ $length ]['words'] > 0 ) {
			$client->ensure_max_tokens( (int) round( $lengths[ $length ]['words'] * 1.8 ) + 300 );
		}
		$content = $client->generate( $user );

		if ( is_wp_error( $content ) ) {
			wp_send_json_error( array( 'message' => $content->get_error_message() ), 400 );
		}

		/**
		 * Filter generated content before it reaches the editor.
		 *
		 * @param string $content  Generated text.
		 * @param string $mode     Generation mode.
		 * @param int    $post_id  Post ID.
		 * @param string $provider Provider slug.
		 */
		$content = apply_filters( 'cxai_generated_content', $content, $mode, $post_id, $provider );

		CXAI_Logger::record( $provider, $mode, $content );
		CXAI_Logger::add_history( get_current_user_id(), $mode, $prompt, $content );

		wp_send_json_success(
			array(
				'mode'      => $mode,
				'raw'       => $content,
				'html'      => wp_kses_post( wpautop( $content ) ),
				'words'     => str_word_count( wp_strip_all_tags( $content ) ),
				'truncated' => $client->was_truncated(),
			)
		);
	}

	/**
	 * Handle a chat message from the AI Studio.
	 *
	 * Creates a new conversation when no chat_id is supplied, so the
	 * client learns the new id (and title) from the response.
	 *
	 * @return void
	 */
	public function handle_chat() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_access();

		$message  = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
		$provider = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : CXAI_Options::get_default_provider();
		$chat_id  = isset( $_POST['chat_id'] ) ? sanitize_key( wp_unslash( $_POST['chat_id'] ) ) : '';
		$is_retry = isset( $_POST['retry'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['retry'] ) );

		if ( '' === trim( $message ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Type a message first.', 'cubix-ai-article-generator' ) ),
				400
			);
		}

		$user_id = get_current_user_id();
		$is_new  = false;

		if ( '' === $chat_id || null === CXAI_Studio::get_chat( $user_id, $chat_id ) ) {
			$chat_id = CXAI_Studio::create_chat( $user_id, $message );
			$is_new  = true;
		}

		CXAI_Studio::set_active( $user_id, $chat_id );

		if ( $is_retry ) {
			// The prompt is already stored from the failed attempt; just
			// clear the error entries it left behind.
			CXAI_Studio::remove_trailing_errors( $user_id, $chat_id );
			$chat    = CXAI_Studio::get_chat( $user_id, $chat_id );
			$history = $chat ? $chat['messages'] : array();
		} else {
			$history = CXAI_Studio::append_message( $user_id, $chat_id, 'user', $message );
		}

		// Send only the recent context window to the engine, excluding
		// stored error entries — they are UI records, not conversation.
		$context = array();

		foreach ( array_slice( $history, -CXAI_Studio::CONTEXT_WINDOW ) as $item ) {
			if ( 'error' === $item['role'] ) {
				continue;
			}

			$context[] = array(
				'role'    => $item['role'],
				'content' => $item['content'],
			);
		}

		$client = CXAI_API_Factory::create( $provider );

		if ( is_wp_error( $client ) ) {
			CXAI_Studio::append_error( $user_id, $chat_id, $client->get_error_message(), $message );
			wp_send_json_error( array( 'message' => $client->get_error_message() ), 400 );
		}

		$client->set_system( __( 'You are a helpful writing assistant inside a WordPress dashboard. Match the length and depth the user asks for: if they request a specific number of points, sections or an FAQ, produce every one of them in full and never stop part-way. Keep short questions short, but never abbreviate a piece the user asked to be detailed. Use plain formatting.', 'cubix-ai-article-generator' ) );

		// Chat answers are often long-form; make sure the budget can hold a
		// useful chunk even when the site setting is very low.
		$client->ensure_max_tokens( 1536 );

		$reply = $client->complete( $context );

		if ( is_wp_error( $reply ) ) {
			// Store the failure so it survives a reload until it is retried.
			CXAI_Studio::append_error( $user_id, $chat_id, $reply->get_error_message(), $message );
			wp_send_json_error( array( 'message' => $reply->get_error_message() ), 400 );
		}

		CXAI_Studio::append_message( $user_id, $chat_id, 'assistant', $reply );
		CXAI_Logger::record( $provider, 'chat', $reply );

		$chat = CXAI_Studio::get_chat( $user_id, $chat_id );

		wp_send_json_success(
			array(
				'raw'       => $reply,
				'html'      => wp_kses_post( wpautop( $reply ) ),
				'chat_id'   => $chat_id,
				'is_new'    => $is_new,
				'title'     => $chat ? $chat['title'] : '',
				'truncated' => $client->was_truncated(),
			)
		);
	}

	/**
	 * Open a conversation (or start fresh) and return its messages.
	 *
	 * @return void
	 */
	public function handle_open_chat() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_access();

		$chat_id = isset( $_POST['chat_id'] ) ? sanitize_key( wp_unslash( $_POST['chat_id'] ) ) : '';
		$user_id = get_current_user_id();

		if ( '' === $chat_id ) {
			CXAI_Studio::set_active( $user_id, '' );
			wp_send_json_success( array( 'messages' => array() ) );
		}

		$chat = CXAI_Studio::get_chat( $user_id, $chat_id );

		if ( null === $chat ) {
			wp_send_json_error(
				array( 'message' => __( 'That conversation no longer exists.', 'cubix-ai-article-generator' ) ),
				404
			);
		}

		CXAI_Studio::set_active( $user_id, $chat_id );

		wp_send_json_success( array( 'messages' => CXAI_Studio::format_messages( $chat ) ) );
	}

	/**
	 * Delete one conversation.
	 *
	 * @return void
	 */
	public function handle_delete_chat() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_access();

		$chat_id = isset( $_POST['chat_id'] ) ? sanitize_key( wp_unslash( $_POST['chat_id'] ) ) : '';

		if ( '' === $chat_id ) {
			wp_send_json_error(
				array( 'message' => __( 'No conversation specified.', 'cubix-ai-article-generator' ) ),
				400
			);
		}

		CXAI_Studio::delete_chat( get_current_user_id(), $chat_id );

		wp_send_json_success( array( 'message' => __( 'Conversation deleted.', 'cubix-ai-article-generator' ) ) );
	}

	/**
	 * Delete every conversation for the current user.
	 *
	 * @return void
	 */
	public function handle_clear_chat() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$this->require_access();

		CXAI_Studio::delete_all_chats( get_current_user_id() );

		wp_send_json_success( array( 'message' => __( 'All conversations deleted.', 'cubix-ai-article-generator' ) ) );
	}

	/**
	 * Export saved settings as JSON, with all API keys stripped.
	 *
	 * @return void
	 */
	public function handle_export_settings() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You are not allowed to manage these settings.', 'cubix-ai-article-generator' ) ),
				403
			);
		}

		$settings = CXAI_Options::get_settings();

		// Keys never leave the site — not even encrypted.
		if ( ! empty( $settings['providers'] ) && is_array( $settings['providers'] ) ) {
			foreach ( $settings['providers'] as $slug => $provider ) {
				unset( $settings['providers'][ $slug ]['api_key'] );
			}
		}

		wp_send_json_success(
			array( 'json' => wp_json_encode( $settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) )
		);
	}

	/**
	 * Import settings JSON exported from another site.
	 *
	 * The payload runs through the exact same sanitizer as the settings
	 * form, and because exports contain no api_key fields, keys already
	 * saved on this site are preserved untouched.
	 *
	 * @return void
	 */
	public function handle_import_settings() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You are not allowed to manage these settings.', 'cubix-ai-article-generator' ) ),
				403
			);
		}

		$payload = isset( $_POST['payload'] ) ? wp_unslash( $_POST['payload'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON is decoded and every field passes through the settings sanitizer below.
		$decoded = json_decode( $payload, true );

		if ( ! is_array( $decoded ) || empty( $decoded ) ) {
			wp_send_json_error(
				array( 'message' => __( 'That is not valid settings JSON. Paste the exact text produced by the Export button.', 'cubix-ai-article-generator' ) ),
				400
			);
		}

		// Never accept keys through import, even hand-crafted ones.
		if ( ! empty( $decoded['providers'] ) && is_array( $decoded['providers'] ) ) {
			foreach ( $decoded['providers'] as $slug => $provider ) {
				unset( $decoded['providers'][ $slug ]['api_key'], $decoded['providers'][ $slug ]['remove'] );
			}
		}

		$settings_page = new CXAI_Settings();
		$sanitized     = $settings_page->sanitize_settings( $decoded );

		update_option( CXAI_OPTION_KEY, $sanitized );

		wp_send_json_success(
			array( 'message' => __( 'Settings imported. Reloading…', 'cubix-ai-article-generator' ) )
		);
	}

	/**
	 * Reset usage statistics.
	 *
	 * @return void
	 */
	public function handle_reset_stats() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You are not allowed to manage these settings.', 'cubix-ai-article-generator' ) ),
				403
			);
		}

		CXAI_Logger::reset_stats();

		wp_send_json_success( array( 'message' => __( 'Statistics reset.', 'cubix-ai-article-generator' ) ) );
	}
}
