<?php
/**
 * Runs the SEO and GEO checks against a single URL.
 *
 * Each check: category, id, label, status (pass|warn|fail|info), message, weight, value.
 * Scores: pass = full weight, warn = half, fail = zero, info / weight 0 = ignored.
 *
 * @package BonsaiSEOGEOChecker
 */

defined( 'ABSPATH' ) || exit;

/**
 * Page analyser.
 */
class BSGC_Analyser {

	const CATEGORIES = array(
		'technical'   => 'Technical',
		'meta'        => 'Titles and descriptions',
		'content'     => 'Content',
		'social'      => 'Social sharing',
		'schema'      => 'Structured data',
		'ai'          => 'AI visibility (GEO)',
		'performance' => 'Performance',
	);

	/**
	 * Crawlers that fetch pages for search results and live AI answers.
	 * Blocking these removes the site from those answers.
	 */
	const SEARCH_BOTS = array( 'Googlebot', 'Bingbot', 'OAI-SearchBot', 'ChatGPT-User', 'PerplexityBot', 'Perplexity-User', 'Claude-SearchBot', 'Claude-User' );

	/**
	 * Crawlers that collect training data. Blocking these is a policy choice.
	 */
	const TRAINING_BOTS = array( 'GPTBot', 'ClaudeBot', 'Google-Extended', 'CCBot', 'Applebot-Extended', 'meta-externalagent' );

	/**
	 * Requested URL.
	 *
	 * @var string
	 */
	private $url;

	/**
	 * Main page response.
	 *
	 * @var array
	 */
	private $page;

	/**
	 * Parsed document.
	 *
	 * @var DOMDocument
	 */
	private $dom;

	/**
	 * XPath for $dom.
	 *
	 * @var DOMXPath
	 */
	private $xpath;

	/**
	 * Origin of the final URL.
	 *
	 * @var string
	 */
	private $origin;

	/**
	 * Parsed robots.txt, if found.
	 *
	 * @var BSGC_Robots|null
	 */
	private $robots = null;

	/**
	 * Results.
	 *
	 * @var array
	 */
	private $checks = array();

	/**
	 * Key facts about the page, passed to the AI summary.
	 *
	 * @var array
	 */
	private $facts = array();

	/**
	 * Headings in document order: [ [ level, text ] ].
	 *
	 * @var array
	 */
	private $headings = array();

	/**
	 * Structured data findings.
	 *
	 * @var array
	 */
	private $schema = array(
		'types'    => array(),
		'same_as'  => 0,
		'has_date' => false,
		'invalid'  => 0,
	);

	/**
	 * Constructor.
	 *
	 * @param string $url Validated URL.
	 */
	public function __construct( $url ) {
		$this->url = $url;
	}

	/**
	 * Run every check.
	 *
	 * @return array|WP_Error Report.
	 */
	public function run() {
		$page = BSGC_Fetcher::get( $this->url );

		if ( is_wp_error( $page ) ) {
			return new WP_Error( 'bsgc_fetch_failed', 'Could not fetch the page: ' . $page->get_error_message() );
		}

		if ( $page['code'] >= 400 ) {
			$hint = in_array( $page['code'], array( 401, 403, 429, 503 ), true )
				? ' A firewall or bot protection (such as Cloudflare) may be blocking server-side requests. Allow-list this server\'s IP or test a staging URL.'
				: ' Check the URL is correct.';

			return new WP_Error( 'bsgc_http_error', sprintf( 'The page returned HTTP %d, so there is nothing meaningful to audit.', $page['code'] ) . $hint );
		}

		$type = strtolower( BSGC_Fetcher::header( $page['headers'], 'content-type' ) );

		if ( $type && false === strpos( $type, 'html' ) ) {
			return new WP_Error( 'bsgc_not_html', sprintf( 'That URL returned %s rather than an HTML page.', $type ) );
		}

		$this->page   = $page;
		$this->origin = BSGC_Fetcher::origin( $page['final_url'] );
		$this->dom    = $this->load_dom( $page['body'] );
		$this->xpath  = new DOMXPath( $this->dom );

		$this->check_technical();
		$this->check_meta();
		$this->check_content();
		$this->check_social();
		$this->check_schema();
		$this->check_ai();

		return array(
			'url'              => $this->url,
			'final_url'        => $page['final_url'],
			'checked_at'       => time(),
			'checked_at_label' => wp_date( 'd/m/Y H:i' ),
			'facts'            => $this->facts,
			'checks'           => $this->checks,
			'scores'           => self::score( $this->checks ),
		);
	}

