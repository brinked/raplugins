<?php
/**
 * Which existing pages should link to a given page.
 *
 * The plugin already reports orphaned pages — "no other page links here" —
 * and then offers no way to fix it, because every link change it knew how to
 * make added a link *from* the page you were looking at. The fix for an
 * orphan is the opposite: other pages need to point at it.
 *
 * Deliberately no AI. Finding an existing phrase in an existing paragraph and
 * wrapping it in an anchor is a search-and-replace problem, and doing it
 * deterministically means it costs nothing, cannot invent a URL, cannot
 * hallucinate a sentence, and produces a change a reviewer can verify at a
 * glance. The model is better used on things that need judgement.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ECP_Link_Suggestions {

    /** Never propose more than this many inbound links in one pass. */
    const MAX_PER_TARGET = 3;

    /** How many candidate source pages to examine. */
    const CANDIDATE_LIMIT = 40;

    /**
     * Find pages that should link to $post_id, and propose the links.
     *
     * @param int  $post_id  The page that needs inbound links.
     * @param bool $dry_run  Return candidates without creating proposals.
     * @return array|WP_Error { proposals: int[], candidates: array[] }
     */
    public static function build($post_id, $dry_run = false) {
        $target = get_post($post_id);

        if (!$target || 'publish' !== $target->post_status) {
            return new WP_Error('ecp_no_post', __('That page is not published.', 'enhanced-content-plugin'));
        }

        $permalink = get_permalink($target);
        $phrases = self::anchor_phrases($target);

        if (!$phrases) {
            return array('proposals' => array(), 'candidates' => array());
        }

        $candidates = self::find_sources($target, $phrases);
        $proposals = array();

        if ($dry_run) {
            return array('proposals' => array(), 'candidates' => $candidates);
        }

        foreach ($candidates as $candidate) {
            if (count($proposals) >= self::MAX_PER_TARGET) {
                break;
            }

            $id = self::propose_link($candidate, $target, $permalink);

            if ($id) {
                $proposals[] = $id;
            }
        }

        return array('proposals' => $proposals, 'candidates' => $candidates);
    }

    /* --------------------------------------------------------------------
     * Finding the phrase worth linking
     * ----------------------------------------------------------------- */

    /**
     * Phrases that would make good anchor text for this page.
     *
     * The page's own title, and — much better — the search terms it already
     * ranks for. A term Google associates with this page is a term that
     * describes it, which is exactly what anchor text is supposed to do.
     *
     * @return string[] Longest first, so the most specific match wins.
     */
    public static function anchor_phrases($post) {
        $phrases = array();

        // Measured first.
        foreach (ECP_Search_Data::top_queries($post->ID, 15) as $query) {
            if ($query['impressions'] >= 10 && str_word_count($query['query']) >= 2) {
                $phrases[] = $query['query'];
            }
        }

        // The title, and the title with any trailing qualifier stripped —
        // "Outdoor Cabinets vs Indoor Cabinets: What's the Difference?"
        // is never going to appear verbatim in another article.
        $title = wp_strip_all_tags($post->post_title);
        $phrases[] = $title;

        foreach (array(':', '|', ' - ', ' — ', '?') as $separator) {
            if (false !== strpos($title, $separator)) {
                $head = trim(strtok($title, $separator));

                if (str_word_count($head) >= 2) {
                    $phrases[] = $head;
                }
            }
        }

        $phrases = array_values(array_unique(array_filter(array_map('trim', $phrases))));

        // Longest first: linking "outdoor kitchen cabinets" is more useful
        // than linking "cabinets".
        usort($phrases, function ($a, $b) {
            return mb_strlen($b) <=> mb_strlen($a);
        });

        // Very short phrases produce noisy, meaningless links.
        return array_values(array_filter($phrases, function ($phrase) {
            return mb_strlen($phrase) >= 10 && mb_strlen($phrase) <= 80;
        }));
    }

    /**
     * Published pages that mention one of the phrases and don't already link.
     *
     * @return array[] { post_id, post_title, phrase, section_id, authority }
     */
    private static function find_sources($target, array $phrases) {
        global $wpdb;

        $post_types = (array) ECP_Agent_Settings::get('post_types', array('post'));
        $excluded = ECP_Agent_Settings::excluded_post_ids();
        $excluded[] = (int) $target->ID;

        $path = wp_parse_url(get_permalink($target), PHP_URL_PATH);
        $candidates = array();
        $seen = array();

        foreach ($phrases as $phrase) {
            if (count($candidates) >= self::CANDIDATE_LIMIT) {
                break;
            }

            $like = '%' . $wpdb->esc_like($phrase) . '%';
            $type_placeholders = implode(',', array_fill(0, count($post_types), '%s'));

            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT ID, post_title, post_content
                 FROM {$wpdb->posts}
                 WHERE post_status = 'publish'
                   AND post_type IN ({$type_placeholders})
                   AND post_content LIKE %s
                 LIMIT 25",
                array_merge($post_types, array($like))
            ));

            foreach ((array) $rows as $row) {
                $source_id = (int) $row->ID;

                if (isset($seen[$source_id]) || in_array($source_id, $excluded, true)) {
                    continue;
                }

                // Already links here? Then there is nothing to propose.
                if ($path && false !== strpos($row->post_content, $path)) {
                    $seen[$source_id] = true;
                    continue;
                }

                $spot = self::locate_phrase($row, $phrase);

                if (!$spot) {
                    continue;
                }

                $seen[$source_id] = true;

                $candidates[] = array(
                    'post_id'    => $source_id,
                    'post_title' => $row->post_title,
                    'phrase'     => $phrase,
                    'section_id' => $spot['section_id'],
                    'section'    => $spot['section'],
                    // A link from a page that itself gets traffic is worth
                    // more than one from a page nobody reaches.
                    'authority'  => self::authority($source_id),
                );
            }
        }

        usort($candidates, function ($a, $b) {
            return $b['authority'] <=> $a['authority'];
        });

        return $candidates;
    }

    /**
     * Find a body paragraph containing the phrase that can safely be linked.
     *
     * Skips headings (linking a heading is bad practice and breaks the
     * section id), anything already inside an anchor, and anything inside an
     * HTML attribute or shortcode.
     *
     * @return array|null { section_id, section }
     */
    private static function locate_phrase($post, $phrase) {
        foreach (ECP_Content_Map::sections($post) as $section) {
            // Never touch the heading itself.
            $body = preg_replace('/<h[1-6]\b[^>]*>.*?<\/h[1-6]>/is', '', $section['html']);

            if (false === stripos($body, $phrase)) {
                continue;
            }

            if (self::already_linked($body, $phrase)) {
                continue;
            }

            return array('section_id' => $section['id'], 'section' => $section);
        }

        return null;
    }

    /**
     * Is every occurrence of the phrase already inside an anchor?
     */
    private static function already_linked($html, $phrase) {
        $stripped = preg_replace('/<a\b[^>]*>.*?<\/a>/is', '', $html);

        return false === stripos($stripped, $phrase);
    }

    /**
     * A rough sense of how much a link from this page is worth.
     */
    private static function authority($post_id) {
        $metrics = ECP_Search_Data::page_metrics($post_id);
        $score = 0;

        if ($metrics) {
            $score += (int) $metrics['clicks'] * 5;
            $score += min(200, (int) $metrics['impressions'] / 10);
        }

        $post = get_post($post_id);

        if ($post) {
            $score += min(50, ECP_Content_Map::word_count(ECP_Content_Map::to_text($post->post_content)) / 40);
        }

        return (int) $score;
    }

    /* --------------------------------------------------------------------
     * Building the change
     * ----------------------------------------------------------------- */

    /**
     * Wrap the first free occurrence of the phrase in a link to the target.
     *
     * @return int Proposal id, or 0.
     */
    private static function propose_link(array $candidate, $target, $permalink) {
        $source = get_post($candidate['post_id']);

        if (!$source) {
            return 0;
        }

        $section = $candidate['section'];
        $linked = self::insert_link($section['html'], $candidate['phrase'], $permalink);

        if (null === $linked) {
            return 0;
        }

        $signals = ECP_Signals::collect($source);

        $change = array(
            'type'    => 'internal_link_add',
            'target'  => $section['id'],
            'content' => $linked,
            'title'   => sprintf(
                /* translators: 1: anchor phrase, 2: target page title */
                __('Link "%1$s" here to %2$s', 'enhanced-content-plugin'),
                $candidate['phrase'],
                get_the_title($target)
            ),
            'rationale' => sprintf(
                /* translators: 1: target title, 2: anchor phrase */
                __('"%1$s" has no other pages linking to it, which means it is crawled less and ranks worse than it should. This page already says "%2$s" in its body, so the link reads naturally rather than being bolted on.', 'enhanced-content-plugin'),
                get_the_title($target),
                $candidate['phrase']
            ),
        );

        $verdict = ECP_Guardrails::check($source, $change, $signals);

        if (is_wp_error($verdict)) {
            return 0;
        }

        $id = ECP_Proposals::create(array(
            'post_id'      => (int) $source->ID,
            'change_type'  => 'internal_link_add',
            'target_key'   => $section['id'],
            'title'        => $change['title'],
            'rationale'    => $change['rationale'],
            'evidence'     => array(
                'source'    => 'link_building',
                'links_to'  => (int) $target->ID,
                'phrase'    => $candidate['phrase'],
                'authority' => $candidate['authority'],
            ),
            'before_value' => $verdict['before'],
            'after_value'  => $verdict['after'],
            'payload'      => $verdict['payload'],
            // Deterministic: no model wrote this, so there is nothing to be
            // uncertain about beyond whether the editor wants the link.
            'confidence'   => 90,
            'risk'         => $verdict['risk'],
            'impact'       => 55,
            'flags'        => $verdict['flags'],
            'content_hash' => ECP_Content_Map::content_hash($source),
        ));

        return is_wp_error($id) ? 0 : (int) $id;
    }

    /**
     * Wrap the first occurrence of $phrase that is not already inside a link,
     * a heading, an HTML tag or a shortcode.
     *
     * @return string|null Null when no safe spot was found.
     */
    public static function insert_link($html, $phrase, $url) {
        // Hide the regions that must not be touched, do the replacement on
        // what's left, then put them back. Far more reliable than trying to
        // write one regex that means "not inside any of these".
        $shielded = array();
        $counter = 0;

        $shield = function ($match) use (&$shielded, &$counter) {
            $counter++;
            $key = "\x02ECPSHIELD{$counter}\x03";
            $shielded[$key] = $match[0];

            return $key;
        };

        $patterns = array(
            '/<a\b[^>]*>.*?<\/a>/is',                 // existing links
            '/<h[1-6]\b[^>]*>.*?<\/h[1-6]>/is',       // headings
            '/<[^>]+>/s',                              // any remaining tag
            '/\[[^\]]+\]/s',                           // shortcodes
        );

        $working = (string) $html;

        foreach ($patterns as $pattern) {
            $replaced = preg_replace_callback($pattern, $shield, $working);

            if (null !== $replaced) {
                $working = $replaced;
            }
        }

        $quoted = preg_quote($phrase, '/');
        $anchor = '<a href="' . esc_url($url) . '">$1</a>';

        // Word boundaries so "cabinet" doesn't match inside "cabinetry".
        $result = preg_replace('/\b(' . $quoted . ')\b/iu', $anchor, $working, 1, $count);

        if (null === $result || !$count) {
            return null;
        }

        return str_replace(array_keys($shielded), array_values($shielded), $result);
    }

    /* --------------------------------------------------------------------
     * Finding what needs links
     * ----------------------------------------------------------------- */

    /**
     * Published pages with no inbound internal links, worst first.
     *
     * @return array[] { post_id, post_title, clicks, impressions }
     */
    public static function orphans($limit = 25) {
        $post_types = (array) ECP_Agent_Settings::get('post_types', array('post'));

        $query = new WP_Query(array(
            'post_type'      => $post_types,
            'post_status'    => 'publish',
            'posts_per_page' => 300,
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'no_found_rows'  => true,
            'fields'         => 'ids',
        ));

        $orphans = array();
        $excluded = ECP_Agent_Settings::excluded_post_ids();

        foreach ($query->posts as $post_id) {
            if (count($orphans) >= $limit) {
                break;
            }

            if (in_array((int) $post_id, $excluded, true)) {
                continue;
            }

            $signals = ECP_Signals::collect($post_id);

            if (!$signals || (int) $signals['inbound_internal_links'] > 0) {
                continue;
            }

            $metrics = ECP_Search_Data::page_metrics($post_id);

            $orphans[] = array(
                'post_id'     => (int) $post_id,
                'post_title'  => get_the_title($post_id),
                'clicks'      => $metrics ? (int) $metrics['clicks'] : 0,
                'impressions' => $metrics ? (int) $metrics['impressions'] : 0,
            );
        }

        // A page that already gets impressions has the most to gain from
        // being properly connected.
        usort($orphans, function ($a, $b) {
            return $b['impressions'] <=> $a['impressions'];
        });

        return $orphans;
    }
}
