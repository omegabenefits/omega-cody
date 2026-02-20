<?php
/**
 * Sync coordinator for conversations and messages.
 *
 * @package Omega_Cody
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Synchronizes API data into local WordPress tables.
 */
class Omega_Cody_Sync_Service {
	/**
	 * Desired API page size.
	 *
	 * @var int
	 */
	const API_PER_PAGE = 100;

	/**
	 * Maximum pagination loops before aborting.
	 *
	 * @var int
	 */
	const MAX_PAGE_GUARD = 500;

	/**
	 * API client.
	 *
	 * @var Omega_Cody_API_Client
	 */
	private $api_client;

	/**
	 * Storage layer.
	 *
	 * @var Omega_Cody_Storage
	 */
	private $storage;

	/**
	 * Constructor.
	 *
	 * @param Omega_Cody_API_Client $api_client API client.
	 * @param Omega_Cody_Storage    $storage    Storage layer.
	 */
	public function __construct( Omega_Cody_API_Client $api_client, Omega_Cody_Storage $storage ) {
		$this->api_client = $api_client;
		$this->storage    = $storage;
	}

	/**
	 * Resolve bot name for a bot id using Cody API.
	 *
	 * @param string $api_key API key.
	 * @param string $bot_id  Bot id.
	 * @return string|WP_Error
	 */
	public function resolve_bot_name( $api_key, $bot_id ) {
		return $this->api_client->find_bot_name_by_id( $api_key, $bot_id );
	}

	/**
	 * Sync conversations and unsynced conversation messages.
	 *
	 * @param string $api_key Cody API key.
	 * @param string $bot_id  Cody bot id.
	 * @return array<string, int>|WP_Error
	 */
	public function sync_all( $api_key, $bot_id ) {
		$clean_api_key = trim( (string) $api_key );
		$clean_bot_id  = trim( (string) $bot_id );

		if ( '' === $clean_api_key || '' === $clean_bot_id ) {
			return new WP_Error( 'omega_cody_missing_credentials', __( 'Cody API key and Bot ID are required.', 'omega-cody' ) );
		}

		$results = array(
			'conversations_processed' => 0,
			'conversations_added'     => 0,
			'conversations_skipped'   => 0,
			'messages_added'          => 0,
		);

		$page      = 1;
		$page_guard = 0;

		while ( $page_guard < self::MAX_PAGE_GUARD ) {
			$response = $this->api_client->list_conversations( $clean_api_key, $clean_bot_id, $page, self::API_PER_PAGE );
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$conversations = $this->extract_data( $response );
			foreach ( $conversations as $conversation ) {
				if ( ! is_array( $conversation ) ) {
					continue;
				}

				++$results['conversations_processed'];

				$conversation_state = $this->storage->upsert_conversation( $conversation );
				if ( ! empty( $conversation_state['is_new'] ) ) {
					++$results['conversations_added'];
				}

				if ( ! empty( $conversation_state['is_synced'] ) ) {
					++$results['conversations_skipped'];
					continue;
				}

				$conversation_id = isset( $conversation_state['remote_id'] ) ? (string) $conversation_state['remote_id'] : '';
				if ( '' === $conversation_id ) {
					continue;
				}

				$messages_added = $this->sync_messages_for_conversation( $clean_api_key, $conversation_id );
				if ( is_wp_error( $messages_added ) ) {
					return $messages_added;
				}

				$results['messages_added'] += absint( $messages_added );
				$this->storage->mark_conversation_synced( $conversation_id );
			}

			$next_page = $this->extract_next_page( $response, $page, count( $conversations ) );
			if ( $next_page <= 0 ) {
				break;
			}

			$page = $next_page;
			++$page_guard;
		}

		return $results;
	}