	/**
	 * Score a set of checks overall and per category.
	 *
	 * @param array $checks Checks.
	 * @return array { overall: int, categories: { key: int } }
	 */
	public static function score( $checks ) {
		$factors = array(
			'pass' => 1,
			'warn' => 0.5,
			'fail' => 0,
		);
		$cats    = array();
		$earned  = 0;
		$total   = 0;

		foreach ( $checks as $check ) {
			if ( empty( $check['weight'] ) || ! isset( $factors[ $check['status'] ] ) ) {
				continue;
			}

			$cat = $check['category'];

			if ( ! isset( $cats[ $cat ] ) ) {
				$cats[ $cat ] = array( 0, 0 );
			}

			$points         = $check['weight'] * $factors[ $check['status'] ];
			$cats[ $cat ][0] += $points;
			$cats[ $cat ][1] += $check['weight'];
			$earned         += $points;
			$total          += $check['weight'];
		}

		$categories = array();

		foreach ( array_keys( self::CATEGORIES ) as $key ) {
			if ( isset( $cats[ $key ] ) && $cats[ $key ][1] > 0 ) {
				$categories[ $key ] = (int) round( $cats[ $key ][0] / $cats[ $key ][1] * 100 );
			}
		}

		return array(
			'overall'    => $total > 0 ? (int) round( $earned / $total * 100 ) : 0,
			'categories' => (object) $categories,
		);
	}

	/* ---------------------------------------------------------------------
	 * Checks
	 * ------------------------------------------------------------------ */

