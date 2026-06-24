<?php
/**
 * Persistence layer for Cody conversations/messages.
 *
 * @package Omega_Cody
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Local database operations.
 */
class Omega_Cody_Storage {
	/**
	 * WordPress database object.
	 *
	 * @var wpdb
	 */
	private $wpdb;

	/**
	 * Conversation table name.
	 *
	 * @var string
	 */
	private $conversations_table;

	/**
	 * Messages table name.
	 *
	 * @var string
	 */
	private $messages_table;

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;

		$this->wpdb                = $wpdb;
		$this->conversations_table = $wpdb->prefix . 'chatbot_conversations';
		$this->messages_table      = $wpdb->prefix . 'chatbot_messages';
	}

	/**
	 * Create required plugin tables.
	 *
	 * @return void
	 */
	public function create_tables() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $this->wpdb->get_charset_collate();

		$conversations_sql = "CREATE TABLE {$this->conversations_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			remote_id varchar(191) NOT NULL,
			name varchar(255) NOT NULL DEFAULT '',
			bot_id varchar(191) NOT NULL DEFAULT '',
			remote_created_at bigint(20) unsigned DEFAULT NULL,
			remote_created_at_gmt datetime DEFAULT NULL,
			synced_at_gmt datetime DEFAULT NULL,
			raw_data longtext DEFAULT NULL,
			created_at_gmt datetime NOT NULL,
			updated_at_gmt datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY remote_id (remote_id),
			KEY bot_id (bot_id),
			KEY synced_at_gmt (synced_at_gmt)
		) {$charset_collate};";

		$messages_sql = "CREATE TABLE {$this->messages_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			remote_id varchar(191) NOT NULL,
			conversation_remote_id varchar(191) NOT NULL,
			content longtext DEFAULT NULL,
			machine tinyint(1) unsigned NOT NULL DEFAULT 0,
			failed_responding tinyint(1) unsigned NOT NULL DEFAULT 0,
			flagged tinyint(1) unsigned NOT NULL DEFAULT 0,
			remote_created_at bigint(20) unsigned DEFAULT NULL,
			remote_created_at_gmt datetime DEFAULT NULL,
			raw_data longtext DEFAULT NULL,
			created_at_gmt datetime NOT NULL,
			updated_at_gmt datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY remote_id (remote_id),
			KEY conversation_remote_id (conversation_remote_id),
			KEY remote_created_at (remote_created_at)
		) {$charset_collate};";

		dbDelta( $conversations_sql );
		dbDelta( $messages_sql );
	}

	/**
	 * Delete all stored conversation and message rows.
	 *
	 * @return bool
	 */
	public function reset_all_data() {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name is trusted plugin constant.
		$messages_deleted = $this->wpdb->query( "DELETE FROM {$this->messages_table}" );
		if ( false === $messages_deleted ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name is trusted plugin constant.
		$conversations_deleted = $this->wpdb->query( "DELETE FROM {$this->conversations_table}" );
		if ( false === $conversations_deleted ) {
			return false;
		}

		return true;
	}

	/**
	 * Insert or update a conversation by remote id.
	 *
	 * @param array<string, mixed> $conversation Conversation payload from API.
	 * @return array<string, mixed>
	 */
	public function upsert_conversation( array $conversation ) {
		$remote_id = isset( $conversation['id'] ) ? sanitize_text_field( (string) $conversation['id'] ) : '';
		if ( '' === $remote_id ) {
			return array(
				'is_new'    => false,
				'is_synced' => true,
				'remote_id' => '',
			);
		}

		$existing = $this->get_conversation_by_remote_id( $remote_id );
		$now_gmt  = current_time( 'mysql', true );

		$data = array(
			'remote_id'             => $remote_id,
			'name'                  => isset( $conversation['name'] ) ? sanitize_text_field( (string) $conversation['name'] ) : '',
			'bot_id'                => isset( $conversation['bot_id'] ) ? sanitize_text_field( (string) $conversation['bot_id'] ) : '',
			'remote_created_at'     => isset( $conversation['created_at'] ) ? absint( $conversation['created_at'] ) : null,
			'remote_created_at_gmt' => $this->convert_unix_to_mysql( isset( $conversation['created_at'] ) ? absint( $conversation['created_at'] ) : 0 ),
			'raw_data'              => wp_json_encode( $conversation ),
			'updated_at_gmt'        => $now_gmt,
		);

		if ( $existing ) {
			$this->wpdb->update(
				$this->conversations_table,
				$data,
				array(
					'id' => absint( $existing->id ),
				),
				array(
					'%s',
					'%s',
					'%s',
					'%d',
					'%s',
					'%s',
					'%s',
				),
				array( '%d' )
			);

			return array(
				'is_new'    => false,
				'is_synced' => ! empty( $existing->synced_at_gmt ),
				'remote_id' => $remote_id,
			);
		}

		$data['created_at_gmt'] = $now_gmt;
		$data['synced_at_gmt']  = null;

		$this->wpdb->insert(
			$this->conversations_table,
			$data,
			array(
				'%s',
				'%s',
				'%s',
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
			)
		);

		return array(
			'is_new'    => true,
			'is_synced' => false,
			'remote_id' => $remote_id,
		);
	}

	/**
	 * Mark one conversation as synced.
	 *
	 * @param string $remote_id Conversation remote id.
	 * @return void
	 */
	public function mark_conversation_synced( $remote_id ) {
		$clean_remote_id = sanitize_text_field( (string) $remote_id );
		if ( '' === $clean_remote_id ) {
			return;
		}

		$now_gmt = current_time( 'mysql', true );

		$this->wpdb->update(
			$this->conversations_table,
			array(
				'synced_at_gmt'  => $now_gmt,
				'updated_at_gmt' => $now_gmt,
			),
			array(
				'remote_id' => $clean_remote_id,
			),
			array(
				'%s',
				'%s',
			),
			array( '%s' )
		);
	}

	/**
	 * Insert one message only when not already present.
	 *
	 * @param array<string, mixed> $message Message payload from API.
	 * @return bool
	 */
	public function insert_message_if_new( array $message ) {
		$remote_id             = isset( $message['id'] ) ? sanitize_text_field( (string) $message['id'] ) : '';
		$conversation_remote_id = isset( $message['conversation_id'] ) ? sanitize_text_field( (string) $message['conversation_id'] ) : '';

		if ( '' === $remote_id || '' === $conversation_remote_id ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is trusted plugin constant.
		$existing_message_id = $this->wpdb->get_var( $this->wpdb->prepare( "SELECT id FROM {$this->messages_table} WHERE remote_id = %s LIMIT 1", $remote_id ) );
		if ( ! empty( $existing_message_id ) ) {
			return false;
		}

		$now_gmt = current_time( 'mysql', true );
		$insert  = $this->wpdb->insert(
			$this->messages_table,
			array(
				'remote_id'             => $remote_id,
				'conversation_remote_id' => $conversation_remote_id,
				'content'               => isset( $message['content'] ) ? (string) $message['content'] : '',
				'machine'               => ! empty( $message['machine'] ) ? 1 : 0,
				'failed_responding'     => ! empty( $message['failed_responding'] ) ? 1 : 0,
				'flagged'               => ! empty( $message['flagged'] ) ? 1 : 0,
				'remote_created_at'     => isset( $message['created_at'] ) ? absint( $message['created_at'] ) : null,
				'remote_created_at_gmt' => $this->convert_unix_to_mysql( isset( $message['created_at'] ) ? absint( $message['created_at'] ) : 0 ),
				'raw_data'              => wp_json_encode( $message ),
				'created_at_gmt'        => $now_gmt,
				'updated_at_gmt'        => $now_gmt,
			),
			array(
				'%s',
				'%s',
				'%s',
				'%d',
				'%d',
				'%d',
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
			)
		);

		return false !== $insert;
	}

	/**
	 * Get one conversation by remote id.
	 *
	 * @param string $remote_id Conversation remote id.
	 * @return object|null
	 */
	public function get_conversation_by_remote_id( $remote_id ) {
		$clean_remote_id = sanitize_text_field( (string) $remote_id );
		if ( '' === $clean_remote_id ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is trusted plugin constant.
		$query = $this->wpdb->prepare( "SELECT * FROM {$this->conversations_table} WHERE remote_id = %s LIMIT 1", $clean_remote_id );
		return $this->wpdb->get_row( $query );
	}

	/**
	 * Get total conversation count.
	 *
	 * @return int
	 */
	public function get_conversation_count() {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is trusted plugin constant.
		$total = $this->wpdb->get_var( "SELECT COUNT(id) FROM {$this->conversations_table}" );
		return absint( $total );
	}

	/**
	 * Fetch paginated conversations with message counts.
	 *
	 * @param int $page     Page number.
	 * @param int $per_page Rows per page.
	 * @return array<int, object>
	 */
	public function get_conversations( $page = 1, $per_page = 20 ) {
		$page     = max( 1, absint( $page ) );
		$per_page = max( 1, absint( $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are trusted plugin constants.
		$query = $this->wpdb->prepare(
			"SELECT
				c.*,
				COALESCE(m.message_count, 0) AS message_count,
				(
					SELECT um.content
					FROM {$this->messages_table} um
					WHERE um.conversation_remote_id = c.remote_id
						AND um.machine = 0
					ORDER BY um.remote_created_at ASC, um.id ASC
					LIMIT 1
				) AS first_user_message
			FROM {$this->conversations_table} c
			LEFT JOIN (
				SELECT conversation_remote_id, COUNT(id) AS message_count
				FROM {$this->messages_table}
				GROUP BY conversation_remote_id
			) m
				ON m.conversation_remote_id = c.remote_id
			ORDER BY c.remote_created_at DESC, c.id DESC
			LIMIT %d OFFSET %d",
			$per_page,
			$offset
		);

		$rows = $this->wpdb->get_results( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared above.
		if ( ! is_array( $rows ) ) {
			return array();
		}

		return $rows;
	}

	/**
	 * Fetch all messages for one conversation.
	 *
	 * @param string $conversation_remote_id Conversation remote id.
	 * @return array<int, object>
	 */
	public function get_messages_by_conversation( $conversation_remote_id ) {
		$clean_conversation_id = sanitize_text_field( (string) $conversation_remote_id );
		if ( '' === $clean_conversation_id ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is trusted plugin constant.
		$query = $this->wpdb->prepare(
			"SELECT *
			FROM {$this->messages_table}
			WHERE conversation_remote_id = %s
			ORDER BY remote_created_at ASC, id ASC",
			$clean_conversation_id
		);

		$rows = $this->wpdb->get_results( $query );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		return $rows;
	}

	/**
	 * Convert unix timestamp to mysql datetime in GMT.
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string|null
	 */
	private function convert_unix_to_mysql( $timestamp ) {
		$clean_timestamp = absint( $timestamp );
		if ( $clean_timestamp <= 0 ) {
			return null;
		}

		return gmdate( 'Y-m-d H:i:s', $clean_timestamp );
	}
}
