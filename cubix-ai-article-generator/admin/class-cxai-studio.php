<?php
/**
 * AI Studio — multi-conversation chat workspace.
 *
 * Conversations are stored per user in user meta as a keyed array of
 * chats, each with its own title and message list. The user can start
 * new chats, reopen old ones, delete one, or delete all.
 *
 * @package Cubix_AI_Article_Generator
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CXAI_Studio
 */
class CXAI_Studio {

	/**
	 * User meta key holding all chats.
	 */
	const CHATS_META = 'cxai_chats';

	/**
	 * User meta key holding the active chat id.
	 */
	const ACTIVE_META = 'cxai_active_chat';

	/**
	 * Legacy single-conversation meta (migrated on first load).
	 */
	const LEGACY_META = 'cxai_chat_history';

	/**
	 * Maximum stored chats per user (oldest pruned first).
	 */
	const CHATS_CAP = 20;

	/**
	 * Maximum messages kept per chat.
	 */
	const MESSAGES_CAP = 80;

	/**
	 * Messages of context sent to the engine per request.
	 */
	const CONTEXT_WINDOW = 14;

	/**
	 * Conversations older than this many days are deleted automatically.
	 */
	const RETENTION_DAYS = 10;

	/**
	 * Load all chats for a user, newest first. Migrates legacy history.
	 *
	 * @param int $user_id User ID.
	 * @return array<string, array{title: string, created: int, updated: int, messages: array}>
	 */
	public static function get_chats( $user_id ) {
		$chats = get_user_meta( $user_id, self::CHATS_META, true );
		$chats = is_array( $chats ) ? $chats : array();

		// Retention: silently drop conversations not touched for 10 days.
		$cutoff  = time() - ( self::RETENTION_DAYS * DAY_IN_SECONDS );
		$changed = false;

		foreach ( $chats as $id => $chat ) {
			if ( (int) $chat['updated'] < $cutoff ) {
				unset( $chats[ $id ] );
				$changed = true;
			}
		}

		if ( $changed ) {
			update_user_meta( $user_id, self::CHATS_META, $chats );
		}

		// One-time migration of the old single conversation.
		if ( empty( $chats ) ) {
			$legacy = get_user_meta( $user_id, self::LEGACY_META, true );

			if ( is_array( $legacy ) && ! empty( $legacy ) ) {
				$id           = self::new_id();
				$chats[ $id ] = array(
					'title'    => __( 'Earlier conversation', 'cubix-ai-article-generator' ),
					'created'  => isset( $legacy[0]['time'] ) ? (int) $legacy[0]['time'] : time(),
					'updated'  => time(),
					'messages' => $legacy,
				);
				update_user_meta( $user_id, self::CHATS_META, $chats );
				delete_user_meta( $user_id, self::LEGACY_META );
			}
		}

		uasort(
			$chats,
			static function ( $a, $b ) {
				return (int) $b['updated'] <=> (int) $a['updated'];
			}
		);

		return $chats;
	}

	/**
	 * Load one chat.
	 *
	 * @param int    $user_id User ID.
	 * @param string $chat_id Chat id.
	 * @return array|null
	 */
	public static function get_chat( $user_id, $chat_id ) {
		$chats = self::get_chats( $user_id );

		return isset( $chats[ $chat_id ] ) ? $chats[ $chat_id ] : null;
	}

	/**
	 * Create a chat titled after the first message.
	 *
	 * @param int    $user_id       User ID.
	 * @param string $first_message First user message.
	 * @return string New chat id.
	 */
	public static function create_chat( $user_id, $first_message ) {
		$chats = self::get_chats( $user_id );
		$id    = self::new_id();

		$title = wp_trim_words( $first_message, 6, '…' );

		$chats[ $id ] = array(
			'title'    => ( '' !== $title ) ? $title : __( 'New chat', 'cubix-ai-article-generator' ),
			'created'  => time(),
			'updated'  => time(),
			'messages' => array(),
		);

		// Prune the oldest chats beyond the cap.
		if ( count( $chats ) > self::CHATS_CAP ) {
			uasort(
				$chats,
				static function ( $a, $b ) {
					return (int) $b['updated'] <=> (int) $a['updated'];
				}
			);
			$chats = array_slice( $chats, 0, self::CHATS_CAP, true );
		}

		update_user_meta( $user_id, self::CHATS_META, $chats );
		update_user_meta( $user_id, self::ACTIVE_META, $id );

		return $id;
	}

