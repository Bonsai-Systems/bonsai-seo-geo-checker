<?php
/**
 * Stores reports in a hidden CPT. The base checks, PageSpeed results and fix
 * list live in separate meta keys so the two slow requests (which run in
 * parallel) never overwrite each other.
 *
 * @package BonsaiSEOGEOChecker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Report storage.
 */
class BSGC_Reports {

	const CPT        = 'bsgc_report';
	const META_BASE  = '_bsgc_report';
	const META_PERF  = '_bsgc_performance';
	const META_AI    = '_bsgc_ai';
	const META_SCORE = '_bsgc_score';
	const META_POST  = '_bsgc_post_id';

	/**
	 * Hook in.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Register the CPT (no UI, no front end).
	 */
	public static function register() {
		register_post_type(
			self::CPT,
			array(
				'label'           => 'SEO/GEO reports',
				'public'          => false,
				'show_ui'         => false,
				'show_in_rest'    => false,
				'rewrite'         => false,
				'query_var'       => false,
				'supports'        => array( 'title' ),
				'capability_type' => 'post',
				'map_meta_cap'    => true,
			)
		);
	}

	/**
	 * Save a new report.
	 *
	 * @param array $report  Base report from BSGC_Analyser::run().
	 * @param int   $post_id Post on this site the checked page belongs to, if any.
	 * @return int|WP_Error Report ID.
	 */
	public static function save( $report, $post_id = 0 ) {
		$id = wp_insert_post(
			array(
				'post_type'   => self::CPT,
				'post_status' => 'private',
				'post_title'  => $report['final_url'],
			),
			true
		);

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		update_post_meta( $id, self::META_BASE, wp_slash( $report ) );
		update_post_meta( $id, self::META_SCORE, (int) $report['scores']['overall'] );

		$post_id = absint( $post_id );
		if ( $post_id && get_post( $post_id ) && self::CPT !== get_post_type( $post_id ) ) {
			update_post_meta( $id, self::META_POST, $post_id );
		}

		return $id;
	}

	/**
	 * Save PageSpeed or AI results.
	 *
	 * @param int    $id   Report ID.
	 * @param string $part perf|ai.
	 * @param array  $data Data.
	 */
	public static function save_part( $id, $part, $data ) {
		update_post_meta( $id, 'perf' === $part ? self::META_PERF : self::META_AI, wp_slash( $data ) );

		if ( 'perf' === $part ) {
			$report = self::get( $id );
			if ( $report ) {
				update_post_meta( $id, self::META_SCORE, (int) $report['scores']['overall'] );
			}
		}
	}

	/**
	 * Get a full report with performance merged and scores recalculated.
	 *
	 * @param int $id Report ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		$id = absint( $id );

		if ( ! $id || self::CPT !== get_post_type( $id ) ) {
			return null;
		}

		$report = get_post_meta( $id, self::META_BASE, true );

		if ( ! is_array( $report ) ) {
			return null;
		}

		$perf = get_post_meta( $id, self::META_PERF, true );
		$ai   = get_post_meta( $id, self::META_AI, true );

		$report['id']          = $id;
		$report['post_id']     = (int) get_post_meta( $id, self::META_POST, true );
		$report['performance'] = is_array( $perf ) ? array_diff_key( $perf, array( 'checks' => 1 ) ) : array( 'status' => 'none' );

		if ( is_array( $perf ) && ! empty( $perf['checks'] ) ) {
			$report['checks'] = array_merge( $report['checks'], $perf['checks'] );
		}

		$report['scores'] = BSGC_Analyser::score( $report['checks'] );
		$report['ai']     = is_array( $ai ) ? $ai : null;

		return $report;
	}

	/**
	 * Recent reports for the history table.
	 *
	 * @param int $limit Number.
	 * @return array
	 */
	public static function recent( $limit = 25 ) {
		$posts = get_posts(
			array(
				'post_type'      => self::CPT,
				'post_status'    => 'private',
				'posts_per_page' => $limit,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);

		return array_map( array( __CLASS__, 'summary' ), $posts );
	}

	/**
	 * Most recent report for a post, for the editor sidebar box.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null Summary, or null if never checked.
	 */
	public static function latest_for_post( $post_id ) {
		$ids = get_posts(
			array(
				'post_type'      => self::CPT,
				'post_status'    => 'private',
				'posts_per_page' => 1,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
				'fields'         => 'ids',
				'meta_key'       => self::META_POST, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- small internal table, one row.
				'meta_value'     => absint( $post_id ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		return $ids ? self::summary( $ids[0] ) : null;
	}

	/**
	 * History-row summary for a report post.
	 *
	 * @param WP_Post|int $post Post.
	 * @return array
	 */
	public static function summary( $post ) {
		$post  = get_post( $post );
		$score = get_post_meta( $post->ID, self::META_SCORE, true );

		return array(
			'id'    => $post->ID,
			'url'   => $post->post_title,
			'score' => '' === $score ? null : (int) $score,
			'date'  => get_the_date( 'd/m/Y H:i', $post ),
		);
	}

	/**
	 * Delete a report.
	 *
	 * @param int $id Report ID.
	 * @return bool
	 */
	public static function delete( $id ) {
		$id = absint( $id );

		if ( ! $id || self::CPT !== get_post_type( $id ) ) {
			return false;
		}

		return (bool) wp_delete_post( $id, true );
	}
}
