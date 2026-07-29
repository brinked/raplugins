<?php
/**
 * Growth Roadmap: the sequenced plan, with the owner in charge of it.
 *
 * Every step says why it is on the list and why it sits where it sits.
 * The owner can approve a step (the agent analyzes that page next),
 * postpone it, dismiss it, lock it in place, or mark it complete —
 * gameplan Phase 2's contract that recommendations are decisions for a
 * human, not a queue the machine works through on its own.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ECP_Screen_Roadmap {

    public static function render() {
        if (!ECP_Capabilities::can_view()) {
            wp_die(esc_html__('You do not have permission to view this page.', 'enhanced-content-plugin'));
        }

        ECP_Roadmap::maybe_rebuild();

        $active = ECP_Roadmap::query(array('status' => array(ECP_Roadmap::PROPOSED, ECP_Roadmap::APPROVED), 'limit' => 50));
        $postponed = ECP_Roadmap::query(array('status' => ECP_Roadmap::POSTPONED, 'limit' => 20));
        $done = ECP_Roadmap::query(array('status' => ECP_Roadmap::DONE, 'limit' => 10));
        $can_review = ECP_Capabilities::can_review();

        // Dependency lines name the step they wait on.
        $needed = array();
        foreach ($active as $row) {
            foreach ((array) $row['depends_on'] as $key) {
                $needed[] = $key;
            }
        }
        $dependency_titles = ECP_Roadmap::titles_for($needed);

        ?>
        <div class="wrap ecp-wrap">
            <h1><?php esc_html_e('Growth Roadmap', 'enhanced-content-plugin'); ?></h1>

            <?php ECP_Admin_Menu::header('ecp-roadmap'); ?>

            <p class="ecp-narrative">
                <?php esc_html_e('The plan, in the order the work should happen: visibility problems first, consolidation decisions before per-page work, cheap snippet wins before restructures. Approve a step and the agent prepares those changes next — nothing is applied without your sign-off. Value figures are directional estimates modelled from Search Console data, not promises.', 'enhanced-content-plugin'); ?>
            </p>

            <?php if ($can_review) : ?>
                <p>
                    <button type="button" class="button" id="ecp-rebuild-roadmap">
                        <?php esc_html_e('Refresh the plan', 'enhanced-content-plugin'); ?>
                    </button>
                    <span class="ecp-muted"><?php esc_html_e('Free — re-derives the plan from the latest scan. Your decisions are kept.', 'enhanced-content-plugin'); ?></span>
                    <span class="ecp-roadmap-rebuild-status" aria-live="polite"></span>
                </p>
            <?php endif; ?>

            <div class="ecp-panel">
                <h2><?php esc_html_e('Next steps', 'enhanced-content-plugin'); ?></h2>

                <?php if (!$active) : ?>
                    <p class="ecp-muted"><?php esc_html_e('Nothing on the plan yet. Run a content scan so the agent has something to sequence.', 'enhanced-content-plugin'); ?></p>
                <?php else : ?>
                    <ol class="ecp-roadmap-list">
                        <?php foreach ($active as $row) : ?>
                            <?php self::render_step($row, $dependency_titles, $can_review); ?>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </div>

            <?php if ($postponed) : ?>
                <div class="ecp-panel">
                    <h2><?php esc_html_e('Postponed', 'enhanced-content-plugin'); ?></h2>
                    <ul class="ecp-roadmap-list ecp-roadmap-muted">
                        <?php foreach ($postponed as $row) : ?>
                            <li class="ecp-roadmap-step" data-id="<?php echo esc_attr($row['id']); ?>">
                                <div class="ecp-roadmap-main">
                                    <strong><?php echo esc_html($row['title']); ?></strong>
                                    <span class="ecp-muted">
                                        <?php
                                        if ($row['postponed_until']) {
                                            printf(
                                                /* translators: %s: human-readable time difference */
                                                esc_html__('returns in %s', 'enhanced-content-plugin'),
                                                esc_html(human_time_diff((int) current_time('timestamp'), strtotime($row['postponed_until'])))
                                            );
                                        }
                                        ?>
                                    </span>
                                </div>
                                <?php if ($can_review) : ?>
                                    <div class="ecp-roadmap-actions">
                                        <button type="button" class="button button-small ecp-roadmap-act" data-act="reopen" data-id="<?php echo esc_attr($row['id']); ?>">
                                            <?php esc_html_e('Bring back', 'enhanced-content-plugin'); ?>
                                        </button>
                                        <span class="ecp-row-status" aria-live="polite"></span>
                                    </div>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($done) : ?>
                <div class="ecp-panel">
                    <h2><?php esc_html_e('Completed', 'enhanced-content-plugin'); ?></h2>
                    <p class="ecp-muted"><?php esc_html_e('Steps whose work went through the review queue and got resolved. The plan re-sequences itself as these land.', 'enhanced-content-plugin'); ?></p>
                    <ul class="ecp-roadmap-list ecp-roadmap-muted">
                        <?php foreach ($done as $row) : ?>
                            <li class="ecp-roadmap-step">
                                <div class="ecp-roadmap-main">
                                    <strong><?php echo esc_html($row['title']); ?></strong>
                                    <?php if ($row['completed_at']) : ?>
                                        <span class="ecp-muted">
                                            <?php
                                            printf(
                                                /* translators: %s: human-readable time difference */
                                                esc_html__('%s ago', 'enhanced-content-plugin'),
                                                esc_html(human_time_diff(strtotime($row['completed_at']), (int) current_time('timestamp')))
                                            );
                                            ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * One step: number, what and why, worth, and the decision buttons.
     */
    private static function render_step(array $row, array $dependency_titles, $can_review) {
        $why = is_array($row['why']) ? $row['why'] : array();
        $issues = isset($why['issues']) ? (array) $why['issues'] : array();
        $approved = ECP_Roadmap::APPROVED === $row['status'];
        $in_review = 'proposed' === $row['source_status'] || 'analyzing' === $row['source_status'];
        ?>
        <li class="ecp-roadmap-step<?php echo $approved ? ' is-approved' : ''; ?>" data-id="<?php echo esc_attr($row['id']); ?>">
            <span class="ecp-roadmap-number"><?php echo esc_html(number_format_i18n((int) $row['step_order'])); ?></span>

            <div class="ecp-roadmap-main">
                <p class="ecp-roadmap-title">
                    <?php if ((int) $row['post_id']) : ?>
                        <a href="<?php echo esc_url(get_edit_post_link((int) $row['post_id'])); ?>"><strong><?php echo esc_html($row['title']); ?></strong></a>
                    <?php else : ?>
                        <strong><?php echo esc_html($row['title']); ?></strong>
                    <?php endif; ?>

                    <span class="ecp-chip"><?php echo esc_html(ECP_Roadmap::track_label($row['track'])); ?></span>

                    <?php if ((int) $row['locked']) : ?>
                        <span class="ecp-chip ecp-chip-sensitive"><?php esc_html_e('Locked in place', 'enhanced-content-plugin'); ?></span>
                    <?php endif; ?>

                    <?php if ($approved) : ?>
                        <span class="ecp-chip ecp-chip-safe"><?php esc_html_e('Approved — queued for analysis', 'enhanced-content-plugin'); ?></span>
                    <?php endif; ?>

                    <?php if ($in_review) : ?>
                        <span class="ecp-chip ecp-chip-moderate"><?php esc_html_e('Changes in the review queue', 'enhanced-content-plugin'); ?></span>
                    <?php endif; ?>
                </p>

                <?php if ($issues) : ?>
                    <p class="ecp-muted ecp-roadmap-why">
                        <?php
                        $labels = array();
                        foreach (array_slice($issues, 0, 3) as $issue) {
                            $labels[] = ECP_Opportunity_Engine::reason_label($issue['code']);
                        }
                        printf(
                            /* translators: %s: comma-separated list of findings */
                            esc_html__('Why: %s.', 'enhanced-content-plugin'),
                            esc_html(implode(' · ', $labels))
                        );
                        ?>
                    </p>
                <?php elseif (isset($why['member_count'])) : ?>
                    <p class="ecp-muted ecp-roadmap-why">
                        <?php
                        printf(
                            /* translators: %d: number of competing pages */
                            esc_html__('Why: %d of your pages compete for the same topic — pick one to own it before improving any of them.', 'enhanced-content-plugin'),
                            (int) $why['member_count']
                        );
                        ?>
                    </p>
                <?php endif; ?>

                <?php if ((float) $row['potential_clicks'] > 0) : ?>
                    <p class="ecp-muted ecp-roadmap-worth">
                        <?php
                        printf(
                            /* translators: %s: click estimate */
                            esc_html__('Worth roughly %s extra monthly clicks — a directional estimate, not a promise.', 'enhanced-content-plugin'),
                            esc_html(number_format_i18n((int) round((float) $row['potential_clicks'])))
                        );
                        ?>
                    </p>
                <?php endif; ?>

                <?php foreach ((array) $row['depends_on'] as $dep_key) : ?>
                    <?php if (isset($dependency_titles[$dep_key]) && !in_array($dependency_titles[$dep_key]['status'], array(ECP_Roadmap::DONE, ECP_Roadmap::DISMISSED), true)) : ?>
                        <p class="ecp-muted ecp-roadmap-depends">
                            <?php
                            printf(
                                /* translators: 1: step number, 2: step title */
                                esc_html__('After step %1$s (%2$s).', 'enhanced-content-plugin'),
                                esc_html(number_format_i18n((int) $dependency_titles[$dep_key]['step_order'])),
                                esc_html($dependency_titles[$dep_key]['title'])
                            );
                            ?>
                        </p>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <?php if ($can_review && ECP_Capabilities::can_review((int) $row['post_id'])) : ?>
                <div class="ecp-roadmap-actions">
                    <?php if (!$approved) : ?>
                        <button type="button" class="button button-small button-primary ecp-roadmap-act" data-act="approve" data-id="<?php echo esc_attr($row['id']); ?>">
                            <?php esc_html_e('Approve', 'enhanced-content-plugin'); ?>
                        </button>
                    <?php else : ?>
                        <button type="button" class="button button-small ecp-roadmap-act" data-act="complete" data-id="<?php echo esc_attr($row['id']); ?>">
                            <?php esc_html_e('Mark complete', 'enhanced-content-plugin'); ?>
                        </button>
                    <?php endif; ?>
                    <button type="button" class="button button-small ecp-roadmap-act" data-act="postpone" data-id="<?php echo esc_attr($row['id']); ?>">
                        <?php esc_html_e('Postpone', 'enhanced-content-plugin'); ?>
                    </button>
                    <button type="button" class="button button-small ecp-roadmap-act" data-act="<?php echo (int) $row['locked'] ? 'unlock' : 'lock'; ?>" data-id="<?php echo esc_attr($row['id']); ?>">
                        <?php echo (int) $row['locked'] ? esc_html__('Unlock', 'enhanced-content-plugin') : esc_html__('Lock', 'enhanced-content-plugin'); ?>
                    </button>
                    <button type="button" class="button-link ecp-roadmap-act" data-act="dismiss" data-id="<?php echo esc_attr($row['id']); ?>">
                        <?php esc_html_e('Dismiss', 'enhanced-content-plugin'); ?>
                    </button>
                    <span class="ecp-row-status" aria-live="polite"></span>
                </div>
            <?php endif; ?>
        </li>
        <?php
    }
}
