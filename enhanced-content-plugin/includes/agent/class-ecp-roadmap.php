<?php
/**
 * The growth roadmap: opportunities turned into a sequenced plan.
 *
 * The opportunity queue answers "which pages are worth attention". This
 * class answers the question a site owner actually asks: "what should we
 * do first, and why in that order?" It derives an ordered list of steps
 * from the opportunities and clusters the scanners already maintain, and
 * sequences them by a rule the UI can state out loud: visibility problems
 * before content polish, consolidation decisions before per-page work on
 * the pages involved, cheap snippet wins before restructures.
 *
 * Two kinds of data live in one table and must never be confused:
 * derived facts (title, evidence, score, ordering) are recomputed on
 * every rebuild; human decisions (status, locked, the decision trail)
 * are ground truth and survive rebuilds untouched — the same contract
 * the inventory keeps for corrected topics.
 *
 * SaaS seam: rebuild() is pure reads over local tables plus writes to
 * one table. In the SaaS this becomes a backend job and the screen reads
 * its output over HTTP.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ECP_Roadmap {

    /* Status values. proposed → approved/postponed/dismissed → done. */
    const PROPOSED  = 'proposed';
    const APPROVED  = 'approved';
    const POSTPONED = 'postponed';
    const DISMISSED = 'dismissed';
    const DONE      = 'done';

    /* Tracks, in the order work should happen. */
    const TRACK_TECHNICAL     = 'technical';
    const TRACK_CONSOLIDATION = 'consolidation';
    const TRACK_SNIPPET       = 'snippet';
    const TRACK_LINKS         = 'links';
    const TRACK_CONTENT       = 'content';

    /** How many opportunity posts feed the plan. Enough to be a real
     *  roadmap, few enough that every line deserves to be there. */
    const MAX_SOURCE_POSTS = 15;

    /** Issue codes that block or distort search visibility. Everything
     *  else on the page is pointless until these are fixed, which is why
     *  they become their own step that the content step depends on. */
    private static $technical_codes = array('noindexed', 'canonical_elsewhere', 'never_seen');

    /** Issue codes a snippet fix (title/description) addresses. */
    private static $snippet_codes = array(
        'low_ctr', 'zero_clicks', 'no_meta_description', 'short_meta_description',
        'long_meta_description', 'long_title', 'dated_title',
    );

    /** Issue codes about the link graph rather than the page body. */
    private static $link_codes = array(
        'orphan_page', 'few_internal_links', 'generic_anchors', 'broken_links', 'insecure_links',
    );

    /* --------------------------------------------------------------------
     * Building the plan
     * ----------------------------------------------------------------- */

    /**
     * Rebuild if the plan is stale. Cheap enough to call from a screen
     * render; the transient keeps a busy admin from re-deriving it on
     * every page load.
     */
    public static function maybe_rebuild() {
        if (get_transient('ecp_roadmap_fresh')) {
            return;
        }

        self::rebuild();
    }

    /**
     * Re-derive the roadmap from the current opportunities and clusters.
     *
     * @return int Number of active (proposed or approved) steps.
     */
    public static function rebuild() {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return 0;
        }

        $table = ECP_DB::roadmap_table();
        $now = ECP_DB::now();

        $existing = array();
        foreach ((array) $wpdb->get_results("SELECT * FROM {$table}", ARRAY_A) as $row) {  // phpcs:ignore WordPress.DB.PreparedSQL
            $existing[$row['item_key']] = $row;
        }

        $candidates = self::candidates();

        // Upsert candidates. Derived columns refresh; decisions do not.
        foreach ($candidates as $key => $item) {
            $facts = array(
                'source'           => $item['source'],
                'post_id'          => (int) $item['post_id'],
                'cluster_id'       => (int) $item['cluster_id'],
                'track'            => $item['track'],
                'title'            => $item['title'],
                'why'              => ECP_DB::encode($item['why']),
                'score'            => round((float) $item['score'], 2),
                'potential_clicks' => round((float) $item['potential_clicks'], 2),
                'depends_on'       => ECP_DB::encode($item['depends_on']),
                'updated_at'       => $now,
            );
            $formats = array('%s', '%d', '%d', '%s', '%s', '%s', '%f', '%f', '%s', '%s');

            if (isset($existing[$key])) {
                $wpdb->update($table, $facts, array('item_key' => $key), $formats, array('%s'));
            } else {
                $facts['item_key'] = $key;
                $facts['status'] = self::PROPOSED;
                $facts['created_at'] = $now;
                array_push($formats, '%s', '%s', '%s');
                $wpdb->insert($table, $facts, $formats);
            }
        }

        // Reconcile rows the derivation no longer produces: completed work
        // gets credited, stale suggestions disappear, decisions persist.
        foreach ($existing as $key => $row) {
            if (isset($candidates[$key])) {
                continue;
            }

            self::reconcile_row($row, $now);
        }

        // A postponement is an appointment, not a dismissal.
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
                SET status = %s, postponed_until = NULL, updated_at = %s
              WHERE status = %s AND postponed_until IS NOT NULL AND postponed_until <= %s",
            self::PROPOSED,
            $now,
            self::POSTPONED,
            $now
        ));

        $active = self::renumber();

        set_transient('ecp_roadmap_fresh', 1, 15 * MINUTE_IN_SECONDS);
        update_option('ecp_roadmap_built_at', $now, false);

        return $active;
    }

    /**
     * Derive the current candidate steps from opportunities and clusters.
     *
     * @return array<string,array> item_key => item
     */
    private static function candidates() {
        global $wpdb;

        $candidates = array();
        $post_cluster = array();   // post_id => cluster item key

        // Open clusters first: which page owns a topic is decided before
        // any per-page work on the pages involved.
        $clusters_table = ECP_DB::clusters_table();
        $clusters = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$clusters_table} WHERE status IN (%s, %s, %s) ORDER BY score DESC LIMIT 5",
            'open',
            'analyzing',
            'proposed'
        ), ARRAY_A);

        foreach ($clusters as $cluster) {
            $key = 'cluster:' . (int) $cluster['id'];
            $members = ECP_DB::decode($cluster['member_ids']);

            $candidates[$key] = array(
                'source'           => 'cluster',
                'post_id'          => 0,
                'cluster_id'       => (int) $cluster['id'],
                'track'            => self::TRACK_CONSOLIDATION,
                'title'            => sprintf(
                    /* translators: %s: cluster label */
                    __('Decide which page owns "%s"', 'enhanced-content-plugin'),
                    $cluster['label'] ? $cluster['label'] : __('a competing topic', 'enhanced-content-plugin')
                ),
                'why'              => array(
                    'cluster_type'  => $cluster['type'],
                    'member_count'  => (int) $cluster['member_count'],
                ),
                'score'            => (float) $cluster['score'],
                'potential_clicks' => 0,
                'depends_on'       => array(),
            );

            foreach ((array) $members as $member_id) {
                $post_cluster[(int) $member_id] = $key;
            }
        }

        $result = ECP_Opportunity_Engine::query(array(
            'status'  => ECP_Opportunity_Engine::STATUS_OPEN,
            'limit'   => self::MAX_SOURCE_POSTS,
            'orderby' => 'score',
            'order'   => 'DESC',
        ));

        foreach ($result['items'] as $opp) {
            $post_id = (int) $opp['post_id'];
            $issues = is_array($opp['reasons']) ? $opp['reasons'] : array();

            $technical = array();
            $rest = array();

            foreach ($issues as $issue) {
                if (in_array($issue['code'], self::$technical_codes, true)) {
                    $technical[] = $issue;
                } else {
                    $rest[] = $issue;
                }
            }

            $depends = array();

            if (isset($post_cluster[$post_id])) {
                $depends[] = $post_cluster[$post_id];
            }

            if ($technical) {
                $tech_key = 'opportunity:' . $post_id . ':technical';

                $candidates[$tech_key] = array(
                    'source'           => 'opportunity',
                    'post_id'          => $post_id,
                    'cluster_id'       => 0,
                    'track'            => self::TRACK_TECHNICAL,
                    'title'            => sprintf(
                        /* translators: %s: post title */
                        __('Fix search visibility: %s', 'enhanced-content-plugin'),
                        $opp['post_title']
                    ),
                    'why'              => array('issues' => self::compact_issues($technical), 'primary' => $technical[0]['code']),
                    'score'            => (float) $opp['score'],
                    'potential_clicks' => 0,
                    'depends_on'       => isset($post_cluster[$post_id]) ? array($post_cluster[$post_id]) : array(),
                );

                $depends[] = $tech_key;
            }

            if (!$rest) {
                continue;
            }

            $track = self::pick_track($opp['primary_reason'], $rest);

            $candidates['opportunity:' . $post_id . ':page'] = array(
                'source'           => 'opportunity',
                'post_id'          => $post_id,
                'cluster_id'       => 0,
                'track'            => $track,
                'title'            => sprintf(self::track_title_pattern($track), $opp['post_title']),
                'why'              => array('issues' => self::compact_issues($rest), 'primary' => $opp['primary_reason']),
                'score'            => (float) $opp['score'],
                'potential_clicks' => (float) $opp['potential_clicks'],
                'depends_on'       => $depends,
            );
        }

        return $candidates;
    }

    /**
     * The step's track, from what is actually wrong: the bucket holding
     * the most issues wins, with the stored primary reason as tiebreaker.
     */
    private static function pick_track($primary_reason, array $issues) {
        $counts = array(self::TRACK_SNIPPET => 0, self::TRACK_LINKS => 0, self::TRACK_CONTENT => 0);

        foreach ($issues as $issue) {
            if (in_array($issue['code'], self::$snippet_codes, true)) {
                $counts[self::TRACK_SNIPPET]++;
            } elseif (in_array($issue['code'], self::$link_codes, true)) {
                $counts[self::TRACK_LINKS]++;
            } else {
                $counts[self::TRACK_CONTENT]++;
            }
        }

        if (in_array($primary_reason, self::$snippet_codes, true)) {
            $counts[self::TRACK_SNIPPET] += 2;
        } elseif (in_array($primary_reason, self::$link_codes, true)) {
            $counts[self::TRACK_LINKS] += 2;
        }

        arsort($counts);

        return (string) array_key_first($counts);
    }

    private static function track_title_pattern($track) {
        switch ($track) {
            case self::TRACK_SNIPPET:
                /* translators: %s: post title */
                return __('Improve the search snippet: %s', 'enhanced-content-plugin');
            case self::TRACK_LINKS:
                /* translators: %s: post title */
                return __('Strengthen the links around: %s', 'enhanced-content-plugin');
            default:
                /* translators: %s: post title */
                return __('Improve the content: %s', 'enhanced-content-plugin');
        }
    }

    /**
     * The evidence the screen shows — codes and severities only, top five
     * by severity. Labels are resolved at render time so they translate.
     */
    private static function compact_issues(array $issues, $limit = 5) {
        $rank = array('high' => 0, 'medium' => 1, 'low' => 2);

        usort($issues, function ($a, $b) use ($rank) {
            $sa = isset($rank[$a['severity']]) ? $rank[$a['severity']] : 3;
            $sb = isset($rank[$b['severity']]) ? $rank[$b['severity']] : 3;

            return $sa <=> $sb;
        });

        $out = array();

        foreach (array_slice($issues, 0, $limit) as $issue) {
            $out[] = array('code' => $issue['code'], 'severity' => $issue['severity']);
        }

        return $out;
    }

    /**
     * Settle a stored row the derivation no longer produces.
     *
     * The source record says why it disappeared: resolved sources credit
     * the step as done, dismissed sources dismiss it, and a step that
     * merely fell out of the top set is deleted only if nobody has
     * touched it — a decision, or a lock, keeps it on the plan.
     */
    private static function reconcile_row(array $row, $now) {
        global $wpdb;

        $table = ECP_DB::roadmap_table();

        if (in_array($row['status'], array(self::DONE, self::DISMISSED), true)) {
            return;   // Already settled; kept as the plan's history.
        }

        $source_status = null;

        if ('cluster' === $row['source']) {
            $source_status = $wpdb->get_var($wpdb->prepare(
                'SELECT status FROM ' . ECP_DB::clusters_table() . ' WHERE id = %d',
                (int) $row['cluster_id']
            ));
            $done_states = array('resolved');
        } else {
            $opp = ECP_Opportunity_Engine::get((int) $row['post_id']);
            $source_status = $opp ? $opp['status'] : null;
            $done_states = array(ECP_Opportunity_Engine::STATUS_DONE);
        }

        if (null !== $source_status && in_array($source_status, $done_states, true)) {
            $wpdb->update(
                $table,
                array('status' => self::DONE, 'completed_at' => $now, 'updated_at' => $now),
                array('id' => (int) $row['id']),
                array('%s', '%s', '%s'),
                array('%d')
            );

            return;
        }

        if ('dismissed' === $source_status) {
            $wpdb->update(
                $table,
                array('status' => self::DISMISSED, 'updated_at' => $now),
                array('id' => (int) $row['id']),
                array('%s', '%s'),
                array('%d')
            );

            return;
        }

        // Source is gone or still open but no longer top-of-queue. Work in
        // flight (analyzing/proposed sources) also lands here and is kept.
        $untouched = self::PROPOSED === $row['status'] && !(int) $row['locked'] && empty($row['decided_at']);

        if ($untouched && (null === $source_status || ECP_Opportunity_Engine::STATUS_OPEN === $source_status || 'open' === $source_status)) {
            $wpdb->delete($table, array('id' => (int) $row['id']), array('%d'));
        }
    }

    /**
     * Assign step numbers. Locked steps hold the front of the plan in the
     * order they were locked; everything else follows the sequencing rule
     * (technical, consolidation, snippet, links, content; best score
     * first within a track).
     *
     * @return int Number of active steps.
     */
    private static function renumber() {
        global $wpdb;

        $table = ECP_DB::roadmap_table();

        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT id, track, score, step_order, locked FROM {$table} WHERE status IN (%s, %s)",
            self::PROPOSED,
            self::APPROVED
        ), ARRAY_A);

        $rank = array(
            self::TRACK_TECHNICAL     => 0,
            self::TRACK_CONSOLIDATION => 1,
            self::TRACK_SNIPPET       => 2,
            self::TRACK_LINKS         => 3,
            self::TRACK_CONTENT       => 4,
        );

        usort($rows, function ($a, $b) use ($rank) {
            if ((int) $a['locked'] !== (int) $b['locked']) {
                return (int) $b['locked'] <=> (int) $a['locked'];
            }

            if ((int) $a['locked']) {
                return (int) $a['step_order'] <=> (int) $b['step_order'];
            }

            $ra = isset($rank[$a['track']]) ? $rank[$a['track']] : 5;
            $rb = isset($rank[$b['track']]) ? $rank[$b['track']] : 5;

            if ($ra !== $rb) {
                return $ra <=> $rb;
            }

            return (float) $b['score'] <=> (float) $a['score'];
        });

        $order = 0;

        foreach ($rows as $row) {
            $order++;

            if ((int) $row['step_order'] !== $order) {
                $wpdb->update($table, array('step_order' => $order), array('id' => (int) $row['id']), array('%d'), array('%d'));
            }
        }

        return $order;
    }

    /* --------------------------------------------------------------------
     * Reading the plan
     * ----------------------------------------------------------------- */

    /**
     * @param array $args { status: string|string[], limit }
     * @return array[] Rows with post_title, decoded why/depends_on, and
     *                 the source's live status as source_status.
     */
    public static function query($args = array()) {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return array();
        }

        $args = wp_parse_args($args, array(
            'status' => array(self::PROPOSED, self::APPROVED),
            'limit'  => 50,
        ));

        $statuses = array_values(array_filter((array) $args['status']));

        if (!$statuses) {
            return array();
        }

        $table = ECP_DB::roadmap_table();
        $posts = $wpdb->posts;
        $opps = ECP_DB::opportunities_table();
        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));

        $order = in_array(self::DONE, $statuses, true) && 1 === count($statuses)
            ? 'r.completed_at DESC'
            : 'r.step_order ASC, r.updated_at DESC';

        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT r.*, p.post_title, o.status AS source_status
               FROM {$table} r
               LEFT JOIN {$posts} p ON p.ID = r.post_id
               LEFT JOIN {$opps} o ON o.post_id = r.post_id AND r.source = 'opportunity'
              WHERE r.status IN ({$placeholders})
                AND (r.post_id = 0 OR p.ID IS NOT NULL)
              ORDER BY {$order}
              LIMIT %d",
            array_merge($statuses, array(max(1, (int) $args['limit'])))
        ), ARRAY_A);

        foreach ($rows as &$row) {
            $row['why'] = ECP_DB::decode($row['why']);
            $row['depends_on'] = ECP_DB::decode($row['depends_on']);
        }
        unset($row);

        return $rows;
    }

    /**
     * The next few active steps, for the dashboard and the digest.
     */
    public static function next_steps($limit = 3) {
        return self::query(array('limit' => $limit));
    }

    /**
     * Step titles by item_key, for rendering dependency lines.
     *
     * @param string[] $keys
     * @return array<string,array> item_key => { title, step_order, status }
     */
    public static function titles_for(array $keys) {
        global $wpdb;

        $keys = array_values(array_unique(array_filter($keys)));

        if (!$keys || !ECP_DB::tables_exist()) {
            return array();
        }

        $table = ECP_DB::roadmap_table();
        $placeholders = implode(',', array_fill(0, count($keys), '%s'));

        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT item_key, title, step_order, status FROM {$table} WHERE item_key IN ({$placeholders})",
            $keys
        ), ARRAY_A);

        $map = array();

        foreach ($rows as $row) {
            $map[$row['item_key']] = $row;
        }

        return $map;
    }

    /**
     * Posts whose roadmap step the owner has approved. The scheduler
     * analyzes these before anything else — approval is a promise that
     * the resulting proposals are wanted.
     *
     * @return int[]
     */
    public static function approved_post_ids() {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return array();
        }

        $table = ECP_DB::roadmap_table();

        $ids = (array) $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT post_id FROM {$table} WHERE status = %s AND source = 'opportunity' AND post_id > 0",
            self::APPROVED
        ));

        return array_map('intval', $ids);
    }

    /**
     * Steps completed since a moment — the digest's "what moved" line.
     *
     * @param string $since MySQL datetime.
     * @return int
     */
    public static function completed_since($since) {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return 0;
        }

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . ECP_DB::roadmap_table() . ' WHERE status = %s AND completed_at >= %s',
            self::DONE,
            $since
        ));
    }

    /**
     * Counters for the screen header.
     */
    public static function stats() {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return array('active' => 0, 'approved' => 0, 'postponed' => 0, 'done' => 0);
        }

        $table = ECP_DB::roadmap_table();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT
                SUM(status IN (%s, %s)) AS active,
                SUM(status = %s) AS approved,
                SUM(status = %s) AS postponed,
                SUM(status = %s) AS done
             FROM {$table}",
            self::PROPOSED,
            self::APPROVED,
            self::APPROVED,
            self::POSTPONED,
            self::DONE
        ), ARRAY_A);

        return array(
            'active'    => (int) $row['active'],
            'approved'  => (int) $row['approved'],
            'postponed' => (int) $row['postponed'],
            'done'      => (int) $row['done'],
        );
    }

    /* --------------------------------------------------------------------
     * Decisions
     * ----------------------------------------------------------------- */

    /**
     * Record the owner's decision on a step.
     *
     * @param int    $id
     * @param string $action approve | postpone | dismiss | reopen | complete
     * @param int    $days   Postponement length.
     * @return array|WP_Error The updated row.
     */
    public static function decide($id, $action, $days = 14) {
        global $wpdb;

        $table = ECP_DB::roadmap_table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", (int) $id), ARRAY_A);

        if (!$row) {
            return new WP_Error('not_found', __('That roadmap step no longer exists.', 'enhanced-content-plugin'));
        }

        $now = ECP_DB::now();
        $data = array(
            'decided_by' => get_current_user_id(),
            'decided_at' => $now,
            'updated_at' => $now,
        );

        switch ($action) {
            case 'approve':
                $data['status'] = self::APPROVED;
                $data['postponed_until'] = null;
                break;

            case 'postpone':
                $data['status'] = self::POSTPONED;
                $data['postponed_until'] = gmdate(
                    'Y-m-d H:i:s',
                    (int) current_time('timestamp') + max(1, (int) $days) * DAY_IN_SECONDS
                );
                break;

            case 'dismiss':
                $data['status'] = self::DISMISSED;
                break;

            case 'reopen':
                $data['status'] = self::PROPOSED;
                $data['postponed_until'] = null;
                $data['completed_at'] = null;
                break;

            case 'complete':
                $data['status'] = self::DONE;
                $data['completed_at'] = $now;
                break;

            default:
                return new WP_Error('bad_action', __('Unknown roadmap action.', 'enhanced-content-plugin'));
        }

        $wpdb->update($table, $data, array('id' => (int) $id), null, array('%d'));

        ECP_Log::record(ECP_Log::ROADMAP_DECIDED, array(
            'message' => sprintf(
                /* translators: 1: action, 2: step title */
                __('Roadmap: %1$s — "%2$s"', 'enhanced-content-plugin'),
                $action,
                $row['title']
            ),
            'post_id' => (int) $row['post_id'],
        ));

        self::renumber();

        return array_merge($row, $data);
    }

    /**
     * Lock or unlock a step. Locked steps hold their place at the front
     * of the plan and are never removed by a rebuild.
     */
    public static function set_locked($id, $locked) {
        global $wpdb;

        $updated = $wpdb->update(
            ECP_DB::roadmap_table(),
            array('locked' => $locked ? 1 : 0, 'updated_at' => ECP_DB::now()),
            array('id' => (int) $id),
            array('%d', '%s'),
            array('%d')
        );

        if (false === $updated) {
            return new WP_Error('not_found', __('That roadmap step no longer exists.', 'enhanced-content-plugin'));
        }

        self::renumber();

        return true;
    }

    /**
     * Track labels for the screen.
     */
    public static function track_label($track) {
        $labels = array(
            self::TRACK_TECHNICAL     => __('Visibility', 'enhanced-content-plugin'),
            self::TRACK_CONSOLIDATION => __('Consolidation', 'enhanced-content-plugin'),
            self::TRACK_SNIPPET       => __('Snippet', 'enhanced-content-plugin'),
            self::TRACK_LINKS         => __('Links', 'enhanced-content-plugin'),
            self::TRACK_CONTENT       => __('Content', 'enhanced-content-plugin'),
        );

        return isset($labels[$track]) ? $labels[$track] : ucfirst((string) $track);
    }
}
