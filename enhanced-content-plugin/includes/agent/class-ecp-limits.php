<?php
/**
 * The one gate every metered activity passes through.
 *
 * Today the numbers come from local settings. When this plugin becomes the
 * client of the RankAudit SaaS, plan entitlements arrive through the
 * `ecp_limits_source` filter and every caller keeps working unchanged —
 * a higher plan is a bigger number, not a refactor. That only stays true
 * if nothing ever checks a cap anywhere else, which is the rule: new
 * metered features ask this class, never a setting directly.
 *
 * Meters are counts per period. The monthly dollar budget stays where it
 * is (ECP_AI_Client computes it from the runs table); this class fronts it
 * so callers have a single API.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ECP_Limits {

    /**
     * The meter registry.
     *
     * `setting` is where the local limit lives; `period` drives the counter
     * key. Reserved meters for later phases are registered now so their
     * names are fixed — callers written today against 'briefs' or 'drafts'
     * will not need renaming when the features ship.
     *
     * @return array<string,array>
     */
    public static function meters() {
        $meters = array(
            'analyze' => array(
                'setting' => 'max_analyses_per_day',
                'default' => 10,
                'period'  => 'day',
                'label'   => __('AI analyses per day', 'enhanced-content-plugin'),
            ),
            'classify' => array(
                'setting' => 'classify_per_day',
                'default' => 100,
                'period'  => 'day',
                'label'   => __('Pages classified per day', 'enhanced-content-plugin'),
            ),
            'maps' => array(
                'setting' => 'maps_per_month',
                'default' => 10,
                'period'  => 'month',
                'label'   => __('Topical maps per month', 'enhanced-content-plugin'),
            ),
            // Reserved for later phases.
            'briefs' => array(
                'setting' => 'briefs_per_month',
                'default' => 0,
                'period'  => 'month',
                'label'   => __('Content briefs per month', 'enhanced-content-plugin'),
            ),
            'drafts' => array(
                'setting' => 'drafts_per_month',
                'default' => 0,
                'period'  => 'month',
                'label'   => __('Article drafts per month', 'enhanced-content-plugin'),
            ),
        );

        /**
         * Replace or adjust the limit sources.
         *
         * This is the SaaS seam: the licensing client filters in the
         * plan's entitlements here, overriding 'limit' per meter outright
         * by setting an explicit 'limit' key that wins over the setting.
         *
         * @param array $meters
         */
        return apply_filters('ecp_limits_source', $meters);
    }

    /**
     * The configured limit for a meter. 0 means unlimited locally — the
     * dollar budget is the true backstop.
     */
    public static function limit($meter) {
        $meters = self::meters();

        if (!isset($meters[$meter])) {
            return 0;
        }

        $config = $meters[$meter];

        if (isset($config['limit'])) {
            return (int) $config['limit'];   // Filter-supplied entitlement wins.
        }

        return (int) ECP_Agent_Settings::get($config['setting'], $config['default']);
    }

    /**
     * Used so far in the meter's current period.
     */
    public static function used($meter) {
        // The analyze meter predates this class and its counter lives in
        // ECP_AI_Client, incremented on every successful request. Fronted
        // rather than duplicated so there is exactly one count.
        if ('analyze' === $meter) {
            return ECP_AI_Client::daily_analyses();
        }

        return (int) get_transient(self::key($meter));
    }

    /**
     * May $count more units be spent?
     *
     * @return true|WP_Error
     */
    public static function can($meter, $count = 1) {
        $limit = self::limit($meter);

        if ($limit > 0 && self::used($meter) + max(1, (int) $count) > $limit) {
            $meters = self::meters();

            return new WP_Error('ecp_limit_' . $meter, sprintf(
                /* translators: 1: meter label, 2: limit */
                __('The limit for %1$s (%2$d) has been reached. It resets at the start of the next period.', 'enhanced-content-plugin'),
                isset($meters[$meter]['label']) ? $meters[$meter]['label'] : $meter,
                $limit
            ));
        }

        return true;
    }

    /**
     * Record spend against a meter.
     */
    public static function spend($meter, $count = 1) {
        if ('analyze' === $meter) {
            return;   // ECP_AI_Client::request() already counts these.
        }

        $key = self::key($meter);
        $meters = self::meters();
        $period = isset($meters[$meter]['period']) ? $meters[$meter]['period'] : 'day';

        set_transient(
            $key,
            (int) get_transient($key) + max(1, (int) $count),
            'month' === $period ? MONTH_IN_SECONDS : DAY_IN_SECONDS
        );
    }

    /**
     * Everything at once, for dashboards and diagnostics.
     *
     * @return array<string,array> meter => { label, limit, used, left }
     */
    public static function status() {
        $out = array();

        foreach (self::meters() as $meter => $config) {
            $limit = self::limit($meter);

            $out[$meter] = array(
                'label' => $config['label'],
                'limit' => $limit,
                'used'  => self::used($meter),
                'left'  => $limit > 0 ? max(0, $limit - self::used($meter)) : null,
            );
        }

        return $out;
    }

    private static function key($meter) {
        $meters = self::meters();
        $period = isset($meters[$meter]['period']) ? $meters[$meter]['period'] : 'day';
        $stamp = 'month' === $period
            ? date('Ym', (int) current_time('timestamp'))
            : date('Ymd', (int) current_time('timestamp'));

        return 'ecp_meter_' . $meter . '_' . $stamp;
    }
}
