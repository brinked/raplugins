# Phase 1 — Architecture Assessment & Implementation Plan
### Autonomous SEO Growth System, content-only track

Governing document: [gameplan.txt](gameplan.txt). This plan executes the gameplan's §27 first
assignment against the codebase as it actually exists at v2.8.3. Products/ecommerce are out of
scope (separate plugin). Per §26, no broad code changes happen until this plan is approved.

Standing decisions (agreed 2026-07-29):

1. **Standalone plugin now, SaaS later.** Everything ships plugin-side, but the classes that will
   migrate to the RankAudit backend are isolated behind service boundaries (see §4) so the move is
   a transport swap, not a rewrite.
2. **SERP data**: topical maps launch GSC-first. A provider slot for DataForSEO is designed in now,
   implemented later. Nothing ever scrapes Google from the customer's server.
3. **Tiered plans**: every metered activity (analyses, classifications, drafts) runs through one
   limits gate (§5) that reads local settings today and a plan entitlement tomorrow.

---

## 1. Architectural assessment of the current plugin (v2.8.3)

### 1.1 What exists and is load-bearing

| Subsystem | Classes | State |
|---|---|---|
| Schema & data | `ECP_DB` (6 tables: runs, opportunities, proposals, events, metrics, clusters) | Solid. Migration self-verifies and retries; version stamped only on success. |
| Search data | `ECP_Search_Data` (Site Kit REST + CSV, 7/28/90d windows, device/country, diagnostics, repair) | Solid after the window_days incident. Diagnostics show raw stored state. |
| Signals & issues | `ECP_Signals` (~30 deterministic issue types incl. indexability, freshness, trust) | Solid. This is Phase 1's raw input, not something to rebuild. |
| Scoring | `ECP_Opportunity_Engine` (issue weight × search weight × confidence) | Works; Phase 1 layers classification on top, does not replace it. |
| AI pipeline | `ECP_AI_Client` + provider classes (Anthropic/OpenAI/RankAudit stub), JSON-schema output, `sanitize_schema()` | The RankAudit provider stub is the SaaS seam already in place. |
| Change lifecycle | `ECP_Analyzer` → `ECP_Guardrails` → `ECP_Proposals` → `ECP_Applier` | The product's core asset. Reused untouched by Phase 1. |
| Safety | Guardrails (tokens, figures, links, trims, em-dash scrub), revisions + revert data, drift checks | Battle-tested this cycle. Phase 1 adds nothing that writes content. |
| Autonomy | `ECP_Trust_Ladder`, `ECP_Refresh` (hold-window auto-apply) | Maps to gameplan §18 modes 2–3. |
| Measurement | `ECP_Measurement` (7/14/28/56/90d checkpoints, verdicts, Results panel) | Gameplan §17.1–17.3 largely done. §17.4 (learning) is Phase 7. |
| Content gaps | `ECP_Content_Gaps` + owner facts meta | Embryo of the Knowledge Vault (Phase 3). |
| Clusters | `ECP_Clusters` | Embryo of cannibalisation prevention (§8.6). |
| UI | 7 screens + AJAX layer, capability system (view/review_own/review_all/manage) | Extended, not replaced. |
| Ops | Scheduler (hourly scan/analyze, daily maintenance), digest, WP-CLI, budget caps | Phase 1 jobs ride these hooks. |

### 1.2 Missing abstractions Phase 1 must introduce

- **Site inventory**: no per-URL structured record exists. Opportunities store scores, not the
  page's facts (headings, links, schema types, taxonomy, fingerprint).
- **Classification**: no notion of a page's topic, search intent, or funnel stage.
- **Site profile**: no structured statement of what the business is, who it serves, what topics
  are in/out of scope. `tone_notes` is the only fragment.
- **Limits gate**: caps exist (daily analyses, monthly budget) but are checked ad hoc. Tiered
  plans need one chokepoint.
- **Narrative dashboard**: the dashboard reports state; it does not select one "do this today."

### 1.3 Technical debt & risks to carry into Phase 1

- No local PHP runtime: verification is the grep sweep + GitHub CI (phpcs/phpstan/phpunit configs
  exist in-repo and now actually run since the push). Phase 1 services must be plain enough to
  unit-test in CI.
