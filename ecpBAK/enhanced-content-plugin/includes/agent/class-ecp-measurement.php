<?php
/**
 * Scheduled follow-up on applied changes.
 *
 * Applying a change captures a baseline; this is the other half. Without it,
 * outcomes were only ever computed when somebody happened to open the right
 * screen — the plugin knew whether its changes worked and never said.
 *
 * Checkpoints follow the plan: roughly 7, 14, 28, 56 and 90 days after a
 * change goes live, its page's search performance is compared against the
 * baseline and the verdict is stored on the proposal. After the 90-day check
 * the verdict is final and the proposal is never measured again.
 *
 * All verdicts are correlational on purpose. "Improved since this change"
 * is a fact; "improved because of this change" is a claim nothing here can
 * support, and the wording never makes it.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ECP_Measurement {

    /**
     * Days after apply at which a check runs. The early ones catch
     * disasters; the late ones are the ones worth believing, because
     * rankings need weeks to settle after a content change.
     */
    const CHECKPOINTS = array(7, 14, 28, 56, 90);

    /** Most proposals measured in one run, so a big backlog cannot stall cron. */
    const BATCH = 100;

    /**
     * Run every due measurement. Called daily from the maintenance job.
     *
     * @return array { measured, improving, declining, finished }
     */
    public static function run() {
        $out = array('measured' => 0, 'improving' => 0, 'declining' => 0, 'finished' => 0);

        if (!ECP_Search_Data::is_connected() || !ECP_DB::tables_exist()) {
            return $out;
        }

        foreach (self::due_proposals() as $proposal) {
            $result = self::measure($proposal);

            if (!$result) {
                continue;
            }

            $out['measured']++;

            if ('improving' === $result['verdict']) {
                $out['improving']++;
            } elseif ('declining' === $result['verdict']) {
                $out['declining']++;
            }

            if (!empty($result['final'])) {
                $out['finished']++;
            }
        }

        return $out;
    }

    /**
     * Applied proposals with a baseline that have a checkpoint due.
     *
     * "Due" is decided in PHP rather than SQL because the evidence column is
     * JSON — the query narrows to applied-with-a-baseline and the loop does
     * the rest.
     *
     * @return array[]
     */
    private static function due_proposals() {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT id FROM ' . ECP_DB::proposals_table() . "
              WHERE status = %s
                AND applied_at IS NOT NULL
                AND evidence LIKE %s
                AND evidence NOT LIKE %s
              ORDER BY applied_at ASC
              LIMIT %d",
            ECP_Proposals::APPLIED,
            '%"baseline"%',
            '%"measurement_final"%',
            self::BATCH
        ), ARRAY_A);

        $due = array();

        foreach ((array) $rows as $row) {
            $proposal = ECP_Proposals::get((int) $row['id']);

            if ($proposal && null !== self::next_checkpoint($proposal)) {
                $due[] = $proposal;
            }
        }

        return $due;
    }

    /**
     * The next unrecorded checkpoint this proposal has reached, or null.
     *
     * @return int|null
     */
    public static function next_checkpoint(array $proposal) {
        if (empty($proposal['applied_at'])) {
            return null;
        }

        $age = (int) floor((time() - strtotime($proposal['applied_at'])) / DAY_IN_SECONDS);
        $evidence = is_array($proposal['evidence']) ? $proposal['evidence'] : array();
        $checks = isset($evidence['checks']) && is_array($evidence['checks']) ? $evidence['checks'] : array();
        $recorded = wp_list_pluck($checks, 'checkpoint');

        // Only the LATEST reached checkpoint is due, not every one that was
        // missed. If cron was down for a month, running the day-7 check on
        // day-40 data would record five copies of the same comparison.
        $reached = null;

        foreach (self::CHECKPOINTS as $day) {
            if ($age >= $day && !in_array($day, $recorded, true)) {
                $reached = $day;
            }
        }

        return $reached;
    }

    /**
     * Take one measurement and store it on the proposal.
     *
     * @return array|null { verdict, final } or null when nothing was measured.
     */
    public static function measure(array $proposal) {
        $checkpoint = self::next_checkpoint($proposal);

        if (null === $checkpoint) {
            return null;
        }

        $evidence = is_array($proposal['evidence']) ? $proposal['evidence'] : array();

        if (empty($evidence['baseline'])) {
            return null;
        }

        $comparison = ECP_Search_Data::compare_to_baseline($evidence['baseline'], (int) $proposal['post_id']);

        if (!$comparison) {
            // No current data for the page — usually a sync gap. Try again
            // next run rather than recording an empty check.
            return null;
        }

        $checks = isset($evidence['checks']) && is_array($evidence['checks']) ? $evidence['checks'] : array();

        $checks[] = array(
            'checkpoint'        => (int) $checkpoint,
            'measured_at'       => current_time('mysql'),
            'verdict'           => $comparison['verdict'],
            'clicks_delta'      => (int) $comparison['clicks_delta'],
            'impressions_delta' => (int) $comparison['impressions_delta'],
            'position_delta'    => (float) $comparison['position_delta'],
        );

        $evidence['checks'] = $checks;
        $evidence['latest_verdict'] = $comparison['verdict'];
        $evidence['latest_checkpoint'] = (int) $checkpoint;

        $final = (int) $checkpoint >= max(self::CHECKPOINTS);

        if ($final) {
            $evidence['measurement_final'] = true;
        }

        ECP_Proposals::update((int) $proposal['id'], array('evidence' => $evidence));

        // One log line per final verdict — not per checkpoint, which would
        // bury the activity feed in bookkeeping.
        if ($final && in_array($comparison['verdict'], array('improving', 'declining'), true)) {
            $message = 'improving' === $comparison['verdict']
                ? sprintf(
                    /* translators: 1: change type, 2: post title, 3: clicks gained */
                    __('%1$s on "%2$s" has correlated with improvement: +%3$d clicks per period after 90 days.', 'enhanced-content-plugin'),
                    ECP_Proposals::type_label($proposal['change_type']),
                    get_the_title((int) $proposal['post_id']),
                    (int) $comparison['clicks_delta']
                )
                : sprintf(
                    /* translators: 1: change type, 2: post title */
                    __('%1$s on "%2$s" has not helped — the page has declined since. Worth reviewing or undoing.', 'enhanced-content-plugin'),
                    ECP_Proposals::type_label($proposal['change_type']),
                    get_the_title((int) $proposal['post_id'])
                );

            ECP_Log::info('measurement.final', $message, array(
                'post_id'     => (int) $proposal['post_id'],
                'proposal_id' => (int) $proposal['id'],
            ));
        }

        return array('verdict' => $comparison['verdict'], 'final' => $final);
    }

    /**
     * Outcome totals across every measured change, for the dashboard.
     *
     * @return array|null Null when there is nothing measured yet.
     */
    public static function summary() {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return null;
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT id, evidence FROM ' . ECP_DB::proposals_table() . "
              WHERE status = %s AND evidence LIKE %s",
            ECP_Proposals::APPLIED,
            '%"latest_verdict"%'
        ), ARRAY_A);

        if (!$rows) {
            return null;
        }

        $summary = array(
            'measured'      => 0,
            'improving'     => 0,
            'stable'        => 0,
            'declining'     => 0,
            'too_early'     => 0,
            'clicks_gained' => 0,
            'clicks_lost'   => 0,
        );

        foreach ($rows as $row) {
            $evidence = ECP_DB::decode($row['evidence']);

            if (empty($evidence['latest_verdict'])) {
                continue;
            }

            $verdict = $evidence['latest_verdict'];
            $summary['measured']++;

            if (isset($summary[$verdict])) {
                $summary[$verdict]++;
            }

            $checks = isset($evidence['checks']) && is_array($evidence['checks']) ? $evidence['checks'] : array();
            $latest = $checks ? end($checks) : null;

            if ($latest && isset($latest['clicks_delta'])) {
                if ('improving' === $verdict) {
                    $summary['clicks_gained'] += max(0, (int) $latest['clicks_delta']);
                } elseif ('declining' === $verdict) {
                    $summary['clicks_lost'] += abs(min(0, (int) $latest['clicks_delta']));
                }
            }
        }

        return $summary['measured'] > 0 ? $summary : null;
    }

    /**
     * Awaiting-measurement count: applied, baselined, but no verdict yet.
     */
    public static function awaiting_count() {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return 0;
        }

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . ECP_DB::proposals_table() . "
              WHERE status = %s AND evidence LIKE %s AND evidence NOT LIKE %s",
            ECP_Proposals::APPLIED,
            '%"baseline"%',
            '%"latest_verdict"%'
        ));
    }
}
