<?php
/**
 * The site's structured self-knowledge: one row per post.
 *
 * Phase 1 of the growth system. Everything downstream — classification,
 * topical coverage, the intelligence report, later the topical maps and
 * duplicate prevention — reads from here instead of re-walking WordPress.
 *
 * Refresh rides the existing hourly scan: score_post() already collects
 * the signals this table stores, so inventory costs no extra parsing and
 * inherits the scan's coverage, batching and resume behaviour for free.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ECP_Inventory {

    /**
     * Create or update a post's row from freshly collected signals.
     *
     * Classification columns are deliberately never written here — they
     * belong to the classifier, and an UPDATE that listed them would reset
     * a classification every time the scan ran. The `locked` flag likewise
     * survives every refresh.
     *
     * @param WP_Post $post
     * @param array   $signals Output of ECP_Signals::collect().
     */
    public static function refresh($post, array $signals) {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return;
        }

        $headings = array();

        foreach (ECP_Content_Map::sections($post) as $section) {
            if (!$section['is_intro']) {
                $headings[] = array('level' => (int) $section['level'], 'text' => $section['heading']);
            }
        }

        $taxonomy = array();

        foreach (array('category', 'post_tag') as $tax) {
            $terms = get_the_terms($post->ID, $tax);

            if ($terms && !is_wp_error($terms)) {
                $taxonomy[$tax] = wp_list_pluck($terms, 'name');
            }
        }

        // Schema emitted by this plugin's own toolkit. Third-party SEO
        // plugins add more; claiming to know their output would be a guess,
        // and this column informs rather than gates anything.
        $schema_types = array('Article');

        if (!empty($signals['has_faq'])) {
            $schema_types[] = 'FAQPage';
        }

        $now = ECP_DB::now();

        $data = array(
            'post_id'            => (int) $post->ID,
            'url'                => (string) get_permalink($post),
            'post_type'          => $post->post_type,
            'post_status'        => $post->post_status,
            'title'              => $post->post_title,
            'meta_description'   => mb_substr((string) $signals['effective_description'], 0, 255),
            'word_count'         => (int) $signals['word_count'],
            'heading_json'       => ECP_DB::encode($headings),
            'taxonomy_json'      => ECP_DB::encode($taxonomy),
            'author_id'          => (int) $post->post_author,
            'internal_links_out' => (int) $signals['internal_links'],
            'internal_links_in'  => (int) $signals['inbound_internal_links'],
            'external_links'     => (int) $signals['external_links'],
            'image_count'        => (int) $signals['image_count'],
            'schema_types'       => implode(',', $schema_types),
            'content_hash'       => ECP_Content_Map::content_hash($post),
            'scanned_at'         => $now,
            'updated_at'         => $now,
        );

        $exists = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . ECP_DB::inventory_table() . ' WHERE post_id = %d',
            (int) $post->ID
        ));

        if ($exists) {
            // Explicit UPDATE, never REPLACE — REPLACE deletes the row and
            // with it the classification this method must not touch.
            $wpdb->update(ECP_DB::inventory_table(), $data, array('id' => $exists), null, array('%d'));
        } else {
            $data['created_at'] = $now;
            $wpdb->insert(ECP_DB::inventory_table(), $data);
        }
    }

    /**
     * Remove rows whose post is gone or out of scope. Daily maintenance.
     *
     * @return int Rows removed.
     */
    public static function prune() {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return 0;
        }

        $table = ECP_DB::inventory_table();

        // Posts that were deleted outright.
        $removed = (int) $wpdb->query(
            "DELETE i FROM {$table} i
              LEFT JOIN {$wpdb->posts} p ON p.ID = i.post_id
             WHERE p.ID IS NULL"  // phpcs:ignore WordPress.DB.PreparedSQL
        );

        // Posts whose type is no longer in scope.
        $types = (array) ECP_Agent_Settings::get('post_types', array('post'));

        if ($types) {
            $placeholders = implode(',', array_fill(0, count($types), '%s'));

            $removed += (int) $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table} WHERE post_type NOT IN ({$placeholders})",  // phpcs:ignore WordPress.DB.PreparedSQL
                $types
            ));
        }

        return $removed;
    }

    /**
     * Rows needing classification: never classified, or the content has
     * moved since. Locked rows are a human's answer and are never eligible.
     *
     * @return array[] { post_id, title, heading_json, taxonomy_json, content_hash }
     */
    public static function unclassified($limit = 20) {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return array();
        }

        return (array) $wpdb->get_results($wpdb->prepare(
            'SELECT post_id, title, meta_description, heading_json, taxonomy_json, content_hash
               FROM ' . ECP_DB::inventory_table() . "
              WHERE post_status = 'publish'
                AND locked = 0
                AND (classified_hash = '' OR classified_hash != content_hash)
              ORDER BY (classified_hash = '') DESC, scanned_at ASC
              LIMIT %d",
            max(1, (int) $limit)
        ), ARRAY_A);
    }

    /**
     * Store one classification result.
     *
     * @param int   $post_id
     * @param array $result { topic, subtopic, intent, funnel_stage, confidence }
     * @param string $content_hash The hash the classification describes.
     */
    public static function store_classification($post_id, array $result, $content_hash) {
        global $wpdb;

        $wpdb->update(
            ECP_DB::inventory_table(),
            array(
                'topic'           => mb_substr(sanitize_text_field($result['topic']), 0, 191),
                'subtopic'        => mb_substr(sanitize_text_field($result['subtopic']), 0, 191),
                'intent'          => $result['intent'],
                'funnel_stage'    => $result['funnel_stage'],
                'confidence'      => max(0, min(100, (int) $result['confidence'])),
                'classified_hash' => (string) $content_hash,
                'classified_at'   => ECP_DB::now(),
                'updated_at'      => ECP_DB::now(),
            ),
            array('post_id' => (int) $post_id, 'locked' => 0),
            null,
            array('%d', '%d')
        );
    }

    /**
     * A human corrected the topic. Their word is final: stored, locked,
     * and immune to every future classification pass.
     */
    public static function override_topic($post_id, $topic) {
        global $wpdb;

        return false !== $wpdb->update(
            ECP_DB::inventory_table(),
            array(
                'topic'      => mb_substr(sanitize_text_field($topic), 0, 191),
                'locked'     => 1,
                'updated_at' => ECP_DB::now(),
            ),
            array('post_id' => (int) $post_id),
            null,
            array('%d')
        );
    }

    /**
     * Progress and coverage numbers for the intelligence screen and the
     * onboarding checklist.
     *
     * @return array
     */
    public static function stats() {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return array('total' => 0, 'published' => 0, 'classified' => 0, 'stale' => 0, 'topics' => 0);
        }

        $table = ECP_DB::inventory_table();

        $row = $wpdb->get_row(
            "SELECT COUNT(*) AS total,
                    SUM(post_status = 'publish') AS published,
                    SUM(classified_hash != '' AND classified_hash = content_hash) AS classified,
                    SUM(classified_hash != '' AND classified_hash != content_hash) AS stale,
                    COUNT(DISTINCT CASE WHEN topic != '' THEN topic END) AS topics
               FROM {$table}",  // phpcs:ignore WordPress.DB.PreparedSQL
            ARRAY_A
        );

        return array(
            'total'      => (int) $row['total'],
            'published'  => (int) $row['published'],
            'classified' => (int) $row['classified'],
            'stale'      => (int) $row['stale'],
            'topics'     => (int) $row['topics'],
        );
    }

    /**
     * Topic rollup: pages per topic with their combined search performance.
     *
     * The join takes each post's page-total metrics row (query = '') for
     * the default window's newest date, so "clicks" here means the same
     * thing it means everywhere else in the plugin.
     *
     * @return array[] { topic, pages, clicks, impressions }
     */
    public static function topics($limit = 100) {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return array();
        }

        $inventory = ECP_DB::inventory_table();
        $metrics = ECP_DB::metrics_table();
        $window = ECP_Search_Data::DEFAULT_WINDOW;
        $latest = ECP_Rankings::latest_date($window);

        if ($latest) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT i.topic,
                        COUNT(*) AS pages,
                        COALESCE(SUM(m.clicks), 0) AS clicks,
                        COALESCE(SUM(m.impressions), 0) AS impressions
                   FROM {$inventory} i
                   LEFT JOIN {$metrics} m
                     ON m.post_id = i.post_id
                    AND m.window_days = %d
                    AND m.metric_date = %s
                    AND m.query = ''
                  WHERE i.topic != '' AND i.post_status = 'publish'
                  GROUP BY i.topic
                  ORDER BY clicks DESC, pages DESC
                  LIMIT %d",
                (int) $window,
                $latest,
                max(1, (int) $limit)
            ), ARRAY_A);
        } else {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT topic, COUNT(*) AS pages, 0 AS clicks, 0 AS impressions
                   FROM {$inventory}
                  WHERE topic != '' AND post_status = 'publish'
                  GROUP BY topic
                  ORDER BY pages DESC
                  LIMIT %d",
                max(1, (int) $limit)
            ), ARRAY_A);
        }

        return array_map(function ($row) {
            return array(
                'topic'       => $row['topic'],
                'pages'       => (int) $row['pages'],
                'clicks'      => (int) $row['clicks'],
                'impressions' => (int) $row['impressions'],
            );
        }, (array) $rows);
    }

    /**
     * Counts by a classification column, for the intent/funnel mix panels.
     *
     * @param string $column 'intent' or 'funnel_stage'.
     * @return array<string,int>
     */
    public static function mix($column) {
        global $wpdb;

        if (!in_array($column, array('intent', 'funnel_stage'), true) || !ECP_DB::tables_exist()) {
            return array();
        }

        $rows = $wpdb->get_results(
            "SELECT {$column} AS k, COUNT(*) AS total
               FROM " . ECP_DB::inventory_table() . "
              WHERE {$column} != '' AND post_status = 'publish'
              GROUP BY {$column}
              ORDER BY total DESC",  // phpcs:ignore WordPress.DB.PreparedSQL
            ARRAY_A
        );

        $out = array();

        foreach ((array) $rows as $row) {
            $out[$row['k']] = (int) $row['total'];
        }

        return $out;
    }

    /**
     * Paged inventory listing for the intelligence screen.
     *
     * @param array $args { topic, intent, unclassified, search, per_page, paged }
     * @return array { items, total }
     */
    public static function query(array $args = array()) {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return array('items' => array(), 'total' => 0);
        }

        $args = wp_parse_args($args, array(
            'topic'        => '',
            'intent'       => '',
            'unclassified' => false,
            'search'       => '',
            'per_page'     => 50,
            'paged'        => 1,
        ));

        $where = array("post_status = 'publish'");
        $params = array();

        if ('' !== $args['topic']) {
            $where[] = 'topic = %s';
            $params[] = $args['topic'];
        }

        if ('' !== $args['intent']) {
            $where[] = 'intent = %s';
            $params[] = $args['intent'];
        }

        if ($args['unclassified']) {
            $where[] = "(classified_hash = '' OR classified_hash != content_hash)";
        }

        if ('' !== $args['search']) {
            $where[] = 'title LIKE %s';
            $params[] = '%' . $wpdb->esc_like($args['search']) . '%';
        }

        $table = ECP_DB::inventory_table();
        $where_sql = implode(' AND ', $where);

        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
        $total = (int) ($params ? $wpdb->get_var($wpdb->prepare($count_sql, $params)) : $wpdb->get_var($count_sql));  // phpcs:ignore WordPress.DB.PreparedSQL

        $per_page = max(1, min(200, (int) $args['per_page']));
        $offset = max(0, ((int) $args['paged'] - 1)) * $per_page;

        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY topic ASC, word_count DESC LIMIT %d OFFSET %d",  // phpcs:ignore WordPress.DB.PreparedSQL
            array_merge($params, array($per_page, $offset))
        ), ARRAY_A);

        return array('items' => (array) $items, 'total' => $total);
    }
}
