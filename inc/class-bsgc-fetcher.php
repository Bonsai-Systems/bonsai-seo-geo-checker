<?php
/**
 * HTTP fetching. Uses wp_safe_remote_get(), which rejects private/internal IPs
 * and odd ports (including on redirects), so the tool can't be pointed at the
 * server's own network.
 *
 * @package BonsaiSEOGEOChecker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fetches remote URLs.
 */
class BSGC_Fetcher {

	/**
	 * Realistic browser UA for the main fetch, so we see what visitors see
	 * and don't trip naive bot blocks.
	 */
	const UA_BROWSER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';

	/**
	 * OpenAI search crawler UA, used for the firewall check.
	 */
	const UA_AI_BOT = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; OAI-SearchBot/1.0; +https://openai.com/searchbot';

	/**
	 * Tidy and validate a user-supplied URL.
	 *
	 * @param string $url Raw input.
	 * @return string|WP_Error
	 */
	public static function normalise_url( $url ) {
		$url = trim( (string) $url );

		if ( '' === $url ) {
			return new WP_Error( 'bsgc_empty_url', 'Enter a URL to check.' );
		}

		if ( ! preg_match( '#^https?://#i', $url ) ) {
			$url = 'https://' . $url;
		}

		$url = esc_url_raw( $url, array( 'http', 'https' ) );

		if ( ! $url || ! wp_http_validate_url( $url ) ) {
			return new WP_Error( 'bsgc_invalid_url', 'Enter a public web address, for example https://example.co.uk/' );
		}

		return $url;
	}

	/**
	 * GET a URL.
	 *
	 * @param string $url  URL.
	 * @param array  $args Overrides for wp_safe_remote_get().
	 * @return array|WP_Error { code, body, headers, time, final_url, redirects }
	 */
	public static function get( $url, $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'timeout'             => 20,
				'redirection'         => 5,
				'user-agent'          => self::UA_BROWSER,
				'limit_response_size' => 5 * MB_IN_BYTES,
				'headers'             => array(
					'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
					'Accept-Language' => 'en-GB,en;q=0.9',
				),
			)
		);

		$start    = microtime( true );
		$response = wp_safe_remote_get( $url, $args );
		$elapsed  = microtime( true ) - $start;

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$final_url = $url;
		$redirects = 0;

		if ( isset( $response['http_response'] ) && $response['http_response'] instanceof WP_HTTP_Requests_Response ) {
			$raw = $response['http_response']->get_response_object();

			if ( ! empty( $raw->url ) ) {
				$final_url = $raw->url;
			}

			$redirects = isset( $raw->redirects ) ? (int) $raw->redirects : 0;
		}

		return array(
			'code'      => (int) wp_remote_retrieve_response_code( $response ),
			'body'      => (string) wp_remote_retrieve_body( $response ),
			'headers'   => wp_remote_retrieve_headers( $response ),
			'time'      => $elapsed,
			'final_url' => $final_url,
			'redirects' => $redirects,
		);
	}

	/**
	 * HTTP status of a URL after redirects. Tries HEAD first and falls back to a
	 * small GET for servers that don't support HEAD.
	 *
	 * @param string $url URL.
	 * @return int|WP_Error Status code.
	 */
	public static function status( $url ) {
		$args = array(
			'timeout'     => 5,
			'redirection' => 3, // HEAD requests don't follow redirects unless told to.
			'user-agent'  => self::UA_BROWSER,
		);

		$response = wp_safe_remote_head( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( in_array( $code, array( 405, 501 ), true ) ) {
			$response = wp_safe_remote_get( $url, $args + array( 'limit_response_size' => 64 * KB_IN_BYTES ) );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
		}

		return $code;
	}

	/**
	 * Read a header as a string (handles repeated headers).
	 *
	 * @param mixed  $headers Headers object/array from get().
	 * @param string $name    Header name, lowercase.
	 * @return string
	 */
	public static function header( $headers, $name ) {
		if ( empty( $headers ) || ! isset( $headers[ $name ] ) ) {
			return '';
		}

		$value = $headers[ $name ];

		return is_array( $value ) ? implode( ', ', $value ) : (string) $value;
	}

	/**
	 * Scheme + host (+ port) for a URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function origin( $url ) {
		$parts = wp_parse_url( $url );

		return $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
	}

	/**
	 * True if a successful response looks like a plain text file rather than
	 * an HTML page (many sites serve their homepage or a 404 template with 200).
	 *
	 * @param array|WP_Error $res Response from get().
	 * @return bool
	 */
	public static function is_text_file( $res ) {
		if ( is_wp_error( $res ) || 200 !== $res['code'] ) {
			return false;
		}

		$type = strtolower( self::header( $res['headers'], 'content-type' ) );
		$body = ltrim( $res['body'] );

		return '' !== $body && false === strpos( $type, 'html' ) && '<' !== substr( $body, 0, 1 );
	}
}
