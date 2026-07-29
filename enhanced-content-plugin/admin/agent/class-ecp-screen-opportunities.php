<?php
/**
 * The prioritised queue: which pages are worth spending an analysis on, and
 * why. Every score is expandable into the evidence behind it, because a
 * ranked list nobody can interrogate is just a ranked list nobody trusts.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ECP_Screen_Opportunities {

    public static function render() {
        if (!ECP_Capabilities::can_view()) {
            wp_die(esc_html__('You do not have permission to view this page.', 'enhanced-content-plugin'));
        }

        $status = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : ECP_Opportunity_Engine::STATUS_OPEN;
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        $per_page = 25;

        $result = ECP_Opportunity_Engine::query(array(
            'status' => $status,
            'search' => $search,
            'limit'  => $per_page,
            'offset' => ($paged - 1) * $per_page,
            'author' => ECP_Capabilities::author_scope(),
        ));

        $stats = ECP_Opportunity_Engine::stats();

        // Analysis costs money, so "ready" here means both configured *and*
        // this user is allowed to spend the budget.
        $ready = ECP_Agent_Settings::is_ready() && ECP_Capabilities::can_analyze();

        ?>
        <div class="wrap ecp-wrap">
            <h1><?php esc_html_e('Opportunities', 'enhanced-content-plugin'); ?></h1>

            <?php ECP_Admin_Menu::header('ecp-opportunities'); ?>

            <p class="ecp-lede">
                <?php esc_html_e('Every page, scored by how much is demonstrably wrong with it and how much search traffic is realistically within reach. Analysing a page is what costs money — this list is how you spend that budget where it counts.', 'enhanced-content-plugin'); ?>
            </p>

            <?php if (0 === $stats['total']) : ?>
                <div class="ecp-empty">
                    <h2><?php esc_html_e('Nothing scored yet', 'enhanced-content-plugin'); ?></h2>
                    <p><?php esc_html_e('Scanning reads your pages and scores them. It makes no AI calls and costs nothing.', 'enhanced-content-plugin'); ?></p>
                    <button type="button" class="button button-primary button-hero" id="ecp-run-scan">
                        <?php esc_html_e('Scan my content', 'enhanced-content-plugin'); ?>
                    </button>
                    <p class="ecp-scan-progress" aria-live="polite"></p>
                </div>
            <?php else : ?>

                <div class="ecp-filter-bar">
                    <div class="ecp-risk-filters">
                        <?php
                        $tabs = array(
                            ECP_Opportunity_Engine::STATUS_OPEN      => __('Open', 'enhanced-content-plugin'),
                            ECP_Opportunity_Engine::STATUS_PROPOSED  => __('Changes proposed', 'enhanced-content-plugin'),
                            ECP_Opportunity_Engine::STATUS_DONE      => __('Done', 'enhanced-content-plugin'),
                            ECP_Opportunity_Engine::STATUS_DISMISSED => __('Dismissed', 'enhanced-content-plugin'),
                        );

                        foreach ($tabs as $slug => $label) {
                            printf(
                                '<a href="%s" class="ecp-pill%s">%s</a>',
                                esc_url(add_query_arg(array('page' => 'ecp-opportunities', 'status' => $slug), admin_url('admin.php'))),
                                $slug === $status ? ' is-active' : '',
                                esc_html($label)
                            );
                        }
                        ?>
                    </div>

                    <form method="get" class="ecp-type-filter">
                        <input type="hidden" name="page" value="ecp-opportunities">
                        <input type="hidden" name="status" value="<?php echo esc_attr($status); ?>">
                        <label class="screen-reader-text" for="ecp-search"><?php esc_html_e('Search pages', 'enhanced-content-plugin'); ?></label>
                        <input type="search" id="ecp-search" name="s" value="<?php echo esc_attr($search); ?>"
                               placeholder="<?php esc_attr_e('Search page titles', 'enhanced-content-plugin'); ?>">
                        <?php submit_button(__('Search', 'enhanced-content-plugin'), 'secondary', '', false); ?>
                        <button type="button" class="button" id="ecp-run-scan"><?php esc_html_e('Rescan', 'enhanced-content-plugin'); ?></button>
                    </form>
                </div>
                <p class="ecp-scan-progress" aria-live="polite"></p>

                <?php if (!$result['items']) : ?>
                    <p><?php esc_html_e('Nothing matches.', 'enhanced-content-plugin'); ?></p>
                <?php else : ?>
                    <table class="widefat striped ecp-opportunity-table">
                        <thead>
                            <tr>
                                <th class="ecp-col-score"><?php esc_html_e('Score', 'enhanced-content-plugin'); ?></th>
                                <th><?php esc_html_e('Page', 'enhanced-content-plugin'); ?></th>
                                <th><?php esc_html_e('What is wrong', 'enhanced-content-plugin'); ?></th>
                                <th class="ecp-col-search"><?php esc_html_e('Search', 'enhanced-content-plugin'); ?></th>
                                <th class="ecp-col-actions"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($result['items'] as $row) : ?>
                                <?php self::render_row($row, $ready); ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php self::render_pagination($result['total'], $paged, $per_page); ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function render_row(array $row, $ready) {
        $post_id = (int) $row['post_id'];
        $issues = is_array($row['reasons']) ? $row['reasons'] : array();
        $metrics = ECP_Search_Data::page_metrics($post_id);
        $score = (float) $row['score'];

        ?>
        <tr>
            <td class="ecp-col-score">
                <span class="ecp-score ecp-score-<?php echo esc_attr(self::score_band($score)); ?>">
                    <?php echo esc_html(number_format_i18n($score, 0)); ?>
                </span>
            </td>

            <td>
                <strong><a href="<?php echo esc_url(get_edit_post_link($post_id)); ?>"><?php echo esc_html($row['post_title']); ?></a></strong>
                <div class="ecp-row-meta">
                    <?php echo esc_html(ECP_Opportunity_Engine::reason_label($row['primary_reason'])); ?>
                    <?php if (!empty($row['potential_clicks']) && (float) $row['potential_clicks'] >= 1) : ?>
                        <span class="ecp-sep">·</span>
                        <?php
                        printf(
                            /* translators: %s: estimated additional clicks per month */
                            esc_html__('~%s more clicks/month within reach', 'enhanced-content-plugin'),
                            esc_html(number_format_i18n((float) $row['potential_clicks'], 0))
                        );
                        ?>
                    <?php endif; ?>
                </div>
            </td>

            <td>
                <?php $gaps = ECP_Content_Gaps::get_report($post_id); ?>

                <?php if ($gaps) : ?>
                    <div class="ecp-gap-summary">
                        <strong><?php
                            printf(
                                /* translators: %d: percentage */
                                esc_html__('Answers %d%% of what its reader needs', 'enhanced-content-plugin'),
                                (int) $gaps['completeness']
                            );
                        ?></strong>
                        <?php if (!empty($gaps['gaps']) || !empty($gaps['for_you'])) : ?>
                            <div class="ecp-row-meta">
                                <?php
                                $bits = array();

                                if (!empty($gaps['gaps'])) {
                                    $bits[] = sprintf(
                                        /* translators: %d: number of gaps */
                                        _n('%d unanswered question', '%d unanswered questions', count($gaps['gaps']), 'enhanced-content-plugin'),
                                        count($gaps['gaps'])
                                    );
                                }

                                if (!empty($gaps['for_you'])) {
                                    $bits[] = sprintf(
                                        /* translators: %d: number needing owner input */
                                        _n('%d needs your input', '%d need your input', count($gaps['for_you']), 'enhanced-content-plugin'),
                                        count($gaps['for_you'])
                                    );
                                }

                                echo esc_html(implode(' · ', $bits));
                                ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if (!$issues) : ?>
                    <span class="ecp-muted"><?php esc_html_e('Nothing detected', 'enhanced-content-plugin'); ?></span>
                <?php else : ?>
                    <ul class="ecp-issue-list">
                        <?php foreach (array_slice($issues, 0, 4) as $issue) : ?>
                            <li class="ecp-issue-<?php echo esc_attr($issue['severity']); ?>"
                                title="<?php echo esc_attr($issue['detail']); ?>">
                                <?php echo esc_html($issue['label']); ?>
                            </li>
                        <?php endforeach; ?>
                        <?php if (count($issues) > 4) : ?>
                            <li class="ecp-muted">
                                <?php
                                printf(
                                    /* translators: %d: number of additional issues */
                                    esc_html__('+%d more', 'enhanced-content-plugin'),
                                    count($issues) - 4
                                );
                                ?>
                            </li>
                        <?php endif; ?>
                    </ul>
                <?php endif; ?>
            </td>

            <td class="ecp-col-search">
                <?php
                // The page's best-ranking term, not its blended average. An
                // average across every term a page appears for describes
                // nothing you can act on; "eleventh for this, and here's the
                // volume" does.
                $best = ECP_Rankings::best_for_post($post_id);
                ?>
                <?php if ($best) : ?>
                    <a class="ecp-band-badge ecp-rank-<?php echo esc_attr($best['band']); ?>"
                       href="<?php echo esc_url(add_query_arg(array('page' => 'ecp-rankings', 'band' => 'all', 'post' => $post_id), admin_url('admin.php'))); ?>"
                       title="<?php echo esc_attr(sprintf(
                           /* translators: 1: position, 2: search term */
                           __('Best position %1$s, for "%2$s". Click for every term this page ranks for.', 'enhanced-content-plugin'),
                           number_format_i18n($best['position'], 1),
                           $best['query']
                       )); ?>">
                        <?php echo esc_html(ECP_Rankings::band_label($best['band'])); ?>
                        &middot; #<?php echo esc_html(number_format_i18n($best['position'], 1)); ?>
                    </a>
                    <div class="ecp-row-meta ecp-rank-best-query"><?php echo esc_html($best['query']); ?></div>
                <?php endif; ?>

                <?php if ($metrics) : ?>
                    <div class="ecp-metric-stack">
                        <span><?php echo esc_html(number_format_i18n($metrics['clicks'])); ?> <?php esc_html_e('clicks', 'enhanced-content-plugin'); ?></span>
                        <span class="ecp-muted"><?php echo esc_html(number_format_i18n($metrics['impressions'])); ?> <?php esc_html_e('impressions', 'enhanced-content-plugin'); ?></span>
                    </div>
                <?php elseif (!$best) : ?>
                    <span class="ecp-muted">—</span>
                <?php endif; ?>
            </td>

            <td class="ecp-col-actions">
                <?php if ($ready) : ?>
                    <button type="button" class="button button-small button-primary ecp-analyze"
                            data-post="<?php echo esc_attr($post_id); ?>">
                        <?php esc_html_e('Analyze', 'enhanced-content-plugin'); ?>
                    </button>
                    <button type="button" class="button button-small ecp-analyze-gaps"
                            data-post="<?php echo esc_attr($post_id); ?>"
                            title="<?php esc_attr_e('Work out what a reader of this title wants answered, and which of those things the article is missing.', 'enhanced-content-plugin'); ?>">
                        <?php esc_html_e('Find gaps', 'enhanced-content-plugin'); ?>
                    </button>
                <?php endif; ?>

                <?php if (ECP_Opportunity_Engine::STATUS_PROPOSED === $row['status']) : ?>
                    <a class="button button-small"
                       href="<?php echo esc_url(add_query_arg(array('page' => 'ecp-review', 'post' => $post_id), admin_url('admin.php'))); ?>">
                        <?php esc_html_e('Review', 'enhanced-content-plugin'); ?>
                    </a>
                <?php endif; ?>

                <?php if (ECP_Capabilities::can_review($post_id)) : ?>
                    <button type="button" class="button-link ecp-dismiss" data-post="<?php echo esc_attr($post_id); ?>">
                        <?php esc_html_e('Dismiss', 'enhanced-content-plugin'); ?>
                    </button>
                <?php endif; ?>

                <div class="ecp-row-status" aria-live="polite"></div>
            </td>
        </tr>
        <?php
    }

    private static function score_band($score) {
        if ($score >= 55) {
            return 'high';
        }

        if ($score >= 30) {
            return 'medium';
        }

        return 'low';
    }

    private static function render_pagination($total, $paged, $per_page) {
        $pages = (int) ceil($total / $per_page);

        if ($pages < 2) {
            return;
        }

        echo '<div class="tablenav bottom"><div class="tablenav-pages">';
        printf('<span class="displaying-num">%s</span>', esc_html(number_format_i18n($total)));

        echo paginate_links(array(  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core escapes.
            'base'    => add_query_arg('paged', '%#%'),
            'format'  => '',
            'total'   => $pages,
            'current' => $paged,
        ));

        echo '</div></div>';
    }
}
