<?php
/**
 * Site Memory — what this site has taught the agent.
 *
 * Every approval, rejection, rollback, edit and measured outcome is already
 * recorded somewhere in this plugin. This class is where those records
 * become institutional memory: per-change-type track records, measured
 * outcomes, title-style performance, and the owner's own words when they
 * rejected something. Its two outputs are a human-readable panel and
 * prompt_context() — the paragraph that makes the next analysis calibrated
 * to THIS site instead of to generic SEO advice.
 *
 * Honesty rules, enforced here rather than hoped for: nothing is asserted
 * below a minimum sample size, outcome language stays correlational, and
 * the numbers shown to the model are the same numbers shown to the user.
 *
 * SaaS seam: pure reads over local tables today; in the SaaS this whole
 * class is a backend service and prompt_context() arrives with the plan's
 * entitlements.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ECP_Memory {

    /** Below these sample sizes, memory stays quiet rather than guessing. */
    const MIN_DECISIONS = 5;
    const MIN_OUTCOMES = 3;

    /**
     * Track record per change type: what the owner approves, rejects and
     * rolls back, and how applied changes of that type measured.
     *
     * Decisions come from the trust ladder (already counted once per real
     * decision); outcomes from measured proposals' latest verdicts.
     *
     * @return array<string,array>
     */
    public static function type_records() {
        global $wpdb;

        $records = array();

        foreach (ECP_Trust_Ladder::record() as $type => $entry) {
            $records[$type] = array(
                'approved' => (int) $entry['approved'],
                'rejected' => (int) $entry['rejected'],
                'reverted' => (int) $entry['reverted'],
                'decisions' => (int) $entry['approved'] + (int) $entry['rejected'],
                'improving' => 0,
                'declining' => 0,
                'measured' => 0,
            );
        }

        if (!ECP_DB::tables_exist()) {
            return $records;
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT change_type, evidence FROM ' . ECP_DB::proposals_table() . '
              WHERE status = %s AND evidence LIKE %s',
            ECP_Proposals::APPLIED,
            '%"latest_verdict"%'
        ), ARRAY_A);

        foreach ((array) $rows as $row) {
            $evidence = ECP_DB::decode($row['evidence']);
            $verdict = isset($evidence['latest_verdict']) ? $evidence['latest_verdict'] : '';

            if (!in_array($verdict, array('improving', 'stable', 'declining'), true)) {
                continue;
            }

            $type = $row['change_type'];

            if (!isset($records[$type])) {
                $records[$type] = array(
                    'approved' => 0, 'rejected' => 0, 'reverted' => 0, 'decisions' => 0,
                    'improving' => 0, 'declining' => 0, 'measured' => 0,
                );
            }

            $records[$type]['measured']++;

            if ('improving' === $verdict) {
                $records[$type]['improving']++;
            } elseif ('declining' === $verdict) {
                $records[$type]['declining']++;
            }
        }

        return $records;
    }

    /**
     * How each snippet style has fared on this site — the aggregation the
     * style tags exist for.
     *
     * @return array<string,array> style => { proposed, approved, rejected, measured, improving, declining }
     */
    public static function style_records() {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return array();
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT status, evidence FROM ' . ECP_DB::proposals_table() . "
              WHERE change_type IN ('meta_title', 'meta_description')
                AND evidence LIKE %s",
            '%"style"%'
        ), ARRAY_A);

        $styles = array();

        foreach ((array) $rows as $row) {
            $evidence = ECP_DB::decode($row['evidence']);
            $style = isset($evidence['style']) ? (string) $evidence['style'] : '';

            if ('' === $style) {
                continue;
            }

            if (!isset($styles[$style])) {
                $styles[$style] = array(
                    'proposed' => 0, 'approved' => 0, 'rejected' => 0,
                    'measured' => 0, 'improving' => 0, 'declining' => 0,
                );
            }

            $styles[$style]['proposed']++;

            if (in_array($row['status'], array(ECP_Proposals::APPLIED, ECP_Proposals::APPROVED), true)) {
                $styles[$style]['approved']++;
            } elseif (ECP_Proposals::REJECTED === $row['status']) {
                $styles[$style]['rejected']++;
            }

            $verdict = isset($evidence['latest_verdict']) ? $evidence['latest_verdict'] : '';

            if (in_array($verdict, array('improving', 'stable', 'declining'), true)) {
                $styles[$style]['measured']++;

                if ('improving' === $verdict) {
                    $styles[$style]['improving']++;
                } elseif ('declining' === $verdict) {
                    $styles[$style]['declining']++;
                }
            }
        }

        return $styles;
    }

    /**
     * The owner's own words when they said no. Three recent rejection
     * notes teach a model more about this site's taste than any statistic.
     *
     * @return array[] { type, note }
     */
    public static function rejection_notes($limit = 3) {
        global $wpdb;

        if (!ECP_DB::tables_exist()) {
            return array();
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT change_type, review_note FROM ' . ECP_DB::proposals_table() . "
              WHERE status = %s AND review_note != ''
              ORDER BY reviewed_at DESC
              LIMIT %d",
            ECP_Proposals::REJECTED,
            max(1, (int) $limit)
        ), ARRAY_A);

        $notes = array();

        foreach ((array) $rows as $row) {
            $notes[] = array(
                'type' => ECP_Proposals::type_label($row['change_type']),
                'note' => mb_substr(wp_strip_all_tags($row['review_note']), 0, 200),
            );
        }

        return $notes;
    }

    /**
     * Everything memory is confident enough to say, as human-readable
     * lines. One source for both the screen panel and the prompt, so the
     * model is never told something the user cannot see.
     *
     * @return string[]
     */
    public static function insights() {
        $lines = array();

        foreach (self::type_records() as $type => $record) {
            $label = ECP_Proposals::type_label($type);

            if ($record['decisions'] >= self::MIN_DECISIONS) {
                $rate = (int) round(($record['approved'] / max(1, $record['decisions'])) * 100);

                if ($rate >= 85) {
                    $lines[] = sprintf(
                        /* translators: 1: change type, 2: approved, 3: decisions */
                        __('%1$s: approved %2$d of %3$d — a change type this owner trusts.', 'enhanced-content-plugin'),
                        $label,
                        $record['approved'],
                        $record['decisions']
                    );
                } elseif ($rate <= 50) {
                    $lines[] = sprintf(
                        /* translators: 1: change type, 2: rejected, 3: decisions */
                        __('%1$s: rejected %2$d of %3$d — propose these sparingly and only with strong evidence.', 'enhanced-content-plugin'),
                        $label,
                        $record['rejected'],
                        $record['decisions']
                    );
                }
            }

            if ($record['reverted'] > 0) {
                $lines[] = sprintf(
                    /* translators: 1: change type, 2: revert count */
                    _n(
                        '%1$s: %2$d applied change was rolled back — treat this type with extra care.',
                        '%1$s: %2$d applied changes were rolled back — treat this type with extra care.',
                        $record['reverted'],
                        'enhanced-content-plugin'
                    ),
                    $label,
                    $record['reverted']
                );
            }

            if ($record['measured'] >= self::MIN_OUTCOMES && $record['improving'] > 0) {
                $lines[] = sprintf(
                    /* translators: 1: change type, 2: improved count, 3: measured count */
                    __('%1$s: %2$d of %3$d measured changes correlated with improvement on this site.', 'enhanced-content-plugin'),
                    $label,
                    $record['improving'],
                    $record['measured']
                );
            }
        }

        $style_labels = array(
            'benefit_driven' => __('benefit-driven', 'enhanced-content-plugin'),
            'question'       => __('question-form', 'enhanced-content-plugin'),
            'how_to'         => __('how-to', 'enhanced-content-plugin'),
            'list'           => __('list-style', 'enhanced-content-plugin'),
            'year_fresh'     => __('year-fresh', 'enhanced-content-plugin'),
            'brand'          => __('brand-led', 'enhanced-content-plugin'),
            'plain'          => __('plain', 'enhanced-content-plugin'),
        );

        foreach (self::style_records() as $style => $record) {
            if ($record['measured'] < self::MIN_OUTCOMES) {
                continue;
            }

            $label = isset($style_labels[$style]) ? $style_labels[$style] : $style;

            $lines[] = sprintf(
                /* translators: 1: style, 2: improved count, 3: measured count */
                __('Snippet style measured here: %1$s titles/descriptions improved %2$d of %3$d times.', 'enhanced-content-plugin'),
                $label,
                (int) $record['improving'],
                (int) $record['measured']
            );
        }

        return $lines;
    }

    /**
     * Site Memory as prompt context for the analysis stages.
     *
     * Returns '' until there is something meaningful to say — an empty
     * memory section in every prompt would just be noise the model learns
     * to ignore.
     *
     * @return string
     */
    public static function prompt_context() {
        $insights = self::insights();
        $notes = self::rejection_notes();

        if (!$insights && !$notes) {
            return '';
        }

        $lines = array();

        $lines[] = 'What this site\'s owner has taught the agent so far (measured on THIS site — weigh it above generic best practice, and let it shape which changes you propose and how many):';

        foreach ($insights as $insight) {
            $lines[] = '- ' . $insight;
        }

        if ($notes) {
            $lines[] = 'Recent rejections, in the owner\'s own words:';

            foreach ($notes as $note) {
                $lines[] = sprintf('- [%s] "%s"', $note['type'], $note['note']);
            }
        }

        return implode("\n", $lines);
    }
}
