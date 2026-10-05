<?php
/**
 * Plugin Name:       Bonsai SEO/GEO Checker
 * Plugin URI:        https://github.com/Bonsai-Systems/bonsai-seo-geo-checker
 * Description:       Internal single-URL audit tool: search basics, AI visibility (GEO), PageSpeed Insights and a Claude-written fix list. Lives under Bonsai → SEO/GEO checker.
 * Version:           1.3.1
 * Author:            The Bonsai Digital Collective
 * Author URI:        https://bonsaidigitalcollective.co.uk/
 * Update URI:        https://github.com/Bonsai-Systems/bonsai-seo-geo-checker
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 * Text Domain:       bsgc
 *
 * @package BonsaiSEOGEOChecker
 */

defined( 'ABSPATH' ) || exit;

/*
 * Duplicate install guard. If a second copy is active (e.g. a GitHub "Source
 * code" zip unpacked as bonsai-seo-geo-checker-1.1.0/ next to the real folder),
 * loading it would fatal on redeclared BSGC_ classes. Bail and tell the admin.
 */
if ( defined( 'BSGC_VERSION' ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>Bonsai SEO/GEO Checker is installed more than once. Please delete the duplicate plugin folder.</p></div>';
		}
	);
	return;
}

define( 'BSGC_VERSION', '1.3.1' );
define( 'BSGC_FILE', __FILE__ );
define( 'BSGC_PATH', plugin_dir_path( __FILE__ ) );
define( 'BSGC_URL', plugin_dir_url( __FILE__ ) );

// Shared Bonsai admin menu, page shell and suite installer. Bundled copy of
// the bonsai-hub repo; update it with bonsai-hub/bin/sync.sh, not by hand.
require_once BSGC_PATH . 'lib/bonsai-hub/bonsai-hub.php';
require_once BSGC_PATH . 'inc/class-bsgc-updater.php';
require_once BSGC_PATH . 'inc/class-bsgc-settings.php';
require_once BSGC_PATH . 'inc/class-bsgc-fetcher.php';
require_once BSGC_PATH . 'inc/class-bsgc-robots.php';
require_once BSGC_PATH . 'inc/class-bsgc-analyser.php';
require_once BSGC_PATH . 'inc/class-bsgc-pagespeed.php';
require_once BSGC_PATH . 'inc/class-bsgc-ai.php';
require_once BSGC_PATH . 'inc/class-bsgc-reports.php';
require_once BSGC_PATH . 'inc/class-bsgc-admin.php';
require_once BSGC_PATH . 'inc/class-bsgc-editor.php';

BSGC_Updater::init();

add_action(
	'plugins_loaded',
	function () {
		BSGC_Settings::init();
		BSGC_Reports::init();
		BSGC_Admin::init();
		BSGC_Editor::init();
	}
);
