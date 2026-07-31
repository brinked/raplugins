<?php
/**
 * Validation between the model and the approval queue.
 *
 * Everything here runs before a human ever sees a proposal. The point is that
 * the review queue should contain only changes that are *safe to approve* —
 * a reviewer clicking through 30 items is not going to notice that item 19
 * quietly dropped a shortcode or invented a statistic.
 *
 * A failed check drops the change entirely and records why. A suspicious but
 * legitimate change is escalated to the 'sensitive' risk tier instead, which
 * surfaces the specific claim to check and blocks auto-apply.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ECP_Guardrails {

    /**
     * Validate one proposed change.
     *
     * @param WP_Post $post
     * @param array   $change  One entry from the model's `changes` array.
     * @param array   $signals On-page signals for the post.
     * @return array|WP_Error { before, after, payload, risk, flags }
     */
    public static function check($post, array $change, array $signals) {
        $type = isset($change['type']) ? (string) $change['type'] : '';
        $target = isset($change['target']) ? (string) $change['target'] : '';
        $content = isset($change['content']) ? (string) $change['content'] : '';

        $info = ECP_Proposals::change_type($type);
        if (!$info) {
            return new WP_Error('ecp_unknown_type', sprintf(
                /* translators: %s: change type slug returned by the model */
                __('Unknown change type "%s".', 'enhanced-content-plugin'),
                $type
            ));
        }

        if (!ECP_Agent_Settings::change_type_enabled($type)) {
            return new WP_Error('ecp_type_disabled', __('That change type is switched off in settings.', 'enhanced-content-plugin'));
        }

        if ('' === trim($content)) {
            return new WP_Error('ecp_empty_change', __('The proposed change had no content.', 'enhanced-content-plugin'));
        }

        // Enforce the no-em-dash rule mechanically, before validation. The
        // prompt asks; this makes sure. Prompts get ignored a few percent of
        // the time, and a few percent of a site's edits carrying the single
        // loudest AI-writing tell is how a blog starts reading machine-made.
        if (ECP_Agent_Settings::is_on('forbid_em_dashes')) {
            $content = self::remove_ai_dashes($content);
        }

        $flags = array();
        $risk = $info['risk'];

        // Dispatch to the per-target validator.
        switch ($info['target']) {
            case 'meta':
                $result = self::check_meta($post, $type, $content, $signals);
                break;

            case 'title':
                $result = self::check_post_title($post, $content);
                break;

            case 'section':
                $result = self::check_section($post, $type, $target, $content);
                break;

            case 'section_insert':
                $result = self::check_section_insert($post, $target, $content);
                break;

            case 'content':
                $result = self::check_internal_link($post, $target, $content);
                break;

            case 'attachment':
                $result = self::check_image_alt($post, $target, $content, $signals);
                break;

            case 'faq':
                $result = self::check_faq($post, $content);
                break;

            case 'sources':
                $result = self::check_sources($post, $content);
                break;

            default:
                return new WP_Error('ecp_no_validator', __('No validator exists for that change type.', 'enhanced-content-plugin'));
        }

        if (is_wp_error($result)) {
            return $result;
        }

        $result = wp_parse_args($result, array(
            'before'  => '',
            'after'   => '',
            'payload' => array(),
            'flags'   => array(),
        ));

        $flags = array_merge($flags, $result['flags']);

        // --- Cross-cutting content checks -------------------------------

        $prose = wp_strip_all_tags($result['after']);

        $banned = self::find_banned_phrases($prose);
        if ($banned) {
            return new WP_Error('ecp_banned_phrase', sprintf(
                /* translators: %s: the banned phrase that was used */
                __('Uses a phrase you have banned: "%s".', 'enhanced-content-plugin'),
                $banned[0]
            ));
        }

        $mangled = self::find_mangled_brand_terms($prose);
        if ($mangled) {
            $flags['brand_terms_altered'] = $mangled;
            $risk = ECP_Proposals::RISK_SENSITIVE;
        }

        // --- Fact-check escalation ----------------------------------------

        $claimed_unverified = !empty($change['needs_fact_check']);
        $unverified_claims = isset($change['unverified_claims']) ? array_filter((array) $change['unverified_claims']) : array();

        if ($claimed_unverified || $unverified_claims) {
            $flags['unverified_claims'] = array_values($unverified_claims);
            $risk = ECP_Proposals::RISK_SENSITIVE;
        }

        // Independent check: numbers, dates and prices that were not in the
        // original. The model self-reports honestly most of the time, but
        // "most of the time" is not a safety property.
        if (ECP_Agent_Settings::is_on('forbid_new_numbers')) {
            $new_figures = self::new_figures($result['before'], $result['after'], $post);

            if ($new_figures) {
                if (ECP_Agent_Settings::is_on('require_source_for_claims') && !$unverified_claims) {
                    // The model added figures and did not flag them. That is
                    // exactly the failure mode this setting exists for.
                    return new WP_Error('ecp_unflagged_figures', sprintf(
                        /* translators: %s: comma-separated list of numbers/dates found */
                        __('Introduces figures that are not in the original and were not flagged for checking: %s.', 'enhanced-content-plugin'),
                        implode(', ', array_slice($new_figures, 0, 5))
                    ));
                }

                $flags['new_figures'] = $new_figures;
                $risk = ECP_Proposals::RISK_SENSITIVE;
            }
        }

        // Low confidence never gets to be 'safe'.
        $confidence = isset($change['confidence']) ? (int) $change['confidence'] : 50;
        if ($confidence < 70 && ECP_Proposals::RISK_SAFE === $risk) {
            $risk = ECP_Proposals::RISK_MODERATE;
            $flags['low_confidence'] = true;
        }

        return array(
            'before'  => $result['before'],
            'after'   => $result['after'],
            'payload' => $result['payload'],
            'risk'    => $risk,
            'flags'   => $flags,
        );
    }

    /* --------------------------------------------------------------------
     * Per-type validators
     * ----------------------------------------------------------------- */

    /**
     * The visible page title. The one field where the URL matters more
     * than the words: the slug is locked at apply time, so a title
     * refresh can never break a single inbound link.
     */
    private static function check_post_title($post, $content) {
        $content = trim(wp_strip_all_tags($content));
        $length = mb_strlen($content);

        if ($length < 15 || $length > 120) {
            return new WP_Error('ecp_post_title_length', sprintf(
                /* translators: %d: character count */
                __('The proposed page title is %d characters; it needs to be between 15 and 120.', 'enhanced-content-plugin'),
                $length
            ));
        }

        if ($content === $post->post_title) {
            return new WP_Error('ecp_post_title_same', __('The proposed page title is identical to the current one.', 'enhanced-content-plugin'));
        }

        return array(
            'before'  => $post->post_title,
            'after'   => $content,
            'payload' => array('field' => 'post_title'),
        );
    }

    private static function check_meta($post, $type, $content, array $signals) {
        $content = trim(wp_strip_all_tags($content));

        if ('meta_title' === $type) {
            // With no SEO plugin there is no separate SEO-title field to
            // write to, and rewriting post_title would change the visible
            // page heading. Refuse here rather than failing at apply time.
            if (!ECP_Signals::seo_meta_keys($signals['seo_plugin'])) {
                return new WP_Error(
                    'ecp_no_seo_plugin',
                    __('No SEO plugin is active, so there is nowhere to store a separate SEO title.', 'enhanced-content-plugin')
                );
            }

            $length = mb_strlen($content);
            if ($length < 15 || $length > 75) {
                return new WP_Error('ecp_title_length', sprintf(
                    /* translators: %d: character count */
                    __('The proposed SEO title is %d characters; it needs to be between 15 and 75.', 'enhanced-content-plugin'),
                    $length
                ));
            }

            return array(
                'before'  => $signals['seo_title'] ? $signals['seo_title'] : $post->post_title,
                'after'   => $content,
                'payload' => array('field' => 'title', 'seo_plugin' => $signals['seo_plugin']),
            );
        }

        if ('meta_description' === $type) {
            $length = mb_strlen($content);
            if ($length < 50 || $length > 200) {
                return new WP_Error('ecp_description_length', sprintf(
                    /* translators: %d: character count */
                    __('The proposed meta description is %d characters; it needs to be between 50 and 200.', 'enhanced-content-plugin'),
                    $length
                ));
            }

            return array(
                'before'  => (string) $signals['effective_description'],
                'after'   => $content,
                'payload' => array('field' => 'description', 'seo_plugin' => $signals['seo_plugin']),
            );
        }

        // schema_fix and anything else meta-shaped.
        return array(
            'before'  => '',
            'after'   => $content,
            'payload' => array('field' => 'schema'),
        );
    }

    private static function check_section($post, $type, $section_id, $content) {
        if ('' === $section_id) {
            return new WP_Error('ecp_no_target', __('No section was named for this rewrite.', 'enhanced-content-plugin'));
        }

        $section = ECP_Content_Map::get_section($post, $section_id);
        if (!$section) {
            return new WP_Error('ecp_bad_section', __('The named section does not exist on this page.', 'enhanced-content-plugin'));
        }

        $protected = ECP_Content_Map::protect($section['html']);

        // The single most important check here: a dropped shortcode silently
        // deletes a form, a gallery, or an affiliate block.
        $missing = ECP_Content_Map::missing_tokens($content, $protected['tokens']);
        if ($missing) {
            return new WP_Error('ecp_dropped_shortcode', sprintf(
                /* translators: %d: number of shortcodes or embeds dropped */
                _n(
                    'Drops %d shortcode or embed that is currently in this section.',
                    'Drops %d shortcodes or embeds that are currently in this section.',
                    count($missing),
                    'enhanced-content-plugin'
                ),
                count($missing)
            ));
        }

        // Put the real markup back before anything is stored.
        $restored = ECP_Content_Map::restore($content, $protected['tokens']);
        $restored = self::sanitize_html($restored);

        if ('' === trim(wp_strip_all_tags(strip_shortcodes($restored)))) {
            return new WP_Error('ecp_empty_section', __('The rewritten section is empty.', 'enhanced-content-plugin'));
        }

        $before_words = (int) $section['words'];
        $after_words = ECP_Content_Map::word_count(ECP_Content_Map::to_text($restored));

        if ('section_trim' === $type) {
            // A trim exists to shrink. One that doesn't is mislabelled, and
            // one that guts the section is the deletion the rewrite rules
            // exist to block — the floor is just set lower here because
            // shrinking is the point.
            if ($after_words >= $before_words) {
                return new WP_Error('ecp_trim_grew', sprintf(
                    /* translators: 1: original word count, 2: new word count */
                    __('A trim has to make the section shorter — this goes from %1$d words to %2$d.', 'enhanced-content-plugin'),
                    $before_words,
                    $after_words
                ));
            }

            if ($before_words > 60 && $after_words < $before_words * 0.3) {
                return new WP_Error('ecp_trim_gutted', sprintf(
                    /* translators: 1: original word count, 2: new word count */
                    __('Cuts the section from %1$d words to %2$d — more than two-thirds gone. That is a deletion wearing a trim\'s clothes.', 'enhanced-content-plugin'),
                    $before_words,
                    $after_words
                ));
            }
        } elseif ($before_words > 60 && $after_words < $before_words * 0.4) {
            // Guard against a "rewrite" that is really a deletion.
            return new WP_Error('ecp_content_loss', sprintf(
                /* translators: 1: original word count, 2: new word count */
                __('Cuts the section from %1$d words to %2$d. That is a deletion, not a rewrite.', 'enhanced-content-plugin'),
                $before_words,
                $after_words
            ));
        }

        // And against a single change swallowing the whole article.
        $max_percent = (int) ECP_Agent_Settings::get('max_change_percent', 40);
        $total_words = max(1, ECP_Content_Map::word_count(ECP_Content_Map::to_text($post->post_content)));
        $share = ($before_words / $total_words) * 100;

        if ($share > $max_percent && 'freshness_update' !== $type) {
            return new WP_Error('ecp_too_large', sprintf(
                /* translators: 1: percentage of the article, 2: configured limit */
                __('This section is %1$d%% of the article, over your %2$d%% single-change limit. Split it into smaller edits.', 'enhanced-content-plugin'),
                (int) round($share),
                $max_percent
            ));
        }

        $flags = array();

        // A heading change breaks the section id, which breaks any other
        // pending proposal that targets it. Worth telling the reviewer.
        if ('heading_rewrite' === $type || self::heading_changed($section['html'], $restored)) {
            $flags['heading_changed'] = true;
        }

        // A trim that removes over half the words deserves a closer read
        // than one that shaves ten percent.
        if ('section_trim' === $type && $before_words > 0 && $after_words < $before_words * 0.5) {
            $flags['large_trim'] = array('from' => $before_words, 'to' => $after_words);
        }

        return array(
            'before'  => $section['html'],
            'after'   => $restored,
            'payload' => array(
                'section_id'  => $section_id,
                'heading'     => $section['heading'],
                'is_intro'    => $section['is_intro'],
                'words_before' => $before_words,
                'words_after' => $after_words,
            ),
            'flags'   => $flags,
        );
    }

    private static function check_section_insert($post, $after_section_id, $content) {
        $restored = self::sanitize_html($content);

        if ('' === trim(wp_strip_all_tags($restored))) {
            return new WP_Error('ecp_empty_section', __('The new section is empty.', 'enhanced-content-plugin'));
        }

        if (!preg_match('/<h[2-4]\b/i', $restored)) {
            return new WP_Error('ecp_no_heading', __('A new section must start with its own H2 or H3 heading.', 'enhanced-content-plugin'));
        }

        // Refuse to add a section that duplicates one already there.
        $new_heading = '';
        if (preg_match('/<h([2-4])[^>]*>(.*?)<\/h\1>/is', $restored, $match)) {
            $new_heading = strtolower(trim(wp_strip_all_tags($match[2])));
        }

        if ($new_heading) {
            foreach (ECP_Content_Map::sections($post) as $section) {
                if (!$section['is_intro'] && strtolower($section['heading']) === $new_heading) {
                    return new WP_Error('ecp_duplicate_section', sprintf(
                        /* translators: %s: heading text */
                        __('The page already has a section headed "%s".', 'enhanced-content-plugin'),
                        $section['heading']
                    ));
                }
            }
        }

        // Capture the anchor's HEADING as well as its id. Section ids bake
        // the section's position into the hash, so applying any earlier
        // insert to the same page shifts every later section's id — the
        // anchor still exists, under a new name. The heading survives that
        // shift and lets apply-time lookups re-find it. This is exactly how
        // approving three gap-fills used to break the third one.
        $anchor_heading = '';

        if ('' !== $after_section_id) {
            $anchor = ECP_Content_Map::get_section($post, $after_section_id);

            if (!$anchor) {
                return new WP_Error('ecp_bad_section', __('The section this would be inserted after does not exist.', 'enhanced-content-plugin'));
            }

            $anchor_heading = $anchor['is_intro'] ? '' : (string) $anchor['heading'];
        }

        return array(
            'before'  => '',
            'after'   => $restored,
            'payload' => array(
                'after_section_id' => $after_section_id,
                'heading'          => $new_heading,
                'anchor_heading'   => $anchor_heading,
            ),
        );
    }

    private static function check_internal_link($post, $section_id, $content) {
        $result = self::check_section($post, 'internal_link_add', $section_id, $content);

        if (is_wp_error($result)) {
            return $result;
        }

        $before_links = self::extract_hrefs($result['before']);
        $after_links = self::extract_hrefs($result['after']);
        $added = array_values(array_diff($after_links, $before_links));
        $removed = array_values(array_diff($before_links, $after_links));

        if ($removed) {
            return new WP_Error('ecp_removed_links', __('This would remove links that are currently in the section.', 'enhanced-content-plugin'));
        }

        if (!$added) {
            return new WP_Error('ecp_no_link_added', __('No new link was actually added.', 'enhanced-content-plugin'));
        }

        if (count($added) > 2) {
            return new WP_Error('ecp_too_many_links', __('Adds more than two links at once. One link per change keeps them reviewable.', 'enhanced-content-plugin'));
        }

        // Every added link must resolve to a real, published page on this
        // site. This is the check that stops invented URLs.
        $home = wp_parse_url(home_url(), PHP_URL_HOST);

        foreach ($added as $href) {
            $host = wp_parse_url($href, PHP_URL_HOST);

            if ($host && $host !== $home) {
                return new WP_Error('ecp_external_link', sprintf(
                    /* translators: %s: the URL */
                    __('Adds a link to an external site (%s). Internal linking only.', 'enhanced-content-plugin'),
                    $href
                ));
            }

            $target_id = ECP_Search_Data::url_to_post_id($href);
            if (!$target_id) {
                return new WP_Error('ecp_dead_link', sprintf(
                    /* translators: %s: the URL */
                    __('Links to %s, which is not a published page on this site.', 'enhanced-content-plugin'),
                    $href
                ));
            }

            if ((int) $target_id === (int) $post->ID) {
                return new WP_Error('ecp_self_link', __('Links the page to itself.', 'enhanced-content-plugin'));
            }
        }

        $result['payload']['added_links'] = $added;

        return $result;
    }

    private static function check_image_alt($post, $src, $content, array $signals) {
        $alt = trim(wp_strip_all_tags($content));

        if ('' === $src) {
            return new WP_Error('ecp_no_image', __('No image was named.', 'enhanced-content-plugin'));
        }

        if (mb_strlen($alt) < 5 || mb_strlen($alt) > 200) {
            return new WP_Error('ecp_alt_length', __('Alt text should be between 5 and 200 characters.', 'enhanced-content-plugin'));
        }

        // Alt text that starts with "image of" wastes the first words a
        // screen reader announces.
        if (preg_match('/^\s*(image|picture|photo|graphic|screenshot)\s+(of|showing)\b/i', $alt)) {
            return new WP_Error('ecp_alt_redundant', __('Alt text should not start with "image of" — describe the content directly.', 'enhanced-content-plugin'));
        }

        $attachment_id = attachment_url_to_postid($src);

        // Confirm the image is actually on this page, so we don't retitle a
        // media-library item used elsewhere.
        if (false === strpos($post->post_content, $src) && !(int) $attachment_id) {
            return new WP_Error('ecp_image_not_found', __('That image is not on this page.', 'enhanced-content-plugin'));
        }

        unset($signals);

        return array(
            'before'  => $attachment_id ? (string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true) : '',
            'after'   => $alt,
            'payload' => array('src' => $src, 'attachment_id' => (int) $attachment_id),
        );
    }

    private static function check_faq($post, $content) {
        $entries = json_decode($content, true);

        if (!is_array($entries) || !$entries) {
            return new WP_Error('ecp_bad_faq', __('The FAQ entries were not valid JSON.', 'enhanced-content-plugin'));
        }

        // get_faq_data() returns { enabled, title, items }; the questions are
        // under 'items'.
        $faq = class_exists('ECP_FAQ') ? ECP_FAQ::get_faq_data($post->ID) : array('items' => array());
        $existing = isset($faq['items']) && is_array($faq['items']) ? $faq['items'] : array();

        $existing_questions = array();
        foreach ($existing as $item) {
            if (isset($item['question'])) {
                $existing_questions[] = strtolower(trim($item['question']));
            }
        }

        $clean = array();
        foreach ($entries as $entry) {
            if (!is_array($entry) || empty($entry['question']) || empty($entry['answer'])) {
                continue;
            }

            $question = trim(wp_strip_all_tags($entry['question']));
            $answer = trim(wp_kses_post($entry['answer']));

            if (mb_strlen($question) < 8 || mb_strlen($answer) < 20) {
                continue;
            }

            if (in_array(strtolower($question), $existing_questions, true)) {
                continue;   // Already answered.
            }

            $clean[] = array('question' => $question, 'answer' => $answer);
        }

        if (!$clean) {
            return new WP_Error('ecp_no_new_faq', __('No usable new FAQ entries — they were empty, too short, or already on the page.', 'enhanced-content-plugin'));
        }

        if (count($clean) > 6) {
            $clean = array_slice($clean, 0, 6);
        }

        $before = '';
        foreach ($existing as $item) {
            $before .= isset($item['question']) ? '• ' . $item['question'] . "\n" : '';
        }

        $after = $before;
        foreach ($clean as $item) {
            $after .= '• ' . $item['question'] . "\n";
        }

        return array(
            'before'  => trim($before),
            'after'   => trim($after),
            'payload' => array('entries' => $clean),
        );
    }

    private static function check_sources($post, $content) {
        $entries = json_decode($content, true);

        if (!is_array($entries) || !$entries) {
            return new WP_Error('ecp_bad_sources', __('The sources were not valid JSON.', 'enhanced-content-plugin'));
        }

        $existing = get_post_meta($post->ID, '_article_sources', true);
        $existing = is_array($existing) ? $existing : array();
        $existing_urls = wp_list_pluck($existing, 'url');

        // Only URLs that already appear in the article body are acceptable —
        // otherwise the agent is citing a page nobody has read.
        $body_urls = self::extract_hrefs($post->post_content);
        $body_urls = array_map('untrailingslashit', $body_urls);

        $clean = array();
        foreach ($entries as $entry) {
            if (!is_array($entry) || empty($entry['url'])) {
                continue;
            }

            $url = esc_url_raw(trim($entry['url']));
            if (!$url || !wp_http_validate_url($url)) {
                continue;
            }

            if (in_array($url, $existing_urls, true)) {
                continue;
            }

            if (!in_array(untrailingslashit($url), $body_urls, true)) {
                return new WP_Error('ecp_source_not_in_body', sprintf(
                    /* translators: %s: the URL */
                    __('Cites %s, which is not linked anywhere in the article. Sources must come from the page itself.', 'enhanced-content-plugin'),
                    $url
                ));
            }

            $clean[] = array(
                'url'   => $url,
                'label' => isset($entry['label']) ? sanitize_text_field($entry['label']) : '',
            );
        }

        if (!$clean) {
            return new WP_Error('ecp_no_new_sources', __('No new sources to add.', 'enhanced-content-plugin'));
        }

        $before = '';
        foreach ($existing as $item) {
            $before .= (isset($item['label']) ? $item['label'] . ' — ' : '') . (isset($item['url']) ? $item['url'] : '') . "\n";
        }

        $after = $before;
        foreach ($clean as $item) {
            $after .= ($item['label'] ? $item['label'] . ' — ' : '') . $item['url'] . "\n";
        }

        return array(
            'before'  => trim($before),
            'after'   => trim($after),
            'payload' => array('sources' => $clean),
        );
    }

    /* --------------------------------------------------------------------
     * Shared checks
     * ----------------------------------------------------------------- */

    /**
     * Allow the tag set an editor would use, and nothing else. In particular
     * no <script>, no <style>, no inline event handlers.
     */
    public static function sanitize_html($html) {
        $allowed = array(
            'p' => array(),
            'br' => array(),
            'strong' => array(), 'b' => array(),
            'em' => array(), 'i' => array(),
            'h2' => array('id' => array()), 'h3' => array('id' => array()), 'h4' => array('id' => array()),
            'ul' => array(), 'ol' => array(), 'li' => array(),
            'a' => array('href' => array(), 'title' => array(), 'rel' => array(), 'target' => array()),
            'blockquote' => array('cite' => array()),
            'table' => array(), 'thead' => array(), 'tbody' => array(),
            'tr' => array(), 'th' => array('scope' => array()), 'td' => array(),
            'code' => array(), 'pre' => array(),
            'figure' => array(), 'figcaption' => array(),
            'img' => array('src' => array(), 'alt' => array(), 'width' => array(), 'height' => array(), 'class' => array()),
            'dl' => array(), 'dt' => array(), 'dd' => array(),
        );

        /**
         * Filter the tags the agent is allowed to emit.
         *
         * @param array $allowed
         */
        $allowed = apply_filters('ecp_allowed_html', $allowed);

        // Preserve block comments and shortcodes, which wp_kses would eat.
        $placeholders = array();
        $counter = 0;

        $html = preg_replace_callback(
            '/<!--\s*\/?wp:.*?-->|\[[^\]]+\]/s',
            function ($match) use (&$placeholders, &$counter) {
                $counter++;
                $key = '<!--ECPKEEP' . $counter . '-->';
                $placeholders[$key] = $match[0];

                return $key;
            },
            (string) $html
        );

        $html = wp_kses($html, $allowed);

        return str_replace(array_keys($placeholders), array_values($placeholders), $html);
    }

    /**
     * Replace em dashes in generated text with punctuation a person would
     * have typed.
     *
     * Runs on the raw model output before validation, so every consumer —
     * sections, metadata, FAQ JSON, cluster rewrites, gap fills — is covered
     * by the one pass. Number ranges keep their en dash (2019–2024 is
     * correct typography, not an AI tell); only dashes doing clause-break
     * duty are rewritten.
     *
     * @param string $content
     * @return string
     */
    public static function remove_ai_dashes($content) {
        $content = (string) $content;

        if ('' === $content) {
            return $content;
        }

        // Em dash used as a clause break, any spacing: comma reads closest
        // to the original rhythm in almost every case.
        $content = preg_replace('/\s*\x{2014}\s*/u', ', ', $content);

        // Spaced en dash and spaced double hyphen do the same job. The
        // required spaces are what protect 2019–2024 and after--effects.
        $content = preg_replace('/\s+\x{2013}\s+/u', ', ', $content);
        $content = preg_replace('/\s+--\s+/', ', ', $content);

        // Substitution artefacts: a dash that sat next to existing
        // punctuation leaves a doubled or orphaned comma behind.
        $content = preg_replace('/,(\s*,)+/', ',', $content);
        $content = preg_replace('/,\s*([.;:!?])/', '$1', $content);

        return $content;
    }

    /**
     * @return string[] Banned phrases present in the text.
     */
    public static function find_banned_phrases($text) {
        $found = array();
        $haystack = strtolower((string) $text);

        foreach (ECP_Agent_Settings::banned_phrases() as $phrase) {
            if ('' === $phrase) {
                continue;
            }

            if (false !== strpos($haystack, strtolower($phrase))) {
                $found[] = $phrase;
            }
        }

        return $found;
    }

    /**
     * Brand terms that appear with the wrong casing or spacing.
     *
     * @return string[]
     */
    public static function find_mangled_brand_terms($text) {
        $mangled = array();
        $text = (string) $text;

        foreach (ECP_Agent_Settings::brand_terms() as $term) {
            if ('' === $term) {
                continue;
            }

            $appears_correctly = false !== strpos($text, $term);
            $appears_at_all = false !== stripos($text, $term);

            if ($appears_at_all && !$appears_correctly) {
                $mangled[] = $term;
            }
        }

        return $mangled;
    }

    /**
     * Numbers, dates, prices and percentages in the new text that are not in
     * the old text or anywhere else on the page.
     *
     * Small integers under 10 are ignored — "three ways to", "the 5 steps" —
     * because flagging those makes the check useless through noise.
     *
     * @return string[]
     */
    public static function new_figures($before, $after, $post = null) {
        $pattern = '/(?:[$£€]\s?\d[\d,]*(?:\.\d+)?|\d[\d,]*(?:\.\d+)?\s?%|\b(?:19|20)\d{2}\b|\b\d{2,}(?:[.,]\d+)?\b)/u';

        preg_match_all($pattern, (string) $after, $after_matches);
        if (empty($after_matches[0])) {
            return array();
        }

        // The comparison corpus is the previous version of this section plus
        // the rest of the page: a figure moved from one paragraph to another
        // is not a new claim.
        $corpus = (string) $before;
        if ($post) {
            $corpus .= ' ' . wp_strip_all_tags($post->post_content) . ' ' . $post->post_title;
        }

        $new = array();
        foreach (array_unique($after_matches[0]) as $figure) {
            $normalized = trim($figure);

            if (false !== strpos($corpus, $normalized)) {
                continue;
            }

            // Also match on digits alone, so "$1,200" vs "1200" doesn't
            // register as new.
            $digits = preg_replace('/[^\d]/', '', $normalized);
            if ($digits && false !== strpos(preg_replace('/[^\d]/', '', $corpus), $digits) && strlen($digits) >= 3) {
                continue;
            }

            if ($digits && (int) $digits < 10 && strlen($digits) === 1) {
                continue;
            }

            $new[] = $normalized;
        }

        return array_values($new);
    }

    /**
     * @return string[]
     */
    private static function extract_hrefs($html) {
        preg_match_all('/href\s*=\s*["\']([^"\']+)["\']/i', (string) $html, $matches);

        return isset($matches[1]) ? array_values(array_unique($matches[1])) : array();
    }

    private static function heading_changed($before_html, $after_html) {
        $extract = function ($html) {
            return preg_match('/<h([1-6])[^>]*>(.*?)<\/h\1>/is', (string) $html, $match)
                ? trim(wp_strip_all_tags($match[2]))
                : '';
        };

        return $extract($before_html) !== $extract($after_html);
    }

    /* --------------------------------------------------------------------
     * Apply-time checks
     * ----------------------------------------------------------------- */

    /**
     * Re-check a proposal immediately before writing it.
     *
     * Time passes between proposal and approval, and between approval and
     * apply. The post may have been edited in that window, and applying a
     * stale section rewrite would silently revert a human's work.
     *
     * @return true|WP_Error
     */
    public static function check_before_apply(array $proposal) {
        $post = get_post((int) $proposal['post_id']);

        if (!$post) {
            return new WP_Error('ecp_no_post', __('The post no longer exists.', 'enhanced-content-plugin'));
        }

        if ('trash' === $post->post_status) {
            return new WP_Error('ecp_trashed', __('The post is in the trash.', 'enhanced-content-plugin'));
        }

        if (in_array((int) $post->ID, ECP_Agent_Settings::excluded_post_ids(), true)) {
            return new WP_Error('ecp_excluded', __('That post is now on the exclusion list.', 'enhanced-content-plugin'));
        }

        // Drift is checked against the specific thing this change replaces,
        // not against the whole post.
        //
        // Hashing the entire post_content seems safer and is actually much
        // worse: approving several changes to one page invalidates every
        // change after the first, because applying the first one rewrites
        // post_content and moves the hash. The agent ends up blocking itself,
        // and the user is told a human edited the page when nobody did.
        //
        // What actually matters is narrower: is the section I am about to
        // replace still the section I was shown? Two changes to different
        // sections of one article do not conflict, and this lets them both
        // through while still catching a real outside edit.
        $info = ECP_Proposals::change_type($proposal['change_type']);
        $payload = is_array($proposal['payload']) ? $proposal['payload'] : array();

        if (!$info) {
            return true;
        }

        switch ($info['target']) {
            case 'section':
            case 'content':
                $section_id = isset($payload['section_id']) ? $payload['section_id'] : $proposal['target_key'];
                $heading = isset($payload['heading']) ? $payload['heading'] : '';

                $section = ECP_Content_Map::find_section($post, $section_id, $heading);

                if (!$section) {
                    return new WP_Error(
                        'ecp_section_missing',
                        __('The part of the page this change rewrites is no longer there — it was renamed or removed after the change was proposed. Re-analyze the page to work from the current text.', 'enhanced-content-plugin')
                    );
                }

                if (!ECP_Content_Map::section_matches($section, $proposal['before_value'])) {
                    return new WP_Error(
                        'ecp_section_changed',
                        __('This part of the page has been edited since the change was proposed, so applying it would undo that edit. Re-analyze the page.', 'enhanced-content-plugin')
                    );
                }
                break;

            case 'section_insert':
                // An insert is never blocked because its anchor moved.
                //
                // This check used to fail the change outright, and it kept
                // biting: section ids bake position into the hash, so any
                // earlier apply to the same page shifts them, and even the
                // stored anchor-heading fallback dies if that heading was
                // itself rewritten. But an insert replaces nothing — the
                // anchor is a placement preference, not a correctness
                // condition. The approved content matters; WHERE it lands
                // degrades gracefully (the writer appends at the end when
                // the anchor cannot be found, and says so).
                //
                // What genuinely invalidates an insert is its heading now
                // existing on the page — added by hand, or by an earlier
                // copy of this same proposal. That is the check worth
                // making here.
                $new_heading = isset($payload['heading']) ? trim((string) $payload['heading']) : '';

                if ('' !== $new_heading) {
                    foreach (ECP_Content_Map::sections($post) as $existing) {
                        if (!$existing['is_intro'] && 0 === strcasecmp(trim($existing['heading']), $new_heading)) {
                            return new WP_Error(
                                'ecp_duplicate_section',
                                __('The page already has a section with this heading — it was added after this change was proposed, so applying it would duplicate the section.', 'enhanced-content-plugin')
                            );
                        }
                    }
                }
                break;

            case 'attachment':
                $src = isset($payload['src']) ? $payload['src'] : '';
                $attachment_id = isset($payload['attachment_id']) ? (int) $payload['attachment_id'] : 0;

                if ($src && false === strpos($post->post_content, $src) && !$attachment_id) {
                    return new WP_Error(
                        'ecp_image_missing',
                        __('That image is no longer on the page.', 'enhanced-content-plugin')
                    );
                }
                break;
        }

        return true;
    }
}
