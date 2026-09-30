<?php
/**
 * Settings: native Settings API page under Settings → SEO/GEO checker, with
 * wp-config.php constants taking priority.
 *
 * Constants (recommended for keys so they never sit in the database):
 *   define( 'BSGC_ANTHROPIC_KEY', 'sk-ant-...' );
 *   define( 'BSGC_PSI_KEY', 'AIza...' );
 *   define( 'BSGC_CLAUDE_MODEL', 'claude-haiku-4-5-20251001' );
 *
 * Saved keys are never sent back to the browser. The field is always empty;
 * leaving it blank keeps the stored key.
 *
 * @package BonsaiSEOGEOChecker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles plugin settings.
 */
class BSGC_Settings {

	const DEFAULT_MODEL = 'claude-haiku-4-5-20251001';
	const OPTION        = 'bsgc_settings';
	const GROUP         = 'bsgc_settings';
	const SLUG          = 'bsgc-settings';
	const HOOK          = 'settings_page_bsgc-settings';
	const WEBSITE       = 'https://bonsaidigitalcollective.co.uk/';

	/**
	 * Setting key => wp-config.php constant that overrides it.
	 *
	 * @var array
	 */
	const MAP = array(
		'anthropic_key' => 'BSGC_ANTHROPIC_KEY',
		'model'         => 'BSGC_CLAUDE_MODEL',
		'psi_key'       => 'BSGC_PSI_KEY',
	);

	/**
	 * Keys treated as secrets: masked, never echoed, blank means keep.
	 *
	 * @var array
	 */
	const SECRETS = array( 'anthropic_key', 'psi_key' );

	/**
	 * Old ACF option names, used once to migrate existing values.
	 *
	 * @var array
	 */
	const LEGACY_ACF = array(
		'anthropic_key' => 'bsgc_anthropic_api_key',
		'model'         => 'bsgc_claude_model',
		'psi_key'       => 'bsgc_psi_api_key',
	);

	/**
	 * Hook in.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_migrate' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( BSGC_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Field definitions, in display order.
	 *
	 * @return array
	 */
	private static function fields() {
		return array(
			'anthropic_key' => array(
				'label' => 'Anthropic API key',
				'help'  => 'Needed for the fix list. Better: define BSGC_ANTHROPIC_KEY in wp-config.php, which overrides this field and keeps the key out of the database.',
			),
			'model'         => array(
				'label' => 'Claude model',
				'help'  => 'Haiku is plenty for fix lists and costs a fraction of a penny per report.',
			),
			'psi_key'       => array(
				'label' => 'PageSpeed Insights API key',
				'help'  => 'Free from Google Cloud Console. Optional, but without one Google rate-limits PageSpeed requests heavily. BSGC_PSI_KEY in wp-config.php overrides this.',
			),
		);
	}

	/**
	 * Copy values saved by the old ACF options page into our option, then
	 * delete the ACF rows so keys aren't left behind in two places. Runs once.
	 */
	public static function maybe_migrate() {
		if ( false !== get_option( self::OPTION ) ) {
			return;
		}

		$values = array();

		foreach ( self::LEGACY_ACF as $key => $field ) {
			// ACF stores option fields as options_{name}, plus a _options_{name} field-key reference.
			$values[ $key ] = trim( (string) get_option( 'options_' . $field, '' ) );
			delete_option( 'options_' . $field );
			delete_option( '_options_' . $field );
		}

		add_option( self::OPTION, $values, '', false );
	}

