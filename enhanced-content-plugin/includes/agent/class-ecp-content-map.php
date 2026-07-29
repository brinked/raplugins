<?php
/**
 * Turns post_content into addressable sections and back again.
 *
 * The agent never rewrites a whole article. It targets one section at a time,
 * identified by a stable id, and this class is the only place that knows how
 * to find that section and swap it out without disturbing anything else.
 *
 * Two content shapes are supported:
 *
 *   Block content  — parsed with parse_blocks() and re-serialized with
 *                    serialize_blocks(), so block comments, attributes and
 *                    inner blocks survive byte-for-byte except where we
 *                    deliberately replace them.
 *   Classic content — split on top-level headings using a regex over the raw
 *                    markup. We never round-trip classic content through DOM,
 *                    because DOMDocument reformats markup and mangles
 *                    shortcodes.
 *
 * Shortcodes, embeds, forms and script tags are replaced with opaque tokens
 * before the content is shown to the AI and restored afterwards. The model
 * therefore cannot delete or "improve" a shortcode it never saw.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ECP_Content_Map {

    /** Token wrapper unlikely to appear in prose or be reflowed by a model. */
    const TOKEN_OPEN = '{{ECP:';
    const TOKEN_CLOSE = '}}';

    /**
     * Break a post into sections.
     *
     * A section is a heading plus everything up to the next heading of the
     * same or higher level. Content before the first heading becomes the
     * "intro" section.
     *
     * @param WP_Post|int $post
     * @return array[] Each: { id, index, level, heading, html, text, words, is_intro, block_range }
     */
    public static function sections($post) {
        $post = get_post($post);
        if (!$post) {
            return array();
        }

        $content = (string) $post->post_content;

        if (self::has_blocks($content)) {
            return self::sections_from_blocks($content);
        }

        return self::sections_from_classic($content);
    }

    /**
     * Find one section by id.
     *
     * @return array|null
     */
    public static function get_section($post, $section_id) {
        return self::find_section($post, $section_id);
    }

    /**
     * Find a section, tolerating an ordinal shift.
     *
     * Section ids hash the heading, its level and its position in the
     * document. That position is what makes them useful — it distinguishes
     * two sections that happen to share a heading — but it also means that
     * inserting a new section renumbers every section after it, changing
     * their ids even though the sections themselves were never touched.
     *
     * When a proposal was created we also stored the heading text, so an id
     * that no longer resolves can be recovered by name. Only an unambiguous
     * match counts: if two sections share the heading there is no way to know
     * which one was meant, and guessing would rewrite the wrong part of the
     * article.
     *
     * @param WP_Post|int $post
     * @param string      $section_id
     * @param string      $heading_hint Heading recorded when the proposal was made.
     * @return array|null
     */
    public static function find_section($post, $section_id, $heading_hint = '') {
        $sections = self::sections($post);

        foreach ($sections as $section) {
            if ($section['id'] === $section_id) {
                return $section;
            }
        }

        $heading_hint = trim((string) $heading_hint);

        if ('' === $heading_hint) {
            return null;
        }

        $matches = array();

        foreach ($sections as $section) {
            if (!$section['is_intro'] && 0 === strcasecmp(trim($section['heading']), $heading_hint)) {
                $matches[] = $section;
            }
        }

        return 1 === count($matches) ? $matches[0] : null;
    }

    /**
     * Collapse whitespace so two renderings of the same markup compare equal.
     *
     * serialize_blocks() joins blocks with no separator, while our own
     * section splitter joins them with a blank line. Re-serializing a post
     * therefore changes the whitespace of sections nobody edited — so any
     * "has this changed?" test has to ignore it or it fires constantly.
     */
    public static function normalize_markup($html) {
        return trim(preg_replace('/\s+/u', ' ', (string) $html));
    }

    /**
     * Whether a section still holds the markup a proposal was built against.
     */
    public static function section_matches($section, $expected_html) {
        return self::normalize_markup($section['html']) === self::normalize_markup($expected_html);
    }

    /**
     * Replace a section's markup, returning the full new post_content.
     *
     * @param WP_Post|int $post
     * @param string      $section_id
     * @param string      $new_html   Replacement markup for the whole section,
     *                                heading included.
     * @return string|WP_Error New content, or WP_Error if the section is gone
     *                         (the post was edited since the proposal was made).
     */
    public static function replace_section($post, $section_id, $new_html, $heading_hint = '') {
        $post = get_post($post);
        if (!$post) {
            return new WP_Error('ecp_no_post', __('Post not found.', 'enhanced-content-plugin'));
        }

        // Resolve first, so an id invalidated by an earlier insert in the
        // same batch can still be recovered from the heading.
        $resolved = self::find_section($post, $section_id, $heading_hint);

        if (!$resolved) {
            return new WP_Error(
                'ecp_section_missing',
                __('The section this change targets no longer exists. The post was edited after the change was proposed.', 'enhanced-content-plugin')
            );
        }

        $section_id = $resolved['id'];
        $content = (string) $post->post_content;

        if (self::has_blocks($content)) {
            return self::replace_section_in_blocks($content, $section_id, $new_html);
        }

        return self::replace_section_in_classic($content, $section_id, $new_html);
    }

    /**
     * Insert new markup after a given section (used by section_add).
     *
     * @param string $section_id Empty string appends to the end of the post.
     * @return string|WP_Error
     */
    public static function insert_after_section($post, $section_id, $new_html, $heading_hint = '') {
        $post = get_post($post);
        if (!$post) {
            return new WP_Error('ecp_no_post', __('Post not found.', 'enhanced-content-plugin'));
        }

        $content = (string) $post->post_content;

        if ('' === $section_id) {
            return rtrim($content) . "\n\n" . trim($new_html) . "\n";
        }

        $section = self::find_section($post, $section_id, $heading_hint);

        if (!$section) {
            // The anchor moved or was renamed since the proposal was made.
            // Appending at the end beats refusing: the content was approved
            // and a new section's most common home is the end of the
            // article anyway. The caller logs the degradation so it is
            // visible, and one drag in the editor fixes placement — nothing
            // fixes a change that refused to apply.
            return rtrim($content) . "\n\n" . trim($new_html) . "\n";
        }

        return self::replace_section(
            $post,
            $section['id'],
            rtrim($section['html']) . "\n\n" . trim($new_html)
        );
    }

    /* --------------------------------------------------------------------
     * Block content
     * ----------------------------------------------------------------- */

    public static function has_blocks($content) {
        return function_exists('has_blocks') ? has_blocks($content) : (false !== strpos($content, '<!-- wp:'));
    }

    /**
     * @return array[]
     */
    private static function sections_from_blocks($content) {
        $blocks = parse_blocks($content);
        $sections = array();

        $current = self::new_section(0, 0, '', true);
        $current['block_range'] = array(0, -1);

        foreach ($blocks as $i => $block) {
            $heading = self::block_heading($block);

            if (null !== $heading) {
                // Close the previous section unless it is a still-empty intro.
                if (!self::section_is_empty($current)) {
                    $current['block_range'][1] = $i - 1;
                    $sections[] = self::finalize_section($current, count($sections));
                }

                $current = self::new_section(count($sections), $heading['level'], $heading['text'], false);
                $current['block_range'] = array($i, -1);
                $current['html'] = serialize_block($block);
                continue;
            }

            $current['html'] .= ('' === $current['html'] ? '' : "\n\n") . serialize_block($block);
        }

        if (!self::section_is_empty($current)) {
            $current['block_range'][1] = count($blocks) - 1;
            $sections[] = self::finalize_section($current, count($sections));
        }

        return $sections;
    }

    /**
     * @return string|WP_Error
     */
    private static function replace_section_in_blocks($content, $section_id, $new_html) {
        $blocks = parse_blocks($content);
        $sections = self::sections_from_blocks($content);

        $target = null;
        foreach ($sections as $section) {
            if ($section['id'] === $section_id) {
                $target = $section;
                break;
            }
        }

        if (!$target) {
            return new WP_Error(
                'ecp_section_missing',
                __('The section this change targets no longer exists. The post was edited after the change was proposed.', 'enhanced-content-plugin')
            );
        }

        list($start, $end) = $target['block_range'];
        if ($start < 0 || $end < $start || $end >= count($blocks)) {
            return new WP_Error('ecp_section_range', __('Could not locate the section in the post structure.', 'enhanced-content-plugin'));
        }

        // parse_blocks() on the replacement gives us properly formed blocks;
        // freeform HTML becomes a core/html-less "classic" block which
        // serialize_blocks() emits verbatim. Either way nothing is lost.
        $replacement = parse_blocks(self::ensure_blocks($new_html));
        $replacement = array_values(array_filter($replacement, array(__CLASS__, 'block_is_meaningful')));

        array_splice($blocks, $start, ($end - $start + 1), $replacement);

        return serialize_blocks($blocks);
    }

    /**
     * A parsed block with no name and only whitespace content is the artefact
     * parse_blocks() leaves between real blocks. Dropping it keeps the
     * re-serialized output clean.
     */
    public static function block_is_meaningful($block) {
        if (!empty($block['blockName'])) {
            return true;
        }

        return '' !== trim((string) $block['innerHTML']);
    }

    /**
     * Wrap raw HTML in block comments when the model returned plain markup,
     * so it round-trips as real blocks rather than one classic lump.
     */
    private static function ensure_blocks($html) {
        $html = trim((string) $html);

        if ('' === $html || self::has_blocks($html)) {
            return $html;
        }

        $out = array();

        // Split on top-level block-ish elements and wrap each in the matching
        // core block comment. Anything unrecognised becomes core/html.
        $chunks = preg_split('/(?=<(?:h[1-6]|p|ul|ol|table|blockquote|figure|pre)\b)/i', $html, -1, PREG_SPLIT_NO_EMPTY);

        foreach ((array) $chunks as $chunk) {
            $chunk = trim($chunk);
            if ('' === $chunk) {
                continue;
            }

            if (preg_match('/^<h([1-6])\b/i', $chunk, $m)) {
                $level = (int) $m[1];
                $attrs = 2 === $level ? '' : ' {"level":' . $level . '}';
                $out[] = "<!-- wp:heading{$attrs} -->\n{$chunk}\n<!-- /wp:heading -->";
            } elseif (preg_match('/^<p\b/i', $chunk)) {
                $out[] = "<!-- wp:paragraph -->\n{$chunk}\n<!-- /wp:paragraph -->";
            } elseif (preg_match('/^<(ul|ol)\b/i', $chunk)) {
                $out[] = "<!-- wp:list -->\n{$chunk}\n<!-- /wp:list -->";
            } elseif (preg_match('/^<table\b/i', $chunk)) {
                $out[] = "<!-- wp:table -->\n<figure class=\"wp-block-table\">{$chunk}</figure>\n<!-- /wp:table -->";
            } elseif (preg_match('/^<blockquote\b/i', $chunk)) {
                $out[] = "<!-- wp:quote -->\n{$chunk}\n<!-- /wp:quote -->";
            } else {
                $out[] = "<!-- wp:html -->\n{$chunk}\n<!-- /wp:html -->";
            }
        }

        return implode("\n\n", $out);
    }

    /**
     * Heading info for a block, or null when it isn't a heading.
     *
     * @return array|null { level, text }
     */
    private static function block_heading($block) {
        $name = isset($block['blockName']) ? $block['blockName'] : '';

        if ('core/heading' === $name) {
            $level = isset($block['attrs']['level']) ? (int) $block['attrs']['level'] : 2;
            return array(
                'level' => $level,
                'text'  => trim(wp_strip_all_tags((string) $block['innerHTML'])),
            );
        }

        // Classic blocks and core/html can still contain a heading.
        if ('' === $name || 'core/html' === $name || 'core/freeform' === $name) {
            $html = (string) $block['innerHTML'];
            if (preg_match('/^\s*<h([1-6])[^>]*>(.*?)<\/h\1>/is', $html, $m)) {
                return array(
                    'level' => (int) $m[1],
                    'text'  => trim(wp_strip_all_tags($m[2])),
                );
            }
        }

        return null;
    }

    /* --------------------------------------------------------------------
     * Classic content
     * ----------------------------------------------------------------- */

    /**
     * @return array[]
     */
    private static function sections_from_classic($content) {
        // Capture each heading and the offset it starts at.
        $pattern = '/<h([1-6])\b[^>]*>(.*?)<\/h\1>/is';
        if (!preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            // No headings at all: the whole post is one intro section.
            $only = self::new_section(0, 0, '', true);
            $only['html'] = $content;
            $only['char_range'] = array(0, strlen($content));

            return self::section_is_empty($only) ? array() : array(self::finalize_section($only, 0));
        }

        $sections = array();
        $boundaries = array();

        foreach ($matches[0] as $i => $match) {
            $boundaries[] = array(
                'offset' => (int) $match[1],
                'level'  => (int) $matches[1][$i][0],
                'text'   => trim(wp_strip_all_tags($matches[2][$i][0])),
            );
        }

        // Intro: everything before the first heading.
        $first_offset = $boundaries[0]['offset'];
        if ($first_offset > 0) {
            $intro = self::new_section(0, 0, '', true);
            $intro['html'] = substr($content, 0, $first_offset);
            $intro['char_range'] = array(0, $first_offset);
            if (!self::section_is_empty($intro)) {
                $sections[] = self::finalize_section($intro, 0);
            }
        }

        foreach ($boundaries as $i => $boundary) {
            $start = $boundary['offset'];
            $end = isset($boundaries[$i + 1]) ? $boundaries[$i + 1]['offset'] : strlen($content);

            $section = self::new_section(count($sections), $boundary['level'], $boundary['text'], false);
            $section['html'] = substr($content, $start, $end - $start);
            $section['char_range'] = array($start, $end);

            $sections[] = self::finalize_section($section, count($sections));
        }

        return $sections;
    }

    /**
     * @return string|WP_Error
     */
    private static function replace_section_in_classic($content, $section_id, $new_html) {
        foreach (self::sections_from_classic($content) as $section) {
            if ($section['id'] !== $section_id) {
                continue;
            }

            list($start, $end) = $section['char_range'];

            return substr($content, 0, $start) . trim($new_html) . "\n\n" . substr($content, $end);
        }

        return new WP_Error(
            'ecp_section_missing',
            __('The section this change targets no longer exists. The post was edited after the change was proposed.', 'enhanced-content-plugin')
        );
    }

    /* --------------------------------------------------------------------
     * Section plumbing
     * ----------------------------------------------------------------- */

    private static function new_section($index, $level, $heading, $is_intro) {
        return array(
            'index'       => $index,
            'level'       => $level,
            'heading'     => $heading,
            'html'        => '',
            'is_intro'    => (bool) $is_intro,
            'block_range' => array(-1, -1),
            'char_range'  => array(-1, -1),
        );
    }

    private static function section_is_empty($section) {
        return '' === trim(wp_strip_all_tags(strip_shortcodes($section['html'])));
    }

    /**
     * Assign the stable id and derived text fields.
     *
     * The id is a hash of the heading text plus the ordinal of that heading,
     * *not* of the body. That way a proposal survives the body being edited
     * slightly, but correctly fails to apply if the heading is renamed or the
     * section is deleted — which is when a human should look again anyway.
     */
    private static function finalize_section($section, $ordinal) {
        $section['index'] = $ordinal;

        $seed = $section['is_intro']
            ? 'intro'
            : strtolower($section['heading']) . '|' . $section['level'] . '|' . $ordinal;

        $section['id'] = ($section['is_intro'] ? 'intro-' : 'sec-') . substr(md5($seed), 0, 12);
        $section['text'] = self::to_text($section['html']);
        $section['words'] = self::word_count($section['text']);

        return $section;
    }

    /**
     * Readable plain text for a chunk of post markup.
     */
    public static function to_text($html) {
        $html = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', (string) $html);
        $html = preg_replace('/<\/(p|div|h[1-6]|li|tr|blockquote)>/i', "\n", $html);
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html);
        $text = wp_strip_all_tags(strip_shortcodes($html));
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        return trim((string) $text);
    }

    /**
     * Multibyte-safe word count.
     */
    public static function word_count($text) {
        return (int) preg_match_all('/\S+/u', (string) $text, $ignored);
    }

    /* --------------------------------------------------------------------
     * Protecting dynamic markup from the model
     * ----------------------------------------------------------------- */

    /**
     * Swap shortcodes, script/style/iframe/form blocks and block comments for
     * opaque tokens.
     *
     * @param string $html
     * @return array { text: string, tokens: array<string,string> }
     */
    public static function protect($html) {
        $tokens = array();
        $counter = 0;

        $store = function ($match) use (&$tokens, &$counter) {
            $counter++;
            $key = self::TOKEN_OPEN . $counter . self::TOKEN_CLOSE;
            $tokens[$key] = $match[0];

            return $key;
        };

        $patterns = array(
            // Paired shortcodes with their inner content.
            '/\[(\w[\w-]*)(?![\w-])[^\]]*\](?:.*?\[\/\1\])?/is',
            // Script, style, iframe, form, noscript, svg blocks.
            '/<(script|style|iframe|form|noscript|svg)\b[^>]*>.*?<\/\1>/is',
            // Self-closing embeds.
            '/<(embed|object|input)\b[^>]*\/?>/i',
        );

        $out = (string) $html;
        foreach ($patterns as $pattern) {
            $replaced = preg_replace_callback($pattern, $store, $out);
            // preg_replace_callback returns null on catastrophic backtracking;
            // keeping the previous value is safer than losing the content.
            if (null !== $replaced) {
                $out = $replaced;
            }
        }

        return array('text' => $out, 'tokens' => $tokens);
    }

    /**
     * Put the protected markup back.
     *
     * @param string $text
     * @param array  $tokens
     * @return string
     */
    public static function restore($text, array $tokens) {
        if (!$tokens) {
            return (string) $text;
        }

        return str_replace(array_keys($tokens), array_values($tokens), (string) $text);
    }

    /**
     * Which protection tokens went missing from a model's output.
     *
     * A non-empty result means the model dropped a shortcode or embed, and the
     * proposal must be rejected rather than applied.
     *
     * @return string[] The original markup of each dropped token.
     */
    public static function missing_tokens($text, array $tokens) {
        $missing = array();

        foreach ($tokens as $key => $original) {
            if (false === strpos((string) $text, $key)) {
                $missing[] = $original;
            }
        }

        return $missing;
    }

    /* --------------------------------------------------------------------
     * Whole-post helpers
     * ----------------------------------------------------------------- */

    /**
     * A hash of the content the agent based its proposals on.
     *
     * Stored with each proposal and re-checked at apply time: if the post has
     * changed underneath, the change is held back instead of clobbering an
     * edit a human just made.
     */
    public static function content_hash($post) {
        $post = get_post($post);
        if (!$post) {
            return '';
        }

        return sha1($post->post_content . '|' . $post->post_title);
    }

    /**
     * Outline of headings, for the analysis prompt.
     *
     * @return array[] { level, text }
     */
    public static function outline($post) {
        $outline = array();

        foreach (self::sections($post) as $section) {
            if ($section['is_intro']) {
                continue;
            }

            $outline[] = array(
                'level' => $section['level'],
                'text'  => $section['heading'],
                'id'    => $section['id'],
                'words' => $section['words'],
            );
        }

        return $outline;
    }
}
