# Changelog

All notable changes to Bonsai SEO/GEO Checker are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.5.1] - 2026-10-08

### Fixed
- Sites on hosts that block old Chrome user agents (SiteGround returned 403 for `Chrome/129.0.0.0`) could not be checked. The browser user agent is now a current Chrome.
- The Indexable check missed the `none` robots directive, which means noindex, nofollow. `none` in meta robots, the googlebot meta or X-Robots-Tag now fails the check. Values such as `max-image-preview:none` are not affected.

### Added
- If the audited page returns 401, 403 or 429, the checker retries once identifying as Firefox. If that gets through, it uses Firefox for the rest of the check (robots.txt, sitemap and link tests) and adds an info-only **Browser access** note to the report.
- `bsgc_user_agent` filter to change the browser user agent on a site without a release.

## [1.5.0] - 2026-10-08

### Added
- **AI readiness score**: a second 0–100 score showing how easily AI search can reach, read and quote the page. It's made up of every AI visibility check plus the checks listed in `BSGC_Analyser::AI_READINESS_CHECKS` (noindex, Googlebot access, canonical, server response, H1, heading structure, content depth, structured data, LocalBusiness details). It's returned by `BSGC_Analyser::score()` as `ai_readiness` and recalculated on read, so older reports get it too.
- AI readiness shown as a second ring next to the overall score, on the PDF cover and in the copied client summary. Checks from other sections that count towards it are tagged **AI**.
- AI snippet controls check: `nosnippet` or `max-snippet:0` (meta robots, googlebot meta or X-Robots-Tag) fails, because Google applies them to AI Overviews and AI Mode. A `max-snippet` limit under 160 warns. `data-nosnippet` elements are mentioned but not scored.
- Answer-first sections check: warns when fewer than half of the H2/H3 sections open with a short, direct paragraph (10–80 words). Headings in nav, header, footer and sidebars are ignored. Info only when there are fewer than two sections.
- Lists and tables check: warns when the body content has no list (3+ items) or data table. Menus and page furniture are excluded.
- Trust signals check: links to an About and a Contact page, plus a named author (schema `author`, `meta name="author"` or `rel="author"`) on Article/BlogPosting/NewsArticle pages.
- `ai_readiness_score` included in the data sent to Claude for the fix list.

### Fixed
- Stray text in README.md and outdated version and menu locations in llm-instructions.txt.

## [1.3.1] - 2026-10-05

### Changed
- Bundled Bonsai Hub updated to 1.0.1 (`lib/bonsai-hub/`): the **Bonsai** admin menu now sits directly below Dashboard instead of above it.

## [1.3.0] - 2026-10-03

### Added
- Bundled Bonsai Hub 1.0.0 (`lib/bonsai-hub/`): a shared top-level **Bonsai** admin menu with a left-hand nav for every Bonsai plugin, plus a **Plugins** screen to install, activate and deactivate the rest of the suite from GitHub releases.
- Broken internal links check: tests up to 10 unique same-site links (HEAD with a GET fallback, via `wp_safe_remote_head`), skipping query-string, admin, login and feed links. Bot-protection responses and timeouts count as "couldn't be verified", not broken.
- Canonical conflict detection between the HTML `<link rel="canonical">` and the HTTP `Link` header. A canonical set only in the header is now recognised.
- hreflang check, shown only when the page has hreflang tags: invalid codes, `-uk` instead of `-gb`, duplicate codes, relative URLs and missing self-reference.
- LocalBusiness details check, shown only when LocalBusiness schema is present: address, telephone and opening hours.
- `BSGC_Fetcher::status()` for lightweight status checks.
- Client-ready PDF report. Printing now produces an A4 cover page with the logo, page URL, score, Claude's summary, date and "Prepared by"; then category scores, the fix list and only the warnings and failures with their fixes; then passing checks and notes condensed into "Already in good shape". Page numbers in Chrome/Edge 131+.
- PDF file name set from the page and date (`SEO report – example.co.uk-page – YYYY-MM-DD`) instead of the admin page title.
- "PDF reports" settings card: branding (Bonsai, custom logo from the Media Library, or none) and a "Prepared by" name, for white-label reports.

### Changed
- The checker (was **Tools → SEO/GEO checker**) and its settings (was **Settings → SEO/GEO checker**) are now one screen, **Bonsai → SEO/GEO checker** (`admin.php?page=bsgc`), with Checker and Settings tabs. Both old URLs redirect, and editor-box / admin-bar run links keep working. No option or field changes.
- `assets/bonsai-admin-ui.css` is no longer the canonical design system; it now lives in the bonsai-hub repo and ships inside `lib/bonsai-hub/`.
- Checks now have a separate `fix` field. `message` holds the finding only, and the report table shows "Fix:" under warnings and failures. Older saved reports still display normally.
- The Claude fix-list prompt leads with the one to three changes that matter most, says how each fix helps (indexing, ranking, clicks or AI citations), leaves out cosmetic items rather than padding, and treats missing schema cautiously. Claude now also receives each issue's suggested fix and weight.

