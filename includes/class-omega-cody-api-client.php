<?php
/**
 * Cody API client.
 *
 * @package Omega_Cody
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HTTP wrapper for Cody API requests.
 */
class Omega_Cody_API_Client {
	/**
	 * Cody API base url.
	 *
	 * @var string
	 */
	const BASE_URL = 'https://getcody.ai/api/v1';

	/**
	 * Fetch one page of conversations.
	 *
	 * @param string $api_key API key.
	 * @param string $bot_id  Bot id.
	 * @param int    $page    Page number.
	 * @param int    $per_page Items per page.
	 * @return array<string, mixed>|WP_Error
	 */
	public function list_conversations( $api_key, $bot_id, $page = 1, $per_page = 100 ) {
		return $this->request(
			'/conversations',
			$api_key,
			array(
				'bot_id'   => sanitize_text_field( (string) $bot_id ),
				'page'     => max( 1, absint( $page ) ),
				'per_page' => max( 1, absint( $per_page ) ),
			)
		);
	}

	/**
	 * Fetch one page of messages for a conversation.
	 *
	 * @param string $api_key         API key.
	 * @param string $conversation_id Conversation id.
	 * @param int    $page            Page number.
	 * @param int    $per_page        Items per page.
	 * @return array<string, mixed>|WP_Error
	 */
	public function list_messages( $api_key, $conversation_id, $page = 1, $per_page = 100 ) {
		return $this->request(
			'/messages',
			$api_key,
			array(
				'conversation_id' => sanitize_text_field( (string) $conversation_id ),
				'page'            => max( 1, absint( $page ) ),
				'per_page'        => max( 1, absint( $per_page ) ),
			)
		);
	}

	/**
	 * Fetch one page of bots.
	 *
	 * @param string $api_key  API key.
	 * @param int    $page     Page number.
	 * @param int    $per_page Items per page.
	 * @return array<string, mixed>|WP_Error
	 */
	public function list_bots( $api_key, $page = 1, $per_page = 100 ) {
		return $this->request(
			'/bots',
			$api_key,
			array(
				'page'     => max( 1, absint( $page ) ),
				'per_page' => max( 1, absint( $per_page ) ),
			)
		);
	}

	/**
	 * Find a bot name by bot id.
	 *
	 * @param string $api_key API key.
	 * @param string $bot_id  Bot id.
	 * @return string|WP_Error
	 */
	public function find_bot_name_by_id( $api_key, $bot_id ) {
		$clean_bot_id = sanitize_text_field( (string) $bot_id );
		if ( '' === $clean_bot_id ) {
			return '';
		}

		$page      = 1;
		$per_page  = 100;
		$page_guard = 0;

		while ( $page_guard < 100 ) {
			$response = $this->list_bots( $api_key, $page, $per_page );
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$bots = $this->extract_data( $response );
			foreach ( $bots as $bot ) {
				if ( ! is_array( $bot ) || ! isset( $bot['id'] ) ) {
					continue;
				}

				if ( $clean_bot_id !== sanitize_text_field( (string) $bot['id'] ) ) {
					continue;
				}

				if ( ! isset( $bot['name'] ) ) {
					return '';
				}

				return sanitize_text_field( (string) $bot['name'] );
			}

			$next_page = $this->extract_next_page( $response, $page, count( $bots ), $per_page );
			if ( $next_page <= $page ) {
				break;
			}

			$page = $next_page;
			++$page_guard;
		}

		return '';
	}

	/**
	 * Perform one authenticated GET request.
	 *
	 * @param string               $path    Endpoint path.
	 * @param string               $api_key API key.
	 * @param array<string, mixed> $query   Query arguments.
	 * @return array<string, mixed>|WP_Error
	 */
	private function request( $path, $api_key, array $query = array() ) {
		$clean_api_key = trim( (string) $api_key );
		if ( '' === $clean_api_key ) {
			return new WP_Error( 'omega_cody_missing_api_key', __( 'Missing Cody API key.', 'omega-cody' ) );
		}

		$endpoint = trailingslashit( self::BASE_URL ) . ltrim( (string) $path, '/' );
		$url      = add_query_arg( $this->clean_query_values( $query ), $endpoint );

		$response = wp_remote_get(
			esc_url_raw( $url ),
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $clean_api_key,
					'Accept'        => 'application/json',
				),
				'timeout' => 25,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$decoded     = json_decode( $body, true );

		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'omega_cody_invalid_json', __( 'Invalid JSON response from Cody API.', 'omega-cody' ) );
		}

		if ( $status_code < 200 || $status_code >= 300 ) {
			$error_message = __( 'Cody API request failed.', 'omega-cody' );
			if ( isset( $decoded['message'] ) && is_string( $decoded['message'] ) ) {
				$error_message = sanitize_text_field( wp_strip_all_tags( $decoded['message'] ) );
			}

			return new WP_Error(
				'omega_cody_api_error',
				$error_message,
				array(
					'status_code' => $status_code,
				)
			);
		}

		return $decoded;
	}

	/**
	 * Remove empty query values.
	 *
	 * @param array<string, mixed> $query Query arguments.
	 * @return array<string, mixed>
	 */
	private function clean_query_values( array $query ) {
		$clean_query = array();

		foreach ( $query as $key => $value ) {
			if ( null === $value ) {
				continue;
			}

			if ( '' === (string) $value ) {
				continue;
			}

			$clean_query[ $key ] = $value;
		}

		return $clean_query;
	}

	/**
	 * Extract data array from API response.
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
	 * @param array<string, mixed> $response      API response.
	 * @param int                  $current_page  Current page.
	 * @param int                  $fetched_count Fetched row count.
	 * @param int                  $per_page      Requested rows per page.
	 * @return int
	 */
	private function extract_next_page( array $response, $current_page, $fetched_count, $per_page ) {
		$clean_current_page = max( 1, absint( $current_page ) );
		$clean_per_page     = max( 1, absint( $per_page ) );

		if ( isset( $response['meta'] ) && is_array( $response['meta'] ) ) {
			$meta = $response['meta'];

			if ( isset( $meta['next_page'] ) ) {
				$next_page = absint( $meta['next_page'] );
				if ( $next_page > $clean_current_page ) {
					return $next_page;
				}
			}

			if ( isset( $meta['pagination'] ) && is_array( $meta['pagination'] ) ) {
				$pagination = $meta['pagination'];

				if ( isset( $pagination['next_page'] ) ) {
					$next_page = absint( $pagination['next_page'] );
					if ( $next_page > $clean_current_page ) {
						return $next_page;
					}
				}

				$current_meta_page = isset( $pagination['current_page'] ) ? absint( $pagination['current_page'] ) : 0;
				$total_pages       = isset( $pagination['total_pages'] ) ? absint( $pagination['total_pages'] ) : 0;
				if ( $current_meta_page > 0 && $total_pages > 0 && $current_meta_page < $total_pages ) {
					return $current_meta_page + 1;
				}
			}
		}

		if ( isset( $response['links'] ) && is_array( $response['links'] ) && ! empty( $response['links']['next'] ) && is_string( $response['links']['next'] ) ) {
			$next_link_url = wp_parse_url( $response['links']['next'] );
			if ( ! empty( $next_link_url['query'] ) ) {
				parse_str( (string) $next_link_url['query'], $query_vars );
				if ( isset( $query_vars['page'] ) ) {
					$next_page = absint( $query_vars['page'] );
					if ( $next_page > $clean_current_page ) {
						return $next_page;
					}
				}
			}
		}

		if ( absint( $fetched_count ) >= $clean_per_page ) {
			return $clean_current_page + 1;
		}

		return 0;
	}
}
