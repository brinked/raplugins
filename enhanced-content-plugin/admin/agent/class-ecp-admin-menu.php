<?php
/**
 * Admin menu and asset loading for the agent.
 *
 * Review Changes is deliberately the second item and carries the count
 * bubble: it is the screen the plugin exists to bring people back to.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ECP_Admin_Menu {

    /**
     * Kept as an alias so existing call sites read naturally. The real
     * permission logic lives in ECP_Capabilities.
     */
    const CAP = ECP_Capabilities::VIEW;

    private static $instance = null;

    /** @var string[] Hook suffixes for our screens. */
    private $hooks = array();

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'register'), 9);
        add_action('admin_enqueue_scripts', array($this, 'enqueue'));
        add_action('admin_notices', array($this, 'notices'));
        add_action('admin_bar_menu', array($this, 'admin_bar'), 90);
    }

    public function register() {
        $pending = ECP_Proposals::pending_count();

        $title = __('Enhanced Content', 'enhanced-content-plugin');
        $menu_title = $pending > 0
            ? $title . ' <span class="update-plugins count-' . (int) $pending . '"><span class="plugin-count">' . number_format_i18n($pending) . '</span></span>'
            : $title;

        $this->hooks['dashboard'] = add_menu_page(
            $title,
            $menu_title,
            self::CAP,
            'ecp-dashboard',
            array('ECP_Screen_Dashboard', 'render'),
            'dashicons-superhero-alt',
            25
        );

        add_submenu_page('ecp-dashboard', __('Dashboard', 'enhanced-content-plugin'), __('Dashboard', 'enhanced-content-plugin'), self::CAP, 'ecp-dashboard', array('ECP_Screen_Dashboard', 'render'));

        $review_title = $pending > 0
            ? sprintf(
                /* translators: %s: number of pending changes */
                __('Review Changes %s', 'enhanced-content-plugin'),
                '<span class="update-plugins count-' . (int) $pending . '"><span class="plugin-count">' . number_format_i18n($pending) . '</span></span>'
            )
            : __('Review Changes', 'enhanced-content-plugin');

        $this->hooks['review'] = add_submenu_page(
            'ecp-dashboard',
            __('Review Changes', 'enhanced-content-plugin'),
            $review_title,
            self::CAP,
            'ecp-review',
            array('ECP_Screen_Review', 'render')
        );

        $this->hooks['opportunities'] = add_submenu_page(
            'ecp-dashboard',
            __('Opportunities', 'enhanced-content-plugin'),
            __('Opportunities', 'enhanced-content-plugin'),
            self::CAP,
            'ecp-opportunities',
            array('ECP_Screen_Opportunities', 'render')
        );

        $this->hooks['roadmap'] = add_submenu_page(
            'ecp-dashboard',
            __('Growth Roadmap', 'enhanced-content-plugin'),
            __('Growth Roadmap', 'enhanced-content-plugin'),
            self::CAP,
            'ecp-roadmap',
            array('ECP_Screen_Roadmap', 'render')
        );

        $this->hooks['map'] = add_submenu_page(
            'ecp-dashboard',
            __('Topical Map', 'enhanced-content-plugin'),
            __('Topical Map', 'enhanced-content-plugin'),
            self::CAP,
            'ecp-map',
            array('ECP_Screen_Map', 'render')
        );

        $this->hooks['plan'] = add_submenu_page(
            'ecp-dashboard',
            __('Content Plan', 'enhanced-content-plugin'),
            __('Content Plan', 'enhanced-content-plugin'),
            self::CAP,
            'ecp-plan',
            array('ECP_Screen_Plan', 'render')
        );

        $this->hooks['rankings'] = add_submenu_page(
            'ecp-dashboard',
            __('Rankings', 'enhanced-content-plugin'),
            __('Rankings', 'enhanced-content-plugin'),
            self::CAP,
            'ecp-rankings',
            array('ECP_Screen_Rankings', 'render')
        );

        if (ECP_Agent_Settings::is_on('clusters_enabled')) {
            $this->hooks['clusters'] = add_submenu_page(
                'ecp-dashboard',
                __('Competing Pages', 'enhanced-content-plugin'),
                __('Competing Pages', 'enhanced-content-plugin'),
                self::CAP,
                'ecp-clusters',
                array('ECP_Screen_Clusters', 'render')
            );
        }

        $this->hooks['intelligence'] = add_submenu_page(
            'ecp-dashboard',
            __('Site Intelligence', 'enhanced-content-plugin'),
            __('Site Intelligence', 'enhanced-content-plugin'),
            self::CAP,
            'ecp-intelligence',
            array('ECP_Screen_Intelligence', 'render')
        );

        $this->hooks['vault'] = add_submenu_page(
            'ecp-dashboard',
            __('Knowledge Vault', 'enhanced-content-plugin'),
            __('Knowledge Vault', 'enhanced-content-plugin'),
            self::CAP,
            'ecp-vault',
            array('ECP_Screen_Vault', 'render')
        );

        $this->hooks['history'] = add_submenu_page(
            'ecp-dashboard',
            __('History', 'enhanced-content-plugin'),
            __('History', 'enhanced-content-plugin'),
            self::CAP,
            'ecp-history',
            array('ECP_Screen_History', 'render')
        );

        $this->hooks['settings'] = add_submenu_page(
            'ecp-dashboard',
            __('Agent Settings', 'enhanced-content-plugin'),
            __('Agent Settings', 'enhanced-content-plugin'),
            ECP_Capabilities::MANAGE,
            'ecp-agent-settings',
            array('ECP_Screen_Agent_Settings', 'render')
        );

        // The v1 editorial settings keep their own page, moved under this
        // menu so everything the plugin does lives in one place.
        add_submenu_page(
            'ecp-dashboard',
            __('Display & Contributors', 'enhanced-content-plugin'),
            __('Display & Contributors', 'enhanced-content-plugin'),
            'manage_options',
            'options-general.php?page=ecp-settings'
        );
    }

    /**
     * Whether the current screen is one of ours.
     */
    private function is_agent_screen($hook) {
        return in_array($hook, $this->hooks, true);
    }

    public function enqueue($hook) {
        if (!$this->is_agent_screen($hook)) {
            return;
        }

        wp_enqueue_style(
            'ecp-agent',
            ECP_PLUGIN_URL . 'admin/css/agent.css',
            array(),
            ECP_VERSION
        );

        // The visual editor for "Edit before applying". Loaded on our
        // screens only; initialized per-textarea from agent-review.js when
        // an edit panel actually opens.
        if (function_exists('wp_enqueue_editor')) {
            wp_enqueue_editor();
        }

        wp_enqueue_script(
            'ecp-agent',
            ECP_PLUGIN_URL . 'admin/js/agent-review.js',
            array('jquery', 'wp-a11y'),
            ECP_VERSION,
            true
        );

        wp_localize_script('ecp-agent', 'ecpAgent', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('ecp_agent_nonce'),
            'i18n'    => array(
                'approving'      => __('Applying…', 'enhanced-content-plugin'),
                'rejecting'      => __('Rejecting…', 'enhanced-content-plugin'),
                'approved'       => __('Applied', 'enhanced-content-plugin'),
                'rejected'       => __('Rejected', 'enhanced-content-plugin'),
                'reverted'       => __('Rolled back', 'enhanced-content-plugin'),
                'failed'         => __('That did not work', 'enhanced-content-plugin'),
                'confirmBulk'    => __('Apply all %d of these changes to your live site?', 'enhanced-content-plugin'),
                'confirmRevert'  => __('Undo this change and restore the previous version?', 'enhanced-content-plugin'),
                'saveEdit'       => __('Save and apply', 'enhanced-content-plugin'),
                'cancel'         => __('Cancel', 'enhanced-content-plugin'),
                'nothingLeft'    => __('Nothing left to review. Nice work.', 'enhanced-content-plugin'),
                'scanning'       => __('Scanning %1$d of %2$d…', 'enhanced-content-plugin'),
                'scanDone'       => __('Scan finished — %d posts scored.', 'enhanced-content-plugin'),
                'analyzing'      => __('Analyzing… this can take a minute.', 'enhanced-content-plugin'),
                'testing'        => __('Testing…', 'enhanced-content-plugin'),
                'networkError'   => __('Could not reach the server. Check your connection and try again.', 'enhanced-content-plugin'),
                'unsavedEdit'    => __('You have an unsaved edit. Discard it?', 'enhanced-content-plugin'),
                'showRendered'   => __('Show it rendered', 'enhanced-content-plugin'),
                'hideRendered'   => __('Hide the rendered version', 'enhanced-content-plugin'),
                'loading'        => __('Rendering…', 'enhanced-content-plugin'),
                'detecting'      => __('Looking for competing pages…', 'enhanced-content-plugin'),
                'syncing'        => __('Fetching from Google… this can take a minute.', 'enhanced-content-plugin'),
                'pickAccount'    => __('Choose an account first.', 'enhanced-content-plugin'),
                'findingGaps'    => __('Working out what a reader wants…', 'enhanced-content-plugin'),
                'findingLinks'   => __('Looking for pages that could link here…', 'enhanced-content-plugin'),
                'saving'         => __('Saving…', 'enhanced-content-plugin'),
                'answerEmpty'    => __('Type an answer first.', 'enhanced-content-plugin'),
                'factEmpty'      => __('Type the fact first.', 'enhanced-content-plugin'),
                'mining'         => __('Reading your site… this can take a minute.', 'enhanced-content-plugin'),
                'seedEmpty'      => __('Type a seed topic first.', 'enhanced-content-plugin'),
                'mapping'        => __('Mapping the topic… this can take a minute.', 'enhanced-content-plugin'),
                'briefing'       => __('Writing the brief… this can take a minute.', 'enhanced-content-plugin'),
                'drafting'       => __('Drafting the article… this can take a few minutes.', 'enhanced-content-plugin'),
                'stillWorking'   => __('Taking longer than your server allows for one request — the work continues in the background. Watching for the result…', 'enhanced-content-plugin'),
                'finishedAfterAll' => __('Done — it finished in the background. Refreshing…', 'enhanced-content-plugin'),
                'checkHistory'   => __('No result after three minutes. Refresh the page in a little while; if nothing appears, the History screen will have the error.', 'enhanced-content-plugin'),
            ),
        ));
    }

    /**
     * Notices that matter enough to interrupt.
     */
    public function notices() {
        $screen = get_current_screen();

        if (!$screen || !current_user_can(self::CAP)) {
            return;
        }

        $on_our_screen = false !== strpos((string) $screen->id, 'ecp-');

        // Missing tables is fatal to every screen here — say so everywhere.
        if (!ECP_DB::tables_exist()) {
            printf(
                '<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
                esc_html__('Enhanced Content:', 'enhanced-content-plugin'),
                esc_html__('the agent database tables are missing. Deactivate and reactivate the plugin, or run "wp ecp install".', 'enhanced-content-plugin')
            );

            return;
        }

        // The remaining notices all end in "go and change a setting", so
        // there is no point showing them to someone who cannot.
        if (!$on_our_screen || !ECP_Capabilities::can_manage()) {
            return;
        }

        // The one thing people get wrong: the agent is off and they are
        // waiting for it to do something.
        if (!ECP_Agent_Settings::is_on('agent_enabled')) {
            printf(
                '<div class="notice notice-warning"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
                esc_html__('The agent is switched off.', 'enhanced-content-plugin'),
                esc_html__('It will not scan or propose anything until you turn it on.', 'enhanced-content-plugin'),
                esc_url(admin_url('admin.php?page=ecp-agent-settings')),
                esc_html__('Finish setup', 'enhanced-content-plugin')
            );
        } elseif (!ECP_Agent_Settings::is_ready()) {
            printf(
                '<div class="notice notice-warning"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
                esc_html__('No AI provider is connected.', 'enhanced-content-plugin'),
                esc_html__('Scanning works, but nothing can be analyzed or proposed.', 'enhanced-content-plugin'),
                esc_url(admin_url('admin.php?page=ecp-agent-settings#ecp-section-provider')),
                esc_html__('Add an API key', 'enhanced-content-plugin')
            );
        }

        $budget = ECP_AI_Client::budget_status();

        if ($budget['priced'] && $budget['monthly_cap'] > 0 && $budget['monthly_pct'] >= 90) {
            printf(
                '<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
                esc_html(sprintf(
                    /* translators: 1: spent, 2: cap */
                    __('AI spending is at $%1$.2f of your $%2$.2f monthly cap. The agent stops when it reaches the cap.', 'enhanced-content-plugin'),
                    $budget['monthly_spent'],
                    $budget['monthly_cap']
                )),
                esc_url(admin_url('admin.php?page=ecp-agent-settings#ecp-section-budget')),
                esc_html__('Adjust the cap', 'enhanced-content-plugin')
            );
        }
    }

    /**
     * A count in the toolbar, so pending work is visible from anywhere.
     */
    public function admin_bar($bar) {
        if (!current_user_can(self::CAP)) {
            return;
        }

        $pending = ECP_Proposals::pending_count();

        if (!$pending) {
            return;
        }

        $bar->add_node(array(
            'id'    => 'ecp-pending',
            'title' => sprintf(
                '<span class="ab-icon dashicons dashicons-superhero-alt" style="top:2px;"></span><span class="ab-label">%s</span>',
                esc_html(sprintf(
                    /* translators: %d: number of pending changes */
                    _n('%d change to review', '%d changes to review', $pending, 'enhanced-content-plugin'),
                    $pending
                ))
            ),
            'href'  => admin_url('admin.php?page=ecp-review'),
            'meta'  => array('title' => __('Enhanced Content — changes waiting for approval', 'enhanced-content-plugin')),
        ));
    }

    /**
     * Shared page header with the nav tabs.
     */
    public static function header($current) {
        $tabs = array(
            'ecp-dashboard'     => __('Dashboard', 'enhanced-content-plugin'),
            'ecp-review'        => __('Review Changes', 'enhanced-content-plugin'),
            'ecp-opportunities' => __('Opportunities', 'enhanced-content-plugin'),
            'ecp-roadmap'       => __('Roadmap', 'enhanced-content-plugin'),
            'ecp-map'           => __('Topical Map', 'enhanced-content-plugin'),
            'ecp-plan'          => __('Content Plan', 'enhanced-content-plugin'),
            'ecp-rankings'      => __('Rankings', 'enhanced-content-plugin'),
        );

        if (ECP_Agent_Settings::is_on('clusters_enabled')) {
            $tabs['ecp-clusters'] = __('Competing Pages', 'enhanced-content-plugin');
        }

        $tabs['ecp-intelligence'] = __('Site Intelligence', 'enhanced-content-plugin');
        $tabs['ecp-vault'] = __('Knowledge Vault', 'enhanced-content-plugin');
        $tabs['ecp-history'] = __('History', 'enhanced-content-plugin');

        if (ECP_Capabilities::can_manage()) {
            $tabs['ecp-agent-settings'] = __('Settings', 'enhanced-content-plugin');
        }

        $pending = ECP_Proposals::pending_count();

        echo '<nav class="nav-tab-wrapper ecp-tabs">';

        foreach ($tabs as $slug => $label) {
            printf(
                '<a href="%s" class="nav-tab%s">%s%s</a>',
                esc_url(admin_url('admin.php?page=' . $slug)),
                $slug === $current ? ' nav-tab-active' : '',
                esc_html($label),
                ('ecp-review' === $slug && $pending) ? ' <span class="ecp-tab-count">' . esc_html(number_format_i18n($pending)) . '</span>' : ''
            );
        }

        echo '</nav>';

        // A view-only reviewer needs to know why the Apply buttons are gone
        // before they go looking for a bug.
        if (ECP_Capabilities::LEVEL_VIEW === ECP_Capabilities::level_for()) {
            printf(
                '<div class="notice notice-info inline"><p>%s</p></div>',
                esc_html(ECP_Capabilities::current_summary())
            );
        }
    }
}
