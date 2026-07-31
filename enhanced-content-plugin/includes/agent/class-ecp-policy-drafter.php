<?php
/**
 * Policy page drafting: the formulaic trust pages, written for the
 * owner's review instead of staying forever on the to-do list.
 *
 * Three pages qualify — editorial policy, review policy, affiliate
 * disclosure. They are structural documents: most of their content
 * follows from what the site verifiably does (the plugin knows it
 * reviews things, knows it carries affiliate links, knows who its
 * authors are and how approval works). What only the owner knows —
 * whether review units are bought or supplied, who fact-checks —
 * arrives as bracketed [OWNER: …] prompts in the draft, never as an
 * invented claim.
 *
 * The About page is deliberately not on this list. A policy is a
 * procedure; an About page is a story, and ghost-writing someone's
 * story is how sites end up feeling fake — the exact opposite of what
 * these pages are for.
 *
 * Every draft is an unpublished WordPress page with the canonical
 * slug, so the moment the owner reads, edits and publishes it, the
 * trust check that demanded it starts passing on its own.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ECP_Policy_Drafter {

    /**
     * The pages this class may draft, keyed by the trust check they
     * satisfy.
     *
     * @return array<string,array> { slug, title, purpose }
     */
    public static function draftable() {
        return array(
            'editorial_policy' => array(
                'slug'    => 'editorial-policy',
                'title'   => __('Editorial Policy', 'enhanced-content-plugin'),
                'purpose' => 'How content on this site is planned, written, reviewed, corrected and kept current.',
            ),
            'review_policy' => array(
                'slug'    => 'review-policy',
                'title'   => __('How We Review', 'enhanced-content-plugin'),
                'purpose' => 'How this site reviews products: what gets covered, how conclusions are reached, and how the site handles products it sells itself.',
            ),
            'affiliate_disclosure_page' => array(
                'slug'    => 'affiliate-disclosure',
                'title'   => __('Affiliate Disclosure', 'enhanced-content-plugin'),
                'purpose' => 'How this site earns from affiliate links and what that does and does not influence.',
            ),
        );
    }

    /**
     * An existing draft for a check, if one was made earlier.
     *
     * @return WP_Post|null
     */
    public static function existing_draft($check_id) {
        $post_id = (int) get_option('ecp_policy_draft_' . sanitize_key($check_id));

        if (!$post_id) {
            return null;
        }

        $post = get_post($post_id);

        return $post && 'draft' === $post->post_status ? $post : null;
    }

    /**
     * Draft one policy page. One AI call on the analyze meter; the
     * result is an unpublished page with the canonical slug.
     *
     * @return array|WP_Error { post_id, edit_link, existing }
     */
    public static function draft($check_id, array $args = array()) {
        $args = wp_parse_args($args, array('trigger_source' => 'manual'));

        $draftable = self::draftable();

        if (!isset($draftable[$check_id])) {
            return new WP_Error('ecp_not_draftable', __('That page is not one the agent drafts — About and Contact pages are yours to write.', 'enhanced-content-plugin'));
        }

        $existing = self::existing_draft($check_id);

        if ($existing) {
            return array(
                'post_id'   => (int) $existing->ID,
                'edit_link' => get_edit_post_link((int) $existing->ID, 'raw'),
                'existing'  => true,
            );
        }

        $spec = $draftable[$check_id];

        $response = ECP_AI_Client::request(
            self::system_prompt(),
            self::user_prompt($check_id, $spec),
            array(
                'type' => 'object',
                'properties' => array(
                    'title' => array('type' => 'string'),
                    'html'  => array('type' => 'string', 'description' => 'The page body. <h2>, <h3>, <p>, <ul>, <ol>, <li>, <strong> only.'),
                ),
                'required' => array('title', 'html'),
                'additionalProperties' => false,
            ),
            array(
                'job_type'       => 'policy',
                'trigger_source' => $args['trigger_source'],
                'max_tokens'     => 16000,
                'effort'         => 'medium',
            )
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $html = ECP_Guardrails::sanitize_html((string) $response['data']['html']);

        if (ECP_Agent_Settings::is_on('forbid_em_dashes')) {
            $html = ECP_Guardrails::remove_ai_dashes($html);
        }

        $post_id = wp_insert_post(array(
            'post_title'   => sanitize_text_field($response['data']['title']),
            'post_name'    => $spec['slug'],
            'post_content' => $html,
            'post_status'  => 'draft',
            'post_type'    => 'page',
        ), true);

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        update_option('ecp_policy_draft_' . sanitize_key($check_id), (int) $post_id, false);

        ECP_Log::info('policy.drafted', sprintf(
            /* translators: %s: page title */
            __('Drafted the "%s" page for review — unpublished until you publish it.', 'enhanced-content-plugin'),
            $spec['title']
        ), array('post_id' => (int) $post_id, 'run_id' => (int) $response['run_id']));

        return array(
            'post_id'   => (int) $post_id,
            'edit_link' => get_edit_post_link((int) $post_id, 'raw'),
            'existing'  => false,
        );
    }

    /* --------------------------------------------------------------------
     * Prompt
     * ----------------------------------------------------------------- */

    private static function system_prompt() {
        $lines = array();

        $lines[] = 'You draft a policy page for one specific website. A policy is a set of commitments the site actually keeps — not aspirational marketing.';
        $lines[] = '';
        $lines[] = 'Rules:';
        $lines[] = '- State only practices supported by the facts provided. The site\'s real workflow is described below; describe THAT, not an idealised one.';
        $lines[] = '- Where a specific only the owner can supply is genuinely needed — whether review products are purchased or supplied, who fact-checks, a contact address — write a bracketed prompt in place: [OWNER: state whether review units are purchased or provided by manufacturers]. Never guess it.';
        $lines[] = '- Plain, first-person-plural, readable by a customer. No legalese unless the fact demands it, no dates, no invented names or credentials.';
        $lines[] = '- Short. A policy a reader actually finishes beats a comprehensive one nobody does.';
        $lines[] = '- HTML: <h2>, <h3>, <p>, <ul>, <ol>, <li>, <strong> only.';

        return implode("\n", $lines);
    }

    private static function user_prompt($check_id, array $spec) {
        global $wpdb;

        $out = array();

        $profile = ECP_Site_Profile::prompt_context();

        if ($profile) {
            $out[] = '## The business';
            $out[] = $profile;
            $out[] = '';
        }

        $out[] = '## The page to draft';
        $out[] = $spec['purpose'];

        // Practices the plugin can honestly attest to, because it runs them.
        $out[] = '';
        $out[] = '## Verifiable practices on this site';

        $mode = ECP_Agent_Settings::get('approval_mode', 'always');
        $out[] = 'always' === $mode
            ? '- AI-assisted improvements are used, and every content change is reviewed and approved by a person before it is published.'
            : '- AI-assisted improvements are used; substantive changes are reviewed by a person, and a narrow set of low-risk formatting fixes may apply automatically with every change logged and reversible.';

        $out[] = '- Published articles carry the author\'s name, photo and role, with published and updated dates shown.';
        $out[] = '- Articles cite sources in a sources list; corrections are logged on the article when made.';

        if ('review_policy' === $check_id) {
            $titles = (array) $wpdb->get_col(
                'SELECT title FROM ' . ECP_DB::inventory_table() . "
                  WHERE post_status = 'publish' AND (title LIKE '%review%' OR schema_types LIKE '%Review%')
                  LIMIT 6"
            );

            $out[] = '';
            $out[] = '## What the site reviews';
            foreach ($titles as $title) {
                $out[] = '- ' . $title;
            }
            $out[] = 'Note: if the business sells products in the same category it reviews, the policy must say so plainly and explain how that is handled — use an [OWNER: …] prompt for the specifics.';
        }

        if ('affiliate_disclosure_page' === $check_id) {
            $out[] = '';
            $out[] = '## Affiliate context';
            $out[] = 'The site carries affiliate links in some articles. The disclosure must cover: that clicks may earn the site a commission at no extra cost to the reader, that this never determines what gets recommended, and how affiliate links relate to the site\'s own products. Use [OWNER: …] prompts for programme names.';
        }

        $facts = ECP_Vault::for_post(0, 15);

        if ($facts) {
            $out[] = '';
            $out[] = '## Owner-verified facts you may use';
            foreach ($facts as $fact) {
                $out[] = '- ' . ($fact['question'] ? $fact['question'] . ' → ' : '') . $fact['fact'];
            }
        }

        $out[] = '';
        $out[] = '## What to return';
        $out[] = 'The page title and body. Every claim either follows from the facts above or is an [OWNER: …] prompt.';

        return implode("\n", $out);
    }
}
