<?php
/**
 * Updates from GitHub releases via YahnisElsts/plugin-update-checker, so new
 * versions show on the Plugins screen like any other plugin.
 *
 * Clash safety with other Bonsai plugins that bundle the same library:
 *  - composer.json sets config.autoloader-suffix, so our Composer autoloader
 *    class is ComposerAutoloaderInitBonsaiSeoGeoChecker, never a shared hash.
 *  - PUC namespaces each release (v5p7 etc.) and only loads a version once, so
 *    several plugins bundling it is supported by the library.
 *
 * @package BonsaiSEOGEOChecker
 */

defined( 'ABSPATH' ) || exit;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

/**
 * GitHub release updater.
 */
class BSGC_Updater {

	const REPO = 'https://github.com/Bonsai-Systems/bonsai-seo-geo-checker';

	/**
	 * Only release assets whose file name matches this are used as the update
	 * package. The release workflow uploads bonsai-seo-geo-checker.zip.
	 */
	const ASSET = '/^bonsai-seo-geo-checker\.zip$/i';

	/**
	 * Hours between automatic checks. "Check again" on the Plugins screen
	 * forces one sooner.
	 */
	const CHECK_HOURS = 6;

	/**
	 * Build the update checker. Called straight from the bootstrap file rather
	 * than a hook, as PUC expects to be set up while plugins load.
	 */
	public static function init() {
		$autoload = BSGC_PATH . 'vendor/autoload.php';

		// A bare git clone has no vendor/ until composer install runs. Carry on without updates rather than fatal.
		if ( ! is_readable( $autoload ) ) {
			return;
		}

		require_once $autoload;

		try {
			$checker = PucFactory::buildUpdateChecker( self::REPO, BSGC_FILE, 'bonsai-seo-geo-checker', self::CHECK_HOURS );

			$checker->setBranch( 'main' );

			// Require our built zip. Without this PUC falls back to GitHub's source archive,
			// which a site could grab in the minute before the release workflow uploads the zip.
			// Constant read off the API object so it isn't tied to a PUC version namespace.
			$api = $checker->getVcsApi();
			$api->enableReleaseAssets( self::ASSET, constant( get_class( $api ) . '::REQUIRE_RELEASE_ASSETS' ) );

			// Optional, only needed if the repo is ever made private. Never hardcode the token.
			if ( defined( 'BSGC_GITHUB_TOKEN' ) && BSGC_GITHUB_TOKEN ) {
				$checker->setAuthentication( BSGC_GITHUB_TOKEN );
			}
		} catch ( Exception $e ) {
			error_log( 'Bonsai SEO/GEO Checker: update checker failed to start: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
