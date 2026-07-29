<?php
/**
 * Agent dashboard: setup checklist for new sites, status and spend for
 * established ones.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ECP_Screen_Dashboard {

    public static function render() {
        if (!ECP_Capabilities::can_view()) {
            wp_die(esc_html__('You do not have permission to view this page.', 'enhanced-content-plugin'));
        }

        $steps = ECP_Agent_Settings::setup_steps();
        $done = count(array_filter(wp_list_pluck($steps, 'done')));
        // The setup checklist only means anything to someone who can act on
        // it, so a reviewer never sees a list of things they cannot do.
        $onboarded = $done === count($steps) || !ECP_Capabilities::can_manage();

        $opportunities = ECP_Opportunity_Engine::stats();
        $counts = ECP_Proposals::counts();
        $budget = ECP_AI_Client::budget_status();
        $search = ECP_Search_Data::status();
        $suggestions = ECP_Capabilities::can_manage() ? ECP_Trust_Ladder::suggestions() : array();

        ?>
        <div class="wrap ecp-wrap">
            <h1><?php esc_html_e('Enhanced Content', 'enhanced-content-plugin'); ?></h1>

            <?php ECP_Admin_Menu::header('ecp-dashboard'); ?>

            <?php if (!$onboarded) : ?>
                <?php self::render_checklist($steps, $done); ?>
            <?php endif; ?>

            <?php self::render_narrative($opportunities, $counts); ?>

            <?php self::render_priority(); ?>

            <div class="ecp-stat-grid">
                <?php
                self::stat(
                    isset($counts['pending']) ? (int) $counts['pending'] : 0,
                    __('Waiting for you', 'enhanced-content-plugin'),
                    admin_url('admin.php?page=ecp-review'),
                    'primary'
                );

                self::stat(
                    (int) $opportunities['open'],
                    __('Pages with opportunities', 'enhanced-content-plugin'),
                    admin_url('admin.php?page=ecp-opportunities')
                );

                self::stat(
                    isset($counts['applied']) ? (int) $counts['applied'] : 0,
                    __('Changes published', 'enhanced-content-plugin'),
                    admin_url('admin.php?page=ecp-review&status=applied')
                );

                self::stat(
                    (int) $opportunities['potential_clicks'],
                    __('Est. monthly clicks within reach', 'enhanced-content-plugin'),
                    '',
                    '',
                    ECP_Search_Data::is_connected()
                        ? __('Directional estimate · medium confidence · modelled from your Search Console impressions and positions over the last 28 days. Not a promise.', 'enhanced-content-plugin')
                        : __('Connect Search Console for a real figure here.', 'enhanced-content-plugin')
                );
                ?>
            </div>

            <div class="ecp-columns">
                <div class="ecp-col-main">

                    <?php if ($suggestions) : ?>
                        <div class="ecp-panel ecp-panel-suggestion">
                            <h2><?php esc_html_e('Save yourself some clicks', 'enhanced-content-plugin'); ?></h2>
                            <p><?php esc_html_e('You have approved these kinds of change consistently and never had to undo one. The agent can apply them without asking. If one ever gets rolled back, it goes straight back to manual review.', 'enhanced-content-plugin'); ?></p>
                            <ul class="ecp-suggestion-list">
                                <?php foreach ($suggestions as $type => $stats) : ?>
                                    <li>
                                        <div>
                                            <strong><?php echo esc_html(ECP_Proposals::type_label($type)); ?></strong>
                                            <span class="ecp-muted">
                                                <?php
                                                printf(
                                                    /* translators: 1: approved count, 2: rejected count */
                                                    esc_html__('%1$d approved, %2$d rejected', 'enhanced-content-plugin'),
                                                    (int) $stats['approved'],
                                                    (int) $stats['rejected']
                                                );
                                                ?>
                                            </span>
                                        </div>
                                        <button type="button" class="button ecp-enable-autopilot" data-type="<?php echo esc_attr($type); ?>">
                                            <?php esc_html_e('Let it run', 'enhanced-content-plugin'); ?>
                                        </button>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php self::render_results_panel(); ?>

                    <?php self::render_questions_panel(); ?>

                    <?php self::render_orphans_panel(); ?>

                    <div class="ecp-panel">
                        <h2><?php esc_html_e('Pages with the most waiting', 'enhanced-content-plugin'); ?></h2>
                        <?php $pending_posts = ECP_Proposals::pending_posts(8); ?>

                        <?php if (!$pending_posts) : ?>
                            <p class="ecp-muted"><?php esc_html_e('Nothing waiting. The agent will add to this as it works through your content.', 'enhanced-content-plugin'); ?></p>
                        <?php else : ?>
                            <table class="widefat striped">
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e('Page', 'enhanced-content-plugin'); ?></th>
                                        <th><?php esc_html_e('Changes', 'enhanced-content-plugin'); ?></th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pending_posts as $row) : ?>
                                        <tr>
                                            <td><strong><?php echo esc_html($row['post_title']); ?></strong></td>
                                            <td>
                                                <?php if ((int) $row['safe']) : ?>
                                                    <span class="ecp-chip ecp-chip-safe"><?php echo esc_html((int) $row['safe']); ?> <?php esc_html_e('safe', 'enhanced-content-plugin'); ?></span>
                                                <?php endif; ?>
                                                <?php if ((int) $row['moderate']) : ?>
                                                    <span class="ecp-chip ecp-chip-moderate"><?php echo esc_html((int) $row['moderate']); ?></span>
                                                <?php endif; ?>
                                                <?php if ((int) $row['sensitive']) : ?>
                                                    <span class="ecp-chip ecp-chip-sensitive"><?php echo esc_html((int) $row['sensitive']); ?> <?php esc_html_e('to check', 'enhanced-content-plugin'); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="ecp-cell-action">
                                                <a class="button button-small button-primary"
                                                   href="<?php echo esc_url(add_query_arg(array('page' => 'ecp-review', 'post' => (int) $row['post_id']), admin_url('admin.php'))); ?>">
                                                    <?php esc_html_e('Review', 'enhanced-content-plugin'); ?>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>

                    <div class="ecp-panel">
                        <h2><?php esc_html_e('Recent agent activity', 'enhanced-content-plugin'); ?></h2>
                        <?php $events = ECP_Log::query(array('per_page' => 10)); ?>

                        <?php if (!$events['items']) : ?>
                            <p class="ecp-muted"><?php esc_html_e('Nothing yet.', 'enhanced-content-plugin'); ?></p>
                        <?php else : ?>
                            <ul class="ecp-activity">
                                <?php foreach ($events['items'] as $event) : ?>
                                    <li class="ecp-activity-<?php echo esc_attr($event['level']); ?>">
                                        <span class="ecp-activity-time">
                                            <?php
                                            printf(
                                                /* translators: %s: human-readable time difference */
                                                esc_html__('%s ago', 'enhanced-content-plugin'),
                                                esc_html(human_time_diff(strtotime($event['created_at']), (int) current_time('timestamp')))
                                            );
                                            ?>
                                        </span>
                                        <span class="ecp-activity-message"><?php echo esc_html($event['message']); ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <p><a href="<?php echo esc_url(admin_url('admin.php?page=ecp-history')); ?>"><?php esc_html_e('Full history', 'enhanced-content-plugin'); ?> &rarr;</a></p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="ecp-col-side">
                    <?php self::render_status_panel($budget, $search); ?>
                    <?php if (ECP_Capabilities::can_review()) : ?>
                        <?php self::render_actions_panel(); ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /* --------------------------------------------------------------------
     * Panels
     * ----------------------------------------------------------------- */

    /**
     * Things the agent needs you to tell it.
     *
     * The counterpart to "never invent a fact": when the gap analysis finds
     * a question it cannot honestly answer — a price, a warranty term, a
     * lead time — it asks here instead of guessing. Answer once and it
     * becomes a verified fact the agent may use from then on.
     */
    /**
     * One honest sentence about where things stand.
     *
     * Templated from real numbers, never generated — a greeting that costs
     * an AI call would be this product arguing against itself.
     */
    private static function render_narrative(array $opportunities, array $counts) {
        $pending = isset($counts['pending']) ? (int) $counts['pending'] : 0;
        $open = (int) $opportunities['open'];

        if (0 === $open && 0 === $pending) {
            return;
        }

        $parts = array();

        if ($open > 0) {
            $parts[] = sprintf(
                /* translators: %s: count */
                _n(
                    'RankAudit sees %s page with a meaningful opportunity',
                    'RankAudit sees %s pages with meaningful opportunities',
                    $open,
                    'enhanced-content-plugin'
                ),
                number_format_i18n($open)
            );
        }

        if ($pending > 0) {
            $parts[] = sprintf(
                /* translators: %s: count */
                _n(
                    '%s prepared change is waiting for your decision',
                    '%s prepared changes are waiting for your decision',
                    $pending,
                    'enhanced-content-plugin'
                ),
                number_format_i18n($pending)
            );
        }

        ?>
        <p class="ecp-narrative"><?php echo esc_html(implode(__(', and ', 'enhanced-content-plugin'), $parts) . '.'); ?></p>
        <?php
    }

    /**
     * Today's Priority: the one action most worth taking, with the reason
     * and the evidence, and the right to say "not today".
     */
    private static function render_priority() {
        $priority = ECP_Opportunity_Engine::top_priority();

        if (!$priority || !ECP_Capabilities::can_review((int) $priority['post_id'])) {
            return;
        }

        $metrics = ECP_Search_Data::page_metrics((int) $priority['post_id']);
        ?>
        <div class="ecp-panel ecp-priority-card">
            <h2><?php esc_html_e("Today's priority", 'enhanced-content-plugin'); ?></h2>

            <p class="ecp-priority-headline">
                <strong><?php echo esc_html($priority['post_title']); ?></strong>
                — <?php echo esc_html(ECP_Opportunity_Engine::reason_label($priority['primary_reason'])); ?>
            </p>

            <?php if ($metrics) : ?>
                <p class="ecp-muted">
                    <?php
                    printf(
                        /* translators: 1: impressions, 2: clicks, 3: position */
                        esc_html__('Last 28 days: %1$s impressions, %2$s clicks, average position %3$s.', 'enhanced-content-plugin'),
                        esc_html(number_format_i18n((int) $metrics['impressions'])),
                        esc_html(number_format_i18n((int) $metrics['clicks'])),
                        esc_html(number_format_i18n((float) $metrics['position'], 1))
                    );
                    ?>
                </p>
            <?php endif; ?>

            <?php if ((float) $priority['potential_clicks'] > 0) : ?>
                <p class="ecp-muted">
                    <?php
                    printf(
                        /* translators: %s: click estimate */
                        esc_html__('Roughly %s additional monthly clicks within reach. Directional estimate, modelled from Search Console impressions and positions — not a promise.', 'enhanced-content-plugin'),
                        esc_html(number_format_i18n((int) round((float) $priority['potential_clicks'])))
                    );
                    ?>
                </p>
            <?php endif; ?>

            <p class="ecp-priority-actions">
                <a class="button button-primary"
                   href="<?php echo esc_url(add_query_arg(array('page' => 'ecp-opportunities', 's' => rawurlencode($priority['post_title'])), admin_url('admin.php'))); ?>">
                    <?php esc_html_e('Review this opportunity', 'enhanced-content-plugin'); ?>
                </a>
                <button type="button" class="button ecp-priority-snooze" data-post="<?php echo esc_attr($priority['post_id']); ?>">
                    <?php esc_html_e('Postpone a week', 'enhanced-content-plugin'); ?>
                </button>
                <button type="button" class="button-link ecp-priority-dismiss" data-post="<?php echo esc_attr($priority['post_id']); ?>">
                    <?php esc_html_e('Dismiss', 'enhanced-content-plugin'); ?>
                </button>
                <span class="ecp-priority-status" aria-live="polite"></span>
            </p>
        </div>
        <?php
    }

    /**
     * Did the approved changes actually work?
     *
     * This is the panel the whole product is for. Approving changes is an
     * act of faith until something comes back and says what happened — and
     * everything here is phrased as correlation, because a ranking that
     * moved after an edit is evidence, not proof.
     */
    private static function render_results_panel() {
        $summary = ECP_Measurement::summary();
        $awaiting = ECP_Measurement::awaiting_count();

        if (!$summary && !$awaiting) {
            return;   // Nothing applied yet — no panel beats an empty one.
        }
        ?>
        <div class="ecp-panel ecp-panel-results">
            <h2><?php esc_html_e('Results of applied changes', 'enhanced-content-plugin'); ?></h2>

            <?php if ($summary) : ?>
                <p class="ecp-results-headline">
                    <?php
                    printf(
                        /* translators: 1: measured count, 2: improved count */
                        esc_html__('%1$d changes measured so far — %2$d correlate with improvement.', 'enhanced-content-plugin'),
                        (int) $summary['measured'],
                        (int) $summary['improving']
                    );

                    if ($summary['clicks_gained'] > 0) {
                        echo ' ';
                        printf(
                            /* translators: %s: click count */
                            esc_html__('Pages that improved are earning roughly %s more clicks per period than before their changes.', 'enhanced-content-plugin'),
                            esc_html(number_format_i18n((int) $summary['clicks_gained']))
                        );
                    }
                    ?>
                </p>

                <div class="ecp-results-grid">
                    <div class="ecp-results-cell is-good">
                        <span class="ecp-results-number"><?php echo esc_html(number_format_i18n((int) $summary['improving'])); ?></span>
                        <span class="ecp-results-label"><?php esc_html_e('Improved', 'enhanced-content-plugin'); ?></span>
                    </div>
                    <div class="ecp-results-cell">
                        <span class="ecp-results-number"><?php echo esc_html(number_format_i18n((int) $summary['stable'])); ?></span>
                        <span class="ecp-results-label"><?php esc_html_e('No clear change', 'enhanced-content-plugin'); ?></span>
                    </div>
                    <div class="ecp-results-cell is-bad">
                        <span class="ecp-results-number"><?php echo esc_html(number_format_i18n((int) $summary['declining'])); ?></span>
                        <span class="ecp-results-label"><?php esc_html_e('Declined', 'enhanced-content-plugin'); ?></span>
                    </div>
                    <div class="ecp-results-cell">
                        <span class="ecp-results-number"><?php echo esc_html(number_format_i18n((int) $summary['too_early'] + $awaiting)); ?></span>
                        <span class="ecp-results-label"><?php esc_html_e('Too early to tell', 'enhanced-content-plugin'); ?></span>
                    </div>
                </div>

                <?php if ((int) $summary['declining'] > 0) : ?>
                    <p class="ecp-muted">
                        <?php esc_html_e('Declines are worth a look in History — every applied change there can be undone with one click.', 'enhanced-content-plugin'); ?>
                    </p>
                <?php endif; ?>

                <p class="ecp-muted">
                    <?php esc_html_e('Measured at 7, 14, 28, 56 and 90 days after each change, against the Search Console baseline captured when it was applied. "Improved" means the page did better afterwards — the change is the likeliest reason, not a proven one.', 'enhanced-content-plugin'); ?>
                </p>
            <?php else : ?>
                <p class="ecp-muted">
                    <?php
                    printf(
                        /* translators: %d: number of changes awaiting measurement */
                        esc_html(_n(
                            '%d applied change is being tracked. Its first performance check runs 7 days after it went live.',
                            '%d applied changes are being tracked. The first performance check runs 7 days after each went live.',
                            $awaiting,
                            'enhanced-content-plugin'
                        )),
                        (int) $awaiting
                    );
                    ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function render_questions_panel() {
        $questions = ECP_Content_Gaps::open_questions(8);

        if (!$questions) {
            return;
        }

        ?>
        <div class="ecp-panel ecp-panel-questions">
            <h2><?php esc_html_e('Questions only you can answer', 'enhanced-content-plugin'); ?></h2>
            <p>
                <?php esc_html_e('Readers arrive wanting to know these things, and the agent will not make them up. Tell it once and it can use the answer from then on.', 'enhanced-content-plugin'); ?>
            </p>

            <ul class="ecp-question-list">
                <?php foreach ($questions as $item) : ?>
                    <li>
                        <div class="ecp-question-head">
                            <strong><?php echo esc_html($item['question']); ?></strong>
                            <?php if ($item['backed']) : ?>
                                <span class="ecp-chip ecp-chip-safe"><?php esc_html_e('people search for this', 'enhanced-content-plugin'); ?></span>
                            <?php endif; ?>
                        </div>

                        <?php if ($item['needed']) : ?>
                            <p class="ecp-muted"><?php echo esc_html($item['needed']); ?></p>
                        <?php endif; ?>

                        <p class="ecp-muted">
                            <?php esc_html_e('For:', 'enhanced-content-plugin'); ?>
                            <a href="<?php echo esc_url(get_edit_post_link($item['post_id'])); ?>">
                                <?php echo esc_html($item['post_title']); ?>
                            </a>
                        </p>

                        <?php if (ECP_Capabilities::can_review($item['post_id'])) : ?>
                            <div class="ecp-answer-row">
                                <label class="screen-reader-text" for="ecp-answer-<?php echo esc_attr($item['post_id']); ?>-<?php echo esc_attr(md5($item['question'])); ?>">
                                    <?php echo esc_html($item['question']); ?>
                                </label>
                                <input type="text"
                                       id="ecp-answer-<?php echo esc_attr($item['post_id']); ?>-<?php echo esc_attr(md5($item['question'])); ?>"
                                       class="ecp-answer-field regular-text"
                                       data-post="<?php echo esc_attr($item['post_id']); ?>"
                                       data-question="<?php echo esc_attr($item['question']); ?>"
                                       placeholder="<?php esc_attr_e('Your answer…', 'enhanced-content-plugin'); ?>">
                                <button type="button" class="button ecp-save-answer"><?php esc_html_e('Save', 'enhanced-content-plugin'); ?></button>
                                <span class="ecp-answer-status" aria-live="polite"></span>
                            </div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
    }

    /**
     * Pages nothing links to.
     *
     * The plugin has reported orphans since the first version and offered no
     * way to fix them, because every link change it knew how to make added a
     * link *from* the page you were looking at. The fix is the other way
     * round.
     */
    private static function render_orphans_panel() {
        if (!ECP_Capabilities::can_review()) {
            return;
        }

        $orphans = ECP_Link_Suggestions::orphans(6);

        if (!$orphans) {
            return;
        }

        ?>
        <div class="ecp-panel">
            <h2><?php esc_html_e('Pages nothing links to', 'enhanced-content-plugin'); ?></h2>
            <p class="ecp-muted">
                <?php esc_html_e('Orphaned pages get crawled less and rank worse. The agent can find existing articles that already mention the topic and propose linking the phrase — no new writing, and nothing invented.', 'enhanced-content-plugin'); ?>
            </p>

            <table class="widefat striped">
                <tbody>
                    <?php foreach ($orphans as $orphan) : ?>
                        <tr>
                            <td>
                                <strong><a href="<?php echo esc_url(get_edit_post_link($orphan['post_id'])); ?>"><?php echo esc_html($orphan['post_title']); ?></a></strong>
                                <?php if ($orphan['impressions']) : ?>
                                    <div class="ecp-row-meta">
                                        <?php
                                        printf(
                                            /* translators: %s: impressions */
                                            esc_html__('%s impressions and no internal links — the most to gain here.', 'enhanced-content-plugin'),
                                            esc_html(number_format_i18n($orphan['impressions']))
                                        );
                                        ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="ecp-cell-action">
                                <button type="button" class="button button-small ecp-build-links"
                                        data-post="<?php echo esc_attr($orphan['post_id']); ?>">
                                    <?php esc_html_e('Find links', 'enhanced-content-plugin'); ?>
                                </button>
                                <div class="ecp-row-status" aria-live="polite"></div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private static function render_checklist(array $steps, $done) {
        ?>
        <div class="ecp-panel ecp-panel-setup">
            <h2>
                <?php esc_html_e('Setup', 'enhanced-content-plugin'); ?>
                <span class="ecp-muted">
                    <?php
                    printf(
                        /* translators: 1: completed steps, 2: total steps */
                        esc_html__('%1$d of %2$d done', 'enhanced-content-plugin'),
                        (int) $done,
                        count($steps)
                    );
                    ?>
                </span>
            </h2>

            <p><?php esc_html_e('The agent will not write anything to your site until you approve it. Even so, work through these before turning it on.', 'enhanced-content-plugin'); ?></p>

            <ol class="ecp-checklist">
                <?php foreach ($steps as $step) : ?>
                    <li class="<?php echo $step['done'] ? 'is-done' : ''; ?>">
                        <span class="ecp-check" aria-hidden="true"><?php echo $step['done'] ? '✓' : ''; ?></span>
                        <div>
                            <strong><?php echo esc_html($step['label']); ?></strong>
                            <?php if (!empty($step['help'])) : ?>
                                <p class="ecp-muted"><?php echo esc_html($step['help']); ?></p>
                            <?php endif; ?>
                        </div>
                        <?php if (!$step['done']) : ?>
                            <a class="button button-small" href="<?php echo esc_url($step['action_url']); ?>">
                                <?php esc_html_e('Set up', 'enhanced-content-plugin'); ?>
                            </a>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        </div>
        <?php
    }

    private static function render_status_panel(array $budget, array $search) {
        $next = ECP_Scheduler::next_runs();
        ?>
        <div class="ecp-panel">
            <h2><?php esc_html_e('Status', 'enhanced-content-plugin'); ?></h2>

            <dl class="ecp-status-list">
                <dt><?php esc_html_e('Agent', 'enhanced-content-plugin'); ?></dt>
                <dd>
                    <?php if (ECP_Agent_Settings::is_ready()) : ?>
                        <span class="ecp-dot ecp-dot-on"></span> <?php esc_html_e('Running', 'enhanced-content-plugin'); ?>
                    <?php elseif (ECP_Agent_Settings::is_on('agent_enabled')) : ?>
                        <span class="ecp-dot ecp-dot-warn"></span> <?php esc_html_e('On, but no AI provider', 'enhanced-content-plugin'); ?>
                    <?php else : ?>
                        <span class="ecp-dot ecp-dot-off"></span> <?php esc_html_e('Off', 'enhanced-content-plugin'); ?>
                    <?php endif; ?>
                </dd>

                <dt><?php esc_html_e('Approval', 'enhanced-content-plugin'); ?></dt>
                <dd>
                    <?php
                    $mode = ECP_Agent_Settings::get('approval_mode', 'always');
                    $labels = array(
                        'always'  => __('You approve everything', 'enhanced-content-plugin'),
                        'safe'    => __('Safe changes apply automatically', 'enhanced-content-plugin'),
                        'trusted' => __('Trusted change types apply automatically', 'enhanced-content-plugin'),
                    );
                    echo esc_html(isset($labels[$mode]) ? $labels[$mode] : $mode);
                    ?>
                </dd>

                <dt><?php esc_html_e('Search data', 'enhanced-content-plugin'); ?></dt>
                <dd><?php echo esc_html($search['label']); ?></dd>

                <?php if ($budget['priced'] && $budget['monthly_cap'] > 0) : ?>
                    <dt><?php esc_html_e('AI spend this month', 'enhanced-content-plugin'); ?></dt>
                    <dd>
                        <?php
                        printf(
                            esc_html__('$%1$.2f of $%2$.2f', 'enhanced-content-plugin'),
                            (float) $budget['monthly_spent'],
                            (float) $budget['monthly_cap']
                        );
                        ?>
                        <div class="ecp-meter">
                            <div class="ecp-meter-fill<?php echo $budget['monthly_pct'] >= 90 ? ' is-high' : ''; ?>"
                                 style="width:<?php echo esc_attr((int) $budget['monthly_pct']); ?>%"></div>
                        </div>
                    </dd>
                <?php endif; ?>

                <dt><?php esc_html_e('Analyses today', 'enhanced-content-plugin'); ?></dt>
                <dd>
                    <?php
                    printf(
                        esc_html__('%1$d of %2$d', 'enhanced-content-plugin'),
                        (int) $budget['daily_used'],
                        (int) $budget['daily_cap']
                    );
                    ?>
                </dd>

                <dt><?php esc_html_e('Next scan', 'enhanced-content-plugin'); ?></dt>
                <dd>
                    <?php
                    echo $next['scan']
                        ? esc_html(human_time_diff((int) $next['scan']))
                        : esc_html__('not scheduled', 'enhanced-content-plugin');
                    ?>
                </dd>
            </dl>

            <?php self::render_automation($budget); ?>
        </div>
        <?php
    }

    /**
     * Does the agent analyse pages on its own, or is it waiting to be asked?
     *
     * Scanning and analysing are separate switches, and the difference between
     * them is invisible from the queue: a full opportunity list looks the same
     * whether analysis is running slowly or not running at all.
     */
    private static function render_automation(array $budget) {
        $auto = ECP_Scheduler::automation_status();
        ?>
        <div class="ecp-automation">
            <h3>
                <span class="ecp-dot <?php echo $auto['running'] ? 'ecp-dot-on' : 'ecp-dot-warn'; ?>"></span>
                <?php
                echo $auto['running']
                    ? esc_html__('Analysing on its own', 'enhanced-content-plugin')
                    : esc_html__('Waiting for you to ask', 'enhanced-content-plugin');
                ?>
            </h3>

            <?php if ($auto['running']) : ?>
                <p class="ecp-muted">
                    <?php
                    if ((int) $auto['per_day'] > 0) {
                        printf(
                            /* translators: 1: analyses per hour, 2: daily cap, 3: used today */
                            esc_html__('Up to %1$d an hour, stopping at %2$d a day so the queue arrives in batches you can review rather than all at once. %3$d used today.', 'enhanced-content-plugin'),
                            (int) $auto['per_hour'],
                            (int) $auto['per_day'],
                            (int) $budget['daily_used']
                        );
                    } else {
                        printf(
                            /* translators: %d: analyses per hour */
                            esc_html__('Up to %d an hour, with no daily limit set.', 'enhanced-content-plugin'),
                            (int) $auto['per_hour']
                        );
                    }
                    ?>
                </p>
                <?php if ($auto['next']) : ?>
                    <p class="ecp-muted">
                        <?php
                        printf(
                            /* translators: %s: human-readable time difference */
                            esc_html__('Next batch in %s.', 'enhanced-content-plugin'),
                            esc_html(human_time_diff((int) $auto['next']))
                        );
                        ?>
                    </p>
                <?php endif; ?>
            <?php else : ?>
                <p class="ecp-muted">
                    <?php esc_html_e('Pages are being scored, but nothing is being analysed automatically:', 'enhanced-content-plugin'); ?>
                </p>
                <ul class="ecp-reasons">
                    <?php foreach ($auto['reasons'] as $reason) : ?>
                        <li><?php echo esc_html($reason); ?></li>
                    <?php endforeach; ?>
                </ul>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=ecp-agent-settings')); ?>">
                    <?php esc_html_e('Open settings', 'enhanced-content-plugin'); ?>
                </a>
            <?php endif; ?>

            <?php if (ECP_Refresh::enabled()) : ?>
                <?php $refresh_queue = ECP_Refresh::queue_status(); ?>
                <p class="ecp-muted">
                    <?php
                    if ($refresh_queue['waiting'] > 0) {
                        printf(
                            /* translators: 1: count of held changes, 2: human time until the first applies */
                            esc_html(_n(
                                'Refresh cycle: %1$d change from aging articles is in its review window%2$s. Veto it in Review Changes, or let it apply.',
                                'Refresh cycle: %1$d changes from aging articles are in their review window%2$s. Veto any in Review Changes, or let them apply.',
                                $refresh_queue['waiting'],
                                'enhanced-content-plugin'
                            )),
                            (int) $refresh_queue['waiting'],
                            $refresh_queue['next_at']
                                ? esc_html(sprintf(
                                    /* translators: %s: human-readable time */
                                    __(' — first applies in %s', 'enhanced-content-plugin'),
                                    human_time_diff((int) $refresh_queue['next_at'])
                                ))
                                : ''
                        );
                    } else {
                        esc_html_e('Refresh cycle: on. Aging articles get small automatic improvements nightly; nothing is waiting right now.', 'enhanced-content-plugin');
                    }
                    ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function render_actions_panel() {
        ?>
        <div class="ecp-panel">
            <h2><?php esc_html_e('Run something now', 'enhanced-content-plugin'); ?></h2>

            <p>
                <button type="button" class="button button-primary" id="ecp-run-scan">
                    <?php esc_html_e('Scan content', 'enhanced-content-plugin'); ?>
                </button>
            </p>
            <p class="ecp-muted"><?php esc_html_e('Rescores every page. Free — no AI calls.', 'enhanced-content-plugin'); ?></p>
            <p class="ecp-scan-progress" aria-live="polite"></p>

            <hr>

            <p>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=ecp-opportunities')); ?>">
                    <?php esc_html_e('Pick a page to analyze', 'enhanced-content-plugin'); ?>
                </a>
            </p>
            <p class="ecp-muted"><?php esc_html_e('Analysis is what costs money. You choose which pages, or let the schedule work through the queue.', 'enhanced-content-plugin'); ?></p>
        </div>
        <?php
    }

    private static function stat($value, $label, $url = '', $variant = '', $tooltip = '') {
        $tag = $url ? 'a' : 'div';

        printf(
            '<%1$s class="ecp-stat%2$s"%3$s%4$s>',
            esc_attr($tag),
            $variant ? ' ecp-stat-' . esc_attr($variant) : '',
            $url ? ' href="' . esc_url($url) . '"' : '',
            $tooltip ? ' title="' . esc_attr($tooltip) . '"' : ''
        );

        printf(
            '<span class="ecp-stat-number">%s</span><span class="ecp-stat-label">%s</span>',
            esc_html(number_format_i18n($value)),
            esc_html($label)
        );

        printf('</%s>', esc_attr($tag));
    }
}
