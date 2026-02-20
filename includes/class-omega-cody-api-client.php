<?php
/**
 * Cody API client.
 *
 * @package OmegaCody
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
}
