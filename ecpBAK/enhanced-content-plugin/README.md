# Enhanced Content

An autonomous SEO content agent for WordPress that never publishes anything you haven't approved.

It scores every page on your site, finds the ones with real ranking upside, works out specifically what is wrong with them, drafts evidence-based fixes with AI, and puts each fix in front of you as a single reviewable change with a diff. You apply it, edit it first, or reject it. Everything applied can be undone in one click.

This is v2 of the Multi-Author Contributor Plugin. The whole v1 feature set — contributors, reviewers, fact-checkers, sources, FAQ, corrections log, editorial team, schema — is still here and unchanged. The agent is built on top of it, and uses it: an article's citations and reviewers are trust signals the agent reads, and "add a source" is a change it can propose.

---

## The loop

```
scan  →  score  →  analyze  →  propose  →  YOU APPROVE  →  apply  →  measure
 free    free       $          queue        the gate      revision    GSC
```

**Scan** and **score** are free — pure database work, no AI. A 5,000-post site can be scored end to end without spending a cent. Only **analyze** costs money, and only on pages you or the schedule pick off the top of the queue.

---

## Nothing is published without you

This is the design constraint everything else follows from.

- Every change lands in a **review queue** as `pending`. It has touched nothing.
- The **applier** creates a WordPress revision before it writes, every time. Your site's own history holds the pre-change state, independently of this plugin.
- Every applied change stores enough to **undo it exactly**, and the History screen has an Undo button on each row.
- **Auto-apply is off by default** and is never all-or-nothing. Even when enabled, a change is auto-applied only if it clears four independent gates: it is on the allowed list, it is not in the `sensitive` risk tier, the guardrails raised no flags, and confidence is at least 85%. One rollback of a change type switches that type back to manual immediately.

### Risk tiers

Every proposal is classified, and the tier drives what the UI emphasises and what may be automated.

| Tier | Meaning | Examples |
|---|---|---|
| **Safe** | Mechanical, reversible, hard to get wrong | Alt text, meta description, schema field |
| **Needs a look** | An editorial judgement | Section rewrite, new internal link, new section |
| **Check the facts** | Contains something the agent could not verify | Anything with a new number, date, price, or an altered brand term |

The tier is a floor, not a fixed value. Guardrails can promote a change to `sensitive` but never demote one.

---

## What stops it inventing things

The failure mode that matters for AI content tooling is a confident, fluent, wrong sentence. Several independent checks run **before a proposal ever reaches your queue**:

- **Shortcode preservation.** Shortcodes, embeds, forms, scripts and iframes are swapped for opaque `{{ECP:n}}` tokens before the model sees the content, and restored after. A proposal that dropped one is rejected outright — the model cannot delete a contact form it never saw.
- **Invented figures.** Every number, date, price and percentage in the new text is diffed against the old text *and the rest of the page*. Anything genuinely new is flagged, and with the strict setting on, the whole change is thrown away if the model did not flag it itself.
- **Self-reported uncertainty.** The model must return `needs_fact_check` and a list of specific unverified claims. Those are shown to you verbatim, as a checklist, above the diff.
- **Real URLs only.** Internal links may only point at pages supplied to the model from your own site, and every added link is re-resolved to a published post before the proposal is stored. Invented URLs cannot get through.
- **Sources must already be on the page.** A citation is only accepted if the URL is already linked in the article body.
- **Content loss.** A "rewrite" that cuts a section below 40% of its original length is a deletion, and is refused.
- **Change size.** No single change may rewrite more than a configurable share of an article (default 40%).
- **Banned phrases.** Your own list. The defaults are the usual AI tells (`delve into`, `in today's digital landscape`, `game-changer`…). A proposal containing one is discarded silently.
- **Brand terms.** Terms you list must be reproduced exactly. Altered casing or spacing promotes the change to `sensitive`.
- **Drift.** At apply time the post's content hash is re-checked. If someone edited the page after the proposal was made, the change is held back rather than silently reverting their work.

