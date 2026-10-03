<?php
/**
 * Bonsai Hub: the built-in Plugins screen.
 *
 * Lists the Bonsai plugin suite with each plugin's status, and installs,
 * activates and deactivates them. Installs pull the latest GitHub release
 * zip through core's Plugin_Upgrader, so filesystem credentials, folder
 * checks and error reporting all behave exactly as core's own installer.
 *
 * @package Bonsai_Hub
 */

defined( 'ABSPATH' ) || exit;

/**
 * Suite catalogue and plugin install/activate/deactivate actions.
 */
final class Bonsai_Hub_Installer {

	/**
	 * Capability needed to see the Plugins screen. Installing also needs
	 * install_plugins; each activate/deactivate is checked per plugin.
	 */
	const CAPABILITY = 'activate_plugins';

	/**
	 * Query arg carrying the requested action.
	 */
	const ACTION_PARAM = 'bonsai_hub_action';

	/**
	 * Catalogue key of the plugin being installed, while an install runs.
	 *
	 * @var string
	 */
	private static $installing = '';

	/**
	 * Wires up the activate/deactivate handler. Called once by bonsai_hub_boot().
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'load-toplevel_page_' . Bonsai_Hub::MENU_SLUG, array( __CLASS__, 'handle_action' ) );
	}

	/**
	 * The Bonsai plugin suite.
	 *
	 * Keys are the folder a fresh install lands in. 'file' is the main plugin
	 * file's name, used to spot the plugin whatever folder it was installed
	 * to. 'page' is its hub page slug when that isn't the key. 'legacy_url' is
	 * the plugin's settings screen in versions that don't register with the
	 * hub yet.
	 *
	 * @return array<string, array>
	 */
	public static function catalogue() {
		$items = array(
			'bonsai-code-injector'    => array(
				'name'        => __( 'Bonsai Code Injector', 'bonsai-hub' ),
				'description' => __( 'Adds tracking and verification code (GA4, Tag Manager, Meta Pixel) to the head and body without editing theme files.', 'bonsai-hub' ),
				'file'        => 'bonsai-code-injector.php',
				'repo'        => 'https://github.com/Bonsai-Systems/bonsai-code-injector',
				'legacy_url'  => 'options-general.php?page=bonsai-code-injector',
			),
			'bonsai-dashboard'        => array(
				'name'        => __( 'Bonsai Dashboard', 'bonsai-hub' ),
				'description' => __( 'Replaces the wp-admin dashboard with a branded welcome panel and quick links, plus white-label admin and login branding.', 'bonsai-hub' ),
				'file'        => 'bonsai-dashboard.php',
				'repo'        => 'https://github.com/Bonsai-Systems/bonsai-dashboard',
				'legacy_url'  => 'options-general.php?page=bonsai-dashboard',
			),
			'bonsai-maintenance'      => array(
				'name'        => __( 'Bonsai Maintenance Mode', 'bonsai-hub' ),
				'description' => __( 'Shows a customisable maintenance page to logged-out visitors while the site is being worked on.', 'bonsai-hub' ),
				'file'        => 'bonsai-maintenance.php',
				'repo'        => 'https://github.com/Bonsai-Systems/bonsai-maintenance',
				'page'        => 'cmm-settings',
				'legacy_url'  => 'options-general.php?page=cmm-settings',
			),
			'bonsai-notoolbar-edit'   => array(
				'name'        => __( 'Bonsai No Toolbar Edit', 'bonsai-hub' ),
				'description' => __( 'Hides the front-end admin toolbar and replaces it with Dashboard and Edit Page shortcuts.', 'bonsai-hub' ),
				'file'        => 'bonsai-notoolbar-edit.php',
				'repo'        => 'https://github.com/Bonsai-Systems/bonsai_notoolbar_edit',
				'legacy_url'  => 'options-general.php?page=bonsai-notoolbar-edit',
			),
			'bonsai-page-transitions' => array(
				'name'        => __( 'Bonsai Page Transitions', 'bonsai-hub' ),
				'description' => __( 'Plays a full-screen wipe animation when visitors click through to another page on the site.', 'bonsai-hub' ),
				'file'        => 'bonsai-page-transitions.php',
				'repo'        => 'https://github.com/Bonsai-Systems/bonsai-page-transitions',
				'legacy_url'  => 'options-general.php?page=bonsai-page-transitions',
			),
			'bonsai-seo-geo-checker'  => array(
				'name'        => __( 'Bonsai SEO/GEO Checker', 'bonsai-hub' ),
				'description' => __( 'Audits a single URL for search basics, AI visibility (GEO) and PageSpeed, with a Claude-written fix list.', 'bonsai-hub' ),
				'file'        => 'bonsai-seo-geo-checker.php',
				'repo'        => 'https://github.com/Bonsai-Systems/bonsai-seo-geo-checker',
				'page'        => 'bsgc',
				'legacy_url'  => 'tools.php?page=bsgc',
			),
			'bonsai-site-snags'       => array(
				'name'        => __( 'Bonsai Site Snags', 'bonsai-hub' ),
				'description' => __( 'Front-end snagging for site QA: click anywhere to drop a note, then tick it off when it\'s fixed.', 'bonsai-hub' ),
				'file'        => 'site-snags.php',
				'repo'        => 'https://github.com/Bonsai-Systems/bonsai-site-snags',
				'page'        => 'site-snags',
				'legacy_url'  => 'edit.php?post_type=site_snag',
			),
		);

		return (array) apply_filters( 'bonsai_hub_catalogue', $items );
	}

