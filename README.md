# Bonsai SEO/GEO Checker

This is an internal tool for auditing a single URL. It lives under **Tools → SEO/GEO checker**, and only administrators (`manage_options`) can use it.

Built by [The Bonsai Digital Collective](https://bonsaidigitalcollective.co.uk/). Source: [Bonsai-Systems/bonsai-seo-geo-checker](https://github.com/Bonsai-Systems/bonsai-seo-geo-checker).

## What it checks

**Technical:** HTTPS, HTTP status, redirect chains, server response time, HTML size, noindex (meta and X-Robots-Tag), canonical, `lang`, viewport, robots.txt, whether Googlebot is blocked, and the XML sitemap (including whether robots.txt references it).
 ther 
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

1. Download `bonsai-seo-geo-checker.zip` from the [latest release](https://github.com/Bonsai-Systems/bonsai-seo-geo-checker/releases/latest), upload it under **Plugins → Add New → Upload Plugin**, then activate it. Don't use GitHub's "Source code" zip: it unpacks to a versioned folder name and includes dev files.
2. Add your API keys using one of the two options below.

### Settings

| Setting | Needed for | Required? | Where to get it |
|---|---|---|---|
| Anthropic API key | The Claude-written fix list | Yes, for the fix list. The checks run without it | [console.anthropic.com](https://console.anthropic.com/) → API keys |
| PageSpeed Insights API key | Mobile performance checks | No, but without it Google rate-limits requests heavily | Google Cloud Console → enable the PageSpeed Insights API → Credentials. It's free |
| Claude model | Choosing the model | No. Defaults to `claude-haiku-4-5-20251001` | – |

### Option 1: Settings screen (quickest)

Go to **Settings → SEO/GEO checker**. There's also a **Settings** link on the plugin's row on the Plugins screen. Paste in the keys and save. You don't need ACF.

The keys are treated like passwords:

- Once saved, a key is never shown again, not even in the page source. The field only shows its last four characters so you can tell which key is in use.
- Leave a key field blank when saving to keep the current key.
- Tick **Remove saved key** to delete it.

Keys entered here are stored in the database (the `bsgc_settings` option).

### Option 2: `wp-config.php` (recommended for client sites)

Add these above the `/* That's all, stop editing! */` line so the keys never touch the database:

```php
define( 'BSGC_ANTHROPIC_KEY', 'sk-ant-...' );
define( 'BSGC_PSI_KEY', 'AIza...' );                        // Free, from Google Cloud Console.
define( 'BSGC_CLAUDE_MODEL', 'claude-haiku-4-5-20251001' ); // Optional.
```

Anything defined here overrides the Settings screen. The matching field is locked and shows **Set in wp-config.php**. You can mix the two, e.g. keys in `wp-config.php` and the model on the Settings screen.

### Where to install it

- **Auditing any site from one place:** install it on an internal site, such as the Bonsai site or a tools install.
- **Using the editor button on a client's own pages:** install it on that client site too. Use `wp-config.php` for the keys there, and remember the API usage is billed to whoever owns the keys.

## Updates

The plugin updates itself from GitHub releases. It uses [YahnisElsts/plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker), installed with Composer (`vendor/` is committed). New versions appear under **Plugins** in wp-admin like any other plugin update. Sites check every 6 hours, or straight away when you click **Check for updates** on the plugin's row.

To ship a new version:

1. Bump `Version:` in `bonsai-seo-geo-checker.php` **and** `BSGC_VERSION`, and add a `CHANGELOG.md` entry.
2. Commit and push to `main`.
3. Publish a GitHub release tagged with the version, e.g. `v1.1.0` or `1.1.0`.
4. The **Release zip** GitHub Action builds `bonsai-seo-geo-checker.zip` and attaches it to the release. It stops with an error if the tag doesn't match both version numbers.

Sites only take the attached `bonsai-seo-geo-checker.zip` and never GitHub's source archive, so a release with no zip isn't offered.

If the repo is ever made private, add a read-only GitHub token to `wp-config.php` on each site:

```php
define( 'BSGC_GITHUB_TOKEN', 'github_pat_...' );
```

The Composer autoloader has its own fixed suffix (`ComposerAutoloaderInitBonsaiSeoGeoChecker`), so it can't clash with other Bonsai plugins that bundle the same updater. If two copies of this plugin are active at once, the second one shows an admin notice instead of causing a fatal error.

## Running costs

- PageSpeed Insights is free. Without a key, Google rate-limits it heavily.
- A Haiku fix list costs a fraction of a penny per report.

## Using it

- **Copy client summary** puts plain text on the clipboard: the score, the AI summary and the fixes in priority order, signed off with the current user's first name.
- **Print or save as PDF** produces a client-ready A4 report: a cover page (logo, page URL, score, Claude's summary, date and "Prepared by"), category scores and the fix list, then only the warnings and failures with their fixes. Passing checks and notes are condensed into "Already in good shape" at the end. The file name defaults to `SEO report – example.co.uk-page – YYYY-MM-DD`. Untick the browser's "Headers and footers" option for the cleanest result.
- The cover branding is set under **Settings → SEO/GEO checker → PDF reports**: Bonsai, a custom logo from the Media Library (for white-label clients) or no logo, plus an optional "Prepared by" name.
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