- `ecp_events` and `ecp_metrics` grow unbounded between prunes; inventory adds another growing
  table — pruning is part of the schema work, not an afterthought.
- The v1 editorial toolkit shares the plugin; nothing in Phase 1 may touch its meta keys.

---

## 2. Phase 1 scope

Build, in order:

1. **Site profile & onboarding** — gameplan §5.2, content-only fields.
2. **Content inventory** — §5.3, one row per indexable post/page, refreshed by the existing scan.
3. **Classification** — §5.3's topic/intent/funnel fields, AI-batched, cached by content hash.
4. **Search Console mapping check** — §5.4 is largely built; add the inventory join + coverage %.
5. **Site Intelligence report** — §5.6, the read-only "what your site is" screen.
6. **Growth Dashboard v1** — §6: narrative header, Today's Priority card, estimate labelling.
7. **Limits gate** — §18.3 foundation, wraps all of the above.

Explicitly NOT in Phase 1 (per §27): topical map generation, briefs, article drafting, campaigns,
media planner, new automation modes, DataForSEO. No content modification paths are added; Phase 1
is read-and-understand only.

---

## 3. Schema migration plan

Additive only; existing tables untouched. `SCHEMA_VERSION` bump; migration runs through the
existing self-verifying `maybe_upgrade()` path.

### 3.1 New table: `ecp_inventory`

One row per post in the configured post types. Refreshed opportunistically by the existing hourly
scan batch (no new crawl machinery).

```
id                bigint PK
post_id           bigint UNIQUE
url               varchar(255)
post_type         varchar(32)
post_status       varchar(20)
title             varchar(255)
meta_description  varchar(255)         -- resolved, not template
word_count        int
heading_json      longtext             -- [{level, text}]
taxonomy_json     longtext             -- {category: [...], post_tag: [...]}
author_id         bigint
internal_links_out smallint
internal_links_in  smallint
external_links    smallint
image_count       smallint
schema_types      varchar(191)
content_hash      char(40)             -- reuses ECP_Content_Map::content_hash
-- classification block (written only by the classifier)
topic             varchar(191)
subtopic          varchar(191)
intent            varchar(24)          -- informational|commercial|transactional|navigational
funnel_stage      varchar(24)          -- awareness|consideration|decision
confidence        tinyint
classified_hash   char(40)             -- content_hash at classification time
classified_at     datetime
-- bookkeeping
scanned_at        datetime
created_at, updated_at datetime
KEY topic (topic), KEY intent (intent), KEY post_type_status (post_type, post_status)
```

Design notes:
- `classified_hash != content_hash` ⇒ classification is stale ⇒ eligible for re-classification.
  Same pattern that already governs gap-report freshness — proven this cycle.
- No `site_id` column: standalone is single-site. The SaaS sync layer adds site scoping on the
  backend, not in WordPress.
- Pruning: rows whose post is deleted are removed during the daily maintenance job.

### 3.2 Site profile

Stored as one option (`ecp_site_profile`), not a table — single row, single site, and the SaaS
version lives server-side anyway. Fields (content-only subset of §5.2): business_name, purpose,
offerings (free text), audience, geo_markets, conversions, seed_topics[], excluded_topics[],
competitors[] (names only, reserved for later), brand_voice, compliance_notes,
publishing_capacity (posts/month), approval_level (maps to existing settings).

### 3.3 Deferred tables

`ecp_topics`, `ecp_campaigns`, `ecp_campaign_items`, `ecp_knowledge_items`, `ecp_media_tasks`
(gameplan §20) belong to Phases 3–5 and are not created in Phase 1. Creating empty tables early
just freezes guesses.

---

## 4. Service boundaries (the SaaS seam)

Three categories, marked now so the split is mechanical later:

**Stays in WordPress forever**: inventory collection, applying changes, revisions/rollback,
capability checks, admin UI, Site Kit access. (WordPress-local by nature.)

