<?php
/**
 * Content Plan: approved topics become campaigns, campaigns become
 * briefs, and nothing drafts until a human approves the brief.
 *
 * Topics appear in publishing order — foundation, supporting
 * expertise, commercial support — with each campaign's progress and
 * outstanding evidence in plain sight. A brief whose information-gain
 * gate is closed wears the warning where the approve button is, so
 * approving a page that adds nothing is a deliberate act, not a slip.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ECP_Screen_Plan {

    public static function render() {
        if (!ECP_Capabilities::can_view()) {
            wp_die(esc_html__('You do not have permission to view this page.', 'enhanced-content-plugin'));
        }

        $campaigns = ECP_Briefs::campaigns();
        $can_review = ECP_Capabilities::can_review();

        ?>
        <div class="wrap ecp-wrap">
            <h1><?php esc_html_e('Content Plan', 'enhanced-content-plugin'); ?></h1>

            <?php ECP_Admin_Menu::header('ecp-plan'); ?>

            <p class="ecp-narrative">
                <?php esc_html_e('Every approved topic gets a strategic brief before anything is written: the unique angle, the structure, the links, the facts to verify, the original media to produce. No article is ever drafted from an unapproved brief.', 'enhanced-content-plugin'); ?>
            </p>

            <?php if (!$campaigns) : ?>
                <div class="ecp-panel">
                    <p class="ecp-muted">
                        <?php esc_html_e('Nothing planned yet. Approve topics on the Topical Map — the ones judged worth a new page arrive here as campaigns.', 'enhanced-content-plugin'); ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=ecp-map')); ?>"><?php esc_html_e('Open the Topical Map', 'enhanced-content-plugin'); ?> &rarr;</a>
                    </p>
                </div>
            <?php endif; ?>

            <?php foreach ($campaigns as $campaign) : ?>
                <?php self::render_campaign($campaign, $can_review); ?>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * One campaign: the seed's approved topics in publishing order.
     */
    private static function render_campaign(array $campaign, $can_review) {
        $progress = ECP_Briefs::progress($campaign['seed']);
        $current_wave = 0;
        ?>
        <div class="ecp-panel ecp-campaign">
            <h2>
                <?php
                printf(
                    /* translators: %s: seed topic */
                    esc_html__('Campaign: %s', 'enhanced-content-plugin'),
                    esc_html($campaign['seed'])
                );
                ?>
            </h2>

            <p class="ecp-muted">
                <?php
                printf(
                    /* translators: 1: planned, 2: briefed, 3: approved, 4: facts outstanding */
                    esc_html__('%1$d pages planned · %2$d briefed · %3$d briefs approved · %4$d facts still need your verification.', 'enhanced-content-plugin'),
                    (int) $progress['planned'],
                    (int) $progress['briefed'],
                    (int) $progress['approved'],
                    (int) $progress['facts_outstanding']
                );
                ?>
            </p>

            <?php foreach ($campaign['topics'] as $topic) : ?>
                <?php if ((int) $topic['wave'] !== $current_wave) : ?>
                    <?php $current_wave = (int) $topic['wave']; ?>
                    <h3 class="ecp-campaign-wave"><?php echo esc_html(ECP_Briefs::wave_label($current_wave)); ?></h3>
                <?php endif; ?>
                <?php self::render_topic($topic, $can_review); ?>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * One planned page: its brief state and controls, and the brief
     * itself when one exists.
     */
    private static function render_topic(array $topic, $can_review) {
        $brief = $topic['brief_id'] ? ECP_Briefs::get((int) $topic['id']) : null;
        ?>
        <div class="ecp-plan-topic" data-topic="<?php echo esc_attr($topic['id']); ?>">
            <div class="ecp-plan-topic-head">
                <strong><?php echo esc_html($topic['topic']); ?></strong>
                <span class="ecp-chip"><?php echo esc_html(ECP_Topical_Map::page_type_label($topic['page_type'])); ?></span>

                <?php if (!$brief) : ?>
                    <?php if ($can_review && ECP_Agent_Settings::is_ready()) : ?>
                        <button type="button" class="button button-small button-primary ecp-build-brief" data-topic="<?php echo esc_attr($topic['id']); ?>">
                            <?php esc_html_e('Write the brief', 'enhanced-content-plugin'); ?>
                        </button>
                    <?php else : ?>
                        <span class="ecp-muted"><?php esc_html_e('No brief yet.', 'enhanced-content-plugin'); ?></span>
                    <?php endif; ?>
                <?php elseif (ECP_Briefs::APPROVED === $brief['status']) : ?>
                    <span class="ecp-chip ecp-chip-safe"><?php esc_html_e('Brief approved — ready for drafting', 'enhanced-content-plugin'); ?></span>
                <?php elseif (ECP_Briefs::REJECTED === $brief['status']) : ?>
                    <span class="ecp-chip"><?php esc_html_e('Brief rejected', 'enhanced-content-plugin'); ?></span>
                <?php elseif (!(int) $brief['info_gain_ok']) : ?>
                    <span class="ecp-chip ecp-chip-sensitive"><?php esc_html_e('Adds nothing new — drafting not advised', 'enhanced-content-plugin'); ?></span>
                <?php else : ?>
                    <span class="ecp-chip ecp-chip-moderate"><?php esc_html_e('Brief awaiting your decision', 'enhanced-content-plugin'); ?></span>
                <?php endif; ?>

                <span class="ecp-row-status" aria-live="polite"></span>
            </div>

            <?php if ($brief) : ?>
                <?php self::render_brief($brief, $can_review); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function render_brief(array $row, $can_review) {
        $brief = is_array($row['brief']) ? $row['brief'] : array();
        $gain = isset($brief['info_gain']) ? $brief['info_gain'] : array('source' => 'none', 'statement' => '');
        ?>
        <div class="ecp-brief">
            <?php if (!empty($brief['unique_angle'])) : ?>
                <p><strong><?php esc_html_e('Angle:', 'enhanced-content-plugin'); ?></strong> <?php echo esc_html($brief['unique_angle']); ?></p>
            <?php endif; ?>

            <p class="<?php echo (int) $row['info_gain_ok'] ? '' : 'ecp-brief-gate'; ?>">
                <strong><?php esc_html_e('What it adds:', 'enhanced-content-plugin'); ?></strong>
                <?php
                if ((int) $row['info_gain_ok']) {
                    echo esc_html($gain['statement']);
                } else {
                    esc_html_e('The brief could not name a real contribution this page would make beyond what already ranks. The gameplan\'s advice: do not write it. Approve only if you can supply the missing angle yourself.', 'enhanced-content-plugin');
                }
                ?>
            </p>

            <?php if ((int) $row['facts_outstanding'] > 0) : ?>
                <p class="ecp-muted">
                    <?php
                    printf(
                        /* translators: %d: number of unverified facts */
                        esc_html(_n(
                            '%d required fact is not in the Knowledge Vault yet — the article cannot be honest without it.',
                            '%d required facts are not in the Knowledge Vault yet — the article cannot be honest without them.',
                            (int) $row['facts_outstanding'],
                            'enhanced-content-plugin'
                        )),
                        (int) $row['facts_outstanding']
                    );
                    ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=ecp-vault')); ?>"><?php esc_html_e('Add them to the vault', 'enhanced-content-plugin'); ?> &rarr;</a>
                </p>
            <?php endif; ?>

            <details class="ecp-brief-details">
                <summary><?php esc_html_e('The full brief', 'enhanced-content-plugin'); ?></summary>

                <?php if (!empty($brief['objective'])) : ?>
                    <p><strong><?php esc_html_e('Objective:', 'enhanced-content-plugin'); ?></strong> <?php echo esc_html($brief['objective']); ?></p>
                <?php endif; ?>

                <?php if (!empty($brief['audience'])) : ?>
                    <p><strong><?php esc_html_e('Audience:', 'enhanced-content-plugin'); ?></strong> <?php echo esc_html($brief['audience']); ?></p>
                <?php endif; ?>

                <?php if (!empty($brief['title_options'])) : ?>
                    <p><strong><?php esc_html_e('Title options:', 'enhanced-content-plugin'); ?></strong> <?php echo esc_html(implode(' · ', array_map('strval', (array) $brief['title_options']))); ?></p>
                <?php endif; ?>

                <?php if (!empty($brief['sections'])) : ?>
                    <p><strong><?php esc_html_e('Structure:', 'enhanced-content-plugin'); ?></strong></p>
                    <ol class="ecp-brief-list">
                        <?php foreach ((array) $brief['sections'] as $section) : ?>
                            <li>
                                <?php echo esc_html($section['heading']); ?>
                                <?php if (empty($section['required'])) : ?>
                                    <span class="ecp-muted"><?php esc_html_e('(optional)', 'enhanced-content-plugin'); ?></span>
                                <?php endif; ?>
                                <span class="ecp-muted">— <?php echo esc_html($section['purpose']); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>

                <?php if (!empty($brief['required_facts'])) : ?>
                    <p><strong><?php esc_html_e('Facts to verify:', 'enhanced-content-plugin'); ?></strong></p>
                    <ul class="ecp-brief-list">
                        <?php foreach ((array) $brief['required_facts'] as $fact) : ?>
                            <li>
                                <?php echo esc_html($fact['claim']); ?>
                                <?php if (!empty($fact['in_vault'])) : ?>
                                    <span class="ecp-chip ecp-chip-safe"><?php esc_html_e('verified in vault', 'enhanced-content-plugin'); ?></span>
                                <?php else : ?>
                                    <span class="ecp-chip ecp-chip-moderate"><?php esc_html_e('needs you', 'enhanced-content-plugin'); ?></span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php if (!empty($brief['internal_links_out']) || !empty($brief['internal_links_in'])) : ?>
                    <p><strong><?php esc_html_e('Link blueprint:', 'enhanced-content-plugin'); ?></strong></p>
                    <ul class="ecp-brief-list">
                        <?php foreach ((array) $brief['internal_links_out'] as $link) : ?>
                            <li>
                                <?php
                                printf(
                                    /* translators: 1: anchor text, 2: page title */
                                    esc_html__('Link out: "%1$s" → %2$s', 'enhanced-content-plugin'),
                                    esc_html($link['anchor']),
                                    esc_html($link['title'])
                                );
                                ?>
                            </li>
                        <?php endforeach; ?>
                        <?php foreach ((array) $brief['internal_links_in'] as $link) : ?>
                            <li>
                                <?php
                                printf(
                                    /* translators: 1: page title, 2: anchor text */
                                    esc_html__('Link in: %1$s should link here as "%2$s"', 'enhanced-content-plugin'),
                                    esc_html($link['title']),
                                    esc_html($link['anchor'])
                                );
                                ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php if (!empty($brief['media_plan'])) : ?>
                    <p><strong><?php esc_html_e('Original media to produce (nothing AI-generated):', 'enhanced-content-plugin'); ?></strong></p>
                    <ul class="ecp-brief-list">
                        <?php foreach ((array) $brief['media_plan'] as $media) : ?>
                            <li><?php echo esc_html($media['type'] . ' — ' . $media['description']); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php if (!empty($brief['risks'])) : ?>
                    <p><strong><?php esc_html_e('Risks:', 'enhanced-content-plugin'); ?></strong> <?php echo esc_html(implode(' · ', array_map('strval', (array) $brief['risks']))); ?></p>
                <?php endif; ?>

                <?php if (!empty($brief['success_metrics'])) : ?>
                    <p><strong><?php esc_html_e('Success looks like:', 'enhanced-content-plugin'); ?></strong> <?php echo esc_html(implode(' · ', array_map('strval', (array) $brief['success_metrics']))); ?></p>
                <?php endif; ?>
            </details>

            <?php self::render_draft_state($row, $can_review); ?>

            <?php if ($can_review) : ?>
                <p class="ecp-brief-actions">
                    <?php if (ECP_Briefs::PROPOSED === $row['status']) : ?>
                        <button type="button" class="button button-small button-primary ecp-brief-act" data-act="approve" data-id="<?php echo esc_attr($row['id']); ?>">
                            <?php esc_html_e('Approve brief', 'enhanced-content-plugin'); ?>
                        </button>
                        <button type="button" class="button-link ecp-brief-act" data-act="reject" data-id="<?php echo esc_attr($row['id']); ?>">
                            <?php esc_html_e('Reject', 'enhanced-content-plugin'); ?>
                        </button>
                    <?php else : ?>
                        <?php if (ECP_Briefs::APPROVED === $row['status'] && !(int) $row['draft_post_id'] && ECP_Agent_Settings::is_ready()) : ?>
                            <button type="button" class="button button-small button-primary ecp-draft-article" data-id="<?php echo esc_attr($row['id']); ?>">
                                <?php esc_html_e('Draft the article', 'enhanced-content-plugin'); ?>
                            </button>
                        <?php endif; ?>
                        <button type="button" class="button button-small ecp-brief-act" data-act="reopen" data-id="<?php echo esc_attr($row['id']); ?>">
                            <?php esc_html_e('Reconsider', 'enhanced-content-plugin'); ?>
                        </button>
                        <?php if (ECP_Briefs::REJECTED === $row['status'] && ECP_Agent_Settings::is_ready()) : ?>
                            <button type="button" class="button button-small ecp-build-brief" data-topic="<?php echo esc_attr($row['topic_id']); ?>">
                                <?php esc_html_e('Rewrite the brief', 'enhanced-content-plugin'); ?>
                            </button>
                        <?php endif; ?>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * The drafted article, its live status, and the quality report.
     *
     * Everything the pipeline flagged is shown before the edit link —
     * the owner walks into the editor knowing exactly what to check.
     */
    private static function render_draft_state(array $row, $can_review) {
        if (!(int) $row['draft_post_id']) {
            return;
        }

        $post = get_post((int) $row['draft_post_id']);

        if (!$post) {
            ?>
            <p class="ecp-muted"><?php esc_html_e('The draft for this brief was deleted. You can draft it again.', 'enhanced-content-plugin'); ?></p>
            <?php
            return;
        }

        $quality = ECP_DB::decode($row['draft_quality']);
        $flags = isset($quality['flags']) ? (array) $quality['flags'] : array();
        $claims = isset($quality['claims']) ? (array) $quality['claims'] : array();
        $unverified = array_filter($claims, function ($claim) {
            return isset($claim['support']) && 'needs_verification' === $claim['support'];
        });
        ?>
        <div class="ecp-draft-state">
            <p>
                <?php if ('publish' === $post->post_status) : ?>
                    <span class="ecp-chip ecp-chip-safe"><?php esc_html_e('Published', 'enhanced-content-plugin'); ?></span>
                    <a href="<?php echo esc_url(get_permalink($post)); ?>"><?php esc_html_e('View the article', 'enhanced-content-plugin'); ?> &rarr;</a>
                <?php else : ?>
                    <span class="ecp-chip ecp-chip-moderate"><?php esc_html_e('Unpublished draft', 'enhanced-content-plugin'); ?></span>
                    <?php if ($can_review) : ?>
                        <a href="<?php echo esc_url(get_edit_post_link($post)); ?>"><?php esc_html_e('Review and publish in the editor', 'enhanced-content-plugin'); ?> &rarr;</a>
                    <?php endif; ?>
                <?php endif; ?>
            </p>

            <?php if ($flags) : ?>
                <p><strong><?php esc_html_e('Check before publishing:', 'enhanced-content-plugin'); ?></strong></p>
                <ul class="ecp-brief-list">
                    <?php foreach ($flags as $flag) : ?>
                        <li>
                            <span class="ecp-chip ecp-chip-<?php echo esc_attr('high' === $flag['severity'] ? 'sensitive' : ('medium' === $flag['severity'] ? 'moderate' : 'safe')); ?>">
                                <?php echo esc_html($flag['severity']); ?>
                            </span>
                            <?php echo esc_html($flag['detail']); ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php elseif ('publish' !== $post->post_status) : ?>
                <p class="ecp-muted"><?php esc_html_e('The quality review found nothing to flag. Read it anyway — it publishes under your name.', 'enhanced-content-plugin'); ?></p>
            <?php endif; ?>

            <?php if ($unverified) : ?>
                <details class="ecp-brief-details">
                    <summary><?php esc_html_e('Claims needing your verification', 'enhanced-content-plugin'); ?></summary>
                    <ul class="ecp-brief-list">
                        <?php foreach ($unverified as $claim) : ?>
                            <li>
                                <strong><?php echo esc_html($claim['section']); ?>:</strong>
                                <?php echo esc_html($claim['statement']); ?>
                                <span class="ecp-muted">— <?php echo esc_html($claim['source_note']); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="ecp-muted">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=ecp-vault')); ?>"><?php esc_html_e('Verify facts in the Knowledge Vault', 'enhanced-content-plugin'); ?> &rarr;</a>
                    </p>
                </details>
            <?php endif; ?>
        </div>
        <?php
    }
}
