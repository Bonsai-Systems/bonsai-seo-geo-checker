<?php
/**
 * Bonsai Hub: upgrader skin for installs run from the Plugins screen.
 *
 * Only loaded during an install, after core's class-wp-upgrader.php.
 * Swaps core's own .wrap/h1 for a Bonsai card (the hub page already has
 * both) and its "Return to Plugin Installer" links for hub ones.
 *
 * @package Bonsai_Hub
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin installer skin styled for the Bonsai hub.
 */
class Bonsai_Hub_Installer_Skin extends Plugin_Installer_Skin {

	/**
	 * Opens the progress card.
	 *
	 * @return void
	 */
	public function header() {
		if ( $this->done_header ) {
			return;
		}

		$this->done_header = true;

		echo '<section class="bonsai-ui-card bonsai-hub-install">';
		echo '<h2 class="bonsai-ui-card__title">' . esc_html( $this->options['title'] ) . '</h2>';
	}

	/**
	 * Closes the progress card.
	 *
	 * @return void
	 */
	public function footer() {
		if ( $this->done_footer ) {
			return;
		}

		$this->done_footer = true;

		echo '</section>';
	}

	/**
	 * Next steps once the install finishes: Activate on success, and always
	 * a way back to the catalogue.
	 *
	 * @return void
	 */
	public function after() {
		$key = isset( $this->options['bonsai_key'] ) ? $this->options['bonsai_key'] : '';

		echo '<p class="bonsai-ui-actions">';

		if ( '' !== $key && $this->result && ! is_wp_error( $this->result ) && current_user_can( 'activate_plugins' ) ) {
			printf(
				'<a class="button button-primary" href="%s">%s</a>',
				esc_url(
					Bonsai_Hub::url(
						Bonsai_Hub::MENU_SLUG,
						'',
						array(
							Bonsai_Hub_Installer::ACTION_PARAM => 'activate',
							'plugin'                           => $key,
							'_wpnonce'                         => wp_create_nonce( 'bonsai_hub_activate_' . $key ),
						)
					)
				),
				esc_html__( 'Activate', 'bonsai-hub' )
			);
		}

		printf(
			'<a class="button" href="%s">%s</a>',
			esc_url( Bonsai_Hub::url( Bonsai_Hub::MENU_SLUG ) ),
			esc_html__( 'Back to Bonsai plugins', 'bonsai-hub' )
		);

		echo '</p>';
	}
}
