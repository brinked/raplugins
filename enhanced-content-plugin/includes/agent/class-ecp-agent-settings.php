<?php
/**
 * Agent configuration store.
 *
 * Kept separate from ECP_Settings (which holds the editorial/display options)
 * because these values control money and content mutation, and want their own
 * capability check, their own audit entry on change, and their own defaults.
 *
 * API keys: the preferred place is a constant in wp-config.php —
 *
 *     define('ECP_AI_API_KEY', 'sk-ant-...');
 *
 * which keeps the secret out of the database and out of database backups
 * entirely. If no constant is defined we fall back to an option, encrypted
 * with a key derived from the site's own salts. That is obfuscation against
 * casual database access, not protection against someone who already has the
 * filesystem — the settings screen says so plainly.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ECP_Agent_Settings {

    const OPTION = 'ecp_agent_settings';

    /** Cached merged settings for this request. */
    private static $cache = null;

    /**
     * Defaults. Note the posture: the agent starts in the safest possible
     * configuration — enabled for scanning, but nothing is written to the
     * site until a human approves it.
     */
    public static function defaults() {
        return array(
            // --- Master switches -------------------------------------------
            'agent_enabled'        => 0,   // Off until the setup checklist is done.
            'scan_enabled'         => 1,
            'analysis_enabled'     => 1,

            // --- AI provider -----------------------------------------------
            'provider'             => 'anthropic',
            'model'                => 'claude-opus-5',
            'effort'               => 'high',   // Anthropic only: low|medium|high|xhigh|max
            'api_key'              => '',   // Stored encrypted; may be empty if constant is used.
            'rankaudit_endpoint'   => '',   // Future: proxy analysis through RankAudit.
            'rankaudit_site_token' => '',
            'request_timeout'      => 120,
            'max_retries'          => 2,

            // --- SERP data (DataForSEO) --------------------------------------
            'dataforseo_login'     => '',
            'dataforseo_password'  => '',   // Stored encrypted, like api_key.
            'serp_location'        => 'United States',
            'serp_language'        => 'English',
            'serp_per_month'       => 300,

            // --- Budget ------------------------------------------------------
            'monthly_budget_usd'   => 20,
            'max_analyses_per_day' => 10,
            'max_posts_per_scan'   => 500,

            // --- Scope --------------------------------------------------------
            'post_types'           => array('post'),
            'excluded_post_ids'    => '',
            'excluded_terms'       => array(),   // taxonomy:term_id strings
            'min_post_age_days'    => 14,        // Don't touch brand-new posts.
            'require_published'    => 1,

            // --- What the agent is allowed to propose -------------------------
            'enabled_change_types' => array(
                'meta_title',
                'meta_description',
                'intro_rewrite',
                'section_rewrite',
                'section_add',
                'heading_rewrite',
                'faq_add',
                'internal_link_add',
                'image_alt',
                'schema_fix',
                'source_add',
                'freshness_update',
                'section_trim',
            ),

            // --- Approval policy -----------------------------------------------
            // 'always'  : every change needs a human (default, and what most
            //             sites should stay on)
            // 'safe'    : auto-apply 'safe' risk tier only
            // 'trusted' : auto-apply change types that have earned trust
            //             (see ECP_Trust_Ladder)
            'approval_mode'        => 'always',
            'auto_apply_types'     => array(),
            'apply_as_draft'       => 0,   // 1 = write to a draft revision instead of live
            'proposal_ttl_days'    => 30,  // Pending proposals expire after this.

            // --- Editorial guardrails --------------------------------------------
            'preserve_voice'       => 1,
            'brand_terms'          => '',
            'banned_phrases'       => "in today's digital landscape\nunlock the power\ndelve into\nin conclusion,\nit's important to note that\ngame-changer\nnavigate the complexities",
            'tone_notes'           => '',
            'max_change_percent'   => 40,   // Refuse patches rewriting more than this share of an article.
            'require_source_for_claims' => 1,
            'forbid_new_numbers'   => 1,    // Block generated stats/dates/prices with no source.
            'forbid_em_dashes'     => 1,    // The single loudest "an AI wrote this" tell.
            'reading_level'        => 'match',

            // --- Quality & freshness ------------------------------------------------
            // gap_mode: what the automatic loop does with reader-question
            // analysis. 'propose' files section proposals (approval still
            // required); 'report' audits without writing; 'off' is manual-only.
            'gap_mode'             => 'propose',
            // stale_refresh: when an article has not been touched in over a
            // year, 'light' limits automatic proposals to refresh-sized
            // changes; 'full' treats it like any other page.
            'stale_refresh'        => 'light',

            // --- Automatic refresh cycle ---------------------------------------------
            // Off by default: this is the one feature that applies changes
            // without a per-change approval, so it must be a deliberate
            // opt-in, never a surprise.
            'refresh_enabled'       => 0,
            'refresh_age_days'      => 365, // Articles older than this qualify.
            'refresh_interval_days' => 90,  // Per-article cooldown between cycles.
            'refresh_per_day'       => 2,   // Articles per nightly run.
            'refresh_hold_hours'    => 48,  // Review window before auto-apply.
            'refresh_types'         => array(
                'meta_description',
                'image_alt',
                'internal_link_add',
                'section_trim',
                'source_add',
                'faq_add',
                'schema_fix',
            ),

            // --- Site intelligence ---------------------------------------------------
            'classify_per_day'      => 100, // Pages classified per day; own meter, not analyses.
            'classify_model'        => '',  // Empty = the main model. Classification runs fine on a cheaper one.

            // --- Search Console ---------------------------------------------------
            'search_source'        => 'auto', // auto | sitekit | csv | none
            'metrics_retention_days' => 400,

            // --- Cross-page analysis ------------------------------------------------
            'clusters_enabled'     => 1,

            // --- Who can do what ----------------------------------------------------
            // role slug => ECP_Capabilities level. Empty means "use defaults".
            'role_access'          => array(),

            // --- Notifications -----------------------------------------------------
            'digest_enabled'       => 1,
            'digest_frequency'     => 'weekly', // daily | weekly | off
            'digest_recipients'    => '',       // Comma list; empty = site admin email.
            'notify_on_failure'    => 1,

            // --- Housekeeping --------------------------------------------------------
            'retention_days'       => 180,
            'debug_logging'        => 0,
        );
    }

    /**
     * All settings, merged over defaults.
     */
    public static function all() {
        if (null !== self::$cache) {
            return self::$cache;
        }

        $stored = get_option(self::OPTION, array());
        if (!is_array($stored)) {
            $stored = array();
        }

        self::$cache = wp_parse_args($stored, self::defaults());

        return self::$cache;
    }

    /**
     * Read one setting.
     *
     * @param string $key
     * @param mixed  $default Returned only if the key is unknown entirely.
     * @return mixed
     */
    public static function get($key, $default = null) {
        $all = self::all();

        if (array_key_exists($key, $all)) {
            return $all[$key];
        }

        return $default;
    }

    /**
     * Boolean read — settings come back from the options table as strings.
     */
    public static function is_on($key) {
        return (bool) (int) self::get($key, 0);
    }

    /**
     * Persist a partial update. Values are sanitized here, not by the caller.
     *
     * @param array $changes
     * @return array The full saved settings array.
     */
    public static function update(array $changes) {
        $current = self::all();
        $clean = self::sanitize($changes, $current);

        $merged = array_merge($current, $clean);

        // Never write the encrypted key back through the plain 'api_key' slot
        // if the submitted value was the masked placeholder.
        update_option(self::OPTION, $merged, false);
        self::$cache = null;

        $changed_keys = array();
        foreach ($clean as $key => $value) {
            if (!isset($current[$key]) || $current[$key] !== $value) {
                $changed_keys[] = $key;
            }
        }

        if ($changed_keys) {
            ECP_Log::record(ECP_Log::SETTINGS_CHANGED, array(
                'message' => sprintf(
                    /* translators: %s: comma-separated list of setting names */
                    __('Updated agent settings: %s', 'enhanced-content-plugin'),
                    implode(', ', $changed_keys)
                ),
                // Deliberately never log values — one of them is an API key.
                'context' => array('keys' => $changed_keys),
            ));
        }

        return $merged;
    }

    /* --------------------------------------------------------------------
     * Sanitizing
     * ----------------------------------------------------------------- */

    /**
     * @param array $input   Raw submitted values (only keys present are touched).
     * @param array $current Existing settings, for fields that keep their old value.
     * @return array
     */
    public static function sanitize(array $input, array $current = array()) {
        $out = array();

        $ints = array(
            'agent_enabled'        => array(0, 1),
            'scan_enabled'         => array(0, 1),
            'analysis_enabled'     => array(0, 1),
            'request_timeout'      => array(15, 600),
            'max_retries'          => array(0, 5),
            'monthly_budget_usd'   => array(0, 100000),
            'max_analyses_per_day' => array(0, 500),
            'max_posts_per_scan'   => array(10, 20000),
            'min_post_age_days'    => array(0, 3650),
            'require_published'    => array(0, 1),
            'apply_as_draft'       => array(0, 1),
            'proposal_ttl_days'    => array(1, 365),
            'preserve_voice'       => array(0, 1),
            'max_change_percent'   => array(5, 100),
            'require_source_for_claims' => array(0, 1),
            'forbid_new_numbers'   => array(0, 1),
            'forbid_em_dashes'     => array(0, 1),
            'digest_enabled'       => array(0, 1),
            'notify_on_failure'    => array(0, 1),
            'retention_days'       => array(7, 3650),
            'metrics_retention_days' => array(30, 3650),
            'clusters_enabled'     => array(0, 1),
            'debug_logging'        => array(0, 1),
            'refresh_enabled'      => array(0, 1),
            'refresh_age_days'     => array(90, 3650),
            'refresh_interval_days' => array(30, 730),
            'refresh_per_day'      => array(1, 10),
            'refresh_hold_hours'   => array(0, 168),
            'classify_per_day'     => array(0, 5000),
            'serp_per_month'       => array(0, 100000),
        );

        foreach ($ints as $key => $range) {
            if (isset($input[$key])) {
                $out[$key] = max($range[0], min($range[1], (int) $input[$key]));
            }
        }

        $enums = array(
            'provider'        => array('anthropic', 'openai', 'rankaudit'),
            'approval_mode'   => array('always', 'safe', 'trusted'),
            'search_source'   => array('auto', 'sitekit', 'csv', 'none'),
            'digest_frequency' => array('daily', 'weekly', 'off'),
            'reading_level'   => array('match', 'simpler', 'technical'),
            'effort'          => array('high', 'low', 'medium', 'xhigh', 'max'),
            'gap_mode'        => array('propose', 'report', 'off'),
            'stale_refresh'   => array('light', 'full'),
        );

        foreach ($enums as $key => $allowed) {
            if (isset($input[$key])) {
                $value = sanitize_text_field((string) $input[$key]);
                $out[$key] = in_array($value, $allowed, true) ? $value : $allowed[0];
            }
        }

        if (isset($input['classify_model'])) {
            $out['classify_model'] = sanitize_text_field((string) $input['classify_model']);
        }

        if (isset($input['model'])) {
            $out['model'] = sanitize_text_field((string) $input['model']);
        }

        if (isset($input['rankaudit_endpoint'])) {
            $out['rankaudit_endpoint'] = esc_url_raw(trim((string) $input['rankaudit_endpoint']));
        }

        if (isset($input['rankaudit_site_token'])) {
            $out['rankaudit_site_token'] = sanitize_text_field((string) $input['rankaudit_site_token']);
        }

        // API key: an unchanged field arrives as the mask, which must not
        // overwrite the stored key.
        if (isset($input['api_key'])) {
            $submitted = trim((string) $input['api_key']);

            if ('' === $submitted) {
                $out['api_key'] = '';   // Explicitly cleared.
            } elseif (!self::is_mask($submitted)) {
                $out['api_key'] = self::encrypt($submitted);
            }
        }

        // DataForSEO credentials: same mask-and-encrypt contract as api_key.
        if (isset($input['dataforseo_login'])) {
            $out['dataforseo_login'] = sanitize_text_field((string) $input['dataforseo_login']);
        }

        if (isset($input['dataforseo_password'])) {
            $submitted = trim((string) $input['dataforseo_password']);

            if ('' === $submitted) {
                $out['dataforseo_password'] = '';
            } elseif (!self::is_mask($submitted)) {
                $out['dataforseo_password'] = self::encrypt($submitted);
            }
        }

        if (isset($input['serp_location'])) {
            $out['serp_location'] = sanitize_text_field((string) $input['serp_location']);
        }

        if (isset($input['serp_language'])) {
            $out['serp_language'] = sanitize_text_field((string) $input['serp_language']);
        }

        // Post types must be real, public and registered.
        if (isset($input['post_types'])) {
            $requested = (array) $input['post_types'];
            $valid = array();
            foreach ($requested as $type) {
                $type = sanitize_key($type);
                if ($type && post_type_exists($type)) {
                    $valid[] = $type;
                }
            }
            $out['post_types'] = $valid ? array_values(array_unique($valid)) : array('post');
        }

        if (isset($input['enabled_change_types'])) {
            $known = array_keys(ECP_Proposals::change_types());
            $valid = array_values(array_intersect(
                array_map('sanitize_key', (array) $input['enabled_change_types']),
                $known
            ));
            $out['enabled_change_types'] = $valid;
        }

        if (isset($input['auto_apply_types'])) {
            $known = array_keys(ECP_Proposals::change_types());
            $out['auto_apply_types'] = array_values(array_intersect(
                array_map('sanitize_key', (array) $input['auto_apply_types']),
                $known
            ));
        }

        if (isset($input['refresh_types'])) {
            // Bounded by the analyzer's refresh-sized set, not just by known
            // types — the refresh cycle must never be widened into rewrites
            // by a crafted POST.
            $out['refresh_types'] = array_values(array_intersect(
                array_map('sanitize_key', (array) $input['refresh_types']),
                ECP_Analyzer::REFRESH_TYPES
            ));
        }

        // Role → access level. Validated against the roles that actually
        // exist and the levels the capability layer defines, so a stale or
        // hand-edited value can never silently widen someone's access.
        if (isset($input['role_access'])) {
            $known_roles = array_keys(ECP_Capabilities::all_roles());
            $known_levels = array_keys(ECP_Capabilities::levels());
            $map = array();

            foreach ((array) $input['role_access'] as $role => $level) {
                $role = sanitize_key($role);
                $level = sanitize_key($level);

                if (in_array($role, $known_roles, true) && in_array($level, $known_levels, true)) {
                    $map[$role] = $level;
                }
            }

            // An administrator cannot be demoted below manage here — the
            // capability layer would override it anyway, and storing a value
            // the system ignores is a lie in the UI.
            $map['administrator'] = ECP_Capabilities::LEVEL_MANAGE;

            $out['role_access'] = $map;
        }

        if (isset($input['excluded_terms'])) {
            $terms = array();
            foreach ((array) $input['excluded_terms'] as $ref) {
                $ref = sanitize_text_field((string) $ref);
                if (preg_match('/^[a-z0-9_-]+:\d+$/i', $ref)) {
                    $terms[] = $ref;
                }
            }
            $out['excluded_terms'] = array_values(array_unique($terms));
        }

        if (isset($input['excluded_post_ids'])) {
            $ids = preg_split('/[\s,]+/', (string) $input['excluded_post_ids'], -1, PREG_SPLIT_NO_EMPTY);
            $ids = array_filter(array_map('absint', (array) $ids));
            $out['excluded_post_ids'] = implode(',', array_unique($ids));
        }

        $textareas = array('brand_terms', 'banned_phrases', 'tone_notes');
        foreach ($textareas as $key) {
            if (isset($input[$key])) {
                $out[$key] = sanitize_textarea_field((string) $input[$key]);
            }
        }

        if (isset($input['digest_recipients'])) {
            $emails = array();
            foreach (preg_split('/[\s,;]+/', (string) $input['digest_recipients'], -1, PREG_SPLIT_NO_EMPTY) as $email) {
                $email = sanitize_email($email);
                if ($email && is_email($email)) {
                    $emails[] = $email;
                }
            }
            $out['digest_recipients'] = implode(', ', array_unique($emails));
        }

        unset($current);

        return $out;
    }

    /* --------------------------------------------------------------------
     * API key handling
     * ----------------------------------------------------------------- */

    /**
     * The effective API key for the active provider.
     *
     * Constant wins over the stored option, so a site can move its secret to
     * wp-config.php at any time without clearing the settings screen.
     *
     * @return string Empty string when no key is configured.
     */
    public static function api_key() {
        if (defined('ECP_AI_API_KEY') && ECP_AI_API_KEY) {
            return (string) ECP_AI_API_KEY;
        }

        $stored = self::get('api_key', '');

        return $stored ? self::decrypt($stored) : '';
    }

    /**
     * Where the key came from — shown on the settings screen.
     *
     * @return string 'constant' | 'database' | 'none'
     */
    public static function api_key_source() {
        if (defined('ECP_AI_API_KEY') && ECP_AI_API_KEY) {
            return 'constant';
        }

        return self::get('api_key', '') ? 'database' : 'none';
    }

    /**
     * The placeholder rendered in the key field when a key is already set.
     */
    public static function mask() {
        return '••••••••••••••••';
    }

    private static function is_mask($value) {
        return $value === self::mask() || (bool) preg_match('/^[•*]{4,}$/u', $value);
    }

    /**
     * Derive a 32-byte encryption key from the site's salts.
     *
     * Consequence worth knowing: rotating the WordPress salts invalidates the
     * stored API key and the user must re-enter it. That is the correct
     * trade-off — a leaked database alone should not yield a usable key.
     */
    private static function crypto_key() {
        $material = (defined('AUTH_KEY') ? AUTH_KEY : '')
            . (defined('SECURE_AUTH_SALT') ? SECURE_AUTH_SALT : '')
            . 'ecp-agent';

        return hash('sha256', $material, true);
    }

    /**
     * @param string $plaintext
     * @return string base64(iv . ciphertext), or the raw value if OpenSSL is absent.
     */
    public static function encrypt($plaintext) {
        if (!function_exists('openssl_encrypt')) {
            // Marker prefix so decrypt() knows this one was never encrypted.
            return 'plain:' . $plaintext;
        }

        $iv = openssl_random_pseudo_bytes(16);
        $cipher = openssl_encrypt($plaintext, 'aes-256-cbc', self::crypto_key(), OPENSSL_RAW_DATA, $iv);

        if (false === $cipher) {
            return 'plain:' . $plaintext;
        }

        return 'enc:' . base64_encode($iv . $cipher);
    }

    /**
     * @param string $stored
     * @return string Empty string if the value cannot be decrypted.
     */
    public static function decrypt($stored) {
        $stored = (string) $stored;

        if (0 === strpos($stored, 'plain:')) {
            return substr($stored, 6);
        }

        if (0 !== strpos($stored, 'enc:') || !function_exists('openssl_decrypt')) {
            return '';
        }

        $raw = base64_decode(substr($stored, 4), true);
        if (false === $raw || strlen($raw) <= 16) {
            return '';
        }

        $iv = substr($raw, 0, 16);
        $cipher = substr($raw, 16);
        $plain = openssl_decrypt($cipher, 'aes-256-cbc', self::crypto_key(), OPENSSL_RAW_DATA, $iv);

        return false === $plain ? '' : $plain;
    }

    /* --------------------------------------------------------------------
     * Derived helpers
     * ----------------------------------------------------------------- */

    /**
     * Post IDs the agent must never touch.
     *
     * @return int[]
     */
    public static function excluded_post_ids() {
        $raw = (string) self::get('excluded_post_ids', '');
        $ids = array_filter(array_map('absint', preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY)));

        /**
         * Filter the post IDs excluded from all agent activity.
         *
         * @param int[] $ids
         */
        return array_values(array_unique(apply_filters('ecp_excluded_post_ids', $ids)));
    }

    /**
     * Brand terms the AI must reproduce verbatim.
     *
     * @return string[]
     */
    public static function brand_terms() {
        return self::lines('brand_terms');
    }

    /**
     * Phrases that fail a proposal outright.
     *
     * @return string[]
     */
    public static function banned_phrases() {
        return self::lines('banned_phrases');
    }

    private static function lines($key) {
        $raw = (string) self::get($key, '');
        $lines = preg_split('/\r\n|\r|\n/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        $lines = array_filter(array_map('trim', (array) $lines));

        return array_values(array_unique($lines));
    }

    /**
     * Voice rules shared by every prompt that writes reader-facing text.
     *
     * One source, three consumers (analyzer, cluster analysis, content
     * gaps) — when a rule about how the agent writes changes, it must
     * change everywhere at once or the prompts drift apart and one code
     * path quietly keeps the old behaviour.
     *
     * These are deliberately blunt. "Match the voice" as a polite aside gets
     * politely ignored; the model needs to be told the reader must not be
     * able to find the seam.
     *
     * @return string[] Prompt lines.
     */
    public static function voice_rules() {
        $lines = array();

        $lines[] = 'Voice: before writing a single word, study the article text you were given. Note its sentence length, vocabulary, punctuation habits, how it addresses the reader, and how formal it is. Write so that nobody — including the author — can tell where their text ends and yours begins. When in doubt, plainer and closer to their style beats better and different.';

        if (self::is_on('forbid_em_dashes')) {
            $lines[] = 'Punctuation, hard rule: never use an em dash (—) or a spaced en dash ( – ) anywhere in anything you write. This site\'s writing does not use them, and readers increasingly read them as the mark of AI text. Where you would reach for one, use a comma, a period, or parentheses. An en dash inside a number range (2019–2024) is fine.';
        }

        return $lines;
    }

    /**
     * Whether a change type may be proposed at all.
     */
    public static function change_type_enabled($type) {
        $enabled = (array) self::get('enabled_change_types', array());

        return in_array($type, $enabled, true);
    }

    /**
     * True when the agent is configured well enough to actually run.
     */
    public static function is_ready() {
        if (!self::is_on('agent_enabled')) {
            return false;
        }

        if ('rankaudit' === self::get('provider')) {
            return (bool) self::get('rankaudit_endpoint') && (bool) self::get('rankaudit_site_token');
        }

        return '' !== self::api_key();
    }

    /**
     * The remaining setup steps, for the dashboard checklist.
     *
     * @return array[] { key, label, done, action_url }
     */
    public static function setup_steps() {
        $settings_url = admin_url('admin.php?page=ecp-agent-settings');

        $steps = array(
            array(
                'key'   => 'api_key',
                'label' => __('Connect an AI provider', 'enhanced-content-plugin'),
                'done'  => 'none' !== self::api_key_source() || 'rankaudit' === self::get('provider'),
                'action_url' => $settings_url . '#ecp-section-provider',
                'help'  => __('Paste an API key, or define ECP_AI_API_KEY in wp-config.php.', 'enhanced-content-plugin'),
            ),
            array(
                'key'   => 'scope',
                'label' => __('Choose which content the agent may work on', 'enhanced-content-plugin'),
                'done'  => (bool) self::get('post_types'),
                'action_url' => $settings_url . '#ecp-section-scope',
                'help'  => __('Post types, exclusions, and the minimum age before a post is eligible.', 'enhanced-content-plugin'),
            ),
            array(
                'key'   => 'budget',
                'label' => __('Set a monthly spend cap', 'enhanced-content-plugin'),
                'done'  => (int) self::get('monthly_budget_usd') > 0,
                'action_url' => $settings_url . '#ecp-section-budget',
                'help'  => __('The agent stops calling the API when the cap is reached.', 'enhanced-content-plugin'),
            ),
            array(
                'key'   => 'search',
                'label' => __('Connect Search Console data (optional but recommended)', 'enhanced-content-plugin'),
                'done'  => ECP_Search_Data::is_connected(),
                'action_url' => admin_url('admin.php?page=ecp-agent-settings&tab=search'),
                'help'  => __('Without it the agent works from on-page signals only and cannot measure results.', 'enhanced-content-plugin'),
            ),
            array(
                'key'   => 'enabled',
                'label' => __('Turn the agent on', 'enhanced-content-plugin'),
                'done'  => self::is_on('agent_enabled'),
                'action_url' => $settings_url,
                'help'  => __('Scanning begins on the next scheduled run. Nothing is published without your approval.', 'enhanced-content-plugin'),
            ),
        );

        // Phase 1 growth-system steps ride the same checklist.
        $steps[] = array(
            'key'   => 'profile',
            'label' => __('Tell the agent about your business', 'enhanced-content-plugin'),
            'done'  => ECP_Site_Profile::completeness() >= 100,
            'action_url' => $settings_url . '&tab=profile',
            'help'  => __('Who you are, who you serve, and which topics are in and out of bounds. Strategic features stay generic without it.', 'enhanced-content-plugin'),
        );

        return $steps;
    }

    /**
     * Clear the request cache (tests, and after a direct option write).
     */
    public static function flush() {
        self::$cache = null;
    }
}
