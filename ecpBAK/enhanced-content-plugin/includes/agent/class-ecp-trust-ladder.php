<?php
/**
 * Earned autonomy, per change type.
 *
 * Nobody wants to approve their 200th alt-text change by hand, and nobody
 * should be asked to hand the agent their whole site on day one. This tracks
 * the approve/reject/revert record for each change type and offers to
 * auto-apply a type only once it has demonstrably earned it on *this* site.
 *
 * The offer is always opt-in. Passing the threshold surfaces a suggestion on
 * the dashboard; it never flips a switch on its own.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ECP_Trust_Ladder {

    const OPTION = 'ecp_trust_record';

    /** Decisions needed before a type can be considered at all. */
    const MIN_DECISIONS = 20;

    /** Approval rate required, as a fraction. */
    const MIN_APPROVAL_RATE = 0.9;

    /** Any rollback within this many recent decisions resets the clock. */
    const REVERT_LOOKBACK = 20;

    /**
     * The full record.
     *
     * @return array<string,array> type => { approved, rejected, reverted, since_revert }
     */
    public static function record() {
        $record = get_option(self::OPTION, array());

        return is_array($record) ? $record : array();
    }

    private static function entry($type) {
        $record = self::record();

        return isset($record[$type])
            ? wp_parse_args($record[$type], self::blank())
            : self::blank();
    }

    private static function blank() {
        return array('approved' => 0, 'rejected' => 0, 'reverted' => 0, 'since_revert' => 0);
    }

    /**
     * Note an approve or reject decision.
     */
    public static function record_decision($type, $approved) {
        $record = self::record();
        $entry = self::entry($type);

        if ($approved) {
            $entry['approved']++;
        } else {
            $entry['rejected']++;
        }

        $entry['since_revert']++;

        $record[$type] = $entry;
        update_option(self::OPTION, $record, false);
    }

    /**
     * Note a rollback, which is the loudest possible "not yet".
     *
     * Beyond resetting the counter, this switches the type back off for
     * autopilot immediately — a change that had to be undone is a change the
     * site owner should be seeing before it lands.
     */
    public static function record_revert($type) {
        $record = self::record();
        $entry = self::entry($type);

        $entry['reverted']++;
        $entry['since_revert'] = 0;

        $record[$type] = $entry;
        update_option(self::OPTION, $record, false);

        $auto = (array) ECP_Agent_Settings::get('auto_apply_types', array());

        if (in_array($type, $auto, true)) {
            ECP_Agent_Settings::update(array(
                'auto_apply_types' => array_values(array_diff($auto, array($type))),
            ));

            ECP_Log::warn(ECP_Log::SETTINGS_CHANGED, sprintf(
                /* translators: %s: change type label */
                __('Auto-apply switched off for %s after a change of that kind was rolled back.', 'enhanced-content-plugin'),
                ECP_Proposals::type_label($type)
            ));
        }
    }

    /**
     * Stats for one change type.
     *
     * @return array { approved, rejected, reverted, total, rate, eligible, auto_on }
     */
    public static function stats($type) {
        $entry = self::entry($type);
        $total = $entry['approved'] + $entry['rejected'];
        $rate = $total > 0 ? $entry['approved'] / $total : 0.0;

        $eligible = $total >= self::MIN_DECISIONS
            && $rate >= self::MIN_APPROVAL_RATE
            && $entry['since_revert'] >= self::REVERT_LOOKBACK;

        return array(
            'approved'     => (int) $entry['approved'],
            'rejected'     => (int) $entry['rejected'],
            'reverted'     => (int) $entry['reverted'],
            'total'        => $total,
            'rate'         => round($rate, 3),
            'eligible'     => $eligible,
            'auto_on'      => in_array($type, (array) ECP_Agent_Settings::get('auto_apply_types', array()), true),
            'since_revert' => (int) $entry['since_revert'],
        );
    }

    /**
     * Change types that have earned an autopilot offer but don't have it on.
     *
     * @return array<string,array>
     */
    public static function suggestions() {
        $out = array();

        foreach (array_keys(ECP_Proposals::change_types()) as $type) {
            $stats = self::stats($type);

            if ($stats['eligible'] && !$stats['auto_on']) {
                $out[$type] = $stats;
            }
        }

        return $out;
    }

    /**
     * Progress toward eligibility, for the settings screen.
     *
     * @return string
     */
    public static function progress_note($type) {
        $stats = self::stats($type);

        if ($stats['auto_on']) {
            return __('Auto-applying.', 'enhanced-content-plugin');
        }

        if (0 === $stats['total']) {
            return __('No decisions yet.', 'enhanced-content-plugin');
        }

        if ($stats['eligible']) {
            return __('Ready for auto-apply if you want it.', 'enhanced-content-plugin');
        }

        if ($stats['total'] < self::MIN_DECISIONS) {
            return sprintf(
                /* translators: 1: decisions made, 2: decisions required */
                __('%1$d of %2$d reviews needed before auto-apply can be offered.', 'enhanced-content-plugin'),
                $stats['total'],
                self::MIN_DECISIONS
            );
        }

        if ($stats['since_revert'] < self::REVERT_LOOKBACK) {
            return sprintf(
                /* translators: %d: number of clean reviews still needed */
                __('A change of this kind was rolled back recently; %d more clean reviews needed.', 'enhanced-content-plugin'),
                self::REVERT_LOOKBACK - $stats['since_revert']
            );
        }

        return sprintf(
            /* translators: 1: current approval rate, 2: required rate */
            __('Approval rate is %1$d%%; %2$d%% is needed.', 'enhanced-content-plugin'),
            (int) round($stats['rate'] * 100),
            (int) round(self::MIN_APPROVAL_RATE * 100)
        );
    }

    /* --------------------------------------------------------------------
     * Autopilot
     * ----------------------------------------------------------------- */

    /**
     * Apply a freshly created proposal if — and only if — the site has
     * explicitly opted this exact case in.
     *
     * Four independent gates have to agree. A 'sensitive' proposal is never
     * auto-applied regardless of settings, because that tier exists precisely
     * for the changes a human has to look at.
     *
     * @return bool Whether it was auto-applied.
     */
    public static function maybe_auto_apply($proposal_id) {
        $mode = (string) ECP_Agent_Settings::get('approval_mode', 'always');

        if ('always' === $mode) {
            return false;
        }

        $proposal = ECP_Proposals::get($proposal_id);
        if (!$proposal || ECP_Proposals::PENDING !== $proposal['status']) {
            return false;
        }

        if (ECP_Proposals::RISK_SENSITIVE === $proposal['risk']) {
            return false;
        }

        // Anything the guardrails flagged stays with a human, whatever the
        // risk tier says.
        $flags = is_array($proposal['flags']) ? $proposal['flags'] : array();
        if (!empty($flags['unverified_claims']) || !empty($flags['new_figures']) || !empty($flags['brand_terms_altered'])) {
            return false;
        }

        if ((int) $proposal['confidence'] < 85) {
            return false;
        }

        $allowed = false;

        if ('safe' === $mode) {
            $allowed = ECP_Proposals::RISK_SAFE === $proposal['risk'];
        } elseif ('trusted' === $mode) {
            $auto_types = (array) ECP_Agent_Settings::get('auto_apply_types', array());
            $allowed = in_array($proposal['change_type'], $auto_types, true)
                && self::stats($proposal['change_type'])['eligible'];
        }

        if (!$allowed) {
            return false;
        }

        $applied = ECP_Applier::apply($proposal_id, true);

        return !is_wp_error($applied);
    }
}