	/**
	 * Register the option with the Settings API.
	 */
	public static function register() {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitise' ),
				'default'           => array(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Register the Settings sub-page.
	 */
	public static function menu() {
		add_options_page( 'SEO/GEO checker settings', 'SEO/GEO checker', 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
	}

	/**
	 * Load the shared admin styles on our settings screen.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function assets( $hook ) {
		if ( self::HOOK === $hook ) {
			wp_enqueue_style( 'bsgc-admin', BSGC_URL . 'assets/admin.css', array(), BSGC_VERSION );
		}
	}

	/**
	 * Add a Settings link to the plugin's row on the Plugins screen.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::settings_url() ) . '">Settings</a>' );

		return $links;
	}

	/**
	 * Stored option, always an array.
	 *
	 * @return array
	 */
	private static function stored() {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Sanitise a save. Blank secret fields keep the stored key; the matching
	 * "remove" checkbox clears it. Constant-controlled keys are left alone.
	 *
	 * @param mixed $input Raw submitted value.
	 * @return array
	 */
	public static function sanitise( $input ) {
		$input  = is_array( $input ) ? $input : array();
		$stored = self::stored();
		$clean  = array();

		foreach ( array_keys( self::MAP ) as $key ) {
			$old = isset( $stored[ $key ] ) ? (string) $stored[ $key ] : '';

			// Disabled fields aren't submitted, so keep whatever was there.
			if ( defined( self::MAP[ $key ] ) ) {
				$clean[ $key ] = $old;
				continue;
			}

			// options.php has already unslashed the submitted values.
			$new = isset( $input[ $key ] ) ? trim( sanitize_text_field( $input[ $key ] ) ) : '';

			if ( in_array( $key, self::SECRETS, true ) ) {
				if ( ! empty( $input[ 'clear_' . $key ] ) ) {
					$new = '';
				} elseif ( '' === $new ) {
					$new = $old;
				}
			} elseif ( 'model' === $key && ! preg_match( '/^[a-z0-9][a-z0-9._-]*$/i', $new ) ) {
				// Model IDs are simple slugs; anything else falls back to the default.
				$new = '';
			}

			$clean[ $key ] = $new;
		}

		return $clean;
	}

	/**
	 * Get a setting. Constant first, then saved option, then default.
	 *
	 * @param string $key Setting key from self::MAP.
	 * @return string
	 */
	public static function get( $key ) {
		if ( ! isset( self::MAP[ $key ] ) ) {
			return '';
		}

		$constant = self::MAP[ $key ];

		if ( defined( $constant ) ) {
			$value = (string) constant( $constant );
		} else {
			$stored = self::stored();
			$value  = isset( $stored[ $key ] ) ? (string) $stored[ $key ] : '';
		}

		$value = trim( $value );

		if ( 'model' === $key && '' === $value ) {
			$value = self::DEFAULT_MODEL;
		}

		return $value;
	}

	/**
	 * URL of the settings screen.
	 *
	 * @return string
	 */
	public static function settings_url() {
		return admin_url( 'options-general.php?page=' . self::SLUG );
	}

	/**
	 * Render the settings screen.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$stored = self::stored();
		?>
		<div class="wrap bsgc">
			<h1>SEO/GEO checker settings</h1>

			<div class="bsgc-brand">
				<img class="bsgc-brand__avatar" src="<?php echo esc_url( BSGC_URL . 'assets/bonsai-avatar.jpg' ); ?>" width="412" height="108" alt="The Bonsai Digital Collective">
				<div class="bsgc-brand__body">
					<p class="bsgc-brand__text">Checks a single page for search basics, AI visibility (GEO) and mobile performance, then uses Claude to write a prioritised fix list. Run checks from <a href="<?php echo esc_url( BSGC_Admin::page_url() ); ?>">Tools → SEO/GEO checker</a> or the box on any published page's edit screen.</p>
					<p class="bsgc-brand__text">Built and maintained by <a href="<?php echo esc_url( self::WEBSITE ); ?>" target="_blank" rel="noopener noreferrer">The Bonsai Digital Collective<span class="screen-reader-text"> (opens in a new tab)</span></a>.</p>
				</div>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>

				<table class="form-table" role="presentation">
					<?php
					foreach ( self::fields() as $key => $field ) {
						self::render_field( $key, $field, $stored );
					}
					?>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render one settings row.
	 *
	 * @param string $key    Setting key.
	 * @param array  $field  Label and help text.
	 * @param array  $stored Stored option values.
	 */
	private static function render_field( $key, $field, $stored ) {
		$id        = 'bsgc-' . str_replace( '_', '-', $key );
		$name      = self::OPTION . '[' . $key . ']';
		$constant  = self::MAP[ $key ];
		$is_const  = defined( $constant );
		$is_secret = in_array( $key, self::SECRETS, true );
		$saved     = isset( $stored[ $key ] ) ? (string) $stored[ $key ] : '';
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $field['label'] ); ?></label></th>
			<td>
				<?php if ( $is_const ) : ?>
					<input type="text" id="<?php echo esc_attr( $id ); ?>" class="regular-text" value="<?php echo esc_attr( $is_secret ? 'Set in wp-config.php' : (string) constant( $constant ) ); ?>" disabled aria-describedby="<?php echo esc_attr( $id ); ?>-help">
					<p class="description" id="<?php echo esc_attr( $id ); ?>-help">Controlled by <code><?php echo esc_html( $constant ); ?></code> in wp-config.php. Remove the constant to manage it here.</p>
				<?php elseif ( $is_secret ) : ?>
					<?php
					// Never print the saved key; just enough of it to recognise which one is in use.
					$placeholder = '' !== $saved ? 'Saved key ending ' . substr( $saved, -4 ) . ' – leave blank to keep it' : 'Not set';
					?>
					<input type="password" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" class="regular-text" value="" placeholder="<?php echo esc_attr( $placeholder ); ?>" autocomplete="new-password" spellcheck="false" aria-describedby="<?php echo esc_attr( $id ); ?>-help">
					<?php if ( '' !== $saved ) : ?>
						<p><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION . '[clear_' . $key . ']' ); ?>" value="1"> Remove saved key</label></p>
					<?php endif; ?>
					<p class="description" id="<?php echo esc_attr( $id ); ?>-help"><?php echo esc_html( $field['help'] ); ?></p>
				<?php else : ?>
					<input type="text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" class="regular-text" value="<?php echo esc_attr( $saved ); ?>" placeholder="<?php echo esc_attr( self::DEFAULT_MODEL ); ?>" spellcheck="false" aria-describedby="<?php echo esc_attr( $id ); ?>-help">
					<p class="description" id="<?php echo esc_attr( $id ); ?>-help"><?php echo esc_html( $field['help'] ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}
}
