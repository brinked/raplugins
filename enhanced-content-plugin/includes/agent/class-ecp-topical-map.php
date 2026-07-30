<?php
/**
 * Topical map: what this site should cover — and what it should not.
 *
 * Grown from a seed topic using what the plugin already knows: the site
 * profile, the classified inventory, and measured Search Console queries.
 * GSC-first by design — no scraping, no external SERP calls from the
 * customer's server; a licensed data provider slots in later behind the
 * same interface (gameplan §8.2).
 *
 * The model proposes; the Content Restraint Engine disposes. Every
 * proposed topic gets a deterministic, explainable verdict computed in
 * PHP from the site's own data: WRITE it, EXPAND an existing page,
 * fold it in as a SUBSECTION, or SKIP it — because a page already owns
 * the query, because it would cannibalize one, or because the owner
 * excluded the territory. "Publish only what deserves to exist" is
 * enforced here, not hoped for in a prompt.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ECP_Topical_Map {

    /* Coverage of a proposed topic by the existing site. */
    const STRONG  = 'strong';
    const PARTIAL = 'partial';
    const WEAK    = 'weak';
    const MISSING = 'missing';

    /* Restraint verdicts. */
    const WRITE      = 'write';
    const EXPAND     = 'expand';
    const SUBSECTION = 'subsection';
    const SKIP       = 'skip';

    /* Owner decisions. */
    const PROPOSED  = 'proposed';
    const APPROVED  = 'approved';
    const DISMISSED = 'dismissed';

    /** Existing pages shown to the model and used for matching. */
    const MAX_PAGES = 120;

    /** Measured queries fed into the research prompt. */
    const MAX_QUERIES = 70;

    /* --------------------------------------------------------------------
     * Building a map
     * ----------------------------------------------------------------- */

    /**
     * Grow a map from one seed topic. One AI call, metered monthly.
     *
     * @param string $seed
     * @param array  $args { trigger_source }
     * @return array|WP_Error { seed, created, updated, skipped }
     */
    public static function build($seed, array $args = array()) {
        $args = wp_parse_args($args, array('trigger_source' => 'manual'));

        $seed = trim(sanitize_text_field($seed));

        if ('' === $seed) {
            return new WP_Error('ecp_no_seed', __('Type a seed topic first.', 'enhanced-content-plugin'));
        }

        $allowed = ECP_Limits::can('maps');

        if (is_wp_error($allowed)) {
            return $allowed;
        }

        $pages = self::existing_pages();
        $queries = self::measured_queries($seed);
        $excluded = (array) ECP_Site_Profile::get('excluded_topics');

        // Licensed third-party demand data, when connected. Empty when
        // not — the map is GSC-first and never depends on it.
        $ideas = ECP_Serp::is_connected() ? ECP_Serp::keyword_ideas($seed, 40) : array();

        $response = ECP_AI_Client::request(
            self::system_prompt(),
            self::user_prompt($seed, $pages, $queries, $excluded, $ideas),
            self::schema(),
            array(
                'job_type'       => 'map',
                'meter'          => 'maps',
                'trigger_source' => $args['trigger_source'],
                'max_tokens'     => 24000,
                // Mapping is breadth work, not prose. Medium effort cuts
                // the build to a fraction of the time — which also keeps
                // it inside shared-host request limits.
                'effort'         => 'medium',
            )
        );

        if (is_wp_error($response)) {
            return $response;
        }

        ECP_Limits::spend('maps');

        $topics = isset($response['data']['topics']) && is_array($response['data']['topics'])
            ? $response['data']['topics']
            : array();

        $volumes = array();
        foreach ($ideas as $idea) {
            $volumes[strtolower($idea['keyword'])] = (int) $idea['volume'];
        }

        $result = self::store($seed, $topics, $pages, $excluded, $volumes);

        ECP_Log::info(ECP_Log::MAP_BUILT, sprintf(
            /* translators: 1: seed topic, 2: topics kept, 3: topics judged not worth writing */
            __('Topical map for "%1$s": %2$d topics mapped, %3$d judged not worth a new page.', 'enhanced-content-plugin'),
            $seed,
            (int) $result['total'],
            (int) $result['restrained']
        ), array('run_id' => (int) $response['run_id']));

        return $result;
    }

    /* --------------------------------------------------------------------
     * Research inputs
     * ----------------------------------------------------------------- */

    /**
     * Structural pages: URLs that exist for navigation, not as content —
     * the homepage, the blog index, the privacy page. They are never
     * coverage of a topic and never candidates for "expand this page":
     * a homepage earning a query is evidence of demand and authority,
     * not of the topic being answered.
     *
     * @return int[] Post ids, keyed by id => human label.
     */
    public static function structural_pages() {
        $pages = array();

        $front = (int) get_option('page_on_front');
        if ($front) {
            $pages[$front] = __('homepage', 'enhanced-content-plugin');
        }

        $posts_page = (int) get_option('page_for_posts');
        if ($posts_page) {
            $pages[$posts_page] = __('blog page', 'enhanced-content-plugin');
        }

        $privacy = (int) get_option('wp_page_for_privacy_policy');
        if ($privacy) {
            $pages[$privacy] = __('privacy page', 'enhanced-content-plugin');
        }

        /**
         * Pages the topical map must never treat as content — cart,
         * checkout, landing pages a theme owns, and so on.
         *
         * @param array<int,string> $pages post_id => label
         */
        return apply_filters('ecp_structural_pages', $pages);
    }

    /**
     * The published pages the map must respect, from the inventory.
     * Structural pages are excluded — they are not coverage.
     *
     * @return array[] { post_id, title, topic, intent, word_count }
     */
    private static function existing_pages() {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return array();
        }

        $structural = array_keys(self::structural_pages());
        $exclude_sql = '';
        $params = array();

        if ($structural) {
            $placeholders = implode(',', array_fill(0, count($structural), '%d'));
            $exclude_sql = "AND post_id NOT IN ({$placeholders})";
            $params = $structural;
        }

        $params[] = self::MAX_PAGES;

        return (array) $wpdb->get_results($wpdb->prepare(
            'SELECT post_id, title, topic, intent, word_count
               FROM ' . ECP_DB::inventory_table() . "
              WHERE post_status = 'publish' {$exclude_sql}
              ORDER BY word_count DESC
              LIMIT %d",
            $params
        ), ARRAY_A);
    }

    /**
     * Measured queries worth showing the model: those sharing a word with
     * the seed, topped up with the site's biggest queries overall. Each
     * carries the page that currently earns it, so ownership is part of
     * the evidence from the start.
     *
     * @return array[] { query, impressions, clicks, position, post_id }
     */
    private static function measured_queries($seed) {
        global $wpdb;

        if (!ECP_DB::tables_exist() || !class_exists('ECP_Rankings')) {
            return array();
        }

        $window = ECP_Search_Data::DEFAULT_WINDOW;
        $latest = ECP_Rankings::latest_date($window);

        if (!$latest) {
            return array();
        }

        $metrics = ECP_DB::metrics_table();

        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT query,
                    SUM(impressions) AS impressions,
                    SUM(clicks) AS clicks,
                    MIN(position) AS position
               FROM {$metrics}
              WHERE window_days = %d AND metric_date = %s AND query != ''
              GROUP BY query
              ORDER BY impressions DESC
              LIMIT 400",
            (int) $window,
            $latest
        ), ARRAY_A);

        $seed_words = array_filter(
            preg_split('/\s+/', strtolower($seed)),
            function ($word) {
                return strlen($word) >= 4;
            }
        );

        $matched = array();
        $top = array();

        foreach ($rows as $row) {
            $query = strtolower($row['query']);
            $hit = false;

            foreach ($seed_words as $word) {
                if (false !== strpos($query, $word)) {
                    $hit = true;
                    break;
                }
            }

            if ($hit && count($matched) < self::MAX_QUERIES - 20) {
                $matched[$row['query']] = $row;
            } elseif (count($top) < 20) {
                $top[$row['query']] = $row;
            }
        }

        $combined = array_slice($matched + $top, 0, self::MAX_QUERIES, true);

        // Who currently earns each query — the restraint engine's
        // strongest signal, fetched once here rather than per topic.
        foreach ($combined as $query => &$row) {
            $row['post_id'] = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$metrics}
                  WHERE window_days = %d AND metric_date = %s AND query = %s
                  ORDER BY impressions DESC
                  LIMIT 1",
                (int) $window,
                $latest,
                $query
            ));
        }
        unset($row);

        return array_values($combined);
    }

    /* --------------------------------------------------------------------
     * Prompt
     * ----------------------------------------------------------------- */

    private static function page_types() {
        return array(
            'pillar_guide', 'supporting_article', 'product_page', 'product_category',
            'service_page', 'comparison_page', 'alternatives_page', 'cost_guide',
            'how_to_guide', 'troubleshooting', 'faq_resource', 'glossary',
            'local_service_page', 'case_study', 'original_research', 'tool_calculator',
        );
    }

    private static function system_prompt() {
        $lines = array();

        $lines[] = 'You are a content strategist mapping what one specific website should cover to become the honest authority on a topic.';
        $lines[] = '';
        $lines[] = 'Rules that decide whether your map is any good:';
        $lines[] = '- Propose topics THIS business has standing to write, judged from its profile and offerings. A topic that would be authoritative coming from a competitor but hollow coming from this site does not belong on the map.';
        $lines[] = '- Match the page type to what the query deserves. Never propose an informational article where the searcher wants a product, category, service or comparison page.';
        $lines[] = '- Group topics into clusters: a parent pillar and its supporting topics. Set parent to the pillar topic; a pillar has an empty parent.';
        $lines[] = '- Prefer main queries that appear in the measured-query list — those are demand this site can already see. Invent no search volume; a query without measured data is a hypothesis and its evidence_needs should say so.';
        $lines[] = '- For every topic, state plainly what the business gains (business_relevance) and what facts or firsthand material an honest article would require (evidence_needs) — prices, test results, photos, credentials. Someone will be asked to supply these before anything is written.';
        $lines[] = '- Fewer, better topics. 15 to 25 that deserve to exist beat 40 that fill space.';
        $lines[] = '- Never propose a topic in the excluded list, or anything that merely rephrases an existing page.';

        return implode("\n", $lines);
    }

    private static function user_prompt($seed, array $pages, array $queries, array $excluded, array $ideas = array()) {
        $out = array();

        $profile = ECP_Site_Profile::prompt_context();

        if ($profile) {
            $out[] = '## The business';
            $out[] = $profile;
            $out[] = '';
        }

        $out[] = '## The seed topic';
        $out[] = $seed;

        if ($excluded) {
            $out[] = '';
            $out[] = '## Excluded — never propose these territories';
            foreach ($excluded as $topic) {
                $out[] = '- ' . $topic;
            }
        }

        if ($pages) {
            $out[] = '';
            $out[] = '## What the site already covers';
            $out[] = 'Do not re-propose these. When a proposed topic is close to one of them, name it in likely_existing_match.';

            foreach ($pages as $page) {
                $out[] = sprintf(
                    '- %s%s (%d words)',
                    $page['title'],
                    $page['topic'] ? ' — topic: ' . $page['topic'] : '',
                    (int) $page['word_count']
                );
            }
        }

        if ($queries) {
            $out[] = '';
            $out[] = '## Measured search demand (Search Console, last 28 days)';
            $out[] = 'Real queries with real impressions. Ground main queries here wherever possible.';

            foreach ($queries as $query) {
                $out[] = sprintf(
                    '- "%s" — %d impressions, position %.1f',
                    $query['query'],
                    (int) $query['impressions'],
                    (float) $query['position']
                );
            }
        } else {
            $out[] = '';
            $out[] = '## No measured search data';
            $out[] = 'Search Console is not providing query data. Every proposed topic is a hypothesis; be conservative and say so in evidence_needs.';
        }

        if ($ideas) {
            $out[] = '';
            $out[] = '## Third-party search volume estimates (licensed data)';
            $out[] = 'Monthly search volumes from a licensed keyword database. These are estimates, not this site\'s own measurements — useful for topics the site does not rank for yet. Prefer main queries from this list or the measured list over invented ones.';

            foreach ($ideas as $idea) {
                $out[] = sprintf(
                    '- "%s" — ~%d searches/month',
                    $idea['keyword'],
                    (int) $idea['volume']
                );
            }
        }

        $out[] = '';
        $out[] = '## What to return';
        $out[] = 'The topic map: 15 to 25 topics in clusters, each fully classified.';

        return implode("\n", $out);
    }

    private static function schema() {
        return array(
            'type' => 'object',
            'properties' => array(
                'topics' => array(
                    'type' => 'array',
                    'items' => array(
                        'type' => 'object',
                        'properties' => array(
                            'topic'  => array('type' => 'string', 'description' => 'The topic as a working page subject, not a keyword string.'),
                            'parent' => array('type' => 'string', 'description' => 'The pillar topic this supports. Empty when this IS the pillar.'),
                            'intent' => array('type' => 'string', 'enum' => array('informational', 'commercial', 'transactional', 'navigational')),
                            'funnel_stage' => array('type' => 'string', 'enum' => array('awareness', 'consideration', 'decision', 'retention')),
                            'page_type' => array('type' => 'string', 'enum' => self::page_types()),
                            'main_query' => array('type' => 'string', 'description' => 'From the measured list when possible.'),
                            'supporting_queries' => array('type' => 'array', 'items' => array('type' => 'string')),
                            'business_relevance' => array('type' => 'string', 'description' => 'What this business concretely gains. One or two sentences.'),
                            'evidence_needs' => array('type' => 'string', 'description' => 'The facts, media or firsthand experience an honest article requires.'),
                            'likely_existing_match' => array('type' => 'string', 'description' => 'Title of the existing page this is closest to, or empty.'),
                        ),
                        'required' => array(
                            'topic', 'parent', 'intent', 'funnel_stage', 'page_type',
                            'main_query', 'supporting_queries', 'business_relevance',
                            'evidence_needs', 'likely_existing_match',
                        ),
                        'additionalProperties' => false,
                    ),
                ),
            ),
            'required' => array('topics'),
            'additionalProperties' => false,
        );
    }

    /* --------------------------------------------------------------------
     * The Content Restraint Engine
     * ----------------------------------------------------------------- */

    /**
     * Judge every proposed topic against the site's own data and store
     * the survivors. Verdicts are computed here, deterministically, so
     * the UI can explain each one and re-running a seed reproduces them.
     *
     * @return array { seed, total, restrained, created, updated }
     */
    private static function store($seed, array $topics, array $pages, array $excluded, array $volumes = array()) {
        global $wpdb;

        $table = ECP_DB::topics_table();
        $now = ECP_DB::now();
        $created = 0;
        $updated = 0;
        $restrained = 0;
        $seen_keys = array();

        foreach ($topics as $entry) {
            if (!is_array($entry) || empty($entry['topic'])) {
                continue;
            }

            $entry = wp_parse_args($entry, array(
                'parent'             => '',
                'intent'             => '',
                'funnel_stage'       => '',
                'page_type'          => '',
                'main_query'         => '',
                'supporting_queries' => array(),
                'business_relevance' => '',
                'evidence_needs'     => '',
            ));

            $topic = trim(sanitize_text_field($entry['topic']));
            $key = md5(strtolower($seed) . '|' . strtolower($topic));

            if (isset($seen_keys[$key])) {
                continue;   // The model repeated itself; keep the first.
            }
            $seen_keys[$key] = true;

            // The owner's exclusion list is a hard wall, checked in code
            // even though the prompt already said so.
            if (self::is_excluded($topic, $excluded)) {
                continue;
            }

            $judgement = self::judge($topic, $entry, $pages, $volumes);

            if (self::SKIP === $judgement['verdict']) {
                $restrained++;
            }

            $data = array(
                'seed'               => $seed,
                'parent'             => trim(sanitize_text_field($entry['parent'])),
                'topic'              => $topic,
                'intent'             => sanitize_key($entry['intent']),
                'funnel_stage'       => sanitize_key($entry['funnel_stage']),
                'page_type'          => sanitize_key($entry['page_type']),
                'main_query'         => sanitize_text_field($entry['main_query']),
                'supporting_queries' => ECP_DB::encode(array_map('sanitize_text_field', (array) $entry['supporting_queries'])),
                'business_relevance' => sanitize_textarea_field($entry['business_relevance']),
                'evidence_needs'     => sanitize_textarea_field($entry['evidence_needs']),
                'coverage'           => $judgement['coverage'],
                'matched_post_id'    => (int) $judgement['post_id'],
                'match_basis'        => ECP_DB::encode($judgement['basis']),
                'verdict'            => $judgement['verdict'],
                'verdict_reason'     => $judgement['reason'],
                'score'              => round($judgement['score'], 2),
                'updated_at'         => $now,
            );
            $formats = array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%f', '%s');

            $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE topic_key = %s", $key));

            if ($existing) {
                // Facts refresh; the owner's decision does not.
                $wpdb->update($table, $data, array('id' => (int) $existing), $formats, array('%d'));
                $updated++;
            } else {
                $data['topic_key'] = $key;
                $data['status'] = self::PROPOSED;
                $data['created_at'] = $now;
                array_push($formats, '%s', '%s', '%s');
                $wpdb->insert($table, $data, $formats);
                $created++;
            }
        }

        return array(
            'seed'       => $seed,
            'total'      => $created + $updated,
            'restrained' => $restrained,
            'created'    => $created,
            'updated'    => $updated,
        );
    }

    /**
     * One topic, one verdict, with the evidence that produced it.
     *
     * @return array { coverage, verdict, reason, post_id, score, basis }
     */
    private static function judge($topic, array $entry, array $pages, array $volumes = array()) {
        global $wpdb;

        $main_query = trim((string) $entry['main_query']);
        $basis = array();
        $score = 0.0;

        // Third-party volume, when known. Weighted down against measured
        // impressions and recorded separately so the UI never presents an
        // estimate as a measurement.
        $volume = isset($volumes[strtolower($main_query)]) ? (int) $volumes[strtolower($main_query)] : 0;

        if ($volume > 0) {
            $basis['volume'] = $volume;
            $score += $volume / 10;
        }

        // --- Who already earns the main query? ---------------------------
        $owner_id = 0;
        $owner_position = 0.0;
        $owner_impressions = 0;

        if ('' !== $main_query && class_exists('ECP_Rankings')) {
            $window = ECP_Search_Data::DEFAULT_WINDOW;
            $latest = ECP_Rankings::latest_date($window);

            if ($latest) {
                $row = $wpdb->get_row($wpdb->prepare(
                    'SELECT post_id, impressions, position FROM ' . ECP_DB::metrics_table() . '
                      WHERE window_days = %d AND metric_date = %s AND query = %s
                      ORDER BY impressions DESC
                      LIMIT 1',
                    (int) $window,
                    $latest,
                    $main_query
                ), ARRAY_A);

                if ($row) {
                    $owner_id = (int) $row['post_id'];
                    $owner_position = (float) $row['position'];
                    $owner_impressions = (int) $row['impressions'];
                    $score += $owner_impressions;
                    $basis['query_owner'] = array(
                        'query'       => $main_query,
                        'post_id'     => $owner_id,
                        'position'    => $owner_position,
                        'impressions' => $owner_impressions,
                    );
                }
            }
        }

        // --- Closest existing page by title/topic overlap ------------------
        $best_overlap = 0.0;
        $best_page = null;

        foreach ($pages as $page) {
            $overlap = self::overlap($topic, $page['title']);

            if ($page['topic']) {
                $overlap = max($overlap, self::overlap($topic, $page['topic']));
            }

            if ($overlap > $best_overlap) {
                $best_overlap = $overlap;
                $best_page = $page;
            }
        }

        if ($best_page && $best_overlap >= 0.5) {
            $basis['similar_page'] = array(
                'post_id' => (int) $best_page['post_id'],
                'title'   => $best_page['title'],
                'overlap' => round($best_overlap, 2),
            );
        }

        // --- The verdict ---------------------------------------------------
        // A structural page earning the query changes what the evidence
        // means. The homepage surfacing for a term proves demand and
        // authority, not coverage — and "expand your homepage into an
        // article" is never the advice. The verdict is a dedicated page,
        // which would be expected to take the ranking over, not fight it.
        $structural = self::structural_pages();

        if ($owner_id && isset($structural[$owner_id])) {
            $basis['structural_owner'] = array('post_id' => $owner_id, 'label' => $structural[$owner_id]);

            return array(
                'coverage' => self::WEAK,
                'verdict'  => self::WRITE,
                'reason'   => sprintf(
                    /* translators: 1: structural page label, 2: query, 3: position */
                    __('Your %1$s currently surfaces for "%2$s" at position %3$s. A %1$s is not an article — a dedicated page can actually answer this intent and should take the ranking over.', 'enhanced-content-plugin'),
                    $structural[$owner_id],
                    $main_query,
                    number_format_i18n($owner_position, 1)
                ),
                'post_id'  => 0,
                'score'    => $score,
                'basis'    => $basis,
            );
        }

        // Query ownership is measured behaviour and beats title similarity.
        if ($owner_id && $owner_position > 0 && $owner_position <= 10) {
            return array(
                'coverage' => self::STRONG,
                'verdict'  => self::SKIP,
                'reason'   => sprintf(
                    /* translators: 1: post title, 2: query, 3: position */
                    __('"%1$s" already earns "%2$s" at position %3$s. A new page would compete with your own ranking — improve that page if anything.', 'enhanced-content-plugin'),
                    get_the_title($owner_id),
                    $main_query,
                    number_format_i18n($owner_position, 1)
                ),
                'post_id'  => $owner_id,
                'score'    => $score,
                'basis'    => $basis,
            );
        }

        if ($owner_id && $owner_position > 10) {
            return array(
                'coverage' => self::PARTIAL,
                'verdict'  => self::EXPAND,
                'reason'   => sprintf(
                    /* translators: 1: post title, 2: query, 3: position */
                    __('"%1$s" already surfaces for "%2$s" at position %3$s. Expanding it is cheaper than starting over and cannot cannibalize.', 'enhanced-content-plugin'),
                    get_the_title($owner_id),
                    $main_query,
                    number_format_i18n($owner_position, 1)
                ),
                'post_id'  => $owner_id,
                'score'    => $score,
                'basis'    => $basis,
            );
        }

        if ($best_page && $best_overlap >= 0.7) {
            return array(
                'coverage' => self::PARTIAL,
                'verdict'  => self::EXPAND,
                'reason'   => sprintf(
                    /* translators: %s: post title */
                    __('Very close to "%s". Expand that page rather than splitting the topic across two URLs.', 'enhanced-content-plugin'),
                    $best_page['title']
                ),
                'post_id'  => (int) $best_page['post_id'],
                'score'    => $score,
                'basis'    => $basis,
            );
        }

        if ($best_page && $best_overlap >= 0.5) {
            $thin = (int) $best_page['word_count'] < 600;

            return array(
                'coverage' => self::WEAK,
                'verdict'  => $thin ? self::EXPAND : self::SUBSECTION,
                'reason'   => $thin
                    ? sprintf(
                        /* translators: %s: post title */
                        __('Overlaps thin coverage in "%s" — grow that page into the real answer first.', 'enhanced-content-plugin'),
                        $best_page['title']
                    )
                    : sprintf(
                        /* translators: %s: post title */
                        __('Related ground is held by "%s". Consider a section there before a standalone page.', 'enhanced-content-plugin'),
                        $best_page['title']
                    ),
                'post_id'  => (int) $best_page['post_id'],
                'score'    => $score,
                'basis'    => $basis,
            );
        }

        if ($owner_impressions > 0) {
            $reason = __('Nothing on the site covers this, and there is measured demand.', 'enhanced-content-plugin');
        } elseif ($volume > 0) {
            $reason = sprintf(
                /* translators: %s: estimated monthly searches */
                __('Nothing on the site covers this. A licensed database estimates roughly %s monthly searches — an estimate, not your own measurement.', 'enhanced-content-plugin'),
                number_format_i18n($volume)
            );
        } else {
            $reason = __('Nothing on the site covers this. No measured demand yet — the case rests on business relevance.', 'enhanced-content-plugin');
        }

        return array(
            'coverage' => self::MISSING,
            'verdict'  => self::WRITE,
            'reason'   => $reason,
            'post_id'  => 0,
            'score'    => $score,
            'basis'    => $basis,
        );
    }

    /**
     * Word-overlap ratio between a topic and a title/topic string,
     * ignoring short stop-ish words. Cheap, explainable, language-naive
     * on purpose — a semantic model can replace this behind the same
     * signature later. Public: the briefs engine reuses it to check
     * required facts against the vault.
     */
    public static function overlap($a, $b) {
        $tokenize = function ($text) {
            $words = preg_split('/[^a-z0-9]+/', strtolower(remove_accents((string) $text)));

            return array_filter($words, function ($word) {
                return strlen($word) >= 4;
            });
        };

        $wa = $tokenize($a);
        $wb = $tokenize($b);

        if (!$wa || !$wb) {
            return 0.0;
        }

        $shared = count(array_intersect($wa, $wb));

        return $shared / min(count($wa), count($wb));
    }

    private static function is_excluded($topic, array $excluded) {
        $topic = strtolower($topic);

        foreach ($excluded as $bad) {
            $bad = strtolower(trim($bad));

            if ('' !== $bad && false !== strpos($topic, $bad)) {
                return true;
            }
        }

        return false;
    }

    /* --------------------------------------------------------------------
     * Reading and deciding
     * ----------------------------------------------------------------- */

    /**
     * @return array[] Seeds with counts: { seed, total, open, built_at }
     */
    public static function seeds() {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return array();
        }

        return (array) $wpdb->get_results($wpdb->prepare(
            'SELECT seed,
                    COUNT(*) AS total,
                    SUM(status = %s) AS open_count,
                    MAX(updated_at) AS built_at
               FROM ' . ECP_DB::topics_table() . '
              GROUP BY seed
              ORDER BY built_at DESC',
            self::PROPOSED
        ), ARRAY_A);
    }

    /**
     * All nodes for a seed, grouped for display: pillars first, then
     * their children, ordered by measured evidence within each group.
     *
     * @return array[]
     */
    public static function map_for($seed) {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return array();
        }

        $rows = (array) $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . ECP_DB::topics_table() . '
              WHERE seed = %s
              ORDER BY (parent = %s) DESC, parent ASC, score DESC, topic ASC',
            $seed,
            ''
        ), ARRAY_A);

        foreach ($rows as &$row) {
            $row['supporting_queries'] = ECP_DB::decode($row['supporting_queries']);
            $row['match_basis'] = ECP_DB::decode($row['match_basis']);
        }
        unset($row);

        return $rows;
    }

    /**
     * Approve or dismiss one topic, or everything in one cluster.
     *
     * @param string $action approve | dismiss | reopen
     * @return int|WP_Error Rows affected.
     */
    public static function decide($ids, $action) {
        global $wpdb;

        $map = array(
            'approve' => self::APPROVED,
            'dismiss' => self::DISMISSED,
            'reopen'  => self::PROPOSED,
        );

        if (!isset($map[$action])) {
            return new WP_Error('ecp_bad_action', __('Unknown topic action.', 'enhanced-content-plugin'));
        }

        $ids = array_filter(array_map('intval', (array) $ids));

        if (!$ids) {
            return new WP_Error('ecp_no_ids', __('Nothing selected.', 'enhanced-content-plugin'));
        }

        $table = ECP_DB::topics_table();
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
                SET status = %s, decided_by = %d, decided_at = %s, updated_at = %s
              WHERE id IN ({$placeholders})",
            array_merge(
                array($map[$action], get_current_user_id(), ECP_DB::now(), ECP_DB::now()),
                $ids
            )
        ));

        return (int) $affected;
    }

    /**
     * Ids of every topic in a cluster (the pillar and its children),
     * for cluster-level approval.
     *
     * @return int[]
     */
    public static function cluster_ids($seed, $parent) {
        global $wpdb;

        $table = ECP_DB::topics_table();

        $ids = (array) $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$table}
              WHERE seed = %s AND status = %s AND (parent = %s OR topic = %s)",
            $seed,
            self::PROPOSED,
            $parent,
            $parent
        ));

        return array_map('intval', $ids);
    }

    /**
     * Restraint headline for the screen and dashboard: how much the
     * engine talked the site out of writing.
     *
     * @return array { mapped, write, expand, subsection, skipped, approved }
     */
    public static function stats() {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return array('mapped' => 0, 'write' => 0, 'expand' => 0, 'subsection' => 0, 'skipped' => 0, 'approved' => 0);
        }

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT COUNT(*) AS mapped,
                    SUM(verdict = %s) AS write_count,
                    SUM(verdict = %s) AS expand_count,
                    SUM(verdict = %s) AS subsection_count,
                    SUM(verdict = %s) AS skip_count,
                    SUM(status = %s) AS approved_count
               FROM ' . ECP_DB::topics_table(),
            self::WRITE,
            self::EXPAND,
            self::SUBSECTION,
            self::SKIP,
            self::APPROVED
        ), ARRAY_A);

        return array(
            'mapped'     => (int) $row['mapped'],
            'write'      => (int) $row['write_count'],
            'expand'     => (int) $row['expand_count'],
            'subsection' => (int) $row['subsection_count'],
            'skipped'    => (int) $row['skip_count'],
            'approved'   => (int) $row['approved_count'],
        );
    }

    /**
     * Labels.
     */
    public static function verdict_label($verdict) {
        $labels = array(
            self::WRITE      => __('Worth writing', 'enhanced-content-plugin'),
            self::EXPAND     => __('Expand an existing page', 'enhanced-content-plugin'),
            self::SUBSECTION => __('Add as a section', 'enhanced-content-plugin'),
            self::SKIP       => __('Not worth a new page', 'enhanced-content-plugin'),
        );

        return isset($labels[$verdict]) ? $labels[$verdict] : $verdict;
    }

    public static function page_type_label($type) {
        $labels = array(
            'pillar_guide'       => __('Pillar guide', 'enhanced-content-plugin'),
            'supporting_article' => __('Supporting article', 'enhanced-content-plugin'),
            'product_page'       => __('Product page', 'enhanced-content-plugin'),
            'product_category'   => __('Product category', 'enhanced-content-plugin'),
            'service_page'       => __('Service page', 'enhanced-content-plugin'),
            'comparison_page'    => __('Comparison', 'enhanced-content-plugin'),
            'alternatives_page'  => __('Alternatives', 'enhanced-content-plugin'),
            'cost_guide'         => __('Cost guide', 'enhanced-content-plugin'),
            'how_to_guide'       => __('How-to', 'enhanced-content-plugin'),
            'troubleshooting'    => __('Troubleshooting', 'enhanced-content-plugin'),
            'faq_resource'       => __('FAQ resource', 'enhanced-content-plugin'),
            'glossary'           => __('Glossary', 'enhanced-content-plugin'),
            'local_service_page' => __('Local service page', 'enhanced-content-plugin'),
            'case_study'         => __('Case study', 'enhanced-content-plugin'),
            'original_research'  => __('Original research', 'enhanced-content-plugin'),
            'tool_calculator'    => __('Tool or calculator', 'enhanced-content-plugin'),
        );

        return isset($labels[$type]) ? $labels[$type] : ucwords(str_replace('_', ' ', (string) $type));
    }
}
