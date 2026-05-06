<?php
/**
 * Admin screens and actions.
 *
 * @package Omega_Cody
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
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_post_omega_cody_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_omega_cody_reset_data', array( $this, 'handle_reset_data' ) );
		add_action( 'admin_post_omega_cody_sync', array( $this, 'handle_sync' ) );
		add_action( 'wp_ajax_omega_cody_sync_start', array( $this, 'ajax_sync_start' ) );
		add_action( 'wp_ajax_omega_cody_sync_step', array( $this, 'ajax_sync_step' ) );
	}

	/**
	 * Enqueue admin assets for plugin screens.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_admin_assets( $hook_suffix ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'omega-cody-conversations' !== $page && 'toplevel_page_omega-cody-conversations' !== $hook_suffix ) {
			return;
		}

		$options       = omega_cody_get_options();
		$is_configured = '' !== trim( (string) $options['api_key'] ) && '' !== trim( (string) $options['bot_id'] );
		$auto_sync     = $this->should_auto_sync_on_page_load( $is_configured );
		$handle        = 'omega-cody-conversations-admin';

		wp_enqueue_script(
			$handle,
			OMEGA_CODY_PLUGIN_URL . 'assets/js/omega-cody-conversations.js',
			array(),
			OMEGA_CODY_VERSION,
			true
		);

		wp_localize_script(
			$handle,
			'omegaCodyConversationsConfig',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'syncAjaxNonce' => wp_create_nonce( 'omega_cody_sync_ajax' ),
				'isConfigured'  => $is_configured,
				'autoSync'      => $auto_sync,
				'text'          => array(
					'syncInProgress'             => __( 'Sync in progress...', 'omega-cody' ),
					'totalMessagesAddedPrefix'   => __( 'Total Messages added', 'omega-cody' ),
					'processedPrefix'            => __( 'Processed', 'omega-cody' ),
					'conversationsWord'          => __( 'conversations', 'omega-cody' ),
					'addedPrefix'                => __( 'added', 'omega-cody' ),
					'skippedPrefix'              => __( 'skipped', 'omega-cody' ),
					'messagesAddedPrefix'        => __( 'messages added', 'omega-cody' ),
					'totalConversationsExpected' => __( 'Total conversations expected:', 'omega-cody' ),
					'invalidSyncStepResponse'    => __( 'Invalid sync step response.', 'omega-cody' ),
					'syncFailed'                 => __( 'Sync failed.', 'omega-cody' ),
					'syncRequestFailed'          => __( 'Sync request failed.', 'omega-cody' ),
					'syncButtonLabel'            => __( 'Sync with Cody API', 'omega-cody' ),
					'syncingButtonLabel'         => __( 'Syncing...', 'omega-cody' ),
					'startingSync'               => __( 'Starting sync...', 'omega-cody' ),
					'unableToStartSync'          => __( 'Unable to start sync.', 'omega-cody' ),
					'couldNotStartSync'          => __( 'Could not start sync.', 'omega-cody' ),
					'threadCopied'               => __( 'Thread copied.', 'omega-cody' ),
					'threadCopyFailed'           => __( 'Could not copy thread.', 'omega-cody' ),
				),
			)
		);
	}

	/**
	 * Register plugin menu pages.
	 *
	 * @return void
	 */
	public function register_admin_pages() {
			add_menu_page(
				__( 'Chatbot Logs', 'omega-cody' ),
				__( 'Chatbot Logs', 'omega-cody' ),
				'read',
				'omega-cody-conversations',
				array( $this, 'render_conversations_page' ),
				'dashicons-format-chat',
				5
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
					<p>
						<?php
						$was_validated = isset( $_GET['omega_cody_validated'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['omega_cody_validated'] ) );
						if ( $was_validated ) {
							echo esc_html__( 'Settings saved. API call succeeded, the API key is valid, and the Bot ID was found.', 'omega-cody' );
						} else {
							echo esc_html__( 'Settings saved.', 'omega-cody' );
						}
						?>
					</p>
				</div>
			<?php endif; ?>
			<?php if ( isset( $_GET['omega_cody_settings_status'] ) && 'error' === sanitize_text_field( wp_unslash( $_GET['omega_cody_settings_status'] ) ) ) : ?>
				<div class="notice notice-error is-dismissible">
					<p>
						<?php
						$error_message = isset( $_GET['omega_cody_settings_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['omega_cody_settings_msg'] ) ) : __( 'Could not save settings.', 'omega-cody' );
						echo esc_html( $error_message );
						?>
					</p>
				</div>
			<?php endif; ?>
			<?php if ( isset( $_GET['omega_cody_reset'] ) ) : ?>
				<?php if ( 'success' === sanitize_text_field( wp_unslash( $_GET['omega_cody_reset'] ) ) ) : ?>
					<div class="notice notice-success is-dismissible">
						<p><?php echo esc_html__( 'All stored conversations and messages were deleted.', 'omega-cody' ); ?></p>
					</div>
				<?php else : ?>
					<div class="notice notice-error is-dismissible">
						<p><?php echo esc_html__( 'Could not delete stored data. Please try again.', 'omega-cody' ); ?></p>
					</div>
				<?php endif; ?>
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
									<?php echo esc_html__( 'Cody API bearer token', 'omega-cody' ); ?>
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
									<?php echo esc_html__( 'Which CodyAI Bot conversations will be retrieved from', 'omega-cody' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="omega_cody_bot_name"><?php echo esc_html__( 'Bot Name', 'omega-cody' ); ?></label>
							</th>
							<td>
								<input
									name="omega_cody_bot_name"
									type="text"
									id="omega_cody_bot_name"
									value="<?php echo esc_attr( '' !== $options['bot_name'] ? $options['bot_name'] : __( 'Unknown (save settings)', 'omega-cody' ) ); ?>"
									class="regular-text"
									readonly="readonly"
								/>
								<p class="description">
									<?php echo esc_html__( 'Retrieved from Cody when settings are saved', 'omega-cody' ); ?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>

				<?php submit_button( __( 'Save Settings', 'omega-cody' ) ); ?>
			</form>

			<hr />

			<h2><?php echo esc_html__( 'Reset Stored Data', 'omega-cody' ); ?></h2>
			<p>
				<?php echo esc_html__( 'Deletes all locally stored conversations and messages from the WordPress database. API settings are not changed.', 'omega-cody' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="omega_cody_reset_data" />
				<?php wp_nonce_field( 'omega_cody_reset_data' ); ?>
				<?php
				submit_button(
					__( 'Reset Stored Conversations and Messages', 'omega-cody' ),
					'delete',
					'submit',
					false,
					array(
						'onclick' => "return confirm('" . esc_js( __( 'Delete all stored conversations and messages? This cannot be undone.', 'omega-cody' ) ) . "');",
					)
				);
				?>
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
		$last_sync_gmt   = get_option( OMEGA_CODY_LAST_SYNC_OPTION, '' );
		$last_sync_label = $this->format_time_ago_from_gmt( $last_sync_gmt );

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
			<h1 id="omega-cody-page-title">
				<?php
				$bot_display_name = trim( (string) $options['bot_name'] );
				if ( '' !== $bot_display_name ) {
					echo esc_html(
						sprintf(
							/* translators: 1: conversation count, 2: bot name */
							__( '%1$s Conversations with %2$s Chatbot', 'omega-cody' ),
							number_format_i18n( $total_items ),
							$bot_display_name
						)
					);
				} else {
					echo esc_html(
						sprintf(
							/* translators: %s: conversation count */
							__( '%s Conversations', 'omega-cody' ),
							number_format_i18n( $total_items )
						)
					);
				}
				?>
			</h1>

			<?php $this->render_sync_notice(); ?>

			<?php if ( ! $is_configured ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php echo esc_html__( 'Set your API Key and Bot ID in Settings before syncing.', 'omega-cody' ); ?>
					</p>
				</div>
			<?php endif; ?>

				<div style="display: flex; align-items: center; gap: 12px; margin: 16px 0 12px 0;">
					<form id="omega-cody-sync-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin: 0;">
						<input type="hidden" name="action" value="omega_cody_sync" />
						<?php wp_nonce_field( 'omega_cody_sync' ); ?>
						<?php
						submit_button(
							__( 'Sync with Cody API', 'omega-cody' ),
							'primary',
							'omega_cody_sync_submit',
							false,
							$is_configured ? array() : array( 'disabled' => 'disabled' )
							);
							?>
					</form>
					<p id="omega-cody-last-sync-line" style="margin: 0; color: #646970; font-style: italic;">
						<?php
						printf(
							/* translators: %s: last sync datetime */
							esc_html__( 'Last sync: %s', 'omega-cody' ),
							esc_html( $last_sync_label )
						);
						?>
					</p>
				</div>
				<div id="omega-cody-sync-progress" style="display: none; margin: 12px 0 16px 0;">
				<div
					style="width: 100%; background: #ffffff; border: 1px solid #dcdcde; border-radius: 4px; overflow: hidden; height: 14px;"
					role="progressbar"
					aria-valuemin="0"
					aria-valuemax="100"
					aria-valuenow="0"
					aria-label="<?php echo esc_attr__( 'Sync progress', 'omega-cody' ); ?>"
					id="omega-cody-sync-progressbar"
				>
					<div id="omega-cody-sync-progressbar-fill" style="height: 100%; width: 0%; background: #2271b1; transition: width .2s ease;"></div>
				</div>
				<p id="omega-cody-sync-live-status-text" style="margin: 8px 0 0 0; font-style: italic;"></p>
			</div>

			<div id="omega-cody-conversations-scroll" style="max-height: 560px; overflow-y: auto; border: 1px solid #dcdcde; border-radius: 4px;">
					<style>
						#omega-cody-conversations-scroll .omega-cody-thread-row {
							cursor: pointer;
						}

						#omega-cody-conversations-scroll table thead th {
							position: sticky;
							top: 0;
							z-index: 2;
							background: #1c2327;
							color: #ffffff;
						}

						#omega-cody-conversations-scroll .omega-cody-thread-row:hover td {
							background-color: #c5d9ed;
						}

						#omega-cody-conversations-scroll .omega-cody-thread-row--active td {
							background-color: #f5f3e2;
						}
					</style>
				<table class="widefat striped" style="border: 0;">
					<thead>
						<tr>
							<th><?php echo esc_html__( 'Opening Message', 'omega-cody' ); ?></th>
							<th><?php echo esc_html__( 'Total Messages', 'omega-cody' ); ?></th>
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
								$row_classes = 'omega-cody-thread-row';
								if ( $conversation->remote_id === $conversation_id ) {
									$row_classes .= ' omega-cody-thread-row--active';
								}
								?>
									<tr
										class="<?php echo esc_attr( $row_classes ); ?>"
									data-omega-cody-thread-url="<?php echo esc_url( $view_url ); ?>"
									tabindex="0"
									role="link"
									aria-label="<?php echo esc_attr( $first_user_message_preview ); ?>"
								>
									<td>
										<?php echo esc_html( $first_user_message_preview ); ?>
									</td>
									<td><?php echo esc_html( absint( $conversation->message_count ) ); ?></td>
									<td><?php echo esc_html( $this->format_unix_timestamp( $conversation->remote_created_at ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

				<?php if ( '' !== $conversation_id ) : ?>
					<hr />
					<?php
					$conversation_date_label = __( 'Unknown', 'omega-cody' );
					if ( ! empty( $selected_conversation ) && isset( $selected_conversation->remote_created_at ) ) {
						$conversation_date_label = $this->format_unix_timestamp( $selected_conversation->remote_created_at );
					}
					$copy_thread_text = $this->build_conversation_copy_text( $conversation_date_label, $messages );
					?>
					<div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin: 1em 0;">
						<h2 style="margin: 0;"><?php echo esc_html( $conversation_date_label ); ?></h2>
						<button
							type="button"
							class="button button-primary"
							id="omega-cody-copy-thread"
							style="background: #2370b1; border-color: #2370b1; color: #ffffff; margin-left: 12px;"
						>
							<?php echo esc_html__( 'copy to clipboard', 'omega-cody' ); ?>
						</button>
						<span id="omega-cody-copy-thread-status" aria-live="polite" style="color: #646970;"></span>
					</div>
					<textarea
						id="omega-cody-copy-thread-source"
						readonly="readonly"
						tabindex="-1"
						aria-hidden="true"
						style="position: absolute; left: -9999px; top: auto; width: 1px; height: 1px; opacity: 0;"
					><?php echo esc_textarea( $copy_thread_text ); ?></textarea>

					<?php
					$messages_to_render = $messages;
					if ( ! empty( $messages_to_render ) ) {
						array_shift( $messages_to_render );
					}
					?>
					<?php if ( empty( $messages_to_render ) ) : ?>
						<p><?php echo esc_html__( 'No messages stored for this conversation yet.', 'omega-cody' ); ?></p>
					<?php else : ?>
						<div>
							<?php foreach ( $messages_to_render as $message ) : ?>
								<?php $is_machine = ! empty( $message->machine ); ?>
								<div style="border: 1px solid #dcdcde; border-radius: 4px; padding: 12px; margin: 12px 0; background: <?php echo esc_attr( $is_machine ? '#ffffff' : '#f5f3e2' ); ?>;">
								<p style="margin-top: 0;">
									<strong>
										<?php if ( $is_machine ) : ?>
											<span class="dashicons dashicons-editor-quote" aria-hidden="true"></span>
										<?php else : ?>
											<span class="dashicons dashicons-admin-users" aria-hidden="true"></span>
										<?php endif; ?>
										<?php echo esc_html( $is_machine ? __( 'Chatbot', 'omega-cody' ) : __( 'User', 'omega-cody' ) ); ?>
									</strong>
									<?php if ( ! empty( $message->failed_responding ) ) : ?>
										<span style="color: #b32d2e;">(<?php echo esc_html__( 'Failed response', 'omega-cody' ); ?>)</span>
									<?php endif; ?>
									<?php if ( ! empty( $message->flagged ) ) : ?>
										<span style="color: #b32d2e;">(<?php echo esc_html__( 'Flagged', 'omega-cody' ); ?>)</span>
									<?php endif; ?>
								</p>
								<div><?php echo wp_kses_post( $this->format_message_content_html( $message->content ) ); ?></div>
								<p style="margin-bottom: 0; color: #b9bfc8; font-style: italic;">
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
		$bot_name = '';
		$was_validated = false;

		if ( '' !== $api_key || '' !== $bot_id ) {
			if ( '' === $api_key || '' === $bot_id ) {
				$this->redirect_settings_error( __( 'Both API Key and Bot ID are required.', 'omega-cody' ) );
			}

			$resolved_bot_name = $this->sync_service->resolve_bot_name( $api_key, $bot_id );
			if ( is_wp_error( $resolved_bot_name ) ) {
				$this->redirect_settings_error( $resolved_bot_name->get_error_message() );
			}

			$bot_name = sanitize_text_field( (string) $resolved_bot_name );
			if ( '' === $bot_name ) {
				$this->redirect_settings_error( __( 'Bot ID was not found for the provided API Key.', 'omega-cody' ) );
			}

			$was_validated = true;
		}

		update_option(
			OMEGA_CODY_OPTION_NAME,
			array(
				'api_key' => $api_key,
				'bot_id'  => $bot_id,
				'bot_name' => $bot_name,
			),
			false
		);

		$this->redirect_settings_success( $was_validated );
	}

	/**
	 * Handle reset data action.
	 *
	 * @return void
	 */
	public function handle_reset_data() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to perform this action.', 'omega-cody' ) );
		}

		check_admin_referer( 'omega_cody_reset_data' );

		$was_reset = $this->storage->reset_all_data();
		if ( $was_reset ) {
			delete_option( OMEGA_CODY_SYNC_STATE_OPTION );
			delete_option( OMEGA_CODY_LAST_SYNC_OPTION );
		}

		$redirect_url = add_query_arg(
			array(
				'page'            => 'omega-cody-settings',
				'omega_cody_reset' => $was_reset ? 'success' : 'error',
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

		$this->record_last_sync_time();

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
	 * Start AJAX sync run.
	 *
	 * @return void
	 */
	public function ajax_sync_start() {
		if ( ! current_user_can( 'read' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You are not allowed to perform this action.', 'omega-cody' ),
				),
				403
			);
		}

		check_ajax_referer( 'omega_cody_sync_ajax' );

		$options = omega_cody_get_options();
		if ( '' === trim( (string) $options['api_key'] ) || '' === trim( (string) $options['bot_id'] ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'API Key and Bot ID are required.', 'omega-cody' ),
				),
				400
			);
		}

		$state = $this->sync_service->get_initial_sync_state();
		$this->save_sync_state( $state );

		wp_send_json_success(
			array(
				'state' => $state,
			)
		);
	}

	/**
	 * Process one AJAX sync step.
	 *
	 * @return void
	 */
	public function ajax_sync_step() {
		if ( ! current_user_can( 'read' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You are not allowed to perform this action.', 'omega-cody' ),
				),
				403
			);
		}

		check_ajax_referer( 'omega_cody_sync_ajax' );

		$state = $this->get_sync_state();
		if ( empty( $state ) || ! is_array( $state ) ) {
			$state = $this->sync_service->get_initial_sync_state();
		}

		$options = omega_cody_get_options();
		$step_result = $this->sync_service->run_incremental_step( $state, $options['api_key'], $options['bot_id'] );

		if ( is_wp_error( $step_result ) ) {
			$state['status']           = 'error';
			$state['progress_message'] = $step_result->get_error_message();
			$state['updated_at_gmt']   = current_time( 'mysql', true );
			$state['finished_at_gmt']  = current_time( 'mysql', true );
			$this->save_sync_state( $state );

			wp_send_json_success(
				array(
					'state' => $state,
				)
			);
		}

		$this->save_sync_state( $step_result );
		if ( isset( $step_result['status'] ) && 'success' === $step_result['status'] ) {
			$this->record_last_sync_time();
		}

		wp_send_json_success(
			array(
				'state' => $step_result,
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
	 * Get saved sync state from options table.
	 *
	 * @return array<string, mixed>
	 */
	private function get_sync_state() {
		$state = get_option( OMEGA_CODY_SYNC_STATE_OPTION, array() );

		if ( ! is_array( $state ) ) {
			return array();
		}

		return $state;
	}

	/**
	 * Persist sync state in options table.
	 *
	 * @param array<string, mixed> $state Sync state.
	 * @return void
	 */
	private function save_sync_state( array $state ) {
		update_option( OMEGA_CODY_SYNC_STATE_OPTION, $state, false );
	}

	/**
	 * Save current GMT timestamp as last successful sync time.
	 *
	 * @return void
	 */
	private function record_last_sync_time() {
		update_option( OMEGA_CODY_LAST_SYNC_OPTION, current_time( 'mysql', true ), false );
	}

	/**
	 * Determine whether an automatic sync should run on conversations page load.
	 *
	 * @param bool $is_configured Whether API credentials are configured.
	 * @return bool
	 */
	private function should_auto_sync_on_page_load( $is_configured ) {
		if ( ! $is_configured ) {
			return false;
		}

		$last_sync_gmt = get_option( OMEGA_CODY_LAST_SYNC_OPTION, '' );
		if ( empty( $last_sync_gmt ) || ! is_string( $last_sync_gmt ) ) {
			return true;
		}

		$last_sync_timestamp = strtotime( $last_sync_gmt . ' GMT' );
		if ( false === $last_sync_timestamp || $last_sync_timestamp <= 0 ) {
			return true;
		}

		$now_timestamp = current_time( 'timestamp', true );
		if ( $last_sync_timestamp > $now_timestamp ) {
			return false;
		}

		return ( $now_timestamp - $last_sync_timestamp ) >= WEEK_IN_SECONDS;
	}

	/**
	 * Redirect to settings page with success message.
	 *
	 * @return void
	 */
	private function redirect_settings_success( $was_validated = false ) {
		$redirect_url = add_query_arg(
			array(
				'page'               => 'omega-cody-settings',
				'omega_cody_updated' => 1,
				'omega_cody_validated' => $was_validated ? 1 : 0,
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Redirect to settings page with error notice.
	 *
	 * @param string $message Error message.
	 * @return void
	 */
	private function redirect_settings_error( $message ) {
		$redirect_url = add_query_arg(
			array(
				'page'                      => 'omega-cody-settings',
				'omega_cody_settings_status' => 'error',
				'omega_cody_settings_msg'    => sanitize_text_field( (string) $message ),
			),
			admin_url( 'admin.php' )
		);

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

	/**
	 * Render message body with clickable URLs.
	 *
	 * @param mixed $message Message content.
	 * @return string
	 */
	private function format_message_content_html( $message ) {
		$plain_text = esc_html( (string) $message );
		$linked     = make_clickable( $plain_text );

		return wpautop( $linked );
	}

	/**
	 * Build a plain-text transcript for copying a selected conversation.
	 *
	 * @param string            $conversation_date_label Conversation date label.
	 * @param array<int,object> $messages                Conversation messages.
	 * @return string
	 */
	private function build_conversation_copy_text( $conversation_date_label, array $messages ) {
		$lines = array(
			sprintf(
				/* translators: %s: conversation date */
				__( 'Conversation: %s', 'omega-cody' ),
				(string) $conversation_date_label
			),
		);

		if ( ! empty( $messages ) ) {
			array_shift( $messages );
		}

		foreach ( $messages as $message ) {
			$is_machine = ! empty( $message->machine );
			$label      = $is_machine ? __( 'Chatbot', 'omega-cody' ) : __( 'User', 'omega-cody' );
			$flags      = array();

			if ( ! empty( $message->failed_responding ) ) {
				$flags[] = __( 'Failed response', 'omega-cody' );
			}
			if ( ! empty( $message->flagged ) ) {
				$flags[] = __( 'Flagged', 'omega-cody' );
			}

			$header = $label;
			if ( ! empty( $flags ) ) {
				$header .= ' (' . implode( ', ', $flags ) . ')';
			}

			$content = $this->format_message_content_text( isset( $message->content ) ? $message->content : '' );

			$lines[] = '';
			$lines[] = $header;
			$lines[] = str_repeat( '-', strlen( $header ) );
			if ( '' !== $content ) {
				$lines[] = $content;
			}
		}

		return trim( implode( "\n", $lines ) );
	}

	/**
	 * Format message content for a plain-text copied transcript.
	 *
	 * @param mixed $message Message content.
	 * @return string
	 */
	private function format_message_content_text( $message ) {
		$content = html_entity_decode( wp_strip_all_tags( (string) $message ), ENT_QUOTES, get_bloginfo( 'charset' ) );
		$content = str_replace( array( "\r\n", "\r" ), "\n", $content );

		return trim( $content );
	}

	/**
	 * Format GMT datetime as relative human-readable age.
	 *
	 * @param string|null $gmt_datetime GMT mysql datetime.
	 * @return string
	 */
	private function format_time_ago_from_gmt( $gmt_datetime ) {
		if ( empty( $gmt_datetime ) || ! is_string( $gmt_datetime ) ) {
			return __( 'Never', 'omega-cody' );
		}

		$timestamp = strtotime( $gmt_datetime . ' GMT' );
		if ( false === $timestamp || $timestamp <= 0 ) {
			return __( 'Never', 'omega-cody' );
		}

		$now = time();
		if ( $timestamp > $now ) {
			$timestamp = $now;
		}

		$time_diff = human_time_diff( $timestamp, $now );
		if ( '' === $time_diff ) {
			return __( 'Just now', 'omega-cody' );
		}

		/* translators: %s: relative time interval */
		return sprintf( __( '%s ago', 'omega-cody' ), $time_diff );
	}
}
