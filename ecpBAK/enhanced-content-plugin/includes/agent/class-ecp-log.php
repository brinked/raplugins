<?php
/**
 * Append-only audit trail.
 *
 * Every state change the agent makes goes through here. The History screen
 * reads this table, and it is the record you point at when a client asks
 * "what did the plugin change on my site and who approved it".
 */

if (!defined('ABSPATH')) {
    exit;
}

class ECP_Log {

    /* Event names used across the plugin. Kept as constants so a typo in
     * one place doesn't silently create a second event stream. */
    const SCAN_STARTED      = 'scan.started';
    const SCAN_COMPLETED    = 'scan.completed';
    const ANALYSIS_STARTED  = 'analysis.started';
    const ANALYSIS_FAILED   = 'analysis.failed';
    const ANALYSIS_COMPLETE = 'analysis.completed';
    const PROPOSAL_CREATED  = 'proposal.created';
    const PROPOSAL_APPROVED = 'proposal.approved';
    const PROPOSAL_REJECTED = 'proposal.rejected';
    const PROPOSAL_EDITED   = 'proposal.edited';
    const PROPOSAL_APPLIED  = 'proposal.applied';
    const PROPOSAL_REVERTED = 'proposal.reverted';
    const PROPOSAL_FAILED   = 'proposal.failed';
    const PROPOSAL_EXPIRED  = 'proposal.expired';
    const GUARDRAIL_BLOCKED = 'guardrail.blocked';
    const BUDGET_EXHAUSTED  = 'budget.exhausted';
    const AUTOPILOT_APPLIED = 'autopilot.applied';
    const SETTINGS_CHANGED  = 'settings.changed';

    /**
     * Write an event.
     *
     * @param string $event   One of the constants above.
     * @param array  $args    { message, level, post_id, proposal_id, run_id, context }
     * @return int Inserted row id, or 0 on failure.
     */
    public static function record($event, $args = array()) {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return 0;
        }

        $args = wp_parse_args($args, array(
            'message'     => '',
            'level'       => 'info',
            'post_id'     => 0,
            'proposal_id' => 0,
            'run_id'      => 0,
            'user_id'     => null,
            'context'     => array(),
        ));

        // get_current_user_id() returns 0 during cron, which is exactly what
        // we want to record — an unattended change has no reviewer.
        $user_id = null === $args['user_id'] ? get_current_user_id() : (int) $args['user_id'];

        $inserted = $wpdb->insert(
            ECP_DB::events_table(),
            array(
                'event'       => substr((string) $event, 0, 48),
                'level'       => substr((string) $args['level'], 0, 16),
                'post_id'     => (int) $args['post_id'],
                'proposal_id' => (int) $args['proposal_id'],
                'run_id'      => (int) $args['run_id'],
                'user_id'     => $user_id,
                'message'     => (string) $args['message'],
                'context'     => ECP_DB::encode($args['context']),
                'created_at'  => ECP_DB::now(),
            ),
            array('%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s')
        );

        return $inserted ? (int) $wpdb->insert_id : 0;
    }

    /**
     * Convenience wrappers.
     */
    public static function info($event, $message, $args = array()) {
        return self::record($event, array_merge($args, array('message' => $message, 'level' => 'info')));
    }

    public static function warn($event, $message, $args = array()) {
        return self::record($event, array_merge($args, array('message' => $message, 'level' => 'warning')));
    }

    public static function error($event, $message, $args = array()) {
        return self::record($event, array_merge($args, array('message' => $message, 'level' => 'error')));
    }

    /**
     * Query the trail.
     *
     * @param array $args { post_id, proposal_id, event, level, search, per_page, paged }
     * @return array { items: array, total: int }
     */
    public static function query($args = array()) {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return array('items' => array(), 'total' => 0);
        }

        $args = wp_parse_args($args, array(
            'post_id'     => 0,
            'proposal_id' => 0,
            'event'       => '',
            'level'       => '',
            'search'      => '',
            'per_page'    => 50,
            'paged'       => 1,
        ));

        $table = ECP_DB::events_table();
        $where = array('1=1');
        $params = array();

        if ($args['post_id']) {
            $where[] = 'post_id = %d';
            $params[] = (int) $args['post_id'];
        }
        if ($args['proposal_id']) {
            $where[] = 'proposal_id = %d';
            $params[] = (int) $args['proposal_id'];
        }
        if ($args['event']) {
            // Allow a prefix filter such as "proposal." for a whole family.
            if (substr($args['event'], -1) === '.') {
                $where[] = 'event LIKE %s';
                $params[] = $wpdb->esc_like($args['event']) . '%';
            } else {
                $where[] = 'event = %s';
                $params[] = $args['event'];
            }
        }
        if ($args['level']) {
            $where[] = 'level = %s';
            $params[] = $args['level'];
        }
        if ($args['search']) {
            $where[] = 'message LIKE %s';
            $params[] = '%' . $wpdb->esc_like($args['search']) . '%';
        }

        $where_sql = implode(' AND ', $where);

        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
        $total = (int) ($params
            ? $wpdb->get_var($wpdb->prepare($count_sql, $params))
            : $wpdb->get_var($count_sql));

        $per_page = max(1, min(200, (int) $args['per_page']));
        $offset = max(0, ((int) $args['paged'] - 1)) * $per_page;

        $rows_sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
        $rows = $wpdb->get_results(
            $wpdb->prepare($rows_sql, array_merge($params, array($per_page, $offset))),
            ARRAY_A
        );

        foreach ($rows as &$row) {
            $row['context'] = ECP_DB::decode($row['context']);
        }
        unset($row);

        return array('items' => $rows ? $rows : array(), 'total' => $total);
    }

    /**
     * Human-readable label for an event name.
     */
    public static function label($event) {
        $labels = array(
            self::SCAN_STARTED      => __('Scan started', 'enhanced-content-plugin'),
            self::SCAN_COMPLETED    => __('Scan completed', 'enhanced-content-plugin'),
            self::ANALYSIS_STARTED  => __('Analysis started', 'enhanced-content-plugin'),
            self::ANALYSIS_FAILED   => __('Analysis failed', 'enhanced-content-plugin'),
            self::ANALYSIS_COMPLETE => __('Analysis completed', 'enhanced-content-plugin'),
            self::PROPOSAL_CREATED  => __('Change proposed', 'enhanced-content-plugin'),
            self::PROPOSAL_APPROVED => __('Change approved', 'enhanced-content-plugin'),
            self::PROPOSAL_REJECTED => __('Change rejected', 'enhanced-content-plugin'),
            self::PROPOSAL_EDITED   => __('Change edited before approval', 'enhanced-content-plugin'),
            self::PROPOSAL_APPLIED  => __('Change applied', 'enhanced-content-plugin'),
            self::PROPOSAL_REVERTED => __('Change rolled back', 'enhanced-content-plugin'),
            self::PROPOSAL_FAILED   => __('Change failed to apply', 'enhanced-content-plugin'),
            self::PROPOSAL_EXPIRED  => __('Change expired', 'enhanced-content-plugin'),
            self::GUARDRAIL_BLOCKED => __('Blocked by guardrail', 'enhanced-content-plugin'),
            self::BUDGET_EXHAUSTED  => __('Budget exhausted', 'enhanced-content-plugin'),
            self::AUTOPILOT_APPLIED => __('Auto-applied', 'enhanced-content-plugin'),
            self::SETTINGS_CHANGED  => __('Settings changed', 'enhanced-content-plugin'),
        );

        return isset($labels[$event]) ? $labels[$event] : $event;
    }
}
