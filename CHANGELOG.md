# Changelog

All notable changes to Bonsai SEO/GEO Checker are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- `CLAUDE.md`, `CHANGELOG.md`, `MEMORY.md` and `llm-instructions.txt` project documentation.

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
