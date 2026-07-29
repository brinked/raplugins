<?php
/**
 * Database schema and low-level access for the SEO agent.
 *
 * Five tables, all prefixed `{wp_prefix}ecp_`:
 *
 *   ecp_runs        One row per agent job (scan, analyze, measure).
 *   ecp_opportunities  Latest opportunity score per post.
 *   ecp_proposals   Individual proposed changes awaiting approval.
 *   ecp_events      Append-only audit trail of everything the agent did.
 *   ecp_metrics     Daily Search Console rows per post (and per query).
 *
 * Post meta is deliberately not used for proposals: a busy site generates
 * thousands of them, they need indexed status/date queries, and they must
 * survive independently of the post they target.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ECP_DB {

    /**
     * Bump this when a CREATE TABLE statement below changes. dbDelta then
     * runs on the next request and migrates existing installs in place.
     */
    const SCHEMA_VERSION = '2.8.3';

    /* --------------------------------------------------------------------
     * Table names
     * ----------------------------------------------------------------- */

    public static function runs_table() {
        global $wpdb;
        return $wpdb->prefix . 'ecp_runs';
    }

    public static function opportunities_table() {
        global $wpdb;
        return $wpdb->prefix . 'ecp_opportunities';
    }

    public static function proposals_table() {
        global $wpdb;
        return $wpdb->prefix . 'ecp_proposals';
    }

    public static function events_table() {
        global $wpdb;
        return $wpdb->prefix . 'ecp_events';
    }

    public static function metrics_table() {
        global $wpdb;
        return $wpdb->prefix . 'ecp_metrics';
    }

    public static function clusters_table() {
        global $wpdb;
        return $wpdb->prefix . 'ecp_clusters';
    }

    public static function inventory_table() {
        global $wpdb;
        return $wpdb->prefix . 'ecp_inventory';
    }

    /**
     * All table names, for uninstall.
     *
     * @return string[]
     */
    public static function all_tables() {
        return array(
            self::runs_table(),
            self::opportunities_table(),
            self::proposals_table(),
            self::events_table(),
            self::metrics_table(),
            self::clusters_table(),
            self::inventory_table(),
        );
    }

    /* --------------------------------------------------------------------
     * Install
     * ----------------------------------------------------------------- */

    /**
     * Create or migrate all agent tables.
     *
     * dbDelta is fussy: two spaces after PRIMARY KEY, KEY names lowercase,
     * one field per line, no backticks around the table name.
     */
    public static function install() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();

        $runs = self::runs_table();
        $opportunities = self::opportunities_table();
        $proposals = self::proposals_table();
        $events = self::events_table();
        $metrics = self::metrics_table();
        $clusters = self::clusters_table();
        $inventory = self::inventory_table();

        // ---- Runs -------------------------------------------------------
        // job_type: scan | analyze | measure | manual
        // status:   queued | running | complete | failed | cancelled
        dbDelta("CREATE TABLE {$runs} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            job_type varchar(32) NOT NULL DEFAULT 'scan',
            status varchar(20) NOT NULL DEFAULT 'queued',
            post_id bigint(20) unsigned NOT NULL DEFAULT 0,
            triggered_by bigint(20) unsigned NOT NULL DEFAULT 0,
            trigger_source varchar(20) NOT NULL DEFAULT 'cron',
            provider varchar(32) NOT NULL DEFAULT '',
            model varchar(64) NOT NULL DEFAULT '',
            input_tokens int(10) unsigned NOT NULL DEFAULT 0,
            output_tokens int(10) unsigned NOT NULL DEFAULT 0,
            cost_micros bigint(20) unsigned NOT NULL DEFAULT 0,
            proposals_created smallint(5) unsigned NOT NULL DEFAULT 0,
            message text NULL,
            started_at datetime NULL,
            finished_at datetime NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY job_status (job_type,status),
            KEY post_id (post_id),
            KEY created_at (created_at)
        ) {$charset};");

        // ---- Opportunities ----------------------------------------------
        // One row per post: the newest scan result. Re-scanning replaces it.
        // status: open | analyzing | proposed | done | dismissed | snoozed
        dbDelta("CREATE TABLE {$opportunities} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            post_id bigint(20) unsigned NOT NULL,
            score decimal(6,2) NOT NULL DEFAULT 0.00,
            potential_clicks decimal(10,2) NOT NULL DEFAULT 0.00,
            primary_reason varchar(64) NOT NULL DEFAULT '',
            reasons longtext NULL,
            signals longtext NULL,
            gap_report longtext NULL,
            status varchar(20) NOT NULL DEFAULT 'open',
            snoozed_until datetime NULL,
            content_hash varchar(40) NOT NULL DEFAULT '',
            last_scanned_at datetime NULL,
            last_analyzed_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY post_id (post_id),
            KEY status_score (status,score),
            KEY last_analyzed_at (last_analyzed_at)
        ) {$charset};");

        // ---- Proposals ---------------------------------------------------
        // The heart of the approval workflow. `payload` holds the structured
        // change (target selector, new value, evidence); before/after hold
        // renderable text for the diff view.
        //
        // status: pending | approved | rejected | applied | reverted
        //         | superseded | failed | expired
        // risk:   safe | moderate | sensitive
        dbDelta("CREATE TABLE {$proposals} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            run_id bigint(20) unsigned NOT NULL DEFAULT 0,
            cluster_id bigint(20) unsigned NOT NULL DEFAULT 0,
            post_id bigint(20) unsigned NOT NULL,
            change_type varchar(48) NOT NULL,
            target_key varchar(191) NOT NULL DEFAULT '',
            title varchar(255) NOT NULL DEFAULT '',
            rationale text NULL,
            evidence longtext NULL,
            before_value longtext NULL,
            after_value longtext NULL,
            payload longtext NULL,
            confidence tinyint(3) unsigned NOT NULL DEFAULT 0,
            risk varchar(16) NOT NULL DEFAULT 'moderate',
            impact tinyint(3) unsigned NOT NULL DEFAULT 0,
            flags longtext NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            content_hash varchar(40) NOT NULL DEFAULT '',
            reviewed_by bigint(20) unsigned NOT NULL DEFAULT 0,
            reviewed_at datetime NULL,
            review_note text NULL,
            applied_at datetime NULL,
            revision_id bigint(20) unsigned NOT NULL DEFAULT 0,
            revert_data longtext NULL,
            auto_applied tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY status_created (status,created_at),
            KEY post_status (post_id,status),
            KEY run_id (run_id),
            KEY cluster_id (cluster_id),
            KEY change_type (change_type),
            KEY risk (risk)
        ) {$charset};");

        // ---- Events (audit trail) -----------------------------------------
        dbDelta("CREATE TABLE {$events} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event varchar(48) NOT NULL,
            level varchar(16) NOT NULL DEFAULT 'info',
            post_id bigint(20) unsigned NOT NULL DEFAULT 0,
            proposal_id bigint(20) unsigned NOT NULL DEFAULT 0,
            run_id bigint(20) unsigned NOT NULL DEFAULT 0,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            message text NULL,
            context longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY event_created (event,created_at),
            KEY post_id (post_id),
            KEY proposal_id (proposal_id),
            KEY created_at (created_at)
        ) {$charset};");

        // ---- Search metrics ------------------------------------------------
        // A row per (post, query, window, date). query = '' means the page
        // total for that window.
        //
        // `window_days` is what makes "last 7 days" and "last 28 days" able
        // to coexist. Search Console figures are always an average over some
        // window, and a position of 8.4 means nothing until you say over
        // what period — so the period is part of the row's identity, not an
        // assumption baked into the sync.
        //
        // `metric_date` is the END of the window, i.e. the day the snapshot
        // describes. Successive syncs on different days build the history
        // that the movement column and sparklines read back.
        self::drop_legacy_metrics_index();

        dbDelta("CREATE TABLE {$metrics} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            post_id bigint(20) unsigned NOT NULL,
            metric_date date NOT NULL,
            window_days smallint(5) unsigned NOT NULL DEFAULT 28,
            query varchar(191) NOT NULL DEFAULT '',
            clicks int(10) unsigned NOT NULL DEFAULT 0,
            impressions int(10) unsigned NOT NULL DEFAULT 0,
            ctr decimal(7,5) NOT NULL DEFAULT 0.00000,
            position decimal(6,2) NOT NULL DEFAULT 0.00,
            source varchar(24) NOT NULL DEFAULT 'gsc',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY post_window_date_query (post_id,window_days,metric_date,query),
            KEY window_date (window_days,metric_date),
            KEY post_window_query (post_id,window_days,query)
        ) {$charset};");

        // ---- Site inventory --------------------------------------------------
        // One row per post the agent may work on: the structured facts of the
        // page (Phase 1 of the growth system). The classification block is
        // written only by the classifier; classified_hash records which
        // version of the content the classification describes, so staleness
        // is a comparison, not a guess. `locked` marks a human-corrected
        // topic the classifier must never overwrite — user corrections are
        // ground truth.
        dbDelta("CREATE TABLE {$inventory} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            post_id bigint(20) unsigned NOT NULL,
            url varchar(255) NOT NULL DEFAULT '',
            post_type varchar(32) NOT NULL DEFAULT 'post',
            post_status varchar(20) NOT NULL DEFAULT 'publish',
            title varchar(255) NOT NULL DEFAULT '',
            meta_description varchar(255) NOT NULL DEFAULT '',
            word_count int(10) unsigned NOT NULL DEFAULT 0,
            heading_json longtext NULL,
            taxonomy_json longtext NULL,
            author_id bigint(20) unsigned NOT NULL DEFAULT 0,
            internal_links_out smallint(5) unsigned NOT NULL DEFAULT 0,
            internal_links_in smallint(5) unsigned NOT NULL DEFAULT 0,
            external_links smallint(5) unsigned NOT NULL DEFAULT 0,
            image_count smallint(5) unsigned NOT NULL DEFAULT 0,
            schema_types varchar(191) NOT NULL DEFAULT '',
            content_hash char(40) NOT NULL DEFAULT '',
            topic varchar(191) NOT NULL DEFAULT '',
            subtopic varchar(191) NOT NULL DEFAULT '',
            intent varchar(24) NOT NULL DEFAULT '',
            funnel_stage varchar(24) NOT NULL DEFAULT '',
            confidence tinyint(3) unsigned NOT NULL DEFAULT 0,
            locked tinyint(1) NOT NULL DEFAULT 0,
            classified_hash char(40) NOT NULL DEFAULT '',
            classified_at datetime NULL,
            scanned_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY post_id (post_id),
            KEY topic (topic),
            KEY intent (intent),
            KEY post_type_status (post_type,post_status)
        ) {$charset};");

        // ---- Clusters -------------------------------------------------------
        // Groups of pages competing for the same topic. `cluster_key` is a
        // hash of the sorted member IDs, so re-running detection updates the
        // existing row instead of accumulating duplicates.
        //
        // type:   cannibalisation | overlap
        // status: open | analyzing | proposed | resolved | dismissed
        dbDelta("CREATE TABLE {$clusters} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            cluster_key varchar(32) NOT NULL,
            type varchar(32) NOT NULL DEFAULT 'cannibalisation',
            label varchar(191) NOT NULL DEFAULT '',
            member_ids longtext NULL,
            member_count smallint(5) unsigned NOT NULL DEFAULT 0,
            primary_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
            score decimal(6,2) NOT NULL DEFAULT 0.00,
            evidence longtext NULL,
            recommendation longtext NULL,
            status varchar(20) NOT NULL DEFAULT 'open',
            detected_at datetime NULL,
            analyzed_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY cluster_key (cluster_key),
            KEY status_score (status,score),
            KEY primary_post_id (primary_post_id)
        ) {$charset};");
    }

    /**
     * Remove the pre-2.2 unique key on the metrics table.
     *
     * That key was (post_id, metric_date, query), from when only one window
     * was ever stored. Leaving it in place would make a 7-day row and a
     * 28-day row for the same page, term and date collide — the second one
     * would silently overwrite the first, and multi-window data would look
     * like it was working while quietly holding whichever window synced last.
     *
     * dbDelta adds indexes but never drops them, so this has to be explicit.
     */
    private static function drop_legacy_metrics_index() {
        global $wpdb;

        $metrics = self::metrics_table();

        // Nothing to migrate on a fresh install.
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $metrics)) !== $metrics) {
            return;
        }

        // Read every index name in one go. `SHOW INDEX ... WHERE Key_name`
        // was the previous approach and something about it did not take on a
        // real install, so this reads the whole list and compares in PHP
        // where the result is unambiguous. Column 2 is Key_name.
        $names = (array) $wpdb->get_col("SHOW INDEX FROM {$metrics}", 2);  // phpcs:ignore WordPress.DB.PreparedSQL

        // 1. Ensure the column exists before anything indexes it.
        $has_column = (bool) $wpdb->get_var("SHOW COLUMNS FROM {$metrics} LIKE 'window_days'");  // phpcs:ignore WordPress.DB.PreparedSQL

        if (!$has_column) {
            $wpdb->query("ALTER TABLE {$metrics} ADD COLUMN window_days smallint(5) unsigned NOT NULL DEFAULT 28");  // phpcs:ignore WordPress.DB.PreparedSQL
        }

        // 2. Drop the pre-2.2 key. This one is not cosmetic: every reporting
        // window writes the same (post, date, query) and differs only by
        // window_days, and $wpdb->replace() issues a REPLACE INTO, which
        // deletes any row matching *any* unique key before inserting. With
        // the old key still present, syncing 7 then 28 then 90 days meant
        // each window silently deleted the one before it and only the last
        // survived.
        if (in_array('post_date_query', $names, true)) {
            $wpdb->query("ALTER TABLE {$metrics} DROP INDEX post_date_query");  // phpcs:ignore WordPress.DB.PreparedSQL
        }

        // 3. Add the correct key ourselves rather than trusting dbDelta,
        // which is unreliable about index changes on an existing table.
        if (!in_array('post_window_date_query', $names, true)) {
            // A unique index will not build over duplicates, and duplicates
            // are possible if the old key was dropped while the new one was
            // never created. Keep the newest row of each tuple.
            $wpdb->query(
                "DELETE older FROM {$metrics} older
                 INNER JOIN {$metrics} newer
                    ON older.post_id = newer.post_id
                   AND older.window_days = newer.window_days
                   AND older.metric_date = newer.metric_date
                   AND older.query = newer.query
                   AND older.id < newer.id"  // phpcs:ignore WordPress.DB.PreparedSQL
            );

            $wpdb->query(
                "ALTER TABLE {$metrics}
                 ADD UNIQUE KEY post_window_date_query (post_id,window_days,metric_date,query)"  // phpcs:ignore WordPress.DB.PreparedSQL
            );
        }
    }

    /**
     * Whether the metrics table is shaped the way the multi-window code
     * expects. Surfaced in the settings diagnostics, because a half-applied
     * migration here looks exactly like "Google returned no data".
     *
     * @return array { window_column, legacy_index_gone, unique_index, ok }
     */
    public static function metrics_schema_status() {
        global $wpdb;

        $metrics = self::metrics_table();

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $metrics)) !== $metrics) {
            return array('window_column' => false, 'legacy_index_gone' => true, 'unique_index' => false, 'ok' => false);
        }

        $names = (array) $wpdb->get_col("SHOW INDEX FROM {$metrics}", 2);  // phpcs:ignore WordPress.DB.PreparedSQL

        $status = array(
            'window_column'     => (bool) $wpdb->get_var("SHOW COLUMNS FROM {$metrics} LIKE 'window_days'"),  // phpcs:ignore WordPress.DB.PreparedSQL
            'legacy_index_gone' => !in_array('post_date_query', $names, true),
            'unique_index'      => in_array('post_window_date_query', $names, true),
        );

        $status['ok'] = $status['window_column'] && $status['legacy_index_gone'] && $status['unique_index'];

        return $status;
    }

    /* --------------------------------------------------------------------
     * Helpers
     * ----------------------------------------------------------------- */

    /**
     * Current time in MySQL datetime format, site timezone.
     */
    public static function now() {
        return current_time('mysql');
    }

    /**
     * JSON-encode for a longtext column. Returns '' for empty input so the
     * column never holds the literal string "null".
     *
     * @param mixed $value
     * @return string
     */
    public static function encode($value) {
        if (null === $value || (is_array($value) && empty($value))) {
            return '';
        }

        $json = wp_json_encode($value);

        return is_string($json) ? $json : '';
    }

    /**
     * Decode a JSON column back to an array, tolerating '' and malformed data.
     *
     * @param string|null $value
     * @return array
     */
    public static function decode($value) {
        if (empty($value) || !is_string($value)) {
            return array();
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : array();
    }

    /**
     * True when the agent tables exist. Used by the admin screens to show a
     * repair notice instead of a fatal SQL error.
     */
    public static function tables_exist() {
        global $wpdb;

        $proposals = self::proposals_table();
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $proposals));

        return $found === $proposals;
    }

    /**
     * Delete agent rows older than the retention window.
     *
     * Proposals that were applied are kept regardless of age — they are the
     * rollback record. Only pending/rejected/expired noise is pruned.
     *
     * @param int $days
     * @return int Rows deleted.
     */
    public static function prune($days = 180) {
        global $wpdb;

        $days = max(7, (int) $days);
        $cutoff = gmdate('Y-m-d H:i:s', strtotime("-{$days} days", (int) current_time('timestamp', true)));

        $deleted = 0;

        $proposals = self::proposals_table();
        $deleted += (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$proposals}
             WHERE created_at < %s
               AND status IN ('rejected','expired','superseded','failed')",
            $cutoff
        ));

        $events = self::events_table();
        $deleted += (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$events} WHERE created_at < %s AND level = 'debug'",
            $cutoff
        ));

        $runs = self::runs_table();
        $deleted += (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$runs} WHERE created_at < %s AND status IN ('complete','failed','cancelled')",
            $cutoff
        ));

        return $deleted;
    }
}
