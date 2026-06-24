<?php
/**
 * Self-hosted plugin update checking.
 *
 * @package Omega_Cody
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

/**
 * Registers the update checker against the Omega update server.
 */
class Omega_Cody_Updater {
	/**
	 * Default metadata endpoint served by the Omega wp-update-server install.
	 *
	 * @var string
	 */
	const METADATA_URL = 'https://omegabenefits.net/wp-update-server/?action=get_metadata&slug=omega-cody';

	/**
	 * Plugin slug the update server keys on.
	 *
	 * @var string
	 */
	const SLUG = 'omega-cody';

	/**
	 * Update checker instance.
	 *
	 * @var \YahnisElsts\PluginUpdateChecker\v5p7\Plugin\UpdateChecker|null
	 */
	private $checker = null;

	/**
	 * Load the library and build the checker.
	 *
	 * @return void
	 */
	public function init() {
		require_once OMEGA_CODY_PLUGIN_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';

		/**
		 * Filter the update server metadata URL.
		 *
		 * @param string $url Default Omega update-server metadata endpoint.
		 */
		$metadata_url = apply_filters( 'omega_cody_update_metadata_url', self::METADATA_URL );

		$this->checker = PucFactory::buildUpdateChecker(
			$metadata_url,
			OMEGA_CODY_PLUGIN_FILE,
			self::SLUG
		);
	}

	/**
	 * Expose the checker for manual integrations.
	 *
	 * @return \YahnisElsts\PluginUpdateChecker\v5p7\Plugin\UpdateChecker|null
	 */
	public function checker() {
		return $this->checker;
	}
}
