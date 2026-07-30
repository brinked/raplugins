<?php
/**
 * Where you actually rank, and whether it is moving.
 *
 * The metrics table has held per-query positions with a date on them since
 * day one, so a ranking history has been accumulating quietly with every
 * sync. Nothing read it back. This does.
 *
 * The unit here is the **query**, not the page. A page's "average position"
 * blends every term it appears for into one number that describes nothing:
 * a page averaging 18 might be third for one query and fortieth for six
 * others, and those two situations call for completely different work. Page
 * two is a property of a query, so that is what gets listed.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ECP_Rankings {

    /* Result-page bands. Positions are averages, so these are inclusive
     * upper bounds rather than integer ranges. */
    const BAND_PAGE_1 = 'page1';
    const BAND_PAGE_2 = 'page2';
    const BAND_PAGE_3 = 'page3';
    const BAND_BEYOND = 'beyond';

    /**
     * @return array<string,string>
     */
    public static function bands() {
        return array(
            self::BAND_PAGE_1 => __('Page 1', 'enhanced-content-plugin'),
            self::BAND_PAGE_2 => __('Page 2', 'enhanced-content-plugin'),
            self::BAND_PAGE_3 => __('Page 3', 'enhanced-content-plugin'),
            self::BAND_BEYOND => __('Page 4+', 'enhanced-content-plugin'),
        );
    }

    /**
     * Bounds for a band, as [min, max].
     *
     * @return array{0:float,1:float}
     */
    public static function band_range($band) {
        switch ($band) {
            case self::BAND_PAGE_1:
                return array(0.01, 10.0);

            case self::BAND_PAGE_2:
                return array(10.001, 20.0);

            case self::BAND_PAGE_3:
                return array(20.001, 30.0);

            case self::BAND_BEYOND:
                return array(30.001, 1000.0);
        }

        return array(0.01, 1000.0);
    }

    public static function band($position) {
        $position = (float) $position;

        if ($position <= 0) {
            return self::BAND_BEYOND;
        }
        if ($position <= 10) {
            return self::BAND_PAGE_1;
        }
        if ($position <= 20) {
            return self::BAND_PAGE_2;
        }
        if ($position <= 30) {
            return self::BAND_PAGE_3;
        }

        return self::BAND_BEYOND;
    }

    public static function band_label($band) {
        $bands = self::bands();

        return isset($bands[$band]) ? $bands[$band] : $band;
    }

    /**
     * The clicks this term is not getting, and why.
     *
     * Two completely different problems hide behind one number if you are
     * not careful, and they need opposite fixes:
     *
     *   Snippet   The page ranks where you would want it to, and people
     *             still are not clicking. A term sitting sixth with a 0.1%
     *             click rate is being seen ~900 times a month and chosen
     *             once. Nothing is wrong with its ranking — the title and
     *             description are not earning the click.
     *
     *   Position  The click rate is normal or better for where it sits, so
     *             the only way to get more traffic is to rank higher. That
     *             is a content problem, not a snippet one.
     *
     * Reporting a single blended figure labelled "if it reached page 1" —
     * which is what this did — is wrong twice over: it names the wrong goal,
     * and it points at the wrong fix for over half the rows on a typical
     * site.
     *
     * The position half deliberately models reaching position 4 rather than
     * position 1. Promising a number-one spot off the back of a content edit
     * is not a claim this plugin can honestly make.
     *
     * @return array { total, ctr_clicks, position_clicks, lever }
     *               lever: snippet | position | both | none
     */
    public static function opportunity($position, $impressions, $ctr) {
        $none = array('total' => 0.0, 'ctr_clicks' => 0.0, 'position_clicks' => 0.0, 'lever' => 'none');

        $position = (float) $position;
        $impressions = (int) $impressions;
        $ctr = (float) $ctr;

        if ($impressions < 1 || $position <= 0) {
            return $none;
        }

        $expected_here = ECP_Opportunity_Engine::expected_ctr($position);
        $expected_top = ECP_Opportunity_Engine::expected_ctr(4);

        // Clicks lost to a weak snippet, at the position it already holds.
        $ctr_clicks = max(0.0, $expected_here - $ctr) * $impressions;

        // Clicks gained by climbing, assuming the click rate then behaves
        // normally for the new position.
        $position_clicks = $position > 4
            ? max(0.0, $expected_top - $expected_here) * $impressions
            : 0.0;

        $total = $ctr_clicks + $position_clicks;

        if ($total < 1) {
            return $none;
        }

        // Name the dominant lever, unless they are genuinely comparable.
        if ($ctr_clicks >= 1 && $position_clicks >= 1 && min($ctr_clicks, $position_clicks) / max($ctr_clicks, $position_clicks) > 0.6) {
            $lever = 'both';
        } elseif ($ctr_clicks > $position_clicks) {
            $lever = 'snippet';
        } else {
            $lever = 'position';
        }

        return array(
            'total'           => round($total, 1),
            'ctr_clicks'      => round($ctr_clicks, 1),
            'position_clicks' => round($position_clicks, 1),
            'lever'           => $lever,
        );
    }

    /**
     * Short label naming the fix a row needs.
     */
    public static function lever_label($lever) {
        $labels = array(
            'snippet'  => __('better snippet', 'enhanced-content-plugin'),
            'position' => __('rank higher', 'enhanced-content-plugin'),
            'both'     => __('both', 'enhanced-content-plugin'),
        );

        return isset($labels[$lever]) ? $labels[$lever] : '';
    }

    /**
     * The reasoning behind a row's number, for its tooltip.
     */
    public static function lever_explanation(array $opportunity, $position, $ctr) {
        $expected = ECP_Opportunity_Engine::expected_ctr($position);

        switch ($opportunity['lever']) {
            case 'snippet':
                return sprintf(
                    /* translators: 1: actual CTR, 2: typical CTR, 3: position */
                    __('This ranks well but is rarely clicked: %1$s of people who see it click, against roughly %2$s typical at position %3$s. The ranking is not the problem — the title and description are. Rewriting them is a safe, fast change.', 'enhanced-content-plugin'),
                    number_format_i18n($ctr * 100, 1) . '%',
                    number_format_i18n($expected * 100, 1) . '%',
                    number_format_i18n($position, 1)
                );

            case 'position':
                return sprintf(
                    /* translators: 1: position, 2: click-through rate */
                    __('The click rate of %2$s is normal or better for position %1$s, so the snippet is doing its job. More traffic here means ranking higher, which is a content problem.', 'enhanced-content-plugin'),
                    number_format_i18n($position, 1),
                    number_format_i18n($ctr * 100, 1) . '%'
                );

            case 'both':
                return sprintf(
                    /* translators: 1: clicks from a better snippet, 2: clicks from a higher position */
                    __('Roughly %1$s clicks from a snippet that earns the click, and %2$s more from ranking higher. Worth doing both.', 'enhanced-content-plugin'),
                    number_format_i18n($opportunity['ctr_clicks'], 0),
                    number_format_i18n($opportunity['position_clicks'], 0)
                );
        }

        return __('This term is performing about as well as its position allows.', 'enhanced-content-plugin');
    }

    /* --------------------------------------------------------------------
     * Reading
     * ----------------------------------------------------------------- */

    /**
     * The most recent snapshot date we hold.
     *
     * @return string|null
     */
    public static function latest_date($window = ECP_Search_Data::DEFAULT_WINDOW) {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return null;
        }

        $date = $wpdb->get_var($wpdb->prepare(
            'SELECT MAX(metric_date) FROM ' . ECP_DB::metrics_table() . ' WHERE window_days = %d',
            (int) $window
        ));

        return $date ? $date : null;
    }

    /**
     * How many distinct snapshot dates exist for a window. Below two, there
     * is no trend to show and the UI should say so rather than render empty
     * arrows.
     */
    public static function snapshot_count($window = ECP_Search_Data::DEFAULT_WINDOW) {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return 0;
        }

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(DISTINCT metric_date) FROM ' . ECP_DB::metrics_table() . ' WHERE window_days = %d',
            (int) $window
        ));
    }

    /**
     * Band counts for every snapshot of a window, newest first.
     *
     * Nothing extra is stored to make this work. Each sync stamps its rows
     * with the day it describes, so the history is already there — it just
     * had nothing reading it back.
     *
     * @param int $window
     * @param int $limit Snapshots to return.
     * @return array<int,array> { date, page1, page2, page3, beyond, total, clicks, impressions, avg_position }
     */
    public static function band_history($window = ECP_Search_Data::DEFAULT_WINDOW, $limit = 30) {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return array();
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT metric_date,
                    SUM(CASE WHEN position > 0 AND position <= 10 THEN 1 ELSE 0 END) AS page1,
                    SUM(CASE WHEN position > 10 AND position <= 20 THEN 1 ELSE 0 END) AS page2,
                    SUM(CASE WHEN position > 20 AND position <= 30 THEN 1 ELSE 0 END) AS page3,
                    SUM(CASE WHEN position > 30 OR position <= 0 THEN 1 ELSE 0 END) AS beyond,
                    COUNT(*) AS total,
                    SUM(clicks) AS clicks,
                    SUM(impressions) AS impressions,
                    AVG(position) AS avg_position
               FROM ' . ECP_DB::metrics_table() . "
              WHERE window_days = %d AND query != '' AND impressions >= 1
              GROUP BY metric_date
              ORDER BY metric_date DESC
              LIMIT %d",
            (int) $window,
            max(2, (int) $limit)
        ), ARRAY_A);

        return array_map(function ($row) {
            return array(
                'date'         => $row['metric_date'],
                'page1'        => (int) $row['page1'],
                'page2'        => (int) $row['page2'],
                'page3'        => (int) $row['page3'],
                'beyond'       => (int) $row['beyond'],
                'total'        => (int) $row['total'],
                'clicks'       => (int) $row['clicks'],
                'impressions'  => (int) $row['impressions'],
                'avg_position' => round((float) $row['avg_position'], 2),
            );
        }, (array) $rows);
    }

    /**
     * What actually happened to your terms between two snapshots.
     *
     * A band count on its own cannot tell you whether you are winning. Fewer
     * terms on page 2 is good if they went up to page 1 and bad if they fell
     * to page 3, and the count is identical either way. So this compares each
     * term against itself and reports the direction it moved.
     *
     * @param int $window
     * @param string $to   Snapshot date to measure. Defaults to the newest.
     * @param string $from Snapshot to compare against. Defaults to the previous one.
     * @return array|null Null when there is only one snapshot.
     */
    public static function movement_summary($window = ECP_Search_Data::DEFAULT_WINDOW, $to = '', $from = '') {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return null;
        }

        $table = ECP_DB::metrics_table();

        if (!$to) {
            $to = self::latest_date($window);
        }

        if (!$to) {
            return null;
        }

        if (!$from) {
            $from = $wpdb->get_var($wpdb->prepare(
                "SELECT MAX(metric_date) FROM {$table} WHERE window_days = %d AND metric_date < %s",
                (int) $window,
                $to
            ));
        }

        if (!$from) {
            return null;
        }

        // A position is an average over the window, so it never sits still.
        // Anything inside half a place is noise, not movement.
        $noise = 0.5;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(*) AS compared,
                SUM(CASE WHEN n.position < o.position - %f THEN 1 ELSE 0 END) AS improved,
                SUM(CASE WHEN n.position > o.position + %f THEN 1 ELSE 0 END) AS declined,
                SUM(CASE WHEN ABS(n.position - o.position) <= %f THEN 1 ELSE 0 END) AS unchanged,
                SUM(CASE WHEN n.position <= 10 AND o.position > 10 THEN 1 ELSE 0 END) AS entered_page1,
                SUM(CASE WHEN n.position > 10 AND o.position <= 10 THEN 1 ELSE 0 END) AS left_page1,
                SUM(o.position - n.position) AS places_gained,
                SUM(n.clicks) - SUM(o.clicks) AS clicks_change
               FROM {$table} n
               INNER JOIN {$table} o
                  ON n.post_id = o.post_id
                 AND n.query = o.query
                 AND n.window_days = o.window_days
              WHERE n.window_days = %d
                AND n.metric_date = %s
                AND o.metric_date = %s
                AND n.query != ''",
            $noise,
            $noise,
            $noise,
            (int) $window,
            $to,
            $from
        ), ARRAY_A);

        if (!$row || !(int) $row['compared']) {
            return null;
        }

        // Terms present in only one of the two snapshots. Worth separating:
        // a term that has appeared for the first time is not an improvement
        // in ranking, and one that has gone is not a decline — Search Console
        // simply stops reporting terms below a volume threshold.
        $appeared = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} n
              WHERE n.window_days = %d AND n.metric_date = %s AND n.query != ''
                AND NOT EXISTS (
                    SELECT 1 FROM {$table} o
                     WHERE o.window_days = n.window_days AND o.metric_date = %s
                       AND o.post_id = n.post_id AND o.query = n.query
                )",
            (int) $window,
            $to,
            $from
        ));

        $gone = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} o
              WHERE o.window_days = %d AND o.metric_date = %s AND o.query != ''
                AND NOT EXISTS (
                    SELECT 1 FROM {$table} n
                     WHERE n.window_days = o.window_days AND n.metric_date = %s
                       AND n.post_id = o.post_id AND n.query = o.query
                )",
            (int) $window,
            $from,
            $to
        ));

        $compared = (int) $row['compared'];
        $gained = (float) $row['places_gained'];

        return array(
            'from'          => $from,
            'to'            => $to,
            'compared'      => $compared,
            'improved'      => (int) $row['improved'],
            'declined'      => (int) $row['declined'],
            'unchanged'     => (int) $row['unchanged'],
            'entered_page1' => (int) $row['entered_page1'],
            'left_page1'    => (int) $row['left_page1'],
            'places_gained' => round($gained, 1),
            'avg_change'    => round($gained / max(1, $compared), 2),
            'clicks_change' => (int) $row['clicks_change'],
            'appeared'      => $appeared,
            'gone'          => $gone,
        );
    }

    /**
     * Ranking rows for the current snapshot, with movement.
     *
     * @param array $args { band, post_id, search, min_impressions, days, per_page, paged, author }
     * @return array { items, total, latest, compared_to }
     */
    public static function query( array $args = array() ) {
        global $wpdb;

        $empty = array('items' => array(), 'total' => 0, 'latest' => null, 'compared_to' => null);

        if (!ECP_DB::tables_exist()) {
            return $empty;
        }

        $args = wp_parse_args($args, array(
            'band'            => '',
            'post_id'         => 0,
            'search'          => '',
            'min_impressions' => 1,
            'window'          => ECP_Search_Data::DEFAULT_WINDOW,
            'days'            => 28,
            'per_page'        => 50,
            'paged'           => 1,
            'author'          => 0,
        ));

        $window = ECP_Search_Data::valid_window($args['window']);
        $latest = self::latest_date($window);

        if (!$latest) {
            return $empty;
        }

        $metrics = ECP_DB::metrics_table();
        $posts = $wpdb->posts;

        $where = array('m.metric_date = %s', 'm.window_days = %d', "m.query != ''", 'm.impressions >= %d');
        $params = array($latest, $window, max(0, (int) $args['min_impressions']));

        if ($args['band']) {
            list($min, $max) = self::band_range($args['band']);
            $where[] = 'm.position >= %f AND m.position <= %f';
            $params[] = $min;
            $params[] = $max;
        }

        if ($args['post_id']) {
            $where[] = 'm.post_id = %d';
            $params[] = (int) $args['post_id'];
        }

        if ($args['search']) {
            $where[] = '(m.query LIKE %s OR p.post_title LIKE %s)';
            $like = '%' . $wpdb->esc_like($args['search']) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        if ($args['author']) {
            $where[] = 'p.post_author = %d';
            $params[] = (int) $args['author'];
        }

        $where_sql = implode(' AND ', $where);

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$metrics} m INNER JOIN {$posts} p ON p.ID = m.post_id WHERE {$where_sql}",
            $params
        ));

        $per_page = max(1, min(200, (int) $args['per_page']));
        $offset = max(0, ((int) $args['paged'] - 1)) * $per_page;

        // Biggest opportunity first: impressions is the honest proxy for how
        // much a position change is worth.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT m.post_id, m.query, m.position, m.impressions, m.clicks, m.ctr,
                    p.post_title
             FROM {$metrics} m
             INNER JOIN {$posts} p ON p.ID = m.post_id
             WHERE {$where_sql}
             ORDER BY m.impressions DESC, m.position ASC
             LIMIT %d OFFSET %d",
            array_merge($params, array($per_page, $offset))
        ), ARRAY_A);

        if (!$rows) {
            return array('items' => array(), 'total' => $total, 'latest' => $latest, 'compared_to' => null, 'window' => $window);
        }

        $baseline = self::baseline_positions($rows, (int) $args['days'], $latest, $window);

        $items = array();

        foreach ($rows as $row) {
            $key = $row['post_id'] . '|' . $row['query'];
            $position = (float) $row['position'];
            $was = isset($baseline['positions'][$key]) ? (float) $baseline['positions'][$key] : null;

            $items[] = array(
                'post_id'     => (int) $row['post_id'],
                'post_title'  => $row['post_title'],
                'query'       => $row['query'],
                'position'    => $position,
                'band'        => self::band($position),
                'impressions' => (int) $row['impressions'],
                'clicks'      => (int) $row['clicks'],
                'ctr'         => (float) $row['ctr'],
                'opportunity' => self::opportunity($position, (int) $row['impressions'], (float) $row['ctr']),
                'was'         => $was,
                // Positive means it climbed. Raw positions run the other way
                // — smaller is better — and getting that backwards in a UI is
                // the kind of thing nobody notices until it has misled them
                // for a month.
                'movement'    => null === $was ? null : round($was - $position, 1),
            );
        }

        return array(
            'items'       => $items,
            'total'       => $total,
            'latest'      => $latest,
            'compared_to' => $baseline['date'],
            'window'      => $window,
        );
    }

    /**
     * Earliest position within the window for each row on the page.
     *
     * Done as one extra query over the rows actually being displayed rather
     * than a correlated subquery per row — on a site with tens of thousands
     * of metric rows the difference is seconds.
     *
     * @return array { positions: array<string,float>, date: string|null }
     */
    private static function baseline_positions(array $rows, $days, $latest, $window) {
        global $wpdb;

        $metrics = ECP_DB::metrics_table();
        $since = gmdate('Y-m-d', strtotime("-{$days} days", strtotime($latest)));

        // The oldest snapshot inside the window is the fairest comparison
        // point: it is the furthest back we can honestly speak to. It must
        // come from the same reporting window, or the "movement" is really
        // the difference between a 7-day and a 28-day average.
        $baseline_date = $wpdb->get_var($wpdb->prepare(
            "SELECT MIN(metric_date) FROM {$metrics}
             WHERE window_days = %d AND metric_date >= %s AND metric_date < %s",
            (int) $window,
            $since,
            $latest
        ));

        if (!$baseline_date) {
            return array('positions' => array(), 'date' => null);
        }

        $post_ids = array_unique(array_map('intval', wp_list_pluck($rows, 'post_id')));
        $placeholders = implode(',', array_fill(0, count($post_ids), '%d'));

        $previous = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, query, position
             FROM {$metrics}
             WHERE metric_date = %s AND window_days = %d AND post_id IN ({$placeholders}) AND query != ''",
            array_merge(array($baseline_date, (int) $window), $post_ids)
        ), ARRAY_A);

        $positions = array();

        foreach ((array) $previous as $row) {
            $positions[$row['post_id'] . '|' . $row['query']] = (float) $row['position'];
        }

        return array('positions' => $positions, 'date' => $baseline_date);
    }

    /**
     * Counts per band for the current snapshot.
     *
     * @return array<string,int>
     */
    public static function band_summary($author = 0, $window = ECP_Search_Data::DEFAULT_WINDOW) {
        global $wpdb;

        $summary = array_fill_keys(array_keys(self::bands()), 0);

        if (!ECP_DB::tables_exist()) {
            return $summary;
        }

        $window = ECP_Search_Data::valid_window($window);
        $latest = self::latest_date($window);

        if (!$latest) {
            return $summary;
        }

        $metrics = ECP_DB::metrics_table();
        $posts = $wpdb->posts;

        $where = array('m.metric_date = %s', 'm.window_days = %d', "m.query != ''", 'm.impressions >= 1');
        $params = array($latest, $window);

        if ($author) {
            $where[] = 'p.post_author = %d';
            $params[] = (int) $author;
        }

        $where_sql = implode(' AND ', $where);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT
                SUM(m.position <= 10) AS page1,
                SUM(m.position > 10 AND m.position <= 20) AS page2,
                SUM(m.position > 20 AND m.position <= 30) AS page3,
                SUM(m.position > 30) AS beyond
             FROM {$metrics} m
             INNER JOIN {$posts} p ON p.ID = m.post_id
             WHERE {$where_sql}",
            $params
        ), ARRAY_A);

        if (!$rows) {
            return $summary;
        }

        $row = $rows[0];

        return array(
            self::BAND_PAGE_1 => (int) $row['page1'],
            self::BAND_PAGE_2 => (int) $row['page2'],
            self::BAND_PAGE_3 => (int) $row['page3'],
            self::BAND_BEYOND => (int) $row['beyond'],
        );
    }

    /**
     * The single best-ranking query for a page.
     *
     * More useful in a list than the blended average, because it answers
     * "how close is this page to working" rather than "what is the mean of
     * some numbers".
     *
     * @return array|null { query, position, band, impressions, clicks }
     */
    public static function best_for_post($post_id, $window = ECP_Search_Data::DEFAULT_WINDOW) {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return null;
        }

        $window = ECP_Search_Data::valid_window($window);
        $latest = self::latest_date($window);

        if (!$latest) {
            return null;
        }

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT query, position, impressions, clicks
             FROM ' . ECP_DB::metrics_table() . "
             WHERE post_id = %d AND window_days = %d AND metric_date = %s AND query != '' AND impressions >= 1
             ORDER BY position ASC
             LIMIT 1",
            (int) $post_id,
            $window,
            $latest
        ), ARRAY_A);

        if (!$row) {
            return null;
        }

        return array(
            'query'       => $row['query'],
            'position'    => (float) $row['position'],
            'band'        => self::band($row['position']),
            'impressions' => (int) $row['impressions'],
            'clicks'      => (int) $row['clicks'],
        );
    }

    /**
     * Full position history for one query, oldest first. Feeds the sparkline.
     *
     * @return array[] { date, position, impressions, clicks }
     */
    public static function history($post_id, $query, $days = 90, $window = ECP_Search_Data::DEFAULT_WINDOW) {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return array();
        }

        $since = gmdate('Y-m-d', strtotime("-{$days} days"));

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT metric_date, position, impressions, clicks
             FROM ' . ECP_DB::metrics_table() . '
             WHERE post_id = %d AND window_days = %d AND query = %s AND metric_date >= %s
             ORDER BY metric_date ASC',
            (int) $post_id,
            (int) $window,
            (string) $query,
            $since
        ), ARRAY_A);

        return array_map(function ($row) {
            return array(
                'date'        => $row['metric_date'],
                'position'    => (float) $row['position'],
                'impressions' => (int) $row['impressions'],
                'clicks'      => (int) $row['clicks'],
            );
        }, (array) $rows);
    }

    /**
     * An inline SVG sparkline of position over time.
     *
     * Y is inverted, because a rising line meaning "got worse" is a trap.
     *
     * @return string
     */
    public static function sparkline(array $history, $width = 90, $height = 24) {
        if (count($history) < 2) {
            return '';
        }

        $positions = wp_list_pluck($history, 'position');
        $min = min($positions);
        $max = max($positions);
        $span = max(0.5, $max - $min);

        $step = $width / (count($positions) - 1);
        $points = array();

        foreach (array_values($positions) as $index => $position) {
            $x = round($index * $step, 1);
            // Invert: the best (lowest) position sits at the top.
            $y = round((($position - $min) / $span) * ($height - 4) + 2, 1);
            $points[] = $x . ',' . $y;
        }

        $improving = $positions[count($positions) - 1] < $positions[0];
        $colour = $improving ? '#00a32a' : '#d63638';

        if (abs($positions[count($positions) - 1] - $positions[0]) < 0.5) {
            $colour = '#a7aaad';
        }

        return sprintf(
            '<svg class="ecp-spark" width="%d" height="%d" viewBox="0 0 %d %d" aria-hidden="true" focusable="false">'
            . '<polyline fill="none" stroke="%s" stroke-width="1.5" stroke-linejoin="round" points="%s"/></svg>',
            $width,
            $height,
            $width,
            $height,
            esc_attr($colour),
            esc_attr(implode(' ', $points))
        );
    }
}
