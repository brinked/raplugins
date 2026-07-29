<?php
/**
 * Background work.
 *
 * Three cron jobs, deliberately separated so a slow or failing analysis run
 * never blocks scanning or housekeeping:
 *
 *   ecp_scan_cron       Hourly. Rescores a slice of the site. Free — no API.
 *   ecp_analyze_cron    Hourly. Sends the top of the queue to the model.
 *                       This is the only job that spends money.
 *   ecp_maintenance_cron Daily. Expiry, pruning, metrics sync.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ECP_Scheduler {

    const SCAN_HOOK        = 'ecp_scan_cron';
    const ANALYZE_HOOK     = 'ecp_analyze_cron';
    const MAINTENANCE_HOOK = 'ecp_maintenance_cron';

    /** How many posts one scan tick rescores. */
    const SCAN_BATCH = 40;

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        add_action(self::SCAN_HOOK, array($this, 'run_scan'));
        add_action(self::ANALYZE_HOOK, array($this, 'run_analysis'));
        add_action(self::MAINTENANCE_HOOK, array($this, 'run_maintenance'));

        // Self-heal: if the schedule was lost (a migration, a cron plugin,
        // a botched deactivation) put it back.
        add_action('init', array(__CLASS__, 'schedule_events'), 20);

        // A published edit invalidates that post's score.
        add_action('save_post', array($this, 'on_post_saved'), 30, 3);
    }

    /* --------------------------------------------------------------------
     * Schedule
     * ----------------------------------------------------------------- */

    public static function schedule_events() {
        if (!wp_next_scheduled(self::SCAN_HOOK)) {
            // Offset the two hourly jobs so a scan and an analysis don't land
            // in the same request on a site using WP's pseudo-cron.
            wp_schedule_event(time() + 300, 'hourly', self::SCAN_HOOK);
        }

        if (!wp_next_scheduled(self::ANALYZE_HOOK)) {
            wp_schedule_event(time() + 900, 'hourly', self::ANALYZE_HOOK);
        }

        if (!wp_next_scheduled(self::MAINTENANCE_HOOK)) {
            wp_schedule_event(time() + 1800, 'daily', self::MAINTENANCE_HOOK);
        }
    }

    public static function unschedule_events() {
        foreach (array(self::SCAN_HOOK, self::ANALYZE_HOOK, self::MAINTENANCE_HOOK) as $hook) {
            $timestamp = wp_next_scheduled($hook);

            while ($timestamp) {
                wp_unschedule_event($timestamp, $hook);
                $timestamp = wp_next_scheduled($hook);
            }
        }
    }

    /**
     * Next run times, for the dashboard.
     *
     * @return array<string,int|false>
     */
    public static function next_runs() {
        return array(
            'scan'        => wp_next_scheduled(self::SCAN_HOOK),
            'analyze'     => wp_next_scheduled(self::ANALYZE_HOOK),
            'maintenance' => wp_next_scheduled(self::MAINTENANCE_HOOK),
        );
    }

    /**
     * Whether the agent is actually working on its own, and if not, the
     * specific reason.
     *
     * Worth being explicit about: every switch here defaults to on, so a site
     * that has never touched settings is automated. But four separate things
     * can stop it, three of them silently, and "why has nothing been analysed"
     * is not a question anyone should have to answer by reading source.
     *
     * @return array { running, per_hour, per_day, used_today, reasons[], next }
     */
    public static function automation_status() {
        $reasons = array();

        $agent_on = ECP_Agent_Settings::is_on('agent_enabled');
        $analysis_on = ECP_Agent_Settings::is_on('analysis_enabled');
        $daily_cap = ECP_Limits::limit('analyze');

        if (!$agent_on) {
            $reasons[] = __('The agent is switched off in Settings → General.', 'enhanced-content-plugin');
        }

        if (!$analysis_on) {
            $reasons[] = __('Automatic analysis is switched off in Settings → General. Scanning still runs, so the queue fills up, but nothing is analysed until you ask.', 'enhanced-content-plugin');
        }

        if (!ECP_Agent_Settings::is_ready()) {
            $reasons[] = __('No working AI provider — add an API key in Settings → AI provider.', 'enhanced-content-plugin');
        }

        $budget = ECP_AI_Client::budget_check();

        if (is_wp_error($budget)) {
            $reasons[] = $budget->get_error_message();
        }

        if (!wp_next_scheduled(self::ANALYZE_HOOK)) {
            $reasons[] = __('The hourly job is not on WordPress\'s schedule. Loading any admin page normally restores it.', 'enhanced-content-plugin');
        }

        if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
            $reasons[] = __('WP-Cron is disabled on this site (DISABLE_WP_CRON), so scheduled jobs only fire if a real cron job calls wp-cron.php.', 'enhanced-content-plugin');
        }

        // Mirrors the arithmetic in run_analysis(), so the number shown is the
        // number that will actually happen rather than a second guess at it.
        // The +1 is the hourly reader-question audit, which rides the same
        // daily budget.
        $per_hour = $daily_cap > 0 ? max(1, (int) ceil($daily_cap / 12)) : 3;

        if ('off' !== ECP_Agent_Settings::get('gap_mode', 'propose')) {
            $per_hour++;
        }

        return array(
            'running'    => empty($reasons),
            'per_hour'   => $per_hour,
            'per_day'    => $daily_cap,
            'used_today' => ECP_AI_Client::daily_analyses(),
            'reasons'    => $reasons,
            'next'       => wp_next_scheduled(self::ANALYZE_HOOK),
        );
    }

    /* --------------------------------------------------------------------
     * Jobs
     * ----------------------------------------------------------------- */

    /**
     * Rescore a slice of the site, walking through it over successive ticks
     * so a 5,000-post site never tries to do everything in one request.
     */
    public function run_scan() {
        if (!ECP_Agent_Settings::is_on('agent_enabled') || !ECP_Agent_Settings::is_on('scan_enabled')) {
            return;
        }

        if (!ECP_DB::tables_exist()) {
            return;
        }

        $offset = (int) get_option('ecp_scan_offset', 0);
        $result = ECP_Opportunity_Engine::scan_batch($offset, self::SCAN_BATCH);

        $next_offset = $offset + $result['processed'];

        // Wrap around when we reach the end, so scanning is continuous.
        if ($result['processed'] < self::SCAN_BATCH || $next_offset >= $result['total']) {
            $next_offset = 0;

            ECP_Log::info(ECP_Log::SCAN_COMPLETED, sprintf(
                /* translators: %d: number of posts */
                __('Finished a full pass over %d posts.', 'enhanced-content-plugin'),
                (int) $result['total']
            ));

            // The scores just changed under the plan — re-derive it now so
            // the roadmap the owner opens tomorrow reflects tonight's scan.
            delete_transient('ecp_roadmap_fresh');
            ECP_Roadmap::rebuild();
        }

        update_option('ecp_scan_offset', $next_offset, false);
    }

    /**
     * Analyze the top of the queue, within the configured caps.
     */
    public function run_analysis() {
        if (!ECP_Agent_Settings::is_on('agent_enabled') || !ECP_Agent_Settings::is_on('analysis_enabled')) {
            return;
        }

        if (!ECP_Agent_Settings::is_ready()) {
            return;
        }

        $budget = ECP_AI_Client::budget_check();
        if (is_wp_error($budget)) {
            return;
        }

        // Spread the daily allowance across the day rather than burning it in
        // the first hour — that keeps the review queue arriving in digestible
        // batches and leaves headroom for on-demand analyses.
        $daily_cap = ECP_Limits::limit('analyze');
        $per_tick = $daily_cap > 0 ? max(1, (int) ceil($daily_cap / 12)) : 3;

        // Clusters first. A cannibalisation fix changes which page should own
        // a topic, and analysing the individual pages before that decision is
        // made produces proposals that pull in the opposite direction.
        $clusters = ECP_Clusters::next_for_analysis(1);

        foreach ($clusters as $cluster_id) {
            if (is_wp_error(ECP_AI_Client::budget_check())) {
                return;
            }

            ECP_Analyzer::analyze_cluster($cluster_id, array('trigger_source' => 'cron'));
            $per_tick--;
        }

        if ($per_tick < 1) {
            return;
        }

        $post_ids = ECP_Opportunity_Engine::next_for_analysis($per_tick);

        foreach ($post_ids as $post_id) {
            // Re-check between posts: the cap may be hit mid-batch.
            if (is_wp_error(ECP_AI_Client::budget_check())) {
                return;
            }

            ECP_Analyzer::analyze($post_id, array('trigger_source' => 'cron'));
        }

        // One classification batch per tick, until the whole site is
        // classified. Separate meter from analyses, so cataloguing a big
        // site never eats the day's improvement budget; after the initial
        // pass this almost always no-ops (only edited pages re-qualify).
        if (!is_wp_error(ECP_Limits::can('classify'))) {
            $unclassified = ECP_Inventory::unclassified(1);

            if ($unclassified) {
                ECP_Classifier::run_batch('cron');
            }
        }

        // One reader-question audit per tick, on the most valuable page
        // whose audit is missing or describes text that has since changed.
        // Costs one analysis from the same daily budget; article quality is
        // the product, so it rides in the standard rotation rather than
        // being a button someone has to remember.
        if ('off' !== ECP_Agent_Settings::get('gap_mode', 'propose') && !is_wp_error(ECP_AI_Client::budget_check())) {
            $candidate = self::next_for_gap_audit();

            if ($candidate) {
                ECP_Content_Gaps::analyze($candidate, array('trigger_source' => 'cron'));
            }
        }

        // Apply refresh changes whose review window has closed. Hourly, so
        // "held for 48 hours" means 48 hours, not "whenever the nightly job
        // next runs". Free — these proposals already exist.
        ECP_Refresh::apply_due();
    }

    /**
     * The highest-scoring published page with no current reader-question
     * audit.
     *
     * "Current" means the stored report's content hash matches the page's
     * hash from the last scan — an audit describes one specific version of
     * an article, and editing the article expires it.
     *
     * @return int Post ID, or 0.
     */
    private static function next_for_gap_audit() {
        global $wpdb;

        $rows = $wpdb->get_results(
            'SELECT o.post_id, o.content_hash, o.gap_report
               FROM ' . ECP_DB::opportunities_table() . " o
              INNER JOIN {$wpdb->posts} p ON p.ID = o.post_id
              WHERE p.post_status = 'publish'
              ORDER BY o.score DESC
              LIMIT 10",
            ARRAY_A
        );

        $excluded = ECP_Agent_Settings::excluded_post_ids();

        foreach ((array) $rows as $row) {
            if (in_array((int) $row['post_id'], $excluded, true)) {
                continue;
            }

            $report = ECP_DB::decode($row['gap_report']);

            $current = $report
                && isset($report['content_hash'])
                && $report['content_hash'] === $row['content_hash'];

            if (!$current) {
                return (int) $row['post_id'];
            }
        }

        return 0;
    }

    /**
     * Daily housekeeping.
     */
    public function run_maintenance() {
        if (!ECP_DB::tables_exist()) {
            return;
        }

        ECP_Proposals::expire_stale();

        ECP_DB::prune((int) ECP_Agent_Settings::get('retention_days', 180));

        ECP_Search_Data::prune();

        ECP_Inventory::prune();

        // Refresh search metrics where we have a live connection.
        if ('sitekit' === ECP_Search_Data::active_source()) {
            $synced = ECP_Search_Data::sync_all();

            if (is_wp_error($synced)) {
                ECP_Log::warn('metrics.sync_failed', $synced->get_error_message());
            }
        }

        // Measure applied changes against their baselines. After the sync,
        // so today's checkpoints compare against today's data.
        ECP_Measurement::run();

        // The nightly pass over aging articles, where enabled.
        ECP_Refresh::run_daily();

        // Cluster detection runs after the metrics refresh, so it works from
        // today's query data rather than yesterday's.
        if (ECP_Agent_Settings::is_on('clusters_enabled')) {
            $result = ECP_Clusters::detect();

            if ($result['found'] > 0) {
                ECP_Log::info('clusters.detected', sprintf(
                    /* translators: %d: number of clusters */
                    _n('Found %d group of pages competing for the same topic.', 'Found %d groups of pages competing for the same topic.', $result['found'], 'enhanced-content-plugin'),
                    $result['found']
                ));
            }
        }
    }

    /**
     * A post that changed needs rescoring, and any pending proposal that
     * targeted its old text is now suspect.
     */
    public function on_post_saved($post_id, $post, $update) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (wp_is_post_revision($post_id) || 'auto-draft' === $post->post_status) {
            return;
        }

        if (!in_array($post->post_type, (array) ECP_Agent_Settings::get('post_types', array('post')), true)) {
            return;
        }

        unset($update);

        // Don't rescore inside a write the applier is performing — the post
        // is half-updated at this point, and a batch approval would trigger
        // this once per change.
        if (ECP_Applier::$applying) {
            return;
        }

        // Cheap: one row update, no API call.
        ECP_Opportunity_Engine::score_post($post_id);
    }

    /* --------------------------------------------------------------------
     * Manual triggers
     * ----------------------------------------------------------------- */

    /**
     * Run a full scan now, in batches, bounded so an admin request cannot
     * hang. Returns progress the caller can loop on.
     *
     * @return array { processed, total, offset, done }
     */
    public static function scan_now($offset = 0, $batch = 50) {
        $result = ECP_Opportunity_Engine::scan_batch($offset, $batch);
        $next = $offset + $result['processed'];
        $done = $result['processed'] < $batch || $next >= $result['total'];

        if (0 === $offset) {
            ECP_Log::info(ECP_Log::SCAN_STARTED, __('Manual scan started.', 'enhanced-content-plugin'));
        }

        if ($done) {
            update_option('ecp_scan_offset', 0, false);

            ECP_Log::info(ECP_Log::SCAN_COMPLETED, sprintf(
                /* translators: %d: number of posts scanned */
                __('Manual scan finished: %d posts scored.', 'enhanced-content-plugin'),
                $next
            ));
        }

        return array(
            'processed' => $result['processed'],
            'total'     => $result['total'],
            'offset'    => $next,
            'done'      => $done,
        );
    }
}