	/**
	 * HTTPS, status, redirects, speed, size, indexability, canonical, robots.txt, sitemap.
	 */
	private function check_technical() {
		$page = $this->page;
		$path = $this->request_path();

		$https = 0 === stripos( $page['final_url'], 'https://' );
		$this->add(
			'technical',
			'https',
			'HTTPS',
			$https ? 'pass' : 'fail',
			$https ? 'Served over HTTPS.' : 'Served over plain HTTP. Install an SSL certificate and redirect all traffic to HTTPS.',
			3
		);

		$this->add(
			'technical',
			'status',
			'HTTP status',
			200 === $page['code'] ? 'pass' : 'warn',
			sprintf( 'Returned HTTP %d.', $page['code'] ),
			3
		);
		$this->facts['http_status'] = $page['code'];

		$redirects = $page['redirects'];
		if ( 0 === $redirects ) {
			$this->add( 'technical', 'redirects', 'Redirects', 'pass', 'No redirects.', 1 );
		} elseif ( 1 === $redirects ) {
			$this->add( 'technical', 'redirects', 'Redirects', 'pass', 'One redirect to the final URL. Fine, but link directly to the final URL where you can.', 1, $page['final_url'] );
		} else {
			$this->add( 'technical', 'redirects', 'Redirects', 'warn', sprintf( 'Chain of %d redirects before the page loads. Collapse it into a single redirect and update internal links.', $redirects ), 1, $page['final_url'] );
		}

		$time   = round( $page['time'], 2 );
		$status = $time < 0.8 ? 'pass' : ( $time < 2 ? 'warn' : 'fail' );
		$this->add(
			'technical',
			'response_time',
			'Server response',
			$status,
			sprintf( 'HTML delivered in %ss (measured from this server, so treat it as indicative). Aim for under 0.8s; page caching usually fixes slow responses.', $time ),
			2
		);
		$this->facts['response_seconds'] = $time;

		$kb     = round( strlen( $page['body'] ) / 1024 );
		$status = $kb < 150 ? 'pass' : ( $kb < 400 ? 'warn' : 'fail' );
		$this->add(
			'technical',
			'html_size',
			'HTML size',
			$status,
			sprintf( 'HTML document is %d KB.%s', $kb, 'pass' === $status ? '' : ' Large HTML usually means inlined CSS/JS or a page builder bloating the markup.' ),
			1
		);

		$robots_meta = strtolower( (string) $this->meta( 'robots' ) . ' ' . (string) $this->meta( 'googlebot' ) );
		$x_robots    = strtolower( BSGC_Fetcher::header( $page['headers'], 'x-robots-tag' ) );
		$noindex     = false !== strpos( $robots_meta, 'noindex' ) || false !== strpos( $x_robots, 'noindex' );
		$this->add(
			'technical',
			'indexable',
			'Indexable',
			$noindex ? 'fail' : 'pass',
			$noindex ? 'Page is set to noindex (meta robots or X-Robots-Tag header). It will not appear in search results or most AI answers. Check Settings → Reading and the SEO plugin\'s per-page setting.' : 'No noindex directive found.',
			5
		);
		$this->facts['noindex'] = $noindex;

		$canonical = $this->xpath->query( '//link[translate(@rel,"CANONICAL","canonical")="canonical"]' )->item( 0 );
		if ( ! $canonical || '' === trim( $canonical->getAttribute( 'href' ) ) ) {
			$this->add( 'technical', 'canonical', 'Canonical tag', 'warn', 'No canonical tag. Add a self-referencing canonical to avoid duplicate-URL issues (most SEO plugins do this by default).', 2 );
		} else {
			$href = $this->absolute( $canonical->getAttribute( 'href' ) );
			if ( $this->compare_key( $href ) === $this->compare_key( $page['final_url'] ) ) {
				$this->add( 'technical', 'canonical', 'Canonical tag', 'pass', 'Self-referencing canonical.', 2 );
			} else {
				$this->add( 'technical', 'canonical', 'Canonical tag', 'warn', 'Canonical points to a different URL. Correct if this page is a deliberate duplicate; a problem if not, as search engines will index the other URL instead.', 2, $href );
			}
		}

		$lang = $this->xpath->query( '//html/@lang' )->item( 0 );
		$this->add(
			'technical',
			'lang',
			'Language',
			$lang && '' !== trim( $lang->nodeValue ) ? 'pass' : 'warn',
			$lang && '' !== trim( $lang->nodeValue ) ? sprintf( 'Language declared as %s.', trim( $lang->nodeValue ) ) : 'No lang attribute on <html>. Add lang="en-GB" so search engines and screen readers know the language.',
			1
		);

		$viewport = $this->meta( 'viewport' );
		$this->add(
			'technical',
			'viewport',
			'Mobile viewport',
			$viewport ? 'pass' : 'fail',
			$viewport ? 'Viewport meta tag present.' : 'No viewport meta tag, so the page won\'t scale properly on phones. Google indexes the mobile version first.',
			2
		);

		$robots_res = BSGC_Fetcher::get( $this->origin . '/robots.txt', array( 'limit_response_size' => 512 * KB_IN_BYTES ) );
		if ( BSGC_Fetcher::is_text_file( $robots_res ) ) {
			$this->robots = new BSGC_Robots( $robots_res['body'] );
			$this->add( 'technical', 'robots_txt', 'robots.txt', 'pass', 'robots.txt found.', 2, $this->origin . '/robots.txt' );

			$google_ok = $this->robots->is_allowed( 'Googlebot', $path );
			$this->add(
				'technical',
				'robots_block',
				'Crawlable by Google',
				$google_ok ? 'pass' : 'fail',
				$google_ok ? 'robots.txt allows Googlebot to crawl this URL.' : 'robots.txt blocks Googlebot from this URL. If WordPress\'s "Discourage search engines" was left on after launch, turn it off under Settings → Reading.',
				5
			);
		} else {
			$this->add( 'technical', 'robots_txt', 'robots.txt', 'warn', 'No robots.txt found. Not fatal, but it\'s the standard place to reference the sitemap and set crawler rules.', 2 );
		}

		$declared   = $this->robots ? $this->robots->sitemaps() : array();
		$candidates = array_unique( array_merge( $declared, array( $this->origin . '/sitemap_index.xml', $this->origin . '/wp-sitemap.xml', $this->origin . '/sitemap.xml' ) ) );
		$found      = '';

		foreach ( array_slice( $candidates, 0, 5 ) as $candidate ) {
			if ( ! preg_match( '#^https?://#i', $candidate ) ) {
				continue;
			}

			$res = BSGC_Fetcher::get( $candidate, array( 'limit_response_size' => MB_IN_BYTES ) );

			if ( ! is_wp_error( $res ) && 200 === $res['code'] && ( false !== stripos( $res['body'], '<urlset' ) || false !== stripos( $res['body'], '<sitemapindex' ) ) ) {
				$found = $candidate;
				break;
			}
		}

		if ( ! $found ) {
			$this->add( 'technical', 'sitemap', 'XML sitemap', 'fail', 'No XML sitemap found at the usual locations or in robots.txt. Enable the sitemap in the SEO plugin and submit it in Search Console.', 2 );
		} elseif ( in_array( $found, $declared, true ) ) {
			$this->add( 'technical', 'sitemap', 'XML sitemap', 'pass', 'XML sitemap found and referenced in robots.txt.', 2, $found );
		} else {
			$this->add( 'technical', 'sitemap', 'XML sitemap', 'warn', 'XML sitemap found but not referenced in robots.txt. Add a "Sitemap:" line so every crawler (not just Google) can find it.', 2, $found );
		}
	}

