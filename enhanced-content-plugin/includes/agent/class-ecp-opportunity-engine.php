<?php
/**
 * Decides which posts are worth spending an AI call on.
 *
 * Scoring runs entirely on stored data — no API calls — so a full-site scan
 * of a few thousand posts costs nothing but database time. Only the top of
 * the resulting queue gets analyzed.
 *
 * The score answers one question: how much upside is there, and how confident
 * are we? A thin page nobody searches for scores low. A page ranking #11 for a
 * term with 5,000 impressions and no meta description scores high.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ECP_Opportunity_Engine {

    /* Status values on the opportunities table. */
    const STATUS_OPEN      = 'open';
    const STATUS_ANALYZING = 'analyzing';
    const STATUS_PROPOSED  = 'proposed';
    const STATUS_DONE      = 'done';
    const STATUS_DISMISSED = 'dismissed';
    const STATUS_SNOOZED   = 'snoozed';

    /**
     * Scan a batch of posts and refresh their opportunity rows.
     *
     * @param int $offset
     * @param int $limit
     * @return array { processed, total, scored }
     */
    public static function scan_batch($offset = 0, $limit = 50) {
        $post_types = (array) ECP_Agent_Settings::get('post_types', array('post'));
        $excluded = ECP_Agent_Settings::excluded_post_ids();
        $min_age = (int) ECP_Agent_Settings::get('min_post_age_days', 14);

        $args = array(
            'post_type'      => $post_types,
            'post_status'    => ECP_Agent_Settings::is_on('require_published') ? 'publish' : array('publish', 'draft', 'private'),
            'posts_per_page' => max(1, (int) $limit),
            'offset'         => max(0, (int) $offset),
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'fields'         => 'ids',
            'no_found_rows'  => false,
            'suppress_filters' => false,
        );

        if ($excluded) {
            $args['post__not_in'] = $excluded;
        }

        if ($min_age > 0) {
            $args['date_query'] = array(
                array(
                    'column' => 'post_date_gmt',
                    'before' => gmdate('Y-m-d H:i:s', strtotime("-{$min_age} days")),
                ),
            );
        }

        $excluded_terms = self::excluded_tax_query();
        if ($excluded_terms) {
            $args['tax_query'] = $excluded_terms;
        }

        $query = new WP_Query($args);
        $scored = 0;

        foreach ($query->posts as $post_id) {
            if (self::score_post($post_id)) {
                $scored++;
            }
        }

        return array(
            'processed' => count($query->posts),
            'total'     => (int) $query->found_posts,
            'scored'    => $scored,
        );
    }

    /**
     * Build the tax_query that excludes opted-out categories/tags.
     *
     * @return array
     */
    private static function excluded_tax_query() {
        $refs = (array) ECP_Agent_Settings::get('excluded_terms', array());
        if (!$refs) {
            return array();
        }

        $by_taxonomy = array();
        foreach ($refs as $ref) {
            $parts = explode(':', (string) $ref);
            if (2 !== count($parts)) {
                continue;
            }
            $by_taxonomy[$parts[0]][] = (int) $parts[1];
        }

        if (!$by_taxonomy) {
            return array();
        }

        $tax_query = array('relation' => 'AND');
        foreach ($by_taxonomy as $taxonomy => $term_ids) {
            $tax_query[] = array(
                'taxonomy' => $taxonomy,
                'field'    => 'term_id',
                'terms'    => $term_ids,
                'operator' => 'NOT IN',
            );
        }

        return $tax_query;
    }

    /**
     * Score one post and upsert its opportunity row.
     *
     * @param int $post_id
     * @return array|false The stored row data, or false when the post is not eligible.
     */
    public static function score_post($post_id) {
        global $wpdb;

        $post = get_post($post_id);
        if (!$post) {
            return false;
        }

        if (in_array((int) $post_id, ECP_Agent_Settings::excluded_post_ids(), true)) {
            return false;
        }

        $signals = ECP_Signals::collect($post);
        if (!$signals) {
            return false;
        }

        // The scorer and the analyzer must read from the same picture. They
        // previously did not: this ranked pages highly for low CTR while the
        // analyzer was never told CTR existed.
        $search = ECP_Search_Data::context($post_id);
        $issues = ECP_Signals::issues($signals, $search);

        // The inventory rides the scan: the signals collected for scoring
        // are exactly what the inventory stores, so refreshing here costs
        // no extra parsing and inherits the scan's batching and resume.
        ECP_Inventory::refresh($post, $signals);

        $metrics = $search ? $search['page'] : null;
        $striking = $search ? $search['striking'] : array();

        $breakdown = self::compute_score($signals, $issues, $metrics, $striking);

        $table = ECP_DB::opportunities_table();
        $now = ECP_DB::now();
        $hash = ECP_Content_Map::content_hash($post);

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status, snoozed_until, created_at FROM {$table} WHERE post_id = %d",
            (int) $post_id
        ), ARRAY_A);

        // A dismissed post stays dismissed until its content actually changes.
        $status = self::STATUS_OPEN;
        if ($existing) {
            $status = $existing['status'];

            if (self::STATUS_DISMISSED === $status || self::STATUS_DONE === $status) {
                $previous_hash = $wpdb->get_var($wpdb->prepare(
                    "SELECT content_hash FROM {$table} WHERE post_id = %d",
                    (int) $post_id
                ));

                if ($previous_hash === $hash) {
                    // Nothing changed — leave it alone but refresh the timestamp.
                    $wpdb->update(
                        $table,
                        array('last_scanned_at' => $now, 'updated_at' => $now),
                        array('post_id' => (int) $post_id),
                        array('%s', '%s'),
                        array('%d')
                    );

                    return false;
                }

                $status = self::STATUS_OPEN;   // Re-open: the content moved.
            }

            if (self::STATUS_SNOOZED === $status && !empty($existing['snoozed_until'])) {
                if (strtotime($existing['snoozed_until']) <= current_time('timestamp')) {
                    $status = self::STATUS_OPEN;
                }
            }
        }

        $data = array(
            'post_id'          => (int) $post_id,
            'score'            => round($breakdown['score'], 2),
            'potential_clicks' => round($breakdown['potential_clicks'], 2),
            'primary_reason'   => $breakdown['primary_reason'],
            'reasons'          => ECP_DB::encode($issues),
            'signals'          => ECP_DB::encode(ECP_Signals::compact_signals($signals)),
            'status'           => $status,
            'content_hash'     => $hash,
            'last_scanned_at'  => $now,
            'updated_at'       => $now,
        );

        $formats = array('%d', '%f', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s');

        if ($existing) {
            $wpdb->update($table, $data, array('post_id' => (int) $post_id), $formats, array('%d'));
        } else {
            $data['created_at'] = $now;
            $formats[] = '%s';
            $wpdb->insert($table, $data, $formats);
        }

        return $data;
    }

    /**
     * The scoring model.
     *
     * Three multiplicative-ish components, deliberately kept legible so the
     * UI can explain a score to the user rather than showing a black box:
     *
     *   issue_weight    How much is demonstrably wrong on the page (0-60)
     *   search_weight   How much traffic is realistically within reach (0-100)
     *   confidence      How sure we are the effort is worth it (0.4-1.0)
     *
     * @return array { score, potential_clicks, primary_reason, components }
     */
    public static function compute_score(array $signals, array $issues, $metrics = null, array $striking = array()) {
        // --- Issue weight -------------------------------------------------
        $severity_points = array('high' => 12, 'medium' => 6, 'low' => 2);
        $issue_weight = 0;
        $top_issue = '';
        $top_points = 0;

        foreach ($issues as $issue) {
            $points = isset($severity_points[$issue['severity']]) ? $severity_points[$issue['severity']] : 2;
            $issue_weight += $points;

            if ($points > $top_points) {
                $top_points = $points;
                $top_issue = $issue['code'];
            }
        }

        $issue_weight = min(60, $issue_weight);

        // --- Search weight --------------------------------------------------
        $search_weight = 0;
        $potential_clicks = 0.0;
        $search_reason = '';

        if ($metrics && $metrics['impressions'] > 0) {
            $impressions = (int) $metrics['impressions'];
            $position = (float) $metrics['position'];
            $ctr = (float) $metrics['ctr'];

            // Striking distance: positions 5-20 are where an edit plausibly
            // moves the needle. Position 1-4 is already won; 30+ needs more
            // than a content tweak.
            if ($position >= 5 && $position <= 20) {
                $search_weight += 35;
                $search_reason = 'striking_distance';
            } elseif ($position > 20 && $position <= 35) {
                $search_weight += 12;
            }

            // Impression volume, log-scaled so a 100k-impression page doesn't
            // drown out everything else on the site.
            $search_weight += min(30, 6 * log10(max(10, $impressions)));

            // CTR gap against a rough expected curve for the position.
            $expected_ctr = self::expected_ctr($position);
            if ($expected_ctr > 0 && $ctr < $expected_ctr * 0.6) {
                $search_weight += 20;
                if (!$search_reason) {
                    $search_reason = 'low_ctr';
                }
                $potential_clicks += ($expected_ctr - $ctr) * $impressions;
            }

            // Upside from moving into the top 5.
            if ($position > 5 && $position <= 20) {
                $potential_clicks += max(0, (self::expected_ctr(4) - $ctr)) * $impressions * 0.35;
            }

            if ($striking) {
                $search_weight += min(15, count($striking) * 3);
            }
        } else {
            // No search data at all. Fall back to the page's own substance so
            // the queue is still ordered sensibly, but cap the contribution —
            // we genuinely don't know if anyone wants this page.
            $search_weight += min(15, (int) $signals['word_count'] / 100);
        }

        $search_weight = min(100, $search_weight);

        // --- Confidence -------------------------------------------------------
        $confidence = 1.0;

        if (!$metrics) {
            $confidence -= 0.35;   // Guessing without query data.
        }
        if ((int) $signals['word_count'] < 200) {
            $confidence -= 0.15;   // Too little to work with.
        }
        if ((int) $signals['days_since_update'] < 30) {
            $confidence -= 0.10;   // Someone just touched this; don't fight them.
        }

        $confidence = max(0.4, min(1.0, $confidence));

        $score = ($issue_weight * 0.45 + $search_weight * 0.55) * $confidence;

        $primary_reason = $search_reason ? $search_reason : $top_issue;

        return array(
            'score'            => round($score, 2),
            'potential_clicks' => round($potential_clicks, 2),
            'primary_reason'   => $primary_reason ? $primary_reason : 'general',
            'components'       => array(
                'issue_weight'  => round($issue_weight, 2),
                'search_weight' => round($search_weight, 2),
                'confidence'    => round($confidence, 2),
                'issue_count'   => count($issues),
            ),
        );
    }

    /**
     * Rough organic CTR by average position. Industry aggregate, used only to
     * spot pages performing far below what their position should deliver.
     */
    public static function expected_ctr($position) {
        $curve = array(
            1 => 0.275, 2 => 0.153, 3 => 0.100, 4 => 0.072, 5 => 0.054,
            6 => 0.042, 7 => 0.034, 8 => 0.028, 9 => 0.024, 10 => 0.021,
        );

        $rounded = (int) round($position);

        if ($rounded < 1) {
            return $curve[1];
        }
        if (isset($curve[$rounded])) {
            return $curve[$rounded];
        }
        if ($rounded <= 20) {
            return 0.012;
        }
        if ($rounded <= 30) {
            return 0.006;
        }

        return 0.002;
    }

    /* --------------------------------------------------------------------
     * Reading the queue
     * ----------------------------------------------------------------- */

    /**
     * @param array $args { status, limit, offset, orderby, order, search }
     * @return array { items: array[], total: int }
     */
    public static function query($args = array()) {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return array('items' => array(), 'total' => 0);
        }

        $args = wp_parse_args($args, array(
            'status'  => '',
            'limit'   => 25,
            'offset'  => 0,
            'orderby' => 'score',
            'order'   => 'DESC',
            'search'  => '',
            'author'  => 0,
        ));

        $table = ECP_DB::opportunities_table();
        $posts = $wpdb->posts;

        $where = array('p.post_status != %s');
        $params = array('trash');

        if ($args['status']) {
            $where[] = 'o.status = %s';
            $params[] = $args['status'];
        }

        if ($args['search']) {
            $where[] = 'p.post_title LIKE %s';
            $params[] = '%' . $wpdb->esc_like($args['search']) . '%';
        }

        // Restricted reviewers only see their own pages, matching what the
        // review queue will let them act on.
        if ($args['author']) {
            $where[] = 'p.post_author = %d';
            $params[] = (int) $args['author'];
        }

        $where_sql = implode(' AND ', $where);

        $allowed_orderby = array(
            'score'            => 'o.score',
            'potential_clicks' => 'o.potential_clicks',
            'title'            => 'p.post_title',
            'modified'         => 'p.post_modified',
            'scanned'          => 'o.last_scanned_at',
        );
        $orderby = isset($allowed_orderby[$args['orderby']]) ? $allowed_orderby[$args['orderby']] : 'o.score';
        $order = 'ASC' === strtoupper($args['order']) ? 'ASC' : 'DESC';

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} o INNER JOIN {$posts} p ON p.ID = o.post_id WHERE {$where_sql}",
            $params
        ));

        $limit = max(1, min(200, (int) $args['limit']));
        $offset = max(0, (int) $args['offset']);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT o.*, p.post_title, p.post_modified, p.post_type
             FROM {$table} o
             INNER JOIN {$posts} p ON p.ID = o.post_id
             WHERE {$where_sql}
             ORDER BY {$orderby} {$order}
             LIMIT %d OFFSET %d",
            array_merge($params, array($limit, $offset))
        ), ARRAY_A);

        foreach ($rows as &$row) {
            $row['reasons'] = ECP_DB::decode($row['reasons']);
            $row['signals'] = ECP_DB::decode($row['signals']);
        }
        unset($row);

        return array('items' => $rows ? $rows : array(), 'total' => $total);
    }

    /**
     * The single action most worth doing today.
     *
     * The top open opportunities by score, with one deliberate thumb on
     * the scale: when a CTR-shaped problem scores close to the leader, it
     * wins. A snippet fix is an afternoon's approval for the same upside a
     * restructure buys with days — cheapest win first is the whole point
     * of a "do this today" card.
     *
     * @return array|null Opportunity row (with post_title) or null.
     */
    public static function top_priority() {
        $result = self::query(array(
            'status'   => self::STATUS_OPEN,
            'limit'    => 5,
            'orderby'  => 'score',
            'order'    => 'DESC',
        ));

        if (empty($result['items'])) {
            return null;
        }

        $items = $result['items'];
        $top = $items[0];
        $cheap = array('low_ctr', 'zero_clicks');

        foreach ($items as $item) {
            if (in_array($item['primary_reason'], $cheap, true) && (float) $item['score'] >= 0.8 * (float) $top['score']) {
                return $item;
            }
        }

        return $top;
    }

    /**
     * The next N posts that should be analyzed.
     *
     * Skips anything already analyzed since its content last changed, so
     * repeat runs don't burn budget re-analyzing an unchanged article.
     *
     * @return int[] Post IDs.
     */
    public static function next_for_analysis($limit = 5) {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return array();
        }

        $table = ECP_DB::opportunities_table();
        $posts = $wpdb->posts;
        $now = ECP_DB::now();

        // Roadmap steps the owner approved jump the queue: approval is an
        // explicit request for those proposals, and the score ordering
        // only decides among pages nobody has asked about.
        $approved = class_exists('ECP_Roadmap') ? ECP_Roadmap::approved_post_ids() : array();
        $approved_sql = '';
        $params = array(self::STATUS_OPEN, $now);

        if ($approved) {
            $placeholders = implode(',', array_fill(0, count($approved), '%d'));
            $approved_sql = "(o.post_id IN ({$placeholders})) DESC, ";
            $params = array_merge($params, $approved);
        }

        $params[] = max(1, (int) $limit);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT o.post_id
             FROM {$table} o
             INNER JOIN {$posts} p ON p.ID = o.post_id
             WHERE o.status = %s
               AND p.post_status = 'publish'
               AND (
                     o.last_analyzed_at IS NULL
                  OR o.last_analyzed_at < o.last_scanned_at
               )
               AND (o.snoozed_until IS NULL OR o.snoozed_until <= %s)
             ORDER BY {$approved_sql}o.score DESC
             LIMIT %d",
            $params
        ));

        return array_map('intval', wp_list_pluck((array) $rows, 'post_id'));
    }

    /**
     * @return array|null
     */
    public static function get($post_id) {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return null;
        }

        $table = ECP_DB::opportunities_table();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE post_id = %d",
            (int) $post_id
        ), ARRAY_A);

        if (!$row) {
            return null;
        }

        $row['reasons'] = ECP_DB::decode($row['reasons']);
        $row['signals'] = ECP_DB::decode($row['signals']);

        return $row;
    }

    /**
     * Move an opportunity to a new status.
     *
     * @param int    $post_id
     * @param string $status
     * @param array  $extra Additional columns (e.g. snoozed_until).
     */
    public static function set_status($post_id, $status, array $extra = array()) {
        global $wpdb;

        $data = array_merge($extra, array(
            'status'     => $status,
            'updated_at' => ECP_DB::now(),
        ));

        return (bool) $wpdb->update(
            ECP_DB::opportunities_table(),
            $data,
            array('post_id' => (int) $post_id),
            null,
            array('%d')
        );
    }

    /**
     * Mark that an analysis just ran for this post.
     */
    public static function mark_analyzed($post_id, $proposals_created) {
        global $wpdb;

        $wpdb->update(
            ECP_DB::opportunities_table(),
            array(
                'status'           => $proposals_created > 0 ? self::STATUS_PROPOSED : self::STATUS_DONE,
                'last_analyzed_at' => ECP_DB::now(),
                'updated_at'       => ECP_DB::now(),
            ),
            array('post_id' => (int) $post_id),
            array('%s', '%s', '%s'),
            array('%d')
        );
    }

    /**
     * Dashboard counters.
     *
     * @return array
     */
    public static function stats() {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return array('total' => 0, 'open' => 0, 'proposed' => 0, 'done' => 0, 'avg_score' => 0, 'potential_clicks' => 0);
        }

        $table = ECP_DB::opportunities_table();

        $row = $wpdb->get_row(
            "SELECT
                COUNT(*) AS total,
                SUM(status = 'open') AS open_count,
                SUM(status = 'proposed') AS proposed_count,
                SUM(status = 'done') AS done_count,
                AVG(score) AS avg_score,
                SUM(potential_clicks) AS potential_clicks
             FROM {$table}",
            ARRAY_A
        );

        return array(
            'total'            => (int) $row['total'],
            'open'             => (int) $row['open_count'],
            'proposed'         => (int) $row['proposed_count'],
            'done'             => (int) $row['done_count'],
            'avg_score'        => round((float) $row['avg_score'], 1),
            'potential_clicks' => (int) round((float) $row['potential_clicks']),
        );
    }

    /**
     * Friendly label for a primary_reason code.
     */
    public static function reason_label($code) {
        $labels = array(
            'striking_distance'    => __('Ranking on page 2 — within reach', 'enhanced-content-plugin'),
            'low_ctr'              => __('Getting impressions but few clicks', 'enhanced-content-plugin'),
            'zero_clicks'          => __('Shown in search, never clicked', 'enhanced-content-plugin'),
            'query_gap'            => __('Ranks for topics it barely covers', 'enhanced-content-plugin'),
            'question_queries'     => __('Searchers ask questions it does not answer', 'enhanced-content-plugin'),
            'traffic_decline'      => __('Losing traffic', 'enhanced-content-plugin'),
            'never_seen'           => __('No search data at all — check indexing', 'enhanced-content-plugin'),
            'noindexed'            => __('Blocked from search by a noindex tag', 'enhanced-content-plugin'),
            'canonical_elsewhere'  => __('Canonical points at a different URL', 'enhanced-content-plugin'),
            'thin_content'         => __('Thin content', 'enhanced-content-plugin'),
            'no_meta_description'  => __('Missing meta description', 'enhanced-content-plugin'),
            'short_meta_description' => __('Meta description too short', 'enhanced-content-plugin'),
            'long_meta_description' => __('Meta description will be truncated', 'enhanced-content-plugin'),
            'long_title'           => __('Title may be truncated', 'enhanced-content-plugin'),
            'stale'                => __('Content is stale', 'enhanced-content-plugin'),
            'aging'                => __('Getting stale', 'enhanced-content-plugin'),
            'orphan_page'          => __('No internal links point here', 'enhanced-content-plugin'),
            'weak_intro'           => __('Weak introduction', 'enhanced-content-plugin'),
            'no_sources'           => __('No sources cited', 'enhanced-content-plugin'),
            'few_internal_links'   => __('Few internal links', 'enhanced-content-plugin'),
            'missing_alt_text'     => __('Images missing alt text', 'enhanced-content-plugin'),
            'no_subheadings'       => __('No subheadings', 'enhanced-content-plugin'),
            'thin_sections'        => __('Sections with almost no content', 'enhanced-content-plugin'),
            'long_sections'        => __('Overlong sections worth tightening', 'enhanced-content-plugin'),
            'wall_of_text'         => __('Very long paragraphs', 'enhanced-content-plugin'),
            'generic_anchors'      => __('Generic link text', 'enhanced-content-plugin'),
            'heading_hierarchy'    => __('Heading levels skip a step', 'enhanced-content-plugin'),
            'no_faq'               => __('No FAQ section', 'enhanced-content-plugin'),
            'no_seo_plugin'        => __('No SEO plugin active', 'enhanced-content-plugin'),
            'dated_title'          => __('Title promises an out-of-date year', 'enhanced-content-plugin'),
            'dated_statements'     => __('Contains statements anchored to old years', 'enhanced-content-plugin'),
            'undated_recency'      => __('Says "recently" but has not been touched in a year', 'enhanced-content-plugin'),
            'broken_links'         => __('Links to pages that no longer exist', 'enhanced-content-plugin'),
            'no_affiliate_disclosure' => __('Affiliate links with no disclosure', 'enhanced-content-plugin'),
            'insecure_links'       => __('Links over plain http', 'enhanced-content-plugin'),
            'unanswered_comments'  => __('Unanswered reader questions in the comments', 'enhanced-content-plugin'),
            'no_images'            => __('Long article with no images', 'enhanced-content-plugin'),
            'no_author_info'       => __('No author information (E-E-A-T)', 'enhanced-content-plugin'),
            'general'              => __('General improvements available', 'enhanced-content-plugin'),
        );

        return isset($labels[$code]) ? $labels[$code] : ucwords(str_replace('_', ' ', (string) $code));
    }
}
