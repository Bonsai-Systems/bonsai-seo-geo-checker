# MEMORY.md — Bonsai SEO/GEO Checker

Decisions, gotchas and open items that aren't obvious from the code. Keep entries short and dated.

## Key decisions

- **2026-09-29 — Internal tool first, client installs allowed.** It started as a single internal install. The editor box/admin bar (added the same day) mean it may now also go on client sites. Access is still `manage_options`.
- **2026-09-29 — Editor button opens the Tools screen rather than running inline.** This reuses the full report UI, history, PDF and summary without duplicating rendering, and avoids tying up the editor for 5–60s.
- **2026-09-29 — Only published posts are checkable.** The fetch is unauthenticated, like a visitor or crawler, so drafts and private posts would 404.
- **2026-09-29 — Three-step request flow.** Main checks run first, then PageSpeed and Claude run in parallel from JS. This keeps each request under host proxy timeouts and shows results sooner.
- **2026-09-29 — Separate meta keys per part.** `_bsgc_report`, `_bsgc_performance`, `_bsgc_ai`, `_bsgc_score`. The parallel requests would otherwise overwrite each other.
- **2026-09-29 — Scores recalculated on read.** `BSGC_Reports::get()` merges performance checks and re-scores; `_bsgc_score` is just a cache for the history table.
- **2026-09-29 — Keys: constants first.** `wp-config.php` constants override the saved settings so keys can stay out of the database.
- **2026-09-29 — Settings moved from ACF to the native Settings API.** This removes the ACF Pro dependency, so the page works on any client site. It also stops the key being printed into the page: ACF's password field puts the saved value in the `value` attribute. Old ACF values are migrated once and then deleted. It's an intentional exception to the "ACF everywhere" default.
- **2026-09-29 — Updates via plugin-update-checker, same as Code Injector.** The Composer `autoloader-suffix` is fixed because Code Injector and ActiveCampaign fatalled on a shared `ComposerAutoloaderInit<hash>` class. Unlike Code Injector, the zip is built by a GitHub Action rather than by hand, and `REQUIRE_RELEASE_ASSETS` stops sites installing the source archive. Sites on 1.0.0 have no updater, so they need a one-off manual install of 1.1.0.
- **2026-09-29 — Training bots unscored.** Blocking GPTBot/ClaudeBot etc. is a client policy decision, not an SEO fault.
- **2026-10-02 — Checks carry a separate `fix`.** The finding and the fix used to share `message`. Splitting them gives a cleaner table and better Claude input. The fix list prompt now leads with the 1–3 changes that matter most (adapted from OpenSEO's `seo-audit` skill).
- **2026-10-02 — Broken link test is a capped sample with `wp_safe_remote_head`.** 10 links and a 20s budget keep the main request under host timeouts. Query-string links are skipped so the test can't trigger add-to-cart or logout. It runs sequentially because parallel `Requests::request_multiple()` would bypass the SSRF guard.
- **2026-10-02 — hreflang and LocalBusiness checks only appear when relevant.** Most Bonsai sites are single-language and many aren't local, so an unscored "not present" row would just be noise.
- **2026-10-02 — PDF is the browser's print-to-PDF, not a server-side PDF library.** A cover page and a condensed passes list are rendered into the report as `.bsgc-print-only` blocks. Print CSS hides pass/info rows and categories with nothing to fix. No dependency, and the PDF always matches the on-screen report.
- **2026-10-02 — Report branding is a setting, not hardcoded.** Bonsai white-labels for Realityhouse, SPS, Web Heads and others, so each install can use its own logo and "Prepared by" name, or none.
- **2026-09-29 — Default model Haiku 4.5.** It's cheap enough for fix lists; override via `BSGC_CLAUDE_MODEL`.

## Gotchas

- `wp_safe_remote_get()` rejects private IPs, so local `.test` sites can't be audited. This is intentional (SSRF guard).
- Hosts with a hard 30s proxy timeout can cut off PageSpeed even with `set_time_limit( 150 )`.
- Sites behind aggressive bot protection return 403 to server-side requests. The tool reports the block instead of auditing.
- The block editor doesn't re-render meta boxes after saving. A newly published post shows "publish first" until the screen is reloaded.
- Page numbers on the PDF use CSS `@page` margin boxes, which only Chrome/Edge 131+ support. Other browsers just leave them off. Browsers also add their own header/footer (URL, date) unless "Headers and footers" is unticked in the print dialog.
- Schema is read from static HTML only, so JS-injected JSON-LD won't be detected. Point users to Rich Results Test.

## Open items / tech debt

- [x] Git repo: https://github.com/Bonsai-Systems/bonsai-seo-geo-checker (moved from gakdesign 2026-09-29).
- [ ] No PHPUnit tests. Priorities: `BSGC_Robots::is_allowed()`, `BSGC_Analyser::score()`, `BSGC_AI::sanitise()`, PSI/Claude error paths.
- [ ] AJAX handlers aren't wrapped in `try/catch` (Bonsai error-handling rule). An unexpected exception in the analyser would return a raw 500 instead of a JSON error.
- [ ] No `uninstall.php`. `bsgc_report` posts/meta and the `bsgc_settings` option (including any saved keys) stay behind after the plugin is deleted.
- [ ] No reports retention/cleanup. History grows indefinitely (only 25 are shown).
- [ ] Single URL only; crawling is planned as "tier 3".
- [ ] **Planned update: measured AI visibility via DataForSEO (idea from [OpenSEO](https://github.com/every-app/open-seo), MIT).** Today's GEO checks measure *readiness*. DataForSEO's LLM Mentions API (`/v3/ai_optimization/...`) gives *outcomes*: brand/domain mentions in ChatGPT and Google AI Overviews, cited pages, triggering prompts, and share of voice against up to 5 competitors. Sketch: optional `BSGC_DATAFORSEO_LOGIN`/`_PASSWORD` constants + settings, a fourth parallel AJAX call (`bsgc_run_ai_visibility`) saving to its own meta key (`_bsgc_ai_visibility`), shown as an unscored panel. Paid per call, so keep it nonced and behind an explicit button, and cache results per domain. Their `src/types/schemas/ai-search.ts` and `src/server/lib/dataforseo/llm-models.ts` are useful references.