---

## Reviewing changes fast

The review queue is built for someone clearing twenty changes in three minutes.

- Each card shows the **word-level diff**, the agent's reasoning, and any unverified claims — without a click.
- **Keyboard:** <kbd>J</kbd>/<kbd>K</kbd> to move, <kbd>A</kbd> to apply, <kbd>R</kbd> to reject, <kbd>E</kbd> to edit, <kbd>Esc</kbd> to cancel.
- **Edit before applying.** Your version is what gets written, and the audit log records that you changed it.
- **Bulk apply** is offered for the safe tier only. Bulk-approving a batch of "check the facts" changes would defeat the point of the tier.
- Cards resolve in place and fade out. You keep your position.

### Seeing it before you approve it

A diff tells you what words moved. It doesn't tell you whether the result looks right in your theme, whether the shortcode still renders, or whether a new section sits properly between the two around it. Three previews, matched to the kind of change:

| Change | Preview |
|---|---|
| Body content | **Preview the whole page** opens the real front end with the change applied in memory. Nothing is saved. |
| Metadata | A **Google-style result snippet**, before and after, with truncation shown — a meta description has no visible form on the page itself. |
| Any section | **Show it rendered** expands the new markup inside the card, run through `do_blocks`, `wpautop` and `do_shortcode`. |

The full-page preview renders unsaved content on a public URL, so it is locked down: a per-proposal nonce, a capability check, a check that the nonce matches the page being viewed, `noindex`, and `DONOTCACHEPAGE` so no page cache can store and serve it to a visitor. A fixed bar across the top makes it impossible to mistake for the live page.

The in-card render deliberately does **not** run the full `the_content` filter chain — that would fire every other plugin's content filters and inject ads, related posts and social buttons into what is meant to be a preview of one section.

---

## Setup

1. Install and activate. Tables are created automatically.
2. **Enhanced Content → Agent Settings.** Choose a provider and paste an API key.
   Better: keep the key out of your database entirely —
   ```php
   // wp-config.php
   define( 'ECP_AI_API_KEY', 'sk-ant-…' );
   ```
   The constant always wins over the stored value. If you do store it in the database it is encrypted with your site's salts — note that rotating your salts will invalidate it.
3. Set a **monthly spend cap**. It is a hard stop, not a warning.
4. **Connect Search Console** (optional, strongly recommended — see below).
5. Turn the agent on, and hit **Scan content**.

### Providers

| Provider | Notes |
|---|---|
| **Anthropic** (default) | Claude Opus 5 / Sonnet 5 / Haiku 4.5. Structured output is schema-constrained, so responses are validated, not parsed hopefully. |
| **OpenAI** | For sites that already have a key. |
| **RankAudit** | The seam for the managed service. Not live yet — see *Integration* below. |

Requests go out through `wp_remote_post`, not a Composer SDK. A WordPress plugin cannot reliably ship a vendor tree; two plugins bundling different versions of the same package fight over the autoloader.

**Effort** is the real cost lever on Claude 5 models — it controls how hard the model thinks before answering. Lower settings are much cheaper and often good enough for routine work. Sweep it against your own content rather than assuming the default is right for you.

### Search Console

Query data turns *"this page is thin"* into *"this page ranks #12 for a term with 4,000 monthly impressions and a 0.4% CTR"*. It is also the only way to measure whether a published change helped.

Three ways in, tried in this order:

1. **Google Site Kit** — if it is installed and connected, this plugin reads Search Console through it. No Google Cloud project of your own, no OAuth to set up.
2. **CSV import** — export Pages from the Search Console UI and upload it. Works anywhere. Column names are matched loosely, so localised exports work.
3. **None** — the agent still works from on-page signals, but cannot prioritise by real demand or measure outcomes.

---

## How pages are prioritised

The score is deliberately legible so the UI can explain it rather than showing a black box:

```
score = (issue_weight × 0.45 + search_weight × 0.55) × confidence
```

- **issue_weight** (0–60) — how much is demonstrably wrong, weighted by severity.
- **search_weight** (0–100) — striking-distance position (5–20 is where an edit plausibly moves the needle), log-scaled impression volume, and the gap between actual CTR and what that position should deliver.
- **confidence** (0.4–1.0) — reduced when there is no query data, when there is too little content to work with, or when a human touched the page recently.

Issues themselves are detected deterministically — no AI — so the Opportunities screen is a useful audit before a single API call is made.

---

## Change types

| Type | Risk | What it does |
|---|---|---|
| `meta_title` | Safe | Rewrites the SEO title (requires an SEO plugin) |
| `meta_description` | Safe | Rewrites the search snippet |
| `image_alt` | Safe | Adds or improves alt text |
| `schema_fix` | Safe | Corrects a schema.org field |
| `source_add` | Moderate | Adds a citation from a URL already in the article |
| `internal_link_add` | Moderate | Links a phrase to another page on this site |
| `heading_rewrite` | Moderate | Changes a subheading |
| `intro_rewrite` | Moderate | Rewrites the opening before the first subheading |
| `section_rewrite` | Moderate | Replaces one section |
| `section_add` | Moderate | Inserts a section covering a missing topic |
| `faq_add` | Moderate | Adds Q&A pairs to the FAQ block and its schema |
| `freshness_update` | **Sensitive** | Updates dated statements — always reviewed |

Each can be switched off entirely in Settings → Writing rules.

---

## Content safety on Gutenberg and Classic

Section-level patching is what makes review practical, and it has to not break your posts.

- **Block content** is parsed with `parse_blocks()` and re-serialized with `serialize_blocks()`. Block comments, attributes and inner blocks survive byte-for-byte except where deliberately replaced.
- **Classic content** is split on top-level headings using offsets into the raw markup. It is never round-tripped through DOMDocument, which reformats markup and mangles shortcodes.
- **Section ids** hash the heading text and its ordinal, not the body. A proposal survives small body edits, but correctly fails to apply if the heading is renamed or the section deleted — which is exactly when a human should look again.

---

## Competing pages

Everything above works one page at a time, which means it structurally cannot see the most common mid-size-site SEO problem: three posts quietly competing for the same query, splitting the links and relevance between them so none of them wins.

**Detection** runs nightly and costs nothing:

1. **Query overlap** — two or more pages taking impressions for the same Search Console query. Real evidence, not a guess.
2. **Title similarity** — fallback when there's no query data. Much weaker, so those clusters are labelled as such and scored lower so they can't outrank a measured conflict.

Clusters of six or more pages are skipped — that's almost always an archive or a paginated series, not genuine competition. So is a pair where one page sits at #4 and the other at #60; the damaging case is two pages at *similar* positions trading places, and the score reflects that.

**What it does about it is deliberately asymmetric:**

- **Retargeting** a secondary page — a sharper SEO title, a rewritten opening that names its distinct angle and links to the primary — becomes ordinary proposals in the normal queue. Same guardrails, same diff, same rollback.
- **Merging** does not. A merge means deleting a URL and setting up a redirect, and getting that wrong loses the rankings you were trying to consolidate. Merge advice is presented as an ordered manual checklist for a human.

The model is told to be conservative and that `leave alone` is the right answer whenever two pages genuinely serve different intents. Recommending a merge for a page that deserves to exist is far more damaging than tolerating a mild overlap.

Which page should win is decided before the model sees anything: measured clicks and position first, then substance, then inbound internal links, with age as a tiebreak. The model can disagree, but its choice is validated against the actual member set.

The Competing Pages screen shows the measured Search Console rows **above** the verdict, so you can check the reasoning against real numbers rather than taking it on faith.

---

## Who can do what

Approving a change writes to your live site, so access is per-role and defaults to conservative.