	/**
	 * Sync all pages of messages for one conversation.
	 *
	 * @param string $api_key         API key.
	 * @param string $conversation_id Conversation id.
	 * @return int|WP_Error
	 */
	private function sync_messages_for_conversation( $api_key, $conversation_id ) {
		$messages_added = 0;
		$page           = 1;
		$page_guard     = 0;

		while ( $page_guard < self::MAX_PAGE_GUARD ) {
			$response = $this->api_client->list_messages( $api_key, $conversation_id, $page, self::API_PER_PAGE );
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$messages = $this->extract_data( $response );
			foreach ( $messages as $message ) {
				if ( ! is_array( $message ) ) {
					continue;
				}

				$is_inserted = $this->storage->insert_message_if_new( $message );
				if ( $is_inserted ) {
					++$messages_added;
				}
			}

			$next_page = $this->extract_next_page( $response, $page, count( $messages ) );
			if ( $next_page <= 0 ) {
				break;
			}

			$page = $next_page;
			++$page_guard;
		}

		return $messages_added;
	}

	/**
	 * Build initial sync state for step-based syncing.
	 *
	 * @return array<string, mixed>
	 */
	public function get_initial_sync_state() {
		return array(
			'status'                   => 'running',
			'phase'                    => 'conversations',
			'next_conversations_page'  => 1,
			'total_conversations_expected' => 0,
			'total_message_conversations' => 0,
			'current_message_conversation_number' => 0,
			'pending_conversation_ids' => array(),
			'current_conversation_id'  => '',
			'current_messages_page'    => 1,
			'conversation_page_guard'  => 0,
			'message_page_guard'       => 0,
			'progress_message'         => __( 'Starting sync...', 'omega-cody' ),
			'started_at_gmt'           => current_time( 'mysql', true ),
			'updated_at_gmt'           => current_time( 'mysql', true ),
			'finished_at_gmt'          => '',
			'results'                  => array(
				'conversations_processed' => 0,
				'conversations_added'     => 0,
				'conversations_skipped'   => 0,
				'conversations_message_synced' => 0,
				'messages_added'          => 0,
			),
		);
	}

	/**
	 * Process one incremental sync step.
	 *
	 * @param array<string, mixed> $state   Current sync state.
	 * @param string               $api_key Cody API key.
	 * @param string               $bot_id  Cody bot id.
	 * @return array<string, mixed>|WP_Error
	 */
	public function run_incremental_step( array $state, $api_key, $bot_id ) {
		$clean_api_key = trim( (string) $api_key );
		$clean_bot_id  = trim( (string) $bot_id );

		if ( '' === $clean_api_key || '' === $clean_bot_id ) {
			return new WP_Error( 'omega_cody_missing_credentials', __( 'Cody API key and Bot ID are required.', 'omega-cody' ) );
		}

		$state = $this->normalize_sync_state( $state );
		if ( 'running' !== $state['status'] ) {
			return $state;
		}

		if ( 'conversations' === $state['phase'] ) {
			return $this->run_conversation_step( $state, $clean_api_key, $clean_bot_id );
		}

		if ( 'messages' === $state['phase'] ) {
			return $this->run_message_step( $state, $clean_api_key );
		}

		$state['status']           = 'success';
		$state['progress_message'] = __( 'Sync complete.', 'omega-cody' );
		$state['updated_at_gmt']   = current_time( 'mysql', true );
		$state['finished_at_gmt']  = current_time( 'mysql', true );

		return $state;
	}