### Fixed
- The "Fix:" line under each check reused the `.bsgc-fix` class from the Claude fix list, so it picked up that list's styling. It now uses `.bsgc-check-fix`.

### Removed
- `inc/class-bsgc-admin-ui.php` and `assets/bonsai-admin-ui.css`. The hub provides the header and design system. `assets/bonsai-avatar.jpg` stays: it's the "Bonsai" PDF report cover logo.

## [1.2.0] - 2026-09-30

### Added
- `assets/bonsai-admin-ui.css`: the Bonsai admin design system (header, cards, form controls, buttons, badges, status list, help area, toggle). This is the canonical copy other Bonsai plugins duplicate.
- `BSGC_Admin_UI` renders the shared header: logo, title, lead, GitHub/changelog/Bonsai links and the version from `BSGC_VERSION`.

### Changed
- Settings screen restyled: logo header with version, fields grouped into "Claude fix list" and "PageSpeed Insights" cards.
- Tools → SEO/GEO checker uses the same header, with a link to Settings.
- Primary buttons hover to a darker pink (`#c21f48`). White on `#ee4367` fails AA contrast.

## [1.1.0] - 2026-09-29

### Added
- Updates from GitHub releases in wp-admin, via YahnisElsts/plugin-update-checker 5.7 (Composer, `vendor/` committed). Sites check every 6 hours and install only the `bonsai-seo-geo-checker.zip` release asset.
- `.github/workflows/release.yml` builds that zip and attaches it when a release is published. It fails if the tag doesn't match the plugin version.
- Fixed Composer `autoloader-suffix` (`BonsaiSeoGeoChecker`), so the autoloader class can't clash with other Bonsai plugins that bundle the same updater.
- Duplicate install guard: a second active copy shows an admin notice instead of causing a fatal error.
- Optional `BSGC_GITHUB_TOKEN` constant, in case the repo is ever made private.
- "SEO/GEO check" sidebar box on edit screens for all public post types. It opens the checker with the post's URL already running and shows the last score with a link to that report.
- "Check this page" admin bar link on the front end for administrators.
- `bsgc_post_types` filter to control which post types get the box.
- Reports store the post they were run for (`_bsgc_post_id`), matched by `url_to_postid()` when the URL is typed in.
- The checker screen now reads `?bsgc_run=` (nonced, runs on load) and `?bsgc_report=` (opens a saved report).
- `CLAUDE.md`, `CHANGELOG.md`, `MEMORY.md` and `llm-instructions.txt` project documentation.
- Settings screen header with the Bonsai avatar, a short description of the plugin and a link to bonsaidigitalcollective.co.uk.
- "Settings" link in the plugin's row on the Plugins screen.
- `Plugin URI`, `Author URI` and `Update URI` plugin headers.

### Changed
- Settings (Settings → SEO/GEO checker) now use the native Settings API instead of an ACF options page, so ACF Pro is no longer needed. Values live in one non-autoloaded `bsgc_settings` option, and existing ACF values are migrated automatically.
- GitHub repository moved to https://github.com/Bonsai-Systems/bonsai-seo-geo-checker.

### Security
- Saved API keys are no longer sent to the browser. The ACF password field printed the stored key into the page source. Key fields now render empty with an "ending xxxx" hint, leaving them blank keeps the saved key, and a "Remove saved key" checkbox clears it. Fields controlled by a `wp-config.php` constant are locked.

### Fixed
- URLs containing %-encoded characters (e.g. non-ASCII permalinks) are no longer mangled. `sanitize_text_field()` stripped them before `esc_url_raw()` ran.

## [1.0.0] - 2026-09-29

### Added
- Tools → SEO/GEO checker admin screen for single-URL audits (`manage_options` only).
- Technical checks: HTTPS, status, redirects, response time, HTML size, noindex (meta and header), canonical, `lang`, viewport, robots.txt, Googlebot access and XML sitemap.
- Title, meta description, heading, word count, image alt and link checks.
- Open Graph / Twitter card and JSON-LD / microdata checks.
- AI visibility (GEO) checks: search/answer crawler access, unscored training crawler report, OAI-SearchBot firewall test, no-JS readability, entity schema with `sameAs`, question-led headings / FAQPage, publish date and llms.txt.
- RFC 9309 robots.txt parser.
- PageSpeed Insights mobile score, LCP, CLS, TBT and field INP.
- Claude-generated prioritised fix list (max 8) with effort and owner.
- Weighted scoring per category and overall.
- Report history stored in a hidden `bsgc_report` CPT (last 25 listed, view/delete).
- Copy client summary and print/PDF styles.
- ACF Pro options page for API keys and model, overridable by `wp-config.php` constants.
