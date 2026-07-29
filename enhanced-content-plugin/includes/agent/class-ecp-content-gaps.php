<?php
/**
 * What a reader arrives wanting to know, and whether the page answers it.
 *
 * Every other check in this plugin asks "is anything wrong with this page?".
 * This one asks a better question: "is this page actually the best answer to
 * the thing someone typed?" A perfectly formatted article with a good meta
 * description and tidy headings can still fail to answer the six things every
 * reader of that title wants to know.
 *
 * Three design decisions keep this from becoming a content-bloat machine:
 *
 *   Grounded, not guessed. The question list is derived from the title *and*
 *   from the searches that actually bring people to the page. A question with
 *   real impressions behind it is evidence; one the model imagined is a
 *   hypothesis, and they are labelled differently.
 *
 *   Facts it cannot know are asked, not invented. "How much does it cost" is
 *   a question the agent must never answer on your behalf. Those become
 *   questions *for you*, stored so that once you answer one it becomes a
 *   verified fact the agent may use from then on.
 *
 *   Few, good additions. Left alone, this kind of analysis will happily bolt
 *   ten thin sections onto an article and make it worse. Proposals are capped
 *   and ordered by what the evidence supports.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ECP_Content_Gaps {

    /** Most sections to propose adding from one gap analysis. */
    const MAX_ADDITIONS = 3;

    /** Where answers you supply are kept, per post. */
    const FACTS_META = '_ecp_owner_facts';

    /* Coverage verdicts the model may return. */
    const COVERED   = 'covered';
    const THIN      = 'thin';
    const MISSING   = 'missing';
    const ELSEWHERE = 'belongs_elsewhere';

    /* --------------------------------------------------------------------
     * Running the analysis
     * ----------------------------------------------------------------- */

    /**
     * Work out what a reader wants from this page and what is missing.
     *
     * @param int   $post_id
     * @param array $args { trigger_source }
     * @return array|WP_Error { report, proposals, questions }
     */
    public static function analyze($post_id, array $args = array()) {
        $args = wp_parse_args($args, array('trigger_source' => 'manual'));

        $post = get_post($post_id);

        if (!$post) {
            return new WP_Error('ecp_no_post', __('Post not found.', 'enhanced-content-plugin'));
        }

        if (in_array((int) $post_id, ECP_Agent_Settings::excluded_post_ids(), true)) {
            return new WP_Error('ecp_excluded', __('That post is on the exclusion list.', 'enhanced-content-plugin'));
        }

        $signals = ECP_Signals::collect($post);
        $search = ECP_Search_Data::context($post_id);
        $facts = self::owner_facts($post_id);

        ECP_Log::info(ECP_Log::ANALYSIS_STARTED, sprintf(
            /* translators: %s: post title */
            __('Looking for content gaps in "%s"', 'enhanced-content-plugin'),
            $post->post_title
        ), array('post_id' => (int) $post_id));

        $response = ECP_AI_Client::request(
            self::system_prompt(),
            self::user_prompt($post, $signals, $search, $facts),
            self::schema(),
            array(
                'post_id'        => (int) $post_id,
                'job_type'       => 'gaps',
                'trigger_source' => $args['trigger_source'],
                'max_tokens'     => 24000,
            )
        );

        if (is_wp_error($response)) {
            ECP_Log::error(ECP_Log::ANALYSIS_FAILED, $response->get_error_message(), array(
                'post_id' => (int) $post_id,
            ));

            return $response;
        }

        $report = self::normalise_report($response['data'], $search);

        // Stamp the content state this audit describes, so the scheduler can
        // tell a current report from one describing a page that has since
        // been edited — and never re-audits an unchanged article.
        $report['content_hash'] = ECP_Content_Map::content_hash($post);
        $report['analyzed_at'] = current_time('mysql');

        self::store_report($post_id, $report);

        // Whether the audit also drafts the missing sections. A human
        // clicking "Find content gaps" asked for the full result; the
        // unattended run respects the configured mode, and drops to
        // report-only on stale articles under gentle refresh — an audit of
        // a year-old page is information, but restructuring it is a
        // decision its owner should take deliberately.
        $propose = true;

        if ('cron' === $args['trigger_source']) {
            $signals_age = isset($signals['days_since_update']) ? (int) $signals['days_since_update'] : 0;

            $propose = 'propose' === ECP_Agent_Settings::get('gap_mode', 'propose')
                && !('light' === ECP_Agent_Settings::get('stale_refresh', 'light') && $signals_age > ECP_Analyzer::STALE_DAYS);
        }

        $proposals = $propose
            ? self::propose_fills($post, $report, (int) $response['run_id'], $signals)
            : array();

        ECP_AI_Client::set_run_proposals((int) $response['run_id'], count($proposals));

        ECP_Log::info(ECP_Log::ANALYSIS_COMPLETE, sprintf(
            /* translators: 1: number of gaps, 2: post title */
            _n('%1$d content gap found in "%2$s"', '%1$d content gaps found in "%2$s"', count($report['gaps']), 'enhanced-content-plugin'),
            count($report['gaps']),
            $post->post_title
        ), array('post_id' => (int) $post_id, 'run_id' => (int) $response['run_id']));

        return array(
            'report'    => $report,
            'proposals' => $proposals,
            'questions' => $report['for_you'],
        );
    }

    /* --------------------------------------------------------------------
     * Prompt
     * ----------------------------------------------------------------- */

    private static function system_prompt() {
        $lines = array();

        $lines[] = 'You are an experienced editor auditing whether an article actually answers what its reader came for.';
        $lines[] = '';
        $lines[] = 'Work in this order:';
        $lines[] = '1. From the title, the opening, and the searches that bring people here, work out who the reader is and what situation they are in when they arrive.';
        $lines[] = '2. List the 8 to 12 things that reader most needs answered before they would feel the page had done its job. Order them by how much the reader cares, not by how easy they are to write.';
        $lines[] = '3. For each one, judge honestly whether the article already answers it.';
        $lines[] = '';
        $lines[] = 'Judging coverage — be strict, because the point of this exercise is to find what is missing:';
        $lines[] = '- covered: a reader would finish the article satisfied on this point. Say which section.';
        $lines[] = '- thin: the article touches it but a reader would still be unsure or would go and search again.';
        $lines[] = '- missing: not addressed at all.';
        $lines[] = '- belongs_elsewhere: a real reader question, but answering it here would drag the article off-topic. It should be a link to another page instead.';
        $lines[] = '';
        $lines[] = 'The rule that matters most:';
        $lines[] = 'Some questions cannot be answered without facts only the site owner has — prices, warranty terms, delivery times, dimensions, materials, compatibility, stock, company policy, test results, anything specific to their business. For those, set can_answer_from_page to false and write exactly what you would need to be told in needed_from_owner. Never guess at such a fact, never write a placeholder, and never soften it into vague language to avoid the problem. A question you cannot answer honestly is still a valuable finding — it tells the owner what to go and write down.';
        $lines[] = '';
        $lines[] = 'When you can answer a question from what is already on the page or from general knowledge that needs no verification, write the section. Rules for that content:';
        $lines[] = '- Match the voice, vocabulary and reading level of the existing article exactly.';
        $lines[] = '- Open by answering the question directly. No throat-clearing.';
        $lines[] = '- No invented statistics, dates, prices, brand claims or test results.';
        $lines[] = '- HTML only: <h2>, <h3>, <p>, <ul>, <ol>, <li>, <strong>, <em>, <table>. No classes or inline styles.';
        $lines[] = '- Start the section with its own heading, phrased the way a reader would think about it rather than as a keyword.';
        $lines[] = '';
        $lines[] = 'Finally, be honest about a well-covered article. If the page genuinely answers almost everything, say so and return few gaps. Padding a good article with thin extra sections makes it worse, and a short honest report is more useful than a long invented one.';

        foreach (ECP_Agent_Settings::voice_rules() as $rule) {
            $lines[] = '';
            $lines[] = $rule;
        }

        $tone = trim((string) ECP_Agent_Settings::get('tone_notes', ''));
        if ($tone) {
            $lines[] = '';
            $lines[] = 'House style notes from the site owner: ' . $tone;
        }

        $banned = ECP_Agent_Settings::banned_phrases();
        if ($banned) {
            $lines[] = '';
            $lines[] = 'Never use these phrases: ' . implode('; ', $banned) . '.';
        }

        $brand = ECP_Agent_Settings::brand_terms();
        if ($brand) {
            $lines[] = '';
            $lines[] = 'Brand terms, reproduce exactly: ' . implode(', ', $brand) . '.';
        }

        return implode("\n", $lines);
    }

    private static function user_prompt($post, array $signals, $search, array $facts) {
        $out = array();

        $out[] = '## The article';
        $out[] = 'Title: ' . $post->post_title;
        $out[] = 'URL: ' . get_permalink($post);
        $out[] = sprintf('%d words across %d sections.', (int) $signals['word_count'], (int) $signals['section_count']);

        $out[] = '';
        $out[] = '## What it currently covers';
        $out[] = 'Section ids are given so you can point at one when you judge something thin.';

        foreach (ECP_Content_Map::sections($post) as $section) {
            $protected = ECP_Content_Map::protect($section['html']);

            $out[] = '';
            $out[] = sprintf(
                '### %s — %s (%d words)',
                $section['id'],
                $section['is_intro'] ? '(introduction)' : $section['heading'],
                $section['words']
            );
            $out[] = mb_substr($protected['text'], 0, 2500);
        }

        // --- Real demand ----------------------------------------------------
        if ($search && !empty($search['queries'])) {
            $out[] = '';
            $out[] = '## What people actually search before landing here';
            $out[] = 'This is measured, not guessed. A question backed by one of these is worth far more than one you infer from the title alone — mark those with backed_by_search.';

            foreach (array_slice($search['queries'], 0, 25) as $query) {
                $out[] = sprintf(
                    '- "%s" — %d impressions, position %.1f',
                    $query['query'],
                    (int) $query['impressions'],
                    (float) $query['position']
                );
            }
        } else {
            $out[] = '';
            $out[] = '## No search data';
            $out[] = 'There is no Search Console data for this page, so you are working from the title and content alone. Be more conservative: infer fewer questions, and set backed_by_search to false on all of them.';
        }

        // --- Facts the owner has already supplied -------------------------------
        if ($facts) {
            $out[] = '';
            $out[] = '## Facts the site owner has confirmed';
            $out[] = 'These are verified. You may use them freely and should treat any question they answer as answerable.';

            foreach ($facts as $fact) {
                $out[] = sprintf('- %s → %s', $fact['question'], $fact['answer']);
            }
        }

        if (!empty($signals['sources'])) {
            $out[] = '';
            $out[] = '## Sources already cited here';
            foreach ((array) $signals['sources'] as $source) {
                $out[] = '- ' . trim((isset($source['label']) ? $source['label'] : '') . ' ' . (isset($source['url']) ? $source['url'] : ''));
            }
        }

        $out[] = '';
        $out[] = '## What to return';
        $out[] = 'The reader, their situation, and the ordered list of what they need answered with your honest verdict on each.';
        $out[] = sprintf('Write full replacement content for at most %d of the gaps — the ones with the strongest case and which you can answer without inventing anything.', self::MAX_ADDITIONS);
        $out[] = 'For every other gap, still return the question and your verdict. A gap you cannot fill is still worth reporting.';

        return implode("\n", $out);
    }

    private static function schema() {
        return array(
            'type' => 'object',
            'properties' => array(
                'reader'  => array(
                    'type' => 'string',
                    'description' => 'Who arrives at this page and what situation they are in. One or two sentences.',
                ),
                'verdict' => array(
                    'type' => 'string',
                    'description' => 'Honest overall judgement of how completely this article serves that reader.',
                ),
                'completeness' => array(
                    'type' => 'integer',
                    'description' => '0-100. How much of what the reader needs the article already delivers.',
                ),
                'questions' => array(
                    'type' => 'array',
                    'items' => array(
                        'type' => 'object',
                        'properties' => array(
                            'question' => array(
                                'type' => 'string',
                                'description' => 'Phrased the way the reader would think it, not as a keyword.',
                            ),
                            'why_it_matters' => array('type' => 'string'),
                            'coverage' => array(
                                'type' => 'string',
                                'enum' => array('covered', 'thin', 'missing', 'belongs_elsewhere'),
                            ),
                            'section_id' => array(
                                'type' => 'string',
                                'description' => 'Where it is covered or thinly covered. Empty when missing.',
                            ),
                            'backed_by_search' => array(
                                'type' => 'boolean',
                                'description' => 'True only when a listed search query supports this question.',
                            ),
                            'supporting_query' => array(
                                'type' => 'string',
                                'description' => 'The query, when backed_by_search is true. Empty otherwise.',
                            ),
                            'can_answer_from_page' => array(
                                'type' => 'boolean',
                                'description' => 'False when answering needs a fact only the site owner has.',
                            ),
                            'needed_from_owner' => array(
                                'type' => 'string',
                                'description' => 'Exactly what you would need to be told. Empty when you can answer.',
                            ),
                            'priority' => array(
                                'type' => 'integer',
                                'description' => '1-10, how much the reader cares.',
                            ),
                            'proposed_heading' => array(
                                'type' => 'string',
                                'description' => 'Heading for the new section, if you wrote one. Empty otherwise.',
                            ),
                            'proposed_content' => array(
                                'type' => 'string',
                                'description' => 'Full replacement HTML including the heading, if you wrote one. Empty otherwise.',
                            ),
                            'after_section_id' => array(
                                'type' => 'string',
                                'description' => 'Which existing section the new one should follow. Empty to append at the end.',
                            ),
                        ),
                        'required' => array(
                            'question', 'why_it_matters', 'coverage', 'section_id',
                            'backed_by_search', 'supporting_query', 'can_answer_from_page',
                            'needed_from_owner', 'priority', 'proposed_heading',
                            'proposed_content', 'after_section_id',
                        ),
                        'additionalProperties' => false,
                    ),
                ),
            ),
            'required' => array('reader', 'verdict', 'completeness', 'questions'),
            'additionalProperties' => false,
        );
    }

    /* --------------------------------------------------------------------
     * Turning the answer into something usable
     * ----------------------------------------------------------------- */

    /**
     * Split the model's answer into covered / gaps / questions-for-you.
     */
    private static function normalise_report(array $data, $search) {
        $questions = isset($data['questions']) && is_array($data['questions']) ? $data['questions'] : array();

        $covered = array();
        $gaps = array();
        $for_you = array();
        $elsewhere = array();

        foreach ($questions as $entry) {
            if (!is_array($entry) || empty($entry['question'])) {
                continue;
            }

            $entry = wp_parse_args($entry, array(
                'why_it_matters'       => '',
                'coverage'             => self::MISSING,
                'section_id'           => '',
                'backed_by_search'     => false,
                'supporting_query'     => '',
                'can_answer_from_page' => true,
                'needed_from_owner'    => '',
                'priority'             => 5,
                'proposed_heading'     => '',
                'proposed_content'     => '',
                'after_section_id'     => '',
            ));

            switch ($entry['coverage']) {
                case self::COVERED:
                    $covered[] = $entry;
                    break;

                case self::ELSEWHERE:
                    $elsewhere[] = $entry;
                    break;

                default:
                    if (empty($entry['can_answer_from_page'])) {
                        $for_you[] = $entry;
                    } else {
                        $gaps[] = $entry;
                    }
            }
        }

        // Evidence first, then the reader's own priority. A question with
        // measured search volume behind it beats a plausible guess.
        $sort = function ($a, $b) {
            if ($a['backed_by_search'] !== $b['backed_by_search']) {
                return $a['backed_by_search'] ? -1 : 1;
            }

            return (int) $b['priority'] <=> (int) $a['priority'];
        };

        usort($gaps, $sort);
        usort($for_you, $sort);

        return array(
            'generated_at'  => current_time('mysql'),
            'reader'        => isset($data['reader']) ? $data['reader'] : '',
            'verdict'       => isset($data['verdict']) ? $data['verdict'] : '',
            'completeness'  => isset($data['completeness']) ? max(0, min(100, (int) $data['completeness'])) : 0,
            'had_search'    => (bool) $search,
            'covered'       => $covered,
            'gaps'          => $gaps,
            'for_you'       => $for_you,
            'elsewhere'     => $elsewhere,
        );
    }

    /**
     * Create proposals for the gaps the model could fill safely.
     *
     * @return int[]
     */
    private static function propose_fills($post, array $report, $run_id, array $signals) {
        $created = array();
        $hash = ECP_Content_Map::content_hash($post);
        $filled = 0;

        foreach ($report['gaps'] as $gap) {
            if ($filled >= self::MAX_ADDITIONS) {
                break;
            }

            if (empty($gap['proposed_content'])) {
                continue;   // Reported as a gap, but nothing written for it.
            }

            $is_rewrite = self::THIN === $gap['coverage'] && !empty($gap['section_id']);

            $change = array(
                'type'    => $is_rewrite ? 'section_rewrite' : 'section_add',
                'target'  => $is_rewrite ? $gap['section_id'] : $gap['after_section_id'],
                'content' => $gap['proposed_content'],
                'title'   => $is_rewrite
                    ? sprintf(
                        /* translators: %s: the reader's question */
                        __('Expand this section to answer "%s"', 'enhanced-content-plugin'),
                        $gap['question']
                    )
                    : sprintf(
                        /* translators: %s: the reader's question */
                        __('Add a section answering "%s"', 'enhanced-content-plugin'),
                        $gap['question']
                    ),
                'rationale' => trim($gap['why_it_matters'] . ' ' . (
                    $gap['backed_by_search'] && $gap['supporting_query']
                        ? sprintf(
                            /* translators: %s: search query */
                            __('People reach this page searching "%s".', 'enhanced-content-plugin'),
                            $gap['supporting_query']
                        )
                        : __('Inferred from the title and the article itself, not from measured searches.', 'enhanced-content-plugin')
                )),
                // A question grounded in real search data is a much stronger
                // case than one inferred from the title.
                'confidence' => $gap['backed_by_search'] ? 80 : 60,
            );

            $verdict = ECP_Guardrails::check($post, $change, $signals);

            if (is_wp_error($verdict)) {
                ECP_Log::warn(ECP_Log::GUARDRAIL_BLOCKED, sprintf(
                    /* translators: 1: question, 2: reason */
                    __('Blocked a gap-fill for "%1$s": %2$s', 'enhanced-content-plugin'),
                    $gap['question'],
                    $verdict->get_error_message()
                ), array('post_id' => (int) $post->ID, 'run_id' => (int) $run_id));

                continue;
            }

            $id = ECP_Proposals::create(array(
                'run_id'       => (int) $run_id,
                'post_id'      => (int) $post->ID,
                'change_type'  => $change['type'],
                'target_key'   => $change['target'],
                'title'        => $change['title'],
                'rationale'    => $change['rationale'],
                'evidence'     => array(
                    'source'           => 'content_gap',
                    'question'         => $gap['question'],
                    'backed_by_search' => (bool) $gap['backed_by_search'],
                    'supporting_query' => $gap['supporting_query'],
                    'reader'           => $report['reader'],
                ),
                'before_value' => $verdict['before'],
                'after_value'  => $verdict['after'],
                'payload'      => $verdict['payload'],
                'confidence'   => (int) $change['confidence'],
                'risk'         => $verdict['risk'],
                'impact'       => (int) min(100, ((int) $gap['priority']) * 10),
                'flags'        => $verdict['flags'],
                'content_hash' => $hash,
            ));

            if (!is_wp_error($id)) {
                $created[] = (int) $id;
                $filled++;
            }
        }

        return $created;
    }

    /* --------------------------------------------------------------------
     * Storage
     * ----------------------------------------------------------------- */

    public static function store_report($post_id, array $report) {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return false;
        }

        return (bool) $wpdb->update(
            ECP_DB::opportunities_table(),
            array('gap_report' => ECP_DB::encode($report), 'updated_at' => ECP_DB::now()),
            array('post_id' => (int) $post_id),
            array('%s', '%s'),
            array('%d')
        );
    }

    /**
     * @return array|null
     */
    public static function get_report($post_id) {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return null;
        }

        $raw = $wpdb->get_var($wpdb->prepare(
            'SELECT gap_report FROM ' . ECP_DB::opportunities_table() . ' WHERE post_id = %d',
            (int) $post_id
        ));

        $report = ECP_DB::decode($raw);

        return $report ? $report : null;
    }

    /* --------------------------------------------------------------------
     * Facts only you can supply
     * ----------------------------------------------------------------- */

    /**
     * Answers the site owner has given, which the agent may then use.
     *
     * This is the loop that makes "the agent must not invent your prices"
     * workable rather than merely safe: it asks once, you answer once, and
     * from then on it is a verified fact rather than a blocked question.
     *
     * @return array[] { question, answer, answered_at, answered_by }
     */
    public static function owner_facts($post_id) {
        $facts = get_post_meta((int) $post_id, self::FACTS_META, true);

        return is_array($facts) ? $facts : array();
    }

    /**
     * Record an answer to one of the agent's questions.
     *
     * @return true|WP_Error
     */
    public static function answer($post_id, $question, $answer) {
        $post_id = (int) $post_id;

        if (!ECP_Capabilities::can_review($post_id)) {
            return new WP_Error('ecp_forbidden', __('You do not have permission to edit that post.', 'enhanced-content-plugin'));
        }

        $question = trim(sanitize_textarea_field($question));
        $answer = trim(sanitize_textarea_field($answer));

        if ('' === $question) {
            return new WP_Error('ecp_no_question', __('No question was given.', 'enhanced-content-plugin'));
        }

        $facts = self::owner_facts($post_id);

        // Answering the same question again replaces the old answer rather
        // than stacking a contradiction the agent would have to choose from.
        $facts = array_values(array_filter($facts, function ($fact) use ($question) {
            return isset($fact['question']) && 0 !== strcasecmp(trim($fact['question']), $question);
        }));

        if ('' !== $answer) {
            $facts[] = array(
                'question'    => $question,
                'answer'      => $answer,
                'answered_at' => current_time('mysql'),
                'answered_by' => get_current_user_id(),
            );
        }

        update_post_meta($post_id, self::FACTS_META, $facts);

        ECP_Log::info('gaps.fact_answered', sprintf(
            /* translators: 1: question, 2: post title */
            __('Answered "%1$s" for "%2$s"', 'enhanced-content-plugin'),
            $question,
            get_the_title($post_id)
        ), array('post_id' => $post_id));

        return true;
    }

    /**
     * Every unanswered question across the site, for the dashboard.
     *
     * @return array[] { post_id, post_title, question, needed, priority }
     */
    public static function open_questions($limit = 20) {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return array();
        }

        $author = ECP_Capabilities::author_scope();
        $posts = $wpdb->posts;
        $table = ECP_DB::opportunities_table();

        $where = "o.gap_report IS NOT NULL AND o.gap_report != ''";
        $params = array();

        if ($author) {
            $where .= ' AND p.post_author = %d';
            $params[] = (int) $author;
        }

        $sql = "SELECT o.post_id, o.gap_report, p.post_title
                FROM {$table} o
                INNER JOIN {$posts} p ON p.ID = o.post_id
                WHERE {$where}
                ORDER BY o.score DESC
                LIMIT 200";

        $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);

        $questions = array();

        foreach ((array) $rows as $row) {
            $report = ECP_DB::decode($row['gap_report']);

            if (empty($report['for_you'])) {
                continue;
            }

            $answered = wp_list_pluck(self::owner_facts($row['post_id']), 'question');
            $answered = array_map('strtolower', array_map('trim', $answered));

            foreach ($report['for_you'] as $item) {
                if (in_array(strtolower(trim($item['question'])), $answered, true)) {
                    continue;   // Already answered.
                }

                $questions[] = array(
                    'post_id'    => (int) $row['post_id'],
                    'post_title' => $row['post_title'],
                    'question'   => $item['question'],
                    'needed'     => $item['needed_from_owner'],
                    'priority'   => (int) $item['priority'],
                    'backed'     => !empty($item['backed_by_search']),
                );

                if (count($questions) >= $limit) {
                    break 2;
                }
            }
        }

        usort($questions, function ($a, $b) {
            if ($a['backed'] !== $b['backed']) {
                return $a['backed'] ? -1 : 1;
            }

            return $b['priority'] <=> $a['priority'];
        });

        return $questions;
    }

    public static function coverage_label($coverage) {
        $labels = array(
            self::COVERED   => __('Answered', 'enhanced-content-plugin'),
            self::THIN      => __('Only touched on', 'enhanced-content-plugin'),
            self::MISSING   => __('Not answered', 'enhanced-content-plugin'),
            self::ELSEWHERE => __('Belongs on another page', 'enhanced-content-plugin'),
        );

        return isset($labels[$coverage]) ? $labels[$coverage] : $coverage;
    }
}
