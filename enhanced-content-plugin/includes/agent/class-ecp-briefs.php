<?php
/**
 * Strategic content briefs: the complete plan an article must have
 * before a single sentence of it may be drafted.
 *
 * Two hard rules from the gameplan live here as code, not policy:
 *
 *   The information-gain gate. Every brief must name what this page
 *   will add that the existing search results do not already repeat,
 *   and where that contribution comes from — company experience, real
 *   measurements, customer questions. A brief that cannot name one is
 *   stored with the gate closed and the UI warns against drafting it.
 *
 *   No draft without an approved brief. The drafting phase reads only
 *   briefs whose status is approved; there is no other path.
 *
 * Facts the brief requires are cross-checked against the Knowledge
 * Vault on arrival: a claim the vault already verifies is marked so,
 * everything else is counted as outstanding — the owner sees exactly
 * what they must supply before the article can be honest.
 *
 * Campaign sequencing (which brief to publish first) is deterministic
 * PHP, not an AI opinion: foundation before supporting expertise
 * before commercial support, best measured evidence first.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ECP_Briefs {

    /* Owner decisions on a brief. */
    const PROPOSED = 'proposed';
    const APPROVED = 'approved';
    const REJECTED = 'rejected';

    /* Publishing waves, in order. */
    const WAVE_FOUNDATION = 1;
    const WAVE_SUPPORTING = 2;
    const WAVE_COMMERCIAL = 3;

    /** Internal-link candidates offered to the model. */
    const MAX_LINK_TARGETS = 60;

    /* --------------------------------------------------------------------
     * Campaigns: which approved topics are waiting for briefs
     * ----------------------------------------------------------------- */

    /**
     * Approved write-verdict topics grouped into campaigns (one per
     * seed), each topic carrying its wave and any existing brief state.
     *
     * @return array<string,array> seed => { seed, topics: array[] }
     */
    public static function campaigns() {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return array();
        }

        $topics = ECP_DB::topics_table();
        $briefs = ECP_DB::briefs_table();

        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT t.*, b.id AS brief_id, b.status AS brief_status,
                    b.info_gain_ok, b.facts_outstanding, b.wave AS brief_wave
               FROM {$topics} t
               LEFT JOIN {$briefs} b ON b.topic_id = t.id
              WHERE t.status = %s AND t.verdict = %s
              ORDER BY t.seed ASC, t.score DESC",
            ECP_Topical_Map::APPROVED,
            ECP_Topical_Map::WRITE
        ), ARRAY_A);

        $campaigns = array();

        foreach ($rows as $row) {
            $seed = $row['seed'];

            if (!isset($campaigns[$seed])) {
                $campaigns[$seed] = array('seed' => $seed, 'topics' => array());
            }

            $row['wave'] = self::wave_for($row);
            $campaigns[$seed]['topics'][] = $row;
        }

        // Present each campaign in publishing order.
        foreach ($campaigns as &$campaign) {
            usort($campaign['topics'], function ($a, $b) {
                if ((int) $a['wave'] !== (int) $b['wave']) {
                    return (int) $a['wave'] <=> (int) $b['wave'];
                }

                return (float) $b['score'] <=> (float) $a['score'];
            });
        }
        unset($campaign);

        return $campaigns;
    }

    /**
     * The publishing wave a topic belongs to. Deterministic (gameplan
     * §9.3): pillars and awareness informational pages are foundation;
     * commercial and decision-stage pages wait until the foundation
     * exists to link down from; everything else is supporting expertise.
     */
    public static function wave_for(array $topic) {
        if ('' === $topic['parent'] || 'pillar_guide' === $topic['page_type']) {
            return self::WAVE_FOUNDATION;
        }

        $commercial_intents = array('commercial', 'transactional');
        $commercial_stages = array('decision', 'retention');

        if (in_array($topic['intent'], $commercial_intents, true)
            || in_array($topic['funnel_stage'], $commercial_stages, true)
        ) {
            return self::WAVE_COMMERCIAL;
        }

        return self::WAVE_SUPPORTING;
    }

    public static function wave_label($wave) {
        $labels = array(
            self::WAVE_FOUNDATION => __('Wave 1 — Foundation', 'enhanced-content-plugin'),
            self::WAVE_SUPPORTING => __('Wave 2 — Supporting expertise', 'enhanced-content-plugin'),
            self::WAVE_COMMERCIAL => __('Wave 3 — Commercial support', 'enhanced-content-plugin'),
        );

        return isset($labels[$wave]) ? $labels[$wave] : sprintf(
            /* translators: %d: wave number */
            __('Wave %d', 'enhanced-content-plugin'),
            (int) $wave
        );
    }

    /* --------------------------------------------------------------------
     * Building one brief
     * ----------------------------------------------------------------- */

    /**
     * Write the strategic brief for one approved topic. One AI call,
     * on the monthly briefs meter.
     *
     * @return array|WP_Error The stored brief row.
     */
    public static function build($topic_id, array $args = array()) {
        global $wpdb;

        $args = wp_parse_args($args, array('trigger_source' => 'manual'));

        $topic = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . ECP_DB::topics_table() . ' WHERE id = %d',
            (int) $topic_id
        ), ARRAY_A);

        if (!$topic) {
            return new WP_Error('ecp_no_topic', __('That topic no longer exists.', 'enhanced-content-plugin'));
        }

        if (ECP_Topical_Map::APPROVED !== $topic['status'] || ECP_Topical_Map::WRITE !== $topic['verdict']) {
            return new WP_Error('ecp_not_briefable', __('Briefs are written only for approved topics the restraint engine judged worth a new page.', 'enhanced-content-plugin'));
        }

        $existing = self::get((int) $topic_id);

        if ($existing && self::APPROVED === $existing['status']) {
            return new WP_Error('ecp_brief_approved', __('This brief is already approved. Reject it first if it needs a rewrite.', 'enhanced-content-plugin'));
        }

        $allowed = ECP_Limits::can('briefs');

        if (is_wp_error($allowed)) {
            return $allowed;
        }

        $topic['supporting_queries'] = ECP_DB::decode($topic['supporting_queries']);
        $link_targets = self::link_targets($topic);
        $facts = self::vault_facts($topic);

        $response = ECP_AI_Client::request(
            self::system_prompt(),
            self::user_prompt($topic, $link_targets, $facts),
            self::schema(),
            array(
                'job_type'       => 'brief',
                'meter'          => 'briefs',
                'trigger_source' => $args['trigger_source'],
                'max_tokens'     => 24000,
            )
        );

        if (is_wp_error($response)) {
            return $response;
        }

        ECP_Limits::spend('briefs');

        $brief = self::normalise($response['data'], $link_targets, $facts);

        $row = array(
            'topic_id'          => (int) $topic_id,
            'seed'              => $topic['seed'],
            'wave'              => self::wave_for($topic),
            'brief'             => ECP_DB::encode($brief),
            'info_gain'         => $brief['info_gain']['statement'],
            'info_gain_ok'      => $brief['info_gain_ok'] ? 1 : 0,
            'facts_outstanding' => count(array_filter($brief['required_facts'], function ($fact) {
                return empty($fact['in_vault']);
            })),
            'status'            => self::PROPOSED,
            'updated_at'        => ECP_DB::now(),
        );
        $formats = array('%d', '%s', '%d', '%s', '%s', '%d', '%d', '%s', '%s');

        $table = ECP_DB::briefs_table();

        if ($existing) {
            $wpdb->update($table, $row, array('id' => (int) $existing['id']), $formats, array('%d'));
            $row['id'] = (int) $existing['id'];
        } else {
            $row['created_at'] = ECP_DB::now();
            $formats[] = '%s';
            $wpdb->insert($table, $row, $formats);
            $row['id'] = (int) $wpdb->insert_id;
        }

        ECP_Log::info(ECP_Log::BRIEF_CREATED, sprintf(
            /* translators: %s: topic */
            __('Brief written for "%s".', 'enhanced-content-plugin'),
            $topic['topic']
        ), array('run_id' => (int) $response['run_id']));

        $row['brief'] = $brief;

        return $row;
    }

    /* --------------------------------------------------------------------
     * Inputs
     * ----------------------------------------------------------------- */

    /**
     * Pages the article may link to, best cluster-mates first.
     *
     * @return array[] { post_id, title, topic }
     */
    private static function link_targets(array $topic) {
        global $wpdb;

        $inventory = ECP_DB::inventory_table();

        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, title, topic
               FROM {$inventory}
              WHERE post_status = 'publish'
              ORDER BY (topic = %s) DESC, word_count DESC
              LIMIT %d",
            (string) $topic['parent'],
            self::MAX_LINK_TARGETS
        ), ARRAY_A);

        return $rows;
    }

    /**
     * Vault facts relevant to this topic: site-wide plus topic-scoped.
     */
    private static function vault_facts(array $topic) {
        $result = ECP_Vault::query(array('status' => ECP_Vault::ACTIVE, 'limit' => 100));
        $relevant = array();

        foreach ($result['items'] as $fact) {
            $site_wide = 0 === (int) $fact['post_id'] && '' === $fact['topic'];
            $topic_match = '' !== $fact['topic'] && (
                0 === strcasecmp($fact['topic'], $topic['topic'])
                || 0 === strcasecmp($fact['topic'], $topic['parent'])
                || 0 === strcasecmp($fact['topic'], $topic['seed'])
            );

            if ($site_wide || $topic_match) {
                $relevant[] = $fact;
            }
        }

        return array_slice($relevant, 0, 20);
    }

    /* --------------------------------------------------------------------
     * Prompt
     * ----------------------------------------------------------------- */

    private static function info_gain_sources() {
        return array(
            'company_experience', 'expert_explanation', 'proprietary_data',
            'original_measurements', 'customer_questions', 'real_project_examples',
            'product_testing', 'comparison_methodology', 'local_insight',
            'process_details', 'pricing_methodology', 'real_photos',
            'common_mistakes', 'decision_framework', 'downloadable_tool',
            'none',
        );
    }

    private static function system_prompt() {
        $lines = array();

        $lines[] = 'You are a senior content strategist writing the brief an article must satisfy before anyone drafts it.';
        $lines[] = '';
        $lines[] = 'The question that decides whether this brief should exist at all:';
        $lines[] = 'What will this page add that is not already repeated across the current search results for its query? Name the contribution and its source honestly in info_gain. The strongest sources are things only this business has: firsthand experience, real measurements, customer questions, project examples, pricing methodology. If you cannot name a real contribution, set source to "none" and say so plainly — a brief that admits there is nothing new is more useful than one that fakes an angle. Never invent experience the business has not claimed.';
        $lines[] = '';
        $lines[] = 'Other rules:';
        $lines[] = '- required_facts lists every claim the article would need that must come from the business or a citable source — prices, specs, warranties, test results. State each as the claim needing verification, not as an assumed fact.';
        $lines[] = '- Internal links: choose only from the provided page list. Never invent a page.';
        $lines[] = '- media_plan recommends only original media the business can produce — real photos, real data charts, diagrams of their actual process. Never stock imagery, never AI-generated images.';
        $lines[] = '- Structure the article for its search intent and page type, not for word count. Required sections are the ones without which the page fails its reader.';
        $lines[] = '- risks: say what could go wrong — thin overlap with an existing page, claims the business may not be able to support, regulatory exposure.';
        $lines[] = '- success_metrics: measurable, from Search Console — never promise rankings.';

        return implode("\n", $lines);
    }

    private static function user_prompt(array $topic, array $link_targets, array $facts) {
        $out = array();

        $profile = ECP_Site_Profile::prompt_context();

        if ($profile) {
            $out[] = '## The business';
            $out[] = $profile;
            $out[] = '';
        }

        $out[] = '## The approved topic';
        $out[] = 'Topic: ' . $topic['topic'];
        $out[] = 'Cluster: ' . ('' !== $topic['parent'] ? $topic['parent'] : $topic['topic'] . ' (this is the pillar)');
        $out[] = 'Search intent: ' . $topic['intent'] . ' · Funnel stage: ' . $topic['funnel_stage'];
        $out[] = 'Recommended page type: ' . $topic['page_type'];
        $out[] = 'Main query: ' . $topic['main_query'];

        if ($topic['supporting_queries']) {
            $out[] = 'Supporting queries: ' . implode('; ', (array) $topic['supporting_queries']);
        }

        if ($topic['business_relevance']) {
            $out[] = 'Why this business is writing it: ' . $topic['business_relevance'];
        }

        if ($topic['evidence_needs']) {
            $out[] = 'Evidence the map already flagged: ' . $topic['evidence_needs'];
        }

        if ($facts) {
            $out[] = '';
            $out[] = '## Verified facts in the Knowledge Vault';
            $out[] = 'Already confirmed by the owner. Build on these; claims they cover need no further verification.';

            foreach ($facts as $fact) {
                $out[] = '- ' . ($fact['question'] ? $fact['question'] . ' → ' : '') . $fact['fact'];
            }
        }

        if ($link_targets) {
            $out[] = '';
            $out[] = '## Pages this article may link to (choose from these only)';

            foreach ($link_targets as $target) {
                $out[] = sprintf('- %s%s', $target['title'], $target['topic'] ? ' — topic: ' . $target['topic'] : '');
            }
        }

        $out[] = '';
        $out[] = '## What to return';
        $out[] = 'The complete brief. Be specific enough that a writer who has never seen this site could draft the article from the brief alone.';

        return implode("\n", $out);
    }

    private static function schema() {
        return array(
            'type' => 'object',
            'properties' => array(
                'objective'    => array('type' => 'string', 'description' => 'What this page must achieve for the business and the reader.'),
                'audience'     => array('type' => 'string'),
                'unique_angle' => array('type' => 'string'),
                'info_gain'    => array(
                    'type' => 'object',
                    'properties' => array(
                        'source'    => array('type' => 'string', 'enum' => self::info_gain_sources()),
                        'statement' => array('type' => 'string', 'description' => 'What this page adds that current results do not already repeat. Honest; "none" source means say there is nothing.'),
                    ),
                    'required' => array('source', 'statement'),
                    'additionalProperties' => false,
                ),
                'title_options' => array('type' => 'array', 'items' => array('type' => 'string')),
                'slug'          => array('type' => 'string'),
                'sections'      => array(
                    'type' => 'array',
                    'items' => array(
                        'type' => 'object',
                        'properties' => array(
                            'heading'  => array('type' => 'string'),
                            'purpose'  => array('type' => 'string'),
                            'required' => array('type' => 'boolean'),
                        ),
                        'required' => array('heading', 'purpose', 'required'),
                        'additionalProperties' => false,
                    ),
                ),
                'user_questions' => array('type' => 'array', 'items' => array('type' => 'string')),
                'internal_links_out' => array(
                    'type' => 'array',
                    'items' => array(
                        'type' => 'object',
                        'properties' => array(
                            'title'  => array('type' => 'string', 'description' => 'Exact title from the provided list.'),
                            'anchor' => array('type' => 'string'),
                        ),
                        'required' => array('title', 'anchor'),
                        'additionalProperties' => false,
                    ),
                ),
                'internal_links_in' => array(
                    'type' => 'array',
                    'items' => array(
                        'type' => 'object',
                        'properties' => array(
                            'title'  => array('type' => 'string', 'description' => 'Exact title from the provided list of the page that should link here.'),
                            'anchor' => array('type' => 'string'),
                        ),
                        'required' => array('title', 'anchor'),
                        'additionalProperties' => false,
                    ),
                ),
                'required_facts' => array(
                    'type' => 'array',
                    'items' => array(
                        'type' => 'object',
                        'properties' => array(
                            'claim' => array('type' => 'string', 'description' => 'The claim needing verification, stated as a question or requirement.'),
                            'why'   => array('type' => 'string'),
                        ),
                        'required' => array('claim', 'why'),
                        'additionalProperties' => false,
                    ),
                ),
                'media_plan' => array(
                    'type' => 'array',
                    'items' => array(
                        'type' => 'object',
                        'properties' => array(
                            'type'        => array('type' => 'string', 'enum' => array('photo', 'diagram', 'data_chart', 'table', 'video', 'screenshot')),
                            'description' => array('type' => 'string', 'description' => 'The original media the business should produce. Never AI-generated imagery.'),
                        ),
                        'required' => array('type', 'description'),
                        'additionalProperties' => false,
                    ),
                ),
                'cta'             => array('type' => 'string'),
                'schema_type'     => array('type' => 'string', 'description' => 'Recommended schema.org type, e.g. Article, HowTo, FAQPage, Product.'),
                'risks'           => array('type' => 'array', 'items' => array('type' => 'string')),
                'success_metrics' => array('type' => 'array', 'items' => array('type' => 'string')),
            ),
            'required' => array(
                'objective', 'audience', 'unique_angle', 'info_gain', 'title_options',
                'slug', 'sections', 'user_questions', 'internal_links_out',
                'internal_links_in', 'required_facts', 'media_plan', 'cta',
                'schema_type', 'risks', 'success_metrics',
            ),
            'additionalProperties' => false,
        );
    }

    /* --------------------------------------------------------------------
     * Post-processing: the gates
     * ----------------------------------------------------------------- */

    /**
     * Enforce in code what the prompt requested: links only to real
     * pages, facts cross-checked against the vault, the info-gain gate
     * judged from the answer rather than trusted.
     */
    private static function normalise(array $data, array $link_targets, array $facts) {
        $by_title = array();

        foreach ($link_targets as $target) {
            $by_title[strtolower(trim($target['title']))] = (int) $target['post_id'];
        }

        // Links must resolve to a provided page or they are dropped.
        foreach (array('internal_links_out', 'internal_links_in') as $key) {
            $clean = array();

            foreach ((array) (isset($data[$key]) ? $data[$key] : array()) as $link) {
                $title_key = strtolower(trim(isset($link['title']) ? $link['title'] : ''));

                if (isset($by_title[$title_key])) {
                    $link['post_id'] = $by_title[$title_key];
                    $clean[] = $link;
                }
            }

            $data[$key] = $clean;
        }

        // Facts the vault already verifies are marked, everything else
        // is outstanding work for the owner.
        $checked = array();

        foreach ((array) (isset($data['required_facts']) ? $data['required_facts'] : array()) as $required) {
            $required['in_vault'] = false;

            foreach ($facts as $fact) {
                $against = $fact['question'] ? $fact['question'] . ' ' . $fact['fact'] : $fact['fact'];

                if (ECP_Topical_Map::overlap($required['claim'], $against) >= 0.6) {
                    $required['in_vault'] = true;
                    break;
                }
            }

            $checked[] = $required;
        }

        $data['required_facts'] = $checked;

        // The information-gain gate, judged not trusted: a real source
        // and a statement of substance, or the gate stays closed.
        $gain = isset($data['info_gain']) && is_array($data['info_gain'])
            ? wp_parse_args($data['info_gain'], array('source' => 'none', 'statement' => ''))
            : array('source' => 'none', 'statement' => '');

        $data['info_gain'] = array(
            'source'    => $gain['source'],
            'statement' => trim((string) $gain['statement']),
        );
        $data['info_gain_ok'] = 'none' !== $data['info_gain']['source']
            && strlen($data['info_gain']['statement']) >= 40;

        return $data;
    }

    /* --------------------------------------------------------------------
     * Reading and deciding
     * ----------------------------------------------------------------- */

    /**
     * @return array|null Brief row with decoded brief.
     */
    public static function get($topic_id) {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return null;
        }

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . ECP_DB::briefs_table() . ' WHERE topic_id = %d',
            (int) $topic_id
        ), ARRAY_A);

        if (!$row) {
            return null;
        }

        $row['brief'] = ECP_DB::decode($row['brief']);

        return $row;
    }

    /**
     * Approve or reject a brief. Approval is the drafting phase's only
     * entry ticket.
     *
     * @param string $action approve | reject | reopen
     * @return true|WP_Error
     */
    public static function decide($id, $action) {
        global $wpdb;

        $map = array(
            'approve' => self::APPROVED,
            'reject'  => self::REJECTED,
            'reopen'  => self::PROPOSED,
        );

        if (!isset($map[$action])) {
            return new WP_Error('ecp_bad_action', __('Unknown brief action.', 'enhanced-content-plugin'));
        }

        $updated = $wpdb->update(
            ECP_DB::briefs_table(),
            array(
                'status'     => $map[$action],
                'decided_by' => get_current_user_id(),
                'decided_at' => ECP_DB::now(),
                'updated_at' => ECP_DB::now(),
            ),
            array('id' => (int) $id),
            array('%s', '%d', '%s', '%s'),
            array('%d')
        );

        if (false === $updated) {
            return new WP_Error('ecp_not_found', __('That brief no longer exists.', 'enhanced-content-plugin'));
        }

        ECP_Log::record(ECP_Log::BRIEF_DECIDED, array(
            'message' => sprintf(
                /* translators: 1: action, 2: brief id */
                __('Brief #%2$d: %1$s', 'enhanced-content-plugin'),
                $action,
                (int) $id
            ),
        ));

        return true;
    }

    /**
     * Campaign progress (gameplan §9.4 subset): planned pages, briefs
     * written, briefs approved, evidence outstanding.
     *
     * @return array
     */
    public static function progress($seed) {
        global $wpdb;

        $topics = ECP_DB::topics_table();
        $briefs = ECP_DB::briefs_table();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS planned,
                    SUM(b.id IS NOT NULL) AS briefed,
                    SUM(b.status = %s) AS approved,
                    COALESCE(SUM(b.facts_outstanding), 0) AS facts_outstanding
               FROM {$topics} t
               LEFT JOIN {$briefs} b ON b.topic_id = t.id
              WHERE t.seed = %s AND t.status = %s AND t.verdict = %s",
            self::APPROVED,
            $seed,
            ECP_Topical_Map::APPROVED,
            ECP_Topical_Map::WRITE
        ), ARRAY_A);

        return array(
            'planned'           => (int) $row['planned'],
            'briefed'           => (int) $row['briefed'],
            'approved'          => (int) $row['approved'],
            'facts_outstanding' => (int) $row['facts_outstanding'],
        );
    }

    /**
     * Where the drafted articles stand, for the digest: created, still
     * waiting unpublished, and published.
     *
     * @return array { drafted, awaiting_publish, published }
     */
    public static function draft_stats() {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return array('drafted' => 0, 'awaiting_publish' => 0, 'published' => 0);
        }

        $briefs = ECP_DB::briefs_table();
        $posts = $wpdb->posts;

        $row = $wpdb->get_row(
            "SELECT COUNT(*) AS drafted,
                    SUM(p.post_status = 'draft') AS awaiting,
                    SUM(p.post_status = 'publish') AS published
               FROM {$briefs} b
              INNER JOIN {$posts} p ON p.ID = b.draft_post_id
              WHERE b.draft_post_id > 0",
            ARRAY_A
        );

        return array(
            'drafted'          => (int) $row['drafted'],
            'awaiting_publish' => (int) $row['awaiting'],
            'published'        => (int) $row['published'],
        );
    }

    /**
     * Site-wide counters for the digest.
     *
     * @return array { briefed, approved, gated }
     */
    public static function stats() {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return array('briefed' => 0, 'approved' => 0, 'gated' => 0);
        }

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT COUNT(*) AS briefed,
                    SUM(status = %s) AS approved_count,
                    SUM(info_gain_ok = 0) AS gated_count
               FROM ' . ECP_DB::briefs_table(),
            self::APPROVED
        ), ARRAY_A);

        return array(
            'briefed'  => (int) $row['briefed'],
            'approved' => (int) $row['approved_count'],
            'gated'    => (int) $row['gated_count'],
        );
    }
}
