<?php
/**
 * Plugin Name:       Bonsai SEO/GEO Checker
 * Description:       Internal single-URL audit tool: search basics, AI visibility (GEO), PageSpeed Insights and a Claude-written fix list. Lives under Tools → SEO/GEO checker.
 * Version:           1.0.0
 * Author:            The Bonsai Digital Collective
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 * Text Domain:       bsgc
 *
 * @package BonsaiSEOGEOChecker
 */

defined( 'ABSPATH' ) || exit;

define( 'BSGC_VERSION', '1.0.0' );
define( 'BSGC_PATH', plugin_dir_path( __FILE__ ) );
define( 'BSGC_URL', plugin_dir_url( __FILE__ ) );

require_once BSGC_PATH . 'inc/class-bsgc-settings.php';
require_once BSGC_PATH . 'inc/class-bsgc-fetcher.php';
require_once BSGC_PATH . 'inc/class-bsgc-robots.php';
require_once BSGC_PATH . 'inc/class-bsgc-analyser.php';
require_once BSGC_PATH . 'inc/class-bsgc-pagespeed.php';
require_once BSGC_PATH . 'inc/class-bsgc-ai.php';
require_once BSGC_PATH . 'inc/class-bsgc-reports.php';
require_once BSGC_PATH . 'inc/class-bsgc-admin.php';
require_once BSGC_PATH . 'inc/class-bsgc-editor.php';

add_action(
	'plugins_loaded',
	function () {
		BSGC_Settings::init();
		BSGC_Reports::init();
		BSGC_Admin::init();
		BSGC_Editor::init();
	}
);
