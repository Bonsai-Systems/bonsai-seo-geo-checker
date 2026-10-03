<?php
/**
 * Bonsai Hub: the shared "Bonsai" admin menu and page shell.
 *
 * Bonsai plugins register a module through the `bonsai_hub_modules` filter.
 * Each module gets a submenu item under Bonsai, an entry in the page's
 * left-hand nav, and optional tabs across the top of its content:
 *
 *   add_filter( 'bonsai_hub_modules', function ( $modules ) {
 *       $modules['my-plugin'] = array(            // Key = admin page slug (?page=…).
 *           'label'       => 'My Plugin',         // Menu and nav label (required).
 *           'title'       => 'Bonsai My Plugin',  // Page header title (defaults to label).
 *           'description' => 'Lead text under the title.',
 *           'version'     => MY_PLUGIN_VERSION,
 *           'repo'        => 'https://github.com/Bonsai-Systems/my-plugin',
 *           'links'       => array( array( 'label' => 'View site', 'url' => home_url( '/' ) ) ), // Extra header links.
 *           'capability'  => 'manage_options',
 *           'position'    => 10,                  // Order in the menu and nav.
 *           'enqueue'     => callable( $tab ),    // Admin assets, this page only.
 *           'load'        => callable( $tab ),    // Runs on load-{hook}, before output.
 *           'legacy'      => array( 'old-page-slug' => 'tab' ), // Old screens merged into a tab here.
 *           'tabs'        => array(
 *               'settings' => array(
 *                   'label'       => 'Settings',
 *                   'description' => 'Lead paragraph above the tab content.', // Optional.
 *                   'render'      => callable,    // Prints the tab's content.
 *                   'capability' => 'manage_options', // Optional, defaults to the module's.
 *                   'load'       => callable,     // Optional, like the module's.
 *               ),
 *               // A tab can instead link to another admin screen, e.g. a
 *               // post type list. It's never the default tab; a user who can
 *               // only see link tabs is sent straight to the first one.
 *               'list'     => array(
 *                   'label' => 'All items',
 *                   'url'   => admin_url( 'edit.php?post_type=my_cpt' ),
 *               ),
 *           ),
 *           // Or, for a single screen with no tab bar:
 *           'render'      => callable,
 *       );
 *       return $modules;
 *   } );
 *
 * The hub prints the wrap, the Bonsai header, admin notices (including the
 * Settings API "Settings saved." notice), the left nav and the tab bar.
 * A module's render callback only prints its own content.
 *
 * @package Bonsai_Hub
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Bonsai menu and renders every hub screen.
 */
final class Bonsai_Hub {

	/**
	 * Top-level menu slug. Also the slug of the built-in Plugins screen.
	 */
	const MENU_SLUG = 'bonsai';

	/**
	 * Tags allowed in header leads and tab descriptions.
	 */
	const LEAD_TAGS = array(
		'a'      => array( 'href' => true ),
		'code'   => array(),
		'strong' => array(),
		'em'     => array(),
	);

	/**
	 * Normalised modules, keyed by page slug. Null until first built.
	 *
	 * @var array|null
	 */
	private static $modules = null;

	/**
	 * Hook suffixes of every hub screen, mapped to their page slug.
	 *
	 * @var array<string, string>
	 */
	private static $hooks = array();

	/**
	 * Wires up the hub. Called once by bonsai_hub_boot().
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_init', array( __CLASS__, 'redirect_legacy_url' ) );

		// Late, so it wins over other plugins' menu ordering.
		add_filter( 'custom_menu_order', '__return_true', 999 );
		add_filter( 'menu_order', array( __CLASS__, 'move_menu_first' ), 999 );
	}

	/**
	 * Every registered module, normalised and sorted.
	 *
	 * Built on first call, which must be no earlier than admin_menu so every
	 * plugin has had the chance to add its filter.
	 *
	 * @return array<string, array> Keyed by page slug.
	 */
	public static function modules() {
		if ( null !== self::$modules ) {
			return self::$modules;
		}

		$modules = array();

		foreach ( (array) apply_filters( 'bonsai_hub_modules', array() ) as $page => $module ) {
			$module = self::normalise_module( $page, $module );
			if ( $module ) {
				$modules[ $module['page'] ] = $module;
			}
		}

		uasort(
			$modules,
			static function ( $a, $b ) {
				return array( $a['position'], $a['label'] ) <=> array( $b['position'], $b['label'] );
			}
		);

		self::$modules = $modules;
		return self::$modules;
	}

