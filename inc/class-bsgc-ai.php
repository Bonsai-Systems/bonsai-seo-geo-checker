<?php
/**
 * Turns failed/warning checks into a prioritised fix list via the Claude API.
 *
 * @package BonsaiSEOGEOChecker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Claude fix-list generator.
 */
class BSGC_AI {

	const ENDPOINT = 'https://api.anthropic.com/v1/messages';

	const SYSTEM_PROMPT = <<<'PROMPT'
You are a senior technical SEO and GEO (generative engine optimisation) consultant at a UK web agency that builds and maintains WordPress sites, usually ACF Pro child themes with an SEO plugin such as Yoast or Rank Math.

You will receive automated audit data for one URL: scores, key facts and every check that returned a warning or failure.

Write a prioritised fix list.

Rules:
- UK English. Plain, direct, practitioner tone. No hype, no guarantees about rankings or AI citations, no invented statistics.
- Only use issues present in the data. Do not invent problems. Merge related issues into one fix where sensible.
- Order by impact on search and AI visibility relative to effort. Indexing and crawl-access problems always come first.
- Maximum 8 fixes. Fewer is fine if there are fewer real issues.
- "why": one or two sentences a non-technical client would understand.
- "how": one to three concrete sentences for a WordPress developer, naming the setting, plugin screen, template or hook involved.
- "effort": quick (under 30 minutes), medium (half a day or less) or involved (more than half a day).
- "owner": developer, or client when the fix needs the business owner (content, accounts, profiles, decisions).
- "summary": two or three sentences for the client giving an honest overall picture, the strongest area and the most important thing to fix.

Respond with ONLY valid JSON, no markdown fences, in exactly this shape:
{"summary":"...","fixes":[{"priority":1,"title":"...","why":"...","how":"...","effort":"quick","owner":"developer"}]}
PROMPT;

	/**
	 * Generate the fix list.
	 *
	 * @param array $report Report (base checks, optionally with performance merged).
	 * @return array|WP_Error
	 */
	public static function run( $report ) {
		$key = BSGC_Settings::get( 'anthropic_key' );

		if ( '' === $key ) {
			return new WP_Error( 'bsgc_no_key', 'Add an Anthropic API key in Settings → SEO/GEO checker (or BSGC_ANTHROPIC_KEY in wp-config.php).' );
		}

		$categories = BSGC_Analyser::CATEGORIES;
		$issues     = array();
		foreach ( $report['checks'] as $check ) {
			if ( in_array( $check['status'], array( 'warn', 'fail' ), true ) ) {
				$issues[] = array(
					'category' => isset( $categories[ $check['category'] ] ) ? $categories[ $check['category'] ] : $check['category'],
					'check'    => $check['label'],
					'status'   => $check['status'],
					'finding'  => $check['message'],
					'value'    => $check['value'],
				);
			}
		}

		if ( empty( $issues ) ) {
			return array(
				'status'  => 'done',
				'summary' => 'Every scored check passed. Nothing to fix from this audit; the next gains will come from content and links rather than technical changes.',
				'fixes'   => array(),
			);
		}

		$payload = array(
			'url'             => $report['final_url'],
			'overall_score'   => $report['scores']['overall'],
			'category_scores' => $report['scores']['categories'],
			'facts'           => $report['facts'],
			'issues'          => $issues,
		);

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => 90,
				'headers' => array(
					'x-api-key'         => $key,
					'anthropic-version' => '2023-06-01',
					'content-type'      => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'      => BSGC_Settings::get( 'model' ),
						'max_tokens' => 2000,
						'system'     => self::SYSTEM_PROMPT,
						'messages'   => array(
							array(
								'role'    => 'user',
								'content' => "Audit data:\n" . wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
							),
						),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'bsgc_ai_failed', 'Claude API request failed: ' . $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$message = isset( $data['error']['message'] ) ? $data['error']['message'] : 'HTTP ' . $code;
			return new WP_Error( 'bsgc_ai_error', 'Claude API returned an error: ' . $message );
		}

		$text = '';
		foreach ( (array) ( isset( $data['content'] ) ? $data['content'] : array() ) as $block ) {
			if ( isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
				$text .= $block['text'];
			}
		}

		$text   = trim( preg_replace( '/^```(?:json)?\s*|\s*```$/', '', trim( $text ) ) );
		$parsed = json_decode( $text, true );

		if ( ! is_array( $parsed ) || ! isset( $parsed['fixes'] ) || ! is_array( $parsed['fixes'] ) ) {
			return new WP_Error( 'bsgc_ai_parse', 'The fix list came back in an unexpected format. Try again.' );
		}

		return self::sanitise( $parsed );
	}

	/**
	 * Sanitise the model output before it's stored or shown.
	 *
	 * @param array $parsed Decoded response.
	 * @return array
	 */
	private static function sanitise( $parsed ) {
		$fixes = array();

		foreach ( array_slice( $parsed['fixes'], 0, 8 ) as $i => $fix ) {
			if ( ! is_array( $fix ) || empty( $fix['title'] ) ) {
				continue;
			}

			$effort = isset( $fix['effort'] ) ? sanitize_key( $fix['effort'] ) : '';
			$owner  = isset( $fix['owner'] ) ? sanitize_key( $fix['owner'] ) : '';

			$fixes[] = array(
				'priority' => $i + 1,
				'title'    => sanitize_text_field( $fix['title'] ),
				'why'      => isset( $fix['why'] ) ? sanitize_textarea_field( $fix['why'] ) : '',
				'how'      => isset( $fix['how'] ) ? sanitize_textarea_field( $fix['how'] ) : '',
				'effort'   => in_array( $effort, array( 'quick', 'medium', 'involved' ), true ) ? $effort : 'medium',
				'owner'    => in_array( $owner, array( 'client', 'developer' ), true ) ? $owner : 'developer',
			);
		}

		return array(
			'status'  => 'done',
			'summary' => isset( $parsed['summary'] ) ? sanitize_textarea_field( $parsed['summary'] ) : '',
			'fixes'   => $fixes,
		);
	}
}
