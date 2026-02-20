<?php
/**
 * Sync coordinator for conversations and messages.
 *
 * @package OmegaCody
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

		while ( $page_guard < 500 ) {
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

		while ( $page_guard < 500 ) {
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
}
