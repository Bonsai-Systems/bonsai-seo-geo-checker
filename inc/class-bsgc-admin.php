<?php
/**
 * Admin screen (Tools → SEO/GEO checker) and AJAX endpoints.
 *
 * @package BonsaiSEOGEOChecker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin UI.
 */
class BSGC_Admin {

	const SLUG = 'bsgc';
	const HOOK = 'tools_page_bsgc';

	/**
	 * Hook in.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );

		foreach ( array( 'run_check', 'run_pagespeed', 'run_ai', 'get_report', 'delete_report' ) as $action ) {
			add_action( 'wp_ajax_bsgc_' . $action, array( __CLASS__, 'ajax_' . $action ) );
		}
	}

	/**
	 * Register the Tools page.
	 */
	public static function menu() {
		add_management_page( 'SEO/GEO checker', 'SEO/GEO checker', 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
	}

	/**
	 * URL of the checker screen.
	 *
	 * @return string
	 */
	public static function page_url() {
		return admin_url( 'tools.php?page=' . self::SLUG );
	}

	/**
	 * Checker URL that fills in a page and runs it straight away. Nonced so a
	 * crafted link can't make an admin spend PageSpeed/Claude credits.
	 *
	 * @param string $url     Page to check.
	 * @param int    $post_id Post the page belongs to, if any.
	 * @return string
	 */
	public static function run_url( $url, $post_id = 0 ) {
		return add_query_arg(
			array(
				'bsgc_run'   => rawurlencode( $url ),
				'bsgc_post'  => absint( $post_id ),
				'_bsgcnonce' => wp_create_nonce( 'bsgc_run' ),
			),
			self::page_url()
		);
	}

	/**
	 * Checker URL that opens a saved report.
	 *
	 * @param int $id Report ID.
	 * @return string
	 */
	public static function report_url( $id ) {
		return add_query_arg( 'bsgc_report', absint( $id ), self::page_url() );
	}

	/**
	 * What to do on load when arriving from the editor box or admin bar.
	 * An expired nonce still fills in the URL, it just doesn't auto-run.
	 *
	 * @return array|null
	 */
	private static function launch() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- run is nonce-checked below; opening a report is read-only and its AJAX fetch is nonced.
		if ( isset( $_GET['bsgc_run'] ) ) {
			// normalise_url() sanitises with esc_url_raw(); sanitize_text_field() would strip %-encoded characters from permalinks.
			$url = BSGC_Fetcher::normalise_url( wp_unslash( $_GET['bsgc_run'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

			if ( is_wp_error( $url ) ) {
				return null;
			}

			$nonce = isset( $_GET['_bsgcnonce'] ) ? sanitize_key( wp_unslash( $_GET['_bsgcnonce'] ) ) : '';

			return array(
				'url'     => $url,
				'postId'  => isset( $_GET['bsgc_post'] ) ? absint( $_GET['bsgc_post'] ) : 0,
				'autorun' => (bool) wp_verify_nonce( $nonce, 'bsgc_run' ),
			);
		}

		if ( isset( $_GET['bsgc_report'] ) ) {
			return array( 'report' => absint( $_GET['bsgc_report'] ) );
		}
		// phpcs:enable

		return null;
	}

	/**
	 * Enqueue CSS/JS on our screen only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function assets( $hook ) {
		if ( self::HOOK !== $hook ) {
			return;
		}

		BSGC_Admin_UI::enqueue();
		wp_enqueue_style( 'bsgc-admin', BSGC_URL . 'assets/admin.css', array( BSGC_Admin_UI::HANDLE ), BSGC_VERSION );
		wp_enqueue_script( 'bsgc-admin', BSGC_URL . 'assets/admin.js', array( 'jquery' ), BSGC_VERSION, true );

		$user = wp_get_current_user();

		// Inline JSON rather than wp_localize_script so booleans stay booleans.
		wp_add_inline_script(
			'bsgc-admin',
			'var BSGC = ' . wp_json_encode(
				array(
					'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
					'nonce'       => wp_create_nonce( 'bsgc' ),
					'categories'  => BSGC_Analyser::CATEGORIES,
					'hasAi'       => '' !== BSGC_Settings::get( 'anthropic_key' ),
					'settingsUrl' => BSGC_Settings::settings_url(),
					'signOff'     => $user->first_name ? $user->first_name : $user->display_name,
					'pageUrl'     => self::page_url(),
					'launch'      => self::launch(),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Render the screen.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$recent = BSGC_Reports::recent( 25 );
		?>
		<div class="wrap bonsai-ui bsgc">
			<?php
			BSGC_Admin_UI::header(
				'SEO/GEO checker',
				'Checks one page for search basics and AI visibility, tests mobile performance with PageSpeed Insights, then writes a prioritised fix list.',
				array(
					array(
						'label' => 'Settings',
						'url'   => BSGC_Settings::settings_url(),
					),
				)
			);
			?>

			<form id="bsgc-form" class="bsgc-form" novalidate>
				<label for="bsgc-url">Page URL</label>
				<div class="bsgc-form__row">
					<input type="text" id="bsgc-url" name="url" inputmode="url" autocomplete="url" spellcheck="false" placeholder="https://example.co.uk/" required>
					<button type="submit" class="button button-primary bsgc-button">Run check</button>
				</div>
			</form>

			<p id="bsgc-status" class="bsgc-status" role="status" aria-live="polite"></p>

			<div id="bsgc-report" class="bsgc-report" hidden></div>

			<section class="bsgc-history" aria-labelledby="bsgc-history-title">
				<h2 id="bsgc-history-title">Previous checks</h2>
				<div class="bsgc-table-wrap">
					<table class="widefat striped bsgc-history__table">
						<thead>
							<tr>
								<th scope="col">Page</th>
								<th scope="col">Score</th>
								<th scope="col">Checked</th>
								<th scope="col"><span class="screen-reader-text">Actions</span></th>
							</tr>
						</thead>
						<tbody id="bsgc-history-body">
							<?php if ( empty( $recent ) ) : ?>
								<tr class="bsgc-history__empty"><td colspan="4">No checks yet. Enter a URL above to run the first one.</td></tr>
							<?php else : ?>
								<?php foreach ( $recent as $item ) : ?>
									<tr data-id="<?php echo esc_attr( $item['id'] ); ?>">
										<td><a href="<?php echo esc_url( $item['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $item['url'] ); ?></a></td>
										<td class="bsgc-history__score"><?php echo null === $item['score'] ? '&mdash;' : esc_html( $item['score'] ); ?></td>
										<td><?php echo esc_html( $item['date'] ); ?></td>
										<td class="bsgc-history__actions">
											<button type="button" class="button-link bsgc-view" aria-label="<?php echo esc_attr( 'View report for ' . $item['url'] ); ?>">View</button>
											<button type="button" class="button-link button-link-delete bsgc-delete" aria-label="<?php echo esc_attr( 'Delete report for ' . $item['url'] ); ?>">Delete</button>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			</section>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------------ */

	/**
	 * Nonce and capability check for every request.
	 */
	private static function verify() {
		if ( ! check_ajax_referer( 'bsgc', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Your session has expired. Reload the page and try again.' ), 403 );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'You don\'t have permission to run checks.' ), 403 );
		}

		// PageSpeed and Claude can each take up to a minute.
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 150 );
		}
	}