	/**
	 * Process one conversation-page sync step.
	 *
	 * @param array<string, mixed> $state   Current sync state.
	 * @param string               $api_key Cody API key.
	 * @param string               $bot_id  Cody bot id.
	 * @return array<string, mixed>|WP_Error
	 */
	private function run_conversation_step( array $state, $api_key, $bot_id ) {
		$response = $this->api_client->list_conversations(
			$api_key,
			$bot_id,
			$state['next_conversations_page'],
			self::API_PER_PAGE
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$total_conversations = $this->extract_total_items( $response );
		if ( $total_conversations > 0 ) {
			$state['total_conversations_expected'] = $total_conversations;
		}

		$conversations = $this->extract_data( $response );
		foreach ( $conversations as $conversation ) {
			if ( ! is_array( $conversation ) ) {
				continue;
			}

			++$state['results']['conversations_processed'];

			$conversation_state = $this->storage->upsert_conversation( $conversation );
			if ( ! empty( $conversation_state['is_new'] ) ) {
				++$state['results']['conversations_added'];
			}

			if ( ! empty( $conversation_state['is_synced'] ) ) {
				++$state['results']['conversations_skipped'];
				continue;
			}

			if ( ! empty( $conversation_state['remote_id'] ) ) {
				$state['pending_conversation_ids'][] = (string) $conversation_state['remote_id'];
			}
		}

		++$state['conversation_page_guard'];
		if ( $state['conversation_page_guard'] > self::MAX_PAGE_GUARD ) {
			return new WP_Error( 'omega_cody_pagination_guard', __( 'Conversation pagination limit reached.', 'omega-cody' ) );
		}

		$next_page = $this->extract_next_page(
			$response,
			$state['next_conversations_page'],
			count( $conversations )
		);

		if ( $next_page > 0 ) {
			$state['next_conversations_page'] = $next_page;
			$state['progress_message']        = sprintf(
				/* translators: %d: next conversation page number */
				__( 'Fetched conversations page. Moving to page %d...', 'omega-cody' ),
				$next_page
			);
			$state['updated_at_gmt'] = current_time( 'mysql', true );
			return $state;
		}

		$state['phase'] = 'messages';

		if ( empty( $state['pending_conversation_ids'] ) ) {
			$state['status']           = 'success';
			$state['progress_message'] = __( 'Sync complete. No new conversations required message sync.', 'omega-cody' );
			$state['updated_at_gmt']   = current_time( 'mysql', true );
			$state['finished_at_gmt']  = current_time( 'mysql', true );
			return $state;
		}

		$state['current_conversation_id'] = (string) array_shift( $state['pending_conversation_ids'] );
		$state['current_messages_page']   = 1;
		$state['total_message_conversations'] = count( $state['pending_conversation_ids'] ) + 1;
		$state['current_message_conversation_number'] = 1;
		$state['progress_message']        = sprintf(
			/* translators: 1: current conversation position, 2: total conversations in message phase */
			__( 'Importing messages for conversation %1$d of %2$d...', 'omega-cody' ),
			$state['current_message_conversation_number'],
			$state['total_message_conversations']
		);
		$state['updated_at_gmt']          = current_time( 'mysql', true );

		return $state;
	}

	/**
	 * Process one message-page sync step.
	 *
	 * @param array<string, mixed> $state   Current sync state.
	 * @param string               $api_key Cody API key.
	 * @return array<string, mixed>|WP_Error
	 */
	private function run_message_step( array $state, $api_key ) {
		if ( $state['total_message_conversations'] <= 0 ) {
			$state['total_message_conversations'] = count( $state['pending_conversation_ids'] );
			if ( '' !== $state['current_conversation_id'] ) {
				++$state['total_message_conversations'];
			}
		}

		if ( '' === $state['current_conversation_id'] ) {
			if ( empty( $state['pending_conversation_ids'] ) ) {
				$state['status']           = 'success';
				$state['progress_message'] = __( 'Sync complete.', 'omega-cody' );
				$state['updated_at_gmt']   = current_time( 'mysql', true );
				$state['finished_at_gmt']  = current_time( 'mysql', true );
				return $state;
			}

			$state['current_conversation_id'] = (string) array_shift( $state['pending_conversation_ids'] );
			$state['current_messages_page']   = 1;
			if ( $state['current_message_conversation_number'] <= 0 ) {
				$state['current_message_conversation_number'] = 1;
			}
		}

		$response = $this->api_client->list_messages(
			$api_key,
			$state['current_conversation_id'],
			$state['current_messages_page'],
			self::API_PER_PAGE
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$messages = $this->extract_data( $response );
		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}

			if ( $this->storage->insert_message_if_new( $message ) ) {
				++$state['results']['messages_added'];
			}
		}

		++$state['message_page_guard'];
		if ( $state['message_page_guard'] > self::MAX_PAGE_GUARD * 50 ) {
			return new WP_Error( 'omega_cody_pagination_guard', __( 'Message pagination limit reached.', 'omega-cody' ) );
		}

		$next_message_page = $this->extract_next_page(
			$response,
			$state['current_messages_page'],
			count( $messages )
		);

		if ( $next_message_page > 0 ) {
			$state['current_messages_page'] = $next_message_page;
			$state['progress_message']      = sprintf(
				/* translators: 1: current conversation position, 2: total conversations in message phase, 3: page number */
				__( 'Syncing messages for conversation %1$d of %2$d (page %3$d)...', 'omega-cody' ),
				$state['current_message_conversation_number'],
				$state['total_message_conversations'],
				$next_message_page
			);
			$state['updated_at_gmt'] = current_time( 'mysql', true );
			return $state;
		}

		$this->storage->mark_conversation_synced( $state['current_conversation_id'] );
		++$state['results']['conversations_message_synced'];

		if ( empty( $state['pending_conversation_ids'] ) ) {
			$state['status']                  = 'success';
			$state['progress_message']        = __( 'Sync complete.', 'omega-cody' );
			$state['current_conversation_id'] = '';
			$state['current_messages_page']   = 1;
			$state['updated_at_gmt']          = current_time( 'mysql', true );
			$state['finished_at_gmt']         = current_time( 'mysql', true );
			return $state;
		}

		$state['current_conversation_id'] = (string) array_shift( $state['pending_conversation_ids'] );
		$state['current_messages_page']   = 1;
		++$state['current_message_conversation_number'];
		$state['progress_message']        = sprintf(
			/* translators: 1: current conversation position, 2: total conversations in message phase */
			__( 'Importing messages for conversation %1$d of %2$d...', 'omega-cody' ),
			$state['current_message_conversation_number'],
			$state['total_message_conversations']
		);
		$state['updated_at_gmt'] = current_time( 'mysql', true );

		return $state;
	}

