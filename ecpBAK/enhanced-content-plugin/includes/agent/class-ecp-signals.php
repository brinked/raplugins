<?php
/**
 * On-page signal extraction.
 *
 * Everything the agent can know about a post without calling an AI model or
 * an external API. The opportunity engine scores from these; the analyzer
 * ships them to the model as evidence so it recommends against facts rather
 * than vibes.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ECP_Signals {

    /**
     * Collect every on-page signal for a post.
     *
     * @param WP_Post|int $post
     * @return array
     */
    public static function collect($post) {
        $post = get_post($post);
        if (!$post) {
            return array();
        }

        $content = (string) $post->post_content;
        $text = ECP_Content_Map::to_text($content);
        $sections = ECP_Content_Map::sections($post);

        $signals = array(
            'post_id'        => (int) $post->ID,
            'post_type'      => $post->post_type,
            'title'          => $post->post_title,
            'permalink'      => get_permalink($post),
            'slug'           => $post->post_name,
            'status'         => $post->post_status,
            'published_at'   => $post->post_date_gmt,
            'modified_at'    => $post->post_modified_gmt,
            'age_days'       => self::days_since($post->post_date_gmt),
            'days_since_update' => self::days_since($post->post_modified_gmt),
            'word_count'     => ECP_Content_Map::word_count($text),
            'section_count'  => count($sections),
            'excerpt'        => $post->post_excerpt,
        );

        $signals = array_merge(
            $signals,
            self::heading_signals($sections),
            self::link_signals($content, $post),
            self::image_signals($content, $post),
            self::meta_signals($post),
            self::editorial_signals($post),
            self::readability_signals($text),
            self::structure_signals($sections),
            self::freshness_signals($post, $text),
            self::trust_signals($content, $text, $post)
        );

        /**
         * Filter the on-page signals collected for a post.
         *
         * @param array   $signals
         * @param WP_Post $post
         */
        return apply_filters('ecp_post_signals', $signals, $post);
    }

    /* --------------------------------------------------------------------
     * Individual signal groups
     * ----------------------------------------------------------------- */

    private static function heading_signals($sections) {
        $levels = array();
        $thin = array();
        $bloated = array();
        $headings = array();

        foreach ($sections as $section) {
            if ($section['is_intro']) {
                continue;
            }

            $levels[] = $section['level'];
            $headings[] = $section['heading'];

            if ($section['words'] < 40) {
                $thin[] = $section['heading'];
            }
            if ($section['words'] > 600) {
                $bloated[] = $section['heading'];
            }
        }

        // A jump from h2 straight to h4 confuses both readers and parsers.
        $skipped = false;
        for ($i = 1, $len = count($levels); $i < $len; $i++) {
            if ($levels[$i] - $levels[$i - 1] > 1) {
                $skipped = true;
                break;
            }
        }

        return array(
            'heading_count'    => count($headings),
            'headings'         => $headings,
            'has_h2'           => in_array(2, $levels, true),
            'skipped_heading_level' => $skipped,
            'thin_sections'    => $thin,
            'bloated_sections' => $bloated,
        );
    }

    private static function link_signals($content, $post) {
        $home = wp_parse_url(home_url(), PHP_URL_HOST);
        $internal = 0;
        $external = 0;
        $nofollow_external = 0;
        $targets = array();
        $generic_anchors = array();

        // Anchor text that tells a reader (and a crawler) nothing.
        $generic = array('click here', 'here', 'read more', 'this', 'link', 'more', 'this page', 'learn more');

        if (preg_match_all('/<a\b([^>]*)>(.*?)<\/a>/is', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $attrs = $match[1];
                $anchor = trim(wp_strip_all_tags($match[2]));

                if (!preg_match('/href\s*=\s*["\']([^"\']+)["\']/i', $attrs, $href_match)) {
                    continue;
                }

                $href = $href_match[1];
                $host = wp_parse_url($href, PHP_URL_HOST);

                if (!$host || $host === $home) {
                    $internal++;
                    $targets[] = $href;
                } else {
                    $external++;
                    if (false !== stripos($attrs, 'nofollow')) {
                        $nofollow_external++;
                    }
                }

                if ($anchor && in_array(strtolower($anchor), $generic, true)) {
                    $generic_anchors[] = $anchor;
                }
            }
        }

        return array(
            'internal_links'      => $internal,
            'external_links'      => $external,
            'nofollow_externals'  => $nofollow_external,
            'internal_link_targets' => array_values(array_unique($targets)),
            'generic_anchor_count' => count($generic_anchors),
            'inbound_internal_links' => self::count_inbound_links($post),
        );
    }

    /**
     * How many other posts link to this one.
     *
     * A LIKE scan over post_content is expensive on large sites, so the result
     * is cached per post and invalidated by the scan job rather than computed
     * on every request.
     */
    private static function count_inbound_links($post) {
        global $wpdb;

        $cache_key = 'ecp_inbound_' . $post->ID;
        $cached = get_transient($cache_key);
        if (false !== $cached) {
            return (int) $cached;
        }

        $permalink = get_permalink($post);
        if (!$permalink) {
            return 0;
        }

        // Match on the path only: protocol and host vary between environments
        // and after a migration.
        $path = wp_parse_url($permalink, PHP_URL_PATH);
        if (!$path || '/' === $path) {
            return 0;
        }

        $like = '%' . $wpdb->esc_like($path) . '%';

        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_status = 'publish'
               AND post_type IN ('post','page')
               AND ID != %d
               AND post_content LIKE %s",
            $post->ID,
            $like
        ));

        set_transient($cache_key, $count, 12 * HOUR_IN_SECONDS);

        return $count;
    }

    private static function image_signals($content, $post) {
        $total = 0;
        $missing_alt = array();

        if (preg_match_all('/<img\b([^>]*)>/i', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $total++;
                $attrs = $match[1];

                if (!preg_match('/\balt\s*=\s*["\']([^"\']*)["\']/i', $attrs, $alt_match) || '' === trim($alt_match[1])) {
                    preg_match('/\bsrc\s*=\s*["\']([^"\']+)["\']/i', $attrs, $src_match);
                    $missing_alt[] = isset($src_match[1]) ? $src_match[1] : '';
                }
            }
        }

        $thumb_id = get_post_thumbnail_id($post);
        $thumb_alt = $thumb_id ? trim((string) get_post_meta($thumb_id, '_wp_attachment_image_alt', true)) : '';

        return array(
            'image_count'         => $total,
            'images_missing_alt'  => array_values(array_filter($missing_alt)),
            'has_featured_image'  => (bool) $thumb_id,
            'featured_image_alt'  => $thumb_alt,
            'featured_image_missing_alt' => (bool) $thumb_id && '' === $thumb_alt,
        );
    }

    /**
     * SEO title / meta description, read from whichever SEO plugin is active.
     *
     * We read but never assume: if no SEO plugin owns the field, the agent
     * proposes the excerpt instead and says so.
     */
    private static function meta_signals($post) {
        $seo_title = '';
        $seo_description = '';
        $owner = 'none';

        $sources = array(
            'yoast'    => array('_yoast_wpseo_title', '_yoast_wpseo_metadesc'),
            'rankmath' => array('rank_math_title', 'rank_math_description'),
            'aioseo'   => array('_aioseo_title', '_aioseo_description'),
            'seopress' => array('_seopress_titles_title', '_seopress_titles_desc'),
        );

        foreach ($sources as $plugin => $keys) {
            $title = get_post_meta($post->ID, $keys[0], true);
            $desc = get_post_meta($post->ID, $keys[1], true);

            if ($title || $desc) {
                $seo_title = (string) $title;
                $seo_description = (string) $desc;
                $owner = $plugin;
                break;
            }
        }

        // Even with no stored value, note which plugin *would* own the field
        // so the applier writes to the right meta key.
        if ('none' === $owner) {
            $owner = self::detect_seo_plugin();
        }

        // Resolve template variables before anything downstream reads these.
        // A stored value of "%%title%% %%page%%" is not an 18-character
        // title — it is however the site renders it, and that is what the
        // analyzer, the length checks and the previews must all see.
        $seo_title = self::resolve_seo_template($seo_title, $post);
        $seo_description = self::resolve_seo_template($seo_description, $post);

        // Resolve template variables before anything downstream reads these.
        // A stored value of "%%title%% %%page%%" is not an 18-character
        // title — it is however the SEO plugin renders it, and that is what
        // the analyzer, the length checks and the previews must see.
        $seo_title = self::resolve_seo_template($seo_title, $post);
        $seo_description = self::resolve_seo_template($seo_description, $post);

        $description = $seo_description ? $seo_description : (string) $post->post_excerpt;

        $indexability = self::indexability_signals($post, $owner);

        return array_merge(array(
            'seo_plugin'        => $owner,
            'seo_title'         => $seo_title,
            'seo_title_length'  => mb_strlen($seo_title ? $seo_title : $post->post_title),
            'seo_description'   => $seo_description,
            'effective_description' => $description,
            'description_length' => mb_strlen($description),
            'has_meta_description' => '' !== trim($description),
            'has_excerpt'       => '' !== trim((string) $post->post_excerpt),
        ), $indexability);
    }

    /**
     * Can this page appear in search results at all?
     *
     * The most expensive blind spot a content tool can have: everything else
     * here optimises a page on the assumption Google is allowed to show it.
     * A stray noindex — one checkbox in an SEO plugin — makes every other
     * improvement worthless, and nothing on the rendered page says so.
     *
     * @param WP_Post $post
     * @param string  $plugin yoast|rankmath|aioseo|seopress|none
     * @return array { noindexed, canonical_url, canonical_elsewhere }
     */
    private static function indexability_signals($post, $plugin) {
        $noindex = false;
        $canonical = '';

        switch ($plugin) {
            case 'yoast':
                // 1 = noindex, 2 = index, '' = site default.
                $noindex = '1' === (string) get_post_meta($post->ID, '_yoast_wpseo_meta-robots-noindex', true);
                $canonical = (string) get_post_meta($post->ID, '_yoast_wpseo_canonical', true);
                break;

            case 'rankmath':
                $robots = get_post_meta($post->ID, 'rank_math_robots', true);
                $noindex = is_array($robots) && in_array('noindex', $robots, true);
                $canonical = (string) get_post_meta($post->ID, 'rank_math_canonical_url', true);
                break;

            case 'seopress':
                $noindex = 'yes' === (string) get_post_meta($post->ID, '_seopress_robots_index', true);
                $canonical = (string) get_post_meta($post->ID, '_seopress_robots_canonical', true);
                break;

            case 'aioseo':
                // AIOSEO keeps robots settings in its own tables; the meta
                // key exists only on some versions. Absence means "not
                // detectable", never "indexed" — so only a positive value
                // is trusted.
                $noindex = (bool) get_post_meta($post->ID, '_aioseo_noindex', true);
                break;
        }

        // A password or non-public status blocks indexing regardless of any
        // SEO plugin.
        if (!empty($post->post_password) || 'private' === $post->post_status) {
            $noindex = true;
        }

        $canonical = trim($canonical);
        $canonical_elsewhere = false;

        if ('' !== $canonical) {
            $self = untrailingslashit((string) get_permalink($post));
            $canonical_elsewhere = untrailingslashit($canonical) !== $self;
        }

        return array(
            'noindexed'           => $noindex,
            'canonical_url'       => $canonical,
            'canonical_elsewhere' => $canonical_elsewhere,
        );
    }

    /**
     * Resolve SEO-plugin template variables to what a searcher actually sees.
     *
     * Yoast, Rank Math and SEOPress store per-post SEO titles that may be
     * templates — "%%title%% %%page%%" — rather than literal text; AIOSEO
     * does the same with #hash tags. Reading the meta raw put the template
     * itself into the analyzer's prompt, the guardrail length checks and the
     * SERP preview, which then displayed "%%title%% %%page%%" to the user as
     * if it were the live snippet.
     *
     * This resolves the common variables and strips any unrecognised ones —
     * an unknown variable renders as nothing in the real SERP too.
     *
     * @param string          $value
     * @param WP_Post|int $post
     * @return string
     */
    public static function resolve_seo_template($value, $post) {
        $value = (string) $value;

        if ('' === $value || (false === strpos($value, '%%') && false === strpos($value, '#'))) {
            return $value;
        }

        $post = get_post($post);

        if (!$post) {
            return $value;
        }

        $category = '';
        $categories = get_the_category($post->ID);

        if (!empty($categories) && !is_wp_error($categories)) {
            $category = $categories[0]->name;
        }

        $author = get_userdata((int) $post->post_author);
        $sep = apply_filters('ecp_seo_title_separator', '-');

        $map = array(
            // Yoast / Rank Math / SEOPress style.
            '%%title%%'            => $post->post_title,
            '%%post_title%%'       => $post->post_title,
            '%%page%%'             => '',   // Page number — empty on page one, which is what SERPs show.
            '%%pagenumber%%'       => '',
            '%%pagetotal%%'        => '',
            '%%sep%%'              => $sep,
            '%%sitename%%'         => get_bloginfo('name'),
            '%%site_title%%'       => get_bloginfo('name'),
            '%%sitedesc%%'         => get_bloginfo('description'),
            '%%tagline%%'          => get_bloginfo('description'),
            '%%excerpt%%'          => $post->post_excerpt ? $post->post_excerpt : wp_trim_words(wp_strip_all_tags($post->post_content), 25, ''),
            '%%excerpt_only%%'     => $post->post_excerpt,
            '%%post_excerpt%%'     => $post->post_excerpt,
            '%%category%%'         => $category,
            '%%primary_category%%' => $category,
            '%%currentyear%%'      => date_i18n('Y'),
            '%%currentmonth%%'     => date_i18n('F'),
            '%%currentdate%%'      => date_i18n(get_option('date_format')),
            '%%date%%'             => mysql2date(get_option('date_format'), $post->post_date),
            '%%modified%%'         => mysql2date(get_option('date_format'), $post->post_modified),
            '%%name%%'             => $author ? $author->display_name : '',
            '%%author%%'           => $author ? $author->display_name : '',
            // AIOSEO style.
            '#post_title'          => $post->post_title,
            '#site_title'          => get_bloginfo('name'),
            '#tagline'             => get_bloginfo('description'),
            '#separator_sa'        => $sep,
            '#author_name'         => $author ? $author->display_name : '',
            '#current_year'        => date_i18n('Y'),
        );

        $value = str_ireplace(array_keys($map), array_values($map), $value);

        // Anything unrecognised renders as nothing in the real snippet too.
        $value = preg_replace('/%%[a-z0-9_-]+%%/i', '', $value);

        // Collapse the whitespace and dangling separators the substitutions
        // leave behind. Regex rather than trim(): trim's character list is
        // byte-wise and would chew partial UTF-8 sequences off legitimate
        // multibyte endings.
        $value = preg_replace('/\s{2,}/u', ' ', (string) $value);
        $value = preg_replace('/^[\s\x{2013}\x{2014}\x{00B7}\x{2022}|:\-]+|[\s\x{2013}\x{2014}\x{00B7}\x{2022}|:\-]+$/u', '', (string) $value);

        return (string) $value;
    }

    /**
     * @return string yoast|rankmath|aioseo|seopress|none
     */
    public static function detect_seo_plugin() {
        if (defined('WPSEO_VERSION') || class_exists('WPSEO_Options')) {
            return 'yoast';
        }
        if (defined('RANK_MATH_VERSION') || class_exists('RankMath')) {
            return 'rankmath';
        }
        if (defined('AIOSEO_VERSION') || function_exists('aioseo')) {
            return 'aioseo';
        }
        if (defined('SEOPRESS_VERSION')) {
            return 'seopress';
        }

        return 'none';
    }

    /**
     * The meta keys the active SEO plugin uses, so the applier writes where
     * the site actually reads from.
     *
     * @return array{title:string,description:string}|null
     */
    public static function seo_meta_keys($plugin = null) {
        $plugin = $plugin ? $plugin : self::detect_seo_plugin();

        $map = array(
            'yoast'    => array('title' => '_yoast_wpseo_title', 'description' => '_yoast_wpseo_metadesc'),
            'rankmath' => array('title' => 'rank_math_title', 'description' => 'rank_math_description'),
            'aioseo'   => array('title' => '_aioseo_title', 'description' => '_aioseo_description'),
            'seopress' => array('title' => '_seopress_titles_title', 'description' => '_seopress_titles_desc'),
        );

        return isset($map[$plugin]) ? $map[$plugin] : null;
    }

    /**
     * Trust signals owned by this plugin's own editorial toolkit.
     */
    private static function editorial_signals($post) {
        $sources = get_post_meta($post->ID, '_article_sources', true);

        // All three contributor roles live in one meta array keyed
        // authors / reviewers / fact_checkers — see ECP_Frontend_Display.
        $contributors = get_post_meta($post->ID, '_article_contributors', true);
        $contributors = is_array($contributors) ? $contributors : array();

        $role = function ($key) use ($contributors) {
            return isset($contributors[$key]) && is_array($contributors[$key]) ? $contributors[$key] : array();
        };

        // get_faq_data() returns { enabled, title, items } — the questions
        // are under 'items'.
        $faq = class_exists('ECP_FAQ') ? ECP_FAQ::get_faq_data($post->ID) : array('items' => array());
        $faq_items = isset($faq['items']) && is_array($faq['items']) ? $faq['items'] : array();

        return array(
            'source_count'      => is_array($sources) ? count($sources) : 0,
            'sources'           => is_array($sources) ? $sources : array(),
            'author_count'      => count($role('authors')),
            'has_reviewer'      => (bool) $role('reviewers'),
            'has_fact_checker'  => (bool) $role('fact_checkers'),
            'faq_count'         => count($faq_items),
            'has_faq'           => !empty($faq_items),
        );
    }

    /**
     * Cheap readability proxies. Not a Flesch score — just the two things that
     * most reliably signal a wall of text.
     */
    private static function readability_signals($text) {
        $paragraphs = preg_split('/\n{2,}/', $text, -1, PREG_SPLIT_NO_EMPTY);
        $paragraphs = array_values(array_filter(array_map('trim', (array) $paragraphs)));

        $sentence_count = max(1, (int) preg_match_all('/[.!?](?:\s|$)/u', $text, $ignored));
        $word_count = ECP_Content_Map::word_count($text);

        $long_paragraphs = 0;
        foreach ($paragraphs as $paragraph) {
            if (ECP_Content_Map::word_count($paragraph) > 150) {
                $long_paragraphs++;
            }
        }

        return array(
            'paragraph_count'      => count($paragraphs),
            'avg_sentence_words'   => $word_count ? round($word_count / $sentence_count, 1) : 0,
            'long_paragraph_count' => $long_paragraphs,
        );
    }

    private static function structure_signals($sections) {
        $intro_words = 0;
        foreach ($sections as $section) {
            if ($section['is_intro']) {
                $intro_words = $section['words'];
                break;
            }
        }

        $outline = array();
        foreach ($sections as $section) {
            $outline[] = array(
                'id'      => $section['id'],
                'heading' => $section['is_intro'] ? '(introduction)' : $section['heading'],
                'level'   => $section['level'],
                'words'   => $section['words'],
            );
        }

        return array(
            'intro_words' => $intro_words,
            'has_intro'   => $intro_words > 0,
            'outline'     => $outline,
        );
    }

    /* --------------------------------------------------------------------
     * Issue derivation
     * ----------------------------------------------------------------- */

    /**
     * Turn raw signals into a list of concrete, named issues.
     *
     * These are deterministic — no AI involved — which means the dashboard can
     * show a useful audit before a single API call is made, and the model gets
     * a checklist rather than an open-ended "make this better".
     *
     * @param array $signals
     * @return array[] { code, severity, label, detail, fix_types }
     */
    /**
     * Evidence that the article's content has aged.
     *
     * "Last modified 400 days ago" says a page is old; it does not say what
     * in it is old. These excerpts are the difference between the agent
     * proposing "refresh this article" (useless) and "this sentence says
     * 'as of 2023' and this one cites 'the latest 2022 study'" (actionable).
     *
     * @param WP_Post $post
     * @param string  $text Plain text of the content.
     * @return array { title_year, dated_excerpts, recency_excerpts }
     */
    private static function freshness_signals($post, $text) {
        $current_year = (int) current_time('Y');

        // A year in the title is a promise of currency. "Best Pellet Grills
        // 2023" read in 2026 tells the reader to hit Back before the page
        // even loads — and Google's users do exactly that.
        $title_year = 0;

        if (preg_match_all('/\b(19|20)\d{2}\b/', $post->post_title, $title_matches)) {
            foreach ($title_matches[0] as $year) {
                if ((int) $year <= $current_year && (int) $year > $title_year) {
                    $title_year = (int) $year;
                }
            }
        }

        // Sentences citing a year at least two behind today. One mention of
        // an old year is often legitimate history; each excerpt carries its
        // sentence so a human (and the analyzer) can judge which it is.
        $dated = array();
        $recency = array();

        $sentences = preg_split('/(?<=[.!?])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        // Phrases that decay silently: true when written, wrong now, and
        // undetectable by any date because they contain none.
        $recency_pattern = '/\b(recently|this year|last year|earlier this year|as of (now|today|this writing)|currently|at the moment|latest (version|model|study|research|data)|just (released|launched|announced)|brand.?new|upcoming)\b/i';

        foreach ((array) $sentences as $sentence) {
            $sentence = trim($sentence);

            if (mb_strlen($sentence) < 20 || mb_strlen($sentence) > 400) {
                continue;
            }

            if (count($dated) < 6 && preg_match_all('/\b(19|20)\d{2}\b/', $sentence, $year_matches)) {
                foreach ($year_matches[0] as $year) {
                    if ((int) $year >= 2000 && (int) $year <= $current_year - 2) {
                        $dated[] = mb_substr($sentence, 0, 200);
                        break;
                    }
                }
            }

            if (count($recency) < 6 && preg_match($recency_pattern, $sentence)) {
                $recency[] = mb_substr($sentence, 0, 200);
            }
        }

        return array(
            'title_year'       => $title_year,
            'dated_excerpts'   => $dated,
            'recency_excerpts' => $recency,
        );
    }

    /**
     * Signals about whether a reader (or a regulator) can trust this page.
     *
     * @param string  $content Raw post content.
     * @param string  $text    Plain text of the content.
     * @param WP_Post $post
     * @return array
     */
    private static function trust_signals($content, $text, $post) {
        $home = wp_parse_url(home_url(), PHP_URL_HOST);

        preg_match_all('/href\s*=\s*["\']([^"\']+)["\']/i', (string) $content, $matches);
        $hrefs = isset($matches[1]) ? array_unique($matches[1]) : array();

        $broken = array();
        $insecure = 0;
        $affiliate = 0;

        // Conservative affiliate markers: each is unambiguous on its own.
        // A broad list would flag ordinary links and teach people to ignore
        // the warning.
        $affiliate_pattern = '/(amzn\.to\/|amazon\.[a-z.]+\/[^"\']*[?&]tag=|\/ref=|shareasale\.com|awin1\.com|anrdoezrs\.net|clickbank\.net|[?&]afftrack=|[?&]affid=)/i';

        foreach ($hrefs as $href) {
            if (0 === strpos($href, 'http://')) {
                $insecure++;
            }

            if (preg_match($affiliate_pattern, $href)) {
                $affiliate++;
            }

            // Broken-link detection is limited to links into THIS site,
            // where "does it resolve to a published post" is answerable
            // without an HTTP request. Only a definite answer is flagged:
            // a URL that resolves to a post that is trashed, drafted or
            // deleted. Archives, files and anything ambiguous are skipped —
            // a trust check that cries wolf gets disabled.
            $host = wp_parse_url($href, PHP_URL_HOST);
            $is_internal = ($host && $host === $home) || (0 === strpos($href, '/') && 0 !== strpos($href, '//'));

            if (!$is_internal || count($broken) >= 5) {
                continue;
            }

            $path = (string) wp_parse_url($href, PHP_URL_PATH);

            if (preg_match('#/(category|tag|author|page|feed)/|\.(jpg|jpeg|png|gif|webp|pdf|zip)$|^/?$#i', $path)) {
                continue;
            }

            $target_id = ECP_Search_Data::url_to_post_id($href);

            if ($target_id && !in_array(get_post_status($target_id), array('publish', false), true)) {
                $broken[] = $href;
            }
        }

        // Disclosure language anywhere on the page. Deliberately generous —
        // the failure being caught is "monetised page, zero disclosure",
        // not "disclosure worded differently than I would put it".
        $has_disclosure = (bool) preg_match(
            '/\b(affiliate|commission|compensated|sponsored|paid link|advertising disclosure|we may earn)\b/i',
            $text
        );

        return array(
            'broken_internal_links' => $broken,
            'insecure_link_count'   => $insecure,
            'affiliate_link_count'  => $affiliate,
            'has_disclosure'        => $has_disclosure,
            'unanswered_questions'  => self::unanswered_comment_questions($post),
        );
    }

    /**
     * Approved reader comments that ask a question and never got a reply.
     *
     * The single most honest content-gap signal a page can have: a real
     * reader, already on the page, telling you in their own words what it
     * failed to answer — and search engines index those comments too.
     *
     * @return string[] Question excerpts, capped at 5.
     */
    private static function unanswered_comment_questions($post) {
        if (!post_type_supports($post->post_type, 'comments')) {
            return array();
        }

        $comments = get_comments(array(
            'post_id' => $post->ID,
            'status'  => 'approve',
            'number'  => 100,
        ));

        if (!$comments) {
            return array();
        }

        $replied_to = array();

        foreach ($comments as $comment) {
            if ((int) $comment->comment_parent > 0) {
                $replied_to[(int) $comment->comment_parent] = true;
            }
        }

        $questions = array();

        foreach ($comments as $comment) {
            if (count($questions) >= 5) {
                break;
            }

            if ((int) $comment->comment_parent > 0 || isset($replied_to[(int) $comment->comment_ID])) {
                continue;
            }

            // The post author asking questions in their own comments is not
            // an unanswered reader.
            if ((int) $comment->user_id && (int) $comment->user_id === (int) $post->post_author) {
                continue;
            }

            $body = trim(wp_strip_all_tags($comment->comment_content));

            if (mb_strlen($body) < 20 || false === strpos($body, '?')) {
                continue;
            }

            $questions[] = mb_substr($body, 0, 200);
        }

        return $questions;
    }

    public static function issues(array $signals, $search = null) {
        $issues = array();

        $add = function ($code, $severity, $label, $detail, $fix_types = array(), $evidence = array()) use (&$issues) {
            $issues[] = compact('code', 'severity', 'label', 'detail', 'fix_types', 'evidence');
        };

        // --- Indexability first --------------------------------------------
        // These have no fix_types on purpose: they are one checkbox for a
        // human, not an AI edit, and they make every other issue on the list
        // irrelevant until resolved. A page Google may not show cannot be
        // improved by better content.
        if (!empty($signals['noindexed'])) {
            $add('noindexed', 'high',
                __('Blocked from search results', 'enhanced-content-plugin'),
                __('This page carries a noindex directive (or is private/password-protected), so search engines will not show it no matter how good it is. If that is unintentional, it is a one-checkbox fix in your SEO plugin — and worth more than every other change on this list combined.', 'enhanced-content-plugin'));
        }

        if (!empty($signals['canonical_elsewhere'])) {
            $add('canonical_elsewhere', 'high',
                __('Tells Google a different URL is the real one', 'enhanced-content-plugin'),
                sprintf(
                    /* translators: %s: canonical URL */
                    __('The canonical tag points to %s, so search engines credit that URL instead of this page. Deliberate for syndicated content; a serious misconfiguration otherwise.', 'enhanced-content-plugin'),
                    (string) $signals['canonical_url']
                ));
        }

        if (empty($signals['has_meta_description'])) {
            $add('no_meta_description', 'high',
                __('No meta description', 'enhanced-content-plugin'),
                __('Search engines are writing your snippet for you.', 'enhanced-content-plugin'),
                array('meta_description'));
        } elseif ($signals['description_length'] < 70) {
            $add('short_meta_description', 'medium',
                __('Meta description is very short', 'enhanced-content-plugin'),
                sprintf(__('%d characters. Around 140-158 uses the full snippet.', 'enhanced-content-plugin'), (int) $signals['description_length']),
                array('meta_description'));
        } elseif ($signals['description_length'] > 165) {
            $add('long_meta_description', 'low',
                __('Meta description will be truncated', 'enhanced-content-plugin'),
                sprintf(__('%d characters; Google typically cuts around 158.', 'enhanced-content-plugin'), (int) $signals['description_length']),
                array('meta_description'));
        }

        if ($signals['seo_title_length'] > 62) {
            $add('long_title', 'low',
                __('Title may be truncated in results', 'enhanced-content-plugin'),
                sprintf(__('%d characters.', 'enhanced-content-plugin'), (int) $signals['seo_title_length']),
                array('meta_title'));
        }

        if ($signals['word_count'] < 500) {
            $add('thin_content', 'high',
                __('Thin content', 'enhanced-content-plugin'),
                sprintf(__('%d words. Likely losing to more complete pages.', 'enhanced-content-plugin'), (int) $signals['word_count']),
                array('section_add'));
        }

        if (empty($signals['has_h2']) && $signals['word_count'] > 400) {
            $add('no_subheadings', 'medium',
                __('No subheadings', 'enhanced-content-plugin'),
                __('A long page with no H2s is hard to scan and hard to rank for sub-topics.', 'enhanced-content-plugin'),
                array('heading_rewrite', 'section_rewrite'));
        }

        if (!empty($signals['skipped_heading_level'])) {
            $add('heading_hierarchy', 'low',
                __('Heading levels skip a step', 'enhanced-content-plugin'),
                __('An H2 is followed directly by an H4, which breaks the document outline.', 'enhanced-content-plugin'),
                array('heading_rewrite'));
        }

        if (!empty($signals['thin_sections'])) {
            $add('thin_sections', 'medium',
                __('Sections with almost no content', 'enhanced-content-plugin'),
                sprintf(__('%d section(s) under 40 words.', 'enhanced-content-plugin'), count($signals['thin_sections'])),
                array('section_rewrite'));
        }

        if ($signals['internal_links'] < 2 && $signals['word_count'] > 600) {
            $add('few_internal_links', 'medium',
                __('Few internal links', 'enhanced-content-plugin'),
                sprintf(__('%d internal link(s) in a %d-word article.', 'enhanced-content-plugin'), (int) $signals['internal_links'], (int) $signals['word_count']),
                array('internal_link_add'));
        }

        if ($signals['inbound_internal_links'] < 1) {
            $add('orphan_page', 'high',
                __('No other page links here', 'enhanced-content-plugin'),
                __('Orphaned pages get crawled less and rank worse.', 'enhanced-content-plugin'),
                array('internal_link_add'));
        }

        if (!empty($signals['images_missing_alt'])) {
            $add('missing_alt_text', 'medium',
                __('Images missing alt text', 'enhanced-content-plugin'),
                sprintf(__('%d image(s).', 'enhanced-content-plugin'), count($signals['images_missing_alt'])),
                array('image_alt'));
        }

        if (0 === (int) $signals['source_count']) {
            $add('no_sources', 'medium',
                __('No sources cited', 'enhanced-content-plugin'),
                __('Citations are a direct E-E-A-T signal and this plugin already renders them.', 'enhanced-content-plugin'),
                array('source_add'));
        }

        if ($signals['days_since_update'] > 365) {
            $add('stale', 'high',
                __('Not updated in over a year', 'enhanced-content-plugin'),
                sprintf(__('Last modified %d days ago.', 'enhanced-content-plugin'), (int) $signals['days_since_update']),
                array('freshness_update', 'section_rewrite'));
        } elseif ($signals['days_since_update'] > 180) {
            $add('aging', 'low',
                __('Getting stale', 'enhanced-content-plugin'),
                sprintf(__('Last modified %d days ago.', 'enhanced-content-plugin'), (int) $signals['days_since_update']),
                array('freshness_update'));
        }

        if (empty($signals['has_faq']) && $signals['word_count'] > 800) {
            $add('no_faq', 'low',
                __('No FAQ section', 'enhanced-content-plugin'),
                __('FAQ schema can win extra SERP real estate on informational queries.', 'enhanced-content-plugin'),
                array('faq_add'));
        }

        if ($signals['long_paragraph_count'] > 0) {
            $add('wall_of_text', 'low',
                __('Very long paragraphs', 'enhanced-content-plugin'),
                sprintf(__('%d paragraph(s) over 150 words.', 'enhanced-content-plugin'), (int) $signals['long_paragraph_count']),
                array('section_trim', 'section_rewrite'));
        }

        if (!empty($signals['bloated_sections'])) {
            $add('long_sections', 'low',
                __('Overlong sections', 'enhanced-content-plugin'),
                sprintf(
                    /* translators: 1: section count, 2: first heading */
                    _n(
                        '%1$d section runs past 600 words ("%2$s"). Filler and repetition bury the substance readers came for.',
                        '%1$d sections run past 600 words, starting with "%2$s". Filler and repetition bury the substance readers came for.',
                        count($signals['bloated_sections']),
                        'enhanced-content-plugin'
                    ),
                    count($signals['bloated_sections']),
                    $signals['bloated_sections'][0]
                ),
                array('section_trim'));
        }

        if ('none' === $signals['seo_plugin']) {
            $add('no_seo_plugin', 'medium',
                __('No SEO plugin active', 'enhanced-content-plugin'),
                __('Without one there is nowhere to store an SEO title, canonical or social-share metadata, and several of this agent\'s fixes have nothing to write to. Yoast, Rank Math, SEOPress or AIOSEO — any of them unlocks the rest.', 'enhanced-content-plugin'));
        }

        // --- Freshness: what specifically has aged --------------------------
        $current_year = (int) current_time('Y');

        if (!empty($signals['title_year']) && (int) $signals['title_year'] < $current_year) {
            $add('dated_title', 'high',
                __('The title promises an out-of-date year', 'enhanced-content-plugin'),
                sprintf(
                    /* translators: 1: year in the title, 2: current year */
                    __('The title says %1$d and it is %2$d. Searchers skip results whose titles admit their age — and once the content genuinely reflects the current year, updating the year is honest rather than cosmetic.', 'enhanced-content-plugin'),
                    (int) $signals['title_year'],
                    $current_year
                ),
                array('meta_title'));
        }

        if (!empty($signals['dated_excerpts'])) {
            $add('dated_statements',
                $signals['days_since_update'] > 365 ? 'high' : 'medium',
                __('Statements anchored to old years', 'enhanced-content-plugin'),
                sprintf(
                    /* translators: %d: number of sentences */
                    _n(
                        '%d sentence cites a year at least two behind today. Some may be legitimate history — the excerpts below show each one so the outdated ones can be updated.',
                        '%d sentences cite years at least two behind today. Some may be legitimate history — the excerpts below show each one so the outdated ones can be updated.',
                        count($signals['dated_excerpts']),
                        'enhanced-content-plugin'
                    ),
                    count($signals['dated_excerpts'])
                ),
                array('freshness_update'),
                array('excerpts' => $signals['dated_excerpts']));
        }

        if (!empty($signals['recency_excerpts']) && $signals['days_since_update'] > 365) {
            $add('undated_recency', 'medium',
                __('Says "recently" on a page that has not been touched in a year', 'enhanced-content-plugin'),
                __('Phrases like "recently", "this year" and "the latest model" were true when written and decay silently — there is no date in them to catch. On a page this old, each one below is probably now wrong.', 'enhanced-content-plugin'),
                array('freshness_update'),
                array('excerpts' => $signals['recency_excerpts']));
        }

        // --- Trust ----------------------------------------------------------
        if (!empty($signals['broken_internal_links'])) {
            $add('broken_links', 'medium',
                __('Links to pages that no longer exist', 'enhanced-content-plugin'),
                sprintf(
                    /* translators: %d: number of links */
                    _n(
                        '%d link points at a page on this site that is no longer published. Dead ends cost reader trust and leak crawl equity.',
                        '%d links point at pages on this site that are no longer published. Dead ends cost reader trust and leak crawl equity.',
                        count($signals['broken_internal_links']),
                        'enhanced-content-plugin'
                    ),
                    count($signals['broken_internal_links'])
                ),
                array(),
                array('excerpts' => $signals['broken_internal_links']));
        }

        if ($signals['affiliate_link_count'] > 0 && empty($signals['has_disclosure'])) {
            $add('no_affiliate_disclosure', 'high',
                __('Affiliate links with no disclosure', 'enhanced-content-plugin'),
                sprintf(
                    /* translators: %d: number of affiliate links */
                    __('%d link(s) look like affiliate links and no disclosure language appears anywhere on the page. That is an FTC requirement in the US and its absence is a trust signal both readers and search engines pick up on. This needs your own wording — it is a legal statement, not something an AI should draft.', 'enhanced-content-plugin'),
                    (int) $signals['affiliate_link_count']
                ));
        }

        if ($signals['insecure_link_count'] > 0) {
            $add('insecure_links', 'low',
                __('Links over plain http', 'enhanced-content-plugin'),
                sprintf(
                    /* translators: %d: number of links */
                    __('%d link(s) use http:// rather than https://. Most will redirect, but each is a slower hop and some browsers warn on them.', 'enhanced-content-plugin'),
                    (int) $signals['insecure_link_count']
                ));
        }

        if (!empty($signals['unanswered_questions'])) {
            $add('unanswered_comments', 'medium',
                __('Readers asked questions in the comments and never got answers', 'enhanced-content-plugin'),
                sprintf(
                    /* translators: %d: number of questions */
                    _n(
                        '%d reader question sits unanswered in the comments. This is the most honest content-gap signal there is — a real reader saying what the page failed to tell them — and answering it in the article helps every future reader, not just one.',
                        '%d reader questions sit unanswered in the comments. This is the most honest content-gap signal there is — real readers saying what the page failed to tell them — and answering them in the article helps every future reader, not just one.',
                        count($signals['unanswered_questions']),
                        'enhanced-content-plugin'
                    ),
                    count($signals['unanswered_questions'])
                ),
                array('faq_add', 'section_add'),
                array('excerpts' => $signals['unanswered_questions']));
        }

        if (0 === (int) $signals['image_count'] && $signals['word_count'] > 1200) {
            $add('no_images', 'low',
                __('A long article with no images at all', 'enhanced-content-plugin'),
                __('Not one image in a piece this length. Diagrams, screenshots or product photos are a helpfulness signal, a rankable asset in image search, and a rest for the reader\'s eyes.', 'enhanced-content-plugin'));
        }

        if (0 === (int) $signals['author_count']) {
            $add('no_author_info', 'medium',
                __('No author information', 'enhanced-content-plugin'),
                __('Nobody is credited as author, reviewer or fact-checker. "Who wrote this and why should I believe them" is the core of E-E-A-T — this plugin\'s own Contributors box on the edit screen adds bylines with credentials and schema.', 'enhanced-content-plugin'));
        }

        if (empty($signals['has_intro']) || $signals['intro_words'] < 40) {
            $add('weak_intro', 'medium',
                __('Weak or missing introduction', 'enhanced-content-plugin'),
                __('The opening should answer the query before the first subheading.', 'enhanced-content-plugin'),
                array('intro_rewrite'));
        }

        if ($signals['generic_anchor_count'] > 0) {
            $add('generic_anchors', 'low',
                __('Generic link anchor text', 'enhanced-content-plugin'),
                sprintf(__('%d link(s) use text like "click here".', 'enhanced-content-plugin'), (int) $signals['generic_anchor_count']),
                array('internal_link_add'));
        }

        // Everything above is on-page: things you can see by reading the
        // markup. Everything below needs Search Console, and is invisible
        // without it — a page can be flawless on-page and still be failing.
        if (is_array($search)) {
            $issues = array_merge($issues, self::search_issues($signals, $search));
        } elseif (empty($signals['noindexed'])
            && empty($signals['canonical_elsewhere'])
            && 'publish' === $signals['status']
            && (int) $signals['age_days'] > 60
            && self::site_has_search_coverage()
        ) {
            // The site has search data; this published, indexable,
            // months-old page has none at all. That is not "no issues" —
            // it usually means not indexed, indexed-but-invisible, or
            // canonicalised away by something outside the page's own meta.
            $add('never_seen', 'high',
                __('Invisible to search', 'enhanced-content-plugin'),
                sprintf(
                    /* translators: %d: age of the post in days */
                    __('Search Console reports data for other pages on this site but none for this one, %d days after publication. Check it in Search Console\'s URL inspection — content changes cannot help a page Google is not showing at all.', 'enhanced-content-plugin'),
                    (int) $signals['age_days']
                ));
        }

        /**
         * Filter the deterministic issue list for a post.
         *
         * @param array[]    $issues
         * @param array      $signals
         * @param array|null $search
         */
        return apply_filters('ecp_post_issues', $issues, $signals, $search);
    }

    /**
     * Does this site have search data for anything at all?
     *
     * Cached per request because issues() runs once per post in a 50-post
     * scan batch, and the answer cannot change mid-request.
     */
    private static function site_has_search_coverage() {
        static $covered = null;

        if (null === $covered) {
            $covered = ECP_Search_Data::is_connected() && ECP_Search_Data::covered_post_count() > 3;
        }

        return $covered;
    }

    /**
     * Issues that only measured search behaviour can reveal.
     *
     * The gap this closes: a page with a well-formed 150-character meta
     * description passes every on-page check there is. If nobody clicks it,
     * nothing on the page says so. The plugin knew — the scorer read the CTR,
     * ranked the page top of the queue and labelled it "getting impressions
     * but few clicks" — and then handed the analyzer a list of problems that
     * never mentioned it. The two halves disagreed about why the page was
     * there.
     *
     * @param array $signals
     * @param array $search { page, queries, striking, trend, window }
     * @return array[]
     */
    public static function search_issues(array $signals, array $search) {
        $issues = array();

        $add = function ($code, $severity, $label, $detail, $fix_types = array(), $evidence = array()) use (&$issues) {
            $issues[] = compact('code', 'severity', 'label', 'detail', 'fix_types', 'evidence');
        };

        $queries = isset($search['queries']) ? (array) $search['queries'] : array();
        $window = isset($search['window']) ? (int) $search['window'] : 28;

        // --- Ranks well, nobody clicks -------------------------------------
        // The highest-value, lowest-risk finding this plugin can make, and
        // the one it was completely blind to.
        $underperforming = array();
        $missed_clicks = 0;

        foreach ($queries as $query) {
            if ($query['impressions'] < 20) {
                continue;   // Too little volume for a CTR to mean anything.
            }

            $expected = ECP_Opportunity_Engine::expected_ctr($query['position']);

            if ($expected <= 0 || $query['ctr'] >= $expected * 0.6) {
                continue;
            }

            $gap = ($expected - $query['ctr']) * $query['impressions'];

            if ($gap < 1) {
                continue;
            }

            $missed_clicks += $gap;
            $underperforming[] = array(
                'query'       => $query['query'],
                'position'    => $query['position'],
                'impressions' => $query['impressions'],
                'clicks'      => $query['clicks'],
                'ctr'         => $query['ctr'],
                'expected'    => $expected,
                'missed'      => round($gap),
            );
        }

        if ($missed_clicks >= 3 && $underperforming) {
            usort($underperforming, function ($a, $b) {
                return $b['missed'] <=> $a['missed'];
            });

            $worst = $underperforming[0];

            $add(
                'low_ctr',
                $missed_clicks >= 20 ? 'high' : 'medium',
                __('Ranks well but is rarely clicked', 'enhanced-content-plugin'),
                sprintf(
                    /* translators: 1: missed clicks, 2: days, 3: query, 4: position, 5: actual CTR, 6: expected CTR */
                    __('Losing roughly %1$d clicks per %2$d days to weak search snippets. Worst: "%3$s" sits at position %4$s and only %5$s of people who see it click, against about %6$s typical for that position. The ranking is fine — the title and description are not earning the click.', 'enhanced-content-plugin'),
                    (int) round($missed_clicks),
                    $window,
                    $worst['query'],
                    number_format_i18n($worst['position'], 1),
                    number_format_i18n($worst['ctr'] * 100, 1) . '%',
                    number_format_i18n($worst['expected'] * 100, 1) . '%'
                ),
                array('meta_description', 'meta_title'),
                array('queries' => array_slice($underperforming, 0, 5), 'missed_clicks' => (int) round($missed_clicks))
            );
        }

        // --- Nearly on page one ---------------------------------------------
        $striking = isset($search['striking']) ? (array) $search['striking'] : array();

        if ($striking) {
            $volume = array_sum(wp_list_pluck($striking, 'impressions'));

            $add(
                'striking_distance',
                $volume >= 500 ? 'high' : 'medium',
                __('Ranking just off page one', 'enhanced-content-plugin'),
                sprintf(
                    /* translators: 1: number of terms, 2: impressions, 3: example query, 4: position */
                    _n(
                        '%1$d search term with %2$s impressions sits in reach of page one, including "%3$s" at position %4$s. Terms in this range respond to the page covering the topic more completely.',
                        '%1$d search terms with %2$s impressions sit in reach of page one, including "%3$s" at position %4$s. Terms in this range respond to the page covering the topic more completely.',
                        count($striking),
                        'enhanced-content-plugin'
                    ),
                    count($striking),
                    number_format_i18n($volume),
                    $striking[0]['query'],
                    number_format_i18n($striking[0]['position'], 1)
                ),
                array('section_add', 'section_rewrite', 'intro_rewrite'),
                array('queries' => array_slice($striking, 0, 8))
            );
        }

        // --- Ranks for things it barely covers --------------------------------
        // Section 7 of the plan calls this query expansion: Google has
        // decided the page is relevant to something the page hardly
        // discusses, which is usually the cheapest content win available.
        $gaps = self::uncovered_queries($signals, $queries);

        if ($gaps) {
            $add(
                'query_gap',
                'medium',
                __('Ranks for topics it barely covers', 'enhanced-content-plugin'),
                sprintf(
                    /* translators: 1: comma-separated queries, 2: impressions */
                    __('People find this page searching for %1$s (%2$s impressions), but those words barely appear in it. Covering them properly is usually the cheapest way to gain ground.', 'enhanced-content-plugin'),
                    '"' . implode('", "', array_slice(wp_list_pluck($gaps, 'query'), 0, 3)) . '"',
                    number_format_i18n(array_sum(wp_list_pluck($gaps, 'impressions')))
                ),
                array('section_add', 'faq_add'),
                array('queries' => array_slice($gaps, 0, 6))
            );
        }

        // --- Questions people are actually asking -------------------------------
        if (empty($signals['has_faq'])) {
            $questions = array_values(array_filter($queries, function ($query) {
                return $query['impressions'] >= 20
                    && preg_match('/^(how|what|why|when|where|which|can|do|does|is|are|should)\b/i', $query['query']);
            }));

            if (count($questions) >= 2) {
                $add(
                    'question_queries',
                    'medium',
                    __('People arrive with questions this page does not answer directly', 'enhanced-content-plugin'),
                    sprintf(
                        /* translators: 1: number of questions, 2: example */
                        __('%1$d question-shaped searches reach this page, such as "%2$s". An FAQ section answering them in the reader\'s own words can win extra space in the results.', 'enhanced-content-plugin'),
                        count($questions),
                        $questions[0]['query']
                    ),
                    array('faq_add'),
                    array('queries' => array_slice($questions, 0, 6))
                );
            }
        }

        // --- Seen, never chosen ---------------------------------------------
        // Distinct from low_ctr: not "clicked less than it should" but
        // "never clicked at all", which usually means the snippet is
        // answering a different question to the one being asked.
        $page = isset($search['page']) ? $search['page'] : null;

        if ($page && $page['impressions'] >= 100 && 0 === (int) $page['clicks']) {
            $add(
                'zero_clicks',
                'high',
                __('Shown in search, never clicked', 'enhanced-content-plugin'),
                sprintf(
                    /* translators: 1: impressions, 2: days, 3: position */
                    __('%1$s people saw this page in results over %2$d days and not one clicked. At an average position of %3$s that is not a ranking problem — the title and description are answering a different question to the one being asked.', 'enhanced-content-plugin'),
                    number_format_i18n($page['impressions']),
                    $window,
                    number_format_i18n($page['position'], 1)
                ),
                array('meta_title', 'meta_description'),
                array('page' => $page)
            );
        }

        // --- Losing ground ------------------------------------------------------
        $trend = isset($search['trend']) ? $search['trend'] : null;

        if (is_array($trend) && null !== $trend['percent_change'] && $trend['percent_change'] <= -25 && $trend['clicks_before'] >= 10) {
            $add(
                'traffic_decline',
                'high',
                __('Losing traffic', 'enhanced-content-plugin'),
                sprintf(
                    /* translators: 1: percentage drop, 2: clicks before, 3: clicks now, 4: days */
                    __('Clicks are down %1$d%% (%2$d to %3$d) over the last %4$d days. Decay like this usually means the page has fallen behind newer or more complete results rather than anything being wrong with it on the page.', 'enhanced-content-plugin'),
                    abs((int) $trend['percent_change']),
                    (int) $trend['clicks_before'],
                    (int) $trend['clicks_now'],
                    (int) $trend['days']
                ),
                array('freshness_update', 'section_rewrite', 'section_add', 'section_trim'),
                array('trend' => $trend)
            );
        }

        return $issues;
    }

    /**
     * Queries a page ranks for whose words are largely absent from it.
     *
     * A crude but useful test: if Google sends people here for "flame tamer
     * replacement" and neither "replacement" nor "flame tamer" appears more
     * than in passing, the page is ranking on adjacency rather than because
     * it answers the question.
     *
     * @return array[]
     */
    private static function uncovered_queries(array $signals, array $queries) {
        $post = get_post($signals['post_id']);

        if (!$post) {
            return array();
        }

        $haystack = strtolower(
            ECP_Content_Map::to_text($post->post_content) . ' ' . $post->post_title
        );

        $stopwords = array('a', 'an', 'and', 'are', 'as', 'at', 'be', 'by', 'for', 'from', 'how', 'in', 'is', 'it', 'of', 'on', 'or', 'the', 'to', 'what', 'why', 'with', 'you', 'your', 'do', 'does', 'can');

        $gaps = array();

        foreach ($queries as $query) {
            if ($query['impressions'] < 30) {
                continue;
            }

            $terms = preg_split('/\s+/u', strtolower(preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $query['query'])), -1, PREG_SPLIT_NO_EMPTY);
            $terms = array_diff((array) $terms, $stopwords);

            if (count($terms) < 2) {
                continue;   // Single-word queries are too blunt to judge.
            }

            $present = 0;
            foreach ($terms as $term) {
                if (strlen($term) > 2 && false !== strpos($haystack, $term)) {
                    $present++;
                }
            }

            if (($present / count($terms)) < 0.5) {
                $gaps[] = $query;
            }
        }

        usort($gaps, function ($a, $b) {
            return $b['impressions'] <=> $a['impressions'];
        });

        return $gaps;
    }

    /* --------------------------------------------------------------------
     * Utilities
     * ----------------------------------------------------------------- */

    private static function days_since($gmt_datetime) {
        $timestamp = strtotime((string) $gmt_datetime . ' UTC');
        if (!$timestamp) {
            return 0;
        }

        return max(0, (int) floor((time() - $timestamp) / DAY_IN_SECONDS));
    }

    /**
     * Drop the verbose members before sending signals to a model or storing
     * them on an opportunity row.
     */
    public static function compact_signals(array $signals) {
        unset(
            $signals['internal_link_targets'],
            $signals['images_missing_alt'],
            $signals['sources'],
            $signals['headings']
        );

        return $signals;
    }
}
