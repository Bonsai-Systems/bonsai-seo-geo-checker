<?php
/**
 * Settings: ACF Pro options sub-page, with wp-config.php constants taking priority.
 *
 * Constants (recommended for keys so they never sit in the database):
 *   define( 'BSGC_ANTHROPIC_KEY', 'sk-ant-...' );
 *   define( 'BSGC_PSI_KEY', 'AIza...' );
 *   define( 'BSGC_CLAUDE_MODEL', 'claude-haiku-4-5-20251001' );
 *
 * @package BonsaiSEOGEOChecker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles plugin settings.
 */
class BSGC_Settings {

	const DEFAULT_MODEL = 'claude-haiku-4-5-20251001';

	/**
	 * Map of setting key => [ constant, ACF field name ].
	 *
	 * @var array
	 */
	const MAP = array(
		'psi_key'       => array( 'BSGC_PSI_KEY', 'bsgc_psi_api_key' ),
		'anthropic_key' => array( 'BSGC_ANTHROPIC_KEY', 'bsgc_anthropic_api_key' ),
		'model'         => array( 'BSGC_CLAUDE_MODEL', 'bsgc_claude_model' ),
	);

	/**
	 * Hook in.
	 */
	public static function init() {
		add_action( 'acf/init', array( __CLASS__, 'register' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	/**
	 * Register the ACF options page and fields in PHP (no JSON sync needed).
	 */
	public static function register() {
		if ( ! function_exists( 'acf_add_options_sub_page' ) || ! function_exists( 'acf_add_local_field_group' ) ) {
			return;
		}

		acf_add_options_sub_page(
			array(
				'page_title'  => 'SEO/GEO checker settings',
				'menu_title'  => 'SEO/GEO checker',
				'menu_slug'   => 'bsgc-settings',
				'parent_slug' => 'options-general.php',
				'capability'  => 'manage_options',
			)
		);

		acf_add_local_field_group(
			array(
				'key'      => 'group_bsgc_settings',
				'title'    => 'SEO/GEO checker',
				'fields'   => array(
					array(
						'key'          => 'field_bsgc_anthropic_api_key',
						'label'        => 'Anthropic API key',
						'name'         => 'bsgc_anthropic_api_key',
						'type'         => 'password',
						'instructions' => 'Needed for the fix list. Better: define BSGC_ANTHROPIC_KEY in wp-config.php, which overrides this field and keeps the key out of the database.',
					),
					array(
						'key'           => 'field_bsgc_claude_model',
						'label'         => 'Claude model',
						'name'          => 'bsgc_claude_model',
						'type'          => 'text',
						'default_value' => self::DEFAULT_MODEL,
						'placeholder'   => self::DEFAULT_MODEL,
						'instructions'  => 'Haiku is plenty for fix lists and costs a fraction of a penny per report.',
					),
					array(
						'key'          => 'field_bsgc_psi_api_key',
						'label'        => 'PageSpeed Insights API key',
						'name'         => 'bsgc_psi_api_key',
						'type'         => 'password',
						'instructions' => 'Free from Google Cloud Console. Optional, but without one Google rate-limits PageSpeed requests heavily. BSGC_PSI_KEY in wp-config.php overrides this.',
					),
				),
				'location' => array(
					array(
						array(
							'param'    => 'options_page',
							'operator' => '==',
							'value'    => 'bsgc-settings',
						),
					),
				),
			)
		);
	}

	/**
	 * Get a setting. Constant first, then ACF option, then default.
	 *
	 * @param string $key Setting key from self::MAP.
	 * @return string
	 */
	public static function get( $key ) {
		$map = self::MAP;

		if ( ! isset( $map[ $key ] ) ) {
			return '';
		}

		list( $constant, $field ) = $map[ $key ];

		if ( defined( $constant ) ) {
			$value = (string) constant( $constant );
		} elseif ( function_exists( 'get_field' ) ) {
			$value = (string) get_field( $field, 'option' );
		} else {
			$value = '';
		}

		$value = trim( $value );

		if ( 'model' === $key && '' === $value ) {
			$value = self::DEFAULT_MODEL;
		}

		return $value;
	}

	/**
	 * URL of the settings screen, or empty if ACF is not active.
	 *
	 * @return string
	 */
	public static function settings_url() {
		return function_exists( 'acf_add_options_sub_page' ) ? admin_url( 'options-general.php?page=bsgc-settings' ) : '';
	}

	/**
	 * Warn on the checker screen when there's no way to store keys.
	 */
	public static function notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'tools_page_bsgc' !== $screen->id || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! function_exists( 'acf_add_options_sub_page' ) && ! defined( 'BSGC_ANTHROPIC_KEY' ) ) {
			echo '<div class="notice notice-warning"><p>ACF Pro isn\'t active, so there\'s no settings screen. Define <code>BSGC_ANTHROPIC_KEY</code> and <code>BSGC_PSI_KEY</code> in wp-config.php to enable the fix list and keyed PageSpeed requests.</p></div>';
		}
	}
}
