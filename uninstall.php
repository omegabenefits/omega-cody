<?php
/**
 * Uninstall cleanup for OMEGA Chatbot Logs.
 *
 * @package Omega_Cody
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'omega_cody_options' );
delete_option( 'omega_cody_sync_state' );
delete_option( 'omega_cody_last_sync_gmt' );
delete_option( 'omega_cody_schema_version' );

global $wpdb;

$messages_table      = $wpdb->prefix . 'chatbot_messages';
$conversations_table = $wpdb->prefix . 'chatbot_conversations';

// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are built from the trusted WordPress prefix.
$wpdb->query( "DROP TABLE IF EXISTS {$messages_table}" );
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names are built from the trusted WordPress prefix.
$wpdb->query( "DROP TABLE IF EXISTS {$conversations_table}" );