	/**
	 * Fills in defaults and drops anything unusable, so one bad module can't
	 * take the whole menu down.
	 *
	 * @param string|int $page   Page slug the module was registered under.
	 * @param mixed      $module Raw module definition.
	 * @return array|null Normalised module, or null if invalid.
	 */
	private static function normalise_module( $page, $module ) {
		$page = sanitize_key( (string) $page );

		if ( '' === $page || self::MENU_SLUG === $page || ! is_array( $module ) || empty( $module['label'] ) ) {
			self::log( sprintf( 'Ignored module "%s": it needs a page slug and a label.', $page ) );
			return null;
		}

		$module = wp_parse_args(
			$module,
			array(
				'title'       => $module['label'],
				'description' => '',
				'version'     => '',
				'repo'        => '',
				'links'       => array(),
				'capability'  => 'manage_options',
				'position'    => 10,
				'enqueue'     => null,
				'load'        => null,
				'legacy'      => array(),
				'tabs'        => array(),
				'render'      => null,
			)
		);

		// A single render callback is shorthand for one tab with no tab bar.
		if ( empty( $module['tabs'] ) && is_callable( $module['render'] ) ) {
			$module['tabs'] = array(
				'main' => array(
					'label'  => $module['label'],
					'render' => $module['render'],
				),
			);
		}

		$tabs = array();

		foreach ( (array) $module['tabs'] as $slug => $tab ) {
			$slug = sanitize_key( (string) $slug );

			$renders = is_array( $tab ) && isset( $tab['render'] ) && is_callable( $tab['render'] );
			$links   = is_array( $tab ) && ! empty( $tab['url'] ) && is_string( $tab['url'] );

			if ( '' === $slug || ! is_array( $tab ) || empty( $tab['label'] ) || ( ! $renders && ! $links ) ) {
				self::log( sprintf( 'Ignored tab "%s" in module "%s": it needs a label and a callable render or a url.', $slug, $page ) );
				continue;
			}

			$tabs[ $slug ] = wp_parse_args(
				$tab,
				array(
					'description' => '',
					'capability'  => $module['capability'],
					'load'        => null,
					'render'      => null,
					'url'         => '',
				)
			);

			// A render callback wins if a tab has both.
			if ( $renders ) {
				$tabs[ $slug ]['url'] = '';
			}
		}

		if ( ! $tabs ) {
			self::log( sprintf( 'Ignored module "%s": it has no usable tabs.', $page ) );
			return null;
		}

		$module['page']     = $page;
		$module['tabs']     = $tabs;
		$module['position'] = (int) $module['position'];

		return $module;
	}

	/**
	 * Adds the Bonsai top-level menu, one submenu per module, and the
	 * built-in Plugins screen last.
	 *
	 * The Plugins screen owns the top-level slug, but modules are spliced in
	 * ahead of it, so clicking "Bonsai" opens the first module, the same way
	 * Vision Website opens on its first tab.
	 *
	 * @return void
	 */
	public static function register_menu() {
		$plugins_cap = Bonsai_Hub_Installer::CAPABILITY;

		add_menu_page(
			__( 'Bonsai plugins', 'bonsai-hub' ),
			__( 'Bonsai', 'bonsai-hub' ),
			$plugins_cap,
			self::MENU_SLUG,
			array( __CLASS__, 'render_page' ),
			(string) apply_filters( 'bonsai_hub_menu_icon', 'dashicons-layout' ),
			(int) apply_filters( 'bonsai_hub_menu_position', 1 )
		);

		$hook = add_submenu_page(
			self::MENU_SLUG,
			__( 'Bonsai plugins', 'bonsai-hub' ),
			__( 'Plugins', 'bonsai-hub' ),
			$plugins_cap,
			self::MENU_SLUG,
			array( __CLASS__, 'render_page' )
		);

		if ( $hook ) {
			self::$hooks[ $hook ] = self::MENU_SLUG;
		}

		$position = 0;

		foreach ( self::modules() as $page => $module ) {
			$hook = add_submenu_page(
				self::MENU_SLUG,
				$module['title'],
				$module['label'],
				$module['capability'],
				$page,
				array( __CLASS__, 'render_page' ),
				$position
			);

			// False when the current user lacks the module's capability.
			if ( $hook ) {
				self::$hooks[ $hook ] = $page;
				add_action( 'load-' . $hook, array( __CLASS__, 'load_page' ) );
				++$position;
			}
		}
	}

