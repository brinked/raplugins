<?php
/**
 * Word-level diff rendering for the review queue.
 *
 * A reviewer approving 30 changes needs to see *what moved*, not two walls of
 * prose side by side. WordPress ships wp_text_diff(), but it is line-based and
 * built for code — on reflowed paragraphs it marks every line as changed and
 * tells you nothing. So this does a word-level LCS instead.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ECP_Diff {

    /**
     * Render an inline word-level diff as safe HTML.
     *
     * @param string $before
     * @param string $after
     * @return string
     */
    public static function inline($before, $after) {
        $before_words = self::tokenize(self::readable($before));
        $after_words = self::tokenize(self::readable($after));

        // A pathological pair would make the O(n*m) table enormous. Above the
        // cap, fall back to a plain before/after — still useful, just not
        // word-highlighted.
        if (count($before_words) > 1200 || count($after_words) > 1200) {
            return self::side_by_side_fallback($before, $after);
        }

        $ops = self::diff_ops($before_words, $after_words);

        $html = '';
        $pending_removed = array();
        $pending_added = array();

        $flush = function () use (&$html, &$pending_removed, &$pending_added) {
            if ($pending_removed) {
                $html .= '<del class="ecp-diff-del">' . esc_html(implode('', $pending_removed)) . '</del>';
                $pending_removed = array();
            }
            if ($pending_added) {
                $html .= '<ins class="ecp-diff-ins">' . esc_html(implode('', $pending_added)) . '</ins>';
                $pending_added = array();
            }
        };

        foreach ($ops as $op) {
            switch ($op[0]) {
                case 'same':
                    $flush();
                    $html .= esc_html($op[1]);
                    break;

                case 'del':
                    $pending_removed[] = $op[1];
                    break;

                case 'add':
                    $pending_added[] = $op[1];
                    break;
            }
        }

        $flush();

        return nl2br($html);
    }

    /**
     * Two-column before/after, for changes where an inline diff is noise
     * (a completely new section, or a metadata field).
     */
    public static function side_by_side($before, $after, $before_label = '', $after_label = '') {
        $before_label = $before_label ? $before_label : __('Now', 'enhanced-content-plugin');
        $after_label = $after_label ? $after_label : __('Proposed', 'enhanced-content-plugin');

        $before_text = trim(self::readable($before));
        $after_text = trim(self::readable($after));

        ob_start();
        ?>
        <div class="ecp-diff-columns">
            <div class="ecp-diff-col ecp-diff-col-before">
                <h4><?php echo esc_html($before_label); ?></h4>
                <?php if ('' === $before_text) : ?>
                    <p class="ecp-diff-empty"><?php esc_html_e('(nothing there yet)', 'enhanced-content-plugin'); ?></p>
                <?php else : ?>
                    <div class="ecp-diff-body"><?php echo nl2br(esc_html($before_text)); ?></div>
                <?php endif; ?>
            </div>
            <div class="ecp-diff-col ecp-diff-col-after">
                <h4><?php echo esc_html($after_label); ?></h4>
                <div class="ecp-diff-body"><?php echo nl2br(esc_html($after_text)); ?></div>
            </div>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    private static function side_by_side_fallback($before, $after) {
        return self::side_by_side($before, $after);
    }

    /**
     * Pick the better presentation automatically.
     *
     * Nothing to compare against means side-by-side is pointless; a heavily
     * rewritten section means an inline diff would be all colour.
     */
    public static function render($before, $after) {
        $before_text = trim(self::readable($before));
        $after_text = trim(self::readable($after));

        if ('' === $before_text) {
            return '<div class="ecp-diff-new">' . nl2br(esc_html($after_text)) . '</div>';
        }

        $similarity = self::similarity($before_text, $after_text);

        if ($similarity < 0.35) {
            return self::side_by_side($before, $after);
        }

        return '<div class="ecp-diff-inline">' . self::inline($before, $after) . '</div>';
    }

    /**
     * A quick 0-1 similarity estimate.
     */
    public static function similarity($a, $b) {
        $a = mb_strtolower($a);
        $b = mb_strtolower($b);

        if ('' === $a || '' === $b) {
            return 0.0;
        }

        // similar_text() is O(n^3) in the worst case; sample long strings.
        if (mb_strlen($a) > 4000) {
            $a = mb_substr($a, 0, 4000);
        }
        if (mb_strlen($b) > 4000) {
            $b = mb_substr($b, 0, 4000);
        }

        similar_text($a, $b, $percent);

        return $percent / 100;
    }

    /**
     * A one-line summary of the size of a change, for the queue list.
     */
    public static function summary($before, $after) {
        $before_words = ECP_Content_Map::word_count(self::readable($before));
        $after_words = ECP_Content_Map::word_count(self::readable($after));
        $delta = $after_words - $before_words;

        if (0 === $before_words) {
            return sprintf(
                /* translators: %d: word count */
                _n('Adds %d word', 'Adds %d words', $after_words, 'enhanced-content-plugin'),
                $after_words
            );
        }

        if (0 === $delta) {
            return __('Reworded, same length', 'enhanced-content-plugin');
        }

        if ($delta > 0) {
            return sprintf(
                /* translators: %d: number of words added */
                _n('+%d word', '+%d words', $delta, 'enhanced-content-plugin'),
                $delta
            );
        }

        return sprintf(
            /* translators: %d: number of words removed */
            _n('−%d word', '−%d words', abs($delta), 'enhanced-content-plugin'),
            abs($delta)
        );
    }

    /* --------------------------------------------------------------------
     * Internals
     * ----------------------------------------------------------------- */

    /**
     * Markup to comparable plain text.
     */
    private static function readable($html) {
        return ECP_Content_Map::to_text($html);
    }

    /**
     * Split into words *and* the whitespace between them, so reassembling the
     * token list reproduces the original text exactly.
     *
     * @return string[]
     */
    private static function tokenize($text) {
        $parts = preg_split('/(\s+)/u', (string) $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        return is_array($parts) ? $parts : array();
    }

    /**
     * Longest-common-subsequence diff over token arrays.
     *
     * @return array[] Each: [op, token] where op is same|del|add.
     */
    private static function diff_ops(array $old, array $new) {
        $n = count($old);
        $m = count($new);

        // LCS length table.
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));

        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = ($old[$i] === $new[$j])
                    ? $lcs[$i + 1][$j + 1] + 1
                    : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }

        $ops = array();
        $i = 0;
        $j = 0;

        while ($i < $n && $j < $m) {
            if ($old[$i] === $new[$j]) {
                $ops[] = array('same', $old[$i]);
                $i++;
                $j++;
            } elseif ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
                $ops[] = array('del', $old[$i]);
                $i++;
            } else {
                $ops[] = array('add', $new[$j]);
                $j++;
            }
        }

        while ($i < $n) {
            $ops[] = array('del', $old[$i]);
            $i++;
        }

        while ($j < $m) {
            $ops[] = array('add', $new[$j]);
            $j++;
        }

        return $ops;
    }
}
