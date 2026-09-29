# Bonsai SEO/GEO Checker

This is an internal tool for auditing a single URL. It lives under **Tools → SEO/GEO checker**, and only administrators (`manage_options`) can use it.

Built by [The Bonsai Digital Collective](https://bonsaidigitalcollective.co.uk/). Source: [Bonsai-Systems/bonsai-seo-geo-checker](https://github.com/Bonsai-Systems/bonsai-seo-geo-checker).

## What it checks

**Technical:** HTTPS, HTTP status, redirect chains, server response time, HTML size, noindex (meta and X-Robots-Tag), canonical, `lang`, viewport, robots.txt, whether Googlebot is blocked, and the XML sitemap (including whether robots.txt references it).

**Titles and descriptions:** title length and duplicate `<title>` tags, and meta description length.

**Content:** H1 count, skipped heading levels, visible word count, image alt attributes, internal/external links and links with no text.

**Social sharing:** Open Graph title, description and image, plus the Twitter card.

**Structured data:** JSON-LD and microdata types, and invalid JSON-LD blocks.

**AI visibility (GEO):**
- robots.txt access for the search and answer crawlers: Googlebot, Bingbot, OAI-SearchBot, ChatGPT-User, PerplexityBot, Perplexity-User, Claude-SearchBot and Claude-User.
- Training crawlers (GPTBot, ClaudeBot, Google-Extended, CCBot and others) are reported but not scored, because blocking them is a policy choice.
- A firewall check that fetches the page as OAI-SearchBot, to catch Cloudflare-style AI blocking.
- Whether the content is readable without JavaScript.
- Organization/LocalBusiness/Person schema with `sameAs`.
- Question-led headings or FAQPage.
- A machine-readable publish or update date.
- llms.txt, reported but unscored.

**Performance:** the PageSpeed Insights mobile score, LCP, CLS and TBT, plus INP from real-user data where it exists.

**Fix list:** Claude turns every warning and failure into up to eight prioritised fixes. Each one has an effort level and an owner (client or developer).

**Scoring:** a pass earns the check's full weight, a warning earns half and a fail earns nothing. Info checks aren't scored.

## Install

1. Zip the `bonsai-seo-geo-checker` folder, or copy it into `wp-content/plugins/`, then activate it.
2. Add the API keys, ideally in `wp-config.php` so they never sit in the database:

```php
define( 'BSGC_ANTHROPIC_KEY', 'sk-ant-...' );
define( 'BSGC_PSI_KEY', 'AIza...' );                        // Free, from Google Cloud Console.
define( 'BSGC_CLAUDE_MODEL', 'claude-haiku-4-5-20251001' ); // Optional.
```

If you don't use constants, enter the keys at **Settings → SEO/GEO checker** (there's also a Settings link on the Plugins screen). You don't need ACF for this. Saved keys are never shown again: the field shows the last four characters, you leave it blank to keep the key, and you tick **Remove saved key** to delete it. When a constant is defined, the matching field is locked.

To audit any site from one place, install it on an internal site such as the Bonsai site or a tools install. To use the editor button on a client's own pages, install it on that client site too. Keep the keys in `wp-config.php` there, and remember the API usage is billed to whoever owns the keys.

## Running costs

- PageSpeed Insights is free. Without a key, Google rate-limits it heavily.
- A Haiku fix list costs a fraction of a penny per report.

## Using it

- **Copy client summary** puts plain text on the clipboard: the score, the AI summary and the fixes in priority order, signed off with the current user's first name.
- **Print or save as PDF** uses print styles that strip out the wp-admin chrome.
- Reports are saved in a hidden `bsgc_report` post type. The last 25 are listed under the form, where you can view or delete them.

## Checking from the editor

- **Edit screens:** every public post type (posts, pages, custom post types and WooCommerce products, but not media) gets an **SEO/GEO check** box in the sidebar. **Check this page** opens the checker in a new tab and runs it on the published URL. The box also shows the last score, with a link to that report.
- **Admin bar:** when you're logged in as an administrator, every front-end page gets **Check this page** in the toolbar, including archives and search results.
- Only published content can be checked, because the checker fetches the live page the way a visitor would. Drafts, scheduled posts and private posts show a note instead of the button.
- The links carry a nonce, so a link you were sent by someone else only fills in the URL without running the check. The same happens if an edit screen has been open for more than a day.
- A report is linked to its post automatically, including when the URL is typed into the checker. To limit which post types get the box:

```php
add_filter( 'bsgc_post_types', function ( $types ) {
	return array( 'post', 'page' );
} );
```

## Known limits

- The main checks run in PHP, so a request can take 5–15 seconds. PageSpeed and Claude each take up to a minute. The plugin raises `set_time_limit`, but hosts with a hard 30-second proxy timeout may cut PageSpeed off. If that happens, run the tool on a host with longer limits.
- Sites behind aggressive bot protection may return 403 to server-side requests. In that case the tool reports the block instead of auditing.
- `wp_safe_remote_get()` refuses private/internal IPs, which stops it being used to probe the server's own network. The same rule means it can't check local `.test` sites.
- It checks one URL at a time and doesn't crawl. Crawling is the tier 3 version.