	/**
	 * Title and meta description.
	 */
	private function check_meta() {
		$titles = $this->xpath->query( '//title' );
		$title  = $titles->length ? $this->clean( $titles->item( 0 )->textContent ) : '';
		$length = mb_strlen( $title );

		if ( '' === $title ) {
			$status  = 'fail';
			$message = 'No title tag. This is the headline shown in search results.';
		} elseif ( $length >= 30 && $length <= 60 ) {
			$status  = 'pass';
			$message = sprintf( '%d characters, within the 30–60 range.', $length );
		} elseif ( $length < 30 ) {
			$status  = 'warn';
			$message = sprintf( 'Short at %d characters. There\'s room for the main keyword and brand.', $length );
		} else {
			$status  = 'warn';
			$message = sprintf( 'Long at %d characters, so it\'s likely to be cut off in results after about 60.', $length );
		}

		if ( $titles->length > 1 ) {
			$status   = 'fail' === $status ? 'fail' : 'warn';
			$message .= sprintf( ' %d <title> tags found; there should be one (often a theme and SEO plugin both outputting one).', $titles->length );
		}

		$this->add( 'meta', 'title', 'Title tag', $status, $message, 4, $title );
		$this->facts['title'] = $title;

		$description = $this->clean( (string) $this->meta( 'description' ) );
		$length      = mb_strlen( $description );

		if ( '' === $description ) {
			$this->add( 'meta', 'description', 'Meta description', 'fail', 'No meta description, so search engines will pull a snippet from the page, often badly.', 3 );
		} elseif ( $length >= 70 && $length <= 160 ) {
			$this->add( 'meta', 'description', 'Meta description', 'pass', sprintf( '%d characters, within the 70–160 range.', $length ), 3, $description );
		} else {
			$this->add( 'meta', 'description', 'Meta description', 'warn', sprintf( '%d characters. Aim for 70–160 so it\'s descriptive without being truncated.', $length ), 3, $description );
		}

		$this->facts['meta_description'] = $description;
	}