	/**
	 * Runs the current module's and tab's 'load' callbacks before any
	 * output, e.g. to process a form and redirect.
	 *
	 * @return void
	 */
	public static function load_page() {
		$module = self::current_module();
		if ( ! $module ) {
			return;
		}

		$tab_slug = self::current_tab( $module );

		if ( '' === $tab_slug ) {
			// Nothing to render here for this user, but they may be able to
			// open a link tab (e.g. a post type list): send them there.
			foreach ( self::accessible_tabs( $module ) as $tab ) {
				if ( '' !== $tab['url'] ) {
					wp_safe_redirect( $tab['url'] );
					exit;
				}
			}
			return;
		}

		foreach ( array( $module['load'], $module['tabs'][ $tab_slug ]['load'] ) as $callback ) {
			if ( is_callable( $callback ) ) {
				call_user_func( $callback, $tab_slug );
			}
		}
	}

	/**
	 * Loads the design system on hub screens, then the module's own assets.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public static function enqueue_assets( $hook ) {
		if ( ! isset( self::$hooks[ $hook ] ) ) {
			return;
		}

		wp_enqueue_style( 'bonsai-hub-ui', BONSAI_HUB_URL . 'assets/bonsai-admin-ui.css', array(), BONSAI_HUB_VERSION );
		wp_enqueue_style( 'bonsai-hub', BONSAI_HUB_URL . 'assets/bonsai-hub.css', array( 'bonsai-hub-ui' ), BONSAI_HUB_VERSION );

		$module = self::current_module();

		if ( $module && is_callable( $module['enqueue'] ) ) {
			call_user_func( $module['enqueue'], self::current_tab( $module ) );
		}
	}

	/**
	 * Sends old bookmarks to the plugin's new home under Bonsai:
	 *   - options-general.php?page=my-plugin → admin.php?page=my-plugin
	 *   - an old screen merged into a tab (the module's 'legacy' map), e.g.
	 *     options-general.php?page=my-plugin-settings → admin.php?page=my-plugin&tab=settings
	 *
	 * Once a page moves parent, core can't find a hook for it under the old
	 * parent and dies with "Cannot load my-plugin." admin_init runs after the
	 * menu is built and before that check, so this catches it first.
	 *
	 * @return void
	 */
	public static function redirect_legacy_url() {
		global $pagenow;

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		// phpcs:enable

		if ( '' === $page || wp_doing_ajax() ) {
			return;
		}

		$modules = self::modules();
		$target  = '';

		if ( self::MENU_SLUG === $page || isset( $modules[ $page ] ) ) {
			// Already on admin.php: the page is where it should be.
			if ( 'admin.php' === $pagenow ) {
				return;
			}
			$target = $page;
		} else {
			foreach ( $modules as $module_page => $module ) {
				if ( isset( $module['legacy'][ $page ] ) ) {
					$target = $module_page;
					$tab    = (string) $module['legacy'][ $page ];
					break;
				}
			}
		}

		// Not ours, or something genuinely lives at this URL: leave it alone.
		if ( '' === $target || get_plugin_page_hook( $page, $pagenow ) ) {
			return;
		}

		wp_safe_redirect( self::url( $target, $tab ) );
		exit;
	}

