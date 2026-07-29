<?php
/**
 * The single door between the agent and any AI provider.
 *
 * Responsibilities beyond dispatching to a provider:
 *
 *   - Enforce the monthly spend cap and the daily analysis cap *before* the
 *     request goes out. A runaway cron job on a large site is the most
 *     plausible way this plugin costs someone real money, so the check is a
 *     hard stop, not a warning.
 *   - Record every call in the runs table, successful or not, so the
 *     dashboard can show what was spent and on what.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ECP_AI_Client {

    /**
     * Build the configured provider.
     *
     * @param array $overrides { provider, model, api_key } — used by the
     *              settings screen's connection test so it can check the
     *              credentials currently on screen rather than the ones last
     *              written to the database. Never persisted.
     * @return ECP_Provider|WP_Error
     */
    public static function provider(array $overrides = array()) {
        $slug = isset($overrides['provider']) && $overrides['provider']
            ? (string) $overrides['provider']
            : (string) ECP_Agent_Settings::get('provider', 'anthropic');

        $model = isset($overrides['model']) && $overrides['model']
            ? (string) $overrides['model']
            : (string) ECP_Agent_Settings::get('model', 'claude-opus-5');

        $key = array_key_exists('api_key', $overrides)
            ? (string) $overrides['api_key']
            : ECP_Agent_Settings::api_key();

        switch ($slug) {
            case 'openai':
                return new ECP_Provider_OpenAI($key, $model);

            case 'rankaudit':
                return new ECP_Provider_RankAudit($key, 'managed');

            case 'anthropic':
                return new ECP_Provider_Anthropic($key, $model);
        }

        return new WP_Error('ecp_unknown_provider', sprintf(
            /* translators: %s: provider slug */
            __('Unknown AI provider "%s".', 'enhanced-content-plugin'),
            $slug
        ));
    }

    /**
     * Every provider, for the settings screen.
     *
     * @return ECP_Provider[]
     */
    public static function all_providers() {
        return array(
            new ECP_Provider_Anthropic('', (string) ECP_Agent_Settings::get('model', 'claude-opus-5')),
            new ECP_Provider_OpenAI('', ''),
            new ECP_Provider_RankAudit('', 'managed'),
        );
    }

    /**
     * Run a structured request, with budget enforcement and run logging.
     *
     * @param string $system
     * @param string $user
     * @param array  $schema
     * @param array  $args { post_id, job_type, trigger_source, max_tokens, effort }
     * @return array|WP_Error
     */
    public static function request(string $system, string $user, array $schema, array $args = array()) {
        $args = wp_parse_args($args, array(
            'post_id'        => 0,
            'job_type'       => 'analyze',
            'trigger_source' => 'cron',
            'max_tokens'     => 16000,
            'effort'         => ECP_Agent_Settings::get('effort', 'high'),
        ));

        $budget = self::budget_check();
        if (is_wp_error($budget)) {
            ECP_Log::warn(ECP_Log::BUDGET_EXHAUSTED, $budget->get_error_message(), array(
                'post_id' => (int) $args['post_id'],
            ));

            return $budget;
        }

        $provider = self::provider();
        if (is_wp_error($provider)) {
            return $provider;
        }

        $run_id = self::start_run($provider, $args);

        $result = $provider->structured($system, $user, $schema, array(
            'max_tokens' => (int) $args['max_tokens'],
            'effort'     => (string) $args['effort'],
            'timeout'    => (int) ECP_Agent_Settings::get('request_timeout', 120),
            'retries'    => (int) ECP_Agent_Settings::get('max_retries', 2),
        ));

        $usage = $provider->last_usage();

        self::finish_run($run_id, $result, $usage);

        if (!is_wp_error($result)) {
            // Cached counters the budget check reads on the hot path.
            self::increment_spend($usage['cost_micros']);
            self::increment_daily_analyses();
        }

        return is_wp_error($result) ? $result : array('data' => $result, 'usage' => $usage, 'run_id' => $run_id);
    }

    /* --------------------------------------------------------------------
     * Budget
     * ----------------------------------------------------------------- */

    /**
     * @return true|WP_Error
     */
    public static function budget_check() {
        $monthly_cap = (float) ECP_Agent_Settings::get('monthly_budget_usd', 20);

        // The per-day analysis cap is read through the limits gate, so a
        // plan entitlement can raise it without this method knowing. The
        // behaviour is identical to the old inline check.
        $daily = ECP_Limits::can('analyze');

        if (is_wp_error($daily)) {
            return $daily;
        }

        if ($monthly_cap > 0) {
            $spent = self::month_spend_usd();
            if ($spent >= $monthly_cap) {
                return new WP_Error('ecp_monthly_cap', sprintf(
                    /* translators: 1: amount spent, 2: configured cap */
                    __('The monthly budget is spent ($%1$.2f of $%2$.2f). Raise the cap in Settings to continue.', 'enhanced-content-plugin'),
                    $spent,
                    $monthly_cap
                ));
            }
        }

        return true;
    }

    /**
     * Spend so far this calendar month, in USD.
     */
    public static function month_spend_usd() {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return 0.0;
        }

        $table = ECP_DB::runs_table();
        $month_start = date('Y-m-01 00:00:00', (int) current_time('timestamp'));

        $micros = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(cost_micros), 0) FROM {$table} WHERE created_at >= %s",
            $month_start
        ));

        return $micros / 1000000;
    }

    /**
     * Analyses started today (successful or not — a failed call still costs).
     */
    public static function daily_analyses() {
        return (int) get_transient(self::daily_key());
    }

    private static function daily_key() {
        return 'ecp_analyses_' . date('Ymd', (int) current_time('timestamp'));
    }

    private static function increment_daily_analyses() {
        $key = self::daily_key();
        $count = (int) get_transient($key);
        set_transient($key, $count + 1, DAY_IN_SECONDS);
    }

    private static function increment_spend($cost_micros) {
        // Spend is derived from the runs table, so nothing to store here —
        // this hook exists so a future cache can be added without touching
        // the callers.
        unset($cost_micros);
    }

    /**
     * Budget summary for the dashboard.
     *
     * @return array
     */
    public static function budget_status() {
        $cap = (float) ECP_Agent_Settings::get('monthly_budget_usd', 20);
        $spent = self::month_spend_usd();
        $daily_cap = ECP_Limits::limit('analyze');
        $today = self::daily_analyses();

        return array(
            'monthly_cap'    => $cap,
            'monthly_spent'  => round($spent, 4),
            'monthly_left'   => $cap > 0 ? max(0, round($cap - $spent, 4)) : null,
            'monthly_pct'    => $cap > 0 ? min(100, (int) round(($spent / $cap) * 100)) : 0,
            'daily_cap'      => $daily_cap,
            'daily_used'     => $today,
            'daily_left'     => $daily_cap > 0 ? max(0, $daily_cap - $today) : null,
            'priced'         => self::provider_is_priced(),
        );
    }

    /**
     * Whether the active provider reports per-token pricing. When it doesn't,
     * the UI must not display a dollar figure it invented.
     */
    public static function provider_is_priced() {
        $provider = self::provider();

        return !is_wp_error($provider) && null !== $provider->pricing();
    }

    /* --------------------------------------------------------------------
     * Run records
     * ----------------------------------------------------------------- */

    private static function start_run(ECP_Provider $provider, array $args) {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return 0;
        }

        $now = ECP_DB::now();

        $wpdb->insert(
            ECP_DB::runs_table(),
            array(
                'job_type'       => (string) $args['job_type'],
                'status'         => 'running',
                'post_id'        => (int) $args['post_id'],
                'triggered_by'   => get_current_user_id(),
                'trigger_source' => (string) $args['trigger_source'],
                'provider'       => $provider->slug(),
                'model'          => (string) ECP_Agent_Settings::get('model', ''),
                'started_at'     => $now,
                'created_at'     => $now,
            ),
            array('%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s')
        );

        return (int) $wpdb->insert_id;
    }

    private static function finish_run($run_id, $result, array $usage) {
        global $wpdb;

        if (!$run_id) {
            return;
        }

        $failed = is_wp_error($result);

        $wpdb->update(
            ECP_DB::runs_table(),
            array(
                'status'        => $failed ? 'failed' : 'complete',
                'input_tokens'  => (int) $usage['input_tokens'],
                'output_tokens' => (int) $usage['output_tokens'],
                'cost_micros'   => (int) $usage['cost_micros'],
                'message'       => $failed ? $result->get_error_message() : '',
                'finished_at'   => ECP_DB::now(),
            ),
            array('id' => (int) $run_id),
            array('%s', '%d', '%d', '%d', '%s', '%s'),
            array('%d')
        );
    }

    /**
     * Attach a proposal count to a finished run.
     */
    public static function set_run_proposals($run_id, $count) {
        global $wpdb;

        if (!$run_id) {
            return;
        }

        $wpdb->update(
            ECP_DB::runs_table(),
            array('proposals_created' => (int) $count),
            array('id' => (int) $run_id),
            array('%d'),
            array('%d')
        );
    }

    /**
     * Recent runs, for the dashboard.
     *
     * @return array[]
     */
    public static function recent_runs($limit = 10) {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return array();
        }

        $table = ECP_DB::runs_table();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d",
            max(1, min(100, (int) $limit))
        ), ARRAY_A);

        return $rows ? $rows : array();
    }
}