	/**
	 * Extract list payload from API response.
	 *
	 * @param array<string, mixed> $response API response.
	 * @return array<int, mixed>
	 */
	private function extract_data( array $response ) {
		if ( ! isset( $response['data'] ) || ! is_array( $response['data'] ) ) {
			return array();
		}

		return $response['data'];
	}

	/**
	 * Extract next page from API response.
	 *
	 * @param array<string, mixed> $response API response.
	 * @param int                  $current_page Current page number.
	 * @param int                  $fetched_count Number of items fetched in page.
	 * @return int
	 */
	private function extract_next_page( array $response, $current_page, $fetched_count ) {
		$clean_current_page = max( 1, absint( $current_page ) );

		if ( isset( $response['meta'] ) && is_array( $response['meta'] ) ) {
			$meta = $response['meta'];

			$direct_next_page = isset( $meta['next_page'] ) ? absint( $meta['next_page'] ) : 0;
			if ( $direct_next_page > $clean_current_page ) {
				return $direct_next_page;
			}

			if ( isset( $meta['pagination'] ) && is_array( $meta['pagination'] ) ) {
				$pagination = $meta['pagination'];
				$next_page  = isset( $pagination['next_page'] ) ? absint( $pagination['next_page'] ) : 0;
				if ( $next_page > $clean_current_page ) {
					return $next_page;
				}

				$current_meta_page = isset( $pagination['current_page'] ) ? absint( $pagination['current_page'] ) : 0;
				$total_pages       = isset( $pagination['total_pages'] ) ? absint( $pagination['total_pages'] ) : 0;
				if ( $current_meta_page > 0 && $total_pages > 0 && $current_meta_page < $total_pages ) {
					return $current_meta_page + 1;
				}
			}
		}

		if ( isset( $response['links'] ) && is_array( $response['links'] ) && ! empty( $response['links']['next'] ) && is_string( $response['links']['next'] ) ) {
			$parsed_next_url = wp_parse_url( $response['links']['next'] );
			if ( ! empty( $parsed_next_url['query'] ) ) {
				parse_str( (string) $parsed_next_url['query'], $query_args );
				if ( isset( $query_args['page'] ) ) {
					$link_next_page = absint( $query_args['page'] );
					if ( $link_next_page > $clean_current_page ) {
						return $link_next_page;
					}
				}
			}
		}

		// Final fallback: if a full page was received, try next page.
		if ( absint( $fetched_count ) >= self::API_PER_PAGE ) {
			return $clean_current_page + 1;
		}

		return 0;
	}

