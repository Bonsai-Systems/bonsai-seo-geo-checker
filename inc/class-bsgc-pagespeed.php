<?php
/**
 * PageSpeed Insights (mobile). Runs separately from the main checks because
 * it takes 20–60 seconds.
 *
 * @package BonsaiSEOGEOChecker
 */

defined( 'ABSPATH' ) || exit;

/**
 * PageSpeed Insights client.
 */
class BSGC_PageSpeed {

	const ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

	/**
	 * Run a mobile performance test.
	 *
	 * @param string $url URL.
	 * @return array|WP_Error { checks, metrics }
	 */
	public static function run( $url ) {
		$params = array(
			'url'      => $url,
			'strategy' => 'mobile',
			'category' => 'performance',
		);

		$key = BSGC_Settings::get( 'psi_key' );
		if ( $key ) {
			$params['key'] = $key;
		}

		$response = wp_remote_get(
			self::ENDPOINT . '?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 ),
			array( 'timeout' => 90 )
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'bsgc_psi_failed', 'PageSpeed Insights request failed: ' . $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			if ( 429 === $code ) {
				return new WP_Error( 'bsgc_psi_rate', 'PageSpeed Insights rate limit hit. Add a free API key in Settings → SEO/GEO checker.' );
			}

			$message = isset( $data['error']['message'] ) ? $data['error']['message'] : 'HTTP ' . $code;

			return new WP_Error( 'bsgc_psi_error', 'PageSpeed Insights returned an error: ' . $message );
		}

		if ( empty( $data['lighthouseResult']['audits'] ) ) {
			return new WP_Error( 'bsgc_psi_empty', 'PageSpeed Insights returned no results for this URL.' );
		}

		$lh     = $data['lighthouseResult'];
		$audits = $lh['audits'];
		$score  = isset( $lh['categories']['performance']['score'] ) ? (int) round( $lh['categories']['performance']['score'] * 100 ) : null;
		$lcp    = isset( $audits['largest-contentful-paint']['numericValue'] ) ? $audits['largest-contentful-paint']['numericValue'] / 1000 : null;
		$cls    = isset( $audits['cumulative-layout-shift']['numericValue'] ) ? (float) $audits['cumulative-layout-shift']['numericValue'] : null;
		$tbt    = isset( $audits['total-blocking-time']['numericValue'] ) ? (int) round( $audits['total-blocking-time']['numericValue'] ) : null;
		$inp    = isset( $data['loadingExperience']['metrics']['INTERACTION_TO_NEXT_PAINT']['percentile'] ) ? (int) $data['loadingExperience']['metrics']['INTERACTION_TO_NEXT_PAINT']['percentile'] : null;

		$checks = array();

		if ( null !== $score ) {
			$checks[] = self::check( 'psi_score', 'Mobile performance score', self::grade( $score, 90, 50, true ), sprintf( 'Lighthouse mobile score %d/100. It\'s a lab test, so expect a few points\' variation between runs.', $score ), 4, $score . '/100', 'Work through the metrics below: the worst one is usually where the points are going.' );
		}

		if ( null !== $lcp ) {
			$checks[] = self::check( 'lcp', 'Largest Contentful Paint', self::grade( $lcp, 2.5, 4 ), sprintf( 'Main content appears after %.1fs on a throttled mobile connection (good is 2.5s or less).', $lcp ), 2, '', 'Usually the hero image: compress it, serve WebP/AVIF and don\'t lazy-load it.' );
		}

		if ( null !== $cls ) {
			$checks[] = self::check( 'cls', 'Cumulative Layout Shift', self::grade( $cls, 0.1, 0.25 ), sprintf( 'Layout shift score %.2f (good is 0.1 or less).', $cls ), 2, '', 'Set width and height on images and reserve space for embeds and cookie banners.' );
		}

		if ( null !== $tbt ) {
			$checks[] = self::check( 'tbt', 'Total Blocking Time', self::grade( $tbt, 200, 600 ), sprintf( '%dms of main-thread blocking (good is 200ms or less), a lab stand-in for responsiveness.', $tbt ), 1, '', 'Usually too much JavaScript from plugins, sliders or tag managers. Remove what isn\'t needed and defer the rest.' );
		}

		if ( null !== $inp ) {
			$checks[] = self::check( 'inp', 'Interaction to Next Paint', self::grade( $inp, 200, 500 ), sprintf( 'Real Chrome users see %dms responsiveness (good is 200ms or less).', $inp ), 2, '', 'Cut heavy JavaScript that runs on clicks and taps, such as large event handlers, chat widgets and tag manager triggers.' );
		} else {
			$checks[] = self::check( 'inp', 'Real-user data', 'info', 'No real-user (Chrome UX Report) data. The site doesn\'t get enough Chrome traffic for field metrics, so the lab results above are all there is.', 0 );
		}

		return array(
			'status'  => 'done',
			'checks'  => $checks,
			'metrics' => array(
				'score' => $score,
				'lcp'   => $lcp,
				'cls'   => $cls,
				'tbt'   => $tbt,
				'inp'   => $inp,
			),
		);
	}

	/**
	 * Build a performance check.
	 *
	 * @param string $id      ID.
	 * @param string $label   Label.
	 * @param string $status  Status.
	 * @param string $message Message.
	 * @param int    $weight  Weight.
	 * @param string $value   Value.
	 * @param string $fix     Fix, shown when the check warns or fails.
	 * @return array
	 */
	private static function check( $id, $label, $status, $message, $weight, $value = '', $fix = '' ) {
		return array(
			'category' => 'performance',
			'id'       => $id,
			'label'    => $label,
			'status'   => $status,
			'message'  => $message,
			'weight'   => $weight,
			'value'    => (string) $value,
			'fix'      => (string) $fix,
		);
	}

	/**
	 * Grade a metric against good/poor thresholds.
	 *
	 * @param float $value         Value.
	 * @param float $good          Good threshold.
	 * @param float $poor          Poor threshold.
	 * @param bool  $higher_better Whether higher values are better.
	 * @return string
	 */
	private static function grade( $value, $good, $poor, $higher_better = false ) {
		if ( $higher_better ) {
			return $value >= $good ? 'pass' : ( $value >= $poor ? 'warn' : 'fail' );
		}

		return $value <= $good ? 'pass' : ( $value <= $poor ? 'warn' : 'fail' );
	}
}
