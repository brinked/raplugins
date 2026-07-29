<?php
/**
 * Turns a post plus its evidence into a set of concrete change proposals.
 *
 * The prompt is built around a hard rule: the model is given the deterministic
 * issue list and the Search Console data, and is asked to fix *those* issues.
 * It is not asked "how would you improve this article", which is how you get
 * confident rewrites of pages that were fine.
 *
 * The response comes back through a JSON Schema, so the parsing step is a
 * validation pass rather than a salvage operation.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ECP_Analyzer {

    /**
     * Most changes to accept from one analysis.
     *
     * Enforced in PHP rather than in the schema: structured output rejects
     * array constraints like maxItems with a 400 that kills the whole
     * request. The prompt asks for the same ceiling; this is the backstop.
     */
    const MAX_CHANGES = 8;

    /**
     * The change types a gentle refresh may use on a stale article.
     *
     * The line being drawn: everything here updates what a page already
     * says or how it presents; nothing restructures it. Freshness edits,
     * titles and snippets, tightening, links, alt text, sources, FAQ,
     * schema — yes. New sections and rewrites — by hand, when the owner
     * decides the old article deserves the full treatment.
     */
    const REFRESH_TYPES = array(
        'meta_title',
        'meta_description',
        'freshness_update',
        'section_trim',
        'internal_link_add',
        'image_alt',
        'source_add',
        'faq_add',
        'schema_fix',
    );

    /** An article untouched for this long counts as stale for refresh mode. */
    const STALE_DAYS = 365;

    /**
     * Analyze one post and store the resulting proposals.
     *
     * @param int   $post_id
     * @param array $args { trigger_source, change_types }
     * @return array|WP_Error { proposals: int[], skipped: array, run_id: int }
     */
    public static function analyze($post_id, array $args = array()) {
        $args = wp_parse_args($args, array(
            'trigger_source' => 'cron',
            'change_types'   => null,   // null = whatever settings allow
        ));

        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('ecp_no_post', __('Post not found.', 'enhanced-content-plugin'));
        }

        if (in_array((int) $post_id, ECP_Agent_Settings::excluded_post_ids(), true)) {
            return new WP_Error('ecp_excluded', __('That post is on the exclusion list.', 'enhanced-content-plugin'));
        }

        $signals = ECP_Signals::collect($post);

        // Search behaviour is half the diagnosis. Without it the analyzer
        // cannot see that a page ranks sixth and is never clicked, which is
        // both the most common problem on an established site and the
        // cheapest one to fix.
        $search = ECP_Search_Data::context($post_id);
        $issues = ECP_Signals::issues($signals, $search);

        if (!$issues) {
            ECP_Opportunity_Engine::mark_analyzed($post_id, 0);

            return array('proposals' => array(), 'skipped' => array(), 'run_id' => 0);
        }

        $allowed = null === $args['change_types']
            ? (array) ECP_Agent_Settings::get('enabled_change_types', array())
            : (array) $args['change_types'];

        // Gentle refresh: on a year-old article the automatic pass limits
        // itself to refresh-sized changes — dated facts, titles, snippets,
        // tightening, links. Restructuring a page nobody has touched in a
        // year is a decision its owner should make deliberately, so the
        // full set still applies when they analyze by hand or pass explicit
        // change types.
        $gentle = 'cron' === $args['trigger_source']
            && null === $args['change_types']
            && 'light' === ECP_Agent_Settings::get('stale_refresh', 'light')
            && (int) $signals['days_since_update'] > self::STALE_DAYS;

        if ($gentle) {
            $allowed = array_values(array_intersect($allowed, self::REFRESH_TYPES));
        }

        // Only ask for change types that both the site allows and the issues
        // actually call for. A shorter menu produces better proposals.
        $relevant = array();
        foreach ($issues as $issue) {
            foreach ((array) $issue['fix_types'] as $type) {
                if (in_array($type, $allowed, true)) {
                    $relevant[$type] = true;
                }
            }
        }
        $relevant = array_keys($relevant);

        if (!$relevant) {
            ECP_Opportunity_Engine::mark_analyzed($post_id, 0);

            return array('proposals' => array(), 'skipped' => array('reason' => 'no_enabled_fix_types'), 'run_id' => 0);
        }

        ECP_Opportunity_Engine::set_status($post_id, ECP_Opportunity_Engine::STATUS_ANALYZING);

        ECP_Log::info(ECP_Log::ANALYSIS_STARTED, sprintf(
            /* translators: %s: post title */
            __('Analyzing "%s"', 'enhanced-content-plugin'),
            $post->post_title
        ), array('post_id' => (int) $post_id));

        $metrics = ECP_Search_Data::page_metrics($post_id);
        $queries = ECP_Search_Data::striking_distance_queries($post_id, 8);
        if (!$queries) {
            $queries = ECP_Search_Data::top_queries($post_id, 8);
        }

        $link_targets = self::internal_link_candidates($post_id);

        $response = ECP_AI_Client::request(
            self::system_prompt(),
            self::user_prompt($post, $signals, $issues, $metrics, $queries, $link_targets, $relevant),
            self::schema($relevant),
            array(
                'post_id'        => (int) $post_id,
                'job_type'       => 'analyze',
                'trigger_source' => $args['trigger_source'],
                'max_tokens'     => 24000,
            )
        );

        if (is_wp_error($response)) {
            ECP_Opportunity_Engine::set_status($post_id, ECP_Opportunity_Engine::STATUS_OPEN);

            ECP_Log::error(ECP_Log::ANALYSIS_FAILED, $response->get_error_message(), array(
                'post_id' => (int) $post_id,
            ));

            return $response;
        }

        $created = self::store_proposals($post, $response['data'], (int) $response['run_id'], $signals);

        ECP_AI_Client::set_run_proposals((int) $response['run_id'], count($created['proposals']));
        ECP_Opportunity_Engine::mark_analyzed($post_id, count($created['proposals']));

        ECP_Log::info(ECP_Log::ANALYSIS_COMPLETE, sprintf(
            /* translators: 1: number of changes, 2: post title */
            _n('%1$d change proposed for "%2$s"', '%1$d changes proposed for "%2$s"', count($created['proposals']), 'enhanced-content-plugin'),
            count($created['proposals']),
            $post->post_title
        ), array('post_id' => (int) $post_id, 'run_id' => (int) $response['run_id']));

        return array(
            'proposals' => $created['proposals'],
            'skipped'   => $created['skipped'],
            'run_id'    => (int) $response['run_id'],
        );
    }

    /* --------------------------------------------------------------------
     * Cluster analysis
     * ----------------------------------------------------------------- */

    /**
     * Work out what to do about a group of pages competing for one topic.
     *
     * Emits ordinary proposals for the members that should be retargeted, so
     * they flow through the same guardrails, queue, applier and rollback as
     * everything else. Merge advice is stored on the cluster instead — a
     * merge means deleting a URL and setting up a redirect, and this plugin
     * does not do that silently.
     *
     * @param int   $cluster_id
     * @param array $args { trigger_source }
     * @return array|WP_Error { proposals: int[], recommendation: array }
     */
    public static function analyze_cluster($cluster_id, array $args = array()) {
        $args = wp_parse_args($args, array('trigger_source' => 'cron'));

        $cluster = ECP_Clusters::get($cluster_id);

        if (!$cluster) {
            return new WP_Error('ecp_no_cluster', __('That cluster no longer exists.', 'enhanced-content-plugin'));
        }

        $members = array();

        foreach ((array) $cluster['member_ids'] as $post_id) {
            $post = get_post((int) $post_id);

            if ($post && 'publish' === $post->post_status) {
                $members[] = $post;
            }
        }

        if (count($members) < 2) {
            // The overlap resolved itself — something was deleted or
            // unpublished since detection.
            ECP_Clusters::set_status($cluster_id, ECP_Clusters::STATUS_RESOLVED);

            return array('proposals' => array(), 'recommendation' => array());
        }

        ECP_Clusters::set_status($cluster_id, ECP_Clusters::STATUS_ANALYZING);

        ECP_Log::info(ECP_Log::ANALYSIS_STARTED, sprintf(
            /* translators: %s: the shared query or topic */
            __('Analyzing the pages competing for "%s"', 'enhanced-content-plugin'),
            $cluster['label']
        ), array('post_id' => (int) $cluster['primary_post_id']));

        $response = ECP_AI_Client::request(
            self::cluster_system_prompt(),
            self::cluster_user_prompt($cluster, $members),
            self::cluster_schema($members),
            array(
                'post_id'        => (int) $cluster['primary_post_id'],
                'job_type'       => 'cluster',
                'trigger_source' => $args['trigger_source'],
                'max_tokens'     => 20000,
            )
        );

        if (is_wp_error($response)) {
            ECP_Clusters::set_status($cluster_id, ECP_Clusters::STATUS_OPEN);

            ECP_Log::error(ECP_Log::ANALYSIS_FAILED, $response->get_error_message(), array(
                'post_id' => (int) $cluster['primary_post_id'],
            ));

            return $response;
        }

        $created = self::store_cluster_proposals($cluster, $members, $response['data'], (int) $response['run_id']);

        ECP_AI_Client::set_run_proposals((int) $response['run_id'], count($created['proposals']));

        ECP_Clusters::set_status(
            $cluster_id,
            $created['proposals'] ? ECP_Clusters::STATUS_PROPOSED : ECP_Clusters::STATUS_OPEN,
            array(
                'recommendation'  => ECP_DB::encode($created['recommendation']),
                'primary_post_id' => (int) $created['recommendation']['primary_post_id'],
                'analyzed_at'     => ECP_DB::now(),
            )
        );

        ECP_Log::info(ECP_Log::ANALYSIS_COMPLETE, sprintf(
            /* translators: 1: number of changes, 2: the shared query */
            _n('%1$d change proposed for the pages competing on "%2$s"', '%1$d changes proposed for the pages competing on "%2$s"', count($created['proposals']), 'enhanced-content-plugin'),
            count($created['proposals']),
            $cluster['label']
        ), array('run_id' => (int) $response['run_id']));

        return array(
            'proposals'      => $created['proposals'],
            'recommendation' => $created['recommendation'],
        );
    }

    private static function cluster_system_prompt() {
        $lines = array();

        $lines[] = 'You are an editor deciding what to do about several pages on one website that are competing for the same searches.';
        $lines[] = '';
        $lines[] = 'The problem you are solving: when two pages target the same query, the site splits its own relevance and internal links between them, and usually neither ranks as well as one strong page would.';
        $lines[] = '';
        $lines[] = 'There are only four things you can decide about each page:';
        $lines[] = '- primary: this is the page that should own the topic. Exactly one page gets this.';
        $lines[] = '- differentiate: this page is worth keeping, but it needs to clearly cover a different angle so it stops competing. You must be able to name that angle from what the page already contains — you may sharpen an existing focus, not invent a new subject.';
        $lines[] = '- merge: this page adds nothing the primary does not already cover. Its content should be folded in and the URL redirected.';
        $lines[] = '- leave: it does not actually compete. Pick this when the evidence is weak.';
        $lines[] = '';
        $lines[] = 'Be conservative. "leave" is the right answer whenever the pages genuinely serve different intents and the overlap is coincidental. Recommending "merge" for a page that deserves to exist is far more damaging than leaving a mild overlap alone, because merging destroys a URL.';
        $lines[] = '';
        $lines[] = 'When you choose differentiate, you must supply a replacement SEO title and a replacement opening section. Both must:';
        $lines[] = '- make the distinct angle obvious in the first sentence, so a reader landing from search immediately knows which page they are on;';
        $lines[] = '- link once to the primary page, using its exact URL as given, with anchor text that describes what is there;';
        $lines[] = '- stay truthful to what the page actually contains. Do not promise coverage the page does not have.';
        $lines[] = '';
        $lines[] = 'Rules that override everything:';
        $lines[] = '- Never invent a fact, statistic, date or price.';
        $lines[] = '- Preserve every {{ECP:n}} token exactly as it appears in the opening you are replacing.';
        $lines[] = '- Only ever link to a URL that appears in the material given to you.';
        $lines[] = '- Return HTML for the opening: <p>, <h2>, <h3>, <ul>, <li>, <strong>, <em>, <a>. No classes, no inline styles.';

        foreach (ECP_Agent_Settings::voice_rules() as $rule) {
            $lines[] = '';
            $lines[] = $rule;
        }

        $brand_terms = ECP_Agent_Settings::brand_terms();
        if ($brand_terms) {
            $lines[] = '';
            $lines[] = 'Brand terms, reproduce exactly: ' . implode(', ', $brand_terms) . '.';
        }

        $banned = ECP_Agent_Settings::banned_phrases();
        if ($banned) {
            $lines[] = '';
            $lines[] = 'Never use these phrases: ' . implode('; ', $banned) . '.';
        }

        $tone = trim((string) ECP_Agent_Settings::get('tone_notes', ''));
        if ($tone) {
            $lines[] = '';
            $lines[] = 'House style notes from the site owner: ' . $tone;
        }

        return implode("\n", $lines);
    }

    private static function cluster_user_prompt(array $cluster, array $members) {
        $evidence = is_array($cluster['evidence']) ? $cluster['evidence'] : array();
        $out = array();

        $out[] = '## The overlap';
        $out[] = 'Topic: ' . $cluster['label'];
        $out[] = 'Pages involved: ' . count($members);

        if (!empty($evidence['queries'])) {
            $out[] = '';
            $out[] = '## Measured evidence from Search Console';
            $out[] = 'These are real queries where more than one of these pages is taking impressions. This is the strongest signal you have.';

            foreach ((array) $evidence['queries'] as $query) {
                $out[] = '';
                $out[] = sprintf('Query: "%s" — %d impressions total', $query['query'], (int) $query['impressions']);

                foreach ((array) $query['pages'] as $page) {
                    $out[] = sprintf(
                        '  - post %d: position %.1f, %d impressions, %d clicks',
                        (int) $page['post_id'],
                        (float) $page['position'],
                        (int) $page['impressions'],
                        (int) $page['clicks']
                    );
                }
            }
        } elseif (!empty($evidence['note'])) {
            $out[] = '';
            $out[] = '## Weak evidence only';
            $out[] = $evidence['note'];
            $out[] = 'Because the evidence is weak, prefer "leave" unless the pages are obviously near-duplicates.';
        }

        $out[] = '';
        $out[] = '## The pages';

        foreach ($members as $post) {
            $signals = ECP_Signals::collect($post);
            $metrics = ECP_Search_Data::page_metrics((int) $post->ID);
            $sections = ECP_Content_Map::sections($post);

            $out[] = '';
            $out[] = sprintf('### post_id: %d', (int) $post->ID);
            $out[] = 'Title: ' . $post->post_title;
            $out[] = 'URL: ' . get_permalink($post);
            $out[] = sprintf('Published %s, last updated %s, %d words.', mysql2date('Y-m-d', $post->post_date), mysql2date('Y-m-d', $post->post_modified), (int) $signals['word_count']);
            $out[] = sprintf('Internal links pointing at it: %d.', (int) $signals['inbound_internal_links']);

            if ($metrics) {
                $out[] = sprintf(
                    'Overall: %d clicks, %d impressions, average position %.1f.',
                    $metrics['clicks'],
                    $metrics['impressions'],
                    $metrics['position']
                );
            }

            // The outline is how you judge whether two pages really cover the
            // same ground.
            $headings = array();
            foreach ($sections as $section) {
                if (!$section['is_intro']) {
                    $headings[] = sprintf('%s (%d words)', $section['heading'], $section['words']);
                }
            }

            $out[] = 'Sections: ' . ($headings ? implode(' | ', $headings) : '(no subheadings)');

            // The intro, which is the part that would be rewritten.
            foreach ($sections as $section) {
                if ($section['is_intro']) {
                    $protected = ECP_Content_Map::protect($section['html']);

                    $out[] = sprintf('Opening section id: %s', $section['id']);
                    $out[] = 'Current opening:';
                    $out[] = self::truncate($protected['text'], 1800);
                    break;
                }
            }
        }

        $out[] = '';
        $out[] = '## What to return';
        $out[] = 'Choose exactly one page as primary. For every other page, give a verdict.';
        $out[] = 'Use the post_id numbers above verbatim, and the opening section id verbatim when you supply a replacement opening.';
        $out[] = 'If you recommend merging any page, also give a short checklist of the manual steps a person would need to take, in order.';

        return implode("\n", $out);
    }

    /**
     * @param WP_Post[] $members
     * @return array
     */
    private static function cluster_schema(array $members) {
        $ids = array_map(function ($post) {
            return (int) $post->ID;
        }, $members);

        return array(
            'type' => 'object',
            'properties' => array(
                'summary' => array(
                    'type' => 'string',
                    'description' => 'Two sentences: whether these pages really compete, and what you decided.',
                ),
                'primary_post_id' => array(
                    'type' => 'integer',
                    'enum' => $ids,
                    'description' => 'The page that should own this topic.',
                ),
                'primary_reason' => array(
                    'type' => 'string',
                    'description' => 'Why that page, in one sentence a site owner can check against the data.',
                ),
                'members' => array(
                    'type' => 'array',
                    'items' => array(
                        'type' => 'object',
                        'properties' => array(
                            'post_id' => array('type' => 'integer', 'enum' => $ids),
                            'verdict' => array(
                                'type' => 'string',
                                'enum' => array('primary', 'differentiate', 'merge', 'leave'),
                            ),
                            'rationale' => array('type' => 'string'),
                            'angle' => array(
                                'type' => 'string',
                                'description' => 'For differentiate: the distinct angle this page should own, in a few words. Empty otherwise.',
                            ),
                            'new_title' => array(
                                'type' => 'string',
                                'description' => 'For differentiate: replacement SEO title, 15-70 characters. Empty otherwise.',
                            ),
                            'opening_section_id' => array(
                                'type' => 'string',
                                'description' => 'For differentiate: the opening section id given above. Empty otherwise.',
                            ),
                            'new_opening' => array(
                                'type' => 'string',
                                'description' => 'For differentiate: replacement HTML for the opening section, including one link to the primary page. Empty otherwise.',
                            ),
                            'confidence' => array('type' => 'integer'),
                        ),
                        'required' => array(
                            'post_id', 'verdict', 'rationale', 'angle',
                            'new_title', 'opening_section_id', 'new_opening', 'confidence',
                        ),
                        'additionalProperties' => false,
                    ),
                ),
                'merge_checklist' => array(
                    'type' => 'array',
                    'items' => array('type' => 'string'),
                    'description' => 'Ordered manual steps if any page should be merged. Empty otherwise.',
                ),
            ),
            'required' => array('summary', 'primary_post_id', 'primary_reason', 'members', 'merge_checklist'),
            'additionalProperties' => false,
        );
    }

    /**
     * Turn a cluster verdict into ordinary proposals.
     *
     * @return array { proposals: int[], recommendation: array }
     */
    private static function store_cluster_proposals(array $cluster, array $members, array $data, $run_id) {
        $valid_ids = array_map(function ($post) {
            return (int) $post->ID;
        }, $members);

        $primary_id = isset($data['primary_post_id']) ? (int) $data['primary_post_id'] : 0;

        // The schema constrains this, but a primary outside the member set
        // would silently link every page to the wrong URL.
        if (!in_array($primary_id, $valid_ids, true)) {
            $primary_id = (int) $cluster['primary_post_id'];
        }

        $recommendation = array(
            'summary'         => isset($data['summary']) ? $data['summary'] : '',
            'primary_post_id' => $primary_id,
            'primary_reason'  => isset($data['primary_reason']) ? $data['primary_reason'] : '',
            'merge_checklist' => isset($data['merge_checklist']) ? array_values((array) $data['merge_checklist']) : array(),
            'members'         => array(),
        );

        $proposals = array();
        $entries = isset($data['members']) && is_array($data['members']) ? $data['members'] : array();

        foreach ($entries as $entry) {
            if (!is_array($entry) || empty($entry['post_id'])) {
                continue;
            }

            $post_id = (int) $entry['post_id'];

            if (!in_array($post_id, $valid_ids, true)) {
                continue;
            }

            $verdict = isset($entry['verdict']) ? $entry['verdict'] : 'leave';

            $recommendation['members'][] = array(
                'post_id'    => $post_id,
                'verdict'    => $verdict,
                'rationale'  => isset($entry['rationale']) ? $entry['rationale'] : '',
                'angle'      => isset($entry['angle']) ? $entry['angle'] : '',
                'confidence' => isset($entry['confidence']) ? (int) $entry['confidence'] : 0,
            );

            // Only "differentiate" produces something applicable. "merge" is
            // advice for a human; "primary" and "leave" are no-ops.
            if ('differentiate' !== $verdict || $post_id === $primary_id) {
                continue;
            }

            $post = get_post($post_id);

            if (!$post) {
                continue;
            }

            $signals = ECP_Signals::collect($post);
            $hash = ECP_Content_Map::content_hash($post);

            $shared = array(
                'run_id'       => (int) $run_id,
                'cluster_id'   => (int) $cluster['id'],
                'post_id'      => $post_id,
                'content_hash' => $hash,
                'confidence'   => isset($entry['confidence']) ? (int) $entry['confidence'] : 60,
                'impact'       => (int) min(100, max(40, $cluster['score'])),
                'evidence'     => array(
                    'cluster_label'   => $cluster['label'],
                    'cluster_id'      => (int) $cluster['id'],
                    'primary_post_id' => $primary_id,
                    'angle'           => isset($entry['angle']) ? $entry['angle'] : '',
                    'summary'         => $recommendation['summary'],
                ),
            );

            // 1. Retargeted SEO title.
            if (!empty($entry['new_title'])) {
                $id = self::store_one($post, array_merge($shared, array(
                    'type'      => 'meta_title',
                    'target'    => '',
                    'content'   => $entry['new_title'],
                    'title'     => sprintf(
                        /* translators: %s: the distinct angle for this page */
                        __('Retarget the title to "%s" so it stops competing', 'enhanced-content-plugin'),
                        isset($entry['angle']) ? $entry['angle'] : __('a different angle', 'enhanced-content-plugin')
                    ),
                    'rationale' => isset($entry['rationale']) ? $entry['rationale'] : '',
                )), $signals);

                if ($id) {
                    $proposals[] = $id;
                }
            }

            // 2. Retargeted opening, containing the link to the primary.
            if (!empty($entry['new_opening']) && !empty($entry['opening_section_id'])) {
                $id = self::store_one($post, array_merge($shared, array(
                    'type'      => 'intro_rewrite',
                    'target'    => $entry['opening_section_id'],
                    'content'   => $entry['new_opening'],
                    'title'     => sprintf(
                        /* translators: %s: title of the page that should own the topic */
                        __('Rewrite the opening to focus this page and point to "%s"', 'enhanced-content-plugin'),
                        get_the_title($primary_id)
                    ),
                    'rationale' => isset($entry['rationale']) ? $entry['rationale'] : '',
                )), $signals);

                if ($id) {
                    $proposals[] = $id;
                }
            }
        }

        return array('proposals' => $proposals, 'recommendation' => $recommendation);
    }

    /**
     * Run one cluster-derived change through the normal guardrails and store
     * it. Returns 0 when the guardrails rejected it.
     *
     * @return int
     */
    private static function store_one($post, array $change, array $signals) {
        $verdict = ECP_Guardrails::check($post, $change, $signals);

        if (is_wp_error($verdict)) {
            ECP_Log::warn(ECP_Log::GUARDRAIL_BLOCKED, sprintf(
                /* translators: 1: change type, 2: reason */
                __('Blocked a proposed %1$s from cluster analysis: %2$s', 'enhanced-content-plugin'),
                ECP_Proposals::type_label($change['type']),
                $verdict->get_error_message()
            ), array('post_id' => (int) $post->ID, 'run_id' => (int) $change['run_id']));

            return 0;
        }

        $id = ECP_Proposals::create(array(
            'run_id'       => (int) $change['run_id'],
            'cluster_id'   => (int) $change['cluster_id'],
            'post_id'      => (int) $post->ID,
            'change_type'  => $change['type'],
            'target_key'   => $change['target'],
            'title'        => $change['title'],
            'rationale'    => $change['rationale'],
            'evidence'     => $change['evidence'],
            'before_value' => $verdict['before'],
            'after_value'  => $verdict['after'],
            'payload'      => $verdict['payload'],
            'confidence'   => (int) $change['confidence'],
            'risk'         => $verdict['risk'],
            'impact'       => (int) $change['impact'],
            'flags'        => $verdict['flags'],
            'content_hash' => $change['content_hash'],
        ));

        return is_wp_error($id) ? 0 : (int) $id;
    }

    /* --------------------------------------------------------------------
     * Prompt
     * ----------------------------------------------------------------- */

    private static function system_prompt() {
        $brand_terms = ECP_Agent_Settings::brand_terms();
        $banned = ECP_Agent_Settings::banned_phrases();
        $tone = trim((string) ECP_Agent_Settings::get('tone_notes', ''));
        $reading_level = (string) ECP_Agent_Settings::get('reading_level', 'match');

        $lines = array();

        $lines[] = 'You are an editor working on an existing website. Your job is to fix specific, identified problems on one page — not to rewrite it in your own voice.';
        $lines[] = '';
        $lines[] = 'Rules that override everything else:';
        $lines[] = '- Never invent a fact. No statistics, dates, prices, test results, customer quotes, credentials, policies, or specifications that are not already present in the material you were given. If a section would be better with a number in it, write it without the number.';
        $lines[] = '- Preserve the author\'s voice, vocabulary and sentence rhythm. A reader who knows this site should not be able to tell which paragraphs you touched.';
        $lines[] = '- Preserve every {{ECP:n}} token exactly as it appears. Those stand in for shortcodes, embeds and forms. Reproduce them verbatim, in place. Never delete, reorder, renumber or explain one.';
        $lines[] = '- Keep the existing meaning. You may reorganise, clarify, and add genuinely missing material. You may not change what the page claims.';
        $lines[] = '- Write for the reader, not for a search engine. No keyword stuffing, no repeating the target phrase in every heading.';
        $lines[] = '- Return HTML for body content: <p>, <h2>, <h3>, <ul>, <ol>, <li>, <table>, <strong>, <em>, <a>. No inline styles, no class attributes, no <div>.';
        $lines[] = '- Every change must address one of the numbered issues you were given. If an issue cannot be fixed without inventing something, skip it and say why in "skipped".';
        $lines[] = '';
        $lines[] = 'Confidence and risk, which you must set honestly:';
        $lines[] = '- confidence 90-100: purely mechanical, no judgement involved (alt text for a visible image, a meta description summarising text that is already there).';
        $lines[] = '- confidence 60-89: an editorial judgement you are fairly sure about.';
        $lines[] = '- confidence below 60: a guess. Say so rather than inflating it.';
        $lines[] = '- Set needs_fact_check to true for ANY change containing a number, date, price, product name, statistic, comparison, or claim about the world that you could not verify from the supplied material.';

        foreach (ECP_Agent_Settings::voice_rules() as $rule) {
            $lines[] = '';
            $lines[] = $rule;
        }

        if ($brand_terms) {
            $lines[] = '';
            $lines[] = 'Brand terms — reproduce these exactly, including capitalisation and spacing: ' . implode(', ', $brand_terms) . '.';
        }

        if ($banned) {
            $lines[] = '';
            $lines[] = 'Never use these phrases: ' . implode('; ', $banned) . '.';
        }

        if ($tone) {
            $lines[] = '';
            $lines[] = 'House style notes from the site owner: ' . $tone;
        }

        if ('simpler' === $reading_level) {
            $lines[] = '';
            $lines[] = 'Write more plainly than the source where you can do so without losing precision. Shorter sentences, fewer subordinate clauses.';
        } elseif ('technical' === $reading_level) {
            $lines[] = '';
            $lines[] = 'The audience is technical. Use precise terminology rather than simplifying it away.';
        } else {
            $lines[] = '';
            $lines[] = 'Match the reading level of the existing text.';
        }

        return implode("\n", $lines);
    }

    private static function user_prompt($post, array $signals, array $issues, $metrics, array $queries, array $link_targets, array $allowed_types) {
        $sections = ECP_Content_Map::sections($post);

        $out = array();

        $out[] = '## The page';
        $out[] = 'Title: ' . $post->post_title;
        $out[] = 'URL: ' . get_permalink($post);
        $out[] = 'Published: ' . mysql2date('Y-m-d', $post->post_date);
        $out[] = 'Last modified: ' . mysql2date('Y-m-d', $post->post_modified) . sprintf(' (%d days ago)', (int) $signals['days_since_update']);
        $out[] = 'Word count: ' . (int) $signals['word_count'];
        $out[] = 'Current SEO title: ' . ($signals['seo_title'] ? $signals['seo_title'] : '(none set — search engines use the post title)');
        $out[] = 'Current meta description: ' . ($signals['effective_description'] ? $signals['effective_description'] : '(none)');

        // --- Search data --------------------------------------------------
        $out[] = '';
        $out[] = '## Search performance';

        if ($metrics) {
            $out[] = sprintf(
                'Last 28 days: %d clicks, %d impressions, %.2f%% CTR, average position %.1f.',
                $metrics['clicks'],
                $metrics['impressions'],
                $metrics['ctr'] * 100,
                $metrics['position']
            );
        } else {
            $out[] = 'No Search Console data is connected for this site. Work from the page itself. Do not guess what people search for — do not write "people often ask" or similar unless the questions come from the page content.';
        }

        if ($queries) {
            $out[] = '';
            $out[] = 'Queries this page already ranks for. These are real, measured, and are the strongest signal you have about what readers want from this page:';
            foreach ($queries as $query) {
                $out[] = sprintf(
                    '- "%s" — position %.1f, %d impressions, %d clicks',
                    $query['query'],
                    $query['position'],
                    $query['impressions'],
                    $query['clicks']
                );
            }
        }

        // --- Issues -------------------------------------------------------
        $out[] = '';
        $out[] = '## Issues to fix';
        $out[] = 'Each of these was detected by measurement, not opinion. Address the ones you can.';
        $out[] = '';

        $n = 0;
        foreach ($issues as $issue) {
            $usable = array_intersect((array) $issue['fix_types'], $allowed_types);
            if (!$usable) {
                continue;   // No enabled change type can fix it.
            }

            $n++;
            $out[] = sprintf(
                '%d. [%s] %s — %s (fix with: %s)',
                $n,
                strtoupper($issue['severity']),
                $issue['label'],
                $issue['detail'],
                implode(', ', $usable)
            );

            // Name the exact terms behind a search-derived issue. Telling the
            // model "the snippet underperforms" without saying on which
            // searches leaves it guessing at what the description should say.
            if (!empty($issue['evidence']['queries'])) {
                foreach ($issue['evidence']['queries'] as $query) {
                    $out[] = sprintf(
                        '     · "%s" — position %.1f, %d impressions, %d clicks (%.1f%% CTR)',
                        $query['query'],
                        (float) $query['position'],
                        (int) $query['impressions'],
                        (int) $query['clicks'],
                        (float) $query['ctr'] * 100
                    );
                }
            }

            // Text evidence: the dated sentences behind a freshness issue,
            // the reader questions behind an unanswered-comments issue.
            // Without these the model is told "something here is out of
            // date" and left to guess what — which is how a freshness pass
            // turns into an unnecessary rewrite.
            if (!empty($issue['evidence']['excerpts'])) {
                foreach ((array) $issue['evidence']['excerpts'] as $excerpt) {
                    $out[] = '     · ' . $excerpt;
                }
            }
        }

        // --- Structure -----------------------------------------------------
        $out[] = '';
        $out[] = '## The page, section by section';
        $out[] = 'Use the section_id verbatim when you target a section. Shortcodes and embeds have been replaced with {{ECP:n}} tokens — reproduce them exactly. Token numbering restarts within each section, so {{ECP:1}} in one section is a different thing from {{ECP:1}} in another; only ever reproduce the tokens from the section you are rewriting.';

        foreach ($sections as $section) {
            // Numbering is per-section on purpose: ECP_Guardrails re-protects
            // the single target section at validation time and must produce
            // the identical token names to detect a dropped shortcode.
            $protected = ECP_Content_Map::protect($section['html']);

            $out[] = '';
            $out[] = sprintf(
                '### section_id: %s%s (%d words)',
                $section['id'],
                $section['is_intro'] ? ' — INTRODUCTION (text before the first subheading)' : ' — H' . $section['level'] . ': ' . $section['heading'],
                $section['words']
            );
            $out[] = self::truncate($protected['text'], 6000);
        }

        // --- Internal link candidates ---------------------------------------
        if ($link_targets && in_array('internal_link_add', $allowed_types, true)) {
            $out[] = '';
            $out[] = '## Pages on this site you may link to';
            $out[] = 'Only ever link to a URL from this list. Never invent a URL.';
            foreach ($link_targets as $target) {
                $out[] = sprintf('- %s — %s', $target['url'], $target['title']);
            }
        }

        // --- Existing sources -------------------------------------------------
        if (!empty($signals['sources'])) {
            $out[] = '';
            $out[] = '## Sources already cited on this page';
            foreach ((array) $signals['sources'] as $source) {
                $url = isset($source['url']) ? $source['url'] : '';
                $label = isset($source['label']) ? $source['label'] : '';
                $out[] = '- ' . trim($label . ' ' . $url);
            }
        }

        // --- The ask ----------------------------------------------------------
        $out[] = '';
        $out[] = '## What to return';
        $out[] = sprintf(
            'Propose at most %d changes, ordered with the highest-impact first. Anything past that is discarded. Fewer good changes beat more mediocre ones — returning two changes is a fine answer.',
            self::MAX_CHANGES
        );
        $out[] = 'You may use only these change types: ' . implode(', ', $allowed_types) . '.';
        $out[] = '';
        $out[] = 'Per change type, the "target" field must be:';
        $out[] = '- meta_title, meta_description, schema_fix: leave target empty.';
        $out[] = '- intro_rewrite, section_rewrite, heading_rewrite, freshness_update: the section_id you are replacing.';
        $out[] = '- section_trim: the section_id you are tightening. "content" is the complete replacement HTML with the same facts, steps, caveats and meaning in fewer words. Cut only filler, repetition, throat-clearing and detours. Never cut a fact, an example that carries information, or a warning. If nothing is safely cuttable, skip the issue instead.';
        $out[] = '- section_add: the section_id your new section should follow, or empty to append at the end.';
        $out[] = '- internal_link_add: the section_id containing the phrase you are linking.';
        $out[] = '- image_alt: the image src as given in the issue list.';
        $out[] = '- faq_add, source_add: leave target empty.';
        $out[] = '';
        $out[] = 'For meta_title and meta_description changes, set "style" to the tag that best describes the copy\'s angle (benefit_driven, question, how_to, list, year_fresh, brand, or plain). For every other change type set it to not_applicable. Tag honestly — this site measures which styles actually win clicks here and learns from it.';
        $out[] = '';
        $out[] = 'For rewrites and additions, "content" is the complete replacement HTML for that whole section, heading included.';
        $out[] = 'For internal_link_add, "content" is the complete replacement HTML for the section, with exactly one new <a href> added.';
        $out[] = 'For faq_add, "content" is a JSON array of {"question","answer"} objects.';
        $out[] = 'For source_add, "content" is a JSON array of {"url","label"} objects, using only URLs that already appear in the page text.';

        return implode("\n", $out);
    }

    private static function truncate($text, $limit) {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        return mb_substr($text, 0, $limit) . "\n\n[...section truncated for length; do not rewrite this section...]";
    }

    /**
     * Recent, related posts the model may link to.
     *
     * Restricting the model to a supplied list is the only reliable way to
     * stop it inventing plausible-looking internal URLs.
     *
     * @return array[] { url, title }
     */
    private static function internal_link_candidates($post_id, $limit = 25) {
        $terms = wp_get_post_terms($post_id, 'category', array('fields' => 'ids'));
        if (is_wp_error($terms)) {
            $terms = array();
        }

        $args = array(
            'post_type'      => (array) ECP_Agent_Settings::get('post_types', array('post')),
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'post__not_in'   => array((int) $post_id),
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'no_found_rows'  => true,
        );

        if ($terms) {
            $args['category__in'] = $terms;
        }

        $query = new WP_Query($args);

        $candidates = array();
        foreach ($query->posts as $candidate) {
            $candidates[] = array(
                'url'   => get_permalink($candidate),
                'title' => get_the_title($candidate),
            );
        }

        return $candidates;
    }

    /* --------------------------------------------------------------------
     * Schema
     * ----------------------------------------------------------------- */

    /**
     * The JSON Schema the response is constrained to.
     *
     * @param string[] $allowed_types
     * @return array
     */
    private static function schema(array $allowed_types) {
        return array(
            'type' => 'object',
            'properties' => array(
                'summary' => array(
                    'type' => 'string',
                    'description' => 'One or two sentences on what is wrong with this page and what you did about it.',
                ),
                'changes' => array(
                    'type' => 'array',
                    // No maxItems — structured output rejects array
                    // constraints outright. The ceiling is stated in the
                    // prompt and enforced in store_proposals().
                    'items' => array(
                        'type' => 'object',
                        'properties' => array(
                            'type' => array(
                                'type' => 'string',
                                'enum' => array_values($allowed_types),
                            ),
                            'target' => array(
                                'type' => 'string',
                                'description' => 'section_id, image src, or empty. See the instructions.',
                            ),
                            'title' => array(
                                'type' => 'string',
                                'description' => 'A short label for the review queue, e.g. "Rewrite the introduction to answer the query directly".',
                            ),
                            'content' => array(
                                'type' => 'string',
                                'description' => 'The new value: replacement HTML, the new meta text, or a JSON array for faq_add and source_add.',
                            ),
                            'rationale' => array(
                                'type' => 'string',
                                'description' => 'Why this helps, in plain language a site owner can judge. Reference the issue number and the query data where relevant.',
                            ),
                            'addresses_issue' => array(
                                'type' => 'string',
                                'description' => 'The issue number from the list, e.g. "3".',
                            ),
                            'confidence' => array(
                                'type' => 'integer',
                                'description' => '0-100, calibrated as described in the instructions.',
                            ),
                            'impact' => array(
                                'type' => 'integer',
                                'description' => '0-100. How much this single change is likely to matter for this page.',
                            ),
                            'style' => array(
                                'type' => 'string',
                                'enum' => array('benefit_driven', 'question', 'how_to', 'list', 'year_fresh', 'brand', 'plain', 'not_applicable'),
                                'description' => 'For meta_title and meta_description changes only: the copy\'s angle. benefit_driven leads with the payoff; question mirrors the search; how_to promises steps; list promises N items; year_fresh leads with currency; brand leads with the site name; plain is none of those. Everything else: not_applicable.',
                            ),
                            'needs_fact_check' => array(
                                'type' => 'boolean',
                                'description' => 'True if this contains anything you could not verify from the supplied material.',
                            ),
                            'unverified_claims' => array(
                                'type' => 'array',
                                'items' => array('type' => 'string'),
                                'description' => 'Each specific statement a human must confirm. Empty when needs_fact_check is false.',
                            ),
                        ),
                        'required' => array(
                            'type', 'target', 'title', 'content', 'rationale',
                            'addresses_issue', 'style', 'confidence', 'impact',
                            'needs_fact_check', 'unverified_claims',
                        ),
                        'additionalProperties' => false,
                    ),
                ),
                'skipped' => array(
                    'type' => 'array',
                    'items' => array(
                        'type' => 'object',
                        'properties' => array(
                            'issue'  => array('type' => 'string'),
                            'reason' => array('type' => 'string'),
                        ),
                        'required' => array('issue', 'reason'),
                        'additionalProperties' => false,
                    ),
                    'description' => 'Issues you deliberately did not fix, and why. Missing information is a good reason.',
                ),
            ),
            'required' => array('summary', 'changes', 'skipped'),
            'additionalProperties' => false,
        );
    }

    /* --------------------------------------------------------------------
     * Storing the result
     * ----------------------------------------------------------------- */

    /**
     * Validate each returned change and store the survivors.
     *
     * @return array { proposals: int[], skipped: array[] }
     */
    private static function store_proposals($post, array $data, $run_id, array $signals) {
        $proposals = array();
        $skipped = isset($data['skipped']) && is_array($data['skipped']) ? $data['skipped'] : array();
        $hash = ECP_Content_Map::content_hash($post);
        $changes = isset($data['changes']) && is_array($data['changes']) ? $data['changes'] : array();

        // The schema can no longer cap this, so cap it here. An overlong list
        // means a queue nobody reads, and the model was asked to order by
        // impact, so the tail is what to drop.
        if (count($changes) > self::MAX_CHANGES) {
            $dropped = count($changes) - self::MAX_CHANGES;
            $changes = array_slice($changes, 0, self::MAX_CHANGES);

            ECP_Log::info(ECP_Log::ANALYSIS_COMPLETE, sprintf(
                /* translators: 1: number dropped, 2: post title */
                _n(
                    'Kept the top %1$d changes for "%2$s" and dropped 1 lower-impact suggestion.',
                    'Kept the top %1$d changes for "%2$s" and dropped %3$d lower-impact suggestions.',
                    $dropped,
                    'enhanced-content-plugin'
                ),
                self::MAX_CHANGES,
                $post->post_title,
                $dropped
            ), array('post_id' => (int) $post->ID, 'run_id' => (int) $run_id));
        }

        foreach ($changes as $change) {
            if (!is_array($change) || empty($change['type'])) {
                continue;
            }

            $verdict = ECP_Guardrails::check($post, $change, $signals);

            if (is_wp_error($verdict)) {
                $skipped[] = array(
                    'issue'  => isset($change['title']) ? $change['title'] : $change['type'],
                    'reason' => $verdict->get_error_message(),
                );

                ECP_Log::warn(ECP_Log::GUARDRAIL_BLOCKED, sprintf(
                    /* translators: 1: change type, 2: reason */
                    __('Blocked a proposed %1$s: %2$s', 'enhanced-content-plugin'),
                    ECP_Proposals::type_label($change['type']),
                    $verdict->get_error_message()
                ), array('post_id' => (int) $post->ID, 'run_id' => (int) $run_id));

                continue;
            }

            $id = ECP_Proposals::create(array(
                'run_id'       => (int) $run_id,
                'post_id'      => (int) $post->ID,
                'change_type'  => $change['type'],
                'target_key'   => isset($change['target']) ? $change['target'] : '',
                'title'        => isset($change['title']) ? $change['title'] : ECP_Proposals::type_label($change['type']),
                'rationale'    => isset($change['rationale']) ? $change['rationale'] : '',
                'evidence'     => array(
                    'addresses_issue'   => isset($change['addresses_issue']) ? $change['addresses_issue'] : '',
                    'unverified_claims' => isset($change['unverified_claims']) ? (array) $change['unverified_claims'] : array(),
                    'summary'           => isset($data['summary']) ? $data['summary'] : '',
                    // Site Memory's raw material: what STYLE of copy this
                    // was. Measurement attaches the outcome later, and the
                    // aggregate — "benefit-driven titles outperform on this
                    // site, 4 of 5 tests" — is the institutional memory a
                    // generic tool can never have. Recorded from day one
                    // because outcomes cannot be backfilled onto changes
                    // that never noted their style.
                    'style'             => isset($change['style']) && 'not_applicable' !== $change['style'] ? $change['style'] : '',
                ),
                'before_value' => $verdict['before'],
                'after_value'  => $verdict['after'],
                'payload'      => $verdict['payload'],
                'confidence'   => isset($change['confidence']) ? (int) $change['confidence'] : 50,
                'risk'         => $verdict['risk'],
                'impact'       => isset($change['impact']) ? (int) $change['impact'] : 50,
                'flags'        => $verdict['flags'],
                'content_hash' => $hash,
            ));

            if (is_wp_error($id)) {
                $skipped[] = array(
                    'issue'  => isset($change['title']) ? $change['title'] : $change['type'],
                    'reason' => $id->get_error_message(),
                );
                continue;
            }

            $proposals[] = $id;

            // Autopilot, where the site has opted into it for this type.
            ECP_Trust_Ladder::maybe_auto_apply($id);
        }

        return array('proposals' => $proposals, 'skipped' => $skipped);
    }
}