	/**
	 * Handles activate/deactivate links before any output, then redirects
	 * back to the Plugins screen with a notice.
	 *
	 * @return void
	 */
	public static function handle_action() {
		$action = self::request_key( self::ACTION_PARAM );

		if ( 'activate' !== $action && 'deactivate' !== $action ) {
			return;
		}

		$key       = self::request_key( 'plugin' );
		$catalogue = self::catalogue();

		check_admin_referer( 'bonsai_hub_' . $action . '_' . $key );

		if ( ! isset( $catalogue[ $key ] ) ) {
			wp_die( esc_html__( 'That isn\'t a Bonsai plugin.', 'bonsai-hub' ), 400 );
		}

		$file = self::installed_file( $catalogue[ $key ] );

		if ( '' === $file ) {
			self::redirect_with_notice( 'not_installed', $key );
		}

		if ( 'activate' === $action ) {
			if ( ! current_user_can( 'activate_plugin', $file ) ) {
				wp_die( esc_html__( 'You do not have permission to activate this plugin.', 'bonsai-hub' ), 403 );
			}

			// The redirect URL catches a fatal error on activation; core
			// sends the browser there with an error nonce if the plugin dies.
			$result = activate_plugin( $file, Bonsai_Hub::url( Bonsai_Hub::MENU_SLUG, '', array( 'bonsai_hub_notice' => 'activate_failed', 'plugin' => $key ) ) );

			if ( is_wp_error( $result ) ) {
				set_transient( self::error_transient(), $result->get_error_message(), MINUTE_IN_SECONDS );
				self::redirect_with_notice( 'activate_failed', $key );
			}

			self::redirect_with_notice( 'activated', $key );
		}

		if ( ! current_user_can( 'deactivate_plugin', $file ) ) {
			wp_die( esc_html__( 'You do not have permission to deactivate this plugin.', 'bonsai-hub' ), 403 );
		}

		if ( is_multisite() && is_plugin_active_for_network( $file ) ) {
			self::redirect_with_notice( 'network_active', $key );
		}

		deactivate_plugins( $file );

		// With no Bonsai plugin left active the Bonsai menu goes too, so
		// redirecting back to it would only show an access error.
		if ( ! self::any_active() ) {
			wp_safe_redirect( admin_url( 'plugins.php?deactivate=true' ) );
			exit;
		}

		self::redirect_with_notice( 'deactivated', $key );
	}