	/**
	 * Headings, word count, images, links.
	 */
	private function check_content() {
		$h1s   = $this->xpath->query( '//h1' );
		$texts = array();

		foreach ( $h1s as $h1 ) {
			$texts[] = $this->clean( $h1->textContent );
		}

		if ( 0 === $h1s->length ) {
			$this->add( 'content', 'h1', 'H1 heading', 'fail', 'No H1. Every page needs one clear main heading describing what it\'s about.', 3 );
		} elseif ( 1 === $h1s->length ) {
			$this->add( 'content', 'h1', 'H1 heading', 'pass', 'One H1.', 3, $texts[0] );
		} else {
			$this->add( 'content', 'h1', 'H1 heading', 'warn', sprintf( '%d H1s found. Use one H1 for the main topic and H2s beneath it. Logos and sliders are common culprits.', $h1s->length ), 3, implode( ' | ', $texts ) );
		}
		$this->facts['h1'] = $texts;

		foreach ( $this->xpath->query( '//h1|//h2|//h3|//h4|//h5|//h6' ) as $heading ) {
			$this->headings[] = array( (int) substr( $heading->nodeName, 1 ), $this->clean( $heading->textContent ) );
		}

		$skips = array();
		$prev  = 0;
		foreach ( $this->headings as $heading ) {
			if ( $prev && $heading[0] > $prev + 1 ) {
				$skips[] = sprintf( 'H%d → H%d', $prev, $heading[0] );
			}
			$prev = $heading[0];
		}
		$skips = array_unique( $skips );
		$this->add(
			'content',
			'heading_order',
			'Heading structure',
			empty( $skips ) ? 'pass' : 'warn',
			empty( $skips ) ? sprintf( '%d headings, no skipped levels.', count( $this->headings ) ) : 'Heading levels are skipped (' . implode( ', ', $skips ) . '). Usually headings styled by size rather than meaning.',
			1
		);

		$words = $this->count_words( $this->visible_text() );
		$this->facts['word_count'] = $words;
		$status = $words >= 300 ? 'pass' : ( $words >= 100 ? 'warn' : 'fail' );
		$this->add(
			'content',
			'word_count',
			'Content depth',
			$status,
			sprintf( '%d words of visible text in the HTML.%s', $words, 'pass' === $status ? '' : ' Thin pages struggle to rank or be quoted; add substance that answers what visitors need.' ),
			2
		);

		$images  = $this->xpath->query( '//body//img' );
		$missing = 0;
		foreach ( $images as $img ) {
			if ( ! $img->hasAttribute( 'alt' ) ) {
				++$missing;
			}
		}

		if ( 0 === $images->length ) {
			$this->add( 'content', 'alt', 'Image alt text', 'info', 'No <img> elements in the HTML (background images and lazy-load placeholders aren\'t counted).', 0 );
		} else {
			$ratio  = $missing / $images->length;
			$status = 0 === $missing ? 'pass' : ( $ratio <= 0.2 ? 'warn' : 'fail' );
			$this->add(
				'content',
				'alt',
				'Image alt text',
				$status,
				0 === $missing ? sprintf( 'All %d images have an alt attribute.', $images->length ) : sprintf( '%d of %d images have no alt attribute. Use alt="" for decorative images and a short description for everything else.', $missing, $images->length ),
				2
			);
		}

		$host     = $this->bare_host( $this->page['final_url'] );
		$internal = 0;
		$external = 0;
		$empty    = 0;

		foreach ( $this->xpath->query( '//body//a[@href]' ) as $link ) {
			$href = trim( $link->getAttribute( 'href' ) );

			if ( '' === $href || '#' === $href[0] || preg_match( '#^(mailto|tel|sms|javascript):#i', $href ) ) {
				continue;
			}

			$link_host = wp_parse_url( $href, PHP_URL_HOST );

			if ( ! $link_host || $this->bare_host( 'https://' . $link_host ) === $host ) {
				++$internal;
			} else {
				++$external;
			}

			$has_text = '' !== $this->clean( $link->textContent )
				|| '' !== trim( $link->getAttribute( 'aria-label' ) )
				|| '' !== trim( $link->getAttribute( 'title' ) )
				|| $this->xpath->query( './/img[normalize-space(@alt)!=""]', $link )->length > 0;

			if ( ! $has_text ) {
				++$empty;
			}
		}

		$this->facts['internal_links'] = $internal;
		$this->facts['external_links'] = $external;

		$this->add(
			'content',
			'internal_links',
			'Internal links',
			$internal >= 3 ? 'pass' : 'warn',
			$internal >= 3 ? sprintf( '%d internal and %d external links.', $internal, $external ) : sprintf( 'Only %d internal links. Crawlers discover and weigh pages through internal links, so link to related pages.', $internal ),
			1
		);

		$this->add(
			'content',
			'link_text',
			'Link text',
			0 === $empty ? 'pass' : 'warn',
			0 === $empty ? 'Every link has readable text or a label.' : sprintf( '%d links have no readable text (usually icon-only links without an aria-label). Bad for accessibility and gives crawlers no context.', $empty ),
			1
		);
	}

	/**
	 * Open Graph and Twitter cards.
	 */
	private function check_social() {
		$required = array( 'og:title', 'og:description', 'og:image' );
		$missing  = array();

		foreach ( $required as $tag ) {
			if ( ! $this->meta( $tag ) ) {
				$missing[] = $tag;
			}
		}

		if ( empty( $missing ) ) {
			$this->add( 'social', 'open_graph', 'Open Graph', 'pass', 'Open Graph title, description and image are all set.', 2, (string) $this->meta( 'og:image' ) );
		} elseif ( count( $missing ) < count( $required ) ) {
			$this->add( 'social', 'open_graph', 'Open Graph', 'warn', 'Missing ' . implode( ', ', $missing ) . '. Shared links will look incomplete on LinkedIn, Facebook and messaging apps.', 2 );
		} else {
			$this->add( 'social', 'open_graph', 'Open Graph', 'fail', 'No Open Graph tags. Shared links will show whatever the platform guesses.', 2 );
		}

		$card = $this->meta( 'twitter:card' );
		$this->add(
			'social',
			'twitter_card',
			'X (Twitter) card',
			$card ? 'pass' : 'warn',
			$card ? sprintf( 'twitter:card set to %s.', $card ) : 'No twitter:card tag. X falls back to Open Graph, but summary_large_image gives a bigger preview.',
			1
		);
	}

	/**
	 * JSON-LD and microdata.
	 */
	private function check_schema() {
		foreach ( $this->xpath->query( '//script[translate(@type,"JSON-LDAPPLICTIN","json-ldapplictin")="application/ld+json"]' ) as $script ) {
			$data = json_decode( trim( $script->textContent ), true );

			if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
				++$this->schema['invalid'];
				continue;
			}

			$this->walk_schema( $data );
		}

