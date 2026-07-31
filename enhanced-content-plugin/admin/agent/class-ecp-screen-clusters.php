<?php
/**
 * Pages competing for the same topic.
 *
 * Presented as evidence first, verdict second. The measured Search Console
 * rows are shown before anything the model concluded, because the whole point
 * of this screen is that the site owner can check the reasoning against real
 * numbers rather than taking a recommendation on faith.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ECP_Screen_Clusters {

    public static function render() {
        if (!ECP_Capabilities::can_view()) {
            wp_die(esc_html__('You do not have permission to view this page.', 'enhanced-content-plugin'));
        }

        $status = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : ECP_Clusters::STATUS_OPEN;
        $paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        $per_page = 10;

        $result = ECP_Clusters::query(array(
            'status' => $status,
            'limit'  => $per_page,
            'offset' => ($paged - 1) * $per_page,
            'author' => ECP_Capabilities::author_scope(),
        ));

        $stats = ECP_Clusters::stats();

        ?>
        <div class="wrap ecp-wrap">
            <h1><?php esc_html_e('Competing Pages', 'enhanced-content-plugin'); ?><?php ECP_Admin_Menu::help(__('Groups of your own pages competing for the same topic in search. Decide which page owns the topic before improving members individually - otherwise the improvements pull in opposite directions.', 'enhanced-content-plugin')); ?></h1>

            <?php ECP_Admin_Menu::header('ecp-clusters'); ?>

            <p class="ecp-lede">
                <?php esc_html_e('When two of your pages target the same search, your site splits its own relevance and internal links between them and neither ranks as well as one strong page would. This finds those pairs from real Search Console data, decides which page should own the topic, and proposes retargeting the others.', 'enhanced-content-plugin'); ?>
            </p>

            <?php if (!ECP_Search_Data::is_connected()) : ?>
                <div class="notice notice-warning inline">
                    <p>
                        <strong><?php esc_html_e('Search Console is not connected.', 'enhanced-content-plugin'); ?></strong>
                        <?php esc_html_e('Without query data this falls back to comparing titles, which finds far fewer real conflicts and more false ones. Anything found that way is labelled.', 'enhanced-content-plugin'); ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=ecp-agent-settings&tab=search')); ?>">
                            <?php esc_html_e('Connect it', 'enhanced-content-plugin'); ?>
                        </a>
                    </p>
                </div>
            <?php endif; ?>

            <?php if (0 === $stats['total']) : ?>
                <div class="ecp-empty">
                    <h2><?php esc_html_e('Nothing checked yet', 'enhanced-content-plugin'); ?></h2>
                    <p><?php esc_html_e('Detection is free — it reads your stored Search Console rows and compares titles. No AI calls.', 'enhanced-content-plugin'); ?></p>
                    <?php if (ECP_Capabilities::can_review()) : ?>
                        <button type="button" class="button button-primary button-hero" id="ecp-detect-clusters">
                            <?php esc_html_e('Look for competing pages', 'enhanced-content-plugin'); ?>
                        </button>
                        <p class="ecp-cluster-progress" aria-live="polite"></p>
                    <?php endif; ?>
                </div>
            <?php else : ?>

                <div class="ecp-filter-bar">
                    <div class="ecp-risk-filters">
                        <?php
                        $tabs = array(
                            ECP_Clusters::STATUS_OPEN      => __('Open', 'enhanced-content-plugin'),
                            ECP_Clusters::STATUS_PROPOSED  => __('Changes proposed', 'enhanced-content-plugin'),
                            ECP_Clusters::STATUS_RESOLVED  => __('Resolved', 'enhanced-content-plugin'),
                            ECP_Clusters::STATUS_DISMISSED => __('Dismissed', 'enhanced-content-plugin'),
                        );

                        foreach ($tabs as $slug => $label) {
                            printf(
                                '<a href="%s" class="ecp-pill%s">%s</a>',
                                esc_url(add_query_arg(array('page' => 'ecp-clusters', 'status' => $slug), admin_url('admin.php'))),
                                $slug === $status ? ' is-active' : '',
                                esc_html($label)
                            );
                        }
                        ?>
                    </div>

                    <?php if (ECP_Capabilities::can_review()) : ?>
                        <div>
                            <button type="button" class="button" id="ecp-detect-clusters">
                                <?php esc_html_e('Check again', 'enhanced-content-plugin'); ?>
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
                <p class="ecp-cluster-progress" aria-live="polite"></p>

                <?php if (!$result['items']) : ?>
                    <p><?php esc_html_e('Nothing in this state.', 'enhanced-content-plugin'); ?></p>
                <?php else : ?>
                    <div class="ecp-cards">
                        <?php foreach ($result['items'] as $cluster) : ?>
                            <?php self::render_cluster($cluster); ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /* --------------------------------------------------------------------
     * One cluster
     * ----------------------------------------------------------------- */

    private static function render_cluster(array $cluster) {
        $evidence = is_array($cluster['evidence']) ? $cluster['evidence'] : array();
        $recommendation = is_array($cluster['recommendation']) ? $cluster['recommendation'] : array();
        $analyzed = !empty($recommendation['members']);
        $weak = 'title_similarity' === (isset($evidence['source']) ? $evidence['source'] : '');

        // Verdicts keyed by post, so the member table can show them inline.
        $verdicts = array();
        foreach ((array) (isset($recommendation['members']) ? $recommendation['members'] : array()) as $member) {
            $verdicts[(int) $member['post_id']] = $member;
        }

        $primary_id = isset($recommendation['primary_post_id'])
            ? (int) $recommendation['primary_post_id']
            : (int) $cluster['primary_post_id'];

        ?>
        <article class="ecp-card ecp-cluster-card" data-cluster="<?php echo esc_attr($cluster['id']); ?>">

            <header class="ecp-card-head">
                <div class="ecp-card-headings">
                    <h2 class="ecp-card-title">
                        <?php
                        printf(
                            /* translators: %d: number of pages */
                            esc_html(_n('%d page competing on', '%d pages competing on', (int) $cluster['member_count'], 'enhanced-content-plugin')),
                            (int) $cluster['member_count']
                        );
                        ?>
                        &ldquo;<?php echo esc_html($cluster['label']); ?>&rdquo;
                    </h2>
                    <p class="ecp-card-meta">
                        <?php echo esc_html(ECP_Clusters::type_label($cluster['type'])); ?>
                        <?php if (!empty($evidence['query_count'])) : ?>
                            <span class="ecp-sep">·</span>
                            <?php
                            printf(
                                /* translators: %d: number of shared queries */
                                esc_html(_n('%d shared query', '%d shared queries', (int) $evidence['query_count'], 'enhanced-content-plugin')),
                                (int) $evidence['query_count']
                            );
                            ?>
                        <?php endif; ?>
                        <?php if (!empty($evidence['total_impressions'])) : ?>
                            <span class="ecp-sep">·</span>
                            <?php
                            printf(
                                /* translators: %s: impression count */
                                esc_html__('%s impressions at stake', 'enhanced-content-plugin'),
                                esc_html(number_format_i18n((int) $evidence['total_impressions']))
                            );
                            ?>
                        <?php endif; ?>
                    </p>
                </div>

                <div class="ecp-card-badges">
                    <span class="ecp-score ecp-score-<?php echo esc_attr(self::band((float) $cluster['score'])); ?>">
                        <?php echo esc_html(number_format_i18n((float) $cluster['score'], 0)); ?>
                    </span>
                </div>
            </header>

            <?php if ($weak) : ?>
                <div class="ecp-warning ecp-warning-note">
                    <?php echo esc_html(isset($evidence['note']) ? $evidence['note'] : ''); ?>
                </div>
            <?php endif; ?>

            <?php if ($analyzed && !empty($recommendation['summary'])) : ?>
                <div class="ecp-why">
                    <strong><?php esc_html_e('Verdict:', 'enhanced-content-plugin'); ?></strong>
                    <?php echo esc_html($recommendation['summary']); ?>
                </div>
            <?php endif; ?>

            <?php self::render_members($cluster, $verdicts, $primary_id, $analyzed); ?>

            <?php if (!empty($evidence['queries'])) : ?>
                <details class="ecp-evidence">
                    <summary><?php esc_html_e('The measured evidence', 'enhanced-content-plugin'); ?></summary>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Query', 'enhanced-content-plugin'); ?></th>
                                <th><?php esc_html_e('Page', 'enhanced-content-plugin'); ?></th>
                                <th><?php esc_html_e('Position', 'enhanced-content-plugin'); ?></th>
                                <th><?php esc_html_e('Impressions', 'enhanced-content-plugin'); ?></th>
                                <th><?php esc_html_e('Clicks', 'enhanced-content-plugin'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ((array) $evidence['queries'] as $query) : ?>
                                <?php $rowspan = count((array) $query['pages']); ?>
                                <?php foreach ((array) $query['pages'] as $index => $page) : ?>
                                    <tr>
                                        <?php if (0 === $index) : ?>
                                            <td rowspan="<?php echo esc_attr($rowspan); ?>">
                                                <strong><?php echo esc_html($query['query']); ?></strong>
                                            </td>
                                        <?php endif; ?>
                                        <td><?php echo esc_html(get_the_title((int) $page['post_id'])); ?></td>
                                        <td><?php echo esc_html(number_format_i18n((float) $page['position'], 1)); ?></td>
                                        <td><?php echo esc_html(number_format_i18n((int) $page['impressions'])); ?></td>
                                        <td><?php echo esc_html(number_format_i18n((int) $page['clicks'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </details>
            <?php endif; ?>

            <?php if (!empty($recommendation['merge_checklist'])) : ?>
                <div class="ecp-merge-advice">
                    <h3><?php esc_html_e('One page should be merged — this part needs you', 'enhanced-content-plugin'); ?></h3>
                    <p>
                        <?php esc_html_e('Merging means deleting a URL and redirecting it. This plugin will not do that automatically: get a redirect wrong and you lose the rankings you were trying to consolidate. Here is the order to do it in.', 'enhanced-content-plugin'); ?>
                    </p>
                    <ol>
                        <?php foreach ((array) $recommendation['merge_checklist'] as $step) : ?>
                            <li><?php echo esc_html($step); ?></li>
                        <?php endforeach; ?>
                    </ol>
                </div>
            <?php endif; ?>

            <footer class="ecp-card-foot">
                <div class="ecp-card-actions">
                    <?php if (ECP_Clusters::STATUS_OPEN === $cluster['status'] && ECP_Capabilities::can_review()) : ?>
                        <?php if (ECP_Agent_Settings::is_ready()) : ?>
                            <button type="button" class="button button-primary ecp-analyze-cluster"
                                    data-cluster="<?php echo esc_attr($cluster['id']); ?>">
                                <?php
                                echo $analyzed
                                    ? esc_html__('Analyze again', 'enhanced-content-plugin')
                                    : esc_html__('Work out what to do', 'enhanced-content-plugin');
                                ?>
                            </button>
                        <?php endif; ?>
                        <button type="button" class="button ecp-dismiss-cluster"
                                data-cluster="<?php echo esc_attr($cluster['id']); ?>">
                            <?php esc_html_e('Not a problem', 'enhanced-content-plugin'); ?>
                        </button>
                    <?php elseif (ECP_Clusters::STATUS_PROPOSED === $cluster['status']) : ?>
                        <a class="button button-primary"
                           href="<?php echo esc_url(add_query_arg(array('page' => 'ecp-review', 'cluster' => (int) $cluster['id']), admin_url('admin.php'))); ?>">
                            <?php esc_html_e('Review the changes', 'enhanced-content-plugin'); ?>
                        </a>
                        <?php if (ECP_Capabilities::can_review()) : ?>
                            <button type="button" class="button ecp-resolve-cluster"
                                    data-cluster="<?php echo esc_attr($cluster['id']); ?>">
                                <?php esc_html_e('Mark resolved', 'enhanced-content-plugin'); ?>
                            </button>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <div class="ecp-card-detail">
                    <?php if ($cluster['analyzed_at']) : ?>
                        <span class="ecp-detail-item">
                            <?php
                            printf(
                                /* translators: %s: human-readable time difference */
                                esc_html__('Analyzed %s ago', 'enhanced-content-plugin'),
                                esc_html(human_time_diff(strtotime($cluster['analyzed_at']), (int) current_time('timestamp')))
                            );
                            ?>
                        </span>
                    <?php endif; ?>
                </div>
            </footer>

            <div class="ecp-card-status" aria-live="polite"></div>
        </article>
        <?php
    }

    private static function render_members(array $cluster, array $verdicts, $primary_id, $analyzed) {
        ?>
        <table class="widefat striped ecp-cluster-members">
            <thead>
                <tr>
                    <th><?php esc_html_e('Page', 'enhanced-content-plugin'); ?></th>
                    <th style="width:120px;"><?php esc_html_e('Performance', 'enhanced-content-plugin'); ?></th>
                    <th style="width:80px;"><?php esc_html_e('Words', 'enhanced-content-plugin'); ?></th>
                    <th style="width:90px;"><?php esc_html_e('Links in', 'enhanced-content-plugin'); ?></th>
                    <?php if ($analyzed) : ?>
                        <th style="width:220px;"><?php esc_html_e('What to do', 'enhanced-content-plugin'); ?></th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ((array) $cluster['member_ids'] as $post_id) : ?>
                    <?php
                    $post_id = (int) $post_id;
                    $post = get_post($post_id);

                    if (!$post) {
                        continue;
                    }

                    $metrics = ECP_Search_Data::page_metrics($post_id);
                    $signals = ECP_Signals::collect($post);
                    $is_primary = $post_id === (int) $primary_id;
                    $verdict = isset($verdicts[$post_id]) ? $verdicts[$post_id] : null;
                    ?>
                    <tr class="<?php echo $is_primary ? 'ecp-member-primary' : ''; ?>">
                        <td>
                            <strong>
                                <a href="<?php echo esc_url(get_edit_post_link($post_id)); ?>">
                                    <?php echo esc_html(get_the_title($post_id)); ?>
                                </a>
                            </strong>
                            <?php if ($is_primary) : ?>
                                <span class="ecp-chip ecp-chip-safe"><?php esc_html_e('should own this topic', 'enhanced-content-plugin'); ?></span>
                            <?php endif; ?>
                            <div class="ecp-row-meta"><?php echo esc_html(wp_make_link_relative(get_permalink($post_id))); ?></div>
                        </td>
                        <td>
                            <?php if ($metrics) : ?>
                                <div class="ecp-metric-stack">
                                    <span><?php echo esc_html(number_format_i18n($metrics['clicks'])); ?> <?php esc_html_e('clicks', 'enhanced-content-plugin'); ?></span>
                                    <span class="ecp-muted"><?php esc_html_e('pos.', 'enhanced-content-plugin'); ?> <?php echo esc_html(number_format_i18n($metrics['position'], 1)); ?></span>
                                </div>
                            <?php else : ?>
                                <span class="ecp-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html(number_format_i18n((int) $signals['word_count'])); ?></td>
                        <td><?php echo esc_html(number_format_i18n((int) $signals['inbound_internal_links'])); ?></td>
                        <?php if ($analyzed) : ?>
                            <td>
                                <?php if ($verdict) : ?>
                                    <strong><?php echo esc_html(ECP_Clusters::verdict_label($verdict['verdict'])); ?></strong>
                                    <?php if (!empty($verdict['angle'])) : ?>
                                        <div class="ecp-row-meta">
                                            <?php
                                            printf(
                                                /* translators: %s: the distinct angle */
                                                esc_html__('New angle: %s', 'enhanced-content-plugin'),
                                                esc_html($verdict['angle'])
                                            );
                                            ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($verdict['rationale'])) : ?>
                                        <div class="ecp-row-meta"><?php echo esc_html($verdict['rationale']); ?></div>
                                    <?php endif; ?>
                                <?php else : ?>
                                    <span class="ecp-muted">—</span>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private static function band($score) {
        if ($score >= 60) {
            return 'high';
        }

        if ($score >= 35) {
            return 'medium';
        }

        return 'low';
    }
}
