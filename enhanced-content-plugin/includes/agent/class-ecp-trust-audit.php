<?php
/**
 * Trust Foundations: the checklist that decides whether any of the
 * content work matters.
 *
 * A reader (and a search quality rater) asks the same questions of
 * every site: who wrote this, can I see who they are, when was it
 * written, does the site say how it operates, and is it honest about
 * making money. A site that cannot answer those loses before the
 * first word of the article is weighed. This class audits exactly
 * that — deterministically, from data already on the site, for free.
 *
 * Three groups of checks:
 *
 *   Authors — every author with published content needs a real photo,
 *   a bio, a role, and at least one verifiable profile (LinkedIn,
 *   X/Twitter) so a reader can confirm they exist.
 *
 *   On-article trust — bylines with dates shown by the plugin's own
 *   display, and no article with affiliate links missing a disclosure.
 *
 *   Site policies — privacy, about, contact, editorial policy; a
 *   review policy when the site reviews things; an affiliate
 *   disclosure page when it earns from links. Conditional checks say
 *   "not applicable" honestly instead of demanding pages a site does
 *   not need.
 *
 * Failing foundational checks feed the top of the Growth Roadmap —
 * above every content improvement, because they gate whether the
 * content improvements will ever pay.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ECP_Trust_Audit {

    /* Check statuses. */
    const PASS = 'pass';
    const WARN = 'warn';
    const FAIL = 'fail';
    const NA   = 'na';

    /**
     * The cache must never outlive the facts: any change that could
     * flip a check — the privacy setting, a page saved, a profile
     * edited, a menu changed, the customizer (logo) saved — clears it
     * on the spot. Fix something, see it fixed.
     */
    public static function init() {
        $bust = array(__CLASS__, 'bust');

        add_action('update_option_wp_page_for_privacy_policy', $bust);
        add_action('save_post_page', $bust);
        add_action('profile_update', $bust);
        add_action('deleted_user', $bust);
        add_action('wp_update_nav_menu', $bust);
        add_action('customize_save_after', $bust);
    }

    public static function bust() {
        delete_transient('ecp_trust_audit');
    }

    /**
     * When the cached audit was produced, for the screen's honesty line.
     *
     * @return int Unix timestamp, 0 when never run.
     */
    public static function checked_at() {
        return (int) get_option('ecp_trust_checked_at', 0);
    }

    /**
     * Run the full audit. Cached briefly — every check is cheap, but
     * the avatar probe touches the network once per author.
     *
     * @param bool $fresh Skip the cache.
     * @return array[] Checks: { id, group, label, status, detail, fix_label, fix_url, severity }
     */
    public static function run($fresh = false) {
        if (!$fresh) {
            $cached = get_transient('ecp_trust_audit');

            if (is_array($cached)) {
                return $cached;
            }
        }

        $checks = array_merge(
            self::site_checks(),
            self::author_checks(),
            self::article_checks(),
            self::policy_checks()
        );

        set_transient('ecp_trust_audit', $checks, 15 * MINUTE_IN_SECONDS);
        update_option('ecp_trust_checked_at', (int) current_time('timestamp'), false);

        return $checks;
    }

    /**
     * Summary counts for the dashboard line.
     *
     * @return array { total, in_place, failing }
     */
    public static function summary() {
        $checks = self::run();
        $total = 0;
        $in_place = 0;
        $failing = 0;

        foreach ($checks as $check) {
            if (self::NA === $check['status']) {
                continue;
            }

            $total++;

            if (self::PASS === $check['status']) {
                $in_place++;
            } elseif (self::FAIL === $check['status']) {
                $failing++;
            }
        }

        return array('total' => $total, 'in_place' => $in_place, 'failing' => $failing);
    }

    /**
     * The failing foundational checks, for the roadmap.
     *
     * @return array[]
     */
    public static function failing() {
        return array_values(array_filter(self::run(), function ($check) {
            return self::FAIL === $check['status'];
        }));
    }

    /* --------------------------------------------------------------------
     * The site itself
     * ----------------------------------------------------------------- */

    private static function site_checks() {
        $checks = array();

        // HTTPS. Table stakes; its absence discounts everything.
        $https = 'https' === wp_parse_url(home_url(), PHP_URL_SCHEME);

        $checks[] = array(
            'id'        => 'site_https',
            'group'     => 'site',
            'label'     => __('The site runs on HTTPS', 'enhanced-content-plugin'),
            'status'    => $https ? self::PASS : self::FAIL,
            'detail'    => $https
                ? __('Every page is served encrypted.', 'enhanced-content-plugin')
                : __('The site address is plain http. Browsers mark it "Not secure" on every visit — no content survives that first impression.', 'enhanced-content-plugin'),
            'fix_label' => __('General settings', 'enhanced-content-plugin'),
            'fix_url'   => admin_url('options-general.php'),
        );

        // Organization schema: does the site tell machines who publishes it?
        $own_schema = !ECP_Settings::get_setting('disable_article_schema', 0);
        $yoast = (array) get_option('wpseo_titles', array());
        $yoast_org = !empty($yoast['company_name']);
        $has_logo = has_custom_logo();

        if ($own_schema || $yoast_org) {
            $status = $has_logo ? self::PASS : self::WARN;
            $detail = $has_logo
                ? __('Articles carry Organization publisher schema with your site logo.', 'enhanced-content-plugin')
                : __('Organization schema is emitted, but no site logo is set — the publisher entry ships without an image. Set one under Appearance → Customize → Site Identity.', 'enhanced-content-plugin');
        } else {
            $status = self::WARN;
            $detail = __('No Organization schema found: the plugin\'s article schema is disabled and no SEO plugin declares an organization. Machines cannot tell who publishes this site.', 'enhanced-content-plugin');
        }

        $checks[] = array(
            'id'        => 'org_schema',
            'group'     => 'site',
            'label'     => __('Organization schema identifies the publisher', 'enhanced-content-plugin'),
            'status'    => $status,
            'detail'    => $detail,
            'fix_label' => __('Site identity', 'enhanced-content-plugin'),
            'fix_url'   => admin_url('customize.php'),
        );

        return $checks;
    }

    /* --------------------------------------------------------------------
     * Authors
     * ----------------------------------------------------------------- */

    /**
     * Everyone with published content in the agent's post types.
     *
     * @return WP_User[]
     */
    private static function content_authors() {
        global $wpdb;

        $types = (array) ECP_Agent_Settings::get('post_types', array('post'));
        $placeholders = implode(',', array_fill(0, count($types), '%s'));

        $ids = (array) $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT post_author FROM {$wpdb->posts}
              WHERE post_status = 'publish' AND post_type IN ({$placeholders}) AND post_author > 0",
            $types
        ));

        $authors = array();

        foreach ($ids as $id) {
            $user = get_userdata((int) $id);

            if ($user) {
                $authors[] = $user;
            }
        }

        return $authors;
    }

    private static function author_checks() {
        $authors = self::content_authors();
        $checks = array();

        if (!$authors) {
            return $checks;
        }

        $total = count($authors);
        $missing_bio = array();
        $missing_photo = array();
        $missing_social = array();
        $missing_role = array();

        foreach ($authors as $author) {
            $bio = trim((string) get_user_meta($author->ID, '_user_short_bio', true));

            if ('' === $bio) {
                $bio = trim((string) get_user_meta($author->ID, 'description', true));
            }

            if ('' === $bio) {
                $missing_bio[] = $author->display_name;
            }

            if (!self::has_real_avatar($author)) {
                $missing_photo[] = $author->display_name;
            }

            $social = trim((string) get_user_meta($author->ID, 'linkedin', true))
                . trim((string) get_user_meta($author->ID, 'twitter', true))
                . trim((string) get_user_meta($author->ID, 'facebook', true))
                . trim((string) get_user_meta($author->ID, 'instagram', true))
                . trim((string) get_user_meta($author->ID, 'youtube', true));

            if ('' === $social) {
                $missing_social[] = $author->display_name;
            }

            if ('' === trim((string) get_user_meta($author->ID, 'job_title', true))) {
                $missing_role[] = $author->display_name;
            }
        }

        $checks[] = self::people_check(
            'author_bio',
            __('Every author has a bio', 'enhanced-content-plugin'),
            __('A byline with no person behind it is just a name. The bio is where expertise gets claimed — and it feeds the author schema search engines read.', 'enhanced-content-plugin'),
            $missing_bio,
            $total,
            self::FAIL
        );

        $checks[] = self::people_check(
            'author_photo',
            __('Every author has a real photo', 'enhanced-content-plugin'),
            __('A default silhouette avatar reads as "this person may not exist". A real face is the cheapest trust signal there is.', 'enhanced-content-plugin'),
            $missing_photo,
            $total,
            self::FAIL
        );

        $checks[] = self::people_check(
            'author_social',
            __('Every author links a verifiable profile', 'enhanced-content-plugin'),
            __('LinkedIn or X lets a reader confirm the author is a real person with a real history — social proof no bio paragraph can fake.', 'enhanced-content-plugin'),
            $missing_social,
            $total,
            self::FAIL
        );

        $checks[] = self::authority_check();

        $checks[] = self::people_check(
            'author_role',
            __('Every author states a role', 'enhanced-content-plugin'),
            __('"Founder", "Installer for 12 years", "Product engineer" — the role is why this person is worth listening to on this topic.', 'enhanced-content-plugin'),
            $missing_role,
            $total,
            self::WARN
        );

        return $checks;
    }

    /**
     * Does the plumbing article carry the plumber's byline? For every
     * substantial topic, at least one of its authors' bios or roles
     * should claim that ground. Where none does, either the bios are
     * underselling real expertise (fix the bio) or the wrong person is
     * on the byline (fix the byline) — both are the owner's call.
     */
    private static function authority_check() {
        global $wpdb;

        $unclaimed = array();

        if (ECP_DB::tables_exist()) {
            $topics = (array) $wpdb->get_results(
                'SELECT topic, GROUP_CONCAT(DISTINCT author_id) AS authors, COUNT(*) AS pages
                   FROM ' . ECP_DB::inventory_table() . "
                  WHERE post_status = 'publish' AND topic != '' AND author_id > 0
                  GROUP BY topic
                 HAVING pages >= 3",
                ARRAY_A
            );

            foreach ($topics as $row) {
                $claimed = false;

                foreach (array_filter(array_map('intval', explode(',', $row['authors']))) as $author_id) {
                    $claim = get_user_meta($author_id, '_user_short_bio', true) . ' '
                        . get_user_meta($author_id, 'description', true) . ' '
                        . get_user_meta($author_id, 'job_title', true);

                    if (ECP_Topical_Map::overlap($row['topic'], $claim) >= 0.34) {
                        $claimed = true;
                        break;
                    }
                }

                if (!$claimed) {
                    $unclaimed[] = $row['topic'];
                }
            }
        }

        return array(
            'id'        => 'author_topic_authority',
            'group'     => 'authors',
            'label'     => __('Authors claim the topics they cover', 'enhanced-content-plugin'),
            'status'    => $unclaimed ? self::WARN : self::PASS,
            'detail'    => $unclaimed
                ? sprintf(
                    /* translators: %s: topic list */
                    __('No author bio or role claims expertise on: %s. Either the bios undersell real experience — update them to claim it — or the wrong byline is on those articles.', 'enhanced-content-plugin'),
                    implode(', ', array_slice($unclaimed, 0, 4))
                )
                : __('Every substantial topic has at least one author whose bio or role claims that ground.', 'enhanced-content-plugin'),
            'fix_label' => __('Edit user profiles', 'enhanced-content-plugin'),
            'fix_url'   => admin_url('users.php'),
        );
    }

    /**
     * One authors check row: pass when nobody is missing it.
     */
    private static function people_check($id, $label, $why, array $missing, $total, $fail_status) {
        $count = count($missing);

        return array(
            'id'        => $id,
            'group'     => 'authors',
            'label'     => $label,
            'status'    => 0 === $count ? self::PASS : $fail_status,
            'detail'    => 0 === $count
                ? sprintf(
                    /* translators: %d: author count */
                    _n('All %d author has this.', 'All %d authors have this.', $total, 'enhanced-content-plugin'),
                    $total
                )
                : sprintf(
                    /* translators: 1: how many are missing it, 2: total, 3: names */
                    __('%1$d of %2$d missing: %3$s. %4$s', 'enhanced-content-plugin'),
                    $count,
                    $total,
                    implode(', ', array_slice($missing, 0, 5)),
                    $why
                ),
            'fix_label' => __('Edit user profiles', 'enhanced-content-plugin'),
            'fix_url'   => admin_url('users.php'),
        );
    }

    /**
     * Whether the author's avatar is a real image rather than the
     * default silhouette. Gravatar answers 404 when asked not to fall
     * back to a default; local-avatar plugins store an attachment in
     * user meta. One HTTP probe per author, cached a week.
     */
    private static function has_real_avatar($user) {
        // Local avatar plugins (Simple Local Avatars, WP User Avatar).
        if (get_user_meta($user->ID, 'simple_local_avatar', true) || get_user_meta($user->ID, 'wp_user_avatar', true)) {
            return true;
        }

        $cache_key = 'ecp_avatar_' . $user->ID;
        $cached = get_transient($cache_key);

        if (false !== $cached) {
            return 'yes' === $cached;
        }

        $probe = add_query_arg('d', '404', get_avatar_url($user->ID, array('size' => 32)));
        $response = wp_remote_head($probe, array('timeout' => 5));

        $has = !is_wp_error($response) && 200 === (int) wp_remote_retrieve_response_code($response);

        set_transient($cache_key, $has ? 'yes' : 'no', WEEK_IN_SECONDS);

        return $has;
    }

    /* --------------------------------------------------------------------
     * On-article trust
     * ----------------------------------------------------------------- */

    private static function article_checks() {
        global $wpdb;

        $checks = array();

        // The plugin's own byline (name, photo, dates) on the content types
        // the agent manages.
        $agent_types = (array) ECP_Agent_Settings::get('post_types', array('post'));
        $display_types = (array) ECP_Settings::get_setting('enabled_post_types', array('post'));
        $uncovered = array_diff($agent_types, $display_types);

        $checks[] = array(
            'id'        => 'byline_display',
            'group'     => 'articles',
            'label'     => __('Articles show an author byline with dates', 'enhanced-content-plugin'),
            'status'    => $uncovered ? self::FAIL : self::PASS,
            'detail'    => $uncovered
                ? sprintf(
                    /* translators: %s: post type list */
                    __('The plugin byline (author, photo, published and updated dates) is not enabled for: %s. An undated, unattributed article is unverifiable by definition.', 'enhanced-content-plugin'),
                    implode(', ', $uncovered)
                )
                : __('The plugin byline — author, photo, published and updated dates — covers every managed content type.', 'enhanced-content-plugin'),
            'fix_label' => __('Display settings', 'enhanced-content-plugin'),
            'fix_url'   => admin_url('options-general.php?page=ecp-settings'),
        );

        // Undisclosed affiliate links, from the scanner's stored findings.
        $undisclosed = 0;

        if (ECP_DB::tables_exist()) {
            $undisclosed = (int) $wpdb->get_var(
                'SELECT COUNT(*) FROM ' . ECP_DB::opportunities_table() . "
                  WHERE reasons LIKE '%no_affiliate_disclosure%'"
            );
        }

        $checks[] = array(
            'id'        => 'affiliate_disclosures',
            'group'     => 'articles',
            'label'     => __('Every article with affiliate links discloses them', 'enhanced-content-plugin'),
            'status'    => $undisclosed > 0 ? self::FAIL : self::PASS,
            'detail'    => $undisclosed > 0
                ? sprintf(
                    /* translators: %d: page count */
                    _n('%d page earns from affiliate links without saying so — an FTC problem and a trust problem in one.', '%d pages earn from affiliate links without saying so — an FTC problem and a trust problem in one.', $undisclosed, 'enhanced-content-plugin'),
                    $undisclosed
                )
                : __('The scanner found no article with affiliate links missing a disclosure.', 'enhanced-content-plugin'),
            'fix_label' => __('See the pages', 'enhanced-content-plugin'),
            'fix_url'   => admin_url('admin.php?page=ecp-opportunities'),
        );

        return $checks;
    }

    /* --------------------------------------------------------------------
     * Site policies
     * ----------------------------------------------------------------- */

    private static function policy_checks() {
        $checks = array();

        // Privacy: WordPress has a canonical setting for this one. The
        // common failure is not a missing page — it is a setting still
        // pointing at a page deleted in some long-ago redesign, while a
        // perfectly good privacy page sits unselected. Say which one.
        $privacy_id = (int) get_option('wp_page_for_privacy_policy');
        $privacy_ok = $privacy_id && 'publish' === get_post_status($privacy_id);

        $orphan_privacy = null;

        if (!$privacy_ok) {
            foreach (array('privacy-policy', 'privacy') as $slug) {
                $page = get_page_by_path($slug);

                if ($page && 'publish' === $page->post_status) {
                    $orphan_privacy = $page;
                    break;
                }
            }
        }

        if ($privacy_ok) {
            $privacy_detail = __('Published and registered in WordPress\'s privacy setting.', 'enhanced-content-plugin');
        } elseif ($orphan_privacy) {
            $privacy_detail = sprintf(
                /* translators: %s: page title */
                __('A page named "%s" exists, but WordPress\'s privacy setting does not point at it — likely stale since an old privacy page was deleted. Open Settings → Privacy, pick it in the dropdown, and click "Use This Page".', 'enhanced-content-plugin'),
                $orphan_privacy->post_title
            );
        } else {
            $privacy_detail = __('Missing or unpublished. Legally required in most jurisdictions, and its absence is a machine-readable distrust signal.', 'enhanced-content-plugin');
        }

        $checks[] = array(
            'id'        => 'privacy_page',
            'group'     => 'policies',
            'label'     => __('Privacy policy, published and set', 'enhanced-content-plugin'),
            'status'    => $privacy_ok ? self::PASS : self::FAIL,
            'detail'    => $privacy_detail,
            'fix_label' => __('Privacy settings', 'enhanced-content-plugin'),
            'fix_url'   => admin_url('options-privacy.php'),
        );

        $checks[] = self::page_check(
            'about_page',
            __('About page', 'enhanced-content-plugin'),
            array('about', 'about-us', 'our-story', 'who-we-are'),
            __('Who runs this site and why they know what they are talking about. The page every quality rater looks for first.', 'enhanced-content-plugin'),
            self::FAIL
        );

        $checks[] = self::page_check(
            'contact_page',
            __('Contact page', 'enhanced-content-plugin'),
            array('contact', 'contact-us'),
            __('A real business can be reached. No contact route reads as nothing behind the site.', 'enhanced-content-plugin'),
            self::FAIL
        );

        $checks[] = self::page_check(
            'editorial_policy',
            __('Editorial policy', 'enhanced-content-plugin'),
            array('editorial-policy', 'editorial-guidelines', 'editorial-standards', 'how-we-write'),
            __('How content gets researched, written, fact-checked and corrected. One page, linked from the footer, cited by every byline.', 'enhanced-content-plugin'),
            self::WARN
        );

        // Review policy — only demanded of sites that actually review.
        $reviews = self::site_does_reviews();

        if ($reviews) {
            $checks[] = self::page_check(
                'review_policy',
                __('Review policy', 'enhanced-content-plugin'),
                array('review-policy', 'how-we-review', 'how-we-test', 'review-process'),
                __('This site publishes reviews, so readers deserve to know how: what gets tested, whether products are bought or supplied, and how scoring works.', 'enhanced-content-plugin'),
                self::FAIL
            );
        } else {
            $checks[] = array(
                'id'        => 'review_policy',
                'group'     => 'policies',
                'label'     => __('Review policy', 'enhanced-content-plugin'),
                'status'    => self::NA,
                'detail'    => __('Not applicable — the site does not appear to publish reviews. This activates automatically if that changes.', 'enhanced-content-plugin'),
                'fix_label' => '',
                'fix_url'   => '',
            );
        }

        // Affiliate disclosure page — only demanded of sites with affiliate links.
        global $wpdb;
        $has_affiliate = false;

        if (ECP_DB::tables_exist()) {
            $has_affiliate = (bool) $wpdb->get_var(
                'SELECT COUNT(*) FROM ' . ECP_DB::opportunities_table() . "
                  WHERE signals LIKE '%affiliate%' OR reasons LIKE '%affiliate%' LIMIT 1"
            );
        }

        if ($has_affiliate) {
            $checks[] = self::page_check(
                'affiliate_disclosure_page',
                __('Affiliate disclosure page', 'enhanced-content-plugin'),
                array('affiliate-disclosure', 'affiliate-disclaimer', 'disclosure', 'disclaimer', 'advertising-disclosure'),
                __('The site earns from affiliate links; a standing disclosure page (plus the per-article notices) is how that stays honest.', 'enhanced-content-plugin'),
                self::FAIL
            );
        } else {
            $checks[] = array(
                'id'        => 'affiliate_disclosure_page',
                'group'     => 'policies',
                'label'     => __('Affiliate disclosure page', 'enhanced-content-plugin'),
                'status'    => self::NA,
                'detail'    => __('Not applicable — the scanner has not found affiliate links on the site. This activates automatically if that changes.', 'enhanced-content-plugin'),
                'fix_label' => '',
                'fix_url'   => '',
            );
        }

        // Findable, not just existing: a policy nothing links to might as
        // well not exist. Every policy page that does exist should be in
        // some menu — footers are where readers and raters look.
        $unlinked = array();
        $linked_ids = self::menu_linked_page_ids();

        $privacy_id = (int) get_option('wp_page_for_privacy_policy');
        $existing = array();

        if ($privacy_id && 'publish' === get_post_status($privacy_id)) {
            $existing[$privacy_id] = get_the_title($privacy_id);
        }

        $slug_sets = array(
            array('about', 'about-us', 'our-story', 'who-we-are'),
            array('contact', 'contact-us'),
            array('editorial-policy', 'editorial-guidelines', 'editorial-standards', 'how-we-write'),
            array('review-policy', 'how-we-review', 'how-we-test', 'review-process'),
            array('affiliate-disclosure', 'affiliate-disclaimer', 'disclosure', 'disclaimer', 'advertising-disclosure'),
        );

        foreach ($slug_sets as $slugs) {
            foreach ($slugs as $slug) {
                $page = get_page_by_path($slug);

                if ($page && 'publish' === $page->post_status) {
                    $existing[$page->ID] = $page->post_title;
                    break;
                }
            }
        }

        foreach ($existing as $page_id => $title) {
            if (!in_array((int) $page_id, $linked_ids, true)) {
                $unlinked[] = $title;
            }
        }

        if ($existing) {
            $checks[] = array(
                'id'        => 'policies_findable',
                'group'     => 'policies',
                'label'     => __('Policy pages are linked from a menu', 'enhanced-content-plugin'),
                'status'    => $unlinked ? self::WARN : self::PASS,
                'detail'    => $unlinked
                    ? sprintf(
                        /* translators: %s: page titles */
                        __('These exist but no menu links to them: %s. A policy nobody can find might as well not exist — add them to the footer menu.', 'enhanced-content-plugin'),
                        implode(', ', $unlinked)
                    )
                    : __('Every policy page is reachable from a menu.', 'enhanced-content-plugin'),
                'fix_label' => __('Edit menus', 'enhanced-content-plugin'),
                'fix_url'   => admin_url('nav-menus.php'),
            );
        }

        return $checks;
    }

    /**
     * Page ids linked from any classic menu or block navigation.
     *
     * @return int[]
     */
    private static function menu_linked_page_ids() {
        $ids = array();

        foreach (wp_get_nav_menus() as $menu) {
            foreach ((array) wp_get_nav_menu_items($menu) as $item) {
                if ('page' === $item->object) {
                    $ids[] = (int) $item->object_id;
                }
            }
        }

        // Block themes keep navigation in wp_navigation posts, where a
        // page link serialises as "id":123 in the block attributes.
        $navs = get_posts(array(
            'post_type'      => 'wp_navigation',
            'post_status'    => 'publish',
            'posts_per_page' => 10,
        ));

        foreach ($navs as $nav) {
            if (preg_match_all('/"id":(\d+)/', $nav->post_content, $matches)) {
                foreach ($matches[1] as $id) {
                    $ids[] = (int) $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * A policy-page existence check by the slugs such pages actually use.
     */
    private static function page_check($id, $label, array $slugs, $why, $fail_status) {
        $found = null;

        foreach ($slugs as $slug) {
            $page = get_page_by_path($slug);

            if ($page && 'publish' === $page->post_status) {
                $found = $page;
                break;
            }
        }

        return array(
            'id'        => $id,
            'group'     => 'policies',
            'label'     => $label,
            'status'    => $found ? self::PASS : $fail_status,
            'detail'    => $found
                ? sprintf(
                    /* translators: %s: page title */
                    __('Found: "%s".', 'enhanced-content-plugin'),
                    $found->post_title
                )
                : $why,
            'fix_label' => $found ? __('View page', 'enhanced-content-plugin') : __('Create the page', 'enhanced-content-plugin'),
            'fix_url'   => $found ? get_edit_post_link($found->ID, 'raw') : admin_url('post-new.php?post_type=page'),
        );
    }

    /**
     * Does this site publish reviews? Judged from its own inventory:
     * review-shaped titles or Review schema.
     */
    private static function site_does_reviews() {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return false;
        }

        $count = (int) $wpdb->get_var(
            'SELECT COUNT(*) FROM ' . ECP_DB::inventory_table() . "
              WHERE post_status = 'publish'
                AND (title LIKE '%review%' OR schema_types LIKE '%Review%')"
        );

        return $count >= 3;
    }

    /**
     * Re-evaluate one check by id, for the roadmap's done-crediting.
     *
     * @return bool True when the check now passes (or stopped applying).
     */
    public static function check_passes($id) {
        foreach (self::run(true) as $check) {
            if ($check['id'] === $id) {
                return in_array($check['status'], array(self::PASS, self::NA), true);
            }
        }

        return true;   // A check that no longer exists is nobody's problem.
    }

    public static function status_label($status) {
        $labels = array(
            self::PASS => __('In place', 'enhanced-content-plugin'),
            self::WARN => __('Worth adding', 'enhanced-content-plugin'),
            self::FAIL => __('Missing', 'enhanced-content-plugin'),
            self::NA   => __('Not applicable', 'enhanced-content-plugin'),
        );

        return isset($labels[$status]) ? $labels[$status] : $status;
    }

    public static function group_label($group) {
        $labels = array(
            'site'     => __('The site itself', 'enhanced-content-plugin'),
            'authors'  => __('The people behind the content', 'enhanced-content-plugin'),
            'articles' => __('Trust on every article', 'enhanced-content-plugin'),
            'policies' => __('Site policies', 'enhanced-content-plugin'),
        );

        return isset($labels[$group]) ? $labels[$group] : $group;
    }
}
