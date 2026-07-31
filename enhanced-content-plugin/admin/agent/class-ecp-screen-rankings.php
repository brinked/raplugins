<?php
/**
 * Where you rank, per query, and which way it is going.
 *
 * Opens on page 2 by default, because that is the answer to the question
 * people actually come here with: what is nearly working. A page ranking
 * eleventh for a term with real volume is the best content investment on
 * most sites — the demand is proven, the relevance is proven, and the gap is
 * small enough that an edit can plausibly close it.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ECP_Screen_Rankings {

    public static function render() {
        if (!ECP_Capabilities::can_view()) {
            wp_die(esc_html__('You do not have permission to view this page.', 'enhanced-content-plugin'));
        }

        $band = isset($_GET['band']) ? sanitize_key(wp_unslash($_GET['band'])) : ECP_Rankings::BAND_PAGE_2;
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $post_id = isset($_GET['post']) ? absint($_GET['post']) : 0;
        $min = isset($_GET['min']) ? absint($_GET['min']) : 1;
        $paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        $per_page = 50;

        if (!array_key_exists($band, ECP_Rankings::bands()) && 'all' !== $band) {
            $band = ECP_Rankings::BAND_PAGE_2;
        }

        $author = ECP_Capabilities::author_scope();
        $window = ECP_Search_Data::valid_window(
            isset($_GET['window']) ? absint($_GET['window']) : ECP_Search_Data::DEFAULT_WINDOW
        );

        $result = ECP_Rankings::query(array(
            'band'            => 'all' === $band ? '' : $band,
            'search'          => $search,
            'post_id'         => $post_id,
            'min_impressions' => $min,
            'window'          => $window,
            'per_page'        => $per_page,
            'paged'           => $paged,
            'author'          => $author,
        ));

        $summary = ECP_Rankings::band_summary($author, $window);
        $snapshots = ECP_Rankings::snapshot_count($window);

        ?>
        <div class="wrap ecp-wrap">
            <h1><?php esc_html_e('Rankings', 'enhanced-content-plugin'); ?></h1>

            <?php ECP_Admin_Menu::header('ecp-rankings'); ?>

            <?php if (!ECP_Search_Data::is_connected()) : ?>
                <?php self::render_not_connected(); ?>

                <?php return; ?>
            <?php endif; ?>

            <p class="ecp-lede">
                <?php esc_html_e('Every search term your pages appear for, grouped by which results page they land on. Page 2 is where the opportunity usually is: the demand and the relevance are already proven, and the gap is small enough that an edit can realistically close it.', 'enhanced-content-plugin'); ?>
            </p>

            <?php self::render_window_switcher($window, $band); ?>

            <?php self::render_summary($summary, $band, $window); ?>

            <?php if ($result['latest']) : ?>
                <p class="ecp-muted ecp-rank-asof">
                    <?php
                    printf(
                        /* translators: 1: window label e.g. "Last 28 days", 2: date */
                        esc_html__('%1$s, ending %2$s. Every position below is an average across that period.', 'enhanced-content-plugin'),
                        esc_html(ECP_Search_Data::window_label($window)),
                        esc_html(mysql2date(get_option('date_format'), $result['latest']))
                    );

                    if ($result['compared_to']) {
                        echo ' ';
                        printf(
                            /* translators: %s: date being compared against */
                            esc_html__('Movement compares against the same window ending %s.', 'enhanced-content-plugin'),
                            esc_html(mysql2date(get_option('date_format'), $result['compared_to']))
                        );
                    } elseif ($snapshots < 2) {
                        echo ' ';
                        esc_html_e('Only one snapshot of this window so far, so there is nothing to compare against yet — movement appears once the next sync runs.', 'enhanced-content-plugin');
                    }
                    ?>
                </p>
            <?php endif; ?>

            <?php self::render_filters($band, $search, $min, $post_id, $window); ?>

            <?php if (!$result['items']) : ?>
                <?php self::render_empty($band, $result['latest']); ?>
            <?php else : ?>
                <table class="widefat striped ecp-rank-table">
                    <thead>
                        <tr>
                            <th class="ecp-col-pos"><?php esc_html_e('Position', 'enhanced-content-plugin'); ?></th>
                            <th><?php esc_html_e('Search term', 'enhanced-content-plugin'); ?></th>
                            <th><?php esc_html_e('Page', 'enhanced-content-plugin'); ?></th>
                            <th class="ecp-col-num"><?php esc_html_e('Impressions', 'enhanced-content-plugin'); ?></th>
                            <th class="ecp-col-num"><?php esc_html_e('Clicks', 'enhanced-content-plugin'); ?></th>
                            <th class="ecp-col-num">
                                <?php esc_html_e('Missed clicks', 'enhanced-content-plugin'); ?>
                                <span class="ecp-help" title="<?php esc_attr_e('Clicks this term is not getting, over the same period as the impressions shown, and which of the two fixes would earn them: a snippet that people actually click, or a higher ranking. Modelled from typical click rates by position — an estimate, not a promise.', 'enhanced-content-plugin'); ?>">?</span>
                            </th>
                            <th class="ecp-col-actions"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($result['items'] as $row) : ?>
                            <?php self::render_row($row, $window); ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php self::render_pagination($result['total'], $paged, $per_page); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /* --------------------------------------------------------------------
     * Pieces
     * ----------------------------------------------------------------- */

    /**
     * The reporting period.
     *
     * A position is always an average over some window, and 8.4 over seven
     * days means something quite different from 8.4 over three months. The
     * period therefore sits above everything else on the screen rather than
     * being buried in a filter row.
     */
    private static function render_window_switcher($current, $band) {
        $base = admin_url('admin.php?page=ecp-rankings');

        ?>
        <div class="ecp-window-switcher">
            <span class="ecp-window-label"><?php esc_html_e('Period', 'enhanced-content-plugin'); ?></span>

            <?php
            // Say which periods hold nothing rather than letting someone pick
            // one and read an empty screen as "no rankings".
            $counts = ECP_Search_Data::rows_per_window();

            foreach (ECP_Search_Data::windows() as $days => $label) :
                $has_rows = !empty($counts[$days]);
                ?>
                <a href="<?php echo esc_url(add_query_arg(array('window' => $days, 'band' => $band), $base)); ?>"
                   class="ecp-window-option<?php echo (int) $days === (int) $current ? ' is-active' : ''; ?><?php echo $has_rows ? '' : ' is-empty'; ?>"
                   <?php if (!$has_rows) : ?>title="<?php esc_attr_e('No data stored for this period yet', 'enhanced-content-plugin'); ?>"<?php endif; ?>>
                    <?php echo esc_html($label); ?>
                    <?php if (!$has_rows) : ?><span class="ecp-window-flag">&mdash;</span><?php endif; ?>
                </a>
            <?php endforeach; ?>

            <span class="ecp-window-note">
                <?php esc_html_e('Search Console data lags about two days, and there is no useful 24-hour view through this connection.', 'enhanced-content-plugin'); ?>
            </span>
        </div>
        <?php
    }

    private static function render_summary(array $summary, $current, $window) {
        $base = add_query_arg('window', (int) $window, admin_url('admin.php?page=ecp-rankings'));
        $total = array_sum($summary);

        // Previous snapshot's band counts, so each card can show its own
        // change rather than only today's number.
        $history = ECP_Rankings::band_history($window, 2);
        $previous = isset($history[1]) ? $history[1] : null;

        echo '<div class="ecp-stat-grid ecp-rank-summary">';

        foreach (ECP_Rankings::bands() as $band => $label) {
            $count = isset($summary[$band]) ? (int) $summary[$band] : 0;
            $delta = null;

            if ($previous && isset($previous[$band])) {
                $delta = $count - (int) $previous[$band];
            }

            printf(
                '<a class="ecp-stat ecp-rank-stat ecp-rank-%1$s%2$s" href="%3$s">'
                . '<span class="ecp-stat-number">%4$s</span>'
                . '<span class="ecp-stat-label">%5$s</span>'
                . '<span class="ecp-stat-sub">%6$s</span>%7$s</a>',
                esc_attr($band),
                $band === $current ? ' is-active' : '',
                esc_url(add_query_arg('band', $band, $base)),
                esc_html(number_format_i18n($count)),
                esc_html($label),
                esc_html(sprintf(
                    /* translators: %s: percentage of all ranking terms */
                    __('%s of your terms', 'enhanced-content-plugin'),
                    $total ? round(($count / $total) * 100) . '%' : '0%'
                )),
                null === $delta || 0 === $delta
                    ? ''
                    : sprintf(
                        '<span class="ecp-stat-delta %s">%s</span>',
                        // Deliberately not coloured good/bad. A smaller page-2
                        // count can mean terms moved up or moved down, and the
                        // card cannot tell which — the movement panel can.
                        'is-neutral',
                        esc_html(sprintf('%+d', $delta))
                    )
            );
        }

        echo '</div>';

        self::render_movement_panel($window);
        self::render_dimensions($window);
    }

    /**
     * Site-wide device and country splits for the selected period.
     *
     * The device row earns its place by exposing one specific failure mode:
     * a page that ranks the same everywhere but converts impressions to
     * clicks far worse on mobile — which no blended CTR will ever show.
     */
    private static function render_dimensions($window) {
        $summary = ECP_Search_Data::dimension_summary($window);

        if (!$summary || (empty($summary['devices']) && empty($summary['countries']))) {
            return;   // Older sync, or the enrichment calls failed. Say nothing.
        }

        $device_labels = array(
            'desktop' => __('Desktop', 'enhanced-content-plugin'),
            'mobile'  => __('Mobile', 'enhanced-content-plugin'),
            'tablet'  => __('Tablet', 'enhanced-content-plugin'),
        );
        ?>
        <div class="ecp-dimensions">
            <?php if (!empty($summary['devices'])) : ?>
                <div class="ecp-panel ecp-dimension-panel">
                    <h2><?php esc_html_e('By device', 'enhanced-content-plugin'); ?><?php ECP_Admin_Menu::help(__('The same search performance split by device over the selected window.', 'enhanced-content-plugin')); ?></h2>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Device', 'enhanced-content-plugin'); ?></th>
                                <th><?php esc_html_e('Clicks', 'enhanced-content-plugin'); ?></th>
                                <th><?php esc_html_e('Impressions', 'enhanced-content-plugin'); ?></th>
                                <th><?php esc_html_e('CTR', 'enhanced-content-plugin'); ?></th>
                                <th><?php esc_html_e('Avg. position', 'enhanced-content-plugin'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($summary['devices'] as $row) : ?>
                                <tr>
                                    <td><strong><?php echo esc_html(isset($device_labels[$row['key']]) ? $device_labels[$row['key']] : ucfirst($row['key'])); ?></strong></td>
                                    <td><?php echo esc_html(number_format_i18n((int) $row['clicks'])); ?></td>
                                    <td><?php echo esc_html(number_format_i18n((int) $row['impressions'])); ?></td>
                                    <td><?php echo esc_html(number_format_i18n((float) $row['ctr'] * 100, 1)); ?>%</td>
                                    <td><?php echo esc_html(number_format_i18n((float) $row['position'], 1)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if (!empty($summary['countries'])) : ?>
                <div class="ecp-panel ecp-dimension-panel">
                    <h2><?php esc_html_e('Top countries', 'enhanced-content-plugin'); ?><?php ECP_Admin_Menu::help(__('Where your impressions come from.', 'enhanced-content-plugin')); ?></h2>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Country', 'enhanced-content-plugin'); ?></th>
                                <th><?php esc_html_e('Clicks', 'enhanced-content-plugin'); ?></th>
                                <th><?php esc_html_e('Impressions', 'enhanced-content-plugin'); ?></th>
                                <th><?php esc_html_e('CTR', 'enhanced-content-plugin'); ?></th>
                                <th><?php esc_html_e('Avg. position', 'enhanced-content-plugin'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($summary['countries'], 0, 10) as $row) : ?>
                                <tr>
                                    <td><strong><?php echo esc_html(strtoupper($row['key'])); ?></strong></td>
                                    <td><?php echo esc_html(number_format_i18n((int) $row['clicks'])); ?></td>
                                    <td><?php echo esc_html(number_format_i18n((int) $row['impressions'])); ?></td>
                                    <td><?php echo esc_html(number_format_i18n((float) $row['ctr'] * 100, 1)); ?>%</td>
                                    <td><?php echo esc_html(number_format_i18n((float) $row['position'], 1)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Direction of travel since the previous snapshot.
     *
     * The band cards answer "where am I". This answers "am I getting better",
     * which is a different question and not derivable from the counts: terms
     * leaving page 2 look identical whether they were promoted or demoted.
     *
     * (render_movement, without the suffix, is the per-row arrow further
     * down. Declaring this under that name took the whole site down with a
     * cannot-redeclare fatal — the duplicate-name check in the build sweep
     * exists because of this.)
     */
    private static function render_movement_panel($window) {
        $move = ECP_Rankings::movement_summary($window);

        if (!$move) {
            $count = ECP_Rankings::snapshot_count($window);
            ?>
            <div class="ecp-panel ecp-movement is-empty">
                <p class="ecp-muted">
                    <?php
                    if ($count < 2) {
                        esc_html_e('Progress tracking starts once there are two snapshots to compare. Each sync stores one, so this fills in from the next sync onward — nothing needs turning on.', 'enhanced-content-plugin');
                    } else {
                        esc_html_e('No terms appear in both of the last two snapshots, so there is nothing to compare yet.', 'enhanced-content-plugin');
                    }
                    ?>
                </p>
            </div>
            <?php
            return;
        }

        $net = (int) $move['improved'] - (int) $move['declined'];
        ?>
        <div class="ecp-panel ecp-movement">
            <h2>
                <?php esc_html_e('Since the previous snapshot', 'enhanced-content-plugin'); ?>
                <span class="ecp-muted">
                    <?php
                    printf(
                        /* translators: 1: earlier date, 2: later date */
                        esc_html__('%1$s → %2$s', 'enhanced-content-plugin'),
                        esc_html($move['from']),
                        esc_html($move['to'])
                    );
                    ?>
                </span>
            </h2>

            <p class="ecp-movement-headline">
                <?php
                if ($net > 0) {
                    printf(
                        /* translators: %d: number of terms */
                        esc_html__('Net gain: %d more terms moved up than down.', 'enhanced-content-plugin'),
                        (int) $net
                    );
                } elseif ($net < 0) {
                    printf(
                        /* translators: %d: number of terms */
                        esc_html__('Net loss: %d more terms moved down than up.', 'enhanced-content-plugin'),
                        (int) abs($net)
                    );
                } else {
                    esc_html_e('Ups and downs balanced out.', 'enhanced-content-plugin');
                }
                ?>
            </p>

            <div class="ecp-movement-grid">
                <?php
                $cells = array(
                    array(
                        'label' => __('Moved up', 'enhanced-content-plugin'),
                        'value' => (int) $move['improved'],
                        'tone'  => 'is-good',
                    ),
                    array(
                        'label' => __('Moved down', 'enhanced-content-plugin'),
                        'value' => (int) $move['declined'],
                        'tone'  => 'is-bad',
                    ),
                    array(
                        'label' => __('Held steady', 'enhanced-content-plugin'),
                        'value' => (int) $move['unchanged'],
                        'tone'  => '',
                    ),
                    array(
                        'label' => __('Onto page 1', 'enhanced-content-plugin'),
                        'value' => (int) $move['entered_page1'],
                        'tone'  => 'is-good',
                    ),
                    array(
                        'label' => __('Off page 1', 'enhanced-content-plugin'),
                        'value' => (int) $move['left_page1'],
                        'tone'  => 'is-bad',
                    ),
                    array(
                        'label' => __('New terms', 'enhanced-content-plugin'),
                        'value' => (int) $move['appeared'],
                        'tone'  => '',
                    ),
                    array(
                        'label' => __('No longer reported', 'enhanced-content-plugin'),
                        'value' => (int) $move['gone'],
                        'tone'  => '',
                    ),
                );

                foreach ($cells as $cell) :
                    ?>
                    <div class="ecp-movement-cell <?php echo esc_attr($cell['tone']); ?>">
                        <span class="ecp-movement-number"><?php echo esc_html(number_format_i18n($cell['value'])); ?></span>
                        <span class="ecp-movement-label"><?php echo esc_html($cell['label']); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>

            <p class="ecp-muted">
                <?php
                printf(
                    /* translators: 1: average change in places, 2: number of terms compared */
                    esc_html__('Average change %1$s places across %2$s terms present in both snapshots. Movement under half a place is treated as noise, because a position is itself an average over the period.', 'enhanced-content-plugin'),
                    esc_html(sprintf('%+.2f', (float) $move['avg_change'])),
                    esc_html(number_format_i18n((int) $move['compared']))
                );
                ?>
            </p>

            <p class="ecp-muted">
                <?php esc_html_e('New and no-longer-reported terms are counted separately on purpose. Search Console stops reporting terms below a volume threshold, so a term disappearing is not the same as a ranking drop.', 'enhanced-content-plugin'); ?>
            </p>
        </div>
        <?php
    }

    private static function render_filters($band, $search, $min, $post_id, $window) {
        $base = add_query_arg('window', (int) $window, admin_url('admin.php?page=ecp-rankings'));
        ?>
        <div class="ecp-filter-bar">
            <div class="ecp-risk-filters">
                <a href="<?php echo esc_url(add_query_arg('band', 'all', $base)); ?>"
                   class="ecp-pill<?php echo 'all' === $band ? ' is-active' : ''; ?>">
                    <?php esc_html_e('Every term', 'enhanced-content-plugin'); ?>
                </a>
                <?php foreach (ECP_Rankings::bands() as $slug => $label) : ?>
                    <a href="<?php echo esc_url(add_query_arg('band', $slug, $base)); ?>"
                       class="ecp-pill<?php echo $slug === $band ? ' is-active' : ''; ?>">
                        <?php echo esc_html($label); ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <form method="get" class="ecp-type-filter">
                <input type="hidden" name="page" value="ecp-rankings">
                <input type="hidden" name="band" value="<?php echo esc_attr($band); ?>">
                <input type="hidden" name="window" value="<?php echo esc_attr($window); ?>">

                <label for="ecp-rank-min" class="screen-reader-text"><?php esc_html_e('Minimum impressions', 'enhanced-content-plugin'); ?></label>
                <select name="min" id="ecp-rank-min">
                    <?php
                    foreach (array(1, 10, 50, 100, 500) as $threshold) {
                        printf(
                            '<option value="%d"%s>%s</option>',
                            (int) $threshold,
                            selected($min, $threshold, false),
                            esc_html(sprintf(
                                /* translators: %s: impression count */
                                __('%s+ impressions', 'enhanced-content-plugin'),
                                number_format_i18n($threshold)
                            ))
                        );
                    }
                    ?>
                </select>

                <label for="ecp-rank-search" class="screen-reader-text"><?php esc_html_e('Search', 'enhanced-content-plugin'); ?></label>
                <input type="search" id="ecp-rank-search" name="s" value="<?php echo esc_attr($search); ?>"
                       placeholder="<?php esc_attr_e('Term or page title', 'enhanced-content-plugin'); ?>">

                <?php submit_button(__('Filter', 'enhanced-content-plugin'), 'secondary', '', false); ?>
            </form>
        </div>

        <?php if ($post_id) : ?>
            <div class="notice notice-info inline">
                <p>
                    <?php
                    printf(
                        /* translators: %s: post title */
                        esc_html__('Showing terms for "%s" only.', 'enhanced-content-plugin'),
                        esc_html(get_the_title($post_id))
                    );
                    ?>
                    <a href="<?php echo esc_url(add_query_arg('band', $band, admin_url('admin.php?page=ecp-rankings'))); ?>">
                        <?php esc_html_e('Show every page', 'enhanced-content-plugin'); ?>
                    </a>
                </p>
            </div>
        <?php endif; ?>
        <?php
    }

    private static function render_row(array $row, $window = ECP_Search_Data::DEFAULT_WINDOW) {
        $history = ECP_Rankings::history($row['post_id'], $row['query'], 90, $window);
        $can_analyze = ECP_Agent_Settings::is_ready() && ECP_Capabilities::can_analyze($row['post_id']);

        ?>
        <tr>
            <td class="ecp-col-pos">
                <span class="ecp-position ecp-rank-<?php echo esc_attr($row['band']); ?>">
                    <?php echo esc_html(number_format_i18n($row['position'], 1)); ?>
                </span>
                <?php self::render_movement($row['movement']); ?>
            </td>

            <td>
                <strong class="ecp-rank-query"><?php echo esc_html($row['query']); ?></strong>
                <div class="ecp-row-meta">
                    <a href="<?php echo esc_url(get_edit_post_link($row['post_id'])); ?>">
                        <?php echo esc_html($row['post_title']); ?>
                    </a>
                </div>
            </td>

            <td>
                <span class="ecp-band-badge ecp-rank-<?php echo esc_attr($row['band']); ?>">
                    <?php echo esc_html(ECP_Rankings::band_label($row['band'])); ?>
                </span>
                <?php
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sparkline() escapes its own attributes.
                echo ECP_Rankings::sparkline($history);
                ?>
            </td>

            <td class="ecp-col-num"><?php echo esc_html(number_format_i18n($row['impressions'])); ?></td>

            <td class="ecp-col-num">
                <?php echo esc_html(number_format_i18n($row['clicks'])); ?>
                <div class="ecp-row-meta"><?php echo esc_html(number_format_i18n($row['ctr'] * 100, 1)); ?>%</div>
            </td>

            <td class="ecp-col-num">
                <?php $opportunity = $row['opportunity']; ?>

                <?php if ('none' !== $opportunity['lever']) : ?>
                    <strong class="ecp-upside"
                            title="<?php echo esc_attr(ECP_Rankings::lever_explanation($opportunity, $row['position'], $row['ctr'])); ?>">
                        +<?php echo esc_html(number_format_i18n($opportunity['total'], 0)); ?>
                    </strong>
                    <div class="ecp-lever ecp-lever-<?php echo esc_attr($opportunity['lever']); ?>">
                        <?php echo esc_html(ECP_Rankings::lever_label($opportunity['lever'])); ?>
                    </div>
                <?php else : ?>
                    <span class="ecp-muted">—</span>
                <?php endif; ?>
            </td>

            <td class="ecp-col-actions">
                <?php if ($can_analyze) : ?>
                    <button type="button" class="button button-small ecp-analyze" data-post="<?php echo esc_attr($row['post_id']); ?>">
                        <?php esc_html_e('Analyze', 'enhanced-content-plugin'); ?>
                    </button>
                <?php endif; ?>
                <div class="ecp-row-status" aria-live="polite"></div>
            </td>
        </tr>
        <?php
    }

    /**
     * Movement is expressed as places gained, so positive is always good —
     * the opposite of the raw position number it derives from.
     */
    private static function render_movement($movement) {
        if (null === $movement) {
            echo '<span class="ecp-move ecp-move-new" title="'
                . esc_attr__('No earlier snapshot to compare against.', 'enhanced-content-plugin')
                . '">' . esc_html__('new', 'enhanced-content-plugin') . '</span>';

            return;
        }

        if (abs($movement) < 0.5) {
            echo '<span class="ecp-move ecp-move-flat" title="'
                . esc_attr__('No meaningful change.', 'enhanced-content-plugin')
                . '">&mdash;</span>';

            return;
        }

        $up = $movement > 0;

        printf(
            '<span class="ecp-move %1$s" title="%2$s">%3$s%4$s</span>',
            $up ? 'ecp-move-up' : 'ecp-move-down',
            esc_attr(
                $up
                    ? sprintf(
                        /* translators: %s: number of places */
                        __('Up %s places since the earlier snapshot.', 'enhanced-content-plugin'),
                        number_format_i18n(abs($movement), 1)
                    )
                    : sprintf(
                        /* translators: %s: number of places */
                        __('Down %s places since the earlier snapshot.', 'enhanced-content-plugin'),
                        number_format_i18n(abs($movement), 1)
                    )
            ),
            $up ? '▲' : '▼',
            esc_html(number_format_i18n(abs($movement), 1))
        );
    }

    private static function render_not_connected() {
        ?>
        <div class="ecp-empty">
            <h2><?php esc_html_e('No search data yet', 'enhanced-content-plugin'); ?></h2>
            <p>
                <?php esc_html_e('Rankings come from Google Search Console. Connect it and this page fills in — positions, which results page each term lands on, and which way each one is moving.', 'enhanced-content-plugin'); ?>
            </p>
            <a class="button button-primary button-hero" href="<?php echo esc_url(admin_url('admin.php?page=ecp-agent-settings&tab=search')); ?>">
                <?php esc_html_e('Connect Search Console', 'enhanced-content-plugin'); ?>
            </a>
        </div>
        <?php
    }

    private static function render_empty($band, $latest) {
        ?>
        <div class="ecp-empty">
            <?php
            if (!$latest) :
                // "Nothing synced yet" is only true if nothing is stored for
                // any period. When other periods do have rows, saying that
                // sends you off to re-sync data you already have, and hides
                // the fact that the problem is confined to one period.
                $elsewhere = array_filter(ECP_Search_Data::rows_per_window());
                ?>
                <?php if ($elsewhere) : ?>
                    <h2><?php esc_html_e('No data for this period', 'enhanced-content-plugin'); ?></h2>
                    <p>
                        <?php esc_html_e('Other periods do have data, so the connection is working — this one specifically came back empty.', 'enhanced-content-plugin'); ?>
                    </p>
                    <p>
                        <?php foreach ($elsewhere as $days => $count) : ?>
                            <?php if (ECP_Search_Data::valid_window($days) === (int) $days) : ?>
                                <a class="button" href="<?php echo esc_url(add_query_arg(array('window' => (int) $days, 'band' => $band), admin_url('admin.php?page=ecp-rankings'))); ?>">
                                    <?php
                                    printf(
                                        /* translators: 1: period label, 2: row count */
                                        esc_html__('%1$s (%2$s rows)', 'enhanced-content-plugin'),
                                        esc_html(ECP_Search_Data::window_label($days)),
                                        esc_html(number_format_i18n($count))
                                    );
                                    ?>
                                </a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </p>
                    <p class="ecp-muted">
                        <?php esc_html_e('If none of those buttons appear, the stored data is tagged with a period this screen cannot read. Settings → Search Console shows exactly what is stored and has a repair button.', 'enhanced-content-plugin'); ?>
                    </p>
                    <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=ecp-agent-settings&tab=search')); ?>">
                        <?php esc_html_e('Check stored data', 'enhanced-content-plugin'); ?>
                    </a>
                <?php else : ?>
                    <h2><?php esc_html_e('Nothing synced yet', 'enhanced-content-plugin'); ?></h2>
                    <p><?php esc_html_e('Search Console is connected, but no data has been pulled down yet.', 'enhanced-content-plugin'); ?></p>
                    <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=ecp-agent-settings&tab=search')); ?>">
                        <?php esc_html_e('Sync now', 'enhanced-content-plugin'); ?>
                    </a>
                <?php endif; ?>
            <?php else : ?>
                <h2><?php esc_html_e('Nothing in this band', 'enhanced-content-plugin'); ?></h2>
                <p>
                    <?php
                    printf(
                        /* translators: %s: band label */
                        esc_html__('No search terms are currently on %s at the impression threshold you have set.', 'enhanced-content-plugin'),
                        esc_html(ECP_Rankings::band_label($band))
                    );
                    ?>
                </p>
                <a class="button" href="<?php echo esc_url(add_query_arg(array('band' => 'all', 'min' => 1), admin_url('admin.php?page=ecp-rankings'))); ?>">
                    <?php esc_html_e('Show every term', 'enhanced-content-plugin'); ?>
                </a>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function render_pagination($total, $paged, $per_page) {
        $pages = (int) ceil($total / $per_page);

        if ($pages < 2) {
            return;
        }

        echo '<div class="tablenav bottom"><div class="tablenav-pages">';

        printf(
            '<span class="displaying-num">%s</span>',
            esc_html(sprintf(
                /* translators: %s: number of search terms */
                _n('%s term', '%s terms', $total, 'enhanced-content-plugin'),
                number_format_i18n($total)
            ))
        );

        echo paginate_links(array(  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core escapes.
            'base'    => add_query_arg('paged', '%#%'),
            'format'  => '',
            'total'   => $pages,
            'current' => $paged,
        ));

        echo '</div></div>';
    }
}
