<?php
/**
 * Bonsai Hub loader.
 *
 * Every Bonsai plugin bundles a copy of this library in lib/bonsai-hub/ and
 * requires this file from its main plugin file. Each copy registers itself
 * as a candidate; on plugins_loaded only the highest version boots. So
 * installing any one Bonsai plugin gives the site the shared "Bonsai" admin
 * menu, and plugins bundling different hub versions never clash.
 *
 * Keep this file small and backwards compatible: older copies of it run
 * alongside newer ones, and the first copy to load defines bonsai_hub_boot().
 *
 * @package Bonsai_Hub
 */

defined( 'ABSPATH' ) || exit;

$bonsai_hub_candidate_version = '1.0.0';

if ( ! isset( $GLOBALS['bonsai_hub_candidates'] ) || ! is_array( $GLOBALS['bonsai_hub_candidates'] ) ) {
	$GLOBALS['bonsai_hub_candidates'] = array();
}

// Two copies of the same version are the same code, so the first one wins.
if ( ! isset( $GLOBALS['bonsai_hub_candidates'][ $bonsai_hub_candidate_version ] ) ) {
	$GLOBALS['bonsai_hub_candidates'][ $bonsai_hub_candidate_version ] = array(
		'dir' => __DIR__,
		'url' => plugin_dir_url( __FILE__ ),
	);
}

unset( $bonsai_hub_candidate_version );

if ( ! function_exists( 'bonsai_hub_boot' ) ) {
	/**
	 * Boots the newest registered copy of the hub. Runs once, early on
	 * plugins_loaded, after every active plugin has registered its copy.
	 *
	 * @return void
	 */
	function bonsai_hub_boot() {
		if ( defined( 'BONSAI_HUB_VERSION' ) || empty( $GLOBALS['bonsai_hub_candidates'] ) ) {
			return;
		}

		$versions = array_map( 'strval', array_keys( $GLOBALS['bonsai_hub_candidates'] ) );
		usort( $versions, 'version_compare' );
		$version   = end( $versions );
		$candidate = $GLOBALS['bonsai_hub_candidates'][ $version ];

		define( 'BONSAI_HUB_VERSION', $version );
		define( 'BONSAI_HUB_DIR', $candidate['dir'] );
		define( 'BONSAI_HUB_URL', $candidate['url'] );

		require_once BONSAI_HUB_DIR . '/includes/class-bonsai-hub.php';
		require_once BONSAI_HUB_DIR . '/includes/class-bonsai-hub-installer.php';

		Bonsai_Hub::init();
		Bonsai_Hub_Installer::init();
	}

	add_action( 'plugins_loaded', 'bonsai_hub_boot', 0 );
}
