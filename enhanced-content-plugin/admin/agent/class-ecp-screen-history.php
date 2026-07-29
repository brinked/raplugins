<?php
/**
 * The audit trail.
 *
 * This is the screen you point at when someone asks "what did this plugin do
 * to my site". Every applied change is listed with who approved it, when, and
 * a one-click rollback.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ECP_Screen_History {

    public static function render() {
        if (!ECP_Capabilities::can_view()) {
            wp_die(esc_html__('You do not have permission to view this page.', 'enhanced-content-plugin'));
        }

        $view = isset($_GET['view']) ? sanitize_key(wp_unslash($_GET['view'])) : 'changes';
        $paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;

        // The raw log and the spend report are site-wide by nature and cannot
        // be meaningfully filtered to one author's posts, so they are hidden
        // from reviewers scoped to their own content rather than shown with
        // other people's page titles in them.
        $site_wide = !ECP_Capabilities::author_scope();

        if (!$site_wide && in_array($view, array('log', 'runs'), true)) {
            $view = 'changes';
        }

        ?>
        <div class="wrap ecp-wrap">
            <h1><?php esc_html_e('History', 'enhanced-content-plugin'); ?></h1>

            <?php ECP_Admin_Menu::header('ecp-history'); ?>

            <ul class="subsubsub">
                <li>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=ecp-history&view=changes')); ?>"
                       class="<?php echo 'changes' === $view ? 'current' : ''; ?>">
                        <?php esc_html_e('Applied changes', 'enhanced-content-plugin'); ?>
                    </a><?php echo $site_wide ? ' |' : ''; ?>
                </li>
                <?php if ($site_wide) : ?>
                    <li>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=ecp-history&view=log')); ?>"
                           class="<?php echo 'log' === $view ? 'current' : ''; ?>">
                            <?php esc_html_e('Full activity log', 'enhanced-content-plugin'); ?>
                        </a> |
                    </li>
                    <li>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=ecp-history&view=runs')); ?>"
                           class="<?php echo 'runs' === $view ? 'current' : ''; ?>">
                            <?php esc_html_e('AI usage', 'enhanced-content-plugin'); ?>
                        </a>
                    </li>
                <?php endif; ?>
            </ul>

            <?php
            if ('log' === $view) {
                self::render_log($paged);
            } elseif ('runs' === $view) {
                self::render_runs();
            } else {
                self::render_changes($paged);
            }
            ?>
        </div>
        <?php
    }

    /* --------------------------------------------------------------------
     * Views
     * ----------------------------------------------------------------- */

    private static function render_changes($paged) {
        $result = ECP_Proposals::query(array(
            'status'   => array(ECP_Proposals::APPLIED, ECP_Proposals::REVERTED),
            'orderby'  => 'created',
            'order'    => 'DESC',
            'paged'    => $paged,
            'per_page' => 25,
            'author'   => ECP_Capabilities::author_scope(),
        ));

        if (!$result['items']) {
            echo '<p>' . esc_html__('Nothing has been applied yet.', 'enhanced-content-plugin') . '</p>';

            return;
        }

        ?>
        <p class="ecp-lede">
            <?php esc_html_e('Everything the agent has written to your site. A WordPress revision was created before each one, so you can undo any of them here or restore from the post editor.', 'enhanced-content-plugin'); ?>
        </p>

        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Change', 'enhanced-content-plugin'); ?></th>
                    <th><?php esc_html_e('Page', 'enhanced-content-plugin'); ?></th>
                    <th><?php esc_html_e('Approved by', 'enhanced-content-plugin'); ?></th>
                    <th><?php esc_html_e('When', 'enhanced-content-plugin'); ?></th>
                    <th><?php esc_html_e('Since then', 'enhanced-content-plugin'); ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($result['items'] as $proposal) : ?>
                    <?php
                    $reverted = ECP_Proposals::REVERTED === $proposal['status'];
                    $performance = $reverted ? null : ECP_Applier::performance((int) $proposal['id']);
                    $user = (int) $proposal['reviewed_by'] ? get_userdata((int) $proposal['reviewed_by']) : null;
                    ?>
                    <tr<?php echo $reverted ? ' class="ecp-row-reverted"' : ''; ?>>
                        <td>
                            <strong><?php echo esc_html($proposal['title']); ?></strong>
                            <div class="ecp-row-meta">
                                <?php echo esc_html(ECP_Proposals::type_label($proposal['change_type'])); ?>
                                <span class="ecp-sep">·</span>
                                <?php echo esc_html(ECP_Diff::summary($proposal['before_value'], $proposal['after_value'])); ?>
                                <?php if ($proposal['auto_applied']) : ?>
                                    <span class="ecp-sep">·</span>
                                    <span class="ecp-chip"><?php esc_html_e('auto-applied', 'enhanced-content-plugin'); ?></span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <a href="<?php echo esc_url(get_edit_post_link((int) $proposal['post_id'])); ?>">
                                <?php echo esc_html($proposal['post_title']); ?>
                            </a>
                        </td>
                        <td>
                            <?php
                            if ($proposal['auto_applied']) {
                                esc_html_e('the agent', 'enhanced-content-plugin');
                            } elseif ($user) {
                                echo esc_html($user->display_name);
                            } else {
                                echo '&mdash;';
                            }
                            ?>
                        </td>
                        <td>
                            <?php
                            echo $proposal['applied_at']
                                ? esc_html(sprintf(
                                    /* translators: %s: human-readable time difference */
                                    __('%s ago', 'enhanced-content-plugin'),
                                    human_time_diff(strtotime($proposal['applied_at']), (int) current_time('timestamp'))
                                ))
                                : '&mdash;';
                            ?>
                        </td>
                        <td>
                            <?php if ($reverted) : ?>
                                <span class="ecp-muted"><?php esc_html_e('rolled back', 'enhanced-content-plugin'); ?></span>
                            <?php elseif ($performance) : ?>
                                <span class="ecp-perf ecp-perf-<?php echo esc_attr($performance['verdict']); ?>">
                                    <?php echo esc_html(ECP_Applier::verdict_label($performance['verdict'])); ?>
                                </span>
                                <div class="ecp-row-meta">
                                    <?php
                                    printf(
                                        '%s%d %s · %s%.1f %s',
                                        $performance['clicks_delta'] >= 0 ? '+' : '',
                                        (int) $performance['clicks_delta'],
                                        esc_html__('clicks', 'enhanced-content-plugin'),
                                        $performance['position_delta'] >= 0 ? '+' : '',
                                        (float) $performance['position_delta'],
                                        esc_html__('positions', 'enhanced-content-plugin')
                                    );
                                    ?>
                                </div>
                            <?php else : ?>
                                <span class="ecp-muted">
                                    <?php
                                    echo ECP_Search_Data::is_connected()
                                        ? esc_html__('too early', 'enhanced-content-plugin')
                                        : esc_html__('no search data', 'enhanced-content-plugin');
                                    ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="ecp-cell-action">
                            <?php if (!$reverted && ECP_Capabilities::can_review((int) $proposal['post_id'])) : ?>
                                <button type="button" class="button button-small ecp-revert"
                                        data-id="<?php echo esc_attr($proposal['id']); ?>">
                                    <?php esc_html_e('Undo', 'enhanced-content-plugin'); ?>
                                </button>
                            <?php endif; ?>
                            <div class="ecp-row-status" aria-live="polite"></div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php

        self::pagination($result['total'], $paged, 25);
    }

    private static function render_log($paged) {
        $level = isset($_GET['level']) ? sanitize_key(wp_unslash($_GET['level'])) : '';

        $result = ECP_Log::query(array(
            'level'    => $level,
            'paged'    => $paged,
            'per_page' => 50,
        ));

        ?>
        <p class="ecp-lede">
            <?php esc_html_e('Every action the agent took, including the ones it decided against.', 'enhanced-content-plugin'); ?>
        </p>

        <div class="ecp-filter-bar">
            <div class="ecp-risk-filters">
                <?php
                $levels = array(
                    ''        => __('Everything', 'enhanced-content-plugin'),
                    'info'    => __('Normal', 'enhanced-content-plugin'),
                    'warning' => __('Warnings', 'enhanced-content-plugin'),
                    'error'   => __('Errors', 'enhanced-content-plugin'),
                );

                foreach ($levels as $slug => $label) {
                    printf(
                        '<a href="%s" class="ecp-pill%s">%s</a>',
                        esc_url(add_query_arg(
                            array('page' => 'ecp-history', 'view' => 'log', 'level' => $slug),
                            admin_url('admin.php')
                        )),
                        $slug === $level ? ' is-active' : '',
                        esc_html($label)
                    );
                }
                ?>
            </div>
        </div>

        <?php if (!$result['items']) : ?>
            <p><?php esc_html_e('Nothing logged.', 'enhanced-content-plugin'); ?></p>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th style="width:150px;"><?php esc_html_e('When', 'enhanced-content-plugin'); ?></th>
                        <th style="width:180px;"><?php esc_html_e('Event', 'enhanced-content-plugin'); ?></th>
                        <th><?php esc_html_e('What happened', 'enhanced-content-plugin'); ?></th>
                        <th style="width:140px;"><?php esc_html_e('Who', 'enhanced-content-plugin'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($result['items'] as $event) : ?>
                        <tr class="ecp-log-<?php echo esc_attr($event['level']); ?>">
                            <td><?php echo esc_html(mysql2date('j M Y, H:i', $event['created_at'])); ?></td>
                            <td><?php echo esc_html(ECP_Log::label($event['event'])); ?></td>
                            <td>
                                <?php echo esc_html($event['message']); ?>
                                <?php if ((int) $event['post_id']) : ?>
                                    <a href="<?php echo esc_url(get_edit_post_link((int) $event['post_id'])); ?>" class="ecp-muted">
                                        <?php esc_html_e('(open page)', 'enhanced-content-plugin'); ?>
                                    </a>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                if ((int) $event['user_id']) {
                                    $user = get_userdata((int) $event['user_id']);
                                    echo $user ? esc_html($user->display_name) : esc_html__('unknown', 'enhanced-content-plugin');
                                } else {
                                    esc_html_e('the agent', 'enhanced-content-plugin');
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php self::pagination($result['total'], $paged, 50); ?>
        <?php endif; ?>
        <?php
    }

    private static function render_runs() {
        $runs = ECP_AI_Client::recent_runs(50);
        $budget = ECP_AI_Client::budget_status();

        ?>
        <p class="ecp-lede">
            <?php esc_html_e('Every call made to the AI provider, and what it cost.', 'enhanced-content-plugin'); ?>
            <?php if ($budget['priced']) : ?>
                <strong>
                    <?php
                    printf(
                        /* translators: 1: amount spent, 2: cap */
                        esc_html__('This month: $%1$.2f of $%2$.2f.', 'enhanced-content-plugin'),
                        (float) $budget['monthly_spent'],
                        (float) $budget['monthly_cap']
                    );
                    ?>
                </strong>
            <?php endif; ?>
        </p>

        <?php if (!$runs) : ?>
            <p><?php esc_html_e('No AI calls yet.', 'enhanced-content-plugin'); ?></p>

            <?php return; ?>
        <?php endif; ?>

        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('When', 'enhanced-content-plugin'); ?></th>
                    <th><?php esc_html_e('Job', 'enhanced-content-plugin'); ?></th>
                    <th><?php esc_html_e('Page', 'enhanced-content-plugin'); ?></th>
                    <th><?php esc_html_e('Model', 'enhanced-content-plugin'); ?></th>
                    <th><?php esc_html_e('Tokens', 'enhanced-content-plugin'); ?></th>
                    <th><?php esc_html_e('Cost', 'enhanced-content-plugin'); ?></th>
                    <th><?php esc_html_e('Result', 'enhanced-content-plugin'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($runs as $run) : ?>
                    <tr>
                        <td><?php echo esc_html(mysql2date('j M, H:i', $run['created_at'])); ?></td>
                        <td><?php echo esc_html($run['job_type']); ?></td>
                        <td>
                            <?php if ((int) $run['post_id']) : ?>
                                <a href="<?php echo esc_url(get_edit_post_link((int) $run['post_id'])); ?>">
                                    <?php echo esc_html(get_the_title((int) $run['post_id'])); ?>
                                </a>
                            <?php else : ?>
                                &mdash;
                            <?php endif; ?>
                        </td>
                        <td class="ecp-muted"><?php echo esc_html($run['model']); ?></td>
                        <td>
                            <?php
                            echo esc_html(number_format_i18n((int) $run['input_tokens'] + (int) $run['output_tokens']));
                            ?>
                        </td>
                        <td>
                            <?php
                            $cost = (int) $run['cost_micros'] / 1000000;
                            echo $cost > 0 ? esc_html('$' . number_format($cost, 4)) : '&mdash;';
                            ?>
                        </td>
                        <td>
                            <?php if ('failed' === $run['status']) : ?>
                                <span class="ecp-perf ecp-perf-declining" title="<?php echo esc_attr($run['message']); ?>">
                                    <?php esc_html_e('failed', 'enhanced-content-plugin'); ?>
                                </span>
                            <?php else : ?>
                                <?php
                                printf(
                                    /* translators: %d: number of changes proposed */
                                    esc_html(_n('%d change', '%d changes', (int) $run['proposals_created'], 'enhanced-content-plugin')),
                                    (int) $run['proposals_created']
                                );
                                ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private static function pagination($total, $paged, $per_page) {
        $pages = (int) ceil($total / $per_page);

        if ($pages < 2) {
            return;
        }

        echo '<div class="tablenav bottom"><div class="tablenav-pages">';
        echo paginate_links(array(  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core escapes.
            'base'    => add_query_arg('paged', '%#%'),
            'format'  => '',
            'total'   => $pages,
            'current' => $paged,
        ));
        echo '</div></div>';
    }
}