**Migrates to backend later** (each a static service class, pure data in/out, no direct
`$wpdb`/UI access): `ECP_Classifier` (prompts + normalization), the Today's Priority selector,
future: topical maps, brief generation, learning. When the backend exists, these become thin HTTP
clients hitting `/v1/*` per gameplan §19.3; their public method signatures are the API contract.

**The gate between them**: `ECP_Limits` (below) — local settings today, plan entitlements from
the licensing server tomorrow. Every metered call already routed through it means plan
enforcement is a data change, not a refactor.

## 5. `ECP_Limits` — the tiered-plans foundation

One class, one question: `ECP_Limits::can('classify', $count)` / `::spend('classify', $count)`.

- Wraps the existing daily-analyses cap and monthly budget without changing their behaviour.
- Adds meters Phase 1 needs: `classify_per_day` (default 100 pages/day).
- Reserves meter names later phases will use: `briefs_per_month`, `drafts_per_month`.
- Backed by transients + options exactly like `ECP_AI_Client::daily_analyses()` today.
- A `ecp_limits_source` filter lets the future licensing client replace the numbers wholesale —
  higher plan, higher numbers, zero code change in the callers. Efficiency still matters
  (batching, caching) because margin per tier matters, but scale is priced, not blocked.

## 6. Classification design (the only AI spend in Phase 1)

- **Batched**: up to 20 pages per request. Input per page: title, resolved SEO title/description,
  heading outline, top 5 GSC queries (when present), taxonomy. Never full content — the outline
  is enough for topic/intent and cuts tokens ~50×.
- **Schema-constrained**: JSON schema (through the existing `sanitize_schema()` restrictions),
  enums for intent and funnel_stage, topic as short free text the normalizer lowercases/dedupes.
- **Cached**: skipped while `classified_hash == content_hash`. A site classifies once, then only
  edited pages re-classify.
- **Budgeted**: `ECP_Limits::can('classify')`; rides the same provider/budget machinery, visible
  in the runs table like every other AI call (job_type `classify`).
- **Cost picture**: 500-page site ≈ 25 batched calls, once. Roughly one day's default analysis
  budget, then pennies per month for edited pages. A "use cheaper model for classification"
  setting is included (provider layer already supports per-call model choice).
- **Resumable**: works through the inventory table oldest-unclassified-first; an interrupted run
  resumes exactly where it stopped (gameplan Phase 1 acceptance criterion).

## 7. Growth Dashboard v1 & Site Intelligence screen

**Dashboard additions** (existing screen, new top section):
- Narrative header: templated from real data, never generated — "RankAudit found N meaningful
  opportunities. The highest-priority action is X, which received Y impressions but only Z clicks
  over the last 28 days." (Templates keep it honest and free.)
- **Today's Priority** card: the single top action, selected by existing opportunity score with a
  tie-break toward CTR fixes (cheapest wins first). Includes reason, evidence, effort, confidence,
  Review / Postpone / Dismiss. Postpone = existing snooze; Dismiss = existing dismiss.
- Estimate labelling per §6.3: every projected number carries "directional estimate · confidence ·
  based on [source, date range]". The existing potential-clicks tile adopts the same label.

**New screen: Site Intelligence** (read-only, `ecp_view_agent` capability):
- Coverage: pages by topic (from classification), topics with 1 page vs several, intent mix,
  funnel mix.
- Health rollups reusing existing detectors: competing pages (clusters), orphans, high-impression/
  low-CTR, declining, dated, thin, missing trust elements — each linking to the existing screen
  that acts on it.
- Inventory table: sortable list of every page with topic/intent/stage/coverage flags, filterable.
  This is also where misclassifications get corrected (inline edit, stored as an override flag the
  classifier never overwrites — user corrections are ground truth).
- Search Console mapping row: "N of M published pages have search data" with the existing
  diagnostics link.

Onboarding: a dashboard checklist panel (pattern already exists for setup steps) walking through
profile → inventory scan → classification → first intelligence report. Profile editing lives in
Settings → Site profile tab.

## 8. Security analysis

