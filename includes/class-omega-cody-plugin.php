<?php
/**
 * Main plugin orchestrator.
 *
 * @package OmegaCody
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
		$this->admin        = new Omega_Cody_Admin( $this->storage, $this->sync_service );
	}

	/**
	 * Run plugin hooks.
	 *
	 * @return void
	 */
	public function run() {
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		$this->admin->register_hooks();
	}

	/**
	 * Plugin activation callback.
	 *
	 * @return void
	 */
	public static function activate() {
		$storage = new Omega_Cody_Storage();
		$storage->create_tables();
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
}
