<?php
/**
 * Article drafting: an approved brief becomes an unpublished WordPress
 * draft, and nothing else does.
 *
 * The entry ticket is checked here, not hoped for: draft() refuses any
 * brief whose status is not approved. The article is generated against
 * the brief's own structure, section by section in one structured
 * call, with every factual claim tagged with where its support comes
 * from — a vault fact, the brief itself, general knowledge, or
 * "needs verification". Claims in that last bucket are flagged to the
 * owner, never silently smoothed over.
 *
 * After generation a deterministic quality pipeline runs in PHP:
 * HTML sanitised to the allowed set, the em-dash scrub, banned
 * phrases, mangled brand terms, generic-intro and keyword-stuffing
 * checks, and a firsthand-experience check — "we tested" is flagged
 * unless the brief's information-gain source actually claims that
 * experience. Internal links are injected here from the brief's
 * blueprint using real permalinks; the model never writes an href.
 *
 * The result is a WordPress draft — post_status 'draft', invisible to
 * visitors — plus a quality report on the brief row. Publishing is
 * the owner's act, in the editor they already know.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ECP_Drafter {

    /** Firsthand info-gain sources that legitimise experience claims. */
    private static $experience_sources = array(
        'company_experience', 'product_testing', 'real_project_examples',
        'original_measurements', 'proprietary_data', 'local_insight',
    );

    /* --------------------------------------------------------------------
     * Drafting
     * ----------------------------------------------------------------- */

    /**
     * Draft the article for one approved brief.
     *
     * @return array|WP_Error { post_id, edit_link, quality }
     */
    public static function draft($brief_id, array $args = array()) {
        global $wpdb;

        $args = wp_parse_args($args, array('trigger_source' => 'manual'));

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . ECP_DB::briefs_table() . ' WHERE id = %d',
            (int) $brief_id
        ), ARRAY_A);

        if (!$row) {
            return new WP_Error('ecp_no_brief', __('That brief no longer exists.', 'enhanced-content-plugin'));
        }

        // The rule the phase exists to enforce.
        if (ECP_Briefs::APPROVED !== $row['status']) {
            return new WP_Error('ecp_brief_not_approved', __('Articles are drafted only from approved briefs.', 'enhanced-content-plugin'));
        }

        if ((int) $row['draft_post_id'] && get_post((int) $row['draft_post_id'])) {
            return new WP_Error('ecp_already_drafted', __('This brief already has a draft. Edit it in WordPress, or delete it to redraft.', 'enhanced-content-plugin'));
        }

        $allowed = ECP_Limits::can('drafts');

        if (is_wp_error($allowed)) {
            return $allowed;
        }

        $topic = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . ECP_DB::topics_table() . ' WHERE id = %d',
            (int) $row['topic_id']
        ), ARRAY_A);

        if (!$topic) {
            return new WP_Error('ecp_no_topic', __('The topic behind this brief no longer exists.', 'enhanced-content-plugin'));
        }

        $brief = ECP_DB::decode($row['brief']);

        if (empty($brief['sections'])) {
            return new WP_Error('ecp_empty_brief', __('The brief has no structure to draft from. Rewrite it first.', 'enhanced-content-plugin'));
        }

        $facts = ECP_Vault::for_post(0, 40);   // Site-wide facts…
        $facts = array_merge($facts, self::topic_facts($topic));   // …plus topic-scoped.

        $response = ECP_AI_Client::request(
            self::system_prompt($brief),
            self::user_prompt($topic, $brief, $facts),
            self::schema(),
            array(
                'job_type'       => 'draft',
                'meter'          => 'drafts',
                'trigger_source' => $args['trigger_source'],
                'max_tokens'     => 32000,
            )
        );

        if (is_wp_error($response)) {
            return $response;
        }

        ECP_Limits::spend('drafts');

        $article = $response['data'];
        $quality = self::review($article, $topic, $brief, $facts);
        $content = self::assemble($article, $brief, $quality);

        $post_id = wp_insert_post(array(
            'post_title'   => sanitize_text_field($article['title']),
            'post_name'    => sanitize_title(!empty($brief['slug']) ? $brief['slug'] : $article['title']),
            'post_content' => $content,
            'post_excerpt' => sanitize_text_field($article['meta_description']),
            'post_status'  => 'draft',   // Never anything else. Publishing is the owner's act.
            'post_type'    => 'post',
        ), true);

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        update_post_meta((int) $post_id, '_ecp_brief_id', (int) $brief_id);

        $now = ECP_DB::now();

        $wpdb->update(
            ECP_DB::briefs_table(),
            array(
                'draft_post_id' => (int) $post_id,
                'drafted_at'    => $now,
                'draft_quality' => ECP_DB::encode($quality),
                'updated_at'    => $now,
            ),
            array('id' => (int) $brief_id),
            array('%d', '%s', '%s', '%s'),
            array('%d')
        );

        ECP_Log::info(ECP_Log::DRAFT_CREATED, sprintf(
            /* translators: 1: article title, 2: number of flags */
            __('Drafted "%1$s" as an unpublished draft — %2$d quality flags for review.', 'enhanced-content-plugin'),
            $article['title'],
            count($quality['flags'])
        ), array('post_id' => (int) $post_id, 'run_id' => (int) $response['run_id']));

        return array(
            'post_id'   => (int) $post_id,
            'edit_link' => get_edit_post_link((int) $post_id, 'raw'),
            'quality'   => $quality,
        );
    }

    /**
     * Vault facts scoped to the topic, its cluster or its seed.
     */
    private static function topic_facts(array $topic) {
        $result = ECP_Vault::query(array('status' => ECP_Vault::ACTIVE, 'limit' => 100));
        $out = array();

        foreach ($result['items'] as $fact) {
            if ('' === $fact['topic']) {
                continue;
            }

            if (0 === strcasecmp($fact['topic'], $topic['topic'])
                || 0 === strcasecmp($fact['topic'], $topic['parent'])
                || 0 === strcasecmp($fact['topic'], $topic['seed'])
            ) {
                $out[] = $fact;
            }
        }

        return $out;
    }

    /* --------------------------------------------------------------------
     * Prompt
     * ----------------------------------------------------------------- */

    private static function system_prompt(array $brief) {
        $lines = array();

        $lines[] = 'You are drafting an article for a specific website, from a brief its owner approved. The brief is the contract: its structure, its angle, its audience.';
        $lines[] = '';
        $lines[] = 'The rules that decide whether this draft survives review:';
        $lines[] = '- Never invent a fact: no prices, statistics, dates, measurements, test results, brand claims or company details beyond the verified facts provided. When a sentence needs a fact you were not given, either write around it honestly or make the claim and tag it needs_verification — never guess and never soften into vagueness to hide the gap.';

        $gain_source = isset($brief['info_gain']['source']) ? $brief['info_gain']['source'] : 'none';

        if (in_array($gain_source, self::$experience_sources, true)) {
            $lines[] = '- Firsthand experience: the business has claimed ' . str_replace('_', ' ', $gain_source) . ' as this article\'s contribution. You may write from that experience where the provided facts support it, and only there.';
        } else {
            $lines[] = '- Firsthand experience: the business has NOT supplied any. Never write "we tested", "in our experience", "our team found" or anything that implies the business did something it has not told you about.';
        }

        $lines[] = '- No fabricated quotes, ever.';
        $lines[] = '- Every factual claim you make gets listed in that section\'s claims array with its support: vault_fact (a provided verified fact), brief (stated in the brief), general_knowledge (uncontroversial, needs no citation), or needs_verification.';
        $lines[] = '- Open every section by answering its question. No throat-clearing, no "in today\'s world", no restating the heading, no paragraph that merely announces what the next paragraph will say.';
        $lines[] = '- Vary paragraph structure. Avoid formulaic transitions ("Moreover", "Furthermore", "It is important to note"). Write like the site\'s own author on a good day, not like a model.';
        $lines[] = '- Do not repeat the main query verbatim more than a natural handful of times. This article ranks by answering, not by chanting.';
        $lines[] = '- HTML only: <h3>, <p>, <ul>, <ol>, <li>, <strong>, <em>, <table>, <thead>, <tbody>, <tr>, <th>, <td>. Section headings are supplied separately — do not include the <h2> in the section html.';
        $lines[] = '- Do not write any links. Internal links are placed afterwards from the approved blueprint.';

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

    private static function user_prompt(array $topic, array $brief, array $facts) {
        $out = array();

        $profile = ECP_Site_Profile::prompt_context();

        if ($profile) {
            $out[] = '## The business';
            $out[] = $profile;
            $out[] = '';
        }

        $out[] = '## The approved brief';
        $out[] = 'Topic: ' . $topic['topic'];
        $out[] = 'Objective: ' . (isset($brief['objective']) ? $brief['objective'] : '');
        $out[] = 'Audience: ' . (isset($brief['audience']) ? $brief['audience'] : '');
        $out[] = 'Unique angle: ' . (isset($brief['unique_angle']) ? $brief['unique_angle'] : '');

        if (!empty($brief['info_gain']['statement'])) {
            $out[] = 'What this page must add: ' . $brief['info_gain']['statement'];
        }

        $out[] = 'Main query: ' . $topic['main_query'];

        $supporting = ECP_DB::decode($topic['supporting_queries']);
        if ($supporting) {
            $out[] = 'Supporting queries: ' . implode('; ', (array) $supporting);
        }

        if (!empty($brief['title_options'])) {
            $out[] = 'Title: choose the strongest of these, or sharpen one: ' . implode(' | ', array_map('strval', (array) $brief['title_options']));
        }

        if (!empty($brief['user_questions'])) {
            $out[] = 'Reader questions the article must answer: ' . implode('; ', array_map('strval', (array) $brief['user_questions']));
        }

        if (!empty($brief['cta'])) {
            $out[] = 'Call to action to close on: ' . $brief['cta'];
        }

        $out[] = '';
        $out[] = '## The structure (write exactly these sections, in this order)';

        foreach ((array) $brief['sections'] as $index => $section) {
            $out[] = sprintf(
                '%d. "%s" — %s%s',
                $index + 1,
                $section['heading'],
                $section['purpose'],
                empty($section['required']) ? ' (optional: include only if you can add real substance)' : ''
            );
        }

        if ($facts) {
            $out[] = '';
            $out[] = '## Verified facts (the only business facts you may state)';

            foreach ($facts as $fact) {
                $out[] = '- ' . ($fact['question'] ? $fact['question'] . ' → ' : '') . $fact['fact'];
            }
        } else {
            $out[] = '';
            $out[] = '## No verified business facts were provided';
            $out[] = 'Anything specific to this business must be tagged needs_verification.';
        }

        $compliance = trim((string) ECP_Site_Profile::get('compliance_notes'));
        if ($compliance) {
            $out[] = '';
            $out[] = '## Compliance restrictions — the content must never violate these';
            $out[] = $compliance;
        }

        if (!empty($brief['risks'])) {
            $out[] = '';
            $out[] = '## Known risks the brief flagged';
            foreach ((array) $brief['risks'] as $risk) {
                $out[] = '- ' . $risk;
            }
        }

        $out[] = '';
        $out[] = '## What to return';
        $out[] = 'The title, the meta description (under 155 characters, matching the page\'s promise), the introduction, and every section with its html and its claims list.';

        return implode("\n", $out);
    }

    private static function schema() {
        $claims = array(
            'type' => 'array',
            'items' => array(
                'type' => 'object',
                'properties' => array(
                    'statement' => array('type' => 'string', 'description' => 'The factual claim as made in the text.'),
                    'support'   => array('type' => 'string', 'enum' => array('vault_fact', 'brief', 'general_knowledge', 'needs_verification')),
                    'source_note' => array('type' => 'string', 'description' => 'Which fact or brief line supports it; what verification is needed when unsupported.'),
                ),
                'required' => array('statement', 'support', 'source_note'),
                'additionalProperties' => false,
            ),
        );

        return array(
            'type' => 'object',
            'properties' => array(
                'title'            => array('type' => 'string'),
                'meta_description' => array('type' => 'string'),
                'intro_html'       => array('type' => 'string', 'description' => 'The introduction. Answers the reader\'s core question immediately.'),
                'intro_claims'     => $claims,
                'sections'         => array(
                    'type' => 'array',
                    'items' => array(
                        'type' => 'object',
                        'properties' => array(
                            'heading' => array('type' => 'string', 'description' => 'Exactly as given in the structure.'),
                            'html'    => array('type' => 'string', 'description' => 'The section body, without its <h2>.'),
                            'claims'  => $claims,
                        ),
                        'required' => array('heading', 'html', 'claims'),
                        'additionalProperties' => false,
                    ),
                ),
            ),
            'required' => array('title', 'meta_description', 'intro_html', 'intro_claims', 'sections'),
            'additionalProperties' => false,
        );
    }

    /* --------------------------------------------------------------------
     * The quality pipeline
     * ----------------------------------------------------------------- */

    /**
     * Deterministic review of the generated article (gameplan §12.3
     * subset). Returns the report stored with the brief and shown to
     * the owner; it never edits meaning, only flags.
     *
     * @return array { flags, claims, needs_verification, sources }
     */
    private static function review(array $article, array $topic, array $brief, array $facts) {
        $flags = array();
        $claims = array();
        $sources = array();

        $all_text = wp_strip_all_tags($article['intro_html'] . ' ' . implode(' ', wp_list_pluck((array) $article['sections'], 'html')));

        // --- Claims and their support --------------------------------------
        $buckets = array(array('section' => __('Introduction', 'enhanced-content-plugin'), 'claims' => (array) $article['intro_claims']));

        foreach ((array) $article['sections'] as $section) {
            $buckets[] = array('section' => $section['heading'], 'claims' => (array) $section['claims']);
        }

        $needs_verification = 0;

        foreach ($buckets as $bucket) {
            foreach ($bucket['claims'] as $claim) {
                $claim['section'] = $bucket['section'];
                $claims[] = $claim;

                if ('needs_verification' === $claim['support']) {
                    $needs_verification++;
                } elseif ('vault_fact' === $claim['support']) {
                    $sources[] = array(
                        'type'      => 'vault',
                        'statement' => $claim['statement'],
                        'note'      => $claim['source_note'],
                    );
                }
            }
        }

        if ($needs_verification > 0) {
            $flags[] = array(
                'code'     => 'unverified_claims',
                'severity' => 'high',
                'detail'   => sprintf(
                    /* translators: %d: number of claims */
                    _n('%d claim needs your verification before this can honestly publish.', '%d claims need your verification before this can honestly publish.', $needs_verification, 'enhanced-content-plugin'),
                    $needs_verification
                ),
            );
        }

        // --- Firsthand experience ------------------------------------------
        $gain_source = isset($brief['info_gain']['source']) ? $brief['info_gain']['source'] : 'none';

        if (!in_array($gain_source, self::$experience_sources, true)
            && preg_match('/\b(we tested|we measured|we installed|we built|our (team|testing|experience|lab)|in our experience)\b/i', $all_text, $match)
        ) {
            $flags[] = array(
                'code'     => 'fabricated_experience',
                'severity' => 'high',
                'detail'   => sprintf(
                    /* translators: %s: the offending phrase */
                    __('Claims firsthand experience ("%s") the business never supplied. Rewrite or delete before publishing.', 'enhanced-content-plugin'),
                    $match[0]
                ),
            );
        }

        // --- Generic openings ----------------------------------------------
        if (preg_match('/^\s*(in today|in this (article|guide|post)|when it comes to|in the (modern|digital) (world|age))/i', wp_strip_all_tags($article['intro_html']))) {
            $flags[] = array(
                'code'     => 'generic_intro',
                'severity' => 'medium',
                'detail'   => __('The introduction opens with scene-setting instead of an answer.', 'enhanced-content-plugin'),
            );
        }

        // --- Keyword stuffing ----------------------------------------------
        $query = trim((string) $topic['main_query']);

        if ('' !== $query) {
            $count = substr_count(strtolower($all_text), strtolower($query));
            $ceiling = max(5, (int) (str_word_count($all_text) / 250));

            if ($count > $ceiling) {
                $flags[] = array(
                    'code'     => 'keyword_stuffing',
                    'severity' => 'medium',
                    'detail'   => sprintf(
                        /* translators: 1: query, 2: count */
                        __('The main query "%1$s" appears %2$d times — reads as chanting, not answering.', 'enhanced-content-plugin'),
                        $query,
                        $count
                    ),
                );
            }
        }

        // --- Formulaic tics ------------------------------------------------
        if (preg_match_all('/\bit is important\b/i', $all_text) > 2) {
            $flags[] = array(
                'code'     => 'importance_tic',
                'severity' => 'low',
                'detail'   => __('"It is important" appears more than twice.', 'enhanced-content-plugin'),
            );
        }

        // --- Banned phrases and brand terms --------------------------------
        foreach (ECP_Guardrails::find_banned_phrases($all_text) as $phrase) {
            $flags[] = array(
                'code'     => 'banned_phrase',
                'severity' => 'medium',
                'detail'   => sprintf(
                    /* translators: %s: phrase */
                    __('Uses a banned phrase: "%s".', 'enhanced-content-plugin'),
                    $phrase
                ),
            );
        }

        foreach (ECP_Guardrails::find_mangled_brand_terms($all_text) as $term) {
            $flags[] = array(
                'code'     => 'brand_term',
                'severity' => 'medium',
                'detail'   => sprintf(
                    /* translators: %s: brand term */
                    __('Brand term written incorrectly: "%s".', 'enhanced-content-plugin'),
                    $term
                ),
            );
        }

        return array(
            'flags'              => $flags,
            'claims'             => $claims,
            'needs_verification' => $needs_verification,
            'sources'            => $sources,
        );
    }

    /* --------------------------------------------------------------------
     * Assembly
     * ----------------------------------------------------------------- */

    /**
     * Sanitise, scrub and join the sections, then place the brief's
     * internal links against real permalinks. The model wrote no links;
     * every href here comes from the approved blueprint.
     */
    private static function assemble(array $article, array $brief, array &$quality) {
        $scrub = ECP_Agent_Settings::is_on('forbid_em_dashes');

        $clean = function ($html) use ($scrub) {
            $html = ECP_Guardrails::sanitize_html($html);

            return $scrub ? ECP_Guardrails::remove_ai_dashes($html) : $html;
        };

        $parts = array($clean($article['intro_html']));

        foreach ((array) $article['sections'] as $section) {
            $parts[] = '<h2>' . esc_html($section['heading']) . '</h2>';
            $parts[] = $clean($section['html']);
        }

        $content = implode("\n\n", array_filter($parts));

        // Internal links from the blueprint: first plain-text occurrence
        // of each anchor gets the link. An anchor that never occurs is
        // flagged rather than forced in.
        foreach ((array) (isset($brief['internal_links_out']) ? $brief['internal_links_out'] : array()) as $link) {
            if (empty($link['post_id']) || empty($link['anchor'])) {
                continue;
            }

            $url = get_permalink((int) $link['post_id']);

            if (!$url) {
                continue;
            }

            $anchor = $link['anchor'];
            $pattern = '/(?<!["\'>])(' . preg_quote($anchor, '/') . ')(?![^<]*<\/a>)/i';
            $replaced = preg_replace(
                $pattern,
                '<a href="' . esc_url($url) . '">$1</a>',
                $content,
                1
            );

            if (null !== $replaced && $replaced !== $content) {
                $content = $replaced;
            } else {
                $quality['flags'][] = array(
                    'code'     => 'link_not_placed',
                    'severity' => 'low',
                    'detail'   => sprintf(
                        /* translators: %s: anchor text */
                        __('The planned link anchor "%s" does not occur in the draft — place it by hand or reword.', 'enhanced-content-plugin'),
                        $anchor
                    ),
                );
            }
        }

        return $content;
    }
}
