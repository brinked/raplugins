<?php
/**
 * Cross-page analysis: cannibalisation and consolidation.
 *
 * Everything else in this plugin looks at one page at a time, which means it
 * structurally cannot see the most common mid-size-site SEO problem: three
 * posts quietly competing for the same query, splitting the links and the
 * relevance signals between them so none of them wins.
 *
 * Detection runs two ways:
 *
 *   Query overlap    Two or more pages taking impressions for the same
 *                    Search Console query. This is real evidence, not a
 *                    guess, and it is the reason connecting Search Console
 *                    matters so much.
 *   Title similarity Fallback for sites with no query data. Much weaker —
 *                    similar titles are a hint, not proof — so clusters found
 *                    this way are labelled as such and scored lower.
 *
 * What it does about it is deliberately asymmetric. Retargeting a secondary
 * page (new angle, new title, new intro, a link to the primary) goes through
 * the normal proposal queue and can be applied and rolled back like anything
 * else. *Merging* two posts cannot: it means deleting a URL and setting up a
 * redirect, which this plugin does not do and should not do silently. Merge
 * advice is presented as a recommendation with a checklist, for a human.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ECP_Clusters {

    const TYPE_CANNIBALISATION = 'cannibalisation';
    const TYPE_OVERLAP         = 'overlap';

    const STATUS_OPEN      = 'open';
    const STATUS_ANALYZING = 'analyzing';
    const STATUS_PROPOSED  = 'proposed';
    const STATUS_RESOLVED  = 'resolved';
    const STATUS_DISMISSED = 'dismissed';

    /** Minimum impressions before a shared query counts as real competition. */
    const MIN_IMPRESSIONS = 10;

    /** Jaccard similarity over title tokens for the no-query-data fallback. */
    const TITLE_SIMILARITY = 0.6;

    /* --------------------------------------------------------------------
     * Detection
     * ----------------------------------------------------------------- */

    /**
     * Rebuild the cluster list.
     *
     * Cheap — no AI, pure SQL and string comparison — so it can run on the
     * normal maintenance schedule.
     *
     * @return array { found: int, source: string }
     */
    public static function detect() {
        if (!ECP_DB::tables_exist()) {
            return array('found' => 0, 'source' => 'none');
        }

        $clusters = self::detect_from_queries();
        $source = 'queries';

        if (!$clusters) {
            $clusters = self::detect_from_titles();
            $source = 'titles';
        }

        $stored = 0;

        foreach ($clusters as $cluster) {
            if (self::store($cluster)) {
                $stored++;
            }
        }

        return array('found' => $stored, 'source' => $source);
    }

    /**
     * Pages competing for the same Search Console query.
     *
     * @return array[]
     */
    private static function detect_from_queries() {
        global $wpdb;

        $metrics = ECP_DB::metrics_table();

        $latest = $wpdb->get_var("SELECT MAX(metric_date) FROM {$metrics}");

        if (!$latest) {
            return array();
        }

        // Queries that more than one page is taking impressions for.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT query,
                    post_id,
                    clicks,
                    impressions,
                    position
             FROM {$metrics}
             WHERE metric_date = %s
               AND query != ''
               AND impressions >= %d
               AND query IN (
                   SELECT q FROM (
                       SELECT query AS q
                       FROM {$metrics}
                       WHERE metric_date = %s AND query != '' AND impressions >= %d
                       GROUP BY query
                       HAVING COUNT(DISTINCT post_id) > 1
                   ) AS competing
               )
             ORDER BY query ASC, impressions DESC",
            $latest,
            self::MIN_IMPRESSIONS,
            $latest,
            self::MIN_IMPRESSIONS
        ), ARRAY_A);

        if (!$rows) {
            return array();
        }

        // Group rows by query.
        $by_query = array();
        foreach ($rows as $row) {
            $by_query[$row['query']][] = $row;
        }

        // Then group queries by the *set of posts* they involve, so three
        // queries all splitting between the same two posts become one
        // cluster rather than three near-identical ones.
        $by_members = array();

        foreach ($by_query as $query => $entries) {
            $post_ids = array_values(array_unique(array_map('intval', wp_list_pluck($entries, 'post_id'))));
            sort($post_ids);

            // A cluster of 6+ pages is almost always a category archive or a
            // paginated series rather than genuine competition.
            if (count($post_ids) < 2 || count($post_ids) > 5) {
                continue;
            }

            if (!self::members_are_eligible($post_ids)) {
                continue;
            }

            $key = implode('-', $post_ids);

            if (!isset($by_members[$key])) {
                $by_members[$key] = array(
                    'members' => $post_ids,
                    'queries' => array(),
                );
            }

            $by_members[$key]['queries'][] = array(
                'query'       => $query,
                'impressions' => array_sum(wp_list_pluck($entries, 'impressions')),
                'pages'       => array_map(function ($entry) {
                    return array(
                        'post_id'     => (int) $entry['post_id'],
                        'clicks'      => (int) $entry['clicks'],
                        'impressions' => (int) $entry['impressions'],
                        'position'    => (float) $entry['position'],
                    );
                }, $entries),
            );
        }

        $clusters = array();

        foreach ($by_members as $group) {
            // Strongest queries first — that is the order a human wants to
            // read the evidence in.
            usort($group['queries'], function ($a, $b) {
                return $b['impressions'] <=> $a['impressions'];
            });

            $total_impressions = array_sum(wp_list_pluck($group['queries'], 'impressions'));

            $clusters[] = array(
                'type'     => self::TYPE_CANNIBALISATION,
                'members'  => $group['members'],
                'label'    => $group['queries'][0]['query'],
                'evidence' => array(
                    'source'            => 'search_console',
                    'queries'           => array_slice($group['queries'], 0, 10),
                    'query_count'       => count($group['queries']),
                    'total_impressions' => $total_impressions,
                ),
                'score'    => self::score_query_cluster($group['queries'], $group['members']),
            );
        }

        return $clusters;
    }

    /**
     * Fallback for sites with no query data: pages whose titles say almost
     * the same thing.
     *
     * @return array[]
     */
    private static function detect_from_titles() {
        $post_types = (array) ECP_Agent_Settings::get('post_types', array('post'));

        $query = new WP_Query(array(
            'post_type'      => $post_types,
            'post_status'    => 'publish',
            'posts_per_page' => 400,   // O(n²) below; bound it.
            'orderby'        => 'date',
            'order'          => 'DESC',
            'no_found_rows'  => true,
            'fields'         => 'ids',
        ));

        $posts = array();

        foreach ($query->posts as $post_id) {
            $tokens = self::title_tokens(get_the_title($post_id));

            // Two- and three-word titles produce false positives constantly.
            if (count($tokens) < 3) {
                continue;
            }

            $posts[(int) $post_id] = $tokens;
        }

        if (count($posts) < 2) {
            return array();
        }

        $ids = array_keys($posts);
        $pairs = array();

        for ($i = 0, $n = count($ids); $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $ids[$i];
                $b = $ids[$j];

                $similarity = self::jaccard($posts[$a], $posts[$b]);

                if ($similarity >= self::TITLE_SIMILARITY) {
                    $pairs[] = array('members' => array($a, $b), 'similarity' => $similarity);
                }
            }
        }

        $clusters = array();

        foreach ($pairs as $pair) {
            if (!self::members_are_eligible($pair['members'])) {
                continue;
            }

            sort($pair['members']);

            $clusters[] = array(
                'type'     => self::TYPE_OVERLAP,
                'members'  => $pair['members'],
                'label'    => get_the_title($pair['members'][0]),
                'evidence' => array(
                    'source'     => 'title_similarity',
                    'similarity' => round($pair['similarity'], 2),
                    'note'       => __('No Search Console data was available, so this was found by comparing titles. Titles being similar is a hint, not proof that these pages compete.', 'enhanced-content-plugin'),
                ),
                // Capped low on purpose: this evidence is much weaker than a
                // measured shared query, and should not outrank one.
                'score'    => min(35, round($pair['similarity'] * 40, 2)),
            );
        }

        return $clusters;
    }

    /**
     * Every member must be a real, published, non-excluded post.
     */
    private static function members_are_eligible(array $post_ids) {
        $excluded = ECP_Agent_Settings::excluded_post_ids();
        $post_types = (array) ECP_Agent_Settings::get('post_types', array('post'));

        foreach ($post_ids as $post_id) {
            if (in_array((int) $post_id, $excluded, true)) {
                return false;
            }

            $post = get_post((int) $post_id);

            if (!$post || 'publish' !== $post->post_status) {
                return false;
            }

            if (!in_array($post->post_type, $post_types, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * How much is at stake, and how badly split.
     */
    private static function score_query_cluster(array $queries, array $members) {
        $impressions = array_sum(wp_list_pluck($queries, 'impressions'));

        // Volume, log-scaled so one huge query doesn't drown everything else.
        $score = min(45, 9 * log10(max(10, $impressions)));

        // More shared queries means a more systemic overlap.
        $score += min(20, count($queries) * 4);

        // The damaging case is two pages at *similar* positions trading
        // places. One at #4 and one at #60 is not really competition.
        $closest_gap = null;

        foreach ($queries as $query) {
            $positions = wp_list_pluck($query['pages'], 'position');
            sort($positions);

            for ($i = 1, $n = count($positions); $i < $n; $i++) {
                $gap = abs($positions[$i] - $positions[$i - 1]);

                if (null === $closest_gap || $gap < $closest_gap) {
                    $closest_gap = $gap;
                }
            }
        }

        if (null !== $closest_gap) {
            if ($closest_gap <= 5) {
                $score += 25;
            } elseif ($closest_gap <= 15) {
                $score += 12;
            }
        }

        // Three-plus pages on one query is worse than two.
        $score += min(10, (count($members) - 2) * 5);

        return round(min(100, $score), 2);
    }

    /* --------------------------------------------------------------------
     * Title tokenising
     * ----------------------------------------------------------------- */

    /**
     * @return string[]
     */
    private static function title_tokens($title) {
        $stopwords = array(
            'a', 'an', 'and', 'are', 'as', 'at', 'be', 'best', 'but', 'by', 'can',
            'do', 'for', 'from', 'guide', 'how', 'in', 'is', 'it', 'of', 'on', 'or',
            'the', 'to', 'top', 'vs', 'what', 'why', 'with', 'you', 'your',
        );

        $title = strtolower(wp_strip_all_tags((string) $title));
        $title = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $title);

        $tokens = preg_split('/\s+/u', (string) $title, -1, PREG_SPLIT_NO_EMPTY);
        $tokens = array_diff((array) $tokens, $stopwords);

        // Crude singularisation so "shoes" and "shoe" match.
        $tokens = array_map(function ($token) {
            return (strlen($token) > 3 && 's' === substr($token, -1) && 'ss' !== substr($token, -2))
                ? substr($token, 0, -1)
                : $token;
        }, $tokens);

        return array_values(array_unique($tokens));
    }

    private static function jaccard(array $a, array $b) {
        $intersection = count(array_intersect($a, $b));
        $union = count(array_unique(array_merge($a, $b)));

        return $union > 0 ? $intersection / $union : 0.0;
    }

    /* --------------------------------------------------------------------
     * Storage
     * ----------------------------------------------------------------- */

    /**
     * Upsert a detected cluster, keyed on its member set.
     *
     * @return bool
     */
    private static function store(array $cluster) {
        global $wpdb;

        $members = array_map('intval', $cluster['members']);
        sort($members);

        $key = md5(implode('-', $members));
        $table = ECP_DB::clusters_table();
        $now = ECP_DB::now();

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status FROM {$table} WHERE cluster_key = %s",
            $key
        ), ARRAY_A);

        // A dismissed cluster stays dismissed. Re-detecting the same overlap
        // every night and re-surfacing it would train people to ignore the
        // screen.
        if ($existing && self::STATUS_DISMISSED === $existing['status']) {
            $wpdb->update($table, array('detected_at' => $now), array('id' => (int) $existing['id']), array('%s'), array('%d'));

            return false;
        }

        $data = array(
            'cluster_key'     => $key,
            'type'            => $cluster['type'],
            'label'           => mb_substr((string) $cluster['label'], 0, 191),
            'member_ids'      => ECP_DB::encode($members),
            'member_count'    => count($members),
            'primary_post_id' => self::pick_primary($members),
            'score'           => (float) $cluster['score'],
            'evidence'        => ECP_DB::encode($cluster['evidence']),
            'detected_at'     => $now,
            'updated_at'      => $now,
        );

        $formats = array('%s', '%s', '%s', '%s', '%d', '%d', '%f', '%s', '%s', '%s');

        if ($existing) {
            $wpdb->update($table, $data, array('id' => (int) $existing['id']), $formats, array('%d'));

            return false;
        }

        $data['status'] = self::STATUS_OPEN;
        $data['created_at'] = $now;
        $formats[] = '%s';
        $formats[] = '%s';

        return (bool) $wpdb->insert($table, $data, $formats);
    }

    /**
     * Which member should win the topic.
     *
     * Search performance first, because it reflects what Google has already
     * decided. Substance second. Age last, as a tiebreak — an older URL has
     * usually accumulated more links.
     *
     * @param int[] $members
     * @return int
     */
    public static function pick_primary(array $members) {
        $best = 0;
        $best_score = -INF;

        foreach ($members as $post_id) {
            $post = get_post((int) $post_id);

            if (!$post) {
                continue;
            }

            $metrics = ECP_Search_Data::page_metrics((int) $post_id);
            $score = 0.0;

            if ($metrics) {
                $score += $metrics['clicks'] * 10;
                $score += $metrics['impressions'] * 0.05;

                // Lower position is better; convert to a bonus.
                if ($metrics['position'] > 0) {
                    $score += max(0, 100 - $metrics['position']) * 2;
                }
            }

            $score += ECP_Content_Map::word_count(ECP_Content_Map::to_text($post->post_content)) * 0.05;

            // Inbound internal links are a strong signal of which URL the
            // site itself treats as canonical.
            $signals = ECP_Signals::collect($post);
            $score += (int) $signals['inbound_internal_links'] * 15;

            // Tiebreak on age.
            $score += max(0, 200 - (int) $signals['age_days'] / 30);

            if ($score > $best_score) {
                $best_score = $score;
                $best = (int) $post_id;
            }
        }

        return $best;
    }

    /* --------------------------------------------------------------------
     * Reading
     * ----------------------------------------------------------------- */

    /**
     * @param array $args { status, limit, offset, author }
     * @return array { items, total }
     */
    public static function query(array $args = array()) {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return array('items' => array(), 'total' => 0);
        }

        $args = wp_parse_args($args, array(
            'status' => self::STATUS_OPEN,
            'limit'  => 20,
            'offset' => 0,
            'author' => 0,
        ));

        $table = ECP_DB::clusters_table();
        $where = array('1=1');
        $params = array();

        if ($args['status']) {
            $where[] = 'status = %s';
            $params[] = $args['status'];
        }

        // A restricted reviewer only sees clusters whose primary page is
        // theirs — a cluster spanning other people's posts is not actionable
        // for them and exposing it leaks titles they cannot edit.
        if ($args['author']) {
            $posts = $wpdb->posts;
            $where[] = "primary_post_id IN (SELECT ID FROM {$posts} WHERE post_author = %d)";
            $params[] = (int) $args['author'];
        }

        $where_sql = implode(' AND ', $where);

        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
        $total = (int) ($params ? $wpdb->get_var($wpdb->prepare($count_sql, $params)) : $wpdb->get_var($count_sql));

        $limit = max(1, min(100, (int) $args['limit']));
        $offset = max(0, (int) $args['offset']);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY score DESC, id DESC LIMIT %d OFFSET %d",
            array_merge($params, array($limit, $offset))
        ), ARRAY_A);

        $items = array();
        foreach ((array) $rows as $row) {
            $items[] = self::hydrate($row);
        }

        return array('items' => $items, 'total' => $total);
    }

    /**
     * @return array|null
     */
    public static function get($id) {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return null;
        }

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . ECP_DB::clusters_table() . ' WHERE id = %d',
            (int) $id
        ), ARRAY_A);

        return $row ? self::hydrate($row) : null;
    }

    private static function hydrate(array $row) {
        $row['member_ids'] = array_map('intval', ECP_DB::decode($row['member_ids']));
        $row['evidence'] = ECP_DB::decode($row['evidence']);
        $row['recommendation'] = ECP_DB::decode($row['recommendation']);

        return $row;
    }

    public static function set_status($id, $status, array $extra = array()) {
        global $wpdb;

        return (bool) $wpdb->update(
            ECP_DB::clusters_table(),
            array_merge($extra, array('status' => $status, 'updated_at' => ECP_DB::now())),
            array('id' => (int) $id),
            null,
            array('%d')
        );
    }

    /**
     * Counts for the screen tabs.
     */
    public static function stats() {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return array('total' => 0, 'open' => 0, 'impressions' => 0);
        }

        $table = ECP_DB::clusters_table();

        $row = $wpdb->get_row(
            "SELECT COUNT(*) AS total, SUM(status = 'open') AS open_count FROM {$table}",
            ARRAY_A
        );

        return array(
            'total' => (int) $row['total'],
            'open'  => (int) $row['open_count'],
        );
    }

    /**
     * The next clusters worth spending an analysis on.
     *
     * @return int[]
     */
    public static function next_for_analysis($limit = 2) {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return array();
        }

        $table = ECP_DB::clusters_table();

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$table}
             WHERE status = %s
               AND (analyzed_at IS NULL OR analyzed_at < detected_at)
             ORDER BY score DESC
             LIMIT %d",
            self::STATUS_OPEN,
            max(1, (int) $limit)
        ));

        return array_map('intval', (array) $ids);
    }

    /* --------------------------------------------------------------------
     * Labels
     * ----------------------------------------------------------------- */

    public static function type_label($type) {
        $labels = array(
            self::TYPE_CANNIBALISATION => __('Competing for the same searches', 'enhanced-content-plugin'),
            self::TYPE_OVERLAP         => __('Possibly overlapping topics', 'enhanced-content-plugin'),
        );

        return isset($labels[$type]) ? $labels[$type] : $type;
    }

    public static function status_label($status) {
        $labels = array(
            self::STATUS_OPEN      => __('Open', 'enhanced-content-plugin'),
            self::STATUS_ANALYZING => __('Analyzing', 'enhanced-content-plugin'),
            self::STATUS_PROPOSED  => __('Changes proposed', 'enhanced-content-plugin'),
            self::STATUS_RESOLVED  => __('Resolved', 'enhanced-content-plugin'),
            self::STATUS_DISMISSED => __('Dismissed', 'enhanced-content-plugin'),
        );

        return isset($labels[$status]) ? $labels[$status] : $status;
    }

    /**
     * The verdicts the model may return for a member page.
     */
    public static function verdict_label($verdict) {
        $labels = array(
            'primary'      => __('Keep as the main page for this topic', 'enhanced-content-plugin'),
            'differentiate' => __('Retarget to a different angle', 'enhanced-content-plugin'),
            'merge'        => __('Merge into the main page', 'enhanced-content-plugin'),
            'leave'        => __('Leave alone', 'enhanced-content-plugin'),
        );

        return isset($labels[$verdict]) ? $labels[$verdict] : $verdict;
    }
}
