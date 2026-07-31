<?php
/**
 * Proposed changes — the model and CRUD for the approval workflow.
 *
 * A proposal is one atomic, reviewable, revertible change to one thing. Not
 * "improve this article" but "replace the meta description with X" or
 * "rewrite the section headed 'Installation'". That granularity is what makes
 * the review queue usable: a reviewer says yes or no to a specific edit they
 * can see in full, rather than to an opaque batch.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ECP_Proposals {

    /* Status values. */
    const PENDING    = 'pending';
    const APPROVED   = 'approved';
    const REJECTED   = 'rejected';
    const APPLIED    = 'applied';
    const REVERTED   = 'reverted';
    const SUPERSEDED = 'superseded';
    const FAILED     = 'failed';
    const EXPIRED    = 'expired';

    /* Risk tiers — these drive what may be auto-applied. */
    const RISK_SAFE      = 'safe';
    const RISK_MODERATE  = 'moderate';
    const RISK_SENSITIVE = 'sensitive';

    /**
     * The change types the agent can propose.
     *
     * `risk` is the *floor*: guardrails can raise a proposal's risk (an
     * unverified factual claim pushes anything to 'sensitive') but never
     * lower it. `target` tells the applier which writer to use.
     *
     * @return array<string,array>
     */
    public static function change_types() {
        static $types = null;

        if (null !== $types) {
            return $types;
        }

        $types = array(
            'meta_title' => array(
                'label'  => __('SEO title', 'enhanced-content-plugin'),
                'risk'   => self::RISK_SAFE,
                'target' => 'meta',
                'help'   => __('Rewrites the title shown in search results. Does not change the post title on the page.', 'enhanced-content-plugin'),
            ),
            'meta_description' => array(
                'label'  => __('Meta description', 'enhanced-content-plugin'),
                'risk'   => self::RISK_SAFE,
                'target' => 'meta',
                'help'   => __('Rewrites the search-result snippet.', 'enhanced-content-plugin'),
            ),
            'post_title' => array(
                'label'  => __('Page title refresh', 'enhanced-content-plugin'),
                'risk'   => self::RISK_MODERATE,
                'target' => 'title',
                'help'   => __('Updates the visible page title — the headline readers see — typically to refresh an outdated year once the content genuinely reflects it. The URL never changes.', 'enhanced-content-plugin'),
            ),
            'image_alt' => array(
                'label'  => __('Image alt text', 'enhanced-content-plugin'),
                'risk'   => self::RISK_SAFE,
                'target' => 'attachment',
                'help'   => __('Describes an image for screen readers and image search.', 'enhanced-content-plugin'),
            ),
            'source_add' => array(
                'label'  => __('Add a source', 'enhanced-content-plugin'),
                'risk'   => self::RISK_MODERATE,
                'target' => 'sources',
                'help'   => __('Adds a citation to the article sources list.', 'enhanced-content-plugin'),
            ),
            'internal_link_add' => array(
                'label'  => __('Add an internal link', 'enhanced-content-plugin'),
                'risk'   => self::RISK_MODERATE,
                'target' => 'content',
                'help'   => __('Links a phrase in the body to another page on this site.', 'enhanced-content-plugin'),
            ),
            'heading_rewrite' => array(
                'label'  => __('Rewrite a heading', 'enhanced-content-plugin'),
                'risk'   => self::RISK_MODERATE,
                'target' => 'section',
                'help'   => __('Changes a subheading. Note that this changes the section\'s identity.', 'enhanced-content-plugin'),
            ),
            'intro_rewrite' => array(
                'label'  => __('Rewrite the introduction', 'enhanced-content-plugin'),
                'risk'   => self::RISK_MODERATE,
                'target' => 'section',
                'help'   => __('Replaces the opening paragraphs before the first subheading.', 'enhanced-content-plugin'),
            ),
            'section_rewrite' => array(
                'label'  => __('Rewrite a section', 'enhanced-content-plugin'),
                'risk'   => self::RISK_MODERATE,
                'target' => 'section',
                'help'   => __('Replaces one section of the article, heading included.', 'enhanced-content-plugin'),
            ),
            'section_add' => array(
                'label'  => __('Add a new section', 'enhanced-content-plugin'),
                'risk'   => self::RISK_MODERATE,
                'target' => 'section_insert',
                'help'   => __('Inserts a new section covering a topic the article is missing.', 'enhanced-content-plugin'),
            ),
            'section_trim' => array(
                'label'  => __('Tighten a section', 'enhanced-content-plugin'),
                'risk'   => self::RISK_MODERATE,
                'target' => 'section',
                'help'   => __('Cuts filler, repetition and detours from a section without losing any fact, step or caveat. The one change type whose job is to make a page shorter.', 'enhanced-content-plugin'),
            ),
            'faq_add' => array(
                'label'  => __('Add FAQ entries', 'enhanced-content-plugin'),
                'risk'   => self::RISK_MODERATE,
                'target' => 'faq',
                'help'   => __('Adds question/answer pairs to the FAQ block and its schema.', 'enhanced-content-plugin'),
            ),
            'schema_fix' => array(
                'label'  => __('Fix structured data', 'enhanced-content-plugin'),
                'risk'   => self::RISK_SAFE,
                'target' => 'meta',
                'help'   => __('Corrects a schema.org field.', 'enhanced-content-plugin'),
            ),
            'freshness_update' => array(
                'label'  => __('Refresh dated content', 'enhanced-content-plugin'),
                'risk'   => self::RISK_SENSITIVE,
                'target' => 'section',
                'help'   => __('Updates statements that have gone out of date. Always needs review — the agent cannot know what is currently true.', 'enhanced-content-plugin'),
            ),
        );

        /**
         * Filter the registry of proposable change types.
         *
         * @param array $types
         */
        $types = apply_filters('ecp_change_types', $types);

        return $types;
    }

    /**
     * @return array|null
     */
    public static function change_type($type) {
        $types = self::change_types();

        return isset($types[$type]) ? $types[$type] : null;
    }

    public static function type_label($type) {
        $info = self::change_type($type);

        return $info ? $info['label'] : ucwords(str_replace('_', ' ', (string) $type));
    }

    public static function risk_label($risk) {
        $labels = array(
            self::RISK_SAFE      => __('Safe', 'enhanced-content-plugin'),
            self::RISK_MODERATE  => __('Needs a look', 'enhanced-content-plugin'),
            self::RISK_SENSITIVE => __('Check the facts', 'enhanced-content-plugin'),
        );

        return isset($labels[$risk]) ? $labels[$risk] : $risk;
    }

    public static function status_label($status) {
        $labels = array(
            self::PENDING    => __('Awaiting review', 'enhanced-content-plugin'),
            self::APPROVED   => __('Approved', 'enhanced-content-plugin'),
            self::REJECTED   => __('Rejected', 'enhanced-content-plugin'),
            self::APPLIED    => __('Applied', 'enhanced-content-plugin'),
            self::REVERTED   => __('Rolled back', 'enhanced-content-plugin'),
            self::SUPERSEDED => __('Superseded', 'enhanced-content-plugin'),
            self::FAILED     => __('Failed', 'enhanced-content-plugin'),
            self::EXPIRED    => __('Expired', 'enhanced-content-plugin'),
        );

        return isset($labels[$status]) ? $labels[$status] : $status;
    }

    /* --------------------------------------------------------------------
     * Create
     * ----------------------------------------------------------------- */

    /**
     * Insert a proposal.
     *
     * @param array $data
     * @return int|WP_Error Proposal id.
     */
    public static function create(array $data) {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return new WP_Error('ecp_no_tables', __('The agent database tables are missing.', 'enhanced-content-plugin'));
        }

        $data = wp_parse_args($data, array(
            'run_id'       => 0,
            'cluster_id'   => 0,
            'post_id'      => 0,
            'change_type'  => '',
            'target_key'   => '',
            'title'        => '',
            'rationale'    => '',
            'evidence'     => array(),
            'before_value' => '',
            'after_value'  => '',
            'payload'      => array(),
            'confidence'   => 0,
            'risk'         => self::RISK_MODERATE,
            'impact'       => 0,
            'flags'        => array(),
            'content_hash' => '',
        ));

        if (!$data['post_id'] || !$data['change_type']) {
            return new WP_Error('ecp_proposal_incomplete', __('A proposal needs a post and a change type.', 'enhanced-content-plugin'));
        }

        // Supersede any pending proposal that targets the same thing, so the
        // queue never shows two competing edits to one field.
        self::supersede_conflicting((int) $data['post_id'], $data['change_type'], (string) $data['target_key']);

        $inserted = $wpdb->insert(
            ECP_DB::proposals_table(),
            array(
                'run_id'       => (int) $data['run_id'],
                'cluster_id'   => (int) $data['cluster_id'],
                'post_id'      => (int) $data['post_id'],
                'change_type'  => substr((string) $data['change_type'], 0, 48),
                'target_key'   => substr((string) $data['target_key'], 0, 191),
                'title'        => substr((string) $data['title'], 0, 255),
                'rationale'    => (string) $data['rationale'],
                'evidence'     => ECP_DB::encode($data['evidence']),
                'before_value' => (string) $data['before_value'],
                'after_value'  => (string) $data['after_value'],
                'payload'      => ECP_DB::encode($data['payload']),
                'confidence'   => max(0, min(100, (int) $data['confidence'])),
                'risk'         => (string) $data['risk'],
                'impact'       => max(0, min(100, (int) $data['impact'])),
                'flags'        => ECP_DB::encode($data['flags']),
                'status'       => self::PENDING,
                'content_hash' => (string) $data['content_hash'],
                'created_at'   => ECP_DB::now(),
            ),
            array('%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s')
        );

        if (!$inserted) {
            return new WP_Error('ecp_proposal_insert_failed', __('Could not save the proposal.', 'enhanced-content-plugin'));
        }

        $id = (int) $wpdb->insert_id;

        ECP_Log::record(ECP_Log::PROPOSAL_CREATED, array(
            'message'     => sprintf(
                /* translators: 1: change type label, 2: post title */
                __('%1$s proposed for "%2$s"', 'enhanced-content-plugin'),
                self::type_label($data['change_type']),
                get_the_title((int) $data['post_id'])
            ),
            'post_id'     => (int) $data['post_id'],
            'proposal_id' => $id,
            'run_id'      => (int) $data['run_id'],
            'user_id'     => 0,
            'context'     => array('risk' => $data['risk'], 'confidence' => $data['confidence']),
        ));

        return $id;
    }

    /**
     * Mark older pending proposals for the same target as superseded.
     */
    private static function supersede_conflicting($post_id, $change_type, $target_key) {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            "UPDATE " . ECP_DB::proposals_table() . "
             SET status = %s
             WHERE post_id = %d AND change_type = %s AND target_key = %s AND status = %s",
            self::SUPERSEDED,
            (int) $post_id,
            (string) $change_type,
            (string) $target_key,
            self::PENDING
        ));
    }

    /* --------------------------------------------------------------------
     * Read
     * ----------------------------------------------------------------- */

    /**
     * @return array|null
     */
    public static function get($id) {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return null;
        }

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . ECP_DB::proposals_table() . ' WHERE id = %d',
            (int) $id
        ), ARRAY_A);

        return $row ? self::hydrate($row) : null;
    }

    private static function hydrate(array $row) {
        $row['evidence'] = ECP_DB::decode($row['evidence']);
        $row['payload'] = ECP_DB::decode($row['payload']);
        $row['flags'] = ECP_DB::decode($row['flags']);
        $row['revert_data'] = ECP_DB::decode($row['revert_data']);

        return $row;
    }

    /**
     * Query proposals.
     *
     * `author` scopes results to posts written by that user. Pass
     * ECP_Capabilities::author_scope() so a restricted reviewer never sees a
     * proposal they could not act on — filtering only at approve time would
     * still leak other people's titles and draft copy into the queue.
     *
     * @param array $args { status, post_id, change_type, risk, cluster_id, author, per_page, paged, orderby, order }
     * @return array { items, total }
     */
    public static function query(array $args = array()) {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return array('items' => array(), 'total' => 0);
        }

        $args = wp_parse_args($args, array(
            'status'      => self::PENDING,
            'post_id'     => 0,
            'change_type' => '',
            'risk'        => '',
            'cluster_id'  => 0,
            'author'      => 0,
            'per_page'    => 20,
            'paged'       => 1,
            'orderby'     => 'priority',
            'order'       => 'DESC',
        ));

        $table = ECP_DB::proposals_table();
        $posts = $wpdb->posts;

        $where = array('1=1');
        $params = array();

        if ($args['status']) {
            if (is_array($args['status'])) {
                $placeholders = implode(',', array_fill(0, count($args['status']), '%s'));
                $where[] = "p.status IN ({$placeholders})";
                $params = array_merge($params, array_values($args['status']));
            } else {
                $where[] = 'p.status = %s';
                $params[] = $args['status'];
            }
        }

        if ($args['post_id']) {
            $where[] = 'p.post_id = %d';
            $params[] = (int) $args['post_id'];
        }

        if ($args['change_type']) {
            $where[] = 'p.change_type = %s';
            $params[] = $args['change_type'];
        }

        if ($args['risk']) {
            $where[] = 'p.risk = %s';
            $params[] = $args['risk'];
        }

        if ($args['cluster_id']) {
            $where[] = 'p.cluster_id = %d';
            $params[] = (int) $args['cluster_id'];
        }

        if ($args['author']) {
            $where[] = 'wp.post_author = %d';
            $params[] = (int) $args['author'];
        }

        $where_sql = implode(' AND ', $where);

        // "priority" sorts the queue the way a reviewer wants it: biggest
        // expected win first, ties broken by confidence.
        $orderby_map = array(
            'priority'   => '(p.impact * 2 + p.confidence)',
            'created'    => 'p.id',
            'post'       => 'wp.post_title',
            'risk'       => "FIELD(p.risk,'safe','moderate','sensitive')",
            'type'       => 'p.change_type',
            'confidence' => 'p.confidence',
        );
        $orderby = isset($orderby_map[$args['orderby']]) ? $orderby_map[$args['orderby']] : $orderby_map['priority'];
        $order = 'ASC' === strtoupper($args['order']) ? 'ASC' : 'DESC';

        $count_sql = "SELECT COUNT(*) FROM {$table} p INNER JOIN {$posts} wp ON wp.ID = p.post_id WHERE {$where_sql}";
        $total = (int) ($params ? $wpdb->get_var($wpdb->prepare($count_sql, $params)) : $wpdb->get_var($count_sql));

        $per_page = max(1, min(100, (int) $args['per_page']));
        $offset = max(0, ((int) $args['paged'] - 1)) * $per_page;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.*, wp.post_title, wp.post_type
             FROM {$table} p
             INNER JOIN {$posts} wp ON wp.ID = p.post_id
             WHERE {$where_sql}
             ORDER BY {$orderby} {$order}, p.id DESC
             LIMIT %d OFFSET %d",
            array_merge($params, array($per_page, $offset))
        ), ARRAY_A);

        $items = array();
        foreach ((array) $rows as $row) {
            $items[] = self::hydrate($row);
        }

        return array('items' => $items, 'total' => $total);
    }

    /**
     * Counts per status, for the review screen's tabs.
     *
     * Scoped to what the current user is allowed to see, so a restricted
     * reviewer's tab counts match the list underneath them.
     *
     * @return array
     */
    public static function counts() {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return array();
        }

        $table = ECP_DB::proposals_table();
        $author = ECP_Capabilities::author_scope();

        $join = '';
        $where = '';
        $params = array();

        if ($author) {
            $join = "INNER JOIN {$wpdb->posts} wp ON wp.ID = p.post_id";
            $where = 'WHERE wp.post_author = %d';
            $params[] = (int) $author;
        }

        $sql = "SELECT p.status, COUNT(*) AS total FROM {$table} p {$join} {$where} GROUP BY p.status";
        $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);

        $counts = array();
        foreach ((array) $rows as $row) {
            $counts[$row['status']] = (int) $row['total'];
        }

        // Break pending down by risk — the queue is triaged by risk tier.
        $risk_where = $where ? $where . ' AND p.status = %s' : 'WHERE p.status = %s';
        $risk_params = array_merge($params, array(self::PENDING));

        $risk_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.risk, COUNT(*) AS total FROM {$table} p {$join} {$risk_where} GROUP BY p.risk",
            $risk_params
        ), ARRAY_A);

        $counts['pending_by_risk'] = array();
        foreach ((array) $risk_rows as $row) {
            $counts['pending_by_risk'][$row['risk']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * How many proposals are waiting for the current user. Drives the menu
     * bubble and the toolbar item, so it has to respect their scope — a
     * badge showing 40 that opens onto an empty queue is worse than no badge.
     */
    public static function pending_count() {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return 0;
        }

        $table = ECP_DB::proposals_table();
        $author = ECP_Capabilities::author_scope();

        if ($author) {
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} p
                 INNER JOIN {$wpdb->posts} wp ON wp.ID = p.post_id
                 WHERE p.status = %s AND wp.post_author = %d",
                self::PENDING,
                (int) $author
            ));
        }

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE status = %s",
            self::PENDING
        ));
    }

    /* --------------------------------------------------------------------
     * Update
     * ----------------------------------------------------------------- */

    /**
     * @param int   $id
     * @param array $fields
     * @return bool
     */
    public static function update($id, array $fields) {
        global $wpdb;

        if (!$fields) {
            return false;
        }

        // Re-encode the JSON columns if the caller passed arrays.
        foreach (array('evidence', 'payload', 'flags', 'revert_data') as $key) {
            if (isset($fields[$key]) && is_array($fields[$key])) {
                $fields[$key] = ECP_DB::encode($fields[$key]);
            }
        }

        return false !== $wpdb->update(
            ECP_DB::proposals_table(),
            $fields,
            array('id' => (int) $id),
            null,
            array('%d')
        );
    }

    /**
     * Approve a proposal. Does not apply it — ECP_Applier does that, so an
     * approval can be recorded even if the write later fails.
     *
     * @return true|WP_Error
     */
    public static function approve($id, $note = '') {
        $proposal = self::get($id);

        if (!$proposal) {
            return new WP_Error('ecp_not_found', __('That change no longer exists.', 'enhanced-content-plugin'));
        }

        if (self::PENDING !== $proposal['status']) {
            return new WP_Error('ecp_not_pending', sprintf(
                /* translators: %s: current status label */
                __('That change is already %s.', 'enhanced-content-plugin'),
                self::status_label($proposal['status'])
            ));
        }

        if (!ECP_Capabilities::can_review((int) $proposal['post_id'])) {
            return new WP_Error('ecp_forbidden', __('You do not have permission to approve changes to that post.', 'enhanced-content-plugin'));
        }

        self::update($id, array(
            'status'      => self::APPROVED,
            'reviewed_by' => get_current_user_id(),
            'reviewed_at' => ECP_DB::now(),
            'review_note' => (string) $note,
        ));

        ECP_Log::record(ECP_Log::PROPOSAL_APPROVED, array(
            'message'     => sprintf(
                /* translators: 1: change type, 2: post title */
                __('Approved %1$s on "%2$s"', 'enhanced-content-plugin'),
                self::type_label($proposal['change_type']),
                get_the_title((int) $proposal['post_id'])
            ),
            'post_id'     => (int) $proposal['post_id'],
            'proposal_id' => (int) $id,
        ));

        // Deliberately NOT recorded in the trust ladder here. An approval
        // whose apply then fails never changed the site, and counting it
        // would inflate the record that decides when a change type may run
        // unattended. The applier records the decision once the write is
        // confirmed — see ECP_Applier::count_trust_decision().

        return true;
    }

    /**
     * @return true|WP_Error
     */
    public static function reject($id, $note = '') {
        $proposal = self::get($id);

        if (!$proposal) {
            return new WP_Error('ecp_not_found', __('That change no longer exists.', 'enhanced-content-plugin'));
        }

        // Only states where "no thanks" is meaningful. Critically, APPLIED is
        // not one of them: rejecting an applied change would strand it in a
        // status the reverter refuses to touch, silently destroying the undo
        // path. An applied change must be rolled back, not rejected.
        $rejectable = array(self::PENDING, self::APPROVED, self::FAILED);

        if (!in_array($proposal['status'], $rejectable, true)) {
            return new WP_Error('ecp_not_rejectable', sprintf(
                /* translators: %s: current status label */
                __('That change is %s. If it has been applied, use "Undo" instead of rejecting it.', 'enhanced-content-plugin'),
                self::status_label($proposal['status'])
            ));
        }

        if (!ECP_Capabilities::can_review((int) $proposal['post_id'])) {
            return new WP_Error('ecp_forbidden', __('You do not have permission to approve changes to that post.', 'enhanced-content-plugin'));
        }

        self::update($id, array(
            'status'      => self::REJECTED,
            'reviewed_by' => get_current_user_id(),
            'reviewed_at' => ECP_DB::now(),
            'review_note' => (string) $note,
        ));

        ECP_Log::record(ECP_Log::PROPOSAL_REJECTED, array(
            'message'     => sprintf(
                /* translators: 1: change type, 2: post title */
                __('Rejected %1$s on "%2$s"', 'enhanced-content-plugin'),
                self::type_label($proposal['change_type']),
                get_the_title((int) $proposal['post_id'])
            ),
            'post_id'     => (int) $proposal['post_id'],
            'proposal_id' => (int) $id,
            'context'     => array('note' => (string) $note),
        ));

        ECP_Trust_Ladder::record_decision($proposal['change_type'], false);

        return true;
    }

    /**
     * Edit the proposed text before approving it.
     *
     * The edited version is what gets applied, and the original is kept in
     * flags so the audit trail shows the reviewer changed it.
     *
     * @return true|WP_Error
     */
    public static function edit($id, $new_value) {
        $proposal = self::get($id);

        if (!$proposal) {
            return new WP_Error('ecp_not_found', __('That change no longer exists.', 'enhanced-content-plugin'));
        }

        if (self::PENDING !== $proposal['status']) {
            return new WP_Error('ecp_not_pending', __('Only pending changes can be edited.', 'enhanced-content-plugin'));
        }

        if (!ECP_Capabilities::can_review((int) $proposal['post_id'])) {
            return new WP_Error('ecp_forbidden', __('You do not have permission to approve changes to that post.', 'enhanced-content-plugin'));
        }

        $flags = is_array($proposal['flags']) ? $proposal['flags'] : array();
        if (!isset($flags['original_after_value'])) {
            $flags['original_after_value'] = $proposal['after_value'];
        }
        $flags['human_edited'] = true;

        self::update($id, array(
            'after_value' => (string) $new_value,
            'flags'       => $flags,
        ));

        ECP_Log::record(ECP_Log::PROPOSAL_EDITED, array(
            'message'     => sprintf(
                /* translators: %s: change type */
                __('Edited the proposed %s before approving', 'enhanced-content-plugin'),
                self::type_label($proposal['change_type'])
            ),
            'post_id'     => (int) $proposal['post_id'],
            'proposal_id' => (int) $id,
        ));

        return true;
    }

    /**
     * Expire pending proposals older than the TTL. Run from cron.
     *
     * Stale proposals are worse than no proposals: the post has probably moved
     * on, and applying a month-old rewrite silently reverts newer edits.
     *
     * @return int Number expired.
     */
    public static function expire_stale() {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return 0;
        }

        $days = (int) ECP_Agent_Settings::get('proposal_ttl_days', 30);

        // created_at is written with current_time('mysql'), which is site-local
        // time — so the cutoff must be site-local too. The previous GMT-based
        // cutoff shifted the TTL by the site's UTC offset.
        $cutoff = gmdate('Y-m-d H:i:s', strtotime("-{$days} days", (int) current_time('timestamp')));

        $ids = $wpdb->get_col($wpdb->prepare(
            'SELECT id FROM ' . ECP_DB::proposals_table() . ' WHERE status = %s AND created_at < %s LIMIT 500',
            self::PENDING,
            $cutoff
        ));

        if (!$ids) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . ECP_DB::proposals_table() . " SET status = %s WHERE id IN ({$placeholders})",
            array_merge(array(self::EXPIRED), array_map('intval', $ids))
        ));

        ECP_Log::info(ECP_Log::PROPOSAL_EXPIRED, sprintf(
            /* translators: 1: count, 2: number of days */
            _n('%1$d change expired after %2$d days without review.', '%1$d changes expired after %2$d days without review.', count($ids), 'enhanced-content-plugin'),
            count($ids),
            $days
        ));

        return count($ids);
    }

    /**
     * Every proposal for a post, grouped for the per-post review view.
     *
     * @return array[]
     */
    public static function for_post($post_id, $status = self::PENDING) {
        $result = self::query(array(
            'post_id'  => (int) $post_id,
            'status'   => $status,
            'per_page' => 100,
        ));

        return $result['items'];
    }

    /**
     * Posts that currently have pending proposals, newest first.
     *
     * @return array[] { post_id, post_title, total, safe, moderate, sensitive, newest }
     */
    public static function pending_posts($limit = 50) {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return array();
        }

        $table = ECP_DB::proposals_table();
        $posts = $wpdb->posts;
        $author = ECP_Capabilities::author_scope();

        $where = 'p.status = %s';
        $params = array(self::PENDING);

        if ($author) {
            $where .= ' AND wp.post_author = %d';
            $params[] = (int) $author;
        }

        $params[] = max(1, min(200, (int) $limit));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.post_id,
                    wp.post_title,
                    COUNT(*) AS total,
                    SUM(p.risk = 'safe') AS safe,
                    SUM(p.risk = 'moderate') AS moderate,
                    SUM(p.risk = 'sensitive') AS sensitive,
                    MAX(p.created_at) AS newest,
                    MAX(p.impact) AS top_impact
             FROM {$table} p
             INNER JOIN {$posts} wp ON wp.ID = p.post_id
             WHERE {$where}
             GROUP BY p.post_id, wp.post_title
             ORDER BY top_impact DESC, newest DESC
             LIMIT %d",
            $params
        ), ARRAY_A);

        return $rows ? $rows : array();
    }
}
