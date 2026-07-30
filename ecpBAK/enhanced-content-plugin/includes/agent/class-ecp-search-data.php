<?php
/**
 * Search Console data.
 *
 * The agent is useful without it — the on-page signals alone find thin
 * content, orphan pages and missing metadata. But query data is what turns
 * "this page is thin" into "this page ranks #12 for a term with 4,000 monthly
 * impressions and a 0.4% CTR", and it is the only way to measure whether a
 * published change actually helped.
 *
 * Three sources, tried in this order under the 'auto' setting:
 *
 *   sitekit  Read through Google Site Kit if it is installed and connected.
 *            No OAuth app of your own to register — Site Kit already holds
 *            the credentials.
 *   csv      Manual upload of the Pages and Queries exports from the Search
 *            Console UI. Works on any site, needs no Google project.
 *   none     On-page signals only.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ECP_Search_Data {

    /**
     * Which source is actually usable right now.
     *
     * @return string 'sitekit' | 'csv' | 'none'
     */
    public static function active_source() {
        $configured = ECP_Agent_Settings::get('search_source', 'auto');

        if ('none' === $configured) {
            return 'none';
        }

        if ('sitekit' === $configured) {
            return self::site_kit_available() ? 'sitekit' : 'none';
        }

        if ('csv' === $configured) {
            return self::has_imported_data() ? 'csv' : 'none';
        }

        // auto
        if (self::site_kit_available()) {
            return 'sitekit';
        }

        return self::has_imported_data() ? 'csv' : 'none';
    }

    public static function is_connected() {
        return 'none' !== self::active_source();
    }

    /**
     * Human-readable status for the settings and dashboard screens.
     *
     * @return array { source, label, detail, connected }
     */
    public static function status() {
        $source = self::active_source();

        switch ($source) {
            case 'sitekit':
                $synced = get_option('ecp_metrics_synced_at', '');
                $rows = self::stored_row_count();

                if ($synced) {
                    $detail = sprintf(
                        /* translators: 1: human-readable time difference, 2: number of stored rows */
                        __('Reading Search Console through Site Kit. Last synced %1$s ago; %2$s rows stored.', 'enhanced-content-plugin'),
                        human_time_diff(strtotime($synced), (int) current_time('timestamp')),
                        number_format_i18n($rows)
                    );
                } else {
                    $detail = __('Connected through Site Kit, but no data has been pulled yet. Sync now, or wait for tonight\'s scheduled run.', 'enhanced-content-plugin');
                }

                return array(
                    'source'    => 'sitekit',
                    'connected' => true,
                    'label'     => __('Google Site Kit', 'enhanced-content-plugin'),
                    'detail'    => $detail,
                    'synced_at' => $synced,
                    'rows'      => $rows,
                );

            case 'csv':
                $last = get_option('ecp_csv_import_meta', array());
                return array(
                    'source'    => 'csv',
                    'connected' => true,
                    'label'     => __('Imported CSV', 'enhanced-content-plugin'),
                    'detail'    => empty($last['imported_at'])
                        ? __('Search Console export in use.', 'enhanced-content-plugin')
                        : sprintf(
                            /* translators: 1: number of rows, 2: human-readable time difference */
                            __('%1$s rows imported %2$s ago.', 'enhanced-content-plugin'),
                            number_format_i18n((int) $last['rows']),
                            human_time_diff(strtotime($last['imported_at']))
                        ),
                );
        }

        return array(
            'source'    => 'none',
            'connected' => false,
            'label'     => __('Not connected', 'enhanced-content-plugin'),
            'detail'    => __('The agent will work from on-page signals only and cannot measure results after publishing.', 'enhanced-content-plugin'),
        );
    }

    /* --------------------------------------------------------------------
     * Site Kit
     * ----------------------------------------------------------------- */

    /**
     * Site Kit installed, active, and its Search Console module connected.
     */
    /**
     * Is the Site Kit plugin present and active?
     *
     * The version constant is the only stable, documented signal Site Kit
     * offers. Class names and internal structure have both moved between
     * releases; the constant has not.
     */
    public static function site_kit_installed() {
        return defined('GOOGLESITEKIT_VERSION') || class_exists('\Google\Site_Kit\Plugin');
    }

    /**
     * Whether we should try to read Search Console through Site Kit.
     *
     * Deliberately permissive: if Site Kit is active, we assume it is usable
     * and let an actual request report the real problem. The alternative —
     * trying to introspect Site Kit's connection state up front — is what
     * broke this integration in the first place, because a wrong guess about
     * its internals is indistinguishable from "not installed" and silently
     * disables the whole feature.
     */
    public static function site_kit_available() {
        return self::site_kit_installed();
    }

    /**
     * The user whose Google credentials Site Kit should use.
     *
     * Site Kit keeps OAuth tokens in *user* meta, not site options. During
     * WP-Cron there is no logged-in user, so a token lookup finds nothing and
     * the request fails — quietly, because that failure looks identical to
     * "Site Kit isn't connected".
     *
     * @return int 0 when a real user is driving, or no owner can be found.
     */
    private static function site_kit_owner_id() {
        if (get_current_user_id()) {
            return 0;   // Someone is logged in; use their own connection.
        }

        return self::site_kit_stored_owner_id();
    }

    /**
     * Which account holds the Google connection, regardless of who is looking.
     *
     * Kept separate from site_kit_owner_id() on purpose. That one answers
     * "whose identity should this request borrow", and correctly returns 0
     * when somebody is logged in — their own token is already in play. The
     * diagnostics screen needs a different question answered: "will the
     * nightly run, with nobody logged in, find an account?" Asking the first
     * question there meant an administrator reading the page always saw
     * "could not identify which user holds the Google connection", because
     * they were logged in. The check reported a problem it had created.
     *
     * @return int 0 when no account can be found.
     */
    public static function site_kit_stored_owner_id() {
        // An explicit choice always wins, and is the escape hatch when
        // detection cannot work — a token held by a user who has since been
        // deleted, an unusual multisite layout, a hosting setup that hides
        // user meta from the query below.
        $chosen = (int) get_option('ecp_sitekit_user', 0);

        if ($chosen && self::user_has_google_token($chosen)) {
            return $chosen;
        }

        // Site Kit records an owner on each module's settings option.
        $settings = get_option('googlesitekit_search-console_settings', array());

        if (is_array($settings) && !empty($settings['ownerID']) && self::user_has_google_token((int) $settings['ownerID'])) {
            return (int) $settings['ownerID'];
        }

        $holders = self::google_token_holders();

        return $holders ? (int) $holders[0] : 0;
    }

    /**
     * Every user who holds a Site Kit Google token.
     *
     * Matched with LIKE rather than an exact meta_key. Site Kit stores user
     * options through its own layer, which prefixes the key with the blog ID
     * on anything other than the main site — so the exact key this used to
     * look for simply does not exist on a subsite, and detection failed there
     * with no way to tell why.
     *
     * @return int[]
     */
    public static function google_token_holders() {
        global $wpdb;

        $ids = $wpdb->get_col(
            "SELECT DISTINCT user_id FROM {$wpdb->usermeta}
              WHERE meta_key LIKE '%googlesitekit%access_token'
                AND meta_value != ''
              ORDER BY user_id ASC"  // phpcs:ignore WordPress.DB.PreparedSQL
        );

        return array_map('intval', (array) $ids);
    }

    private static function user_has_google_token($user_id) {
        return in_array((int) $user_id, self::google_token_holders(), true);
    }

    /**
     * Pull search analytics rows from Site Kit.
     *
     * Goes through Site Kit's own REST route rather than its PHP classes.
     * That route is the surface Site Kit's dashboard uses, it is versioned,
     * and it handles module resolution, OAuth and token refresh internally —
     * none of which is safe to reimplement against private internals.
     *
     * rest_do_request() dispatches in-process, so there is no HTTP round trip
     * and no separate authentication to arrange.
     *
     * @param array $args { start_date, end_date, dimensions, url, limit }
     * @return array[]|WP_Error Raw rows: { keys: [...], clicks, impressions, ctr, position }
     */
    private static function site_kit_query(array $args) {
        if (!self::site_kit_installed()) {
            return new WP_Error(
                'ecp_sitekit_missing',
                __('Google Site Kit is not active on this site.', 'enhanced-content-plugin')
            );
        }

        $params = array(
            'slug'       => 'search-console',
            'datapoint'  => 'searchanalytics',
            'startDate'  => $args['start_date'],
            'endDate'    => $args['end_date'],
            'dimensions' => (array) $args['dimensions'],
            'limit'      => (int) $args['limit'],
        );

        if (!empty($args['url'])) {
            $params['url'] = $args['url'];
        }

        // Borrow the owner's identity for unattended runs, and hand it back
        // afterwards whatever happens.
        $previous_user = get_current_user_id();
        $owner_id = self::site_kit_owner_id();

        if ($owner_id) {
            wp_set_current_user($owner_id);
        }

        $rows = self::site_kit_rest_request($params);

        if ($owner_id) {
            wp_set_current_user($previous_user);
        }

        if (is_wp_error($rows)) {
            return $rows;
        }

        $response = $rows;

        $rows = array();

        foreach ((array) $response as $row) {
            // Site Kit returns Google API model objects, not arrays.
            $keys = is_object($row) && method_exists($row, 'getKeys') ? (array) $row->getKeys() : (isset($row['keys']) ? (array) $row['keys'] : array());

            $rows[] = array(
                'keys'        => $keys,
                'clicks'      => (int) self::row_value($row, 'clicks'),
                'impressions' => (int) self::row_value($row, 'impressions'),
                'ctr'         => (float) self::row_value($row, 'ctr'),
                'position'    => (float) self::row_value($row, 'position'),
            );
        }

        return $rows;
    }

    /**
     * Dispatch one request to Site Kit's REST route.
     *
     * @param array $params
     * @return array|WP_Error
     */
    private static function site_kit_rest_request(array $params) {
        if (!function_exists('rest_do_request')) {
            return new WP_Error('ecp_no_rest', __('The WordPress REST API is unavailable on this site.', 'enhanced-content-plugin'));
        }

        $route = '/google-site-kit/v1/modules/search-console/data/searchanalytics';

        $request = new WP_REST_Request('GET', $route);
        $request->set_query_params($params);

        $response = rest_do_request($request);

        if (!$response instanceof WP_REST_Response) {
            return new WP_Error('ecp_sitekit_no_response', __('Site Kit did not return a response.', 'enhanced-content-plugin'));
        }

        if ($response->is_error()) {
            $error = $response->as_error();

            // 404 means the route is not registered, which means Site Kit is
            // active but not set up — a different problem to a failed query,
            // and worth saying so plainly.
            if (404 === (int) $response->get_status()) {
                return new WP_Error(
                    'ecp_sitekit_not_setup',
                    __('Site Kit is active but its Search Console connection is not set up. Open Site Kit and complete the sign-in with the Google account that owns your Search Console property.', 'enhanced-content-plugin')
                );
            }

            if (in_array((int) $response->get_status(), array(401, 403), true)) {
                return new WP_Error(
                    'ecp_sitekit_forbidden',
                    __('Site Kit refused the request. The account it is connected with may not have access to the Search Console property, or the connection needs re-authorising in Site Kit.', 'enhanced-content-plugin')
                );
            }

            return new WP_Error('ecp_sitekit_error', $error->get_error_message());
        }

        $data = rest_get_server()->response_to_data($response, false);

        if (!is_array($data)) {
            return new WP_Error('ecp_sitekit_shape', __('Site Kit returned data in an unexpected shape.', 'enhanced-content-plugin'));
        }

        return $data;
    }

    /**
     * Human-readable checks for the settings screen.
     *
     * Every previous failure in this integration has been invisible — the UI
     * said "not connected" whether the plugin was missing, unauthenticated,
     * or working fine but returning nothing. This makes the difference
     * legible without needing the logs.
     *
     * @return array[] { label, ok, detail }
     */
    public static function site_kit_diagnostics() {
        $checks = array();

        $installed = self::site_kit_installed();

        $checks[] = array(
            'label'  => __('Site Kit plugin', 'enhanced-content-plugin'),
            'ok'     => $installed,
            'detail' => $installed
                ? sprintf(
                    /* translators: %s: version number */
                    __('Active, version %s.', 'enhanced-content-plugin'),
                    defined('GOOGLESITEKIT_VERSION') ? GOOGLESITEKIT_VERSION : __('unknown', 'enhanced-content-plugin')
                )
                : __('Not found. Install and activate "Site Kit by Google".', 'enhanced-content-plugin'),
            'action' => $installed ? null : array(
                'type'  => 'link',
                'label' => __('Install Site Kit', 'enhanced-content-plugin'),
                'href'  => admin_url('plugin-install.php?s=Site+Kit+by+Google&tab=search&type=term'),
            ),
        );

        if (!$installed) {
            return $checks;
        }

        // Does the REST route exist? That is the real "has it been set up"
        // signal, and it costs nothing to check.
        $routes = function_exists('rest_get_server') ? rest_get_server()->get_routes() : array();
        $has_route = isset($routes['/google-site-kit/v1/modules/(?P<slug>[a-z0-9\-]+)/data/(?P<datapoint>[a-z\-]+)'])
            || (bool) preg_grep('#^/google-site-kit/v1/modules#', array_keys($routes));

        $checks[] = array(
            'label'  => __('Site Kit REST connection', 'enhanced-content-plugin'),
            'ok'     => $has_route,
            'detail' => $has_route
                ? __('Available.', 'enhanced-content-plugin')
                : __('Site Kit has not registered its data routes — finish its setup wizard.', 'enhanced-content-plugin'),
            'action' => $has_route ? null : array(
                'type'  => 'link',
                'label' => __('Finish Site Kit setup', 'enhanced-content-plugin'),
                'href'  => admin_url('admin.php?page=googlesitekit-splash'),
            ),
        );

        $owner = self::site_kit_stored_owner_id();
        $owner_user = $owner ? get_userdata($owner) : null;
        $holders = self::google_token_holders();

        $checks[] = array(
            'label'  => __('Account used for scheduled syncs', 'enhanced-content-plugin'),
            'ok'     => (bool) $owner_user,
            'detail' => $owner_user
                ? sprintf(
                    /* translators: %s: user display name */
                    __('%s — the nightly sync runs as this user, because Site Kit stores its Google token per user.', 'enhanced-content-plugin'),
                    $owner_user->display_name
                )
                : ($holders
                    ? __('More than one account holds a Google connection and none is marked as the owner. Pick one below so the nightly sync knows whose to use.', 'enhanced-content-plugin')
                    : __('No account on this site holds a Google token. Sign in to Site Kit with the Google account that owns your Search Console property. Manual syncs run as you and will still work; the nightly one will not.', 'enhanced-content-plugin')),
            'action' => $holders
                ? array('type' => 'picker')
                : array('type' => 'link', 'label' => __('Open Site Kit', 'enhanced-content-plugin'), 'href' => admin_url('admin.php?page=googlesitekit-settings')),
        );

        $rows = self::stored_row_count();

        $checks[] = array(
            'label'  => __('Data pulled so far', 'enhanced-content-plugin'),
            'ok'     => $rows > 0,
            'detail' => $rows > 0
                ? sprintf(
                    /* translators: 1: row count, 2: number of pages */
                    __('%1$s rows across %2$s pages.', 'enhanced-content-plugin'),
                    number_format_i18n($rows),
                    number_format_i18n(self::covered_post_count())
                )
                : __('Nothing yet.', 'enhanced-content-plugin'),
            'action' => $rows > 0 ? null : array(
                'type'  => 'button',
                'action' => 'sync_search',
                'label' => __('Sync now', 'enhanced-content-plugin'),
            ),
        );

        // Per window, because a total row count hides the failure where one
        // window has everything and the others have nothing.
        $per_window = self::rows_per_window();
        $known = array_keys(self::windows());

        $empty = array();
        $stranded = 0;
        $parts = array();

        foreach ($per_window as $days => $count) {
            if (in_array((int) $days, $known, true)) {
                $parts[] = sprintf('%s: %s', self::window_label($days), number_format_i18n($count));

                if (0 === $count) {
                    $empty[] = $days;
                }

                continue;
            }

            // A period the UI cannot display. These rows are real data that no
            // screen will ever read, so they need naming rather than hiding.
            $stranded += $count;
            $parts[] = sprintf(
                /* translators: 1: window_days value, 2: row count */
                __('unrecognised period "%1$s": %2$s', 'enhanced-content-plugin'),
                (int) $days,
                number_format_i18n($count)
            );
        }

        $note = '';
        $action = null;

        if ($stranded > 0) {
            $note = '  ' . sprintf(
                /* translators: %s: row count */
                __('%s rows are stamped with a period no screen can show. Repair discards them and fetches the data again.', 'enhanced-content-plugin'),
                number_format_i18n($stranded)
            );
            $action = array('type' => 'button', 'action' => 'repair_search', 'label' => __('Repair', 'enhanced-content-plugin'));
        } elseif ($empty) {
            // Deliberately not asserting a cause. The honest statement is that
            // these periods have never been fetched on the current schema —
            // which is true whether the rows predate an upgrade or a sync was
            // interrupted, and is fixed the same way either way.
            $note = '  ' . __('These periods have no rows yet. One sync fills all three.', 'enhanced-content-plugin');
            $action = array('type' => 'button', 'action' => 'sync_search', 'label' => __('Sync now', 'enhanced-content-plugin'));
        }

        $checks[] = array(
            'label'  => __('Each reporting period', 'enhanced-content-plugin'),
            'ok'     => !$empty && !$stranded,
            'detail' => implode(' · ', $parts) . $note,
            'action' => $action,
        );

        // The shape of the table itself, since a half-applied migration
        // presents exactly like "Google returned nothing".
        $schema = ECP_DB::metrics_schema_status();

        if (!$schema['ok']) {
            $problems = array();

            if (!$schema['window_column']) {
                $problems[] = __('the reporting-period column is missing', 'enhanced-content-plugin');
            }
            if (!$schema['legacy_index_gone']) {
                $problems[] = __('an old index is still present, which makes each period overwrite the last', 'enhanced-content-plugin');
            }
            if (!$schema['unique_index']) {
                $problems[] = __('the current unique index is missing, which allows duplicate rows', 'enhanced-content-plugin');
            }

            $checks[] = array(
                'label'  => __('Metrics table', 'enhanced-content-plugin'),
                'ok'     => false,
                'detail' => sprintf(
                    /* translators: %s: list of schema problems */
                    __('Needs repair — %s.', 'enhanced-content-plugin'),
                    implode('; ', $problems)
                ),
                'action' => array(
                    'type'  => 'button',
                    'action' => 'repair_search',
                    'label' => __('Repair now', 'enhanced-content-plugin'),
                ),
            );
        }

        return $checks;
    }

    /**
     * Make one small request as the account scheduled syncs will use.
     *
     * The point is to fail here, in front of somebody who can act on it,
     * rather than at 3am in a cron run nobody watches.
     *
     * @return true|WP_Error
     */
    public static function test_owner_connection() {
        $owner = self::site_kit_stored_owner_id();

        if (!$owner) {
            return new WP_Error('ecp_no_owner', __('No account is set for scheduled syncs.', 'enhanced-content-plugin'));
        }

        $end = gmdate('Y-m-d', strtotime('-2 days'));

        // Switch identity explicitly. site_kit_query() would otherwise defer
        // to whoever is logged in, which is exactly the account not under test.
        $previous = get_current_user_id();
        wp_set_current_user($owner);

        $rows = self::site_kit_rest_request(array(
            'slug'       => 'search-console',
            'datapoint'  => 'searchanalytics',
            'startDate'  => gmdate('Y-m-d', strtotime('-7 days', strtotime($end))),
            'endDate'    => $end,
            'dimensions' => array('page'),
            'limit'      => 1,
        ));

        wp_set_current_user($previous);

        return is_wp_error($rows) ? $rows : true;
    }

    /**
     * Exactly what is in the metrics table, ungrouped and uninterpreted.
     *
     * Every summary view of this data has, at some point, hidden the failure
     * it was meant to expose. This one does no interpreting: one line per
     * period and date actually present, with the raw counts.
     *
     * @return array<int,array> { window_days, metric_date, rows, pages, queries, readable }
     */
    public static function stored_breakdown() {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return array();
        }

        $rows = $wpdb->get_results(
            "SELECT window_days, metric_date,
                    COUNT(*) AS rows_total,
                    COUNT(DISTINCT post_id) AS pages,
                    SUM(CASE WHEN query != '' THEN 1 ELSE 0 END) AS queries
               FROM " . ECP_DB::metrics_table() . '
              GROUP BY window_days, metric_date
              ORDER BY metric_date DESC, window_days ASC',  // phpcs:ignore WordPress.DB.PreparedSQL
            ARRAY_A
        );

        $known = array_map('intval', array_keys(self::windows()));
        $out = array();

        foreach ((array) $rows as $row) {
            $out[] = array(
                'window_days' => (int) $row['window_days'],
                'metric_date' => (string) $row['metric_date'],
                'rows'        => (int) $row['rows_total'],
                'pages'       => (int) $row['pages'],
                'queries'     => (int) $row['queries'],
                'readable'    => in_array((int) $row['window_days'], $known, true),
            );
        }

        return $out;
    }

    /**
     * Delete rows stamped with a reporting period the UI cannot display, then
     * refetch everything.
     *
     * Restamping would be guesswork — nothing in the row records which window
     * it was collected for once that value is wrong. A sync costs six API
     * calls and a few seconds, so discarding unreadable rows and asking Google
     * again is both cheaper and honest.
     *
     * @return array|WP_Error { removed, synced }
     */
    public static function repair_windows() {
        global $wpdb;

        // Re-run the schema migration first. The usual reason a period cannot
        // be read is not the rows but the table: a column the queries select
        // on is missing, every one of them errors, and the screens report no
        // data rather than a failure.
        ECP_DB::install();

        if (!ECP_DB::tables_exist()) {
            return new WP_Error('ecp_no_tables', __('The agent tables are missing and could not be created. Check that the database user is allowed to CREATE TABLE.', 'enhanced-content-plugin'));
        }

        $schema = ECP_DB::metrics_schema_status();

        if (empty($schema['ok'])) {
            return new WP_Error('ecp_schema_failed', __('The metrics table could not be repaired. The database user may not have ALTER permission — ask your host to grant it, then try again.', 'enhanced-content-plugin'));
        }

        update_option('ecp_db_version', ECP_DB::SCHEMA_VERSION, false);

        $known = array_map('intval', array_keys(self::windows()));
        $placeholders = implode(',', array_fill(0, count($known), '%d'));

        $removed = (int) $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM ' . ECP_DB::metrics_table() . " WHERE window_days NOT IN ({$placeholders})",  // phpcs:ignore WordPress.DB.PreparedSQL
                $known
            )
        );

        $synced = self::sync_all();

        if (is_wp_error($synced)) {
            return $synced;
        }

        return array('removed' => $removed, 'synced' => $synced);
    }

    /**
     * Stored row count per reporting period.
     *
     * Every distinct window_days value in the table is reported, not just the
     * three the UI offers. An earlier version of this method counted only the
     * known windows, which meant rows stamped with anything else — 0 from a
     * column added by dbDelta with the wrong default, or a window that has
     * since been retired — vanished from the diagnostics entirely. The screen
     * then showed "7: 0 · 28: 0 · 90: 0" directly underneath "1,341 rows
     * stored" and gave no hint where the difference had gone.
     *
     * @return array<int,int> window_days => row count, known windows first.
     */
    public static function rows_per_window() {
        global $wpdb;

        $counts = array_fill_keys(array_keys(self::windows()), 0);

        if (!ECP_DB::tables_exist()) {
            return $counts;
        }

        $rows = $wpdb->get_results(
            'SELECT window_days, COUNT(*) AS total FROM ' . ECP_DB::metrics_table() . ' GROUP BY window_days ORDER BY window_days ASC',
            ARRAY_A
        );

        foreach ((array) $rows as $row) {
            $counts[(int) $row['window_days']] = (int) $row['total'];
        }

        return $counts;
    }

    private static function row_value($row, $field) {
        if (is_array($row)) {
            return isset($row[$field]) ? $row[$field] : 0;
        }

        $getter = 'get' . ucfirst($field);

        return (is_object($row) && method_exists($row, $getter)) ? $row->$getter() : 0;
    }

    /* --------------------------------------------------------------------
     * Sync
     * ----------------------------------------------------------------- */

    /**
     * Refresh stored metrics from the live source.
     *
     * Stores one page-total row and up to $query_limit per-query rows per
     * post, dated to the end of the window. We do not store a row per day —
     * that is a lot of rows for a self-hosted site, and every question the
     * agent asks is about a window, not a day.
     *
     * @param int $days
     * @return array|WP_Error { posts: int, rows: int }
     */
    /**
     * The reporting windows kept in sync.
     *
     * Search Console has no useful "last 24 hours" through this route:
     * its API data lags roughly two days, and a single day of position
     * figures is too noisy to act on anyway. The hourly view in the Search
     * Console UI comes from a different dataset that Site Kit does not
     * expose. Seven days is the shortest window worth trusting.
     *
     * @return array<int,string> days => label
     */
    public static function windows() {
        return array(
            7  => __('Last 7 days', 'enhanced-content-plugin'),
            28 => __('Last 28 days', 'enhanced-content-plugin'),
            90 => __('Last 3 months', 'enhanced-content-plugin'),
        );
    }

    /**
     * The window everything defaults to when none is named — scoring,
     * cluster detection, the opportunity queue.
     */
    const DEFAULT_WINDOW = 28;

    public static function window_label($days) {
        $windows = self::windows();

        return isset($windows[(int) $days]) ? $windows[(int) $days] : sprintf(
            /* translators: %d: number of days */
            __('Last %d days', 'enhanced-content-plugin'),
            (int) $days
        );
    }

    /**
     * Validate a window from user input.
     */
    public static function valid_window($days) {
        $days = (int) $days;

        return array_key_exists($days, self::windows()) ? $days : self::DEFAULT_WINDOW;
    }

    /**
     * Refresh every window.
     *
     * @return array|WP_Error { posts, rows, windows, truncated }
     */
    public static function sync_all($query_limit = 5000) {
        $posts = 0;
        $rows = 0;
        $done = array();
        $truncated = array();
        $last_error = null;

        $per_window = array();

        foreach (array_keys(self::windows()) as $days) {
            $result = self::sync($days, $query_limit);

            if (is_wp_error($result)) {
                $last_error = $result;

                // Recorded per window rather than collapsed into one error.
                // Swallowing these is why a run that fetched only one of the
                // three periods still reported plain success, and the two
                // empty periods looked like a display problem.
                $per_window[$days] = array('rows' => 0, 'error' => $result->get_error_message());

                ECP_Log::warn('metrics.window_failed', sprintf(
                    /* translators: 1: period label, 2: error message */
                    __('Could not fetch %1$s: %2$s', 'enhanced-content-plugin'),
                    self::window_label($days),
                    $result->get_error_message()
                ));

                continue;
            }

            $posts = max($posts, (int) $result['posts']);
            $rows += (int) $result['rows'];
            $done[] = $days;
            $per_window[$days] = array('rows' => (int) $result['rows'], 'error' => '');

            // A window that returns no rows is not an error, but it is not a
            // success either, and it is the exact symptom being chased when a
            // period stays empty.
            if (0 === (int) $result['rows']) {
                ECP_Log::warn('metrics.window_empty', sprintf(
                    /* translators: %s: period label */
                    __('%s synced without error but Google returned no rows for it.', 'enhanced-content-plugin'),
                    self::window_label($days)
                ));
            }

            if (!empty($result['truncated'])) {
                $truncated[] = $days;
            }
        }

        if (!$done && $last_error) {
            return $last_error;
        }

        update_option('ecp_metrics_synced_at', current_time('mysql'), false);

        return array(
            'posts'      => $posts,
            'rows'       => $rows,
            'windows'    => $done,
            'truncated'  => $truncated,
            'per_window' => $per_window,
        );
    }

    /**
     * Refresh one reporting window.
     *
     * Two API calls, not one per page. The previous version asked Google for
     * the query breakdown of every page separately, which on a 200-page site
     * meant 201 requests and a sync slow enough to time out in a browser.
     * Asking for the page and query dimensions together returns the same
     * information in a single response.
     *
     * The page totals still need their own call: a page's true totals include
     * long-tail and anonymised queries that never appear in a per-query
     * breakdown, so summing the query rows would quietly under-report every
     * page on the site.
     *
     * @param int $days
     * @param int $row_limit
     * @return array|WP_Error { posts, rows, truncated }
     */
    public static function sync($days = self::DEFAULT_WINDOW, $row_limit = 5000) {
        if ('sitekit' !== self::active_source()) {
            return new WP_Error('ecp_no_live_source', __('No live Search Console connection. Import a CSV instead.', 'enhanced-content-plugin'));
        }

        $days = max(1, (int) $days);
        $end = gmdate('Y-m-d', strtotime('-2 days'));   // GSC data lags ~2 days.
        $start = gmdate('Y-m-d', strtotime("-{$days} days", strtotime($end)));

        // --- Page totals ---------------------------------------------------
        $page_rows = self::site_kit_query(array(
            'start_date' => $start,
            'end_date'   => $end,
            'dimensions' => array('page'),
            'limit'      => 1000,
        ));

        if (is_wp_error($page_rows)) {
            return $page_rows;
        }

        $posts_touched = 0;
        $rows_written = 0;

        // Resolving a URL to a post is cached per request, but building the
        // map once keeps the query pass from repeating the work.
        $url_map = array();

        foreach ($page_rows as $row) {
            $url = isset($row['keys'][0]) ? $row['keys'][0] : '';
            $post_id = self::url_to_post_id($url);

            if (!$post_id) {
                continue;
            }

            $url_map[$url] = $post_id;
            $posts_touched++;
            $rows_written += self::store_row($post_id, $end, '', $row, 'gsc', $days);
        }

        // --- Per-query breakdown, all pages at once --------------------------
        $query_rows = self::site_kit_query(array(
            'start_date' => $start,
            'end_date'   => $end,
            'dimensions' => array('page', 'query'),
            'limit'      => max(100, (int) $row_limit),
        ));

        if (is_wp_error($query_rows)) {
            // Page totals already landed, so this is a partial success rather
            // than a failure.
            return array('posts' => $posts_touched, 'rows' => $rows_written, 'truncated' => false);
        }

        foreach ($query_rows as $row) {
            $url = isset($row['keys'][0]) ? $row['keys'][0] : '';
            $query = isset($row['keys'][1]) ? $row['keys'][1] : '';

            if ('' === $query) {
                continue;
            }

            $post_id = isset($url_map[$url]) ? $url_map[$url] : self::url_to_post_id($url);

            if (!$post_id) {
                continue;
            }

            $rows_written += self::store_row($post_id, $end, $query, $row, 'gsc', $days);
        }

        // --- Device and country splits, site-wide ---------------------------
        // Stored as one small option per window rather than per-post rows:
        // the useful question at this level is "is my mobile CTR collapsing"
        // and "which markets see me", not a per-post-per-country matrix that
        // would multiply the metrics table by fifty.
        self::store_dimension_summary($days, $start, $end);

        return array(
            'posts'     => $posts_touched,
            'rows'      => $rows_written,
            // Hitting the ceiling means the tail was cut off. Worth saying so
            // rather than letting the numbers look complete.
            'truncated' => count($query_rows) >= (int) $row_limit,
        );
    }

    /**
     * Fetch and store the device and country splits for one window.
     *
     * Failures are deliberately non-fatal — this is enrichment, and a
     * missing breakdown must never break the page-level sync it rides on.
     */
    private static function store_dimension_summary($days, $start, $end) {
        $summary = get_option('ecp_dimension_summary', array());
        $summary = is_array($summary) ? $summary : array();

        $devices = self::site_kit_query(array(
            'start_date' => $start,
            'end_date'   => $end,
            'dimensions' => array('device'),
            'limit'      => 10,
        ));

        $countries = self::site_kit_query(array(
            'start_date' => $start,
            'end_date'   => $end,
            'dimensions' => array('country'),
            'limit'      => 12,
        ));

        $pack = function ($rows) {
            $out = array();

            foreach ((array) $rows as $row) {
                $out[] = array(
                    'key'         => isset($row['keys'][0]) ? strtolower((string) $row['keys'][0]) : '',
                    'clicks'      => (int) $row['clicks'],
                    'impressions' => (int) $row['impressions'],
                    'ctr'         => (float) $row['ctr'],
                    'position'    => (float) $row['position'],
                );
            }

            return $out;
        };

        $summary[(int) $days] = array(
            'date'      => $end,
            'devices'   => is_wp_error($devices) ? array() : $pack($devices),
            'countries' => is_wp_error($countries) ? array() : $pack($countries),
        );

        update_option('ecp_dimension_summary', $summary, false);
    }

    /**
     * Stored device/country splits for a window.
     *
     * @return array|null { date, devices: array[], countries: array[] }
     */
    public static function dimension_summary($window = self::DEFAULT_WINDOW) {
        $summary = get_option('ecp_dimension_summary', array());

        return isset($summary[(int) $window]) && is_array($summary[(int) $window])
            ? $summary[(int) $window]
            : null;
    }

    /**
     * Upsert one metrics row.
     *
     * @return int 1 when a row was written.
     */
    private static function store_row($post_id, $date, $query, array $row, $source = 'gsc', $window_days = self::DEFAULT_WINDOW) {
        global $wpdb;

        $table = ECP_DB::metrics_table();
        $query = mb_substr((string) $query, 0, 191);

        // Deliberately not $wpdb->replace(). REPLACE INTO deletes every row
        // matching *any* unique key before inserting, so it is only ever as
        // correct as the table's indexes are. When a stale index from an
        // earlier schema survived a migration, that meant each reporting
        // window wiped the previous one on write and only the last window
        // ever had data — with nothing anywhere reporting an error.
        //
        // Deleting the exact tuple and inserting is one extra query and is
        // correct whatever indexes happen to exist.
        $wpdb->delete(
            $table,
            array(
                'post_id'     => (int) $post_id,
                'window_days' => (int) $window_days,
                'metric_date' => $date,
                'query'       => $query,
            ),
            array('%d', '%d', '%s', '%s')
        );

        $result = $wpdb->insert(
            $table,
            array(
                'post_id'     => (int) $post_id,
                'metric_date' => $date,
                'window_days' => (int) $window_days,
                'query'       => $query,
                'clicks'      => (int) $row['clicks'],
                'impressions' => (int) $row['impressions'],
                'ctr'         => round((float) $row['ctr'], 5),
                'position'    => round((float) $row['position'], 2),
                'source'      => $source,
                'created_at'  => ECP_DB::now(),
            ),
            array('%d', '%s', '%d', '%s', '%d', '%d', '%f', '%f', '%s', '%s')
        );

        return $result ? 1 : 0;
    }

    /* --------------------------------------------------------------------
     * CSV import
     * ----------------------------------------------------------------- */

    /**
     * Import a Search Console CSV export.
     *
     * Accepts either the Pages export (columns: Top pages, Clicks,
     * Impressions, CTR, Position) or the Queries export with a page column.
     * Header names are matched loosely because they are localized.
     *
     * @param string $path      Path to the uploaded file.
     * @param string $date      Date to stamp the rows with (defaults to today).
     * @return array|WP_Error { rows: int, matched: int, unmatched: string[] }
     */
    public static function import_csv($path, $date = '') {
        if (!is_readable($path)) {
            return new WP_Error('ecp_csv_unreadable', __('Could not read the uploaded file.', 'enhanced-content-plugin'));
        }

        $date = $date ? $date : gmdate('Y-m-d');

        $handle = fopen($path, 'r');
        if (!$handle) {
            return new WP_Error('ecp_csv_open', __('Could not open the uploaded file.', 'enhanced-content-plugin'));
        }

        $header = fgetcsv($handle);
        if (!$header) {
            fclose($handle);
            return new WP_Error('ecp_csv_empty', __('The file appears to be empty.', 'enhanced-content-plugin'));
        }

        // Strip a UTF-8 BOM from the first header cell.
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);

        $cols = self::map_csv_columns($header);

        if (null === $cols['page'] && null === $cols['query']) {
            fclose($handle);
            return new WP_Error(
                'ecp_csv_columns',
                __('Could not find a page or query column. Export "Pages" or "Queries" from Search Console without changing the columns.', 'enhanced-content-plugin')
            );
        }

        $rows = 0;
        $matched = 0;
        $unmatched = array();
        $line = 0;

        while (false !== ($data = fgetcsv($handle))) {
            $line++;
            if ($line > 25000) {   // Sanity bound on a hand-uploaded file.
                break;
            }

            $page = null !== $cols['page'] && isset($data[$cols['page']]) ? trim($data[$cols['page']]) : '';
            $query = null !== $cols['query'] && isset($data[$cols['query']]) ? trim($data[$cols['query']]) : '';

            // A Queries export has no page column; those rows describe the
            // whole site and cannot be attributed to a post.
            if ('' === $page) {
                continue;
            }

            $post_id = self::url_to_post_id($page);
            if (!$post_id) {
                if (count($unmatched) < 25) {
                    $unmatched[] = $page;
                }
                continue;
            }

            $matched++;
            $rows += self::store_row(
                $post_id,
                $date,
                $query,
                array(
                    'clicks'      => self::csv_number($data, $cols['clicks']),
                    'impressions' => self::csv_number($data, $cols['impressions']),
                    'ctr'         => self::csv_percent($data, $cols['ctr']),
                    'position'    => self::csv_number($data, $cols['position']),
                ),
                'csv'
            );
        }

        fclose($handle);

        update_option('ecp_csv_import_meta', array(
            'imported_at' => current_time('mysql'),
            'rows'        => $rows,
            'matched'     => $matched,
        ), false);

        return array('rows' => $rows, 'matched' => $matched, 'unmatched' => $unmatched);
    }

    /**
     * Match CSV headers to our fields, tolerating localized labels.
     */
    private static function map_csv_columns(array $header) {
        $cols = array('page' => null, 'query' => null, 'clicks' => null, 'impressions' => null, 'ctr' => null, 'position' => null);

        foreach ($header as $i => $label) {
            $key = strtolower(trim((string) $label));

            if (null === $cols['page'] && (false !== strpos($key, 'page') || false !== strpos($key, 'url') || false !== strpos($key, 'address'))) {
                $cols['page'] = $i;
            } elseif (null === $cols['query'] && (false !== strpos($key, 'quer') || false !== strpos($key, 'keyword') || false !== strpos($key, 'search term'))) {
                $cols['query'] = $i;
            } elseif (null === $cols['clicks'] && false !== strpos($key, 'click')) {
                $cols['clicks'] = $i;
            } elseif (null === $cols['impressions'] && (false !== strpos($key, 'impress') || false !== strpos($key, 'view'))) {
                $cols['impressions'] = $i;
            } elseif (null === $cols['ctr'] && false !== strpos($key, 'ctr')) {
                $cols['ctr'] = $i;
            } elseif (null === $cols['position'] && (false !== strpos($key, 'position') || false !== strpos($key, 'rank'))) {
                $cols['position'] = $i;
            }
        }

        return $cols;
    }

    private static function csv_number($data, $index) {
        if (null === $index || !isset($data[$index])) {
            return 0;
        }

        // Strip thousands separators and any stray currency/percent glyphs.
        return (float) preg_replace('/[^0-9.\-]/', '', (string) $data[$index]);
    }

    private static function csv_percent($data, $index) {
        $value = self::csv_number($data, $index);

        // Search Console exports CTR as "4.2%"; store it as a 0-1 ratio.
        return $value > 1 ? $value / 100 : $value;
    }

    public static function has_imported_data() {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return false;
        }

        $table = ECP_DB::metrics_table();

        return (bool) $wpdb->get_var("SELECT 1 FROM {$table} LIMIT 1");
    }

    /**
     * How many metric rows are stored, for the status readout.
     */
    public static function stored_row_count() {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return 0;
        }

        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . ECP_DB::metrics_table());
    }

    /**
     * How many distinct posts have search data, which is the number that
     * actually tells you whether the URL matching worked.
     */
    public static function covered_post_count() {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return 0;
        }

        return (int) $wpdb->get_var('SELECT COUNT(DISTINCT post_id) FROM ' . ECP_DB::metrics_table());
    }

    /* --------------------------------------------------------------------
     * Reading stored metrics
     * ----------------------------------------------------------------- */

    /**
     * Page-level totals for a post from the most recent stored snapshot.
     *
     * @return array|null { clicks, impressions, ctr, position, metric_date }
     */
    public static function page_metrics($post_id, $window = self::DEFAULT_WINDOW) {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return null;
        }

        $table = ECP_DB::metrics_table();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT clicks, impressions, ctr, position, metric_date, window_days
             FROM {$table}
             WHERE post_id = %d AND query = '' AND window_days = %d
             ORDER BY metric_date DESC
             LIMIT 1",
            (int) $post_id,
            (int) $window
        ), ARRAY_A);

        if (!$row) {
            return null;
        }

        return array(
            'clicks'      => (int) $row['clicks'],
            'impressions' => (int) $row['impressions'],
            'ctr'         => (float) $row['ctr'],
            'position'    => (float) $row['position'],
            'metric_date' => $row['metric_date'],
            'window_days' => (int) $row['window_days'],
        );
    }

    /**
     * Top queries for a post from the most recent snapshot.
     *
     * @return array[] { query, clicks, impressions, ctr, position }
     */
    public static function top_queries($post_id, $limit = 10, $window = self::DEFAULT_WINDOW) {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return array();
        }

        $table = ECP_DB::metrics_table();

        $latest = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(metric_date) FROM {$table} WHERE post_id = %d AND window_days = %d",
            (int) $post_id,
            (int) $window
        ));

        if (!$latest) {
            return array();
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT query, clicks, impressions, ctr, position
             FROM {$table}
             WHERE post_id = %d AND window_days = %d AND metric_date = %s AND query != ''
             ORDER BY impressions DESC
             LIMIT %d",
            (int) $post_id,
            (int) $window,
            $latest,
            max(1, min(50, (int) $limit))
        ), ARRAY_A);

        return array_map(function ($row) {
            return array(
                'query'       => $row['query'],
                'clicks'      => (int) $row['clicks'],
                'impressions' => (int) $row['impressions'],
                'ctr'         => (float) $row['ctr'],
                'position'    => (float) $row['position'],
            );
        }, (array) $rows);
    }

    /**
     * Queries where the page ranks in striking distance (positions 5-20) —
     * the highest-leverage set to optimize for.
     *
     * @return array[]
     */
    public static function striking_distance_queries($post_id, $limit = 10, $window = self::DEFAULT_WINDOW) {
        $queries = self::top_queries($post_id, 50, $window);

        $striking = array_filter($queries, function ($q) {
            return $q['position'] >= 5 && $q['position'] <= 20 && $q['impressions'] >= 10;
        });

        usort($striking, function ($a, $b) {
            return $b['impressions'] <=> $a['impressions'];
        });

        return array_slice(array_values($striking), 0, $limit);
    }

    /**
     * Everything the issue detector needs to reason about search.
     *
     * Collected in one place so the analyzer and the scorer work from an
     * identical picture. They previously did not: the scorer knew a page was
     * being seen and not clicked, ranked it top of the queue for exactly that
     * reason, and then handed the analyzer a list of problems that never
     * mentioned it.
     *
     * @return array|null Null when there is no search data for this page.
     */
    public static function context($post_id, $window = self::DEFAULT_WINDOW) {
        $page = self::page_metrics($post_id, $window);

        if (!$page) {
            return null;
        }

        return array(
            'window'   => (int) $window,
            'page'     => $page,
            'queries'  => self::top_queries($post_id, 25, $window),
            'striking' => self::striking_distance_queries($post_id, 10, $window),
            'trend'    => self::page_trend($post_id, $window),
        );
    }

    /**
     * How a page's traffic has moved between the oldest and newest snapshot.
     *
     * Content decay is one of the things the plan asks for and the on-page
     * signals cannot see: an article can be perfectly well written, recently
     * updated, and still be quietly losing the traffic it used to earn.
     *
     * @return array|null { clicks_before, clicks_now, clicks_change, position_change, days, from, to }
     */
    public static function page_trend($post_id, $window = self::DEFAULT_WINDOW) {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return null;
        }

        $table = ECP_DB::metrics_table();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT metric_date, clicks, impressions, position
             FROM {$table}
             WHERE post_id = %d AND window_days = %d AND query = ''
             ORDER BY metric_date ASC",
            (int) $post_id,
            (int) $window
        ), ARRAY_A);

        // Two snapshots at least a fortnight apart, or there is no trend —
        // just noise between two nearby days.
        if (count($rows) < 2) {
            return null;
        }

        $first = $rows[0];
        $last = $rows[count($rows) - 1];

        $days = (int) round((strtotime($last['metric_date']) - strtotime($first['metric_date'])) / DAY_IN_SECONDS);

        if ($days < 14) {
            return null;
        }

        $before = (int) $first['clicks'];
        $now = (int) $last['clicks'];

        return array(
            'clicks_before'   => $before,
            'clicks_now'      => $now,
            'clicks_change'   => $now - $before,
            'percent_change'  => $before > 0 ? round((($now - $before) / $before) * 100) : null,
            'position_change' => round((float) $first['position'] - (float) $last['position'], 1),
            'days'            => $days,
            'from'            => $first['metric_date'],
            'to'              => $last['metric_date'],
        );
    }

    /**
     * Snapshot for a performance baseline, stored on a proposal when applied.
     *
     * @return array
     */
    public static function baseline($post_id, $window = self::DEFAULT_WINDOW) {
        $page = self::page_metrics($post_id, $window);

        return array(
            'captured_at' => current_time('mysql'),
            'source'      => self::active_source(),
            'window_days' => (int) $window,
            'page'        => $page,
            'queries'     => self::top_queries($post_id, 10, $window),
        );
    }

    /**
     * Compare current metrics against a stored baseline.
     *
     * Deliberately reports "correlated" language, not causation — a ranking
     * move after an edit is evidence, not proof.
     *
     * @return array|null { verdict, clicks_delta, impressions_delta, position_delta, days }
     */
    public static function compare_to_baseline(array $baseline, $post_id) {
        if (empty($baseline['page']) || empty($baseline['captured_at'])) {
            return null;
        }

        // Compare like with like: a baseline captured over 28 days must be
        // measured against another 28-day figure, or the "improvement" is
        // just the difference between two window lengths.
        $window = isset($baseline['window_days']) ? (int) $baseline['window_days'] : self::DEFAULT_WINDOW;

        $now = self::page_metrics($post_id, $window);
        if (!$now) {
            return null;
        }

        $days = max(0, (int) floor((time() - strtotime($baseline['captured_at'])) / DAY_IN_SECONDS));

        $before = $baseline['page'];
        $clicks_delta = $now['clicks'] - $before['clicks'];
        $impressions_delta = $now['impressions'] - $before['impressions'];
        // Position is inverted: a smaller number is better.
        $position_delta = round($before['position'] - $now['position'], 2);

        if ($days < 7) {
            $verdict = 'too_early';
        } elseif ($clicks_delta > 0 && $position_delta >= 0) {
            $verdict = 'improving';
        } elseif ($clicks_delta < 0 && $position_delta < 0) {
            $verdict = 'declining';
        } else {
            $verdict = 'stable';
        }

        return array(
            'verdict'           => $verdict,
            'days'              => $days,
            'clicks_delta'      => $clicks_delta,
            'impressions_delta' => $impressions_delta,
            'position_delta'    => $position_delta,
            'before'            => $before,
            'after'             => $now,
        );
    }

    /* --------------------------------------------------------------------
     * URL matching
     * ----------------------------------------------------------------- */

    /**
     * Resolve a Search Console page URL to a post ID.
     *
     * url_to_postid() misses a lot on sites with custom permalinks or a
     * different home URL than the GSC property, so we also try a direct
     * post_name lookup on the last path segment.
     */
    public static function url_to_post_id($url) {
        $url = trim((string) $url);
        if ('' === $url) {
            return 0;
        }

        static $cache = array();
        if (isset($cache[$url])) {
            return $cache[$url];
        }

        $path = wp_parse_url($url, PHP_URL_PATH);
        $path = $path ? $path : $url;

        // Rebuild against this site's home so a property mismatch (http vs
        // https, www vs bare) doesn't break every row.
        $candidate = home_url($path);

        $post_id = (int) url_to_postid($candidate);

        if (!$post_id) {
            $slug = trim((string) $path, '/');
            if ($slug) {
                $parts = explode('/', $slug);
                $slug = end($parts);

                global $wpdb;
                $post_id = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts}
                     WHERE post_name = %s AND post_status = 'publish'
                     LIMIT 1",
                    $slug
                ));
            }
        }

        $cache[$url] = $post_id;

        return $post_id;
    }

    /**
     * Delete metrics rows older than the retention setting.
     */
    public static function prune() {
        global $wpdb;

        $days = (int) ECP_Agent_Settings::get('metrics_retention_days', 400);
        $cutoff = gmdate('Y-m-d', strtotime("-{$days} days"));
        $table = ECP_DB::metrics_table();

        return (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE metric_date < %s",
            $cutoff
        ));
    }
}