	/**
	 * Prints the Plugins screen: an install run if one was requested,
	 * otherwise the catalogue.
	 *
	 * @return void
	 */
	public static function render() {
		$catalogue = self::catalogue();
		$key       = self::request_key( 'plugin' );

		if ( 'install' === self::request_key( self::ACTION_PARAM ) && isset( $catalogue[ $key ] ) ) {
			self::render_install( $key, $catalogue[ $key ] );
			return;
		}

		self::render_notice( $catalogue );

		$plugins = self::get_plugins();
		?>
		<ul class="bonsai-hub-catalogue">
			<?php foreach ( $catalogue as $item_key => $item ) : ?>
				<?php
				$file      = self::installed_file( $item );
				$installed = '' !== $file;
				$active    = $installed && is_plugin_active( $file );
				$title_id  = 'bonsai-hub-plugin-' . $item_key;
				?>
				<li class="bonsai-ui-card bonsai-hub-plugin" aria-labelledby="<?php echo esc_attr( $title_id ); ?>">
					<div class="bonsai-hub-plugin__body">
						<div class="bonsai-ui-card__head">
							<h2 class="bonsai-ui-card__title" id="<?php echo esc_attr( $title_id ); ?>"><?php echo esc_html( $item['name'] ); ?></h2>
							<?php if ( $active ) : ?>
								<span class="bonsai-ui-badge bonsai-ui-badge--success"><?php esc_html_e( 'Active', 'bonsai-hub' ); ?></span>
							<?php elseif ( $installed ) : ?>
								<span class="bonsai-ui-badge bonsai-ui-badge--warning"><?php esc_html_e( 'Inactive', 'bonsai-hub' ); ?></span>
							<?php else : ?>
								<span class="bonsai-ui-badge"><?php esc_html_e( 'Not installed', 'bonsai-hub' ); ?></span>
							<?php endif; ?>
						</div>
						<p class="bonsai-ui-card__intro"><?php echo esc_html( $item['description'] ); ?></p>
						<?php if ( $installed && ! empty( $plugins[ $file ]['Version'] ) ) : ?>
							<p class="bonsai-hub-plugin__meta">
								<?php
								/* translators: %s: plugin version number. */
								echo esc_html( sprintf( __( 'Version %s', 'bonsai-hub' ), $plugins[ $file ]['Version'] ) );
								?>
							</p>
						<?php endif; ?>
					</div>
					<div class="bonsai-ui-card__footer bonsai-ui-actions">
						<?php self::render_card_actions( $item_key, $item, $file, $active ); ?>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * Buttons for one catalogue card, depending on its status and what the
	 * current user is allowed to do.
	 *
	 * @param string $key    Catalogue key.
	 * @param array  $item   Catalogue item.
	 * @param string $file   Installed plugin file, or '' if not installed.
	 * @param bool   $active Whether the plugin is active.
	 * @return void
	 */
	private static function render_card_actions( $key, $item, $file, $active ) {
		// Screen readers get the plugin name too, so seven "Activate" buttons aren't ambiguous.
		$name_suffix = '<span class="screen-reader-text"> ' . esc_html( $item['name'] ) . '</span>';

		if ( '' === $file ) {
			if ( current_user_can( 'install_plugins' ) ) {
				printf(
					'<a class="button button-primary" href="%s">%s%s</a>',
					esc_url( self::action_url( 'install', $key ) ),
					esc_html__( 'Install', 'bonsai-hub' ),
					$name_suffix // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
				);
			} else {
				echo '<span class="description">' . esc_html__( 'Ask an administrator to install this plugin.', 'bonsai-hub' ) . '</span>';
			}
			return;
		}

		if ( ! $active ) {
			if ( current_user_can( 'activate_plugin', $file ) ) {
				printf(
					'<a class="button button-primary" href="%s">%s%s</a>',
					esc_url( self::action_url( 'activate', $key ) ),
					esc_html__( 'Activate', 'bonsai-hub' ),
					$name_suffix // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
				);
			}
			return;
		}

		$settings_url = self::settings_url( $key, $item );

		if ( '' !== $settings_url ) {
			printf(
				'<a class="button button-primary" href="%s">%s%s</a>',
				esc_url( $settings_url ),
				esc_html__( 'Open', 'bonsai-hub' ),
				$name_suffix // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			);
		}

		if ( current_user_can( 'deactivate_plugin', $file ) && ! ( is_multisite() && is_plugin_active_for_network( $file ) ) ) {
			printf(
				'<a class="button" href="%s">%s%s</a>',
				esc_url( self::action_url( 'deactivate', $key ) ),
				esc_html__( 'Deactivate', 'bonsai-hub' ),
				$name_suffix // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
			);
		}
	}

	/**
	 * Where "Open" goes: the plugin's hub screen if it registers one, else
	 * its old settings screen.
	 *
	 * @param string $key  Catalogue key.
	 * @param array  $item Catalogue item.
	 * @return string Unescaped URL, or '' if there's nowhere to go.
	 */
	private static function settings_url( $key, $item ) {
		$modules = Bonsai_Hub::modules();
		$page    = isset( $item['page'] ) ? $item['page'] : $key;

		if ( isset( $modules[ $page ] ) ) {
			return current_user_can( $modules[ $page ]['capability'] ) ? Bonsai_Hub::url( $page ) : '';
		}

		return ! empty( $item['legacy_url'] ) ? admin_url( $item['legacy_url'] ) : '';
	}

	/**
	 * Runs an install inside the Plugins screen, printing core's upgrader
	 * progress (and its filesystem credentials form, when needed).
	 *
	 * @param string $key  Catalogue key.
	 * @param array  $item Catalogue item.
	 * @return void
	 */
	private static function render_install( $key, $item ) {
		check_admin_referer( 'bonsai_hub_install_' . $key );

		if ( ! current_user_can( 'install_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to install plugins.', 'bonsai-hub' ), 403 );
		}

		if ( '' !== self::installed_file( $item ) ) {
			printf(
				'<div class="notice notice-info"><p>%s</p></div><p><a class="button" href="%s">%s</a></p>',
				/* translators: %s: plugin name. */
				esc_html( sprintf( __( '%s is already installed.', 'bonsai-hub' ), $item['name'] ) ),
				esc_url( Bonsai_Hub::url( Bonsai_Hub::MENU_SLUG ) ),
				esc_html__( 'Back to Bonsai plugins', 'bonsai-hub' )
			);
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once __DIR__ . '/class-bonsai-hub-installer-skin.php';

		$skin = new Bonsai_Hub_Installer_Skin(
			array(
				/* translators: %s: plugin name. */
				'title'      => sprintf( __( 'Installing %s', 'bonsai-hub' ), $item['name'] ),
				// The credentials form posts back here, so it carries the nonce.
				'url'        => self::action_url( 'install', $key ),
				'nonce'      => 'bonsai_hub_install_' . $key,
				'type'       => 'web',
				'bonsai_key' => $key,
			)
		);

		self::$installing = $key;
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'rename_source' ), 10, 3 );

		$upgrader = new Plugin_Upgrader( $skin );
		$upgrader->install( self::package_url( $item ) );

		remove_filter( 'upgrader_source_selection', array( __CLASS__, 'rename_source' ), 10 );
		self::$installing = '';
	}

