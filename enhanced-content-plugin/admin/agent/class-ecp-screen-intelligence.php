<?php
/**
 * Site Intelligence — what this website is, measured.
 *
 * Read-only by design (Phase 1 adds no content-modification paths): topic
 * coverage, intent and funnel mix, health rollups that link to the screens
 * which act on them, and the full inventory with inline topic correction.
 *
 * The screen shows partial state honestly. A site mid-classification says
 * "214 of 500 classified" over real data, never a blank page — the lesson
 * this plugin keeps re-learning is that hidden partial state reads as
 * broken.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ECP_Screen_Intelligence {

    public static function render() {
        if (!ECP_Capabilities::can_view()) {
            wp_die(esc_html__('You do not have permission to view this page.', 'enhanced-content-plugin'));
        }

        $stats = ECP_Inventory::stats();

        $args = array(
            'topic'        => isset($_GET['topic']) ? sanitize_text_field(wp_unslash($_GET['topic'])) : '',
            'intent'       => isset($_GET['intent']) ? sanitize_key(wp_unslash($_GET['intent'])) : '',
            'unclassified' => !empty($_GET['unclassified']),
            'search'       => isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '',
            'paged'        => isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1,
            'per_page'     => 50,
        );

        $list = ECP_Inventory::query($args);

        ?>
        <div class="wrap ecp-wrap">
            <h1><?php esc_html_e('Site Intelligence', 'enhanced-content-plugin'); ?></h1>

            <?php ECP_Admin_Menu::header('ecp-intelligence'); ?>

            <?php if (0 === $stats['total']) : ?>
                <div class="ecp-empty">
                    <h2><?php esc_html_e('The inventory is still being built', 'enhanced-content-plugin'); ?></h2>
                    <p><?php esc_html_e('The hourly scan fills it automatically, a batch at a time. On the command line, "wp ecp inventory" builds it in one pass.', 'enhanced-content-plugin'); ?></p>
                </div>
            <?php else : ?>

                <?php self::render_progress($stats); ?>

                <div class="ecp-columns">
                    <div class="ecp-col-main">
                        <?php self::render_topics(); ?>
                        <?php self::render_inventory($list, $args, $stats); ?>
                    </div>
                    <div class="ecp-col-side">
                        <?php self::render_mix(); ?>
                        <?php self::render_health(); ?>
                    </div>
                </div>

            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Classification progress, with the button that moves it along.
     */
    private static function render_progress(array $stats) {
        $published = max(1, $stats['published']);
        $pct = (int) round(($stats['classified'] / $published) * 100);
        $remaining = max(0, $stats['published'] - $stats['classified']);
        ?>
        <div class="ecp-panel ecp-intel-progress">
            <p>
                <strong>
                    <?php
                    printf(
                        /* translators: 1: classified count, 2: published count, 3: topic count */
                        esc_html__('%1$s of %2$s published pages classified, across %3$s topics.', 'enhanced-content-plugin'),
                        esc_html(number_format_i18n($stats['classified'])),
                        esc_html(number_format_i18n($stats['published'])),
                        esc_html(number_format_i18n($stats['topics']))
                    );

                    if ($stats['stale'] > 0) {
                        echo ' ';
                        printf(
                            /* translators: %s: count */
                            esc_html__('%s changed since classification and will be refreshed.', 'enhanced-content-plugin'),
                            esc_html(number_format_i18n($stats['stale']))
                        );
                    }
                    ?>
                </strong>
            </p>

            <div class="ecp-meter"><div class="ecp-meter-fill" style="width:<?php echo esc_attr($pct); ?>%"></div></div>

            <?php if ($remaining > 0 && ECP_Capabilities::can_manage()) : ?>
                <p>
                    <button type="button" class="button" id="ecp-classify-now">
                        <?php esc_html_e('Classify a batch now', 'enhanced-content-plugin'); ?>
                    </button>
                    <span id="ecp-classify-result" aria-live="polite"></span>
                </p>
                <p class="description">
                    <?php esc_html_e('Otherwise this proceeds automatically, one batch an hour, within the daily limit in Settings.', 'enhanced-content-plugin'); ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Topic coverage: pages per topic with their combined search reach.
     */
    private static function render_topics() {
        $topics = ECP_Inventory::topics(30);

        if (!$topics) {
            return;
        }

        $base = admin_url('admin.php?page=ecp-intelligence');
        ?>
        <div class="ecp-panel">
            <h2><?php esc_html_e('Topic coverage', 'enhanced-content-plugin'); ?></h2>
            <p class="description">
                <?php esc_html_e('What this site actually covers, and which subjects earn its traffic. One page on a topic is a mention; several are a claim to authority.', 'enhanced-content-plugin'); ?>
            </p>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Topic', 'enhanced-content-plugin'); ?></th>
                        <th><?php esc_html_e('Pages', 'enhanced-content-plugin'); ?></th>
                        <th><?php esc_html_e('Clicks (28d)', 'enhanced-content-plugin'); ?></th>
                        <th><?php esc_html_e('Impressions (28d)', 'enhanced-content-plugin'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($topics as $topic) : ?>
                        <tr>
                            <td>
                                <a href="<?php echo esc_url(add_query_arg('topic', rawurlencode($topic['topic']), $base)); ?>">
                                    <strong><?php echo esc_html($topic['topic']); ?></strong>
                                </a>
                            </td>
                            <td><?php echo esc_html(number_format_i18n($topic['pages'])); ?></td>
                            <td><?php echo esc_html(number_format_i18n($topic['clicks'])); ?></td>
                            <td><?php echo esc_html(number_format_i18n($topic['impressions'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Intent and funnel mix — the shape of the site at a glance.
     */
    private static function render_mix() {
        $intent = ECP_Inventory::mix('intent');
        $funnel = ECP_Inventory::mix('funnel_stage');

        if (!$intent && !$funnel) {
            return;
        }

        $labels = array(
            'informational' => __('Informational', 'enhanced-content-plugin'),
            'commercial'    => __('Commercial research', 'enhanced-content-plugin'),
            'transactional' => __('Transactional', 'enhanced-content-plugin'),
            'navigational'  => __('Navigational', 'enhanced-content-plugin'),
            'awareness'     => __('Awareness', 'enhanced-content-plugin'),
            'consideration' => __('Consideration', 'enhanced-content-plugin'),
            'decision'      => __('Decision', 'enhanced-content-plugin'),
        );

        $render = function ($title, $mix) use ($labels) {
            if (!$mix) {
                return;
            }

            $total = max(1, array_sum($mix));

            echo '<h3>' . esc_html($title) . '</h3>';

            foreach ($mix as $key => $count) {
                $label = isset($labels[$key]) ? $labels[$key] : ucfirst($key);
                $pct = (int) round(($count / $total) * 100);

                printf(
                    '<div class="ecp-mix-row"><span class="ecp-mix-label">%s</span><div class="ecp-meter"><div class="ecp-meter-fill" style="width:%d%%"></div></div><span class="ecp-mix-count">%s</span></div>',
                    esc_html($label),
                    esc_attr($pct),
                    esc_html(number_format_i18n($count))
                );
            }
        };
        ?>
        <div class="ecp-panel">
            <h2><?php esc_html_e('Content mix', 'enhanced-content-plugin'); ?></h2>
            <?php
            $render(__('By search intent', 'enhanced-content-plugin'), $intent);
            $render(__('By funnel stage', 'enhanced-content-plugin'), $funnel);
            ?>
            <p class="description">
                <?php esc_html_e('A site that is all awareness content teaches readers who then buy elsewhere. A site that is all decision pages has nothing to earn the reader\'s trust first.', 'enhanced-content-plugin'); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Health rollups. Every line links to the existing screen that acts on
     * it — this screen diagnoses, the others treat.
     */
    private static function render_health() {
        $opportunities = ECP_Opportunity_Engine::stats();
        $search = ECP_Search_Data::status();
        $inventory = ECP_Inventory::stats();

        $covered = ECP_Search_Data::covered_post_count();
        ?>
        <div class="ecp-panel">
            <h2><?php esc_html_e('Health', 'enhanced-content-plugin'); ?></h2>
            <ul class="ecp-health-list">
                <li>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=ecp-opportunities')); ?>">
                        <?php
                        printf(
                            /* translators: %s: count */
                            esc_html__('%s pages with open opportunities', 'enhanced-content-plugin'),
                            esc_html(number_format_i18n((int) $opportunities['open']))
                        );
                        ?>
                    </a>
                </li>
                <li>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=ecp-clusters')); ?>">
                        <?php esc_html_e('Competing pages report', 'enhanced-content-plugin'); ?>
                    </a>
                </li>
                <li>
                    <?php if ($search['connected']) : ?>
                        <?php
                        printf(
                            /* translators: 1: pages with data, 2: published pages */
                            esc_html__('%1$s of %2$s published pages have search data', 'enhanced-content-plugin'),
                            esc_html(number_format_i18n($covered)),
                            esc_html(number_format_i18n($inventory['published']))
                        );
                        ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=ecp-agent-settings&tab=search')); ?>" class="ecp-muted">
                            <?php esc_html_e('(diagnostics)', 'enhanced-content-plugin'); ?>
                        </a>
                    <?php else : ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=ecp-agent-settings&tab=search')); ?>">
                            <?php esc_html_e('Search Console is not connected', 'enhanced-content-plugin'); ?>
                        </a>
                    <?php endif; ?>
                </li>
                <li>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=ecp-agent-settings&tab=profile')); ?>">
                        <?php
                        printf(
                            /* translators: %d: percentage */
                            esc_html__('Site profile %d%% complete', 'enhanced-content-plugin'),
                            (int) ECP_Site_Profile::completeness()
                        );
                        ?>
                    </a>
                </li>
            </ul>
        </div>
        <?php
    }

    /**
     * The inventory itself, filterable, with inline topic correction.
     */
    private static function render_inventory(array $list, array $args, array $stats) {
        $base = admin_url('admin.php?page=ecp-intelligence');
        ?>
        <div class="ecp-panel">
            <h2><?php esc_html_e('Every page', 'enhanced-content-plugin'); ?></h2>

            <form method="get" class="ecp-intel-filters">
                <input type="hidden" name="page" value="ecp-intelligence">
                <input type="search" name="s" value="<?php echo esc_attr($args['search']); ?>" placeholder="<?php esc_attr_e('Search titles…', 'enhanced-content-plugin'); ?>">
                <select name="intent">
                    <option value=""><?php esc_html_e('Any intent', 'enhanced-content-plugin'); ?></option>
                    <?php foreach (ECP_Classifier::INTENTS as $intent) : ?>
                        <option value="<?php echo esc_attr($intent); ?>" <?php selected($args['intent'], $intent); ?>><?php echo esc_html(ucfirst($intent)); ?></option>
                    <?php endforeach; ?>
                </select>
                <label>
                    <input type="checkbox" name="unclassified" value="1" <?php checked($args['unclassified']); ?>>
                    <?php esc_html_e('Unclassified only', 'enhanced-content-plugin'); ?>
                </label>
                <?php if ('' !== $args['topic']) : ?>
                    <input type="hidden" name="topic" value="<?php echo esc_attr($args['topic']); ?>">
                    <a href="<?php echo esc_url($base); ?>" class="button">
                        <?php
                        printf(
                            /* translators: %s: topic name */
                            esc_html__('Clear topic: %s', 'enhanced-content-plugin'),
                            esc_html($args['topic'])
                        );
                        ?>
                    </a>
                <?php endif; ?>
                <button type="submit" class="button"><?php esc_html_e('Filter', 'enhanced-content-plugin'); ?></button>
            </form>

            <?php if (!$list['items']) : ?>
                <p class="ecp-muted"><?php esc_html_e('Nothing matches these filters.', 'enhanced-content-plugin'); ?></p>
            <?php else : ?>
                <table class="widefat striped ecp-intel-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Page', 'enhanced-content-plugin'); ?></th>
                            <th><?php esc_html_e('Topic', 'enhanced-content-plugin'); ?></th>
                            <th><?php esc_html_e('Intent', 'enhanced-content-plugin'); ?></th>
                            <th><?php esc_html_e('Stage', 'enhanced-content-plugin'); ?></th>
                            <th><?php esc_html_e('Words', 'enhanced-content-plugin'); ?></th>
                            <th><?php esc_html_e('Links in/out', 'enhanced-content-plugin'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($list['items'] as $row) : ?>
                            <tr>
                                <td>
                                    <a href="<?php echo esc_url(get_edit_post_link((int) $row['post_id'])); ?>">
                                        <strong><?php echo esc_html($row['title']); ?></strong>
                                    </a>
                                </td>
                                <td>
                                    <span class="ecp-intel-topic"
                                          data-post="<?php echo esc_attr($row['post_id']); ?>"
                                          title="<?php echo $row['locked'] ? esc_attr__('Set by you — the classifier never changes it', 'enhanced-content-plugin') : esc_attr__('Click to correct', 'enhanced-content-plugin'); ?>">
                                        <?php echo esc_html($row['topic'] ? $row['topic'] : '—'); ?><?php echo $row['locked'] ? ' 🔒' : ''; ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html($row['intent'] ? ucfirst($row['intent']) : '—'); ?></td>
                                <td><?php echo esc_html($row['funnel_stage'] ? ucfirst($row['funnel_stage']) : '—'); ?></td>
                                <td><?php echo esc_html(number_format_i18n((int) $row['word_count'])); ?></td>
                                <td><?php echo esc_html((int) $row['internal_links_in'] . ' / ' . (int) $row['internal_links_out']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php
                $pages = (int) ceil($list['total'] / $args['per_page']);

                if ($pages > 1) {
                    echo '<div class="tablenav bottom"><div class="tablenav-pages">';
                    echo paginate_links(array(  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core-generated pagination markup.
                        'base'    => add_query_arg('paged', '%#%'),
                        'total'   => $pages,
                        'current' => $args['paged'],
                    ));
                    echo '</div></div>';
                }
                ?>
            <?php endif; ?>
        </div>
        <?php

        unset($stats);
    }
}