	/**
	 * Append a message to a chat.
	 *
	 * @param int    $user_id User ID.
	 * @param string $chat_id Chat id.
	 * @param string $role    'user' or 'assistant'.
	 * @param string $content Message text.
	 * @return array The chat's messages after appending.
	 */
	public static function append_message( $user_id, $chat_id, $role, $content ) {
		$chats = self::get_chats( $user_id );

		if ( ! isset( $chats[ $chat_id ] ) ) {
			return array();
		}

		$chats[ $chat_id ]['messages'][] = array(
			'role'    => ( 'assistant' === $role ) ? 'assistant' : 'user',
			'content' => $content,
			'time'    => time(),
		);

		if ( count( $chats[ $chat_id ]['messages'] ) > self::MESSAGES_CAP ) {
			$chats[ $chat_id ]['messages'] = array_slice( $chats[ $chat_id ]['messages'], -self::MESSAGES_CAP );
		}

		$chats[ $chat_id ]['updated'] = time();

		update_user_meta( $user_id, self::CHATS_META, $chats );

		return $chats[ $chat_id ]['messages'];
	}

	/**
	 * Append a failed request so it survives a page reload.
	 *
	 * @param int    $user_id User ID.
	 * @param string $chat_id Chat id.
	 * @param string $message Error text.
	 * @param string $prompt  The prompt that failed, for retrying.
	 * @return void
	 */
	public static function append_error( $user_id, $chat_id, $message, $prompt ) {
		$chats = self::get_chats( $user_id );

		if ( ! isset( $chats[ $chat_id ] ) ) {
			return;
		}

		$chats[ $chat_id ]['messages'][] = array(
			'role'    => 'error',
			'content' => $message,
			'prompt'  => $prompt,
			'time'    => time(),
		);

		$chats[ $chat_id ]['updated'] = time();

		update_user_meta( $user_id, self::CHATS_META, $chats );
	}

	/**
	 * Drop trailing error entries (called when a retry succeeds).
	 *
	 * @param int    $user_id User ID.
	 * @param string $chat_id Chat id.
	 * @return void
	 */
	public static function remove_trailing_errors( $user_id, $chat_id ) {
		$chats = self::get_chats( $user_id );

		if ( ! isset( $chats[ $chat_id ] ) ) {
			return;
		}

		$messages = $chats[ $chat_id ]['messages'];

		while ( ! empty( $messages ) && 'error' === end( $messages )['role'] ) {
			array_pop( $messages );
		}

		$chats[ $chat_id ]['messages'] = array_values( $messages );
		update_user_meta( $user_id, self::CHATS_META, $chats );
	}

	/**
	 * Delete one chat.
	 *
	 * @param int    $user_id User ID.
	 * @param string $chat_id Chat id.
	 * @return void
	 */
	public static function delete_chat( $user_id, $chat_id ) {
		$chats = self::get_chats( $user_id );

		unset( $chats[ $chat_id ] );
		update_user_meta( $user_id, self::CHATS_META, $chats );

		if ( get_user_meta( $user_id, self::ACTIVE_META, true ) === $chat_id ) {
			delete_user_meta( $user_id, self::ACTIVE_META );
		}
	}

	/**
	 * Delete every chat.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public static function delete_all_chats( $user_id ) {
		delete_user_meta( $user_id, self::CHATS_META );
		delete_user_meta( $user_id, self::ACTIVE_META );
		delete_user_meta( $user_id, self::LEGACY_META );
	}

	/**
	 * Remember which chat is open.
	 *
	 * @param int    $user_id User ID.
	 * @param string $chat_id Chat id ('' for a fresh chat).
	 * @return void
	 */
	public static function set_active( $user_id, $chat_id ) {
		if ( '' === $chat_id ) {
			delete_user_meta( $user_id, self::ACTIVE_META );
			return;
		}

		update_user_meta( $user_id, self::ACTIVE_META, $chat_id );
	}

	/**
	 * Get the active chat id (validated against existing chats).
	 *
	 * @param int $user_id User ID.
	 * @return string Empty string when none.
	 */
	public static function get_active( $user_id ) {
		$active = (string) get_user_meta( $user_id, self::ACTIVE_META, true );
		$chats  = self::get_chats( $user_id );

		return isset( $chats[ $active ] ) ? $active : '';
	}

	/**
	 * Generate a chat id.
	 *
	 * @return string
	 */
	private static function new_id() {
		return 'c' . time() . wp_rand( 100, 999 );
	}