	/**
	 * Admin URL for a hub screen. Use this instead of hand-building
	 * admin.php?page=… links.
	 *
	 * @param string $page Page slug (a module key, or Bonsai_Hub::MENU_SLUG).
	 * @param string $tab  Optional tab slug.
	 * @param array  $args Extra query args.
	 * @return string Unescaped URL.
	 */
	public static function url( $page, $tab = '', $args = array() ) {
		$query = array( 'page' => $page );

		if ( '' !== $tab ) {
			$query['tab'] = $tab;
		}

		return add_query_arg( array_merge( $query, $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * The module for the current ?page=, if it's one of ours.
	 *
	 * @return array|null
	 */
	public static function current_module() {
		$page    = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
		$modules = self::modules();

		return isset( $modules[ $page ] ) ? $modules[ $page ] : null;
	}

	/**
	 * The module's tabs the current user can see.
	 *
	 * @param array $module Normalised module.
	 * @return array<string, array>
	 */
	private static function accessible_tabs( $module ) {
		return array_filter(
			$module['tabs'],
			static function ( $tab ) {
				return current_user_can( $tab['capability'] );
			}
		);
	}

	/**
	 * The requested ?tab= slug, falling back to the first tab the user can
	 * see when it's missing, unknown or off-limits. Link tabs are skipped:
	 * they're other screens, never rendered here.
	 *
	 * @param array $module Normalised module.
	 * @return string Tab slug, or '' when the user can see no renderable tab.
	 */
	public static function current_tab( $module ) {
		$tabs = array_filter(
			self::accessible_tabs( $module ),
			static function ( $tab ) {
				return '' === $tab['url'];
			}
		);
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation.

		if ( isset( $tabs[ $tab ] ) ) {
			return $tab;
		}

		return $tabs ? (string) array_key_first( $tabs ) : '';
	}

	/**
	 * Renders every hub screen: header, notices, left nav, tab bar, then the
	 * active tab (or the Plugins screen).
	 *
	 * @return void
	 */
	public static function render_page() {
		$module   = self::current_module();
		$is_suite = ! $module;

		if ( $is_suite ) {
			if ( ! current_user_can( Bonsai_Hub_Installer::CAPABILITY ) ) {
				wp_die( esc_html__( 'You do not have permission to access this page.', 'bonsai-hub' ), 403 );
			}

			$current = self::MENU_SLUG;
			$tab     = '';
		} else {
			$current = $module['page'];
			$tab     = self::current_tab( $module );

			if ( '' === $tab || ! current_user_can( $module['capability'] ) ) {
				wp_die( esc_html__( 'You do not have permission to access this page.', 'bonsai-hub' ), 403 );
			}
		}
		?>
		<div class="wrap bonsai-ui bonsai-ui--hub">
			<?php
			if ( $is_suite ) {
				self::render_header(
					__( 'Bonsai plugins', 'bonsai-hub' ),
					__( 'Every plugin from The Bonsai Digital Collective, in one place. Install, activate and open each one from here.', 'bonsai-hub' ),
					/* translators: %s: Bonsai Hub version number. */
					sprintf( __( 'Hub v%s', 'bonsai-hub' ), BONSAI_HUB_VERSION )
				);
			} else {
				self::render_header(
					$module['title'],
					$module['description'],
					'' !== $module['version'] ? 'v' . $module['version'] : '',
					$module['repo'],
					(array) $module['links']
				);
			}

			// Core only prints these automatically under Settings, and hub
			// screens no longer live there. options.php leaves its own
			// "Settings saved." in a transient; plugins saving through their
			// own admin-post handler just redirect with ?settings-updated, so
			// supply the notice for them.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only flag.
			if ( ! empty( $_GET['settings-updated'] ) && ! get_settings_errors() ) {
				add_settings_error( 'general', 'settings_updated', __( 'Settings saved.', 'bonsai-hub' ), 'success' );
			}
			settings_errors();
			?>
			<div class="bonsai-hub">
				<?php self::render_nav( $current ); ?>

				<div class="bonsai-hub-main">
					<?php
					if ( $is_suite ) {
						Bonsai_Hub_Installer::render();
					} else {
						self::render_tabs( $module, $tab );

						if ( '' !== $module['tabs'][ $tab ]['description'] ) {
							echo '<p class="bonsai-hub-lead">' . wp_kses( $module['tabs'][ $tab ]['description'], self::LEAD_TAGS ) . '</p>';
						}

						call_user_func( $module['tabs'][ $tab ]['render'] );
					}
					?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Left-hand nav: one link per module the user can open, then Plugins.
	 *
	 * @param string $current Current page slug.
	 * @return void
	 */
	private static function render_nav( $current ) {
		$modules = array_filter(
			self::modules(),
			static function ( $module ) {
				return current_user_can( $module['capability'] );
			}
		);
		?>
		<nav class="bonsai-hub-nav" aria-label="<?php esc_attr_e( 'Bonsai plugins', 'bonsai-hub' ); ?>">
			<?php if ( $modules ) : ?>
				<ul>
					<?php foreach ( $modules as $page => $module ) : ?>
						<li><a href="<?php echo esc_url( self::url( $page ) ); ?>"<?php echo $page === $current ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $module['label'] ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if ( current_user_can( Bonsai_Hub_Installer::CAPABILITY ) ) : ?>
				<ul class="bonsai-hub-nav__footer">
					<li><a href="<?php echo esc_url( self::url( self::MENU_SLUG ) ); ?>"<?php echo self::MENU_SLUG === $current ? ' aria-current="page"' : ''; ?>><?php esc_html_e( 'Plugins', 'bonsai-hub' ); ?></a></li>
				</ul>
			<?php endif; ?>
		</nav>
		<?php
	}

	/**
	 * Tab bar across the top of the content. Skipped when there's only one
	 * tab to show.
	 *
	 * @param array  $module  Normalised module.
	 * @param string $current Current tab slug.
	 * @return void
	 */
	private static function render_tabs( $module, $current ) {
		$tabs = self::accessible_tabs( $module );

		if ( count( $tabs ) < 2 ) {
			return;
		}
		?>
		<nav class="bonsai-hub-tabs" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: plugin name. */ __( '%s sections', 'bonsai-hub' ), $module['label'] ) ); ?>">
			<?php foreach ( $tabs as $slug => $tab ) : ?>
				<a href="<?php echo esc_url( '' !== $tab['url'] ? $tab['url'] : self::url( $module['page'], $slug ) ); ?>"<?php echo $slug === $current ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $tab['label'] ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Bonsai page header, followed by the marker core uses to place admin
	 * notices, so notices sit below the header rather than above it.
	 *
	 * @param string $title   Page title (plain text).
	 * @param string $lead    Short description. May contain links, code and emphasis.
	 * @param string $version Text for the version badge, or '' for none.
	 * @param string $repo    GitHub repo URL for the GitHub/Changelog links, or ''.
	 * @param array  $extra   Links shown first: each array( 'label' => '', 'url' => '' ).
	 * @return void
	 */
	private static function render_header( $title, $lead = '', $version = '', $repo = '', $extra = array() ) {
		$links = array();

		foreach ( $extra as $link ) {
			if ( is_array( $link ) && ! empty( $link['label'] ) && ! empty( $link['url'] ) ) {
				$links[] = $link;
			}
		}

		if ( '' !== $repo ) {
			$repo    = untrailingslashit( $repo );
			$links[] = array(
				'label' => __( 'GitHub', 'bonsai-hub' ),
				'url'   => $repo,
			);
			$links[] = array(
				'label' => __( 'Changelog', 'bonsai-hub' ),
				'url'   => $repo . '/releases',
			);
		}

		$links[] = array(
			'label' => __( 'The Bonsai Digital Collective', 'bonsai-hub' ),
			'url'   => 'https://bonsaidigitalcollective.co.uk/',
		);

		?>
		<header class="bonsai-ui-header">
			<div class="bonsai-ui-header__main">
				<img class="bonsai-ui-header__logo" src="<?php echo esc_url( BONSAI_HUB_URL . 'assets/bonsai-avatar.jpg' ); ?>" width="412" height="108" alt="<?php esc_attr_e( 'The Bonsai Digital Collective', 'bonsai-hub' ); ?>">
				<h1 class="bonsai-ui-header__title"><?php echo esc_html( $title ); ?></h1>
				<?php if ( '' !== $lead ) : ?>
					<p class="bonsai-ui-header__lead"><?php echo wp_kses( $lead, self::LEAD_TAGS ); ?></p>
				<?php endif; ?>
				<ul class="bonsai-ui-header__links">
					<?php foreach ( $links as $link ) : ?>
						<li><a href="<?php echo esc_url( $link['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $link['label'] ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'bonsai-hub' ); ?></span></a></li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php if ( '' !== $version ) : ?>
				<div class="bonsai-ui-header__meta">
					<span class="bonsai-ui-version"><?php echo esc_html( $version ); ?></span>
				</div>
			<?php endif; ?>
		</header>
		<hr class="wp-header-end">
		<?php
	}

	/**
	 * Moves Bonsai to the very top of the admin sidebar, above Dashboard.
	 *
	 * Menu position 1 already puts it there on a clean install, but other
	 * plugins can claim the same position or reorder the menu, so this
	 * runs late on core's menu_order filter to make it stick. Return false
	 * from `bonsai_hub_menu_first` to fall back to the plain position.
	 *
	 * When a user can't open the Plugins screen, core re-points the
	 * top-level item at the first submenu they can open, so any hub page
	 * slug counts as "the Bonsai menu".
	 *
	 * @param array $order Top-level menu slugs, in display order.
	 * @return array
	 */
	public static function move_menu_first( $order ) {
		if ( ! is_array( $order ) || ! apply_filters( 'bonsai_hub_menu_first', true ) ) {
			return $order;
		}

		$ours = array_merge( array( self::MENU_SLUG ), array_keys( self::modules() ) );

		foreach ( $order as $index => $slug ) {
			if ( in_array( $slug, $ours, true ) ) {
				unset( $order[ $index ] );
				array_unshift( $order, $slug );
				break;
			}
		}

		return array_values( $order );
	}

	/**
	 * Logs a hub problem when WP_DEBUG is on. Never shown on screen.
	 *
	 * @param string $message What went wrong.
	 * @return void
	 */
	public static function log( $message ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'Bonsai Hub: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only logging.
		}
	}
}