		foreach ( $this->xpath->query( '//*[@itemtype]' ) as $node ) {
			foreach ( preg_split( '/\s+/', trim( $node->getAttribute( 'itemtype' ) ) ) as $type ) {
				$this->schema['types'][] = basename( $type );
			}
		}

		$types = array_values( array_unique( array_filter( $this->schema['types'] ) ) );
		$this->facts['schema_types'] = $types;

		if ( empty( $types ) ) {
			$this->add( 'schema', 'structured_data', 'Structured data', 'fail', 'No structured data found. Add JSON-LD: Organization or LocalBusiness site-wide, plus page types such as Service, Product, Article or FAQPage.', 2 );
		} elseif ( $this->schema['invalid'] ) {
			$this->add( 'schema', 'structured_data', 'Structured data', 'warn', sprintf( '%d JSON-LD block(s) contain invalid JSON and are ignored entirely. Check them in the Schema Markup Validator.', $this->schema['invalid'] ), 2, implode( ', ', $types ) );
		} else {
			$this->add( 'schema', 'structured_data', 'Structured data', 'pass', 'Valid structured data found.', 2, implode( ', ', $types ) );
		}
	}

	/**
	 * AI visibility: crawler access, firewall behaviour, raw-HTML content, entity markup, llms.txt, Q&A, freshness.
	 */
	private function check_ai() {
		$path = $this->request_path();

		if ( $this->robots ) {
			$blocked = array();
			foreach ( self::SEARCH_BOTS as $bot ) {
				if ( ! $this->robots->is_allowed( $bot, $path ) ) {
					$blocked[] = $bot;
				}
			}

			$this->add(
				'ai',
				'ai_search_access',
				'AI search crawlers',
				empty( $blocked ) ? 'pass' : 'fail',
				empty( $blocked ) ? 'robots.txt lets the search and AI answer crawlers reach this URL (Googlebot, Bingbot, OAI-SearchBot, ChatGPT-User, PerplexityBot, Claude-SearchBot and others).' : 'robots.txt blocks ' . implode( ', ', $blocked ) . '. These crawlers fetch pages to show or cite in search and live AI answers, so blocking them removes the site from those answers.',
				4
			);

			$training = array();
			foreach ( self::TRAINING_BOTS as $bot ) {
				if ( ! $this->robots->is_allowed( $bot, $path ) ) {
					$training[] = $bot;
				}
			}

			$this->add(
				'ai',
				'ai_training_access',
				'AI training crawlers',
				'info',
				empty( $training ) ? 'No training crawlers blocked. This is a policy choice either way; blocking them doesn\'t affect live AI search citations.' : 'Blocked: ' . implode( ', ', $training ) . '. This is a policy choice; it stops content being used for model training but doesn\'t affect live AI search citations.',
				0
			);
		} else {
			$this->add( 'ai', 'ai_search_access', 'AI search crawlers', 'pass', 'No robots.txt, so no crawler is blocked at rule level.', 4 );
		}

		$bot = BSGC_Fetcher::get(
			$this->page['final_url'],
			array(
				'user-agent'          => BSGC_Fetcher::UA_AI_BOT,
				'limit_response_size' => 256 * KB_IN_BYTES,
				'redirection'         => 3,
			)
		);

		if ( is_wp_error( $bot ) ) {
			$this->add( 'ai', 'ai_firewall', 'Firewall check', 'warn', 'A request identifying as OAI-SearchBot failed: ' . $bot->get_error_message(), 3 );
		} elseif ( in_array( $bot['code'], array( 401, 403, 406, 429, 503 ), true ) || false !== stripos( substr( $bot['body'], 0, 20000 ), 'cf-chl' ) ) {
			$this->add( 'ai', 'ai_firewall', 'Firewall check', 'warn', sprintf( 'A request identifying as OAI-SearchBot got HTTP %d (or a challenge page) while a browser request got through. Usually a CDN or firewall rule such as Cloudflare\'s AI bot blocking. Real crawlers are verified by IP, so confirm in the firewall settings before acting.', $bot['code'] ), 3 );
		} else {
			$this->add( 'ai', 'ai_firewall', 'Firewall check', 'pass', sprintf( 'A request identifying as OAI-SearchBot was served normally (HTTP %d).', $bot['code'] ), 3 );
		}

		$words   = $this->facts['word_count'];
		$scripts = $this->xpath->query( '//script[@src]' )->length;
		$shell   = $this->xpath->query( '//*[@id="root" or @id="app" or @id="__next" or @id="__nuxt"]' )->length > 0;

		if ( $words >= 150 ) {
			$this->add( 'ai', 'raw_html', 'Readable without JavaScript', 'pass', sprintf( '%d words are in the raw HTML, so crawlers that don\'t run JavaScript (most AI crawlers) can read the content.', $words ), 4 );
		} elseif ( $shell || $scripts > 15 ) {
			$this->add( 'ai', 'raw_html', 'Readable without JavaScript', 'fail', sprintf( 'Only %d words in the raw HTML and the page looks JavaScript-rendered. Most AI crawlers don\'t run JavaScript, so they see an almost empty page.', $words ), 4 );
		} else {
			$this->add( 'ai', 'raw_html', 'Readable without JavaScript', 'warn', sprintf( 'Only %d words in the raw HTML, which gives AI systems very little to understand or quote.', $words ), 4 );
		}

		$entities = array_values(
			array_filter(
				array_unique( $this->schema['types'] ),
				function ( $type ) {
					return (bool) preg_match( '/(Organization|Business|Person|Service|Store|Restaurant|Hotel|Agency|Office|Shop|Club|Practice|Contractor|Dentist|Physician)$/', $type );
				}
			)
		);

		if ( empty( $entities ) ) {
			$this->add( 'ai', 'entity', 'Entity markup', 'fail', 'No Organization, LocalBusiness or Person schema. This is the clearest way to tell search engines and AI systems exactly who is behind the site.', 3 );
		} elseif ( 0 === $this->schema['same_as'] ) {
			$this->add( 'ai', 'entity', 'Entity markup', 'warn', 'Entity schema found but no sameAs links. Add the social profiles, Google Business Profile and key directory listings so AI systems can confirm the business is the same one mentioned elsewhere.', 3, implode( ', ', $entities ) );
		} else {
			$this->add( 'ai', 'entity', 'Entity markup', 'pass', sprintf( 'Entity schema with %d sameAs links, which ties the site to one clearly defined business.', $this->schema['same_as'] ), 3, implode( ', ', $entities ) );
		}

		$questions = 0;
		foreach ( $this->headings as $heading ) {
			if ( '?' === mb_substr( $heading[1], -1 ) ) {
				++$questions;
			}
		}
		$has_faq = in_array( 'FAQPage', $this->schema['types'], true );

		if ( $has_faq || $questions >= 2 ) {
			$this->add( 'ai', 'qa_format', 'Question-led content', 'pass', $has_faq ? 'FAQPage markup present.' : sprintf( '%d headings are phrased as questions.', $questions ), 1 );
		} else {
			$this->add( 'ai', 'qa_format', 'Question-led content', 'warn', 'No question-style headings or FAQ section. AI answers tend to lift content that directly answers a stated question, so consider H2s phrased as the questions customers actually ask.', 1 );
		}

		$has_date = $this->schema['has_date']
			|| $this->meta( 'article:modified_time' )
			|| $this->meta( 'article:published_time' )
			|| $this->xpath->query( '//time[@datetime]' )->length > 0;

		$this->add(
			'ai',
			'freshness',
			'Freshness signal',
			$has_date ? 'pass' : 'warn',
			$has_date ? 'A machine-readable publish or update date is exposed.' : 'No machine-readable publish or update date. Expose dateModified in schema so systems can see the content is current.',
			1
		);

		$llms = BSGC_Fetcher::get( $this->origin . '/llms.txt', array( 'limit_response_size' => 256 * KB_IN_BYTES ) );
		$this->add(
			'ai',
			'llms_txt',
			'llms.txt',
			'info',
			BSGC_Fetcher::is_text_file( $llms ) ? 'llms.txt found.' : 'No llms.txt. It\'s an emerging convention and no major AI platform has confirmed using it, so it isn\'t scored. Low effort if you want to add one.',
			0
		);
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Record a check.
	 *
	 * @param string $category Category key.
	 * @param string $id       Check ID.
	 * @param string $label    Human label.
	 * @param string $status   pass|warn|fail|info.
	 * @param string $message  Finding.
	 * @param int    $weight   Scoring weight.
	 * @param string $value    Optional raw value.
	 */
	private function add( $category, $id, $label, $status, $message, $weight, $value = '' ) {
		$this->checks[] = array(
			'category' => $category,
			'id'       => $id,
			'label'    => $label,
			'status'   => $status,
			'message'  => $message,
			'weight'   => $weight,
			'value'    => (string) $value,
		);
	}

	/**
	 * Parse HTML into a DOMDocument, quietly.
	 *
	 * @param string $html HTML.
	 * @return DOMDocument
	 */
	private function load_dom( $html ) {
		$dom      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return $dom;
	}

	/**
	 * Content of a meta tag by name or property (case-insensitive).
	 *
	 * @param string $key Name/property.
	 * @return string|null
	 */
	private function meta( $key ) {
		$lower = 'translate(%s,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")';
		$expr  = sprintf( '//meta[%1$s="%3$s" or %2$s="%3$s"]', sprintf( $lower, '@name' ), sprintf( $lower, '@property' ), strtolower( $key ) );
		$node  = $this->xpath->query( $expr )->item( 0 );

		if ( ! $node ) {
			return null;
		}

		$content = trim( $node->getAttribute( 'content' ) );

		return '' === $content ? null : $content;
	}

	/**
	 * Visible body text, excluding scripts, styles and similar.
	 *
	 * @return string
	 */
	private function visible_text() {
		$dom   = $this->load_dom( $this->page['body'] );
		$xpath = new DOMXPath( $dom );

		foreach ( $xpath->query( '//script|//style|//noscript|//template|//svg|//iframe|//head' ) as $node ) {
			if ( $node->parentNode ) {
				$node->parentNode->removeChild( $node );
			}
		}

		$body = $xpath->query( '//body' )->item( 0 );

		return $this->clean( $body ? $body->textContent : $dom->textContent );
	}

	/**
	 * Unicode-aware word count.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	private function count_words( $text ) {
		return (int) preg_match_all( '/[\p{L}\p{N}]+(?:[\'’\-][\p{L}\p{N}]+)*/u', $text );
	}

	/**
	 * Collapse whitespace.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private function clean( $text ) {
		return trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
	}

	/**
	 * Recursively collect schema types, sameAs links and dates.
	 *
	 * @param mixed $data Decoded JSON-LD.
	 */
	private function walk_schema( $data ) {
		if ( ! is_array( $data ) ) {
			return;
		}

		if ( isset( $data['@type'] ) ) {
			foreach ( (array) $data['@type'] as $type ) {
				if ( is_string( $type ) ) {
					$this->schema['types'][] = basename( $type );
				}
			}
		}

		if ( ! empty( $data['sameAs'] ) ) {
			$this->schema['same_as'] += count( (array) $data['sameAs'] );
		}

		if ( ! empty( $data['dateModified'] ) || ! empty( $data['datePublished'] ) ) {
			$this->schema['has_date'] = true;
		}

		foreach ( $data as $value ) {
			if ( is_array( $value ) ) {
				$this->walk_schema( $value );
			}
		}
	}

	/**
	 * Path and query of the final URL, for robots.txt matching.
	 *
	 * @return string
	 */
	private function request_path() {
		$path  = wp_parse_url( $this->page['final_url'], PHP_URL_PATH );
		$query = wp_parse_url( $this->page['final_url'], PHP_URL_QUERY );

		return ( $path ? $path : '/' ) . ( $query ? '?' . $query : '' );
	}

	/**
	 * Resolve a possibly-relative href against the final URL.
	 *
	 * @param string $href Href.
	 * @return string
	 */
	private function absolute( $href ) {
		$href = trim( $href );

		if ( preg_match( '#^https?://#i', $href ) ) {
			return $href;
		}

		if ( 0 === strpos( $href, '//' ) ) {
			return wp_parse_url( $this->page['final_url'], PHP_URL_SCHEME ) . ':' . $href;
		}

		if ( 0 === strpos( $href, '/' ) ) {
			return $this->origin . $href;
		}

		$path = wp_parse_url( $this->page['final_url'], PHP_URL_PATH );
		$path = $path ? $path : '/';

		return $this->origin . substr( $path, 0, strrpos( $path, '/' ) + 1 ) . $href;
	}

	/**
	 * Normalised form of a URL for canonical comparison.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function compare_key( $url ) {
		$parts = wp_parse_url( $url );

		if ( empty( $parts['host'] ) ) {
			return '';
		}

		return strtolower( ( isset( $parts['scheme'] ) ? $parts['scheme'] : 'https' ) . '://' . $parts['host'] )
			. ( isset( $parts['path'] ) ? rtrim( $parts['path'], '/' ) : '' )
			. ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
	}

	/**
	 * Host without www, lowercase.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function bare_host( $url ) {
		return preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) );
	}
}