	/**
	 * Load the report named in the request, or bail.
	 *
	 * @return array
	 */
	private static function requested_report() {
		$id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in verify().
		$report = BSGC_Reports::get( $id );

		if ( ! $report ) {
			wp_send_json_error( array( 'message' => 'That report no longer exists.' ), 404 );
		}

		return $report;
	}

	/**
	 * Run the main checks and save the report.
	 */
	public static function ajax_run_check() {
		self::verify();

		// normalise_url() sanitises with esc_url_raw(); sanitize_text_field() would strip %-encoded characters from permalinks.
		$raw     = isset( $_POST['url'] ) ? wp_unslash( $_POST['url'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified in verify(); sanitised by normalise_url().
		$url     = BSGC_Fetcher::normalise_url( $raw );
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in verify().

		if ( is_wp_error( $url ) ) {
			wp_send_json_error( array( 'message' => $url->get_error_message() ) );
		}

		$report = ( new BSGC_Analyser( $url ) )->run();

		if ( is_wp_error( $report ) ) {
			wp_send_json_error( array( 'message' => $report->get_error_message() ) );
		}

		// Typed-in URLs on this site still get linked to their post; returns 0 for other sites.
		if ( ! $post_id ) {
			$post_id = url_to_postid( $report['final_url'] );
		}

		$id = BSGC_Reports::save( $report, $post_id );

		if ( is_wp_error( $id ) ) {
			wp_send_json_error( array( 'message' => 'Checks ran but the report could not be saved: ' . $id->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'report'  => BSGC_Reports::get( $id ),
				'history' => BSGC_Reports::summary( $id ),
			)
		);
	}

	/**
	 * Run PageSpeed Insights for a saved report.
	 */
	public static function ajax_run_pagespeed() {
		self::verify();

		$report = self::requested_report();
		$result = BSGC_PageSpeed::run( $report['final_url'] );

		if ( is_wp_error( $result ) ) {
			$result = array(
				'status' => 'error',
				'error'  => $result->get_error_message(),
			);
		}

		BSGC_Reports::save_part( $report['id'], 'perf', $result );

		wp_send_json_success( array( 'report' => BSGC_Reports::get( $report['id'] ) ) );
	}

	/**
	 * Generate the fix list for a saved report.
	 */
	public static function ajax_run_ai() {
		self::verify();

		$report = self::requested_report();
		$result = BSGC_AI::run( $report );

		if ( is_wp_error( $result ) ) {
			$result = array(
				'status' => 'error',
				'error'  => $result->get_error_message(),
			);
		}

		BSGC_Reports::save_part( $report['id'], 'ai', $result );

		wp_send_json_success( array( 'ai' => $result ) );
	}

	/**
	 * Fetch a saved report.
	 */
	public static function ajax_get_report() {
		self::verify();
		wp_send_json_success( array( 'report' => self::requested_report() ) );
	}

	/**
	 * Delete a saved report.
	 */
	public static function ajax_delete_report() {
		self::verify();

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in verify().

		if ( ! BSGC_Reports::delete( $id ) ) {
			wp_send_json_error( array( 'message' => 'That report could not be deleted.' ) );
		}

		wp_send_json_success();
	}
}