| Level | Can | Cannot |
|---|---|---|
| **No access** | — | The menu is hidden entirely |
| **Look, not approve** | Read the queue, diffs, reasoning, history | Apply, reject, edit, or spend budget |
| **Own posts** | Approve and roll back changes to posts they wrote | See anyone else's posts — filtered out of the queue, counts, and badges |
| **Any change** | Approve, reject, edit, roll back anything; run analyses | Change settings, see the API key, enable auto-apply |
| **Full control** | Everything | — |

Defaults: administrator → full control, editor → any change, author → own posts, everyone else → none.

Two implementation notes that matter:

- **Capabilities are virtual**, granted through the `user_has_cap` filter rather than written into the roles table. Mutating roles is a one-way door — remove the plugin and you're left with orphaned caps and no clean way to tell which were yours. Change the setting, and the permission changes.
- **WordPress has the final say.** Someone set to "any change" still cannot approve a change to a post their WordPress role won't let them edit. This setting narrows what people reach; it can never widen it.
- **Scoping happens in the query, not just at the button.** A restricted reviewer never sees a proposal they couldn't act on — filtering only at approve time would still leak other people's titles and draft copy into the queue, and would make the menu badge lie.

Administrators can't be demoted here. Anyone who can install plugins can reach these settings anyway, and pretending otherwise would be security theatre.

---

## Earned autonomy

Nobody wants to hand-approve their 200th alt-text change. Nobody should hand the agent their whole site on day one.

The plugin tracks the approve/reject/revert record for each change type **on your site**. After 20 reviews at a 90%+ approval rate with no rollbacks, it offers — on the dashboard, as a suggestion you can decline — to start applying that type automatically. A single rollback resets the counter and switches the type back off.

---

## Measurement

When a change is applied, the current Search Console figures are stored as a baseline. Afterwards the History screen reports movement: improving, stable, declining, or too early (under 7 days).

The wording is deliberately correlational. A ranking moved after the edit. That is evidence, not proof, and the plugin does not claim otherwise.

---

## Budget control

- **Monthly cap in dollars** — a hard stop. Checked before every API call and again between posts in a batch.
- **Analyses per day** — also controls how fast your review queue fills. The cron job spreads the daily allowance across the day rather than burning it in the first hour.
- Every call is logged with token counts and cost. History → AI usage shows exactly where the money went.

---

## Scheduled work

| Job | Frequency | Cost |
|---|---|---|
| Scan | Hourly, batched, walks the site continuously | Free |
| Analyze | Hourly, top of the queue, within caps | Paid |
| Maintenance | Daily — expiry, pruning, metrics sync | Free |
| Digest email | Daily or weekly | Free |

The digest is what stops the review queue becoming a graveyard: what's waiting, what was published, and how the last batch performed. Nothing is sent when there is nothing to say.

---

## WP-CLI

Worth using for the first full scan and first analysis batch — no execution-time limit, and you can watch.

```bash
wp ecp status                        # is it configured, what has it done
wp ecp scan                          # score every page (free)
wp ecp analyze --limit=20 --dry-run  # what would be analyzed, and why
wp ecp analyze --limit=5             # spend money
wp ecp proposals                     # what is waiting
wp ecp show 42                       # one proposal in full, with the diff
wp ecp approve 42 43 44              # approve and apply
wp ecp reject 45 --note="wrong tone"
wp ecp revert 42                     # undo
wp ecp clusters                      # find pages competing for the same searches
wp ecp analyze-cluster 3             # decide what to do about one group
wp ecp import-metrics gsc.csv        # Search Console export
wp ecp digest                        # send the digest now
wp ecp install                       # recreate tables
```

---

## Hooks

