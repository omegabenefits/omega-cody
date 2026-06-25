<?php
/**
 * Plugin Name: OMEGA Chatbot Logs
 * Description: Retrieve, store, and display CodyAI chatbot conversation threads in WordPress admin.
 * Version: 1.4
 * Author: Omega Benefits
 * Text Domain: omega-cody
 *
 * @package Omega_Cody
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OMEGA_CODY_VERSION', '1.4' );
define( 'OMEGA_CODY_PLUGIN_FILE', __FILE__ );
define( 'OMEGA_CODY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'OMEGA_CODY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'OMEGA_CODY_OPTION_NAME', 'omega_cody_options' );
define( 'OMEGA_CODY_SYNC_STATE_OPTION', 'omega_cody_sync_state' );
define( 'OMEGA_CODY_LAST_SYNC_OPTION', 'omega_cody_last_sync_gmt' );
define( 'OMEGA_CODY_SCHEMA_VERSION_OPTION', 'omega_cody_schema_version' );
define( 'OMEGA_CODY_SCHEMA_VERSION', '1' );

require_once OMEGA_CODY_PLUGIN_DIR . 'includes/class-omega-cody-storage.php';
require_once OMEGA_CODY_PLUGIN_DIR . 'includes/class-omega-cody-api-client.php';
require_once OMEGA_CODY_PLUGIN_DIR . 'includes/class-omega-cody-sync-service.php';
require_once OMEGA_CODY_PLUGIN_DIR . 'includes/class-omega-cody-admin.php';
require_once OMEGA_CODY_PLUGIN_DIR . 'includes/class-omega-cody-updater.php';
require_once OMEGA_CODY_PLUGIN_DIR . 'includes/class-omega-cody-plugin.php';

/**
 * Return plugin options with defaults.
 *
 * @return array<string, string>
 */
function omega_cody_get_options() {
	$defaults = array(
		'api_key'   => '',
		'bot_id'    => '',
		'bot_name'  => '',
		'widget_id' => '',
	);

	$options = get_option( OMEGA_CODY_OPTION_NAME, array() );
	if ( ! is_array( $options ) ) {
		$options = array();
	}

	return wp_parse_args( $options, $defaults );
}

/**
 * Bootstrap plugin services.
 *
 * @return void
 */
function omega_cody_run_plugin() {
	$plugin = new Omega_Cody_Plugin();
	$plugin->run();
}

register_activation_hook( __FILE__, array( 'Omega_Cody_Plugin', 'activate' ) );

omega_cody_run_plugin();