	/**
	 * Format a chat's messages for the client.
	 *
	 * @param array $chat Chat record.
	 * @return array<int, array{role: string, raw: string, html: string}>
	 */
	public static function format_messages( $chat ) {
		$out = array();

		foreach ( (array) $chat['messages'] as $message ) {
			if ( 'error' === $message['role'] ) {
				$out[] = array(
					'role'   => 'error',
					'raw'    => (string) $message['content'],
					'html'   => '',
					'prompt' => isset( $message['prompt'] ) ? (string) $message['prompt'] : '',
				);
				continue;
			}

			$out[] = array(
				'role'   => ( 'assistant' === $message['role'] ) ? 'ai' : 'me',
				'raw'    => (string) $message['content'],
				'html'   => wp_kses_post( wpautop( $message['content'] ) ),
				'prompt' => '',
			);
		}

		return $out;
	}

	/**
	 * Conversation starters shown on the empty state.
	 *
	 * @return string[]
	 */
	private function get_starters() {
		return array(
			__( 'Give me 10 blog post ideas for my audience', 'cubix-ai-article-generator' ),
			__( 'Turn these rough notes into a clean outline', 'cubix-ai-article-generator' ),
			__( 'Rewrite this paragraph so it sounds more confident', 'cubix-ai-article-generator' ),
			__( 'Suggest meta descriptions for my latest post', 'cubix-ai-article-generator' ),
		);
	}