```php
// Never let the agent touch these posts.
add_filter( 'ecp_excluded_post_ids', function ( $ids ) {
    $ids[] = 42;
    return $ids;
} );

// Add your own signals for the scorer and the prompt.
add_filter( 'ecp_post_signals', function ( $signals, $post ) {
    $signals['is_cornerstone'] = (bool) get_post_meta( $post->ID, '_cornerstone', true );
    return $signals;
}, 10, 2 );

// Add your own detectable issues.
add_filter( 'ecp_post_issues', function ( $issues, $signals ) {
    if ( empty( $signals['has_featured_image'] ) ) {
        $issues[] = array(
            'code'      => 'no_featured_image',
            'severity'  => 'low',
            'label'     => 'No featured image',
            'detail'    => 'Hurts social sharing.',
            'fix_types' => array(),
        );
    }
    return $issues;
}, 10, 2 );

// Register or remove change types.
add_filter( 'ecp_change_types', function ( $types ) {
    unset( $types['section_add'] );
    return $types;
} );

// Widen or narrow the HTML the agent may emit.
add_filter( 'ecp_allowed_html', function ( $allowed ) {
    $allowed['kbd'] = array();
    return $allowed;
} );

// Override a user's agent access level.
add_filter( 'ecp_user_access_level', function ( $level, $user ) {
    if ( user_can( $user, 'manage_woocommerce' ) ) {
        return ECP_Capabilities::LEVEL_REVIEW_ALL;
    }
    return $level;
}, 10, 2 );

// React to an applied change.
add_action( 'ecp_change_applied', function ( $proposal_id, $proposal, $revision_id ) {
    // purge a CDN, ping a webhook…
}, 10, 3 );
```

---

## Database

Six tables, prefixed `{wp_prefix}ecp_`:

| Table | Holds |
|---|---|
| `ecp_runs` | One row per AI call: tokens, cost, outcome |
| `ecp_opportunities` | Latest score and issue list per post |
| `ecp_proposals` | Proposed changes and their approval state |
| `ecp_events` | Append-only audit trail |
| `ecp_metrics` | Search Console rows per post and query |
| `ecp_clusters` | Groups of pages competing for the same topic |

Post meta keys are **unchanged from v1** — `_article_contributors`, `_article_sources`, `_map_faq`, `_map_word_count` and the rest — so upgrading does not migrate or risk your existing data.

Uninstalling drops the agent tables. The changes it made stay on your posts (WordPress revisions were created before each one), but the one-click undo goes away with the tables.

---

## Integration with RankAudit

This plugin is deliberately standalone so the agent logic can be proven before it is wired into the platform. The seam is `ECP_Provider`:

```
ECP_Analyzer  →  ECP_AI_Client  →  ECP_Provider  →  Anthropic | OpenAI | RankAudit
```

Everything above that line — signals, scoring, guardrails, the approval queue, the applier, rollback, measurement — is provider-agnostic. Switching a site from a self-managed API key to the RankAudit subscription is a settings change.

`ECP_Provider_RankAudit` already implements the request signing that section 5 of the plan describes: site id, timestamp, nonce, body SHA-256, HMAC signature. The endpoint it posts to does not exist yet, so the wire format is a proposal for the server side to be built against, not a contract with a running service.

---

## Requirements

- WordPress 5.8+
- PHP 7.4+
- An API key from Anthropic or OpenAI
- Optional: Google Site Kit, for Search Console without your own OAuth app
- Optional: Yoast, Rank Math, AIOSEO or SEOPress — needed for SEO title changes

---

## Editorial toolkit (from v1)

Unchanged, and documented in [docs/README-v1.md](docs/README-v1.md):

- Multiple contributor types with hover popups and schema.org roles
- Fact-check dates and a public corrections log
- Sources and citations
- FAQ blocks with FAQPage schema
- Editorial team page
- Shortcodes and Gutenberg blocks: `[map_contributors]`, `[map_sources]`, `[map_faq]`, `[map_corrections]`, `[map_editorial_team]`
- Article Health dashboard

Shortcode names, block names, CSS classes, filter names and meta keys all kept their `map_` prefix so nothing breaks on upgrade. Only PHP class names and the text domain were renamed.

---

## License

GPL v2 or later.
