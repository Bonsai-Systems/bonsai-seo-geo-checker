<?php
/**
 * Minimal robots.txt parser following RFC 9309: groups by user-agent,
 * falls back to *, longest matching rule wins, Allow wins ties,
 * supports * and $ wildcards.
 *
 * @package BonsaiSEOGEOChecker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Parses robots.txt.
 */
class BSGC_Robots {

	/**
	 * Groups: [ [ 'agents' => [], 'rules' => [ [ 'allow' => bool, 'path' => string ] ] ] ].
	 *
	 * @var array
	 */
	private $groups = array();

	/**
	 * Sitemap URLs declared in the file.
	 *
	 * @var array
	 */
	private $sitemaps = array();

	/**
	 * Constructor.
	 *
	 * @param string $txt robots.txt contents.
	 */
	public function __construct( $txt ) {
		$this->parse( (string) $txt );
	}

	/**
	 * Parse the file into groups.
	 *
	 * @param string $txt Contents.
	 */
	private function parse( $txt ) {
		$current   = null;
		$has_rules = false;

		foreach ( preg_split( '/\r\n|\r|\n/', $txt ) as $line ) {
			$line = trim( preg_replace( '/#.*$/', '', $line ) );

			if ( '' === $line || false === strpos( $line, ':' ) ) {
				continue;
			}

			list( $field, $value ) = array_map( 'trim', explode( ':', $line, 2 ) );
			$field                 = strtolower( $field );

			if ( 'user-agent' === $field ) {
				// Consecutive user-agent lines share a group; one after rules starts a new group.
				if ( null === $current || $has_rules ) {
					$this->groups[] = array(
						'agents' => array(),
						'rules'  => array(),
					);
					$current        = count( $this->groups ) - 1;
					$has_rules      = false;
				}
				$this->groups[ $current ]['agents'][] = strtolower( $value );
			} elseif ( 'sitemap' === $field ) {
				if ( '' !== $value ) {
					$this->sitemaps[] = $value;
				}
			} elseif ( null !== $current ) {
				$has_rules = true;

				// An empty Disallow means "allow everything", so it adds no rule.
				if ( ( 'allow' === $field || 'disallow' === $field ) && '' !== $value ) {
					$this->groups[ $current ]['rules'][] = array(
						'allow' => 'allow' === $field,
						'path'  => $value,
					);
				}
			}
		}
	}

	/**
	 * Declared sitemaps.
	 *
	 * @return array
	 */
	public function sitemaps() {
		return array_values( array_unique( $this->sitemaps ) );
	}

	/**
	 * Is the agent allowed to fetch the path?
	 *
	 * @param string $agent Product token, e.g. GPTBot.
	 * @param string $path  Path plus query, e.g. /about/?x=1.
	 * @return bool
	 */
	public function is_allowed( $agent, $path = '/' ) {
		$rules = $this->rules_for( strtolower( $agent ) );

		if ( null === $rules ) {
			$rules = $this->rules_for( '*' );
		}

		if ( empty( $rules ) ) {
			return true;
		}

		$best    = -1;
		$allowed = true;

		foreach ( $rules as $rule ) {
			if ( ! $this->matches( $rule['path'], $path ) ) {
				continue;
			}

			$length = strlen( $rule['path'] );

			if ( $length > $best || ( $length === $best && $rule['allow'] ) ) {
				$best    = $length;
				$allowed = $rule['allow'];
			}
		}

		return $allowed;
	}

	/**
	 * Merged rules for every group naming this agent, or null if none do.
	 *
	 * @param string $agent Lowercase agent.
	 * @return array|null
	 */
	private function rules_for( $agent ) {
		$found = false;
		$rules = array();

		foreach ( $this->groups as $group ) {
			if ( in_array( $agent, $group['agents'], true ) ) {
				$found = true;
				$rules = array_merge( $rules, $group['rules'] );
			}
		}

		return $found ? $rules : null;
	}

	/**
	 * Match a robots pattern against a path.
	 *
	 * @param string $pattern Rule path.
	 * @param string $path    Request path.
	 * @return bool
	 */
	private function matches( $pattern, $path ) {
		$regex = str_replace( '\*', '.*', preg_quote( $pattern, '#' ) );

		if ( '\$' === substr( $regex, -2 ) ) {
			$regex = substr( $regex, 0, -2 ) . '$';
		}

		return (bool) preg_match( '#^' . $regex . '#', $path );
	}
}