Phase 1 adds **no** new attack surface classes: no REST endpoints (existing AJAX + nonce +
capability pattern), no external URL fetching (SSRF surface unchanged; DataForSEO later gets a
domain allowlist per §21), no file writes, no content mutation paths. Specific measures:
- Classification prompts contain site-owned content only; still treated as data (headings are
  quoted into a user message, never concatenated into system instructions) — prompt-injection
  hygiene before external content ever arrives in later phases.
- Site profile fields sanitized as plain text; rendered escaped everywhere.
- Inventory writes prepared-statement only; the table contains nothing sensitive beyond what
  WordPress already exposes publicly.
- Classifier output constrained by enum schema; free-text topic run through `sanitize_text_field`
  and length-capped before storage.

## 9. Test strategy

CI (GitHub Actions, already in repo) is the test runner — no local PHP exists.
- **Unit (phpunit, no WP)**: classifier normalizer (mojibake/case/length/enum fallbacks), limits
  gate arithmetic, narrative template renderer, inventory diff (changed-hash detection).
- **Static**: phpstan level bump for new files only; phpcs on changed paths.
- **The local sweep** (braces, duplicate names, static refs, `self::` resolution) remains the
  pre-push gate as documented in the session notes.
- Acceptance pass against gameplan Phase 1 criteria (§24) on a staging copy of the live site:
  full inventory built, every page classified or queued, GSC rows joined, priorities shown, zero
  content modified, interruption + resume verified by killing cron mid-classification.

## 10. File-by-file implementation plan

New files:
| File | Contents |
|---|---|
| `includes/agent/class-ecp-inventory.php` | Table name, collect_for_post(), refresh hooks into scan batch, prune, coverage stats |
| `includes/agent/class-ecp-classifier.php` | Batch builder, prompt + schema, normalizer, cache/staleness logic, CLI-callable |
| `includes/agent/class-ecp-site-profile.php` | Typed get/set over the option, completeness %, prompt-context serializer |
| `includes/agent/class-ecp-limits.php` | can()/spend()/status(), meter registry, filter seam |
| `admin/agent/class-ecp-screen-intelligence.php` | Site Intelligence screen |

Modified files:
| File | Change |
|---|---|
| `class-ecp-db.php` | `ecp_inventory` CREATE TABLE + SCHEMA bump |
| `class-ecp-scheduler.php` | scan batch also refreshes inventory rows; daily job runs classification within limits |
| `class-ecp-opportunity-engine.php` | expose top-priority selector for Today's Priority |
| `class-ecp-ai-client.php` | route metered calls through ECP_Limits (thin wrapper, behaviour unchanged) |
| `class-ecp-agent-settings.php` | profile tab fields, classify caps/model, defaults + sanitize |
| `class-ecp-screen-dashboard.php` | narrative header, Today's Priority, estimate labels, onboarding checklist items |
| `class-ecp-admin-menu.php` | register Site Intelligence screen |
| `class-ecp-ajax.php` | classify-now, priority dismiss/postpone, profile save, inline topic override |
| `class-ecp-agent-cli.php` | `wp ecp inventory`, `wp ecp classify` |
| `uninstall.php` | new table + options |

Order of commits (each independently shippable, current agent untouched throughout):
1. `ECP_Limits` wrapping existing caps (pure refactor, no behaviour change)
2. Inventory table + collector + scan integration
3. Site profile + settings tab + onboarding checklist
4. Classifier + scheduler integration + CLI
5. Site Intelligence screen
6. Dashboard v1 (narrative, Today's Priority, estimate labels)

## 11. Rollback & migration concerns

- Every step is additive; deactivating nothing. Rolling back = removing the new screen/menu
  entries; the inventory table is inert data.
- The upgrade path is the self-verifying `maybe_upgrade()`; a failed `ecp_inventory` creation
  logs, retries next request, and blocks nothing existing (inventory consumers all
  `tables_exist()`-guard, the standing pattern).
- No existing option, meta key, or table is altered. v1 toolkit untouched.
- Live-site sequence: upload → migration self-runs → scan fills inventory over ~a day of hourly
  batches (500 posts ≈ 10 batches) → classification proceeds at the daily cap → intelligence
  screen populates progressively with an honest "N of M classified" header rather than appearing
  empty (the lesson this codebase keeps re-learning: show partial state, never blank).
