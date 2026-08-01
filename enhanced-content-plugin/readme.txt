=== Enhanced Content ===
Contributors: yourwporgusername
Tags: seo, ai, content optimization, e-e-a-t, schema
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 2.21.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

An autonomous SEO content agent that finds ranking opportunities, drafts evidence-based fixes with AI, and publishes nothing without your approval.

== Description ==

Multi-Author Contributor Plugin helps websites and blogs earn reader trust through transparency. Credit everyone who worked on an article — authors, reviewers, and fact checkers — and back your content with sources, process links, and structured data that search engines understand.

= Trust & transparency features =

* **Multiple contributor roles** — co-authors, reviewers, and fact checkers per article, with drag-and-drop ordering and bulk assignment
* **Contributor bios on hover** — photo, job title, short bio, social profiles, and a link to the full profile (keyboard and touch accessible)
* **"Fact-checked on" dates** — show when an article was last verified, independent of the modified date
* **Corrections & updates log** — a dated, public log of corrections at the end of each article
* **Editorial Team section** — a shortcode/block that showcases your reviewers and fact checkers, with per-member article credits; members are hand-picked by administrators
* **Expert Verified badge** — a configurable badge with an explainer popup for expert-reviewed content
* **AI disclosure badges** — declare "No AI" or "AI Enhanced" per article, with a breakdown of exactly how AI was used
* **Sources & citations** — a numbered, labeled, drag-to-reorder source list at the end of each article
* **Editorial process links** — link your editorial, review, and fact-checking process pages from contributor popups
* **FAQ sections** — per-article FAQ accordion with FAQPage JSON-LD
* **Schema.org JSON-LD** — Article markup with all authors, plus reviewers and fact checkers expressed as schema.org Roles (E-E-A-T signals)
* **Author archive credits** — show "Reviewed N articles" on author archive pages
* **Article Health dashboard** — track word count, citation coverage, and content freshness, with batched recalculation that scales to large sites

= Placement & editor support =

* Every section can be placed automatically or manually via shortcodes — `[map_contributors]`, `[map_sources]`, `[map_faq]`, `[map_corrections]`, `[map_editorial_team]`
* Matching Gutenberg blocks with live editor previews
* Live preview of styling changes right on the settings page

= Developer friendly =

* Works on posts by default; enable any public post type from Settings
* Override any template from your theme: `your-theme/multi-author-plugin/{template}.php`
* Filters: `map_supported_post_types`, `map_template_path`, `map_output_schema`, `map_sources_title`, `map_corrections_title`, `map_editorial_process_label`
* WP-CLI: `wp map recalculate`
* Integrates with WordPress privacy tools (data export/erase) and Site Health
* SEO-plugin conflict detection (Yoast, Rank Math, AIOSEO)
* Clean uninstall — removes all options and metadata

== Installation ==

1. Upload the `multi-author-plugin` folder to `/wp-content/plugins/`, or install the ZIP via Plugins → Add New → Upload Plugin.
2. Activate the plugin through the Plugins menu.
3. Go to **Settings → Multi-Author** to choose post types, labels, badges, and styling.
4. Fill in contributor bios under **Users → Profile** (Contributor Information section).
5. Edit a post and use the **Article Contributors**, **Sources**, **AI Disclosure**, and **FAQ** meta boxes.

== Frequently Asked Questions ==

= Does it work with SEO plugins like Yoast or Rank Math? =

Yes. If your SEO plugin already outputs Article schema, you can disable this plugin's Article schema with one line: `add_filter('map_output_schema', '__return_false');`

= Can I use it on custom post types? =

Yes — enable any public post type under Settings → Multi-Author → Post Types.

= How do I customize the templates? =

Copy any file from the plugin's `templates/` folder into a `multi-author-plugin/` folder inside your theme and edit it there.

= Will deleting the plugin remove my data? =

Deactivating keeps everything. Deleting the plugin runs a full cleanup (options plus post/user metadata created by the plugin).

== Changelog ==

= 1.3.0 =
* New: "Fact-checked on" date shown in the byline, separate from the modified date
* New: Corrections & Updates log per article for transparent corrections
* New: Editorial Team shortcode/block — members hand-picked by administrators on user profiles or via the include attribute
* New: shortcodes and Gutenberg blocks for all sections, with automatic/manual placement setting
* New: bulk contributor assignment from the posts list, and a Contributors column with avatars
* New: live preview on the settings page
* New: author archive credits ("Reviewed N articles")
* New: WP-CLI command `wp map recalculate`; the dashboard button now recalculates in batches with progress
* New: privacy tools integration (personal data export/erase) and Site Health checks
* New: SEO-plugin detection with a one-click option to disable duplicate Article schema
* Improved: user picker suggests recently used contributors and warns when one person holds multiple roles on a post
* Improved: sources are drag-to-reorder; deleted users are automatically removed from contributor lists

= 1.2.0 =
* New: enable plugin features on any public post type (Settings → Post Types)
* New: theme template overrides (`your-theme/multi-author-plugin/`) and `map_template_path` filter
* New: `map_output_schema` filter for SEO-plugin compatibility
* New: clean uninstall (removes options and metadata)
* Improved: reviewers/fact-checkers expressed as schema.org Roles; JSON-LD hardened; password-protected posts excluded from schema
* Improved: keyboard and touch accessibility for contributor popups and badges
* Improved: Article Health dashboard performance on large sites; sortable columns no longer hide posts
* Fixed: fatal error when `the_content` ran twice; shortcode execution via contributor bios; admin XSS in user search; user enumeration endpoints removed
* Fixed: many smaller correctness, sanitization, and i18n issues

= 1.1.2 =
* Expert Verified badge, AI disclosure badges, process links, FAQ sections, Article Health dashboard

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.2.0 =
Major security and correctness release — all users should update. Adds custom post type support and theme template overrides.
