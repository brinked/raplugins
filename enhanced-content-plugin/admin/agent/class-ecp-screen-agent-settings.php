<?php
/**
 * Agent settings.
 *
 * Hand-rolled rather than using the Settings API, because the fields here are
 * grouped into explanatory sections with live state (a connection test, trust
 * progress, a CSV importer) that the Settings API's field callbacks make
 * awkward. ECP_Agent_Settings::sanitize() is still the single sanitising
 * authority.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ECP_Screen_Agent_Settings {

    public static function render() {
        if (!ECP_Capabilities::can_manage()) {
            wp_die(esc_html__('You do not have permission to change these settings.', 'enhanced-content-plugin'));
        }

        $notice = '';

        if (isset($_POST['ecp_settings_nonce'])) {
            $notice = self::handle_post();
        }

        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'general';
        $settings = ECP_Agent_Settings::all();

        ?>
        <div class="wrap ecp-wrap ecp-settings">
            <h1><?php esc_html_e('Agent Settings', 'enhanced-content-plugin'); ?></h1>

            <?php ECP_Admin_Menu::header('ecp-agent-settings'); ?>

            <?php if ($notice) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
            <?php endif; ?>

            <ul class="subsubsub">
                <?php
                $tabs = array(
                    'general'     => __('General', 'enhanced-content-plugin'),
                    'profile'     => __('Site profile', 'enhanced-content-plugin'),
                    'writing'     => __('Writing rules', 'enhanced-content-plugin'),
                    'approval'    => __('Approval & autopilot', 'enhanced-content-plugin'),
                    'permissions' => __('Who can do what', 'enhanced-content-plugin'),
                    'search'      => __('Search Console', 'enhanced-content-plugin'),
                    'email'       => __('Email', 'enhanced-content-plugin'),
                );

                $links = array();
                foreach ($tabs as $slug => $label) {
                    $links[] = sprintf(
                        '<li><a href="%s"%s>%s</a></li>',
                        esc_url(add_query_arg(array('page' => 'ecp-agent-settings', 'tab' => $slug), admin_url('admin.php'))),
                        $slug === $tab ? ' class="current"' : '',
                        esc_html($label)
                    );
                }

                echo implode(' | ', $links); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
                ?>
            </ul>

            <form method="post" action="<?php echo esc_url(add_query_arg(array('page' => 'ecp-agent-settings', 'tab' => $tab), admin_url('admin.php'))); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field('ecp_save_settings', 'ecp_settings_nonce'); ?>
                <input type="hidden" name="ecp_tab" value="<?php echo esc_attr($tab); ?>">

                <?php
                switch ($tab) {
                    case 'profile':
                        self::tab_profile();
                        break;
                    case 'writing':
                        self::tab_writing($settings);
                        break;
                    case 'approval':
                        self::tab_approval($settings);
                        break;
                    case 'permissions':
                        self::tab_permissions($settings);
                        break;
                    case 'search':
                        self::tab_search($settings);
                        break;
                    case 'email':
                        self::tab_email($settings);
                        break;
                    default:
                        self::tab_general($settings);
                }
                ?>

                <?php submit_button(__('Save settings', 'enhanced-content-plugin')); ?>
            </form>
        </div>
        <?php
    }

    /* --------------------------------------------------------------------
     * Save
     * ----------------------------------------------------------------- */

    private static function handle_post() {
        check_admin_referer('ecp_save_settings', 'ecp_settings_nonce');

        if (!ECP_Capabilities::can_manage()) {
            wp_die(esc_html__('You do not have permission to change these settings.', 'enhanced-content-plugin'));
        }

        // A CSV upload is handled separately from the settings write.
        if (!empty($_FILES['ecp_csv']['tmp_name'])) {
            return self::handle_csv_upload();
        }

        // The site profile is its own record, not an agent setting — it
        // routes to ECP_Site_Profile, which owns its own sanitization.
        if ('profile' === (isset($_POST['ecp_tab']) ? sanitize_key(wp_unslash($_POST['ecp_tab'])) : '')) {
            $profile_input = array();

            foreach (array_keys(ECP_Site_Profile::fields()) as $key) {
                if (isset($_POST['ecp_pf_' . $key])) {
                    $profile_input[$key] = wp_unslash($_POST['ecp_pf_' . $key]);  // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized per-type in ECP_Site_Profile::update().
                }
            }

            ECP_Site_Profile::update($profile_input);

            return __('Site profile saved.', 'enhanced-content-plugin');
        }

        // Checkboxes only appear in POST when checked, so the set of keys we
        // consider has to come from the tab, not from what was submitted.
        $checkbox_keys = self::checkbox_keys(isset($_POST['ecp_tab']) ? sanitize_key(wp_unslash($_POST['ecp_tab'])) : 'general');

        $input = array();

        foreach ($_POST as $key => $value) {  // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
            if (0 !== strpos($key, 'ecp_') || in_array($key, array('ecp_settings_nonce', 'ecp_tab'), true)) {
                continue;
            }

            $field = substr($key, 4);
            $input[$field] = is_array($value) ? wp_unslash($value) : wp_unslash($value);
        }

        foreach ($checkbox_keys as $key) {
            if (!isset($input[$key])) {
                $input[$key] = 0;
            }
        }

        // Multi-checkbox groups: an empty group means "none", which only
        // registers if we say so explicitly.
        foreach (array('enabled_change_types', 'auto_apply_types', 'post_types') as $group) {
            if (in_array($group, self::group_keys(isset($_POST['ecp_tab']) ? sanitize_key(wp_unslash($_POST['ecp_tab'])) : 'general'), true)
                && !isset($input[$group])) {
                $input[$group] = array();
            }
        }

        ECP_Agent_Settings::update($input);

        return __('Settings saved.', 'enhanced-content-plugin');
    }

    /**
     * Checkboxes that exist on each tab.
     *
     * An unchecked box sends nothing, so these are forced to 0 on save. That
     * means every key listed here MUST have a rendered input on that tab —
     * list one that isn't rendered and it gets silently zeroed every time the
     * tab is saved, with no visible cause.
     */
    private static function checkbox_keys($tab) {
        $map = array(
            'general'  => array('agent_enabled', 'scan_enabled', 'analysis_enabled', 'require_published', 'refresh_enabled'),
            'writing'  => array('preserve_voice', 'require_source_for_claims', 'forbid_new_numbers', 'forbid_em_dashes'),
            'approval' => array('apply_as_draft'),
            'email'    => array('digest_enabled', 'notify_on_failure', 'debug_logging'),
            'search'   => array('clusters_enabled'),
            'permissions' => array(),
        );

        return isset($map[$tab]) ? $map[$tab] : array();
    }

    private static function group_keys($tab) {
        $map = array(
            'general'  => array('post_types', 'refresh_types'),
            'writing'  => array('enabled_change_types'),
            'approval' => array('auto_apply_types'),
        );

        return isset($map[$tab]) ? $map[$tab] : array();
    }

    private static function handle_csv_upload() {
        $file = $_FILES['ecp_csv'];  // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated below.

        if (!empty($file['error'])) {
            return __('The upload failed.', 'enhanced-content-plugin');
        }

        $check = wp_check_filetype($file['name'], array('csv' => 'text/csv', 'txt' => 'text/plain'));
        if (!$check['ext']) {
            return __('That is not a CSV file.', 'enhanced-content-plugin');
        }

        $result = ECP_Search_Data::import_csv($file['tmp_name']);

        if (is_wp_error($result)) {
            return $result->get_error_message();
        }

        return sprintf(
            /* translators: 1: rows imported, 2: rows matched to posts */
            __('%1$d rows imported, %2$d matched to pages on this site.', 'enhanced-content-plugin'),
            (int) $result['rows'],
            (int) $result['matched']
        );
    }

    /* --------------------------------------------------------------------
     * Tabs
     * ----------------------------------------------------------------- */

    private static function tab_general(array $s) {
        $key_source = ECP_Agent_Settings::api_key_source();
        ?>
        <h2 id="ecp-section-provider"><?php esc_html_e('Switch', 'enhanced-content-plugin'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('Run the agent', 'enhanced-content-plugin'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="ecp_agent_enabled" value="1" <?php checked($s['agent_enabled'], 1); ?>>
                        <?php esc_html_e('Yes — scan my content and propose improvements', 'enhanced-content-plugin'); ?>
                    </label>
                    <p class="description"><?php esc_html_e('Nothing is written to your site until you approve it, unless you turn on auto-apply under Approval.', 'enhanced-content-plugin'); ?></p>

                    <label class="ecp-nested">
                        <input type="checkbox" name="ecp_scan_enabled" value="1" <?php checked($s['scan_enabled'], 1); ?>>
                        <?php esc_html_e('Keep scoring pages on a schedule', 'enhanced-content-plugin'); ?>
                    </label>
                    <p class="description ecp-nested"><?php esc_html_e('Free — no AI calls. Turn off only if you want to freeze the Opportunities list where it is.', 'enhanced-content-plugin'); ?></p>

                    <label class="ecp-nested">
                        <input type="checkbox" name="ecp_analysis_enabled" value="1" <?php checked($s['analysis_enabled'], 1); ?>>
                        <?php esc_html_e('Let the schedule analyze pages and propose changes', 'enhanced-content-plugin'); ?>
                    </label>
                    <p class="description ecp-nested"><?php esc_html_e('This is the part that spends money. Turn it off to pause all automatic spending while still keeping scores up to date — you can still analyze individual pages by hand.', 'enhanced-content-plugin'); ?></p>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e('Quality & freshness', 'enhanced-content-plugin'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="ecp_gap_mode"><?php esc_html_e('Reader-question audit', 'enhanced-content-plugin'); ?></label></th>
                <td>
                    <select name="ecp_gap_mode" id="ecp_gap_mode">
                        <option value="propose" <?php selected($s['gap_mode'], 'propose'); ?>><?php esc_html_e('Audit and propose missing sections (needs your approval)', 'enhanced-content-plugin'); ?></option>
                        <option value="report" <?php selected($s['gap_mode'], 'report'); ?>><?php esc_html_e('Audit only — show me the gaps, write nothing', 'enhanced-content-plugin'); ?></option>
                        <option value="off" <?php selected($s['gap_mode'], 'off'); ?>><?php esc_html_e('Off — only when I click "Find content gaps"', 'enhanced-content-plugin'); ?></option>
                    </select>
                    <p class="description">
                        <?php esc_html_e('Works out the 8–12 things a reader arrives wanting answered, checks each against the article, and — in propose mode — drafts sections for up to 3 gaps per article. These are the biggest changes the agent ever suggests, which is why the mode is yours to choose; every one of them still waits for your approval either way. Runs on your best opportunities, one article per hour, within the same daily analysis limit.', 'enhanced-content-plugin'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="ecp_stale_refresh"><?php esc_html_e('Articles over a year old', 'enhanced-content-plugin'); ?></label></th>
                <td>
                    <select name="ecp_stale_refresh" id="ecp_stale_refresh">
                        <option value="light" <?php selected($s['stale_refresh'], 'light'); ?>><?php esc_html_e('Refresh gently — dated facts, titles, snippets, links; no restructuring', 'enhanced-content-plugin'); ?></option>
                        <option value="full" <?php selected($s['stale_refresh'], 'full'); ?>><?php esc_html_e('Treat them like any other page', 'enhanced-content-plugin'); ?></option>
                    </select>
                    <p class="description">
                        <?php esc_html_e('Gentle mode limits automatic proposals on year-old articles to refresh-sized changes: updating dated statements, titles and descriptions, tightening, links and image text. New sections and rewrites are not proposed automatically — analyze the page by hand when you want the full treatment. The reader-question audit still runs in report mode on these, so you can see what is missing without it being drafted.', 'enhanced-content-plugin'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Automatic refresh cycle', 'enhanced-content-plugin'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="ecp_refresh_enabled" value="1" <?php checked($s['refresh_enabled'], 1); ?>>
                        <?php esc_html_e('Keep aging articles fresh without asking me about each change', 'enhanced-content-plugin'); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e('Each night the agent works through your oldest articles, analyzes them, and applies small improvements from the list below on its own — after a waiting period during which each change sits in the review queue for you to veto. Silence is consent; anything factual, low-confidence or flagged still waits for you indefinitely. Every change creates a revision and can be undone from History. This is the only feature that publishes without a per-change click, which is why it is off until you turn it on.', 'enhanced-content-plugin'); ?>
                    </p>

                    <div class="ecp-refresh-options">
                        <p>
                            <label>
                                <?php esc_html_e('Articles older than', 'enhanced-content-plugin'); ?>
                                <input type="number" name="ecp_refresh_age_days" value="<?php echo esc_attr($s['refresh_age_days']); ?>" min="90" max="3650" step="1" class="small-text">
                                <?php esc_html_e('days qualify', 'enhanced-content-plugin'); ?>
                            </label>
                            &nbsp;·&nbsp;
                            <label>
                                <?php esc_html_e('revisit each at most every', 'enhanced-content-plugin'); ?>
                                <input type="number" name="ecp_refresh_interval_days" value="<?php echo esc_attr($s['refresh_interval_days']); ?>" min="30" max="730" step="1" class="small-text">
                                <?php esc_html_e('days', 'enhanced-content-plugin'); ?>
                            </label>
                            &nbsp;·&nbsp;
                            <label>
                                <input type="number" name="ecp_refresh_per_day" value="<?php echo esc_attr($s['refresh_per_day']); ?>" min="1" max="10" step="1" class="small-text">
                                <?php esc_html_e('articles per night', 'enhanced-content-plugin'); ?>
                            </label>
                        </p>
                        <p>
                            <label for="ecp_refresh_hold_hours"><?php esc_html_e('Hold each change for review for', 'enhanced-content-plugin'); ?></label>
                            <select name="ecp_refresh_hold_hours" id="ecp_refresh_hold_hours">
                                <option value="0" <?php selected((int) $s['refresh_hold_hours'], 0); ?>><?php esc_html_e('No hold — apply immediately', 'enhanced-content-plugin'); ?></option>
                                <option value="24" <?php selected((int) $s['refresh_hold_hours'], 24); ?>><?php esc_html_e('24 hours', 'enhanced-content-plugin'); ?></option>
                                <option value="48" <?php selected((int) $s['refresh_hold_hours'], 48); ?>><?php esc_html_e('48 hours', 'enhanced-content-plugin'); ?></option>
                                <option value="72" <?php selected((int) $s['refresh_hold_hours'], 72); ?>><?php esc_html_e('72 hours', 'enhanced-content-plugin'); ?></option>
                                <option value="168" <?php selected((int) $s['refresh_hold_hours'], 168); ?>><?php esc_html_e('One week', 'enhanced-content-plugin'); ?></option>
                            </select>
                        </p>
                        <p><strong><?php esc_html_e('Changes it may apply on its own:', 'enhanced-content-plugin'); ?></strong></p>
                        <?php
                        $refresh_selected = (array) $s['refresh_types'];

                        foreach (ECP_Analyzer::REFRESH_TYPES as $type) :
                            $info = ECP_Proposals::change_type($type);

                            if (!$info) {
                                continue;
                            }

                            // Sensitive types can be listed but never
                            // auto-apply; better not to offer them at all.
                            if (ECP_Proposals::RISK_SENSITIVE === $info['risk']) {
                                continue;
                            }
                            ?>
                            <label class="ecp-nested">
                                <input type="checkbox" name="ecp_refresh_types[]" value="<?php echo esc_attr($type); ?>" <?php checked(in_array($type, $refresh_selected, true)); ?>>
                                <?php echo esc_html($info['label']); ?>
                                <span class="ecp-muted">— <?php echo esc_html($info['help']); ?></span>
                            </label>
                        <?php endforeach; ?>
                        <p class="description">
                            <?php esc_html_e('Updating dated statements is deliberately not offered here: only a person can know what is currently true. Those findings are still proposed — they wait in the queue for you.', 'enhanced-content-plugin'); ?>
                        </p>
                    </div>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e('AI provider', 'enhanced-content-plugin'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="ecp_provider"><?php esc_html_e('Provider', 'enhanced-content-plugin'); ?></label></th>
                <td>
                    <select name="ecp_provider" id="ecp_provider">
                        <?php foreach (ECP_AI_Client::all_providers() as $provider) : ?>
                            <option value="<?php echo esc_attr($provider->slug()); ?>" <?php selected($s['provider'], $provider->slug()); ?>>
                                <?php echo esc_html($provider->label()); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="ecp_model"><?php esc_html_e('Model', 'enhanced-content-plugin'); ?></label></th>
                <td>
                    <select name="ecp_model" id="ecp_model">
                        <?php
                        foreach (ECP_AI_Client::all_providers() as $provider) {
                            foreach ($provider->models() as $id => $label) {
                                printf(
                                    '<option value="%s"%s>%s</option>',
                                    esc_attr($id),
                                    selected($s['model'], $id, false),
                                    esc_html($label)
                                );
                            }
                        }
                        ?>
                    </select>
                    <p class="description"><?php esc_html_e('The prices shown are the provider\'s per-million-token rates. A single article analysis typically runs a few cents.', 'enhanced-content-plugin'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="ecp_effort"><?php esc_html_e('Effort', 'enhanced-content-plugin'); ?></label></th>
                <td>
                    <select name="ecp_effort" id="ecp_effort">
                        <?php foreach (ECP_Provider_Anthropic::effort_levels() as $level => $label) : ?>
                            <option value="<?php echo esc_attr($level); ?>" <?php selected($s['effort'], $level); ?>>
                                <?php echo esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description"><?php esc_html_e('How hard the model thinks before answering. This is the main cost and speed lever — lower settings are cheaper and faster, and often good enough. Anthropic models only.', 'enhanced-content-plugin'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="ecp_api_key"><?php esc_html_e('API key', 'enhanced-content-plugin'); ?></label></th>
                <td>
                    <?php if ('constant' === $key_source) : ?>
                        <p><strong><?php esc_html_e('Set in wp-config.php.', 'enhanced-content-plugin'); ?></strong>
                        <?php esc_html_e('That is the safest place for it — the key never touches the database or a database backup. Remove the ECP_AI_API_KEY constant if you would rather manage it here.', 'enhanced-content-plugin'); ?></p>
                    <?php else : ?>
                        <input type="password" name="ecp_api_key" id="ecp_api_key" class="regular-text"
                               autocomplete="off"
                               value="<?php echo 'database' === $key_source ? esc_attr(ECP_Agent_Settings::mask()) : ''; ?>">
                        <p class="description">
                            <?php esc_html_e('Stored encrypted with your site\'s salts. Better still, put it in wp-config.php and keep it out of the database entirely:', 'enhanced-content-plugin'); ?>
                            <code>define('ECP_AI_API_KEY', 'sk-…');</code>
                        </p>
                    <?php endif; ?>

                    <p>
                        <button type="button" class="button" id="ecp-test-provider"><?php esc_html_e('Test the connection', 'enhanced-content-plugin'); ?></button>
                        <span id="ecp-test-result" aria-live="polite"></span>
                    </p>
                    <p class="description">
                        <?php esc_html_e('Tests whatever is in the box above — you do not have to save first.', 'enhanced-content-plugin'); ?>
                        <?php
                        printf(
                            /* translators: %s: plugin version number */
                            esc_html__('(Plugin version %s. If the test says no key reached the server, an old cached copy of the plugin JavaScript is running.)', 'enhanced-content-plugin'),
                            esc_html(ECP_VERSION)
                        );
                        ?>
                    </p>
                </td>
            </tr>
            <tr class="ecp-rankaudit-row">
                <th scope="row"><?php esc_html_e('RankAudit connection', 'enhanced-content-plugin'); ?></th>
                <td>
                    <input type="url" name="ecp_rankaudit_endpoint" class="regular-text"
                           value="<?php echo esc_attr($s['rankaudit_endpoint']); ?>"
                           placeholder="https://api.rankaudit.com">
                    <p class="description"><?php esc_html_e('Endpoint. Only used when the provider above is set to RankAudit.', 'enhanced-content-plugin'); ?></p>
                    <input type="text" name="ecp_rankaudit_site_token" class="regular-text"
                           value="<?php echo esc_attr($s['rankaudit_site_token']); ?>"
                           placeholder="<?php esc_attr_e('Site token', 'enhanced-content-plugin'); ?>">
                </td>
            </tr>
        </table>

        <h2 id="ecp-section-budget"><?php esc_html_e('Spending limits', 'enhanced-content-plugin'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="ecp_monthly_budget_usd"><?php esc_html_e('Monthly cap', 'enhanced-content-plugin'); ?></label></th>
                <td>
                    $<input type="number" name="ecp_monthly_budget_usd" id="ecp_monthly_budget_usd"
                            value="<?php echo esc_attr($s['monthly_budget_usd']); ?>" min="0" step="1" class="small-text">
                    <p class="description"><?php esc_html_e('A hard stop, not a warning. The agent makes no further AI calls once it reaches this. Set 0 for no cap.', 'enhanced-content-plugin'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="ecp_max_analyses_per_day"><?php esc_html_e('Analyses per day', 'enhanced-content-plugin'); ?></label></th>
                <td>
                    <input type="number" name="ecp_max_analyses_per_day" id="ecp_max_analyses_per_day"
                           value="<?php echo esc_attr($s['max_analyses_per_day']); ?>" min="0" step="1" class="small-text">
                    <p class="description"><?php esc_html_e('Also controls how fast your review queue fills up. Ten a day is a queue you can actually keep on top of.', 'enhanced-content-plugin'); ?></p>
                </td>
            </tr>
        </table>

        <h2 id="ecp-section-scope"><?php esc_html_e('What the agent may touch', 'enhanced-content-plugin'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('Post types', 'enhanced-content-plugin'); ?></th>
                <td>
                    <?php
                    $post_types = get_post_types(array('public' => true), 'objects');
                    foreach ($post_types as $post_type) {
                        if ('attachment' === $post_type->name) {
                            continue;
                        }
                        printf(
                            '<label class="ecp-inline-check"><input type="checkbox" name="ecp_post_types[]" value="%s"%s> %s</label>',
                            esc_attr($post_type->name),
                            in_array($post_type->name, (array) $s['post_types'], true) ? ' checked' : '',
                            esc_html($post_type->labels->name)
                        );
                    }
                    ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="ecp_min_post_age_days"><?php esc_html_e('Leave new posts alone for', 'enhanced-content-plugin'); ?></label></th>
                <td>
                    <input type="number" name="ecp_min_post_age_days" id="ecp_min_post_age_days"
                           value="<?php echo esc_attr($s['min_post_age_days']); ?>" min="0" class="small-text">
                    <?php esc_html_e('days', 'enhanced-content-plugin'); ?>
                    <p class="description"><?php esc_html_e('Stops the agent second-guessing something you published this morning.', 'enhanced-content-plugin'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="ecp_excluded_post_ids"><?php esc_html_e('Never touch these', 'enhanced-content-plugin'); ?></label></th>
                <td>
                    <input type="text" name="ecp_excluded_post_ids" id="ecp_excluded_post_ids" class="regular-text"
                           value="<?php echo esc_attr($s['excluded_post_ids']); ?>" placeholder="12, 45, 108">
                    <p class="description"><?php esc_html_e('Post IDs, comma-separated. Use for legal pages, landing pages, anything hand-tuned.', 'enhanced-content-plugin'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Published only', 'enhanced-content-plugin'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="ecp_require_published" value="1" <?php checked($s['require_published'], 1); ?>>
                        <?php esc_html_e('Ignore drafts and private posts', 'enhanced-content-plugin'); ?>
                    </label>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * The site profile: who this business is, in their own words.
     *
     * Driven entirely by the field registry so a field added there appears
     * here with no screen work. No agent settings on this tab — it posts to
     * ECP_Site_Profile through its own handle_post() branch.
     */
    private static function tab_profile() {
        $profile = ECP_Site_Profile::all();
        $completeness = ECP_Site_Profile::completeness();
        ?>
        <h2><?php esc_html_e('Site profile', 'enhanced-content-plugin'); ?></h2>
        <p class="description" style="max-width:720px;">
            <?php esc_html_e('The agent reads this before making any strategic judgement: which topics are worth covering, which are out of bounds, and who the writing is for. Plain, specific sentences work best — this is context for the AI, not marketing copy.', 'enhanced-content-plugin'); ?>
        </p>

        <p>
            <span class="ecp-dot <?php echo $completeness >= 100 ? 'ecp-dot-on' : 'ecp-dot-warn'; ?>"></span>
            <?php
            printf(
                /* translators: %d: percentage */
                esc_html__('%d%% complete. The core fields (name, purpose, offerings, audience, conversions, topics) unlock the growth features.', 'enhanced-content-plugin'),
                (int) $completeness
            );
            ?>
        </p>

        <table class="form-table" role="presentation">
            <?php foreach (ECP_Site_Profile::fields() as $key => $field) : ?>
                <tr>
                    <th scope="row">
                        <label for="ecp_pf_<?php echo esc_attr($key); ?>"><?php echo esc_html($field['label']); ?></label>
                    </th>
                    <td>
                        <?php if ('textarea' === $field['type']) : ?>
                            <textarea name="ecp_pf_<?php echo esc_attr($key); ?>" id="ecp_pf_<?php echo esc_attr($key); ?>"
                                      rows="3" class="large-text"><?php echo esc_textarea($profile[$key]); ?></textarea>
                        <?php elseif ('list' === $field['type']) : ?>
                            <textarea name="ecp_pf_<?php echo esc_attr($key); ?>" id="ecp_pf_<?php echo esc_attr($key); ?>"
                                      rows="4" class="large-text"><?php echo esc_textarea(implode("\n", (array) $profile[$key])); ?></textarea>
                        <?php elseif ('number' === $field['type']) : ?>
                            <input type="number" name="ecp_pf_<?php echo esc_attr($key); ?>" id="ecp_pf_<?php echo esc_attr($key); ?>"
                                   value="<?php echo esc_attr($profile[$key]); ?>" min="0" step="1" class="small-text">
                        <?php else : ?>
                            <input type="text" name="ecp_pf_<?php echo esc_attr($key); ?>" id="ecp_pf_<?php echo esc_attr($key); ?>"
                                   value="<?php echo esc_attr($profile[$key]); ?>" class="regular-text">
                        <?php endif; ?>

                        <?php if ($field['help']) : ?>
                            <p class="description"><?php echo esc_html($field['help']); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
        <?php
    }

    private static function tab_writing(array $s) {
        ?>
        <h2><?php esc_html_e('What the agent may propose', 'enhanced-content-plugin'); ?></h2>
        <p class="description"><?php esc_html_e('Switch off anything you would rather handle yourself. The agent will not suggest it at all.', 'enhanced-content-plugin'); ?></p>

        <table class="widefat striped ecp-type-table">
            <thead>
                <tr>
                    <th style="width:30px;"></th>
                    <th><?php esc_html_e('Change', 'enhanced-content-plugin'); ?></th>
                    <th><?php esc_html_e('What it does', 'enhanced-content-plugin'); ?></th>
                    <th style="width:120px;"><?php esc_html_e('Risk', 'enhanced-content-plugin'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (ECP_Proposals::change_types() as $slug => $info) : ?>
                    <tr>
                        <td>
                            <input type="checkbox" name="ecp_enabled_change_types[]" value="<?php echo esc_attr($slug); ?>"
                                   id="ecp-type-<?php echo esc_attr($slug); ?>"
                                   <?php checked(in_array($slug, (array) $s['enabled_change_types'], true)); ?>>
                        </td>
                        <td><label for="ecp-type-<?php echo esc_attr($slug); ?>"><strong><?php echo esc_html($info['label']); ?></strong></label></td>
                        <td class="ecp-muted"><?php echo esc_html($info['help']); ?></td>
                        <td>
                            <span class="ecp-badge ecp-badge-<?php echo esc_attr($info['risk']); ?>">
                                <?php echo esc_html(ECP_Proposals::risk_label($info['risk'])); ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h2><?php esc_html_e('House rules', 'enhanced-content-plugin'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="ecp_brand_terms"><?php esc_html_e('Brand terms', 'enhanced-content-plugin'); ?></label></th>
                <td>
                    <textarea name="ecp_brand_terms" id="ecp_brand_terms" rows="4" class="large-text code"><?php echo esc_textarea($s['brand_terms']); ?></textarea>
                    <p class="description"><?php esc_html_e('One per line, spelled exactly as they should appear. Any change that alters the casing or spacing gets flagged for you to check.', 'enhanced-content-plugin'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="ecp_banned_phrases"><?php esc_html_e('Never write these', 'enhanced-content-plugin'); ?></label></th>
                <td>
                    <textarea name="ecp_banned_phrases" id="ecp_banned_phrases" rows="8" class="large-text code"><?php echo esc_textarea($s['banned_phrases']); ?></textarea>
                    <p class="description"><?php esc_html_e('One per line. Any proposal containing one is thrown away before you see it. The defaults are the usual AI tells.', 'enhanced-content-plugin'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="ecp_tone_notes"><?php esc_html_e('How we write', 'enhanced-content-plugin'); ?></label></th>
                <td>
                    <textarea name="ecp_tone_notes" id="ecp_tone_notes" rows="5" class="large-text"
                              placeholder="<?php esc_attr_e('e.g. Second person. Short paragraphs. British spelling. Never use exclamation marks. Assume the reader is a professional installer, not a homeowner.', 'enhanced-content-plugin'); ?>"><?php echo esc_textarea($s['tone_notes']); ?></textarea>
                    <p class="description"><?php esc_html_e('Passed to the model with every request. The single highest-leverage box on this page — be specific.', 'enhanced-content-plugin'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="ecp_reading_level"><?php esc_html_e('Reading level', 'enhanced-content-plugin'); ?></label></th>
                <td>
                    <select name="ecp_reading_level" id="ecp_reading_level">
                        <option value="match" <?php selected($s['reading_level'], 'match'); ?>><?php esc_html_e('Match the existing writing', 'enhanced-content-plugin'); ?></option>
                        <option value="simpler" <?php selected($s['reading_level'], 'simpler'); ?>><?php esc_html_e('Simplify where possible', 'enhanced-content-plugin'); ?></option>
                        <option value="technical" <?php selected($s['reading_level'], 'technical'); ?>><?php esc_html_e('Keep it technical', 'enhanced-content-plugin'); ?></option>
                    </select>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e('Factual safety', 'enhanced-content-plugin'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('Numbers and dates', 'enhanced-content-plugin'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="ecp_forbid_new_numbers" value="1" <?php checked($s['forbid_new_numbers'], 1); ?>>
                        <?php esc_html_e('Flag any statistic, date, price or measurement that is not already on the page', 'enhanced-content-plugin'); ?>
                    </label>
                    <p class="description"><?php esc_html_e('This is the check that catches invented facts. Leave it on.', 'enhanced-content-plugin'); ?></p>

                    <label class="ecp-nested">
                        <input type="checkbox" name="ecp_require_source_for_claims" value="1" <?php checked($s['require_source_for_claims'], 1); ?>>
                        <?php esc_html_e('And throw the change away entirely if the agent did not flag them itself', 'enhanced-content-plugin'); ?>
                    </label>
                    <p class="description ecp-nested"><?php esc_html_e('Stricter. A change that quietly introduces a number never reaches your queue.', 'enhanced-content-plugin'); ?></p>

                    <label>
                        <input type="checkbox" name="ecp_forbid_em_dashes" value="1" <?php checked($s['forbid_em_dashes'], 1); ?>>
                        <?php esc_html_e('No em dashes in anything the agent writes', 'enhanced-content-plugin'); ?>
                    </label>
                    <p class="description"><?php esc_html_e('The em dash (—) has become the single loudest "an AI wrote this" tell. The agent is told not to use them, and any that slip through anyway are converted to a comma or period before the change reaches your queue. Number ranges like 2019–2024 keep their dash.', 'enhanced-content-plugin'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="ecp_max_change_percent"><?php esc_html_e('Biggest single change', 'enhanced-content-plugin'); ?></label></th>
                <td>
                    <input type="number" name="ecp_max_change_percent" id="ecp_max_change_percent"
                           value="<?php echo esc_attr($s['max_change_percent']); ?>" min="5" max="100" class="small-text">%
                    <p class="description"><?php esc_html_e('The most of one article a single change may rewrite. Keeps changes small enough to actually review.', 'enhanced-content-plugin'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Voice', 'enhanced-content-plugin'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="ecp_preserve_voice" value="1" <?php checked($s['preserve_voice'], 1); ?>>
                        <?php esc_html_e('Tell the agent to match the existing author\'s voice', 'enhanced-content-plugin'); ?>
                    </label>
                </td>
            </tr>
        </table>
        <?php
    }

    private static function tab_approval(array $s) {
        ?>
        <h2 id="ecp-section-approval"><?php esc_html_e('Who approves changes', 'enhanced-content-plugin'); ?></h2>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('Approval', 'enhanced-content-plugin'); ?></th>
                <td>
                    <fieldset>
                        <label class="ecp-radio-card">
                            <input type="radio" name="ecp_approval_mode" value="always" <?php checked($s['approval_mode'], 'always'); ?>>
                            <span>
                                <strong><?php esc_html_e('I approve everything', 'enhanced-content-plugin'); ?></strong>
                                <em><?php esc_html_e('Nothing is written to the site without a click from a human. This is the right setting for almost everyone.', 'enhanced-content-plugin'); ?></em>
                            </span>
                        </label>

                        <label class="ecp-radio-card">
                            <input type="radio" name="ecp_approval_mode" value="safe" <?php checked($s['approval_mode'], 'safe'); ?>>
                            <span>
                                <strong><?php esc_html_e('Apply safe changes automatically', 'enhanced-content-plugin'); ?></strong>
                                <em><?php esc_html_e('Alt text, meta descriptions and similar go straight in when the agent is over 85% confident. Everything else still waits for you.', 'enhanced-content-plugin'); ?></em>
                            </span>
                        </label>

                        <label class="ecp-radio-card">
                            <input type="radio" name="ecp_approval_mode" value="trusted" <?php checked($s['approval_mode'], 'trusted'); ?>>
                            <span>
                                <strong><?php esc_html_e('Apply change types I have come to trust', 'enhanced-content-plugin'); ?></strong>
                                <em><?php esc_html_e('Only the types ticked below, and only once they have a proven record on this site. One rollback switches a type straight back off.', 'enhanced-content-plugin'); ?></em>
                            </span>
                        </label>
                    </fieldset>

                    <p class="description ecp-note">
                        <?php esc_html_e('Whatever you choose, a change that contains an unverified claim, an invented number, or an altered brand term is never applied automatically.', 'enhanced-content-plugin'); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e('Trusted change types', 'enhanced-content-plugin'); ?></th>
                <td>
                    <table class="widefat striped ecp-type-table">
                        <tbody>
                            <?php foreach (ECP_Proposals::change_types() as $slug => $info) : ?>
                                <?php $stats = ECP_Trust_Ladder::stats($slug); ?>
                                <tr>
                                    <td style="width:30px;">
                                        <input type="checkbox" name="ecp_auto_apply_types[]" value="<?php echo esc_attr($slug); ?>"
                                               id="ecp-auto-<?php echo esc_attr($slug); ?>"
                                               <?php checked($stats['auto_on']); ?>
                                               <?php disabled(!$stats['eligible'] && !$stats['auto_on']); ?>>
                                    </td>
                                    <td><label for="ecp-auto-<?php echo esc_attr($slug); ?>"><?php echo esc_html($info['label']); ?></label></td>
                                    <td class="ecp-muted"><?php echo esc_html(ECP_Trust_Ladder::progress_note($slug)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p class="description"><?php esc_html_e('A type unlocks after 20 reviews at a 90% approval rate with no rollbacks. That threshold is per-site — it measures how well the agent does on your content, not on anyone else\'s.', 'enhanced-content-plugin'); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php esc_html_e('Where changes land', 'enhanced-content-plugin'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="ecp_apply_as_draft" value="1" <?php checked($s['apply_as_draft'], 1); ?>>
                        <?php esc_html_e('Save approved changes as a draft revision instead of publishing them', 'enhanced-content-plugin'); ?>
                    </label>
                    <p class="description"><?php esc_html_e('Adds a second gate: approve here, then publish from the post editor. Only applies to body-content changes — metadata has no draft state in WordPress.', 'enhanced-content-plugin'); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row"><label for="ecp_proposal_ttl_days"><?php esc_html_e('Expire unreviewed changes after', 'enhanced-content-plugin'); ?></label></th>
                <td>
                    <input type="number" name="ecp_proposal_ttl_days" id="ecp_proposal_ttl_days"
                           value="<?php echo esc_attr($s['proposal_ttl_days']); ?>" min="1" max="365" class="small-text">
                    <?php esc_html_e('days', 'enhanced-content-plugin'); ?>
                    <p class="description"><?php esc_html_e('An old proposal was written against an old version of the page. Better to let it lapse and re-analyze.', 'enhanced-content-plugin'); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    private static function tab_permissions(array $s) {
        $map = ECP_Capabilities::role_map();
        $levels = ECP_Capabilities::levels();

        ?>
        <h2><?php esc_html_e('Who can approve changes', 'enhanced-content-plugin'); ?></h2>

        <p class="description" style="max-width:70ch;">
            <?php esc_html_e('Approving a change writes to your live site, so this is worth getting right before you invite anyone in. Two levels are worth knowing about: "can look, cannot approve" is for a client or stakeholder who wants visibility without the button, and "own posts" defers to WordPress — an author sees changes to their own articles and nothing else.', 'enhanced-content-plugin'); ?>
        </p>

        <table class="widefat striped ecp-type-table" style="max-width:820px;">
            <thead>
                <tr>
                    <th><?php esc_html_e('Role', 'enhanced-content-plugin'); ?></th>
                    <th style="width:340px;"><?php esc_html_e('Agent access', 'enhanced-content-plugin'); ?></th>
                    <th><?php esc_html_e('People', 'enhanced-content-plugin'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (ECP_Capabilities::all_roles() as $slug => $name) : ?>
                    <?php
                    $is_admin_role = 'administrator' === $slug;
                    $count = count(get_users(array('role' => $slug, 'fields' => 'ID', 'number' => 200)));
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html(translate_user_role($name)); ?></strong>
                        </td>
                        <td>
                            <?php if ($is_admin_role) : ?>
                                <em><?php echo esc_html(ECP_Capabilities::level_label(ECP_Capabilities::LEVEL_MANAGE)); ?></em>
                                <p class="description">
                                    <?php esc_html_e('Cannot be reduced — anyone who can install plugins can reach these settings anyway, and pretending otherwise would be security theatre.', 'enhanced-content-plugin'); ?>
                                </p>
                                <input type="hidden" name="ecp_role_access[administrator]" value="<?php echo esc_attr(ECP_Capabilities::LEVEL_MANAGE); ?>">
                            <?php else : ?>
                                <label class="screen-reader-text" for="ecp-role-<?php echo esc_attr($slug); ?>">
                                    <?php
                                    printf(
                                        /* translators: %s: role name */
                                        esc_html__('Agent access for %s', 'enhanced-content-plugin'),
                                        esc_html(translate_user_role($name))
                                    );
                                    ?>
                                </label>
                                <select name="ecp_role_access[<?php echo esc_attr($slug); ?>]" id="ecp-role-<?php echo esc_attr($slug); ?>">
                                    <?php foreach ($levels as $level => $label) : ?>
                                        <option value="<?php echo esc_attr($level); ?>" <?php selected(isset($map[$slug]) ? $map[$slug] : ECP_Capabilities::LEVEL_NONE, $level); ?>>
                                            <?php echo esc_html($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        </td>
                        <td class="ecp-muted">
                            <?php
                            printf(
                                /* translators: %d: number of users with this role */
                                esc_html(_n('%d user', '%d users', $count, 'enhanced-content-plugin')),
                                (int) $count
                            );
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h2><?php esc_html_e('What each level means', 'enhanced-content-plugin'); ?></h2>
        <table class="widefat striped" style="max-width:820px;">
            <thead>
                <tr>
                    <th style="width:220px;"><?php esc_html_e('Level', 'enhanced-content-plugin'); ?></th>
                    <th><?php esc_html_e('Can', 'enhanced-content-plugin'); ?></th>
                    <th><?php esc_html_e('Cannot', 'enhanced-content-plugin'); ?></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong><?php esc_html_e('No access', 'enhanced-content-plugin'); ?></strong></td>
                    <td class="ecp-muted"><?php esc_html_e('Nothing — the menu is hidden entirely.', 'enhanced-content-plugin'); ?></td>
                    <td class="ecp-muted">—</td>
                </tr>
                <tr>
                    <td><strong><?php esc_html_e('Look, not approve', 'enhanced-content-plugin'); ?></strong></td>
                    <td><?php esc_html_e('Read the queue, the diffs, the reasoning and the history.', 'enhanced-content-plugin'); ?></td>
                    <td class="ecp-muted"><?php esc_html_e('Apply, reject, edit, run an analysis, or spend budget.', 'enhanced-content-plugin'); ?></td>
                </tr>
                <tr>
                    <td><strong><?php esc_html_e('Own posts', 'enhanced-content-plugin'); ?></strong></td>
                    <td><?php esc_html_e('Everything above, plus approving and rolling back changes to posts they wrote.', 'enhanced-content-plugin'); ?></td>
                    <td class="ecp-muted"><?php esc_html_e('See or touch anyone else\'s posts. Those are filtered out of the queue entirely.', 'enhanced-content-plugin'); ?></td>
                </tr>
                <tr>
                    <td><strong><?php esc_html_e('Any change', 'enhanced-content-plugin'); ?></strong></td>
                    <td><?php esc_html_e('Approve, reject, edit and roll back anything on the site, and run analyses.', 'enhanced-content-plugin'); ?></td>
                    <td class="ecp-muted"><?php esc_html_e('Change settings, see or set the API key, or turn on auto-apply.', 'enhanced-content-plugin'); ?></td>
                </tr>
                <tr>
                    <td><strong><?php esc_html_e('Full control', 'enhanced-content-plugin'); ?></strong></td>
                    <td><?php esc_html_e('All of the above, plus settings, spending limits, autopilot and the API key.', 'enhanced-content-plugin'); ?></td>
                    <td class="ecp-muted">—</td>
                </tr>
            </tbody>
        </table>

        <p class="description ecp-note" style="max-width:820px;">
            <?php esc_html_e('WordPress has the final say. Someone set to "any change" still cannot approve a change to a post their WordPress role does not let them edit — this setting can narrow what people reach, never widen it.', 'enhanced-content-plugin'); ?>
        </p>

        <?php unset($s); ?>
        <?php
    }

    private static function tab_search(array $s) {
        $status = ECP_Search_Data::status();
        ?>
        <h2><?php esc_html_e('Search Console', 'enhanced-content-plugin'); ?></h2>

        <div class="ecp-panel ecp-panel-inline">
            <p>
                <span class="ecp-dot <?php echo $status['connected'] ? 'ecp-dot-on' : 'ecp-dot-off'; ?>"></span>
                <strong><?php echo esc_html($status['label']); ?></strong>
            </p>
            <p class="ecp-muted"><?php echo esc_html($status['detail']); ?></p>

            <?php if ('sitekit' === $status['source']) : ?>
                <p>
                    <button type="button" class="button" id="ecp-sync-search">
                        <?php esc_html_e('Sync from Search Console now', 'enhanced-content-plugin'); ?>
                    </button>
                    <button type="button" class="button" id="ecp-repair-search">
                        <?php esc_html_e('Repair stored periods', 'enhanced-content-plugin'); ?>
                    </button>
                    <span id="ecp-sync-result" aria-live="polite"></span>
                </p>
                <p class="description">
                    <?php esc_html_e('Repair rebuilds the metrics table, discards any rows tagged with a reporting period the Rankings screen cannot display, and fetches everything again. Use it if the check below reports a problem, or if a period shows zero while the total row count is not zero.', 'enhanced-content-plugin'); ?>
                </p>
                <p class="description">
                    <?php esc_html_e('Otherwise this happens once a night. Google\'s data lags about two days, so the most recent 48 hours will always be missing — that is Google, not this plugin.', 'enhanced-content-plugin'); ?>
                </p>
            <?php elseif (!ECP_Search_Data::site_kit_installed()) : ?>
                <p class="description">
                    <?php
                    printf(
                        /* translators: %s: link to the plugin installer, already escaped */
                        esc_html__('The easiest route is %s: install it, sign in with the Google account that owns your Search Console property, and this plugin picks it up automatically — no API project of your own to create.', 'enhanced-content-plugin'),
                        '<a href="' . esc_url(admin_url('plugin-install.php?s=Site+Kit+by+Google&tab=search&type=term')) . '">' . esc_html__('Site Kit by Google', 'enhanced-content-plugin') . '</a>'
                    );
                    ?>
                </p>
            <?php endif; ?>
        </div>

        <?php if (ECP_Search_Data::site_kit_installed()) : ?>
            <h3><?php esc_html_e('Site Kit connection check', 'enhanced-content-plugin'); ?></h3>
            <table class="widefat striped ecp-diagnostics" style="max-width:820px;">
                <tbody>
                    <?php foreach (ECP_Search_Data::site_kit_diagnostics() as $check) : ?>
                        <tr>
                            <td style="width:30px;">
                                <span class="ecp-dot <?php echo $check['ok'] ? 'ecp-dot-on' : 'ecp-dot-warn'; ?>"></span>
                            </td>
                            <td style="width:250px;"><strong><?php echo esc_html($check['label']); ?></strong></td>
                            <td class="ecp-muted">
                                <?php echo esc_html($check['detail']); ?>
                                <?php
                                // Every failing check carries the thing that
                                // fixes it. Telling someone what is wrong and
                                // leaving them to find the control is how a
                                // diagnostic turns into another chore.
                                if (!$check['ok'] && !empty($check['action'])) {
                                    self::render_check_action($check['action']);
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php
        $breakdown = ECP_Search_Data::stored_breakdown();

        if ($breakdown) :
            ?>
            <h3><?php esc_html_e('What is actually stored', 'enhanced-content-plugin'); ?></h3>
            <p class="description">
                <?php esc_html_e('Straight from the database, with nothing summarised away. If a period you can select on the Rankings screen is missing from this list, that period has no data — whatever the total row count says.', 'enhanced-content-plugin'); ?>
            </p>
            <table class="widefat striped" style="max-width:820px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Period', 'enhanced-content-plugin'); ?></th>
                        <th><?php esc_html_e('Data through', 'enhanced-content-plugin'); ?></th>
                        <th><?php esc_html_e('Rows', 'enhanced-content-plugin'); ?></th>
                        <th><?php esc_html_e('Pages', 'enhanced-content-plugin'); ?></th>
                        <th><?php esc_html_e('Search terms', 'enhanced-content-plugin'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($breakdown as $line) : ?>
                        <tr>
                            <td>
                                <?php if ($line['readable']) : ?>
                                    <?php echo esc_html(ECP_Search_Data::window_label($line['window_days'])); ?>
                                <?php else : ?>
                                    <strong><?php esc_html_e('Not displayable', 'enhanced-content-plugin'); ?></strong>
                                    <span class="ecp-muted">
                                        <?php
                                        printf(
                                            /* translators: %d: raw window_days value */
                                            esc_html__('(stored as %d)', 'enhanced-content-plugin'),
                                            (int) $line['window_days']
                                        );
                                        ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($line['metric_date']); ?></td>
                            <td><?php echo esc_html(number_format_i18n($line['rows'])); ?></td>
                            <td><?php echo esc_html(number_format_i18n($line['pages'])); ?></td>
                            <td><?php echo esc_html(number_format_i18n($line['queries'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <p class="description">
            <?php esc_html_e('Query data is what turns "this page is thin" into "this page ranks eleventh for something 4,000 people search for". It is also the only way to measure whether a published change actually helped.', 'enhanced-content-plugin'); ?>
        </p>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="ecp_search_source"><?php esc_html_e('Where to get it', 'enhanced-content-plugin'); ?></label></th>
                <td>
                    <select name="ecp_search_source" id="ecp_search_source">
                        <option value="auto" <?php selected($s['search_source'], 'auto'); ?>><?php esc_html_e('Work it out automatically', 'enhanced-content-plugin'); ?></option>
                        <option value="sitekit" <?php selected($s['search_source'], 'sitekit'); ?>><?php esc_html_e('Google Site Kit', 'enhanced-content-plugin'); ?></option>
                        <option value="csv" <?php selected($s['search_source'], 'csv'); ?>><?php esc_html_e('CSV I upload myself', 'enhanced-content-plugin'); ?></option>
                        <option value="none" <?php selected($s['search_source'], 'none'); ?>><?php esc_html_e('Do not use search data', 'enhanced-content-plugin'); ?></option>
                    </select>
                    <p class="description">
                        <?php
                        if (ECP_Search_Data::site_kit_available()) {
                            esc_html_e('Google Site Kit is installed and connected, so no extra setup is needed — this plugin reads Search Console through it.', 'enhanced-content-plugin');
                        } else {
                            esc_html_e('The easiest route is Google Site Kit: install it, connect Search Console, and this plugin picks it up. No API project of your own to create.', 'enhanced-content-plugin');
                        }
                        ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="ecp_csv"><?php esc_html_e('Or upload an export', 'enhanced-content-plugin'); ?></label></th>
                <td>
                    <input type="file" name="ecp_csv" id="ecp_csv" accept=".csv,text/csv">
                    <p class="description">
                        <?php esc_html_e('In Search Console, open Performance, then Export → CSV, and upload the Pages file here. Columns are matched by name, so a localised export works too.', 'enhanced-content-plugin'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="ecp_metrics_retention_days"><?php esc_html_e('Keep metrics for', 'enhanced-content-plugin'); ?></label></th>
                <td>
                    <input type="number" name="ecp_metrics_retention_days" id="ecp_metrics_retention_days"
                           value="<?php echo esc_attr($s['metrics_retention_days']); ?>" min="30" class="small-text">
                    <?php esc_html_e('days', 'enhanced-content-plugin'); ?>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e('Competing pages', 'enhanced-content-plugin'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('Cross-page analysis', 'enhanced-content-plugin'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="ecp_clusters_enabled" value="1" <?php checked($s['clusters_enabled'], 1); ?>>
                        <?php esc_html_e('Look for pages competing with each other for the same searches', 'enhanced-content-plugin'); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e('Detection is free and runs nightly. It works properly only with Search Console data — without it, the fallback compares page titles, which finds fewer real conflicts and more false ones.', 'enhanced-content-plugin'); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * The control that resolves a failing connection check, inline.
     *
     * @param array $action { type: button|link|picker, ... }
     */
    private static function render_check_action(array $action) {
        $type = isset($action['type']) ? $action['type'] : '';

        if ('link' === $type) {
            printf(
                ' <a class="button button-small" href="%s">%s</a>',
                esc_url($action['href']),
                esc_html($action['label'])
            );

            return;
        }

        if ('button' === $type) {
            printf(
                ' <button type="button" class="button button-small ecp-fix" data-action="%s">%s</button>'
                    . '<span class="ecp-fix-result" aria-live="polite"></span>',
                esc_attr($action['action']),
                esc_html($action['label'])
            );

            return;
        }

        if ('picker' !== $type) {
            return;
        }

        $holders = ECP_Search_Data::google_token_holders();
        $current = (int) get_option('ecp_sitekit_user', 0);
        ?>
        <span class="ecp-check-action">
            <select id="ecp-sitekit-user">
                <option value="0"><?php esc_html_e('Choose an account…', 'enhanced-content-plugin'); ?></option>
                <?php foreach ($holders as $user_id) : ?>
                    <?php $user = get_userdata($user_id); ?>
                    <?php if ($user) : ?>
                        <option value="<?php echo esc_attr($user_id); ?>" <?php selected($current, $user_id); ?>>
                            <?php echo esc_html($user->display_name . ' (' . $user->user_email . ')'); ?>
                        </option>
                    <?php endif; ?>
                <?php endforeach; ?>
            </select>
            <button type="button" class="button button-small" id="ecp-save-sitekit-user">
                <?php esc_html_e('Use this account', 'enhanced-content-plugin'); ?>
            </button>
            <span id="ecp-sitekit-user-result" aria-live="polite"></span>
        </span>
        <?php
    }

    private static function tab_email(array $s) {
        ?>
        <h2><?php esc_html_e('Digest email', 'enhanced-content-plugin'); ?></h2>
        <p class="description"><?php esc_html_e('An approval queue only works if someone comes back to it. This is the nudge.', 'enhanced-content-plugin'); ?></p>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('Send it', 'enhanced-content-plugin'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="ecp_digest_enabled" value="1" <?php checked($s['digest_enabled'], 1); ?>>
                        <?php esc_html_e('Email me what is waiting', 'enhanced-content-plugin'); ?>
                    </label>
                    <p class="description"><?php esc_html_e('Nothing is sent when there is nothing to say.', 'enhanced-content-plugin'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="ecp_digest_frequency"><?php esc_html_e('How often', 'enhanced-content-plugin'); ?></label></th>
                <td>
                    <select name="ecp_digest_frequency" id="ecp_digest_frequency">
                        <option value="weekly" <?php selected($s['digest_frequency'], 'weekly'); ?>><?php esc_html_e('Weekly', 'enhanced-content-plugin'); ?></option>
                        <option value="daily" <?php selected($s['digest_frequency'], 'daily'); ?>><?php esc_html_e('Daily', 'enhanced-content-plugin'); ?></option>
                        <option value="off" <?php selected($s['digest_frequency'], 'off'); ?>><?php esc_html_e('Never', 'enhanced-content-plugin'); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="ecp_digest_recipients"><?php esc_html_e('Send to', 'enhanced-content-plugin'); ?></label></th>
                <td>
                    <input type="text" name="ecp_digest_recipients" id="ecp_digest_recipients" class="regular-text"
                           value="<?php echo esc_attr($s['digest_recipients']); ?>"
                           placeholder="<?php echo esc_attr(get_option('admin_email')); ?>">
                    <p class="description"><?php esc_html_e('Comma-separated. Leave blank to use the site admin address.', 'enhanced-content-plugin'); ?></p>
                    <p>
                        <button type="button" class="button" id="ecp-send-digest"><?php esc_html_e('Send one now', 'enhanced-content-plugin'); ?></button>
                        <span id="ecp-digest-result" aria-live="polite"></span>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Failures', 'enhanced-content-plugin'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="ecp_notify_on_failure" value="1" <?php checked($s['notify_on_failure'], 1); ?>>
                        <?php esc_html_e('Include errors in the digest', 'enhanced-content-plugin'); ?>
                    </label>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e('Housekeeping', 'enhanced-content-plugin'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="ecp_retention_days"><?php esc_html_e('Keep rejected changes and logs for', 'enhanced-content-plugin'); ?></label></th>
                <td>
                    <input type="number" name="ecp_retention_days" id="ecp_retention_days"
                           value="<?php echo esc_attr($s['retention_days']); ?>" min="7" class="small-text">
                    <?php esc_html_e('days', 'enhanced-content-plugin'); ?>
                    <p class="description"><?php esc_html_e('Applied changes are kept regardless — they are your rollback record.', 'enhanced-content-plugin'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Verbose logging', 'enhanced-content-plugin'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="ecp_debug_logging" value="1" <?php checked($s['debug_logging'], 1); ?>>
                        <?php esc_html_e('Record debug-level detail in the activity log', 'enhanced-content-plugin'); ?>
                    </label>
                    <p class="description"><?php esc_html_e('Useful when something is going wrong. These entries are pruned first, on the retention schedule above.', 'enhanced-content-plugin'); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }
}