	/**
	 * Extract total expected items from response metadata.
	 *
	 * @param array<string, mixed> $response API response.
	 * @return int
	 */
	private function extract_total_items( array $response ) {
		if ( ! isset( $response['meta'] ) || ! is_array( $response['meta'] ) ) {
			return 0;
		}

		$meta = $response['meta'];

		if ( isset( $meta['total'] ) ) {
			$total = absint( $meta['total'] );
			if ( $total > 0 ) {
				return $total;
			}
		}

		if ( isset( $meta['pagination'] ) && is_array( $meta['pagination'] ) && isset( $meta['pagination']['total'] ) ) {
			return absint( $meta['pagination']['total'] );
		}

		return 0;
	}

	/**
	 * Normalize stored sync state.
	 *
	 * @param array<string, mixed> $state Sync state.
	 * @return array<string, mixed>
	 */
	private function normalize_sync_state( array $state ) {
		$defaults = $this->get_initial_sync_state();
		$results  = isset( $state['results'] ) && is_array( $state['results'] ) ? $state['results'] : array();

		$state = wp_parse_args( $state, $defaults );

		$state['results'] = wp_parse_args(
			$results,
			$defaults['results']
		);

		if ( ! is_array( $state['pending_conversation_ids'] ) ) {
			$state['pending_conversation_ids'] = array();
		}

		$normalized_ids = array();
		foreach ( $state['pending_conversation_ids'] as $conversation_id ) {
			$clean_id = sanitize_text_field( (string) $conversation_id );
			if ( '' !== $clean_id ) {
				$normalized_ids[] = $clean_id;
			}
		}
		$state['pending_conversation_ids'] = $normalized_ids;

		$state['status']                   = sanitize_key( (string) $state['status'] );
		$state['phase']                    = sanitize_key( (string) $state['phase'] );
		$state['next_conversations_page']  = max( 1, absint( $state['next_conversations_page'] ) );
		$state['total_conversations_expected'] = absint( $state['total_conversations_expected'] );
		$state['total_message_conversations'] = absint( $state['total_message_conversations'] );
		$state['current_message_conversation_number'] = absint( $state['current_message_conversation_number'] );
		$state['current_conversation_id']  = sanitize_text_field( (string) $state['current_conversation_id'] );
		$state['current_messages_page']    = max( 1, absint( $state['current_messages_page'] ) );
		$state['conversation_page_guard']  = absint( $state['conversation_page_guard'] );
		$state['message_page_guard']       = absint( $state['message_page_guard'] );
		$state['progress_message']         = sanitize_text_field( (string) $state['progress_message'] );
		$state['started_at_gmt']           = sanitize_text_field( (string) $state['started_at_gmt'] );
		$state['updated_at_gmt']           = sanitize_text_field( (string) $state['updated_at_gmt'] );
		$state['finished_at_gmt']          = sanitize_text_field( (string) $state['finished_at_gmt'] );
		$state['results']['conversations_processed'] = absint( $state['results']['conversations_processed'] );
		$state['results']['conversations_added']     = absint( $state['results']['conversations_added'] );
		$state['results']['conversations_skipped']   = absint( $state['results']['conversations_skipped'] );
		$state['results']['conversations_message_synced'] = absint( $state['results']['conversations_message_synced'] );
		$state['results']['messages_added']          = absint( $state['results']['messages_added'] );

		return $state;
	}
}