	/**
	 * Render the studio page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! CXAI_Options::current_user_can_use() ) {
			wp_die( esc_html__( 'You do not have permission to use the AI Studio.', 'cubix-ai-article-generator' ) );
		}

		$user_id   = get_current_user_id();
		$providers = CXAI_Options::get_providers();
		$default   = CXAI_Options::get_default_provider();
		$chats     = self::get_chats( $user_id );
		$active    = self::get_active( $user_id );
		$messages  = ( '' !== $active ) ? self::format_messages( $chats[ $active ] ) : array();
		?>
		<div class="wrap cx-app">
			<div class="cx-prism-rule" aria-hidden="true"></div>

			<header class="cx-masthead">
				<div class="cx-masthead-brand">
					<?php echo wp_kses( CXAI_Branding::logo( 52 ), CXAI_Branding::svg_kses() ); ?>
					<div>
						<p class="cx-eyebrow"><?php esc_html_e( 'Cubixsol', 'cubix-ai-article-generator' ); ?></p>
						<h1><?php esc_html_e( 'AI Studio', 'cubix-ai-article-generator' ); ?></h1>
					</div>
				</div>

				<div class="cx-masthead-meta">
					<label class="cx-select-inline">
						<span class="cx-eyebrow"><?php esc_html_e( 'Engine', 'cubix-ai-article-generator' ); ?></span>
						<select id="cx-chat-engine" class="cx-input">
							<?php foreach ( $providers as $slug => $definition ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $default, $slug ); ?> <?php disabled( ! CXAI_Options::is_provider_ready( $slug ) ); ?>>
									<?php echo esc_html( $definition['label'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</label>
				</div>
			</header>

			<div class="cx-studio-grid" id="cx-chat">
				<!-- Conversation list -->
				<aside class="cx-chatlist" aria-label="<?php esc_attr_e( 'Conversations', 'cubix-ai-article-generator' ); ?>">
					<button type="button" class="cx-btn cx-btn-primary cx-btn-newchat" id="cx-chat-new">
						<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
						<?php esc_html_e( 'New chat', 'cubix-ai-article-generator' ); ?>
					</button>

					<p class="cx-chatlist-label"><?php esc_html_e( 'Conversations', 'cubix-ai-article-generator' ); ?></p>

					<div class="cx-chatlist-items" id="cx-chatlist">
						<?php if ( empty( $chats ) ) : ?>
							<p class="cx-chatlist-empty" id="cx-chatlist-hint"><?php esc_html_e( 'No conversations yet. Your chats will appear here.', 'cubix-ai-article-generator' ); ?></p>
						<?php endif; ?>
						<?php foreach ( $chats as $id => $chat ) : ?>
							<div class="cx-chatlist-item<?php echo ( $id === $active ) ? ' is-active' : ''; ?>" data-chat="<?php echo esc_attr( $id ); ?>">
								<button type="button" class="cx-chatlist-open" title="<?php echo esc_attr( $chat['title'] ); ?>">
									<span class="cx-chatlist-title"><?php echo esc_html( $chat['title'] ); ?></span>
									<span class="cx-chatlist-time"><?php echo esc_html( human_time_diff( (int) $chat['updated'] ) ); ?></span>
								</button>
								<button type="button" class="cx-chatlist-del" aria-label="<?php esc_attr_e( 'Delete this chat', 'cubix-ai-article-generator' ); ?>">
									<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
								</button>
							</div>
						<?php endforeach; ?>
					</div>

					<button type="button" class="cx-btn cx-btn-ghost cx-btn-danger cx-btn-delall" id="cx-chat-delall" <?php disabled( empty( $chats ) ); ?>>
						<span class="dashicons dashicons-trash" aria-hidden="true"></span>
						<?php esc_html_e( 'Delete all chats', 'cubix-ai-article-generator' ); ?>
					</button>

					<p class="cx-chatlist-note">
						<span class="dashicons dashicons-clock" aria-hidden="true"></span>
						<?php
						printf(
							/* translators: %d: number of days chats are kept. */
							esc_html__( 'Chats delete automatically after %d days.', 'cubix-ai-article-generator' ),
							(int) self::RETENTION_DAYS
						);
						?>
					</p>
				</aside>

				<!-- Conversation -->
				<div class="cx-studio" data-active="<?php echo esc_attr( $active ); ?>">
					<div class="cx-chat-scroll" id="cx-chat-window">
						<div class="cx-chat-empty<?php echo $messages ? ' cx-hidden' : ''; ?>" id="cx-chat-empty">
							<?php echo wp_kses( CXAI_Branding::logo( 64 ), CXAI_Branding::svg_kses() ); ?>
							<h2><?php esc_html_e( 'What are we writing today?', 'cubix-ai-article-generator' ); ?></h2>
							<p><?php esc_html_e( 'Conversations are private to your account and stay on this site.', 'cubix-ai-article-generator' ); ?></p>

							<div class="cx-starters">
								<?php foreach ( $this->get_starters() as $starter ) : ?>
									<button type="button" class="cx-starter"><?php echo esc_html( $starter ); ?></button>
								<?php endforeach; ?>
							</div>
						</div>

						<?php
						$last_index = count( $messages ) - 1;

						foreach ( $messages as $index => $message ) :
							if ( 'error' === $message['role'] ) :
								?>
								<article class="cx-msg cx-msg-ai cx-msg-error" data-prompt="<?php echo esc_attr( $message['prompt'] ); ?>">
									<div class="cx-msg-avatar cx-msg-avatar-error" aria-hidden="true">
										<span class="dashicons dashicons-warning"></span>
									</div>
									<div class="cx-msg-body">
										<div class="cx-msg-bubble cx-bubble-error" role="alert">
											<p class="cx-error-head">
												<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
												<?php esc_html_e( 'That request did not go through', 'cubix-ai-article-generator' ); ?>
											</p>
											<p class="cx-error-detail"><?php echo esc_html( $message['raw'] ); ?></p>
										</div>
										<?php if ( $index === $last_index && '' !== $message['prompt'] ) : ?>
											<button type="button" class="cx-error-retry">
												<span class="dashicons dashicons-update" aria-hidden="true"></span><?php esc_html_e( 'Try again', 'cubix-ai-article-generator' ); ?>
											</button>
										<?php endif; ?>
									</div>
								</article>
								<?php
								continue;
							endif;
							?>
							<article class="cx-msg cx-msg-<?php echo esc_attr( $message['role'] ); ?>">
								<div class="cx-msg-avatar" aria-hidden="true">
									<?php if ( 'ai' === $message['role'] ) : ?>
										<?php echo wp_kses( CXAI_Branding::logo( 22 ), CXAI_Branding::svg_kses() ); ?>
									<?php else : ?>
										<span class="dashicons dashicons-admin-users"></span>
									<?php endif; ?>
								</div>
								<div class="cx-msg-body">
									<div class="cx-msg-bubble"><?php echo wp_kses_post( wpautop( $message['raw'] ) ); ?></div>
									<button type="button" class="cx-msg-copy" data-raw="<?php echo esc_attr( $message['raw'] ); ?>">
										<span class="dashicons dashicons-clipboard" aria-hidden="true"></span><?php esc_html_e( 'Copy', 'cubix-ai-article-generator' ); ?>
									</button>
								</div>
							</article>
						<?php endforeach; ?>
					</div>

					<div class="cx-composer">
						<textarea
							id="cx-chat-input"
							rows="1"
							placeholder="<?php esc_attr_e( 'Ask anything — Enter to send, Shift+Enter for a new line', 'cubix-ai-article-generator' ); ?>"
							aria-label="<?php esc_attr_e( 'Message', 'cubix-ai-article-generator' ); ?>"
						></textarea>
						<button type="button" class="cx-send" id="cx-chat-send" aria-label="<?php esc_attr_e( 'Send message', 'cubix-ai-article-generator' ); ?>">
							<span class="dashicons dashicons-arrow-up-alt" aria-hidden="true"></span>
						</button>
					</div>

					<p class="cx-composer-note"><?php esc_html_e( 'AI can be wrong. Check anything factual before you publish it.', 'cubix-ai-article-generator' ); ?></p>
				</div>
			</div>
		</div>
		<?php
	}
}
