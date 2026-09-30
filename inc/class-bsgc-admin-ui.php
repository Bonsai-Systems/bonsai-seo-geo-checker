<?php
/**
 * Bonsai admin UI: shared header and stylesheet for this plugin's screens.
 *
 * Markup and CSS follow the Bonsai admin design system
 * (assets/bonsai-admin-ui.css). Each Bonsai plugin carries its own copy, so
 * nothing here depends on another plugin being active.
 *
 * @package BonsaiSEOGEOChecker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the Bonsai header and loads the design-system stylesheet.
 */
class BSGC_Admin_UI {

	const REPO    = 'https://github.com/Bonsai-Systems/bonsai-seo-geo-checker';
	const WEBSITE = 'https://bonsaidigitalcollective.co.uk/';
	const HANDLE  = 'bsgc-bonsai-admin-ui';

	/**
	 * Register the design-system stylesheet. Call from admin_enqueue_scripts
	 * on this plugin's screens only.
	 */
	public static function enqueue() {
		wp_enqueue_style( self::HANDLE, BSGC_URL . 'assets/bonsai-admin-ui.css', array(), BSGC_VERSION );
	}

	/**
	 * Print the page header, followed by the marker core uses to place
	 * admin notices, so notices sit below the header rather than above it.
	 *
	 * @param string $title Page title (plain text).
	 * @param string $lead  Short description. May contain links, code and emphasis.
	 * @param array  $links Extra links shown before the standard ones: each array( 'label' => '', 'url' => '' ).
	 */
	public static function header( $title, $lead = '', $links = array() ) {
		$links = array_merge(
			$links,
			array(
				array(
					'label'    => 'GitHub',
					'url'      => self::REPO,
					'external' => true,
				),
				array(
					'label'    => 'Changelog',
					'url'      => self::REPO . '/releases',
					'external' => true,
				),
				array(
					'label'    => 'The Bonsai Digital Collective',
					'url'      => self::WEBSITE,
					'external' => true,
				),
			)
		);
		?>
		<header class="bonsai-ui-header">
			<div class="bonsai-ui-header__main">
				<img class="bonsai-ui-header__logo" src="<?php echo esc_url( BSGC_URL . 'assets/bonsai-avatar.jpg' ); ?>" width="412" height="108" alt="The Bonsai Digital Collective">
				<h1 class="bonsai-ui-header__title"><?php echo esc_html( $title ); ?></h1>
				<?php if ( '' !== $lead ) : ?>
					<p class="bonsai-ui-header__lead"><?php echo wp_kses( $lead, self::lead_tags() ); ?></p>
				<?php endif; ?>
				<ul class="bonsai-ui-header__links">
					<?php foreach ( $links as $link ) : ?>
						<li>
							<?php if ( ! empty( $link['external'] ) ) : ?>
								<a href="<?php echo esc_url( $link['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $link['label'] ); ?><span class="screen-reader-text"> (opens in a new tab)</span></a>
							<?php else : ?>
								<a href="<?php echo esc_url( $link['url'] ); ?>"><?php echo esc_html( $link['label'] ); ?></a>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
			<div class="bonsai-ui-header__meta">
				<span class="bonsai-ui-version">v<?php echo esc_html( BSGC_VERSION ); ?></span>
			</div>
		</header>
		<hr class="wp-header-end">
		<?php
	}

	/**
	 * Tags allowed in the header lead.
	 *
	 * @return array
	 */
	private static function lead_tags() {
		return array(
			'a'      => array( 'href' => true ),
			'code'   => array(),
			'strong' => array(),
			'em'     => array(),
		);
	}
}
