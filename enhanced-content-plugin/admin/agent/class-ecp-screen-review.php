<?php
/**
 * The approval inbox.
 *
 * Design intent: a reviewer should be able to clear twenty changes in three
 * minutes without ever wondering what they just agreed to. That means every
 * card shows, without a click — the diff, why the agent thinks it helps, and
 * anything it could not verify. Keyboard shortcuts (J/K to move, A to apply,
 * R to reject, E to edit) are what make it fast; the buttons are what make it
 * discoverable.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ECP_Screen_Review {

    public static function render() {
        if (!ECP_Capabilities::can_view()) {
            wp_die(esc_html__('You do not have permission to review content changes.', 'enhanced-content-plugin'));
        }

        $status = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : ECP_Proposals::PENDING;
        $risk = isset($_GET['risk']) ? sanitize_key(wp_unslash($_GET['risk'])) : '';
        $type = isset($_GET['type']) ? sanitize_key(wp_unslash($_GET['type'])) : '';
        $post_id = isset($_GET['post']) ? absint($_GET['post']) : 0;
        $cluster_id = isset($_GET['cluster']) ? absint($_GET['cluster']) : 0;
        $paged = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        $per_page = 20;

        $result = ECP_Proposals::query(array(
            'status'      => $status,
            'risk'        => $risk,
            'change_type' => $type,
            'post_id'     => $post_id,
            'cluster_id'  => $cluster_id,
            'author'      => ECP_Capabilities::author_scope(),
            'paged'       => $paged,
            'per_page'    => $per_page,
        ));

        $counts = ECP_Proposals::counts();
        $can_review = ECP_Capabilities::can_review();

        ?>
        <div class="wrap ecp-wrap">
            <h1><?php esc_html_e('Review Changes', 'enhanced-content-plugin'); ?><?php ECP_Admin_Menu::help(__('Every change the agent prepares waits here as a before/after diff. Approve applies it (a revision is kept, so History can undo it in one click). Edit lets you adjust the text first. Reject teaches the agent - rejections feed Site Memory and shape future proposals.', 'enhanced-content-plugin')); ?></h1>

            <?php ECP_Admin_Menu::header('ecp-review'); ?>

            <p class="ecp-lede">
                <?php
                echo $can_review
                    ? esc_html__('Nothing here has touched your site yet. Read each change, then apply it, edit it first, or reject it. Anything you apply can be undone from History.', 'enhanced-content-plugin')
                    : esc_html__('Nothing here has touched your site. You can read what the agent proposes and why, but applying a change needs an editor.', 'enhanced-content-plugin');
                ?>
            </p>

            <?php self::render_cluster_context($cluster_id); ?>

            <?php self::render_filters($status, $risk, $type, $post_id, $counts); ?>

            <?php if (!$result['items']) : ?>
                <?php self::render_empty($status); ?>
            <?php else : ?>

                <?php if (ECP_Proposals::PENDING === $status && $can_review) : ?>
                    <?php self::render_bulk_bar($result, $risk, $type, $post_id); ?>
                <?php endif; ?>

                <div class="ecp-cards" id="ecp-cards">
                    <?php foreach ($result['items'] as $proposal) : ?>
                        <?php self::render_card($proposal); ?>
                    <?php endforeach; ?>
                </div>

                <?php self::render_pagination($result['total'], $paged, $per_page); ?>

                <p class="ecp-shortcut-hint" <?php echo $can_review ? '' : 'hidden'; ?>>
                    <?php
                    printf(
                        /* translators: keyboard shortcut list */
                        esc_html__('Shortcuts: %1$s move between changes · %2$s apply · %3$s reject · %4$s edit before applying', 'enhanced-content-plugin'),
                        '<kbd>J</kbd>/<kbd>K</kbd>',
                        '<kbd>A</kbd>',
                        '<kbd>R</kbd>',
                        '<kbd>E</kbd>'
                    );
                    ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /* --------------------------------------------------------------------
     * Pieces
     * ----------------------------------------------------------------- */

    private static function render_filters($status, $risk, $type, $post_id, array $counts) {
        $base = admin_url('admin.php?page=ecp-review');

        $statuses = array(
            ECP_Proposals::PENDING  => __('Waiting', 'enhanced-content-plugin'),
            ECP_Proposals::APPLIED  => __('Applied', 'enhanced-content-plugin'),
            ECP_Proposals::REJECTED => __('Rejected', 'enhanced-content-plugin'),
            ECP_Proposals::REVERTED => __('Rolled back', 'enhanced-content-plugin'),
            ECP_Proposals::FAILED   => __('Failed', 'enhanced-content-plugin'),
        );

        echo '<ul class="subsubsub">';
        $links = array();

        foreach ($statuses as $slug => $label) {
            $count = isset($counts[$slug]) ? (int) $counts[$slug] : 0;

            if (0 === $count && $slug !== $status && ECP_Proposals::PENDING !== $slug) {
                continue;
            }

            $links[] = sprintf(
                '<li><a href="%s"%s>%s <span class="count">(%s)</span></a></li>',
                esc_url(add_query_arg('status', $slug, $base)),
                $slug === $status ? ' class="current"' : '',
                esc_html($label),
                esc_html(number_format_i18n($count))
            );
        }

        echo implode(' | ', $links); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each part escaped above
        echo '</ul>';

        echo '<div class="ecp-filter-bar">';

        // Risk pills — the main triage axis.
        if (ECP_Proposals::PENDING === $status) {
            $by_risk = isset($counts['pending_by_risk']) ? $counts['pending_by_risk'] : array();

            echo '<div class="ecp-risk-filters">';
            printf(
                '<a href="%s" class="ecp-pill%s">%s</a>',
                esc_url(remove_query_arg('risk', add_query_arg('status', $status, $base))),
                '' === $risk ? ' is-active' : '',
                esc_html__('All', 'enhanced-content-plugin')
            );

            foreach (array(ECP_Proposals::RISK_SAFE, ECP_Proposals::RISK_MODERATE, ECP_Proposals::RISK_SENSITIVE) as $tier) {
                $count = isset($by_risk[$tier]) ? (int) $by_risk[$tier] : 0;

                if (!$count && $tier !== $risk) {
                    continue;
                }

                printf(
                    '<a href="%s" class="ecp-pill ecp-pill-%s%s">%s <span>%s</span></a>',
                    esc_url(add_query_arg(array('status' => $status, 'risk' => $tier), $base)),
                    esc_attr($tier),
                    $tier === $risk ? ' is-active' : '',
                    esc_html(ECP_Proposals::risk_label($tier)),
                    esc_html(number_format_i18n($count))
                );
            }
            echo '</div>';
        }

        // Type filter.
        echo '<form method="get" class="ecp-type-filter">';
        echo '<input type="hidden" name="page" value="ecp-review">';
        echo '<input type="hidden" name="status" value="' . esc_attr($status) . '">';
        if ($risk) {
            echo '<input type="hidden" name="risk" value="' . esc_attr($risk) . '">';
        }

        echo '<label class="screen-reader-text" for="ecp-type">' . esc_html__('Filter by change type', 'enhanced-content-plugin') . '</label>';
        echo '<select name="type" id="ecp-type">';
        echo '<option value="">' . esc_html__('Every kind of change', 'enhanced-content-plugin') . '</option>';

        foreach (ECP_Proposals::change_types() as $slug => $info) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($slug),
                selected($type, $slug, false),
                esc_html($info['label'])
            );
        }

        echo '</select> ';
        submit_button(__('Filter', 'enhanced-content-plugin'), 'secondary', '', false);
        echo '</form>';

        echo '</div>';

        if ($post_id) {
            printf(
                '<div class="notice notice-info inline"><p>%s <a href="%s">%s</a></p></div>',
                esc_html(sprintf(
                    /* translators: %s: post title */
                    __('Showing changes for "%s" only.', 'enhanced-content-plugin'),
                    get_the_title($post_id)
                )),
                esc_url(remove_query_arg('post', add_query_arg('status', $status, $base))),
                esc_html__('Show everything', 'enhanced-content-plugin')
            );
        }
    }

    private static function render_bulk_bar($result, $risk, $type, $post_id) {
        // Bulk-approving is only offered for the safe tier. Approving a
        // batch of "check the facts" changes without reading them defeats the
        // point of the tier existing.
        $safe_here = 0;
        foreach ($result['items'] as $item) {
            if (ECP_Proposals::RISK_SAFE === $item['risk']) {
                $safe_here++;
            }
        }

        ?>
        <div class="ecp-bulk-bar">
            <div class="ecp-bulk-left">
                <label>
                    <input type="checkbox" id="ecp-select-all">
                    <?php esc_html_e('Select all on this page', 'enhanced-content-plugin'); ?>
                </label>
                <span class="ecp-selected-count" aria-live="polite"></span>
            </div>
            <div class="ecp-bulk-right">
                <button type="button" class="button" id="ecp-bulk-reject" disabled>
                    <?php esc_html_e('Reject selected', 'enhanced-content-plugin'); ?>
                </button>
                <button type="button" class="button button-primary" id="ecp-bulk-approve" disabled>
                    <?php esc_html_e('Apply selected', 'enhanced-content-plugin'); ?>
                </button>
                <?php if ($safe_here > 1) : ?>
                    <button type="button" class="button" id="ecp-approve-safe"
                            data-count="<?php echo esc_attr($safe_here); ?>">
                        <?php
                        printf(
                            /* translators: %d: number of safe changes */
                            esc_html__('Apply all %d safe changes', 'enhanced-content-plugin'),
                            (int) $safe_here
                        );
                        ?>
                    </button>
                <?php endif; ?>
            </div>
        </div>
        <?php

        unset($risk, $type, $post_id);
    }

    /**
     * Context banner when the queue is filtered to one cluster.
     */
    private static function render_cluster_context($cluster_id) {
        if (!$cluster_id) {
            return;
        }

        $cluster = ECP_Clusters::get($cluster_id);

        if (!$cluster) {
            return;
        }

        $recommendation = is_array($cluster['recommendation']) ? $cluster['recommendation'] : array();
        $primary_id = isset($recommendation['primary_post_id']) ? (int) $recommendation['primary_post_id'] : 0;

        ?>
        <div class="ecp-cluster-context">
            <h2>
                <?php
                printf(
                    /* translators: %s: the shared query */
                    esc_html__('Fixing the overlap on “%s”', 'enhanced-content-plugin'),
                    esc_html($cluster['label'])
                );
                ?>
            </h2>
            <?php if (!empty($recommendation['summary'])) : ?>
                <p><?php echo esc_html($recommendation['summary']); ?></p>
            <?php endif; ?>
            <?php if ($primary_id) : ?>
                <p>
                    <?php
                    printf(
                        /* translators: %s: linked post title */
                        esc_html__('These changes all point readers and search engines at %s as the page that owns this topic. They work as a set — applying some and not others leaves the overlap half-fixed.', 'enhanced-content-plugin'),
                        '<strong>' . esc_html(get_the_title($primary_id)) . '</strong>'
                    );
                    ?>
                </p>
            <?php endif; ?>
            <p>
                <a href="<?php echo esc_url(admin_url('admin.php?page=ecp-clusters')); ?>">
                    <?php esc_html_e('Back to competing pages', 'enhanced-content-plugin'); ?>
                </a>
                <span class="ecp-sep">·</span>
                <a href="<?php echo esc_url(admin_url('admin.php?page=ecp-review')); ?>">
                    <?php esc_html_e('Show every waiting change', 'enhanced-content-plugin'); ?>
                </a>
            </p>
        </div>
        <?php
    }

    /**
     * One proposal card.
     */
    private static function render_card(array $proposal) {
        $post_id = (int) $proposal['post_id'];
        $flags = is_array($proposal['flags']) ? $proposal['flags'] : array();
        $evidence = is_array($proposal['evidence']) ? $proposal['evidence'] : array();
        $is_pending = ECP_Proposals::PENDING === $proposal['status'];
        $type_info = ECP_Proposals::change_type($proposal['change_type']);
        $can_act = $is_pending && ECP_Capabilities::can_review($post_id);
        $preview_url = ECP_Preview::url($proposal);
        $is_meta = $type_info && 'meta' === $type_info['target'];

        ?>
        <article class="ecp-card ecp-risk-<?php echo esc_attr($proposal['risk']); ?>"
                 id="ecp-proposal-<?php echo esc_attr($proposal['id']); ?>"
                 data-id="<?php echo esc_attr($proposal['id']); ?>"
                 data-risk="<?php echo esc_attr($proposal['risk']); ?>"
                 tabindex="-1">

            <header class="ecp-card-head">
                <?php if ($can_act) : ?>
                    <input type="checkbox" class="ecp-card-select"
                           value="<?php echo esc_attr($proposal['id']); ?>"
                           aria-label="<?php echo esc_attr(sprintf(
                               /* translators: %s: change title */
                               __('Select: %s', 'enhanced-content-plugin'),
                               $proposal['title']
                           )); ?>">
                <?php endif; ?>

                <div class="ecp-card-headings">
                    <h2 class="ecp-card-title"><?php echo esc_html($proposal['title']); ?></h2>
                    <p class="ecp-card-meta">
                        <a href="<?php echo esc_url(get_edit_post_link($post_id)); ?>" class="ecp-post-link">
                            <?php echo esc_html(get_the_title($post_id)); ?>
                        </a>
                        <span class="ecp-sep">·</span>
                        <span class="ecp-type"><?php echo esc_html(ECP_Proposals::type_label($proposal['change_type'])); ?></span>
                        <span class="ecp-sep">·</span>
                        <span class="ecp-size"><?php echo esc_html(ECP_Diff::summary($proposal['before_value'], $proposal['after_value'])); ?></span>
                        <?php if ((int) $proposal['cluster_id']) : ?>
                            <span class="ecp-sep">·</span>
                            <a class="ecp-cluster-tag"
                               href="<?php echo esc_url(add_query_arg(array('page' => 'ecp-review', 'cluster' => (int) $proposal['cluster_id']), admin_url('admin.php'))); ?>">
                                <?php esc_html_e('part of a competing-pages fix', 'enhanced-content-plugin'); ?>
                            </a>
                        <?php endif; ?>
                    </p>
                </div>

                <div class="ecp-card-badges">
                    <span class="ecp-badge ecp-badge-risk ecp-badge-<?php echo esc_attr($proposal['risk']); ?>"
                          title="<?php echo esc_attr(self::risk_explanation($proposal['risk'])); ?>">
                        <?php echo esc_html(ECP_Proposals::risk_label($proposal['risk'])); ?>
                    </span>
                    <span class="ecp-badge ecp-badge-confidence"
                          title="<?php esc_attr_e('How sure the agent is about this change.', 'enhanced-content-plugin'); ?>">
                        <?php
                        printf(
                            /* translators: %d: confidence percentage */
                            esc_html__('%d%% confident', 'enhanced-content-plugin'),
                            (int) $proposal['confidence']
                        );
                        ?>
                    </span>
                    <?php if (!$is_pending) : ?>
                        <span class="ecp-badge ecp-badge-status">
                            <?php echo esc_html(ECP_Proposals::status_label($proposal['status'])); ?>
                        </span>
                    <?php endif; ?>
                </div>
            </header>

            <?php if ($proposal['rationale']) : ?>
                <div class="ecp-why">
                    <strong><?php esc_html_e('Why:', 'enhanced-content-plugin'); ?></strong>
                    <?php echo esc_html($proposal['rationale']); ?>
                </div>
            <?php endif; ?>

            <?php self::render_warnings($flags, $proposal); ?>

            <?php if ($is_meta) : ?>
                <?php
                // A meta description has no visible form on the page, so the
                // useful preview is the search result it produces.
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ECP_Preview escapes everything it emits.
                echo ECP_Preview::serp_snippet($proposal);
                ?>
            <?php endif; ?>

            <div class="ecp-diff-wrap">
                <?php
                // Meta before-values can be stored SEO-plugin templates
                // ("%%title%% %%page%%"). The diff should compare what a
                // searcher sees, not the template syntax.
                $diff_before = $is_meta
                    ? ECP_Signals::resolve_seo_template($proposal['before_value'], $post_id)
                    : $proposal['before_value'];

                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ECP_Diff escapes every text node it emits.
                echo ECP_Diff::render($diff_before, $proposal['after_value']);
                ?>
            </div>

            <?php if (!$is_meta) : ?>
                <p class="ecp-preview-actions">
                    <button type="button" class="button-link ecp-render-toggle"
                            data-id="<?php echo esc_attr($proposal['id']); ?>"
                            aria-expanded="false">
                        <?php esc_html_e('Show it rendered', 'enhanced-content-plugin'); ?>
                    </button>
                    <?php if ($preview_url) : ?>
                        <span class="ecp-sep">·</span>
                        <a href="<?php echo esc_url($preview_url); ?>" target="_blank" rel="noopener">
                            <?php esc_html_e('Preview the whole page', 'enhanced-content-plugin'); ?>
                            <span class="dashicons dashicons-external" style="font-size:14px;vertical-align:text-bottom;"></span>
                        </a>
                    <?php endif; ?>
                </p>
                <div class="ecp-rendered" hidden></div>
            <?php endif; ?>

            <?php if ($can_act) : ?>
                <?php
                // Section-shaped content opens in the visual editor; metadata
                // and the JSON payloads (FAQ, sources) stay plain text, where
                // a rich editor would only corrupt them.
                $edit_info = ECP_Proposals::change_type($proposal['change_type']);
                $edit_visual = $edit_info && in_array($edit_info['target'], array('section', 'section_insert', 'content'), true);
                ?>
                <div class="ecp-edit-panel" hidden>
                    <label for="ecp-edit-<?php echo esc_attr($proposal['id']); ?>">
                        <?php esc_html_e('Edit before applying', 'enhanced-content-plugin'); ?>
                    </label>
                    <textarea id="ecp-edit-<?php echo esc_attr($proposal['id']); ?>"
                              class="ecp-edit-field<?php echo $edit_visual ? ' ecp-edit-html' : ''; ?>" rows="12"><?php echo esc_textarea($proposal['after_value']); ?></textarea>
                    <p class="description">
                        <?php esc_html_e('Your version is what gets applied, and the audit log records that you changed it.', 'enhanced-content-plugin'); ?>
                    </p>
                    <button type="button" class="button button-primary ecp-save-edit"><?php esc_html_e('Save and apply', 'enhanced-content-plugin'); ?></button>
                    <button type="button" class="button ecp-cancel-edit"><?php esc_html_e('Cancel', 'enhanced-content-plugin'); ?></button>
                </div>
            <?php endif; ?>

            <footer class="ecp-card-foot">
                <div class="ecp-card-actions">
                    <?php if ($can_act) : ?>
                        <button type="button" class="button button-primary ecp-approve">
                            <?php esc_html_e('Apply', 'enhanced-content-plugin'); ?>
                        </button>
                        <button type="button" class="button ecp-edit">
                            <?php esc_html_e('Edit first', 'enhanced-content-plugin'); ?>
                        </button>
                        <button type="button" class="button ecp-reject">
                            <?php esc_html_e('Reject', 'enhanced-content-plugin'); ?>
                        </button>
                    <?php elseif ($is_pending) : ?>
                        <span class="ecp-muted">
                            <?php esc_html_e('Waiting for someone who can approve changes to this page.', 'enhanced-content-plugin'); ?>
                        </span>
                    <?php elseif (ECP_Proposals::APPLIED === $proposal['status']) : ?>
                        <?php if (ECP_Capabilities::can_review($post_id)) : ?>
                            <button type="button" class="button ecp-revert">
                                <?php esc_html_e('Undo this change', 'enhanced-content-plugin'); ?>
                            </button>
                        <?php endif; ?>
                        <a href="<?php echo esc_url(get_permalink($post_id)); ?>" class="button" target="_blank" rel="noopener">
                            <?php esc_html_e('View the page', 'enhanced-content-plugin'); ?>
                        </a>
                    <?php endif; ?>
                </div>

                <div class="ecp-card-detail">
                    <?php if (!empty($evidence['addresses_issue'])) : ?>
                        <span class="ecp-detail-item">
                            <?php
                            printf(
                                /* translators: %s: issue reference */
                                esc_html__('Fixes issue %s', 'enhanced-content-plugin'),
                                esc_html($evidence['addresses_issue'])
                            );
                            ?>
                        </span>
                    <?php endif; ?>

                    <?php if (ECP_Proposals::APPLIED === $proposal['status']) : ?>
                        <?php $performance = ECP_Applier::performance((int) $proposal['id']); ?>
                        <?php if ($performance) : ?>
                            <span class="ecp-detail-item ecp-perf ecp-perf-<?php echo esc_attr($performance['verdict']); ?>">
                                <?php echo esc_html(ECP_Applier::verdict_label($performance['verdict'])); ?>
                                <?php
                                printf(
                                    ' (%s%d %s)',
                                    $performance['clicks_delta'] >= 0 ? '+' : '',
                                    (int) $performance['clicks_delta'],
                                    esc_html__('clicks', 'enhanced-content-plugin')
                                );
                                ?>
                            </span>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if (!empty($type_info['help'])) : ?>
                        <span class="ecp-detail-item ecp-type-help"><?php echo esc_html($type_info['help']); ?></span>
                    <?php endif; ?>
                </div>
            </footer>

            <div class="ecp-card-status" aria-live="polite"></div>
        </article>
        <?php
    }

    /**
     * The warnings a reviewer must not miss.
     */
    private static function render_warnings(array $flags, array $proposal) {
        $has_claims = !empty($flags['unverified_claims']);
        $has_figures = !empty($flags['new_figures']);
        $has_brand = !empty($flags['brand_terms_altered']);
        $heading_changed = !empty($flags['heading_changed']);
        $edited = !empty($flags['human_edited']);
        $large_trim = !empty($flags['large_trim']) && is_array($flags['large_trim']);

        if (!$has_claims && !$has_figures && !$has_brand && !$heading_changed && !$edited && !$large_trim) {
            return;
        }

        echo '<div class="ecp-warnings">';

        if ($has_claims) {
            echo '<div class="ecp-warning ecp-warning-danger">';
            echo '<strong>' . esc_html__('The agent could not verify these — check them before applying:', 'enhanced-content-plugin') . '</strong>';
            echo '<ul>';
            foreach ((array) $flags['unverified_claims'] as $claim) {
                echo '<li>' . esc_html($claim) . '</li>';
            }
            echo '</ul>';
            echo '</div>';
        }

        if ($has_figures) {
            echo '<div class="ecp-warning ecp-warning-danger">';
            printf(
                '<strong>%s</strong> %s',
                esc_html__('Contains numbers that are not in the original:', 'enhanced-content-plugin'),
                esc_html(implode(', ', (array) $flags['new_figures']))
            );
            echo '</div>';
        }

        if ($has_brand) {
            echo '<div class="ecp-warning ecp-warning-caution">';
            printf(
                '<strong>%s</strong> %s',
                esc_html__('Your brand terms were written differently:', 'enhanced-content-plugin'),
                esc_html(implode(', ', (array) $flags['brand_terms_altered']))
            );
            echo '</div>';
        }

        if ($large_trim) {
            echo '<div class="ecp-warning ecp-warning-caution">';
            printf(
                '<strong>%s</strong> %s',
                esc_html__('Heavy trim:', 'enhanced-content-plugin'),
                esc_html(sprintf(
                    /* translators: 1: original word count, 2: new word count */
                    __('cuts this section from %1$d words to %2$d. Read the removed half before approving — the guardrails check that facts survive, but only you know which detail your readers came for.', 'enhanced-content-plugin'),
                    (int) $flags['large_trim']['from'],
                    (int) $flags['large_trim']['to']
                ))
            );
            echo '</div>';
        }

        if ($heading_changed) {
            echo '<div class="ecp-warning ecp-warning-note">';
            echo esc_html__('This changes a heading. Any anchor links pointing at it will need updating.', 'enhanced-content-plugin');
            echo '</div>';
        }

        if ($edited) {
            echo '<div class="ecp-warning ecp-warning-note">';
            echo esc_html__('You edited this before it was applied.', 'enhanced-content-plugin');
            echo '</div>';
        }

        echo '</div>';

        unset($proposal);
    }

    private static function risk_explanation($risk) {
        switch ($risk) {
            case ECP_Proposals::RISK_SAFE:
                return __('Mechanical and reversible. Low chance of getting it wrong.', 'enhanced-content-plugin');

            case ECP_Proposals::RISK_SENSITIVE:
                return __('Contains something the agent could not verify. Read it properly.', 'enhanced-content-plugin');
        }

        return __('An editorial judgement. Worth reading before you apply it.', 'enhanced-content-plugin');
    }

    private static function render_empty($status) {
        $ready = ECP_Agent_Settings::is_ready();
        $scanned = ECP_Opportunity_Engine::stats();

        ?>
        <div class="ecp-empty">
            <?php if (ECP_Proposals::PENDING !== $status) : ?>
                <h2><?php esc_html_e('Nothing here', 'enhanced-content-plugin'); ?></h2>
                <p><?php esc_html_e('No changes with that status yet.', 'enhanced-content-plugin'); ?></p>
            <?php elseif (!$ready) : ?>
                <h2><?php esc_html_e('The agent is not connected yet', 'enhanced-content-plugin'); ?></h2>
                <p><?php esc_html_e('It needs an AI provider before it can propose anything. Setup takes about a minute.', 'enhanced-content-plugin'); ?></p>
                <a href="<?php echo esc_url(admin_url('admin.php?page=ecp-agent-settings')); ?>" class="button button-primary button-hero">
                    <?php esc_html_e('Finish setup', 'enhanced-content-plugin'); ?>
                </a>
            <?php elseif (0 === $scanned['total']) : ?>
                <h2><?php esc_html_e('Nothing has been scanned yet', 'enhanced-content-plugin'); ?></h2>
                <p><?php esc_html_e('Scanning scores your pages and costs nothing — no AI calls are made.', 'enhanced-content-plugin'); ?></p>
                <button type="button" class="button button-primary button-hero" id="ecp-run-scan">
                    <?php esc_html_e('Scan my content now', 'enhanced-content-plugin'); ?>
                </button>
                <p class="ecp-scan-progress" aria-live="polite"></p>
            <?php else : ?>
                <h2><?php esc_html_e('All clear', 'enhanced-content-plugin'); ?></h2>
                <p>
                    <?php
                    printf(
                        /* translators: %d: number of open opportunities */
                        esc_html__('Nothing is waiting for you. %d pages have opportunities the agent has not analyzed yet.', 'enhanced-content-plugin'),
                        (int) $scanned['open']
                    );
                    ?>
                </p>
                <a href="<?php echo esc_url(admin_url('admin.php?page=ecp-opportunities')); ?>" class="button button-primary">
                    <?php esc_html_e('See the opportunities', 'enhanced-content-plugin'); ?>
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
                /* translators: %s: number of changes */
                _n('%s change', '%s changes', $total, 'enhanced-content-plugin'),
                number_format_i18n($total)
            ))
        );

        echo paginate_links(array(  // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core function escapes.
            'base'      => add_query_arg('paged', '%#%'),
            'format'    => '',
            'total'     => $pages,
            'current'   => $paged,
            'prev_text' => '&laquo;',
            'next_text' => '&raquo;',
        ));

        echo '</div></div>';
    }
}
