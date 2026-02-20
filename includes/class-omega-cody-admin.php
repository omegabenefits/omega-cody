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
		add_action( 'admin_post_omega_cody_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_omega_cody_reset_data', array( $this, 'handle_reset_data' ) );
		add_action( 'admin_post_omega_cody_sync', array( $this, 'handle_sync' ) );
		add_action( 'wp_ajax_omega_cody_sync_start', array( $this, 'ajax_sync_start' ) );
		add_action( 'wp_ajax_omega_cody_sync_step', array( $this, 'ajax_sync_step' ) );
	}

	/**
	 * Register plugin menu pages.
	 *
	 * @return void
	 */
	public function register_admin_pages() {
		add_menu_page(
			__( 'Chatbot Log', 'omega-cody' ),
			__( 'Chatbot Log', 'omega-cody' ),
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
		$sync_ajax_nonce = wp_create_nonce( 'omega_cody_sync_ajax' );

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

			<form id="omega-cody-sync-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin: 16px 0;">
				<input type="hidden" name="action" value="omega_cody_sync" />
				<?php wp_nonce_field( 'omega_cody_sync' ); ?>
				<?php
				submit_button(
					__( 'Sync from Cody API', 'omega-cody' ),
					'primary',
					'omega_cody_sync_submit',
					false,
					$is_configured ? array() : array( 'disabled' => 'disabled' )
				);
				?>
			</form>
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

					#omega-cody-conversations-scroll .omega-cody-thread-row:hover td {
						background-color: #dcdcde;
					}

					#omega-cody-conversations-scroll .omega-cody-thread-row--active td {
						background-color: #c5d9ed;
					}
				</style>
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

			<script>
				(function() {
					var storageKey = 'omega_cody_conversations_scroll_top';
					var syncForm = document.getElementById('omega-cody-sync-form');
					var syncButton = document.getElementById('omega_cody_sync_submit');
					var statusWrap = document.getElementById('omega-cody-sync-progress');
					var statusText = document.getElementById('omega-cody-sync-live-status-text');
					var progressBar = document.getElementById('omega-cody-sync-progressbar');
					var progressFill = document.getElementById('omega-cody-sync-progressbar-fill');
					var pageTitle = document.getElementById('omega-cody-page-title');
					var container = document.getElementById('omega-cody-conversations-scroll');
					var pollTimer = null;
					var syncAjaxNonce = <?php echo wp_json_encode( $sync_ajax_nonce ); ?>;
					var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
					var isConfigured = <?php echo wp_json_encode( $is_configured ); ?>;

					function setStatus(text) {
						if (!statusWrap || !statusText) {
							return;
						}

						statusWrap.style.display = 'block';
						statusText.textContent = text;
					}

					function setTitleVisible(isVisible) {
						if (!pageTitle) {
							return;
						}

						pageTitle.style.display = isVisible ? '' : 'none';
					}

					function setProgress(percent) {
						if (!progressBar || !progressFill) {
							return;
						}

						var safePercent = percent;
						if (safePercent < 0) {
							safePercent = 0;
						}
						if (safePercent > 100) {
							safePercent = 100;
						}

						progressFill.style.width = String(safePercent) + '%';
						progressBar.setAttribute('aria-valuenow', String(Math.round(safePercent)));
					}

					function buildProgressPercent(state) {
						if (!state || !state.results) {
							return 0;
						}

						var total = Number(state.total_conversations_expected || 0);
						if (!total || total <= 0) {
							return 0;
						}

						var completed;
						if (state.phase === 'conversations') {
							completed = Number(state.results.conversations_processed || 0);
						} else {
							completed = Number(state.results.conversations_skipped || 0) +
								Number(state.results.conversations_message_synced || 0);
						}

						if (completed < 0) {
							completed = 0;
						}

						return Math.round((completed / total) * 100);
					}

					function setSyncButtonEnabled(enabled, label) {
						if (!syncButton) {
							return;
						}

						syncButton.disabled = !enabled;
						if (label) {
							syncButton.value = label;
						}
					}

						function buildProgressText(state) {
							if (!state || !state.results) {
								return 'Sync in progress...';
							}

							var pieces = [];
							if (state.progress_message) {
								pieces.push(state.progress_message);
							}
							if (state.phase === 'messages' && state.status === 'running') {
								pieces.push(
									'Total Messages added ' + String(state.results.messages_added || 0) + '.'
								);
								return pieces.join(' ');
							}

							pieces.push(
								'Processed ' + String(state.results.conversations_processed || 0) +
								' conversations, added ' + String(state.results.conversations_added || 0) +
								', skipped ' + String(state.results.conversations_skipped || 0) +
								', messages added ' + String(state.results.messages_added || 0) + '.'
							);

							if (state.phase === 'conversations' && state.total_conversations_expected && Number(state.total_conversations_expected) > 0) {
								pieces.push('Total conversations expected: ' + String(state.total_conversations_expected) + '.');
							}

							return pieces.join(' ');
						}

					function postSyncAction(action) {
						var formData = new window.FormData();
						formData.append('action', action);
						formData.append('_ajax_nonce', syncAjaxNonce);

						return window.fetch(ajaxUrl, {
							method: 'POST',
							credentials: 'same-origin',
							body: formData
						}).then(function(response) {
							return response.json();
						});
					}

					function startPollingSteps() {
						if (pollTimer) {
							window.clearTimeout(pollTimer);
							pollTimer = null;
						}

						postSyncAction('omega_cody_sync_step').then(function(payload) {
							if (!payload || payload.success !== true || !payload.data || !payload.data.state) {
								if (payload && payload.data && payload.data.message) {
									throw new Error(payload.data.message);
								}
								throw new Error('Invalid sync step response.');
							}

									var state = payload.data.state;
									if (state.status === 'running') {
										setTitleVisible(false);
										setStatus(buildProgressText(state));
										setProgress(buildProgressPercent(state));
										pollTimer = window.setTimeout(startPollingSteps, 1200);
										return;
									}

									if (state.status === 'success') {
										setProgress(100);
										setStatus(buildProgressText(state));
										setSyncButtonEnabled(true, 'Sync from Cody API');
									var summaryUrl = new window.URL(window.location.href);
									summaryUrl.searchParams.set('omega_cody_sync_status', 'success');
									summaryUrl.searchParams.set('processed', String(state.results.conversations_processed || 0));
									summaryUrl.searchParams.set('added', String(state.results.conversations_added || 0));
									summaryUrl.searchParams.set('skipped', String(state.results.conversations_skipped || 0));
									summaryUrl.searchParams.set('messages_added', String(state.results.messages_added || 0));
									window.setTimeout(function() {
										window.location.href = summaryUrl.toString();
									}, 500);
									return;
									}

									setStatus(state.progress_message || 'Sync failed.');
									setTitleVisible(true);
									setSyncButtonEnabled(true, 'Sync from Cody API');
								}).catch(function(error) {
									setStatus(error && error.message ? error.message : 'Sync request failed.');
									setTitleVisible(true);
									setSyncButtonEnabled(true, 'Sync from Cody API');
								});
							}

						if (syncForm && window.fetch && isConfigured) {
								syncForm.addEventListener('submit', function(event) {
									event.preventDefault();
									setTitleVisible(false);
									setSyncButtonEnabled(false, 'Syncing...');
									setProgress(0);
									setStatus('Starting sync...');

								postSyncAction('omega_cody_sync_start').then(function(payload) {
								if (!payload || payload.success !== true || !payload.data || !payload.data.state) {
									if (payload && payload.data && payload.data.message) {
										throw new Error(payload.data.message);
									}
									throw new Error('Unable to start sync.');
								}

									setStatus(buildProgressText(payload.data.state));
										setProgress(buildProgressPercent(payload.data.state));
										startPollingSteps();
									}).catch(function(error) {
										setStatus(error && error.message ? error.message : 'Could not start sync.');
										setTitleVisible(true);
										setSyncButtonEnabled(true, 'Sync from Cody API');
									});
								});
						}

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

					var rows = container.querySelectorAll('tr[data-omega-cody-thread-url]');
					function goToRow(rowElement) {
						if (!rowElement) {
							return;
						}

						var destination = rowElement.getAttribute('data-omega-cody-thread-url');
						if (!destination) {
							return;
						}

						window.sessionStorage.setItem(storageKey, String(container.scrollTop));
						window.location.href = destination;
					}

					for (var i = 0; i < rows.length; i++) {
						rows[i].addEventListener('click', function() {
							goToRow(this);
						});

						rows[i].addEventListener('keydown', function(event) {
							if (event.key !== 'Enter' && event.key !== ' ') {
								return;
							}

							event.preventDefault();
							goToRow(this);
						});

						rows[i].addEventListener('mousedown', function() {
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
					echo esc_html( $conversation_date_label );
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
}
