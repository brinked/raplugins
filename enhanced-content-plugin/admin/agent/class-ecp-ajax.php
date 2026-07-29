<?php
/**
 * AJAX endpoints for the agent screens.
 *
 * Every handler checks the nonce first and the capability second. The
 * capability check is per-post where a post is involved — a contributor with
 * edit access to their own articles should be able to review changes to those
 * and nothing else.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ECP_Ajax {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        $actions = array(
            'approve'          => 'approve',
            'reject'           => 'reject',
            'edit_apply'       => 'edit_apply',
            'revert'           => 'revert',
            'bulk'             => 'bulk',
            'scan'             => 'scan',
            'analyze'          => 'analyze',
            'render_preview'   => 'render_preview',
            'test_provider'    => 'test_provider',
            'dismiss'          => 'dismiss',
            'snooze'           => 'snooze',
            'send_digest'      => 'send_digest',
            'enable_autopilot' => 'enable_autopilot',
            'analyze_gaps'     => 'analyze_gaps',
            'answer_question'  => 'answer_question',
            'build_links'      => 'build_links',
            'sync_search'      => 'sync_search',
            'repair_search'    => 'repair_search',
            'set_sitekit_user' => 'set_sitekit_user',
            'classify_now'     => 'classify_now',
            'save_topic'       => 'save_topic',
            'detect_clusters'  => 'detect_clusters',
            'analyze_cluster'  => 'analyze_cluster',
            'cluster_status'   => 'cluster_status',
            'roadmap_action'   => 'roadmap_action',
            'rebuild_roadmap'  => 'rebuild_roadmap',
            'save_fact'        => 'save_fact',
            'fact_action'      => 'fact_action',
        );

        foreach ($actions as $action => $method) {
            add_action('wp_ajax_ecp_' . $action, array($this, $method));
        }
    }

    /* --------------------------------------------------------------------
     * Guards
     * ----------------------------------------------------------------- */

    /**
     * Verify the nonce and the capability. Exits on failure.
     *
     * Defaults to REVIEW rather than VIEW: almost everything reachable here
     * mutates something or spends money, so read-only access has to be asked
     * for explicitly by the two handlers that genuinely are read-only.
     */
    private function guard($capability = ECP_Capabilities::REVIEW) {
        check_ajax_referer('ecp_agent_nonce', 'nonce');

        if (!current_user_can($capability)) {
            wp_send_json_error(array(
                'message' => __('You do not have permission to do that.', 'enhanced-content-plugin'),
            ), 403);
        }
    }

    private function proposal_id() {
        return isset($_POST['id']) ? absint($_POST['id']) : 0;
    }

    /* --------------------------------------------------------------------
     * Single-proposal actions
     * ----------------------------------------------------------------- */

    public function approve() {
        $this->guard();

        $result = ECP_Applier::approve_and_apply($this->proposal_id());

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success(array(
            'message' => ECP_Agent_Settings::is_on('apply_as_draft')
                ? __('Saved as a draft revision — your live page is unchanged.', 'enhanced-content-plugin')
                : __('Applied.', 'enhanced-content-plugin'),
            'pending' => ECP_Proposals::pending_count(),
        ));
    }

    public function reject() {
        $this->guard();

        $note = isset($_POST['note']) ? sanitize_textarea_field(wp_unslash($_POST['note'])) : '';
        $result = ECP_Proposals::reject($this->proposal_id(), $note);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success(array(
            'message' => __('Rejected.', 'enhanced-content-plugin'),
            'pending' => ECP_Proposals::pending_count(),
        ));
    }

    /* --------------------------------------------------------------------
     * Roadmap
     * ----------------------------------------------------------------- */

    /**
     * A decision on a roadmap step: approve, postpone, dismiss, reopen,
     * complete, lock or unlock.
     */
    public function roadmap_action() {
        $this->guard();

        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        $act = isset($_POST['act']) ? sanitize_key($_POST['act']) : '';

        if ('lock' === $act || 'unlock' === $act) {
            $result = ECP_Roadmap::set_locked($id, 'lock' === $act);
        } else {
            $result = ECP_Roadmap::decide($id, $act);
        }

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        $messages = array(
            'approve'  => __('Approved — the agent will prepare these changes next.', 'enhanced-content-plugin'),
            'postpone' => __('Postponed two weeks.', 'enhanced-content-plugin'),
            'dismiss'  => __('Dismissed.', 'enhanced-content-plugin'),
            'reopen'   => __('Back on the plan.', 'enhanced-content-plugin'),
            'complete' => __('Marked complete.', 'enhanced-content-plugin'),
            'lock'     => __('Locked in place.', 'enhanced-content-plugin'),
            'unlock'   => __('Unlocked.', 'enhanced-content-plugin'),
        );

        wp_send_json_success(array(
            'message' => isset($messages[$act]) ? $messages[$act] : __('Done.', 'enhanced-content-plugin'),
        ));
    }

    /* --------------------------------------------------------------------
     * Knowledge Vault
     * ----------------------------------------------------------------- */

    /**
     * Add a fact to the vault.
     */
    public function save_fact() {
        $this->guard();

        $id = ECP_Vault::add(array(
            'fact'     => isset($_POST['fact']) ? wp_unslash($_POST['fact']) : '',
            'question' => isset($_POST['question']) ? wp_unslash($_POST['question']) : '',
            'topic'    => isset($_POST['topic']) ? wp_unslash($_POST['topic']) : '',
            'source'   => 'manual',
        ));

        if (is_wp_error($id)) {
            wp_send_json_error(array('message' => $id->get_error_message()));
        }

        wp_send_json_success(array(
            'message' => __('In the vault. The agent can use it from the next analysis on.', 'enhanced-content-plugin'),
        ));
    }

    /**
     * Confirm, edit, retire or restore a vault fact.
     */
    public function fact_action() {
        $this->guard();

        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        $act = isset($_POST['act']) ? sanitize_key($_POST['act']) : '';

        switch ($act) {
            case 'confirm':
                $result = ECP_Vault::confirm($id);
                $message = __('Confirmed as still true.', 'enhanced-content-plugin');
                break;

            case 'retire':
                $result = ECP_Vault::retire($id);
                $message = __('Retired — removed from every future analysis.', 'enhanced-content-plugin');
                break;

            case 'restore':
                $result = ECP_Vault::restore($id);
                $message = __('Restored.', 'enhanced-content-plugin');
                break;

            case 'edit':
                $result = ECP_Vault::update_fact($id, array(
                    'fact' => isset($_POST['fact']) ? wp_unslash($_POST['fact']) : '',
                ));
                $message = __('Updated and re-confirmed.', 'enhanced-content-plugin');
                break;

            default:
                wp_send_json_error(array('message' => __('Unknown vault action.', 'enhanced-content-plugin')));
                return;
        }

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success(array('message' => $message));
    }

    /**
     * Re-derive the roadmap on demand. Free — reads stored data only.
     */
    public function rebuild_roadmap() {
        $this->guard();

        delete_transient('ecp_roadmap_fresh');
        $active = ECP_Roadmap::rebuild();

        wp_send_json_success(array(
            'message' => sprintf(
                /* translators: %d: number of steps */
                _n('Plan refreshed — %d step.', 'Plan refreshed — %d steps.', $active, 'enhanced-content-plugin'),
                $active
            ),
        ));
    }

    /**
     * Save a reviewer's edit, then apply it.
     */
    public function edit_apply() {
        $this->guard();

        $id = $this->proposal_id();

        // Intentionally not sanitize_textarea_field: this is post content and
        // may legitimately contain HTML. ECP_Guardrails::sanitize_html is the
        // filter that matters, and it runs below.
        $value = isset($_POST['value']) ? wp_unslash($_POST['value']) : '';

        $proposal = ECP_Proposals::get($id);
        if (!$proposal) {
            wp_send_json_error(array('message' => __('That change no longer exists.', 'enhanced-content-plugin')));
        }

        $info = ECP_Proposals::change_type($proposal['change_type']);
        $is_markup = $info && in_array($info['target'], array('section', 'section_insert', 'content'), true);

        $value = $is_markup
            ? ECP_Guardrails::sanitize_html($value)
            : sanitize_textarea_field($value);

        if ('' === trim(wp_strip_all_tags($value))) {
            wp_send_json_error(array('message' => __('The edited version is empty.', 'enhanced-content-plugin')));
        }

        // A human edit still has to clear the banned-phrase rule — those are
        // the site owner's own house rules, not a model guardrail.
        $banned = ECP_Guardrails::find_banned_phrases(wp_strip_all_tags($value));
        if ($banned) {
            wp_send_json_error(array(
                'message' => sprintf(
                    /* translators: %s: the banned phrase */
                    __('That uses a phrase on your banned list: "%s".', 'enhanced-content-plugin'),
                    $banned[0]
                ),
            ));
        }

        $edited = ECP_Proposals::edit($id, $value);
        if (is_wp_error($edited)) {
            wp_send_json_error(array('message' => $edited->get_error_message()));
        }

        $result = ECP_Applier::approve_and_apply($id);
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success(array(
            'message' => __('Your version was applied.', 'enhanced-content-plugin'),
            'pending' => ECP_Proposals::pending_count(),
        ));
    }

    public function revert() {
        $this->guard();

        $result = ECP_Applier::revert($this->proposal_id());

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success(array('message' => __('Rolled back.', 'enhanced-content-plugin')));
    }

    /**
     * Approve or reject several at once.
     */
    public function bulk() {
        $this->guard();

        $ids = isset($_POST['ids']) ? array_map('absint', (array) wp_unslash($_POST['ids'])) : array();
        $operation = isset($_POST['operation']) ? sanitize_key(wp_unslash($_POST['operation'])) : '';

        if (!$ids) {
            wp_send_json_error(array('message' => __('Nothing was selected.', 'enhanced-content-plugin')));
        }

        if (!in_array($operation, array('approve', 'reject'), true)) {
            wp_send_json_error(array('message' => __('Unknown action.', 'enhanced-content-plugin')));
        }

        // Bound the batch: each approval is a post write, and a 500-item
        // batch would time out halfway through with no clear record of where.
        if (count($ids) > 50) {
            $ids = array_slice($ids, 0, 50);
        }

        if ('approve' === $operation) {
            $ids = self::order_for_apply($ids);
        }

        $succeeded = array();
        $failed = array();

        foreach ($ids as $id) {
            $result = 'approve' === $operation
                ? ECP_Applier::approve_and_apply($id)
                : ECP_Proposals::reject($id);

            if (is_wp_error($result)) {
                $failed[] = array('id' => $id, 'message' => $result->get_error_message());
            } else {
                $succeeded[] = $id;
            }
        }

        wp_send_json_success(array(
            'succeeded' => $succeeded,
            'failed'    => $failed,
            'pending'   => ECP_Proposals::pending_count(),
            'message'   => $failed
                ? sprintf(
                    /* translators: 1: number applied, 2: number that failed */
                    __('%1$d done, %2$d could not be applied.', 'enhanced-content-plugin'),
                    count($succeeded),
                    count($failed)
                )
                : sprintf(
                    /* translators: %d: number of changes */
                    _n('%d change done.', '%d changes done.', count($succeeded), 'enhanced-content-plugin'),
                    count($succeeded)
                ),
        ));
    }

    /**
     * Order a batch so earlier changes disturb later ones as little as
     * possible.
     *
     * Applying several changes to one page happens in sequence, and each one
     * rewrites post_content. Two orderings matter:
     *
     *   Metadata first — it never touches the body, so it can never be
     *   invalidated by anything else in the batch.
     *
     *   Inserts last — adding a section renumbers every section after it,
     *   which changes their ids. Section lookups recover from that via the
     *   stored heading, but not needing to is better than relying on it.
     *
     * Within a group the reviewer's order is preserved.
     *
     * @param int[] $ids
     * @return int[]
     */
    private static function order_for_apply(array $ids) {
        $weights = array();

        foreach ($ids as $index => $id) {
            $proposal = ECP_Proposals::get($id);
            $info = $proposal ? ECP_Proposals::change_type($proposal['change_type']) : null;
            $target = $info ? $info['target'] : '';

            switch ($target) {
                case 'meta':
                case 'attachment':
                case 'faq':
                case 'sources':
                    $rank = 0;   // Independent of the body entirely.
                    break;

                case 'section_insert':
                    $rank = 2;   // Shifts everything after it.
                    break;

                default:
                    $rank = 1;   // In-place body rewrites.
            }

            $weights[] = array('id' => (int) $id, 'rank' => $rank, 'index' => $index);
        }

        usort($weights, function ($a, $b) {
            return $a['rank'] === $b['rank']
                ? $a['index'] <=> $b['index']
                : $a['rank'] <=> $b['rank'];
        });

        return wp_list_pluck($weights, 'id');
    }

    /* --------------------------------------------------------------------
     * Jobs
     * ----------------------------------------------------------------- */

    /**
     * One batch of a scan. The browser loops until done, so a big site never
     * hits max_execution_time in one request.
     */
    public function scan() {
        $this->guard();

        $offset = isset($_POST['offset']) ? absint($_POST['offset']) : 0;
        $result = ECP_Scheduler::scan_now($offset, 40);

        wp_send_json_success($result);
    }

    /**
     * Analyze one post on demand.
     */
    public function analyze() {
        $this->guard();

        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;

        if (!$post_id || !ECP_Capabilities::can_analyze($post_id)) {
            wp_send_json_error(array('message' => __('You do not have permission to run an analysis on that post.', 'enhanced-content-plugin')), 403);
        }

        if (!ECP_Agent_Settings::is_ready()) {
            wp_send_json_error(array(
                'message' => __('The agent is not connected to an AI provider yet.', 'enhanced-content-plugin'),
            ));
        }

        $result = ECP_Analyzer::analyze($post_id, array('trigger_source' => 'manual'));

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        $count = count($result['proposals']);

        $skipped_reasons = array();
        foreach ((array) $result['skipped'] as $skip) {
            if (is_array($skip) && !empty($skip['reason'])) {
                $skipped_reasons[] = $skip['reason'];
            }
        }

        wp_send_json_success(array(
            'count'    => $count,
            'skipped'  => $skipped_reasons,
            'redirect' => add_query_arg(
                array('page' => 'ecp-review', 'post' => $post_id),
                admin_url('admin.php')
            ),
            'message'  => $count
                ? sprintf(
                    /* translators: %d: number of changes */
                    _n('%d change proposed — review it below.', '%d changes proposed — review them below.', $count, 'enhanced-content-plugin'),
                    $count
                )
                : __('The agent found nothing worth changing on that page.', 'enhanced-content-plugin'),
        ));
    }

    /**
     * Render one proposal's new content, for the in-card preview.
     *
     * Read-only, so VIEW is enough — but still per-post, because the rendered
     * fragment is content the user may not be allowed to see.
     */
    public function render_preview() {
        $this->guard(ECP_Capabilities::VIEW);

        $proposal = ECP_Proposals::get($this->proposal_id());

        if (!$proposal) {
            wp_send_json_error(array('message' => __('That change no longer exists.', 'enhanced-content-plugin')));
        }

        if (!current_user_can('edit_post', (int) $proposal['post_id'])) {
            wp_send_json_error(array('message' => __('You do not have permission to view that page.', 'enhanced-content-plugin')), 403);
        }

        $html = ECP_Preview::render_fragment($proposal);

        if ('' === trim($html)) {
            wp_send_json_error(array('message' => __('There is nothing to render for this change.', 'enhanced-content-plugin')));
        }

        wp_send_json_success(array('html' => $html));
    }

    /**
     * Pull Search Console data through Site Kit right now.
     *
     * Free — Google's API, not the AI provider — but it can take a while on
     * a site with a lot of ranking pages, because it makes one extra request
     * per page for the per-query breakdown.
     */
    public function sync_search() {
        $this->guard(ECP_Capabilities::MANAGE);

        if ('sitekit' !== ECP_Search_Data::active_source()) {
            wp_send_json_error(array(
                'message' => __('No live Site Kit connection. Install and connect Google Site Kit, or upload a CSV export instead.', 'enhanced-content-plugin'),
            ));
        }

        // Pull every reporting period in one go — six API calls total, and
        // it means the period switcher on the Rankings screen is never
        // showing a window that was never fetched.
        $result = ECP_Search_Data::sync_all();

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        $covered = ECP_Search_Data::covered_post_count();

        // Report every period's outcome, not just the total. A run that
        // fetched one period and failed the other two used to report success.
        $lines = array();

        foreach ((array) $result['per_window'] as $days => $outcome) {
            $label = ECP_Search_Data::window_label($days);

            if (!empty($outcome['error'])) {
                $lines[] = sprintf(
                    /* translators: 1: period label, 2: error message */
                    __('%1$s failed — %2$s', 'enhanced-content-plugin'),
                    $label,
                    $outcome['error']
                );
                continue;
            }

            $lines[] = sprintf(
                /* translators: 1: period label, 2: number of rows */
                _n('%1$s: %2$s row', '%1$s: %2$s rows', (int) $outcome['rows'], 'enhanced-content-plugin'),
                $label,
                number_format_i18n((int) $outcome['rows'])
            );
        }

        $message = sprintf(
            /* translators: 1: number of pages matched, 2: per-period breakdown */
            __('%1$s of your pages had search data. %2$s', 'enhanced-content-plugin'),
            number_format_i18n((int) $result['posts']),
            implode(' · ', $lines)
        );

        if (!empty($result['truncated'])) {
            $message .= ' ' . __('Some periods hit the row ceiling, so the long tail of low-volume terms was cut off.', 'enhanced-content-plugin');
        }

        // The failure mode people actually hit: Google returns rows fine, but
        // none of the URLs resolve to a post, so everything downstream looks
        // broken for no visible reason.
        if (0 === (int) $result['posts']) {
            $message = __('Google returned data, but none of the URLs matched a page on this site. That usually means the Search Console property is a different domain to this WordPress install — check for a www / non-www or http / https mismatch.', 'enhanced-content-plugin');

            wp_send_json_error(array('message' => $message));
        }

        wp_send_json_success(array(
            'message' => $message,
            'covered' => $covered,
            'reload'  => true,
        ));
    }

    /**
     * Discard metrics rows stamped with an unreadable reporting period and
     * pull the data again.
     */
    public function repair_search() {
        $this->guard(ECP_Capabilities::MANAGE);

        $result = ECP_Search_Data::repair_windows();

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success(array(
            'message' => sprintf(
                /* translators: 1: rows removed, 2: rows stored */
                __('Removed %1$s unreadable rows and refetched %2$s. Every period should now show data.', 'enhanced-content-plugin'),
                number_format_i18n((int) $result['removed']),
                number_format_i18n((int) $result['synced']['rows'])
            ),
            'reload'  => true,
        ));
    }

    /**
     * Choose which account scheduled syncs borrow.
     *
     * Verified by actually using it: a saved setting that turns out not to
     * work is the same failure as no setting at all, discovered a night later.
     */
    public function set_sitekit_user() {
        $this->guard(ECP_Capabilities::MANAGE);

        $user_id = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;

        if (!$user_id || !in_array($user_id, ECP_Search_Data::google_token_holders(), true)) {
            wp_send_json_error(array('message' => __('That account does not hold a Google connection. Pick one of the listed accounts.', 'enhanced-content-plugin')));
        }

        update_option('ecp_sitekit_user', $user_id, false);

        $test = ECP_Search_Data::test_owner_connection();

        if (is_wp_error($test)) {
            wp_send_json_error(array(
                'message' => sprintf(
                    /* translators: %s: error message from Google */
                    __('Saved, but a test request as that account failed: %s', 'enhanced-content-plugin'),
                    $test->get_error_message()
                ),
            ));
        }

        $user = get_userdata($user_id);

        wp_send_json_success(array(
            'message' => sprintf(
                /* translators: %s: user display name */
                __('Scheduled syncs will run as %s. A test request succeeded.', 'enhanced-content-plugin'),
                $user ? $user->display_name : (string) $user_id
            ),
            'reload'  => true,
        ));
    }

    /**
     * Run one classification batch on demand.
     */
    public function classify_now() {
        $this->guard(ECP_Capabilities::MANAGE);

        $result = ECP_Classifier::run_batch('manual');

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        if (0 === (int) $result['classified']) {
            wp_send_json_success(array(
                'message' => __('Everything is already classified.', 'enhanced-content-plugin'),
            ));
        }

        wp_send_json_success(array(
            'message' => sprintf(
                /* translators: 1: pages classified, 2: pages remaining */
                __('Classified %1$d pages. %2$d remaining.', 'enhanced-content-plugin'),
                (int) $result['classified'],
                (int) $result['remaining']
            ),
            'reload'  => 0 === (int) $result['remaining'],
        ));
    }

    /**
     * A human corrected a page's topic. Locked from then on.
     */
    public function save_topic() {
        $this->guard(ECP_Capabilities::MANAGE);

        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        $topic = isset($_POST['topic']) ? sanitize_text_field(wp_unslash($_POST['topic'])) : '';

        if (!$post_id || '' === $topic) {
            wp_send_json_error(array('message' => __('A page and a topic are both needed.', 'enhanced-content-plugin')));
        }

        if (!ECP_Inventory::override_topic($post_id, $topic)) {
            wp_send_json_error(array('message' => __('Could not save the topic.', 'enhanced-content-plugin')));
        }

        wp_send_json_success(array(
            'message' => __('Saved. The classifier will never overwrite your label.', 'enhanced-content-plugin'),
            'topic'   => $topic,
        ));
    }

    /* --------------------------------------------------------------------
     * Content gaps
     * ----------------------------------------------------------------- */

    /**
     * Work out what a reader wants from a page and what is missing.
     */
    public function analyze_gaps() {
        $this->guard();

        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;

        if (!$post_id || !ECP_Capabilities::can_analyze($post_id)) {
            wp_send_json_error(array('message' => __('You do not have permission to analyze that post.', 'enhanced-content-plugin')), 403);
        }

        if (!ECP_Agent_Settings::is_ready()) {
            wp_send_json_error(array('message' => __('The agent is not connected to an AI provider yet.', 'enhanced-content-plugin')));
        }

        $result = ECP_Content_Gaps::analyze($post_id, array('trigger_source' => 'manual'));

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        $report = $result['report'];
        $parts = array();

        $parts[] = sprintf(
            /* translators: %d: completeness percentage */
            __('This article answers about %d%% of what its reader needs.', 'enhanced-content-plugin'),
            (int) $report['completeness']
        );

        if ($result['proposals']) {
            $parts[] = sprintf(
                /* translators: %d: number of sections */
                _n('%d section drafted and waiting for review.', '%d sections drafted and waiting for review.', count($result['proposals']), 'enhanced-content-plugin'),
                count($result['proposals'])
            );
        }

        if ($result['questions']) {
            $parts[] = sprintf(
                /* translators: %d: number of questions */
                _n('%d question needs an answer only you can give.', '%d questions need answers only you can give.', count($result['questions']), 'enhanced-content-plugin'),
                count($result['questions'])
            );
        }

        wp_send_json_success(array(
            'message'  => implode(' ', $parts),
            'gaps'     => count($report['gaps']),
            'reload'   => true,
        ));
    }

    /**
     * Record an answer to one of the agent's questions.
     */
    public function answer_question() {
        $this->guard();

        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        $question = isset($_POST['question']) ? wp_unslash($_POST['question']) : '';
        $answer = isset($_POST['answer']) ? wp_unslash($_POST['answer']) : '';

        $result = ECP_Content_Gaps::answer($post_id, $question, $answer);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success(array(
            'message' => __('Saved. The agent will treat this as a verified fact and can use it from now on — re-run the gap analysis when you have answered everything you want to.', 'enhanced-content-plugin'),
        ));
    }

    /* --------------------------------------------------------------------
     * Internal linking
     * ----------------------------------------------------------------- */

    /**
     * Propose inbound links to a page that has none.
     */
    public function build_links() {
        $this->guard();

        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;

        if (!$post_id || !ECP_Capabilities::can_review($post_id)) {
            wp_send_json_error(array('message' => __('You do not have permission to change that post.', 'enhanced-content-plugin')), 403);
        }

        $result = ECP_Link_Suggestions::build($post_id);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        if (!$result['proposals']) {
            wp_send_json_error(array(
                'message' => $result['candidates']
                    ? __('Found pages that mention this topic, but none had a spot where a link would read naturally. Try adding a sentence about it to a related article by hand.', 'enhanced-content-plugin')
                    : __('No other page on the site mentions this topic in a way that could carry a link. This page may need something written about it elsewhere first.', 'enhanced-content-plugin'),
            ));
        }

        wp_send_json_success(array(
            'message' => sprintf(
                /* translators: %d: number of links */
                _n('%d inbound link proposed — review it before it goes live.', '%d inbound links proposed — review them before they go live.', count($result['proposals']), 'enhanced-content-plugin'),
                count($result['proposals'])
            ),
            'redirect' => admin_url('admin.php?page=ecp-review'),
        ));
    }

    /* --------------------------------------------------------------------
     * Clusters
     * ----------------------------------------------------------------- */

    /**
     * Re-run cluster detection. Free — no AI calls.
     */
    public function detect_clusters() {
        $this->guard();

        $result = ECP_Clusters::detect();
        $stats = ECP_Clusters::stats();

        $message = $result['found'] > 0
            ? sprintf(
                /* translators: %d: number of new groups found */
                _n('Found %d new group of competing pages.', 'Found %d new groups of competing pages.', (int) $result['found'], 'enhanced-content-plugin'),
                (int) $result['found']
            )
            : ($stats['open'] > 0
                ? __('Nothing new. The groups already listed are still open.', 'enhanced-content-plugin')
                : __('No competing pages found.', 'enhanced-content-plugin'));

        if ('titles' === $result['source'] && $result['found'] > 0) {
            $message .= ' ' . __('These came from comparing titles rather than search data, so check them carefully.', 'enhanced-content-plugin');
        }

        wp_send_json_success(array(
            'found'   => (int) $result['found'],
            'source'  => $result['source'],
            'message' => $message,
            'reload'  => $result['found'] > 0,
        ));
    }

    /**
     * Work out what to do about one cluster. Costs money.
     */
    public function analyze_cluster() {
        $this->guard();

        $cluster_id = isset($_POST['cluster_id']) ? absint($_POST['cluster_id']) : 0;

        if (!$cluster_id) {
            wp_send_json_error(array('message' => __('No group was named.', 'enhanced-content-plugin')));
        }

        if (!ECP_Agent_Settings::is_ready()) {
            wp_send_json_error(array(
                'message' => __('The agent is not connected to an AI provider yet.', 'enhanced-content-plugin'),
            ));
        }

        $cluster = ECP_Clusters::get($cluster_id);

        if (!$cluster) {
            wp_send_json_error(array('message' => __('That group no longer exists.', 'enhanced-content-plugin')));
        }

        // A restricted reviewer must be able to act on every page in the
        // group — a partial fix to a cannibalisation problem is worse than
        // none, because it moves signals without completing the move.
        foreach ((array) $cluster['member_ids'] as $member_id) {
            if (!ECP_Capabilities::can_review((int) $member_id)) {
                wp_send_json_error(array(
                    'message' => __('This group includes pages you cannot edit, so it needs someone with wider access.', 'enhanced-content-plugin'),
                ), 403);
            }
        }

        $result = ECP_Analyzer::analyze_cluster($cluster_id, array('trigger_source' => 'manual'));

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        $count = count($result['proposals']);

        wp_send_json_success(array(
            'count'    => $count,
            'reload'   => true,
            'message'  => $count
                ? sprintf(
                    /* translators: %d: number of changes */
                    _n('%d change proposed.', '%d changes proposed.', $count, 'enhanced-content-plugin'),
                    $count
                )
                : __('No changes to propose — see the verdict for what to do instead.', 'enhanced-content-plugin'),
        ));
    }

    /**
     * Dismiss or resolve a cluster.
     */
    public function cluster_status() {
        $this->guard();

        $cluster_id = isset($_POST['cluster_id']) ? absint($_POST['cluster_id']) : 0;
        $status = isset($_POST['status']) ? sanitize_key(wp_unslash($_POST['status'])) : '';

        $allowed = array(ECP_Clusters::STATUS_DISMISSED, ECP_Clusters::STATUS_RESOLVED, ECP_Clusters::STATUS_OPEN);

        if (!$cluster_id || !in_array($status, $allowed, true)) {
            wp_send_json_error(array('message' => __('Unknown action.', 'enhanced-content-plugin')));
        }

        ECP_Clusters::set_status($cluster_id, $status);

        wp_send_json_success(array(
            'message' => ECP_Clusters::STATUS_DISMISSED === $status
                ? __('Dismissed. It will not come back unless the pages change.', 'enhanced-content-plugin')
                : __('Updated.', 'enhanced-content-plugin'),
        ));
    }

    /* --------------------------------------------------------------------
     * Settings helpers
     * ----------------------------------------------------------------- */

    /**
     * Test the configured provider credentials.
     */
    public function test_provider() {
        $this->guard(ECP_Capabilities::MANAGE);

        // Test what is on screen, not what was last saved. Otherwise the
        // button sits next to a key it cannot see and reports "no API key"
        // at the exact moment the user has just pasted one in.
        $overrides = array();

        if (isset($_POST['api_key'])) {
            $key = trim((string) wp_unslash($_POST['api_key']));

            // The field renders a bullet mask when a key is already stored;
            // that is not a credential and must never be sent as one.
            if ('' !== $key && !preg_match('/^[\x{2022}*]+$/u', $key)) {
                $overrides['api_key'] = sanitize_text_field($key);
            }
        }

        if (!empty($_POST['provider'])) {
            $overrides['provider'] = sanitize_key(wp_unslash($_POST['provider']));
        }

        if (!empty($_POST['model'])) {
            $overrides['model'] = sanitize_text_field(wp_unslash($_POST['model']));
        }

        // Distinguish "you have not entered a key" from "your browser is
        // running a cached copy of the old JavaScript that does not send
        // one". Both used to surface as the same unhelpful message.
        if (!isset($overrides['api_key']) && 'none' === ECP_Agent_Settings::api_key_source()) {
            wp_send_json_error(array(
                'message' => sprintf(
                    /* translators: %s: plugin version */
                    __('No key reached the server. If the box above is filled in, your browser is running a cached copy of this plugin\'s JavaScript — hard-refresh the page (Ctrl+F5), and clear your page-cache plugin if you use one. Running version %s.', 'enhanced-content-plugin'),
                    ECP_VERSION
                ),
            ));
        }

        $provider = ECP_AI_Client::provider($overrides);

        if (is_wp_error($provider)) {
            wp_send_json_error(array('message' => $provider->get_error_message()));
        }

        if (!method_exists($provider, 'test_connection')) {
            wp_send_json_error(array('message' => __('That provider has no connection test.', 'enhanced-content-plugin')));
        }

        $result = $provider->test_connection();

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        $usage = $provider->last_usage();

        $message = sprintf(
            /* translators: 1: provider name, 2: token count */
            __('Connected to %1$s. The test used %2$s tokens.', 'enhanced-content-plugin'),
            $provider->label(),
            number_format_i18n($usage['input_tokens'] + $usage['output_tokens'])
        );

        // A working key that has not been written yet is the single easiest
        // thing to walk away from thinking you are finished.
        if (isset($overrides['api_key']) && 'none' === ECP_Agent_Settings::api_key_source()) {
            $message .= ' ' . __('This key is not saved yet — click Save settings.', 'enhanced-content-plugin');
        }

        wp_send_json_success(array('message' => $message));
    }

    /* --------------------------------------------------------------------
     * Opportunity queue
     * ----------------------------------------------------------------- */

    public function dismiss() {
        $this->guard();

        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;

        if (!$post_id || !ECP_Capabilities::can_review($post_id)) {
            wp_send_json_error(array('message' => __('You do not have permission to change that post.', 'enhanced-content-plugin')), 403);
        }

        ECP_Opportunity_Engine::set_status($post_id, ECP_Opportunity_Engine::STATUS_DISMISSED);

        wp_send_json_success(array(
            'message' => __('Dismissed. It will come back only if the page changes.', 'enhanced-content-plugin'),
        ));
    }

    public function snooze() {
        $this->guard();

        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        $days = isset($_POST['days']) ? max(1, min(365, absint($_POST['days']))) : 30;

        if (!$post_id || !ECP_Capabilities::can_review($post_id)) {
            wp_send_json_error(array('message' => __('You do not have permission to change that post.', 'enhanced-content-plugin')), 403);
        }

        ECP_Opportunity_Engine::set_status(
            $post_id,
            ECP_Opportunity_Engine::STATUS_SNOOZED,
            array('snoozed_until' => gmdate('Y-m-d H:i:s', strtotime("+{$days} days", (int) current_time('timestamp'))))
        );

        wp_send_json_success(array(
            'message' => sprintf(
                /* translators: %d: number of days */
                _n('Snoozed for %d day.', 'Snoozed for %d days.', $days, 'enhanced-content-plugin'),
                $days
            ),
        ));
    }

    public function send_digest() {
        $this->guard(ECP_Capabilities::MANAGE);

        $sent = ECP_Digest::send_test();

        if (!$sent) {
            wp_send_json_error(array(
                'message' => __('wp_mail() returned false. This site cannot send email — check your SMTP setup.', 'enhanced-content-plugin'),
            ));
        }

        wp_send_json_success(array(
            'message' => sprintf(
                /* translators: %s: comma-separated email addresses */
                __('Sent to %s.', 'enhanced-content-plugin'),
                implode(', ', ECP_Digest::recipients())
            ),
        ));
    }

    /**
     * Accept a trust-ladder suggestion from the dashboard.
     */
    public function enable_autopilot() {
        $this->guard(ECP_Capabilities::MANAGE);

        $type = isset($_POST['type']) ? sanitize_key(wp_unslash($_POST['type'])) : '';

        if (!ECP_Proposals::change_type($type)) {
            wp_send_json_error(array('message' => __('Unknown change type.', 'enhanced-content-plugin')));
        }

        $stats = ECP_Trust_Ladder::stats($type);

        if (!$stats['eligible']) {
            wp_send_json_error(array(
                'message' => __('That change type has not earned auto-apply yet.', 'enhanced-content-plugin'),
            ));
        }

        $current = (array) ECP_Agent_Settings::get('auto_apply_types', array());
        $current[] = $type;

        ECP_Agent_Settings::update(array(
            'auto_apply_types' => array_values(array_unique($current)),
            'approval_mode'    => 'trusted',
        ));

        wp_send_json_success(array(
            'message' => sprintf(
                /* translators: %s: change type label */
                __('%s will now be applied automatically. It reverts to manual review the moment one gets rolled back.', 'enhanced-content-plugin'),
                ECP_Proposals::type_label($type)
            ),
        ));
    }
}
