<?php
/**
 * Settings: native Settings API form on the Settings tab of Bonsai →
 * SEO/GEO checker (registered in BSGC_Admin::register_hub_module()), with
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
 * The "PDF reports" card sets the branding on printed reports (cover logo and
 * "Prepared by"), so white-label installs can swap Bonsai for the agency's own.
 *
 * @package BonsaiSEOGEOChecker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles plugin settings.
 */
class BSGC_Settings {

	const DEFAULT_MODEL = 'claude-haiku-4-5-20251001';
	const DEFAULT_BY    = 'The Bonsai Digital Collective';
	const OPTION        = 'bsgc_settings';
	const GROUP         = 'bsgc_settings';
	const SLUG          = 'bsgc-settings'; // Old Settings sub-page slug, redirected by the hub.

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
		add_filter( 'plugin_action_links_' . plugin_basename( BSGC_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Card sections on the settings screen, in display order.
	 *
	 * @return array
	 */
	private static function sections() {
		return array(
			'claude'    => array(
				'title' => 'Claude fix list',
				'intro' => 'Claude reads each finished report and writes the prioritised fix list. Without a key, reports still run but skip that step.',
			),
			'pagespeed' => array(
				'title' => 'PageSpeed Insights',
				'intro' => 'Google\'s mobile performance run, used for the Core Web Vitals checks.',
			),
			'report'    => array(
				'title' => 'PDF reports',
				'intro' => 'Branding on the cover of printed and PDF reports. Use your own logo for white-label clients, or none for a neutral report.',
			),
		);
	}

	/**
	 * Field definitions, in display order.
	 *
	 * Types: secret (masked key), text, choice (radio buttons), media (image from the Media Library).
	 *
	 * @return array
	 */
	private static function fields() {
		return array(
			'anthropic_key' => array(
				'section' => 'claude',
				'type'    => 'secret',
				'label'   => 'Anthropic API key',
				'help'    => 'Needed for the fix list. Better: define BSGC_ANTHROPIC_KEY in wp-config.php, which overrides this field and keeps the key out of the database.',
			),
			'model'         => array(
				'section'     => 'claude',
				'type'        => 'text',
				'label'       => 'Claude model',
				'placeholder' => self::DEFAULT_MODEL,
				'help'        => 'Haiku is plenty for fix lists and costs a fraction of a penny per report.',
			),
			'psi_key'       => array(
				'section' => 'pagespeed',
				'type'    => 'secret',
				'label'   => 'PageSpeed Insights API key',
				'help'    => 'Free from Google Cloud Console. Optional, but without one Google rate-limits PageSpeed requests heavily. BSGC_PSI_KEY in wp-config.php overrides this.',
			),
			'report_brand'  => array(
				'section' => 'report',
				'type'    => 'choice',
				'label'   => 'Branding',
				'choices' => array(
					'bonsai' => 'The Bonsai Digital Collective',
					'custom' => 'Custom logo (below)',
					'none'   => 'No logo',
				),
				'default' => 'bonsai',
				'help'    => 'Shown on the cover page of printed and PDF reports.',
			),
			'report_logo'   => array(
				'section' => 'report',
				'type'    => 'media',
				'label'   => 'Custom logo',
				'help'    => 'Used when Branding is set to Custom logo. A wide PNG or SVG on a transparent or white background prints best.',
			),
			'report_by'     => array(
				'section'     => 'report',
				'type'        => 'text',
				'label'       => 'Prepared by',
				'placeholder' => self::DEFAULT_BY,
				'help'        => 'Agency name on the cover. Leave blank to use The Bonsai Digital Collective with Bonsai branding, or to leave it off with the other options.',
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
	 * Load the media modal and the logo picker on the Settings tab. Called
	 * from BSGC_Admin::assets(), which has already loaded the styles.
	 */
	public static function assets() {
		wp_enqueue_media();
		wp_enqueue_script( 'bsgc-settings', BSGC_URL . 'assets/settings.js', array( 'jquery' ), BSGC_VERSION, true );
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

		foreach ( self::fields() as $key => $field ) {
			$old = isset( $stored[ $key ] ) ? (string) $stored[ $key ] : '';

			// Disabled fields aren't submitted, so keep whatever was there.
			if ( isset( self::MAP[ $key ] ) && defined( self::MAP[ $key ] ) ) {
				$clean[ $key ] = $old;
				continue;
			}

			// options.php has already unslashed the submitted values.
			$new = isset( $input[ $key ] ) ? trim( sanitize_text_field( $input[ $key ] ) ) : '';

			if ( 'choice' === $field['type'] ) {
				$new = isset( $field['choices'][ $new ] ) ? $new : $field['default'];
			} elseif ( 'media' === $field['type'] ) {
				// Only keep IDs of real image attachments.
				$new = absint( $new ) && wp_attachment_is_image( absint( $new ) ) ? (string) absint( $new ) : '';
			} elseif ( in_array( $key, self::SECRETS, true ) ) {
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
	 * @param string $key Setting key from self::fields().
	 * @return string
	 */
	public static function get( $key ) {
		$fields = self::fields();

		if ( ! isset( $fields[ $key ] ) ) {
			return '';
		}

		if ( isset( self::MAP[ $key ] ) && defined( self::MAP[ $key ] ) ) {
			$value = (string) constant( self::MAP[ $key ] );
		} else {
			$stored = self::stored();
			$value  = isset( $stored[ $key ] ) ? (string) $stored[ $key ] : '';
		}

		$value = trim( $value );

		if ( 'model' === $key && '' === $value ) {
			$value = self::DEFAULT_MODEL;
		}

		if ( '' === $value && isset( $fields[ $key ]['default'] ) ) {
			$value = $fields[ $key ]['default'];
		}

		return $value;
	}

	/**
	 * Branding for the printed report cover.
	 *
	 * @return array { logo: URL or '', logoAlt: string, preparedBy: string }
	 */
	public static function report_brand() {
		$brand = self::get( 'report_brand' );
		$by    = self::get( 'report_by' );
		$logo  = '';
		$alt   = '';

		if ( 'bonsai' === $brand ) {
			$logo = BSGC_URL . 'assets/bonsai-avatar.jpg';
			$alt  = self::DEFAULT_BY;
			$by   = '' !== $by ? $by : self::DEFAULT_BY;
		} elseif ( 'custom' === $brand && self::get( 'report_logo' ) ) {
			$id   = absint( self::get( 'report_logo' ) );
			$url  = wp_get_attachment_image_url( $id, 'medium_large' );
			$logo = $url ? $url : '';
			$alt  = trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) );
			$alt  = '' !== $alt ? $alt : $by;
		}

		return array(
			'logo'       => $logo,
			'logoAlt'    => $alt,
			'preparedBy' => $by,
		);
	}

	/**
	 * URL of the settings screen.
	 *
	 * @return string
	 */
	public static function settings_url() {
		return add_query_arg( 'tab', 'settings', BSGC_Admin::page_url() );
	}

	/**
	 * Render the Settings tab. The hub prints the page wrap, header, notices
	 * and tabs around it, and has already checked manage_options.
	 */
	public static function render() {
		$stored = self::stored();
		$fields = self::fields();
		?>
		<div class="bsgc-settings">
			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>

				<?php foreach ( self::sections() as $section_id => $section ) : ?>
					<section class="bonsai-ui-card" aria-labelledby="bsgc-section-<?php echo esc_attr( $section_id ); ?>">
						<h2 class="bonsai-ui-card__title" id="bsgc-section-<?php echo esc_attr( $section_id ); ?>"><?php echo esc_html( $section['title'] ); ?></h2>
						<p class="bonsai-ui-card__intro"><?php echo esc_html( $section['intro'] ); ?></p>

						<table class="form-table" role="presentation">
							<?php
							foreach ( $fields as $key => $field ) {
								if ( $section_id === $field['section'] ) {
									self::render_field( $key, $field, $stored );
								}
							}
							?>
						</table>
					</section>
				<?php endforeach; ?>

				<?php submit_button( 'Save settings' ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render one settings row.
	 *
	 * @param string $key    Setting key.
	 * @param array  $field  Field definition from self::fields().
	 * @param array  $stored Stored option values.
	 */
	private static function render_field( $key, $field, $stored ) {
		$id       = 'bsgc-' . str_replace( '_', '-', $key );
		$name     = self::OPTION . '[' . $key . ']';
		$constant = isset( self::MAP[ $key ] ) ? self::MAP[ $key ] : '';
		$is_const = $constant && defined( $constant );
		$saved    = isset( $stored[ $key ] ) ? (string) $stored[ $key ] : '';
		$type     = $field['type'];
		?>
		<tr>
			<th scope="row">
				<?php if ( 'choice' === $type ) : ?>
					<?php echo esc_html( $field['label'] ); ?>
				<?php else : ?>
					<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $field['label'] ); ?></label>
				<?php endif; ?>
			</th>
			<td>
				<?php if ( $is_const ) : ?>
					<input type="text" id="<?php echo esc_attr( $id ); ?>" class="regular-text" value="<?php echo esc_attr( 'secret' === $type ? 'Set in wp-config.php' : (string) constant( $constant ) ); ?>" disabled aria-describedby="<?php echo esc_attr( $id ); ?>-help">
					<p class="description" id="<?php echo esc_attr( $id ); ?>-help">Controlled by <code><?php echo esc_html( $constant ); ?></code> in wp-config.php. Remove the constant to manage it here.</p>

				<?php elseif ( 'secret' === $type ) : ?>
					<?php
					// Never print the saved key; just enough of it to recognise which one is in use.
					$placeholder = '' !== $saved ? 'Saved key ending ' . substr( $saved, -4 ) . ' – leave blank to keep it' : 'Not set';
					?>
					<input type="password" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" class="regular-text" value="" placeholder="<?php echo esc_attr( $placeholder ); ?>" autocomplete="new-password" spellcheck="false" aria-describedby="<?php echo esc_attr( $id ); ?>-help">
					<?php if ( '' !== $saved ) : ?>
						<p><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION . '[clear_' . $key . ']' ); ?>" value="1"> Remove saved key</label></p>
					<?php endif; ?>
					<p class="description" id="<?php echo esc_attr( $id ); ?>-help"><?php echo esc_html( $field['help'] ); ?></p>

				<?php elseif ( 'choice' === $type ) : ?>
					<?php $current = '' !== $saved ? $saved : $field['default']; ?>
					<fieldset aria-describedby="<?php echo esc_attr( $id ); ?>-help">
						<legend class="screen-reader-text"><?php echo esc_html( $field['label'] ); ?></legend>
						<?php foreach ( $field['choices'] as $value => $label ) : ?>
							<label><input type="radio" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php checked( $current, $value ); ?>> <?php echo esc_html( $label ); ?></label><br>
						<?php endforeach; ?>
					</fieldset>
					<p class="description" id="<?php echo esc_attr( $id ); ?>-help"><?php echo esc_html( $field['help'] ); ?></p>

				<?php elseif ( 'media' === $type ) : ?>
					<?php $preview = $saved ? wp_get_attachment_image_url( absint( $saved ), 'medium' ) : ''; ?>
					<div class="bsgc-media">
						<input type="hidden" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $preview ? $saved : '' ); ?>">
						<img class="bsgc-media__preview" src="<?php echo esc_url( $preview ? $preview : '' ); ?>" alt="Current logo"<?php echo $preview ? '' : ' hidden'; ?>>
						<p>
							<button type="button" class="button bsgc-media__choose" aria-describedby="<?php echo esc_attr( $id ); ?>-help"><?php echo $preview ? 'Change logo' : 'Choose logo'; ?></button>
							<button type="button" class="button-link bsgc-media__remove"<?php echo $preview ? '' : ' hidden'; ?>>Remove</button>
						</p>
					</div>
					<p class="description" id="<?php echo esc_attr( $id ); ?>-help"><?php echo esc_html( $field['help'] ); ?></p>

				<?php else : ?>
					<input type="text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" class="regular-text" value="<?php echo esc_attr( $saved ); ?>" placeholder="<?php echo esc_attr( isset( $field['placeholder'] ) ? $field['placeholder'] : '' ); ?>" spellcheck="false" aria-describedby="<?php echo esc_attr( $id ); ?>-help">
					<p class="description" id="<?php echo esc_attr( $id ); ?>-help"><?php echo esc_html( $field['help'] ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}
}
