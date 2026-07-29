<?php
/**
 * Automatic freshness maintenance for aging articles.
 *
 * The deal this feature offers, and the reason it is opt-in: for articles
 * past an age you choose, the agent may apply small, useful improvements on
 * its own — tightened sections, better snippets, internal links, alt text,
 * FAQ entries from real reader questions — so the article earns a genuine
 * freshness signal without you approving every comma.
 *
 * "Genuine" is the load-bearing word. Nothing here bumps a modified date
 * for its own sake and nothing invents facts: every change is produced by
 * the same analysis, guardrails and revision safety as a manual one, only
 * the approval step is replaced by a waiting period. Changes sit in the
 * review queue for a configurable number of hours first — review them if
 * you want, and silence is consent. Anything factual, sensitive or flagged
 * still waits for a human indefinitely, whatever this setting says.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ECP_Refresh {

    /** Post meta: when this article last went through a refresh cycle. */
    const META_LAST = '_ecp_last_refreshed';

    /** Auto-apply floor — below this confidence a human decides, always. */
    const MIN_CONFIDENCE = 75;

    public static function enabled() {
        return ECP_Agent_Settings::is_on('agent_enabled')
            && (bool) ECP_Agent_Settings::get('refresh_enabled', 0);
    }

    /**
     * Analyze a day's worth of aging articles. Called from the daily
     * maintenance job.
     *
     * @return int Articles put through the cycle.
     */
    public static function run_daily() {
        if (!self::enabled() || !ECP_Agent_Settings::is_ready()) {
            return 0;
        }

        $processed = 0;

        foreach (self::candidates((int) ECP_Agent_Settings::get('refresh_per_day', 2)) as $post_id) {
            if (is_wp_error(ECP_AI_Client::budget_check())) {
                break;
            }

            $types = self::allowed_types();

            if (!$types) {
                break;   // Every refresh type has been unticked.
            }

            // freshness_update rides along on top of the ticked list: the
            // whole point of refreshing an old article is finding what has
            // gone stale in it. It is risk-sensitive, so may_auto_apply()
            // can never land it — those proposals wait in the queue for a
            // person, exactly as the settings screen promises.
            $result = ECP_Analyzer::analyze($post_id, array(
                'trigger_source' => 'refresh',
                'change_types'   => array_values(array_unique(array_merge($types, array('freshness_update')))),
            ));

            if (is_wp_error($result)) {
                continue;   // Provider hiccup — leave unstamped, retry tomorrow.
            }

            // Stamped whether or not anything was proposed: "analyzed and
            // found fine" is a completed refresh, and re-running the same
            // unchanged article every night would burn the budget on it.
            update_post_meta($post_id, self::META_LAST, current_time('mysql'));
            $processed++;

            foreach ((array) $result['proposals'] as $proposal_id) {
                self::tag($proposal_id);
            }
        }

        if ($processed) {
            ECP_Log::info('refresh.cycle', sprintf(
                /* translators: %d: number of articles */
                _n('Refreshed %d aging article.', 'Ran the refresh cycle on %d aging articles.', $processed, 'enhanced-content-plugin'),
                $processed
            ));
        }

        // With no waiting period, eligible changes land right away rather
        // than on the next hourly tick.
        if (0 === (int) ECP_Agent_Settings::get('refresh_hold_hours', 48)) {
            self::apply_due();
        }

        return $processed;
    }

    /**
     * Apply refresh proposals whose waiting period has passed. Called
     * hourly, so a 48-hour hold means 48 hours, not "sometime this week".
     *
     * @return int Changes applied.
     */
    public static function apply_due() {
        global $wpdb;

        if (!self::enabled() || !ECP_DB::tables_exist()) {
            return 0;
        }

        $hold = max(0, (int) ECP_Agent_Settings::get('refresh_hold_hours', 48));
        $cutoff = gmdate('Y-m-d H:i:s', strtotime("-{$hold} hours", (int) current_time('timestamp')));

        $ids = $wpdb->get_col($wpdb->prepare(
            'SELECT id FROM ' . ECP_DB::proposals_table() . '
              WHERE status = %s AND flags LIKE %s AND created_at <= %s
              ORDER BY id ASC
              LIMIT 20',
            ECP_Proposals::PENDING,
            '%"auto_refresh":true%',
            $cutoff
        ));

        $applied = 0;

        foreach ((array) $ids as $id) {
            $proposal = ECP_Proposals::get((int) $id);

            if (!$proposal || !self::may_auto_apply($proposal)) {
                continue;
            }

            $result = ECP_Applier::apply((int) $id, true);

            if (!is_wp_error($result)) {
                $applied++;
            }
        }

        return $applied;
    }

    /**
     * The bar a refresh change must clear to land without a human.
     *
     * Deliberately stricter than the proposal pipeline itself: the pipeline
     * only has to produce something worth REVIEWING, this has to be right
     * unattended. Anything it declines simply stays in the queue for a
     * person — declining is free, wrongly applying is not.
     */
    private static function may_auto_apply(array $proposal) {
        if (ECP_Proposals::RISK_SENSITIVE === $proposal['risk']) {
            return false;
        }

        if ((int) $proposal['confidence'] < self::MIN_CONFIDENCE) {
            return false;
        }

        // Not in the currently-ticked set — the owner may have narrowed the
        // list since this was proposed, and the current setting wins.
        if (!in_array($proposal['change_type'], self::allowed_types(), true)) {
            return false;
        }

        $flags = is_array($proposal['flags']) ? $proposal['flags'] : array();

        if (!empty($flags['unverified_claims']) || !empty($flags['new_figures']) || !empty($flags['brand_terms_altered'])) {
            return false;
        }

        // A large trim was flagged specifically so a person would read it.
        if (!empty($flags['large_trim'])) {
            return false;
        }

        return true;
    }

    /**
     * Change types the refresh may propose: the ones ticked in settings,
     * further limited to the analyzer's refresh-sized set as a hard floor.
     *
     * @return string[]
     */
    public static function allowed_types() {
        $chosen = (array) ECP_Agent_Settings::get('refresh_types', array());

        return array_values(array_intersect($chosen, ECP_Analyzer::REFRESH_TYPES));
    }

    /**
     * Published articles old enough to qualify and not refreshed recently,
     * oldest-untouched first.
     *
     * @return int[]
     */
    public static function candidates($limit = 2) {
        $age_days = max(90, (int) ECP_Agent_Settings::get('refresh_age_days', 365));
        $interval = max(30, (int) ECP_Agent_Settings::get('refresh_interval_days', 90));

        $args = array(
            'post_type'      => (array) ECP_Agent_Settings::get('post_types', array('post')),
            'post_status'    => 'publish',
            'posts_per_page' => max(1, (int) $limit),
            'orderby'        => 'modified',
            'order'          => 'ASC',
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'date_query'     => array(
                array(
                    'column' => 'post_date_gmt',
                    'before' => gmdate('Y-m-d H:i:s', strtotime("-{$age_days} days")),
                ),
            ),
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
            'meta_query'     => array(
                'relation' => 'OR',
                array('key' => self::META_LAST, 'compare' => 'NOT EXISTS'),
                array(
                    'key'     => self::META_LAST,
                    'compare' => '<',
                    'value'   => gmdate('Y-m-d H:i:s', strtotime("-{$interval} days", (int) current_time('timestamp'))),
                ),
            ),
        );

        $excluded = ECP_Agent_Settings::excluded_post_ids();

        if ($excluded) {
            $args['post__not_in'] = $excluded;
        }

        $query = new WP_Query($args);

        return array_map('intval', (array) $query->posts);
    }

    /**
     * Mark a proposal as born from the refresh cycle, which is what makes
     * it eligible for the waiting-period auto-apply.
     */
    private static function tag($proposal_id) {
        $proposal = ECP_Proposals::get((int) $proposal_id);

        if (!$proposal) {
            return;
        }

        $flags = is_array($proposal['flags']) ? $proposal['flags'] : array();
        $flags['auto_refresh'] = true;

        ECP_Proposals::update((int) $proposal_id, array('flags' => $flags));
    }

    /**
     * Pending refresh changes and when the next lands, for the dashboard.
     *
     * @return array { waiting, next_at }
     */
    public static function queue_status() {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return array('waiting' => 0, 'next_at' => null);
        }

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT COUNT(*) AS waiting, MIN(created_at) AS oldest
               FROM ' . ECP_DB::proposals_table() . '
              WHERE status = %s AND flags LIKE %s',
            ECP_Proposals::PENDING,
            '%"auto_refresh":true%'
        ), ARRAY_A);

        $hold = max(0, (int) ECP_Agent_Settings::get('refresh_hold_hours', 48));
        $next = null;

        if ($row && $row['oldest']) {
            $next = strtotime($row['oldest']) + $hold * HOUR_IN_SECONDS;
        }

        return array(
            'waiting' => $row ? (int) $row['waiting'] : 0,
            'next_at' => $next,
        );
    }
}
