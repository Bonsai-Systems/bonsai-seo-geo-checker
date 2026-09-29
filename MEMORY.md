# MEMORY.md — Bonsai SEO/GEO Checker

Decisions, gotchas and open items that aren't obvious from the code. Keep entries short and dated.

## Key decisions

- **2026-09-29 — Internal tool only.** Install on one Bonsai/tools site, not client sites. Access is `manage_options`.
- **2026-09-29 — Three-step request flow.** Main checks run first, then PageSpeed and Claude run in parallel from JS. This keeps each request under host proxy timeouts and shows results sooner.
- **2026-09-29 — Separate meta keys per part.** `_bsgc_report`, `_bsgc_performance`, `_bsgc_ai`, `_bsgc_score`. The parallel requests would otherwise overwrite each other.
- **2026-09-29 — Scores recalculated on read.** `BSGC_Reports::get()` merges performance checks and re-scores; `_bsgc_score` is just a cache for the history table.
- **2026-09-29 — Keys: constants first.** `wp-config.php` constants override the ACF fields so keys can stay out of the database.
- **2026-09-29 — ACF fields in PHP, no acf-json.** Registered with `acf_add_local_field_group()`, so this is an exception to the usual Bonsai JSON sync rule.
- **2026-09-29 — Training bots unscored.** Blocking GPTBot/ClaudeBot etc. is a client policy decision, not an SEO fault.
- **2026-09-29 — Default model Haiku 4.5.** It's cheap enough for fix lists; override via `BSGC_CLAUDE_MODEL`.

## Gotchas

- `wp_safe_remote_get()` rejects private IPs, so local `.test` sites can't be audited. This is intentional (SSRF guard).
- Hosts with a hard 30s proxy timeout can cut off PageSpeed even with `set_time_limit( 150 )`.
- Sites behind aggressive bot protection return 403 to server-side requests. The tool reports the block instead of auditing.
- Schema is read from static HTML only, so JS-injected JSON-LD won't be detected. Point users to Rich Results Test.

## Open items / tech debt

- [ ] No git repo yet. Initialise and push to GitHub.
- [ ] No PHPUnit tests. Priorities: `BSGC_Robots::is_allowed()`, `BSGC_Analyser::score()`, `BSGC_AI::sanitise()`, PSI/Claude error paths.
- [ ] AJAX handlers aren't wrapped in `try/catch` (Bonsai error-handling rule). An unexpected exception in the analyser would return a raw 500 instead of a JSON error.
- [ ] No `uninstall.php`. `bsgc_report` posts/meta and ACF options stay behind after the plugin is deleted.
- [ ] No reports retention/cleanup. History grows indefinitely (only 25 are shown).
- [ ] Single URL only; crawling is planned as "tier 3".