	/**
	 * Download URL for a plugin's latest release: the release's .zip asset
	 * (built with the right folder name), else the release source zip, else
	 * main branch. GitHub's API is rate-limited for anonymous requests, so
	 * any failure falls through rather than blocking the install.
	 *
	 * @param array $item Catalogue item.
	 * @return string
	 */
	private static function package_url( $item ) {
		$repo_path = trim( (string) wp_parse_url( $item['repo'], PHP_URL_PATH ), '/' );
		$fallback  = 'https://github.com/' . $repo_path . '/archive/refs/heads/main.zip';

		$response = wp_remote_get(
			'https://api.github.com/repos/' . $repo_path . '/releases/latest',
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'Bonsai-Hub/' . BONSAI_HUB_VERSION,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			Bonsai_Hub::log( 'Release lookup failed for ' . $repo_path . ': ' . $response->get_error_message() );
			return $fallback;
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			Bonsai_Hub::log( 'Release lookup for ' . $repo_path . ' returned HTTP ' . wp_remote_retrieve_response_code( $response ) );
			return $fallback;
		}

		$release = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $release ) ) {
			Bonsai_Hub::log( 'Release lookup for ' . $repo_path . ' returned invalid JSON.' );
			return $fallback;
		}

		foreach ( (array) ( $release['assets'] ?? array() ) as $asset ) {
			if ( ! empty( $asset['browser_download_url'] ) && ! empty( $asset['name'] ) && '.zip' === substr( $asset['name'], -4 ) ) {
				return $asset['browser_download_url'];
			}
		}

		return ! empty( $release['zipball_url'] ) ? $release['zipball_url'] : $fallback;
	}

	/**
	 * Renames the unpacked folder to the catalogue key during our installs.
	 * GitHub source zips unpack as "Owner-repo-sha" or "repo-main", which
	 * would otherwise become the plugin's folder name.
	 *
	 * @param string|WP_Error $source        Unpacked plugin folder.
	 * @param string          $remote_source Working directory it was unpacked into.
	 * @param WP_Upgrader     $upgrader      Upgrader instance.
	 * @return string|WP_Error
	 */
	public static function rename_source( $source, $remote_source, $upgrader ) {
		global $wp_filesystem;

		if ( '' === self::$installing || is_wp_error( $source ) || ! $wp_filesystem ) {
			return $source;
		}

		$target = trailingslashit( $remote_source ) . self::$installing;

		if ( untrailingslashit( $source ) === $target ) {
			return $source;
		}

		if ( $wp_filesystem->move( untrailingslashit( $source ), $target, true ) ) {
			return trailingslashit( $target );
		}

		return new WP_Error( 'bonsai_hub_rename_failed', __( 'The plugin downloaded, but its folder couldn\'t be renamed.', 'bonsai-hub' ) );
	}

	/**
	 * Notice for the result of the last activate/deactivate action.
	 *
	 * @param array $catalogue Suite catalogue.
	 * @return void
	 */
	private static function render_notice( $catalogue ) {
		$notice = self::request_key( 'bonsai_hub_notice' );
		$key    = self::request_key( 'plugin' );

		if ( '' === $notice || ! isset( $catalogue[ $key ] ) ) {
			return;
		}

		$name = $catalogue[ $key ]['name'];
		$type = 'success';

		switch ( $notice ) {
			case 'activated':
				/* translators: %s: plugin name. */
				$message = sprintf( __( '%s activated.', 'bonsai-hub' ), $name );
				break;
			case 'deactivated':
				/* translators: %s: plugin name. */
				$message = sprintf( __( '%s deactivated.', 'bonsai-hub' ), $name );
				break;
			case 'activate_failed':
				$type   = 'error';
				$detail = get_transient( self::error_transient() );
				delete_transient( self::error_transient() );
				/* translators: %s: plugin name. */
				$message = sprintf( __( '%s couldn\'t be activated.', 'bonsai-hub' ), $name );
				if ( $detail ) {
					$message .= ' ' . wp_strip_all_tags( $detail );
				} else {
					$message .= ' ' . __( 'Try activating it from the main Plugins screen to see the error.', 'bonsai-hub' );
				}
				break;
			case 'network_active':
				$type = 'warning';
				/* translators: %s: plugin name. */
				$message = sprintf( __( '%s is network activated, so it can only be deactivated from Network Admin.', 'bonsai-hub' ), $name );
				break;
			case 'not_installed':
				$type = 'error';
				/* translators: %s: plugin name. */
				$message = sprintf( __( '%s isn\'t installed.', 'bonsai-hub' ), $name );
				break;
			default:
				return;
		}

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}

	/**
	 * Nonced Plugins-screen URL for an action on one plugin.
	 *
	 * @param string $action install, activate or deactivate.
	 * @param string $key    Catalogue key.
	 * @return string Unescaped URL.
	 */
	private static function action_url( $action, $key ) {
		return Bonsai_Hub::url(
			Bonsai_Hub::MENU_SLUG,
			'',
			array(
				self::ACTION_PARAM => $action,
				'plugin'           => $key,
				'_wpnonce'         => wp_create_nonce( 'bonsai_hub_' . $action . '_' . $key ),
			)
		);
	}

	/**
	 * Redirects back to the Plugins screen with a result notice.
	 *
	 * @param string $notice Notice code (see render_notice()).
	 * @param string $key    Catalogue key.
	 * @return void
	 */
	private static function redirect_with_notice( $notice, $key ) {
		wp_safe_redirect(
			Bonsai_Hub::url(
				Bonsai_Hub::MENU_SLUG,
				'',
				array(
					'bonsai_hub_notice' => $notice,
					'plugin'            => $key,
				)
			)
		);
		exit;
	}

	/**
	 * Installed plugin file for a catalogue item, matched on the main file's
	 * name so it's found whichever folder it was installed to.
	 *
	 * @param array $item Catalogue item.
	 * @return string Plugin file relative to the plugins folder, or '' if not installed.
	 */
	private static function installed_file( $item ) {
		foreach ( array_keys( self::get_plugins() ) as $file ) {
			if ( false !== strpos( $file, '/' ) && basename( $file ) === $item['file'] ) {
				return $file;
			}
		}

		return '';
	}

	/**
	 * Whether any catalogue plugin is still active.
	 *
	 * @return bool
	 */
	private static function any_active() {
		foreach ( self::catalogue() as $item ) {
			$file = self::installed_file( $item );
			if ( '' !== $file && is_plugin_active( $file ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Core's installed-plugin list, loading plugin.php if needed.
	 *
	 * @return array<string, array>
	 */
	private static function get_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return get_plugins();
	}

	/**
	 * A sanitised key from the request, or ''.
	 *
	 * @param string $name Query/body arg name.
	 * @return string
	 */
	private static function request_key( $name ) {
		return isset( $_REQUEST[ $name ] ) ? sanitize_key( wp_unslash( $_REQUEST[ $name ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonces are checked by the callers that act on it.
	}

	/**
	 * Per-user transient holding the last activation error message.
	 *
	 * @return string
	 */
	private static function error_transient() {
		return 'bonsai_hub_activate_error_' . get_current_user_id();
	}
}
