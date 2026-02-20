<?php
/**
 * Admin screens and actions.
 *
 * @package OmegaCody
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress admin UI.
 */
class Omega_Cody_Admin {
	/**
	 * Storage service.
	 *
	 * @var Omega_Cody_Storage
	 */
	private $storage;

	/**
	 * Sync service.
	 *
	 * @var Omega_Cody_Sync_Service
	 */
	private $sync_service;

	/**
	 * Constructor.
	 *
	 * @param Omega_Cody_Storage      $storage      Storage service.
	 * @param Omega_Cody_Sync_Service $sync_service Sync service.
	 */
	public function __construct( Omega_Cody_Storage $storage, Omega_Cody_Sync_Service $sync_service ) {
		$this->storage      = $storage;
		$this->sync_service = $sync_service;
	}

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'register_admin_pages' ) );
		add_action( 'admin_post_omega_cody_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_omega_cody_sync', array( $this, 'handle_sync' ) );
	}

	/**
	 * Register plugin menu pages.
	 *
	 * @return void
	 */
	public function register_admin_pages() {
		add_menu_page(
			__( 'Cody Threads', 'omega-cody' ),
			__( 'Cody Threads', 'omega-cody' ),
			'read',
			'omega-cody-conversations',
			array( $this, 'render_conversations_page' ),
			'dashicons-format-chat',
			65
		);

		add_submenu_page(
			'omega-cody-conversations',
			__( 'Conversations', 'omega-cody' ),
			__( 'Conversations', 'omega-cody' ),
			'read',
			'omega-cody-conversations',
			array( $this, 'render_conversations_page' )
		);

		add_submenu_page(
			'omega-cody-conversations',
			__( 'Settings', 'omega-cody' ),
			__( 'Settings', 'omega-cody' ),
			'manage_options',
			'omega-cody-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Render settings page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'omega-cody' ) );
		}

		$options = omega_cody_get_options();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Cody API Settings', 'omega-cody' ); ?></h1>

			<?php if ( isset( $_GET['omega_cody_updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html__( 'Settings saved.', 'omega-cody' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="omega_cody_save_settings" />
				<?php wp_nonce_field( 'omega_cody_save_settings' ); ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="omega_cody_api_key"><?php echo esc_html__( 'API Key', 'omega-cody' ); ?></label>
							</th>
							<td>
								<input
									name="omega_cody_api_key"
									type="password"
									id="omega_cody_api_key"
									value="<?php echo esc_attr( $options['api_key'] ); ?>"
									class="regular-text"
									autocomplete="off"
								/>
								<p class="description">
									<?php echo esc_html__( 'Cody API bearer token.', 'omega-cody' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="omega_cody_bot_id"><?php echo esc_html__( 'Bot ID', 'omega-cody' ); ?></label>
							</th>
							<td>
								<input
									name="omega_cody_bot_id"
									type="text"
									id="omega_cody_bot_id"
									value="<?php echo esc_attr( $options['bot_id'] ); ?>"
									class="regular-text"
								/>
								<p class="description">
									<?php echo esc_html__( 'Bot identifier used to filter conversations.', 'omega-cody' ); ?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>

				<?php submit_button( __( 'Save Settings', 'omega-cody' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render conversations page.
	 *
	 * @return void
	 */
	public function render_conversations_page() {
		if ( ! current_user_can( 'read' ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'omega-cody' ) );
		}

		$options      = omega_cody_get_options();
		$is_configured = '' !== trim( (string) $options['api_key'] ) && '' !== trim( (string) $options['bot_id'] );

		$total_items    = $this->storage->get_conversation_count();
		$conversations  = array();
		$selected_count = max( 20, $total_items );

		if ( $total_items > 0 ) {
			$conversations = $this->storage->get_conversations( 1, $selected_count );
		}

		$conversation_id = isset( $_GET['conversation'] ) ? sanitize_text_field( wp_unslash( $_GET['conversation'] ) ) : '';
		$messages        = array();
		$selected_conversation = null;

		if ( '' !== $conversation_id ) {
			$messages              = $this->storage->get_messages_by_conversation( $conversation_id );
			$selected_conversation = $this->storage->get_conversation_by_remote_id( $conversation_id );
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Cody Conversation Threads', 'omega-cody' ); ?></h1>

			<?php $this->render_sync_notice(); ?>

			<?php if ( ! $is_configured ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php echo esc_html__( 'Set your API Key and Bot ID in Settings before syncing.', 'omega-cody' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin: 16px 0;">
				<input type="hidden" name="action" value="omega_cody_sync" />
				<?php wp_nonce_field( 'omega_cody_sync' ); ?>
				<?php submit_button( __( 'Sync from Cody API', 'omega-cody' ), 'primary', 'submit', false, $is_configured ? array() : array( 'disabled' => 'disabled' ) ); ?>
			</form>

			<div id="omega-cody-conversations-scroll" style="max-height: 560px; overflow-y: auto; border: 1px solid #dcdcde; border-radius: 4px;">
				<table class="widefat striped" style="border: 0;">
					<thead>
						<tr>
							<th><?php echo esc_html__( 'First User Message', 'omega-cody' ); ?></th>
							<th><?php echo esc_html__( 'Messages', 'omega-cody' ); ?></th>
							<th><?php echo esc_html__( 'Created', 'omega-cody' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $conversations ) ) : ?>
							<tr>
								<td colspan="3"><?php echo esc_html__( 'No conversations found yet. Run a sync to fetch data.', 'omega-cody' ); ?></td>
							</tr>
						<?php else : ?>
							<?php foreach ( $conversations as $conversation ) : ?>
								<tr>
									<td>
										<?php
										$view_url = add_query_arg(
											array(
												'page'         => 'omega-cody-conversations',
												'conversation' => $conversation->remote_id,
											),
											admin_url( 'admin.php' )
										);
										$first_user_message_preview = $this->format_message_preview(
											isset( $conversation->first_user_message ) ? $conversation->first_user_message : ''
										);
										?>
										<a href="<?php echo esc_url( $view_url ); ?>" data-omega-cody-thread-link="1">
											<?php echo esc_html( $first_user_message_preview ); ?>
										</a>
									</td>
									<td><?php echo esc_html( absint( $conversation->message_count ) ); ?></td>
									<td><?php echo esc_html( $this->format_unix_timestamp( $conversation->remote_created_at ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

			<script>
				(function() {
					var storageKey = 'omega_cody_conversations_scroll_top';
					var container = document.getElementById('omega-cody-conversations-scroll');

					if (!container) {
						return;
					}

					var savedTop = window.sessionStorage.getItem(storageKey);
					if (savedTop !== null) {
						var parsedTop = parseInt(savedTop, 10);
						if (!Number.isNaN(parsedTop)) {
							container.scrollTop = parsedTop;
						}
					}

					container.addEventListener('scroll', function() {
						window.sessionStorage.setItem(storageKey, String(container.scrollTop));
					});

					var links = container.querySelectorAll('a[data-omega-cody-thread-link="1"]');
					for (var i = 0; i < links.length; i++) {
						links[i].addEventListener('click', function() {
							window.sessionStorage.setItem(storageKey, String(container.scrollTop));
						});
					}
				}());
			</script>

			<?php if ( '' !== $conversation_id ) : ?>
				<hr />
				<h2>
					<?php
					$conversation_date_label = __( 'Unknown', 'omega-cody' );
					if ( ! empty( $selected_conversation ) && isset( $selected_conversation->remote_created_at ) ) {
						$conversation_date_label = $this->format_unix_timestamp( $selected_conversation->remote_created_at );
					}

					printf(
						/* translators: %s: conversation date */
						esc_html__( 'Conversation Date: %s', 'omega-cody' ),
						esc_html( $conversation_date_label )
					);
					?>
				</h2>

				<?php if ( empty( $messages ) ) : ?>
					<p><?php echo esc_html__( 'No messages stored for this conversation yet.', 'omega-cody' ); ?></p>
				<?php else : ?>
					<div>
						<?php foreach ( $messages as $message ) : ?>
							<?php $is_machine = ! empty( $message->machine ); ?>
							<div style="border: 1px solid #dcdcde; border-radius: 4px; padding: 12px; margin: 12px 0; background: <?php echo esc_attr( $is_machine ? '#f6f7f7' : '#ffffff' ); ?>;">
								<p style="margin-top: 0;">
									<strong><?php echo esc_html( $is_machine ? __( 'Assistant', 'omega-cody' ) : __( 'User', 'omega-cody' ) ); ?></strong>
									<?php if ( ! empty( $message->failed_responding ) ) : ?>
										<span style="color: #b32d2e;">(<?php echo esc_html__( 'Failed response', 'omega-cody' ); ?>)</span>
									<?php endif; ?>
									<?php if ( ! empty( $message->flagged ) ) : ?>
										<span style="color: #b32d2e;">(<?php echo esc_html__( 'Flagged', 'omega-cody' ); ?>)</span>
									<?php endif; ?>
								</p>
								<div><?php echo wp_kses_post( wpautop( esc_html( $message->content ) ) ); ?></div>
								<p style="margin-bottom: 0; color: #646970;">
									<?php echo esc_html( $this->format_unix_timestamp( $message->remote_created_at ) ); ?>
								</p>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Handle settings save.
	 *
	 * @return void
	 */
	public function handle_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to perform this action.', 'omega-cody' ) );
		}

		check_admin_referer( 'omega_cody_save_settings' );

		$api_key = isset( $_POST['omega_cody_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['omega_cody_api_key'] ) ) : '';
		$bot_id  = isset( $_POST['omega_cody_bot_id'] ) ? sanitize_text_field( wp_unslash( $_POST['omega_cody_bot_id'] ) ) : '';

		update_option(
			OMEGA_CODY_OPTION_NAME,
			array(
				'api_key' => $api_key,
				'bot_id'  => $bot_id,
			),
			false
		);

		$redirect_url = add_query_arg(
			array(
				'page'               => 'omega-cody-settings',
				'omega_cody_updated' => 1,
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Handle manual sync action.
	 *
	 * @return void
	 */
	public function handle_sync() {
		if ( ! current_user_can( 'read' ) ) {
			wp_die( esc_html__( 'You are not allowed to perform this action.', 'omega-cody' ) );
		}

		check_admin_referer( 'omega_cody_sync' );

		$options = omega_cody_get_options();
		if ( '' === trim( (string) $options['api_key'] ) || '' === trim( (string) $options['bot_id'] ) ) {
			$this->redirect_with_sync_notice(
				array(
					'omega_cody_sync_status' => 'error',
					'omega_cody_sync_msg'    => __( 'API Key and Bot ID are required.', 'omega-cody' ),
				)
			);
		}

		$result = $this->sync_service->sync_all( $options['api_key'], $options['bot_id'] );

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_sync_notice(
				array(
					'omega_cody_sync_status' => 'error',
					'omega_cody_sync_msg'    => $result->get_error_message(),
				)
			);
		}

		$this->redirect_with_sync_notice(
			array(
				'omega_cody_sync_status' => 'success',
				'processed'              => absint( $result['conversations_processed'] ),
				'added'                  => absint( $result['conversations_added'] ),
				'skipped'                => absint( $result['conversations_skipped'] ),
				'messages_added'         => absint( $result['messages_added'] ),
			)
		);
	}

	/**
	 * Print sync notices on conversations screen.
	 *
	 * @return void
	 */
	private function render_sync_notice() {
		if ( ! isset( $_GET['omega_cody_sync_status'] ) ) {
			return;
		}

		$status = sanitize_text_field( wp_unslash( $_GET['omega_cody_sync_status'] ) );
		$class  = 'success' === $status ? 'notice-success' : 'notice-error';
		?>
		<div class="notice <?php echo esc_attr( $class ); ?> is-dismissible">
			<p>
				<?php
				if ( 'success' === $status ) {
					$processed     = isset( $_GET['processed'] ) ? absint( $_GET['processed'] ) : 0;
					$added         = isset( $_GET['added'] ) ? absint( $_GET['added'] ) : 0;
					$skipped       = isset( $_GET['skipped'] ) ? absint( $_GET['skipped'] ) : 0;
					$messages_added = isset( $_GET['messages_added'] ) ? absint( $_GET['messages_added'] ) : 0;

					printf(
						/* translators: 1: processed conversations, 2: new conversations, 3: skipped conversations, 4: new messages */
						esc_html__( 'Sync complete. Processed %1$d conversations, added %2$d new conversations, skipped %3$d already-synced conversations, and added %4$d new messages.', 'omega-cody' ),
						$processed,
						$added,
						$skipped,
						$messages_added
					);
				} else {
					$error_message = isset( $_GET['omega_cody_sync_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['omega_cody_sync_msg'] ) ) : __( 'Sync failed.', 'omega-cody' );
					echo esc_html( $error_message );
				}
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Redirect to conversation page with sync query vars.
	 *
	 * @param array<string, string|int> $args Query args.
	 * @return void
	 */
	private function redirect_with_sync_notice( array $args ) {
		$base_args = array(
			'page' => 'omega-cody-conversations',
		);

		$redirect_url = add_query_arg( array_merge( $base_args, $args ), admin_url( 'admin.php' ) );
		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Format unix timestamp into site-local datetime string.
	 *
	 * @param mixed $timestamp Unix timestamp.
	 * @return string
	 */
	private function format_unix_timestamp( $timestamp ) {
		$clean_timestamp = absint( $timestamp );
		if ( $clean_timestamp <= 0 ) {
			return __( 'Unknown', 'omega-cody' );
		}

		return wp_date(
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
			$clean_timestamp
		);
	}

	/**
	 * Format GMT datetime string into site-local datetime.
	 *
	 * @param string|null $gmt_datetime GMT mysql datetime.
	 * @return string
	 */
	private function format_gmt_datetime( $gmt_datetime ) {
		if ( empty( $gmt_datetime ) || ! is_string( $gmt_datetime ) ) {
			return __( 'Never', 'omega-cody' );
		}

		$local_datetime = get_date_from_gmt( $gmt_datetime, 'Y-m-d H:i:s' );
		if ( empty( $local_datetime ) ) {
			return __( 'Never', 'omega-cody' );
		}

		return mysql2date(
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
			$local_datetime
		);
	}

	/**
	 * Build a short preview for table display.
	 *
	 * @param mixed $message Message content.
	 * @return string
	 */
	private function format_message_preview( $message ) {
		$clean_message = trim( wp_strip_all_tags( (string) $message ) );
		if ( '' === $clean_message ) {
			return __( '(No user message saved)', 'omega-cody' );
		}

		return wp_html_excerpt( $clean_message, 140, '...' );
	}
}
