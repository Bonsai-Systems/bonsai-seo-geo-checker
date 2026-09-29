<?php
/**
 * Entry points outside the Tools screen: a sidebar box on post edit screens
 * and a "Check this page" link in the front-end admin bar. Both open the
 * checker in a new tab with the URL filled in and the check running.
 *
 * @package BonsaiSEOGEOChecker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Editor and admin bar integration.
 */
class BSGC_Editor {

	/**
	 * Hook in.
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 90 );
	}

	/**
	 * Post types that get the sidebar box: every public type except attachments.
	 *
	 * @return string[]
	 */
	public static function post_types() {
		$types = get_post_types( array( 'public' => true ) );
		unset( $types['attachment'] );

		/**
		 * Filter the post types that show the SEO/GEO check box.
		 *
		 * @param string[] $types Post type names.
		 */
		return (array) apply_filters( 'bsgc_post_types', array_values( $types ) );
	}

	/**
	 * Register the sidebar box.
	 */
	public static function meta_boxes() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		foreach ( self::post_types() as $type ) {
			add_meta_box( 'bsgc-check', 'SEO/GEO check', array( __CLASS__, 'render' ), $type, 'side', 'default' );
		}
	}

	/**
	 * Render the sidebar box.
	 *
	 * @param WP_Post $post Current post.
	 */
	public static function render( $post ) {
		$type = get_post_type_object( $post->post_type );
		$noun = $type ? strtolower( $type->labels->singular_name ) : 'page';
		$last = BSGC_Reports::latest_for_post( $post->ID );

		if ( 'publish' === $post->post_status ) {
			printf(
				'<p><a href="%1$s" class="button button-primary" target="_blank" rel="noopener noreferrer">%2$s<span class="screen-reader-text"> (opens in a new tab)</span></a></p>',
				esc_url( BSGC_Admin::run_url( get_permalink( $post ), $post->ID ) ),
				esc_html( 'Check this ' . $noun )
			);

			if ( '' !== $post->post_password ) {
				echo '<p class="description">' . esc_html( sprintf( 'This %s is password-protected, so the checker will only see the password form.', $noun ) ) . '</p>';
			}
		} else {
			// The block editor doesn't re-render meta boxes after saving, hence "reload".
			echo '<p class="description">' . esc_html( sprintf( 'Publish this %s, then reload this screen to check it. The checker fetches the live page, so drafts, scheduled and private content can\'t be checked.', $noun ) ) . '</p>';
		}

		if ( $last ) {
			printf(
				'<p>Last checked %1$s: <strong>%2$s</strong>. <a href="%3$s" target="_blank" rel="noopener noreferrer">View report<span class="screen-reader-text"> (opens in a new tab)</span></a></p>',
				esc_html( $last['date'] ),
				esc_html( null === $last['score'] ? 'no score' : $last['score'] . '/100' ),
				esc_url( BSGC_Admin::report_url( $last['id'] ) )
			);
		} else {
			echo '<p class="description">Not checked yet.</p>';
		}
	}

	/**
	 * Add "Check this page" to the admin bar on the front end.
	 *
	 * @param WP_Admin_Bar $bar Admin bar.
	 */
	public static function admin_bar( $bar ) {
		if ( is_admin() || ! current_user_can( 'manage_options' ) || is_preview() || is_404() ) {
			return;
		}

		$post_id = 0;

		if ( is_singular() ) {
			$post_id = get_queried_object_id();

			// Admins can view private posts, but the checker can't fetch them.
			if ( 'publish' !== get_post_status( $post_id ) ) {
				return;
			}

			$url = get_permalink( $post_id );
		} else {
			$url = self::current_url();
		}

		if ( ! $url ) {
			return;
		}

		$bar->add_node(
			array(
				'id'    => 'bsgc-check',
				'title' => 'Check this page',
				'href'  => BSGC_Admin::run_url( $url, $post_id ),
				'meta'  => array(
					'target' => '_blank',
					'rel'    => 'noopener noreferrer',
					'title'  => 'Run the SEO/GEO checker on this page (opens in a new tab)',
				),
			)
		);
	}

	/**
	 * URL of the current front-end request (archives, search, etc.).
	 * Uses the site's own origin rather than the Host header.
	 *
	 * @return string
	 */
	private static function current_url() {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}

		return esc_url_raw( BSGC_Fetcher::origin( home_url() ) . wp_unslash( $_SERVER['REQUEST_URI'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised by esc_url_raw().
	}
}
