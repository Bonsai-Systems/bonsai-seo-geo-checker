# Changelog

All notable changes to Bonsai SEO/GEO Checker are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- "SEO/GEO check" sidebar box on edit screens for all public post types. It opens the checker with the post's URL already running and shows the last score with a link to that report.
- "Check this page" admin bar link on the front end for administrators.
- `bsgc_post_types` filter to control which post types get the box.
- Reports store the post they were run for (`_bsgc_post_id`), matched by `url_to_postid()` when the URL is typed in.
- The checker screen now reads `?bsgc_run=` (nonced, runs on load) and `?bsgc_report=` (opens a saved report).
- `CLAUDE.md`, `CHANGELOG.md`, `MEMORY.md` and `llm-instructions.txt` project documentation.

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
