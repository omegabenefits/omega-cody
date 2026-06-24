<?php
/**
 * Main plugin orchestrator.
 *
 * @package Omega_Cody
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin runtime container.
 */
class Omega_Cody_Plugin {
	/**
	 * Storage service.
	 *
	 * @var Omega_Cody_Storage
	 */
	private $storage;

	/**
	 * API service.
	 *
	 * @var Omega_Cody_API_Client
	 */
	private $api_client;

	/**
	 * Sync service.
	 *
	 * @var Omega_Cody_Sync_Service
	 */
	private $sync_service;

	/**
	 * Admin service.
	 *
	 * @var Omega_Cody_Admin
	 */
	private $admin;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->storage      = new Omega_Cody_Storage();
		$this->api_client   = new Omega_Cody_API_Client();
		$this->sync_service = new Omega_Cody_Sync_Service( $this->api_client, $this->storage );
		$this->admin        = new Omega_Cody_Admin( $this->storage, $this->sync_service, $this->api_client );
	}

	/**
	 * Run plugin hooks.
	 *
	 * @return void
	 */
	public function run() {
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( $this, 'maybe_upgrade_storage_schema' ), 5 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_widget_script' ) );
		$this->admin->register_hooks();
	}

	/**
	 * Enqueue Cody widget embed script on the front end.
	 *
	 * @return void
	 */
	public function enqueue_widget_script() {
		if ( is_admin() ) {
			return;
		}

		$options   = omega_cody_get_options();
		$widget_id = trim( (string) $options['widget_id'] );
		if ( '' === $widget_id ) {
			return;
		}

		$handle = 'omega-cody-widget';
		wp_enqueue_script(
			$handle,
			'https://trinketsofcody.com/cody-widget.js',
			array(),
			null,
			true
		);
		wp_add_inline_script(
			$handle,
			'window.codySettings = { widget_id: ' . wp_json_encode( $widget_id ) . ' };',
			'before'
		);
	}

	/**
	 * Plugin activation callback.
	 *
	 * @return void
	 */
	public static function activate() {
		$storage = new Omega_Cody_Storage();
		$storage->create_tables();
		update_option( OMEGA_CODY_SCHEMA_VERSION_OPTION, OMEGA_CODY_SCHEMA_VERSION, false );
	}

	/**
	 * Load translation files.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'omega-cody',
			false,
			dirname( plugin_basename( OMEGA_CODY_PLUGIN_FILE ) ) . '/languages'
		);
	}

	/**
	 * Upgrade storage schema when the stored schema version changes.
	 *
	 * @return void
	 */
	public function maybe_upgrade_storage_schema() {
		if ( OMEGA_CODY_SCHEMA_VERSION === get_option( OMEGA_CODY_SCHEMA_VERSION_OPTION, '' ) ) {
			return;
		}

		$this->storage->create_tables();
		update_option( OMEGA_CODY_SCHEMA_VERSION_OPTION, OMEGA_CODY_SCHEMA_VERSION, false );
	}
}
