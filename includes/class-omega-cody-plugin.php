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
		$this->admin        = new Omega_Cody_Admin( $this->storage, $this->sync_service );
	}

	/**
	 * Run plugin hooks.
	 *
	 * @return void
	 */
	public function run() {
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( $this, 'maybe_create_storage_tables' ), 5 );
		add_action( 'wp_footer', array( $this, 'render_widget_script' ) );
		$this->admin->register_hooks();
	}

	/**
	 * Render Cody widget embed script on the front end.
	 *
	 * @return void
	 */
	public function render_widget_script() {
		if ( is_admin() ) {
			return;
		}

		$options   = omega_cody_get_options();
		$widget_id = trim( (string) $options['widget_id'] );
		if ( '' === $widget_id ) {
			return;
		}
		?>
		<script>
		window.codySettings = { widget_id: <?php echo wp_json_encode( $widget_id ); ?> };

		!function(){var t=window,e=document,a=function(){var t=e.createElement("script");t.type="text/javascript",t.async=!0,t.src="https://trinketsofcody.com/cody-widget.js";var a=e.getElementsByTagName("script")[0];a.parentNode.insertBefore(t,a)};"complete"===document.readyState?a():t.attachEvent?t.attachEvent("onload",a):t.addEventListener("load",a,!1)}();
		</script>
		<?php
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

	/**
	 * Ensure storage tables exist for current schema naming.
	 *
	 * @return void
	 */
	public function maybe_create_storage_tables() {
		$this->storage->maybe_create_tables();
	}
}
