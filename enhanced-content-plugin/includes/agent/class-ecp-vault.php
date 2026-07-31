<?php
/**
 * The Knowledge Vault: everything the owner has told the agent is true.
 *
 * "Never invent a fact" only works if the facts the owner *has* supplied
 * are somewhere the agent can actually use them. Before the vault they
 * lived in per-post meta: an answer given on one article was invisible to
 * every other article, invisible to the owner, and current forever. The
 * vault makes owner knowledge a first-class store — site-wide or tied to
 * a page, browsable, correctable, retirable, and dated so that a price
 * confirmed two years ago reads as two years old rather than as true.
 *
 * The honesty contract, same as Site Memory's: everything in the vault
 * was typed by a human with review rights. The agent quotes facts, it
 * never writes them; retiring a fact removes it from every future prompt.
 *
 * SaaS seam: pure reads and writes over one table. In the SaaS this is
 * a backend service with per-plan fact limits via ECP_Limits.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ECP_Vault {

    /* A fact is active (fed to prompts), retired (kept as history), or
     * pending — mined from the site's own content and waiting for a
     * human to confirm it. Pending facts never feed a prompt: the vault
     * holds nothing as true that a person has not signed off on. */
    const ACTIVE  = 'active';
    const RETIRED = 'retired';
    const PENDING = 'pending';

    /** Facts older than this read as "worth re-confirming" in the UI. */
    const STALE_DAYS = 365;

    /** Most facts fed into any single prompt. */
    const PROMPT_LIMIT = 20;

    /* --------------------------------------------------------------------
     * Writing
     * ----------------------------------------------------------------- */

    /**
     * Add a fact, or replace the answer to the same question on the same
     * scope — answering twice must never stack a contradiction the model
     * would have to choose from.
     *
     * @param array $args { fact, question, post_id, topic, source }
     * @return int|WP_Error Fact id.
     */
    public static function add(array $args) {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return new WP_Error('ecp_no_tables', __('The agent database tables are missing.', 'enhanced-content-plugin'));
        }

        $args = wp_parse_args($args, array(
            'fact'        => '',
            'question'    => '',
            'post_id'     => 0,
            'topic'       => '',
            'source'      => 'manual',
            'verified_at' => '',   // Migration passes the original answer date.
            'status'      => self::ACTIVE,   // The miner submits PENDING.
            'evidence'    => array(),        // Provenance: { source_post_id, quote }.
        ));

        $fact = trim(sanitize_textarea_field($args['fact']));
        $question = trim(sanitize_textarea_field($args['question']));

        if ('' === $fact) {
            return new WP_Error('ecp_no_fact', __('The fact is empty.', 'enhanced-content-plugin'));
        }

        $table = ECP_DB::facts_table();
        $now = ECP_DB::now();
        $verified = '' !== $args['verified_at'] ? $args['verified_at'] : $now;
        $status = self::PENDING === $args['status'] ? self::PENDING : self::ACTIVE;

        // Same question, same scope: replace, don't stack. With one hard
        // rule: a machine-mined PENDING submission never overwrites or
        // duplicates what already exists — a human's answer outranks the
        // miner, and a question already queued stays queued once.
        if ('' !== $question) {
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE post_id = %d AND question = %s AND status IN (%s, %s)",
                (int) $args['post_id'],
                $question,
                self::ACTIVE,
                self::PENDING
            ));

            if ($existing && self::PENDING === $status) {
                return (int) $existing;
            }

            if ($existing) {
                $wpdb->update(
                    $table,
                    array(
                        'fact'        => $fact,
                        'topic'       => sanitize_text_field($args['topic']),
                        'status'      => self::ACTIVE,
                        'verified_at' => $verified,
                        'updated_at'  => $now,
                    ),
                    array('id' => (int) $existing),
                    array('%s', '%s', '%s', '%s', '%s'),
                    array('%d')
                );

                return (int) $existing;
            }
        }

        $wpdb->insert(
            $table,
            array(
                'post_id'     => (int) $args['post_id'],
                'question'    => $question,
                'fact'        => $fact,
                'topic'       => sanitize_text_field($args['topic']),
                'source'      => sanitize_key($args['source']),
                'evidence'    => ECP_DB::encode($args['evidence']),
                'status'      => $status,
                'verified_at' => self::PENDING === $status ? null : $verified,
                'created_by'  => get_current_user_id(),
                'created_at'  => $now,
                'updated_at'  => $now,
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s')
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * Edit a fact's text, topic or scope. Editing re-verifies: the owner
     * just looked at it.
     *
     * @return true|WP_Error
     */
    public static function update_fact($id, array $args) {
        global $wpdb;

        $data = array('updated_at' => ECP_DB::now(), 'verified_at' => ECP_DB::now());
        $formats = array('%s', '%s');

        if (isset($args['fact'])) {
            $fact = trim(sanitize_textarea_field($args['fact']));

            if ('' === $fact) {
                return new WP_Error('ecp_no_fact', __('The fact is empty.', 'enhanced-content-plugin'));
            }

            $data['fact'] = $fact;
            $formats[] = '%s';
        }

        if (isset($args['topic'])) {
            $data['topic'] = sanitize_text_field($args['topic']);
            $formats[] = '%s';
        }

        if (isset($args['post_id'])) {
            $data['post_id'] = (int) $args['post_id'];
            $formats[] = '%d';
        }

        $updated = $wpdb->update(ECP_DB::facts_table(), $data, array('id' => (int) $id), $formats, array('%d'));

        if (false === $updated) {
            return new WP_Error('ecp_not_found', __('That fact no longer exists.', 'enhanced-content-plugin'));
        }

        return true;
    }

    /**
     * Retire a fact: out of every future prompt, kept as history.
     */
    public static function retire($id) {
        return self::set_status($id, self::RETIRED);
    }

    /**
     * Bring a retired fact back, freshly verified.
     */
    public static function restore($id) {
        return self::set_status($id, self::ACTIVE, array('verified_at' => ECP_DB::now()));
    }

    /**
     * The owner confirms the fact is still true today.
     */
    public static function confirm($id) {
        return self::set_status($id, self::ACTIVE, array('verified_at' => ECP_DB::now()));
    }

    /**
     * Confirm a mined fact as true for the whole site rather than just
     * the page whose analysis raised the question.
     */
    public static function confirm_site_wide($id) {
        return self::set_status($id, self::ACTIVE, array(
            'post_id'     => 0,
            'verified_at' => ECP_DB::now(),
        ));
    }

    /**
     * Delete a fact outright. Only sensible for pending mined noise a
     * human rejected — real facts are retired, which keeps history.
     */
    public static function discard($id) {
        global $wpdb;

        $deleted = $wpdb->delete(ECP_DB::facts_table(), array('id' => (int) $id), array('%d'));

        if (!$deleted) {
            return new WP_Error('ecp_not_found', __('That fact no longer exists.', 'enhanced-content-plugin'));
        }

        return true;
    }

    private static function set_status($id, $status, array $extra = array()) {
        global $wpdb;

        $updated = $wpdb->update(
            ECP_DB::facts_table(),
            array_merge($extra, array('status' => $status, 'updated_at' => ECP_DB::now())),
            array('id' => (int) $id),
            null,
            array('%d')
        );

        if (false === $updated) {
            return new WP_Error('ecp_not_found', __('That fact no longer exists.', 'enhanced-content-plugin'));
        }

        return true;
    }

    /* --------------------------------------------------------------------
     * Reading
     * ----------------------------------------------------------------- */

    /**
     * The facts the agent may use when working on one post: facts tied to
     * the post itself, site-wide facts, and facts tagged with the post's
     * classified topic. Post-specific first — they are the most likely to
     * be about exactly this page.
     *
     * @return array[] Rows.
     */
    public static function for_post($post_id, $limit = self::PROMPT_LIMIT) {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return array();
        }

        $table = ECP_DB::facts_table();
        $post_id = (int) $post_id;

        $topic = '';

        if ($post_id) {
            $topic = (string) $wpdb->get_var($wpdb->prepare(
                'SELECT topic FROM ' . ECP_DB::inventory_table() . ' WHERE post_id = %d',
                $post_id
            ));
        }

        $where = 'status = %s AND (post_id = %d OR post_id = 0';
        $params = array(self::ACTIVE, $post_id);

        if ('' !== $topic) {
            $where .= ' OR topic = %s';
            $params[] = $topic;
        }

        $where .= ')';
        $params[] = $post_id;
        $params[] = max(1, (int) $limit);

        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
              WHERE {$where}
              ORDER BY (post_id = %d) DESC, verified_at DESC
              LIMIT %d",
            $params
        ), ARRAY_A);
    }

    /**
     * The prompt block: verified facts as data lines, or nothing. The
     * caller decides where in the user message it belongs.
     *
     * @return string
     */
    public static function prompt_context($post_id) {
        $facts = self::for_post($post_id);

        if (!$facts) {
            return '';
        }

        $out = array();
        $out[] = '## Facts the site owner has confirmed';
        $out[] = 'These were supplied and verified by the site owner. You may use them freely, must not contradict them, and must still never invent facts beyond them.';

        foreach ($facts as $fact) {
            $line = '- ' . $fact['fact'];

            if (!empty($fact['question'])) {
                $line = '- ' . $fact['question'] . ' → ' . $fact['fact'];
            }

            $out[] = $line;
        }

        return implode("\n", $out);
    }

    /**
     * Browse the vault.
     *
     * @param array $args { status, search, post_id: int|null, limit, offset }
     * @return array { items, total }
     */
    public static function query($args = array()) {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return array('items' => array(), 'total' => 0);
        }

        $args = wp_parse_args($args, array(
            'status' => self::ACTIVE,
            'search' => '',
            'post_id' => null,
            'limit'  => 50,
            'offset' => 0,
        ));

        $table = ECP_DB::facts_table();
        $where = array('1=1');
        $params = array();

        if ($args['status']) {
            $where[] = 'status = %s';
            $params[] = $args['status'];
        }

        if ('' !== $args['search']) {
            $where[] = '(fact LIKE %s OR question LIKE %s OR topic LIKE %s)';
            $like = '%' . $wpdb->esc_like($args['search']) . '%';
            array_push($params, $like, $like, $like);
        }

        if (null !== $args['post_id']) {
            $where[] = 'post_id = %d';
            $params[] = (int) $args['post_id'];
        }

        $where_sql = implode(' AND ', $where);

        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
        $total = (int) ($params
            ? $wpdb->get_var($wpdb->prepare($count_sql, $params))
            : $wpdb->get_var($count_sql));  // phpcs:ignore WordPress.DB.PreparedSQL

        $params[] = max(1, min(200, (int) $args['limit']));
        $params[] = max(0, (int) $args['offset']);

        $items = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY verified_at DESC LIMIT %d OFFSET %d",
            $params
        ), ARRAY_A);

        return array('items' => $items, 'total' => $total);
    }

    /**
     * @return int Active facts in the vault.
     */
    public static function count_active() {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return 0;
        }

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . ECP_DB::facts_table() . ' WHERE status = %s',
            self::ACTIVE
        ));
    }

    /**
     * Whether a verified date is old enough to be worth re-confirming.
     */
    public static function is_stale($verified_at) {
        if (!$verified_at) {
            return true;
        }

        return (current_time('timestamp') - strtotime($verified_at)) > self::STALE_DAYS * DAY_IN_SECONDS;
    }

    /* --------------------------------------------------------------------
     * Migration from the per-post meta embryo
     * ----------------------------------------------------------------- */

    /**
     * Sweep every `_ecp_owner_facts` post meta entry into the vault.
     *
     * Idempotent: add() replaces same-question-same-post rather than
     * duplicating, so running twice is harmless. The meta is left in
     * place untouched — it stops being read, and uninstall deletes it.
     *
     * @return int Facts migrated.
     */
    public static function migrate_meta() {
        global $wpdb;

        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
            ECP_Content_Gaps::FACTS_META
        ), ARRAY_A);

        $migrated = 0;

        foreach ($rows as $row) {
            $facts = maybe_unserialize($row['meta_value']);

            if (!is_array($facts)) {
                continue;
            }

            foreach ($facts as $fact) {
                if (empty($fact['answer'])) {
                    continue;
                }

                $id = self::add(array(
                    'fact'        => $fact['answer'],
                    'question'    => isset($fact['question']) ? $fact['question'] : '',
                    'post_id'     => (int) $row['post_id'],
                    'source'      => 'owner_answer',
                    'verified_at' => isset($fact['answered_at']) ? $fact['answered_at'] : '',
                ));

                if (!is_wp_error($id)) {
                    $migrated++;
                }
            }
        }

        if ($migrated) {
            ECP_Log::info('vault.migrated', sprintf(
                /* translators: %d: number of facts */
                __('Moved %d owner-supplied facts into the Knowledge Vault.', 'enhanced-content-plugin'),
                $migrated
            ));
        }

        return $migrated;
    }
}
