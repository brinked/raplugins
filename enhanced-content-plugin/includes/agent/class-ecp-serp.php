<?php
/**
 * SERP and keyword data through DataForSEO's REST API.
 *
 * The licensed external-data slot the topical map and briefs were
 * designed around (gameplan §8.2): keyword ideas with real search
 * volume, and live SERP contents so "what do the current results
 * already repeat" is measured instead of inferred. Nothing here
 * scrapes; every request goes to api.dataforseo.com and nowhere else.
 *
 * Money rules, because this API bills per request: every response is
 * cached in its own table and answered from there for thirty days; a
 * real API hit spends the monthly 'serp' meter; when the meter is
 * exhausted or credentials are absent, callers get an empty result
 * and carry on GSC-only — external data is an upgrade, never a
 * dependency.
 *
 * Credentials live in Agent Settings, password encrypted with the
 * site's salts exactly like the AI key. SaaS seam: this class is the
 * HTTP client that later points at the RankAudit backend instead,
 * where one DataForSEO account serves every site.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ECP_Serp {

    /** The only host this class will ever call. */
    const API_BASE = 'https://api.dataforseo.com';

    /** How long a bought answer stays good. */
    const CACHE_DAYS = 30;

    /* --------------------------------------------------------------------
     * Connection
     * ----------------------------------------------------------------- */

    /**
     * Whether credentials are configured.
     */
    public static function is_connected() {
        return '' !== trim((string) ECP_Agent_Settings::get('dataforseo_login', ''))
            && '' !== ECP_Agent_Settings::decrypt(ECP_Agent_Settings::get('dataforseo_password', ''));
    }

    /**
     * Live connection test: the free account-status endpoint. Returns
     * the account balance so the settings screen can show something
     * more convincing than "ok".
     *
     * @return array|WP_Error { login, balance }
     */
    public static function test() {
        $result = self::call('/v3/appendix/user_data', null, false);

        if (is_wp_error($result)) {
            return $result;
        }

        $money = isset($result['money']['balance']) ? (float) $result['money']['balance'] : null;

        return array(
            'login'   => isset($result['login']) ? (string) $result['login'] : '',
            'balance' => $money,
        );
    }

    /* --------------------------------------------------------------------
     * The two questions the plugin asks
     * ----------------------------------------------------------------- */

    /**
     * Keyword ideas around a seed, with real volume figures.
     *
     * @return array[] { keyword, volume, competition } Empty when not
     *                 connected, metered out, or the API fails — the
     *                 caller proceeds GSC-only.
     */
    public static function keyword_ideas($seed, $limit = 40) {
        $seed = trim((string) $seed);

        if ('' === $seed) {
            return array();
        }

        $payload = array(
            'keywords'      => array(strtolower($seed)),
            'location_name' => (string) ECP_Agent_Settings::get('serp_location', 'United States'),
            'language_name' => (string) ECP_Agent_Settings::get('serp_language', 'English'),
            'limit'         => max(10, min(100, (int) $limit)),
            'include_seed_keyword' => true,
        );

        $result = self::call('/v3/dataforseo_labs/google/keyword_ideas/live', $payload);

        if (is_wp_error($result) || empty($result['items'])) {
            return array();
        }

        $out = array();

        foreach ((array) $result['items'] as $item) {
            if (empty($item['keyword'])) {
                continue;
            }

            $info = isset($item['keyword_info']) ? $item['keyword_info'] : array();

            $out[] = array(
                'keyword'     => (string) $item['keyword'],
                'volume'      => isset($info['search_volume']) ? (int) $info['search_volume'] : 0,
                'competition' => isset($info['competition']) ? (float) $info['competition'] : 0.0,
            );
        }

        return $out;
    }

    /**
     * What currently ranks for a query: the top organic results and
     * the People Also Ask questions.
     *
     * @return array { results: array[], questions: string[] } Empty
     *               arrays when unavailable.
     */
    public static function serp($query) {
        $query = trim((string) $query);

        if ('' === $query) {
            return array('results' => array(), 'questions' => array());
        }

        $payload = array(
            'keyword'       => $query,
            'location_name' => (string) ECP_Agent_Settings::get('serp_location', 'United States'),
            'language_name' => (string) ECP_Agent_Settings::get('serp_language', 'English'),
            'depth'         => 10,
        );

        $result = self::call('/v3/serp/google/organic/live/advanced', $payload);

        if (is_wp_error($result) || empty($result['items'])) {
            return array('results' => array(), 'questions' => array());
        }

        $results = array();
        $questions = array();

        foreach ((array) $result['items'] as $item) {
            $type = isset($item['type']) ? $item['type'] : '';

            if ('organic' === $type && count($results) < 10) {
                $results[] = array(
                    'title'       => isset($item['title']) ? (string) $item['title'] : '',
                    'description' => isset($item['description']) ? (string) $item['description'] : '',
                    'domain'      => isset($item['domain']) ? (string) $item['domain'] : '',
                );
            } elseif ('people_also_ask' === $type && !empty($item['items'])) {
                foreach ((array) $item['items'] as $paa) {
                    if (!empty($paa['title'])) {
                        $questions[] = (string) $paa['title'];
                    }
                }
            }
        }

        return array('results' => $results, 'questions' => array_slice($questions, 0, 10));
    }

    /* --------------------------------------------------------------------
     * Transport
     * ----------------------------------------------------------------- */

    /**
     * One API call: cache first, then meter, then the wire.
     *
     * @param string     $path    e.g. '/v3/serp/google/organic/live/advanced'
     * @param array|null $payload Task payload; null for GET-style calls.
     * @param bool       $cache   The free test endpoint skips the cache.
     * @return array|WP_Error The first task's first result.
     */
    private static function call($path, $payload = null, $cache = true) {
        if (!self::is_connected()) {
            return new WP_Error('ecp_serp_off', __('DataForSEO is not connected. Add your API credentials in Agent Settings.', 'enhanced-content-plugin'));
        }

        $key = md5($path . '|' . wp_json_encode($payload));

        if ($cache) {
            $hit = self::cache_get($key);

            if (null !== $hit) {
                return $hit;
            }

            $allowed = ECP_Limits::can('serp');

            if (is_wp_error($allowed)) {
                return $allowed;
            }
        }

        $login = trim((string) ECP_Agent_Settings::get('dataforseo_login', ''));
        $password = ECP_Agent_Settings::decrypt(ECP_Agent_Settings::get('dataforseo_password', ''));

        $request = array(
            'timeout' => 60,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($login . ':' . $password),
                'Content-Type'  => 'application/json',
            ),
        );

        // Data endpoints take a POSTed array of tasks (the plugin always
        // sends one); status endpoints like user_data are plain GETs.
        if (null === $payload) {
            $request['method'] = 'GET';
        } else {
            $request['method'] = 'POST';
            $request['body'] = wp_json_encode(array($payload));
        }

        $response = wp_remote_request(self::API_BASE . $path, $request);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!is_array($body) || !isset($body['status_code'])) {
            return new WP_Error('ecp_serp_bad_response', __('DataForSEO returned something unreadable.', 'enhanced-content-plugin'));
        }

        if (20000 !== (int) $body['status_code']) {
            return new WP_Error('ecp_serp_api_error', sprintf(
                /* translators: 1: status code, 2: message */
                __('DataForSEO error %1$d: %2$s', 'enhanced-content-plugin'),
                (int) $body['status_code'],
                isset($body['status_message']) ? $body['status_message'] : ''
            ));
        }

        $task = isset($body['tasks'][0]) ? $body['tasks'][0] : array();

        if (isset($task['status_code']) && 20000 !== (int) $task['status_code']) {
            return new WP_Error('ecp_serp_task_error', sprintf(
                /* translators: 1: status code, 2: message */
                __('DataForSEO task error %1$d: %2$s', 'enhanced-content-plugin'),
                (int) $task['status_code'],
                isset($task['status_message']) ? $task['status_message'] : ''
            ));
        }

        $result = isset($task['result'][0]) && is_array($task['result'][0]) ? $task['result'][0] : array();

        if ($cache) {
            ECP_Limits::spend('serp');
            self::cache_set($key, $path, $result);
        }

        return $result;
    }

    /* --------------------------------------------------------------------
     * Cache
     * ----------------------------------------------------------------- */

    /**
     * @return array|null Decoded payload, or null on miss/expiry.
     */
    private static function cache_get($key) {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return null;
        }

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT payload, created_at FROM ' . ECP_DB::serp_cache_table() . ' WHERE cache_key = %s',
            $key
        ), ARRAY_A);

        if (!$row) {
            return null;
        }

        if (strtotime($row['created_at']) < current_time('timestamp') - self::CACHE_DAYS * DAY_IN_SECONDS) {
            return null;   // Expired; pruning is the maintenance job's problem.
        }

        return ECP_DB::decode($row['payload']);
    }

    private static function cache_set($key, $endpoint, array $result) {
        global $wpdb;

        $wpdb->replace(
            ECP_DB::serp_cache_table(),
            array(
                'cache_key'  => $key,
                'endpoint'   => substr((string) $endpoint, 0, 120),
                'payload'    => ECP_DB::encode($result),
                'created_at' => ECP_DB::now(),
            ),
            array('%s', '%s', '%s', '%s')
        );
    }

    /**
     * Drop rows past their useful life. Called by the maintenance job.
     *
     * @return int Rows deleted.
     */
    public static function prune() {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return 0;
        }

        $cutoff = gmdate('Y-m-d H:i:s', (int) current_time('timestamp', true) - 2 * self::CACHE_DAYS * DAY_IN_SECONDS);

        return (int) $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . ECP_DB::serp_cache_table() . ' WHERE created_at < %s',
            $cutoff
        ));
    }
}
