<?php
/**
 * The email that keeps the review queue from becoming a graveyard.
 *
 * An approval workflow only works if somebody comes back to it. The digest
 * exists to make returning worthwhile: what is waiting, what was published,
 * and — the part that earns the next click — how the last batch performed.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ECP_Digest {

    const HOOK = 'ecp_digest_cron';

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        add_action(self::HOOK, array($this, 'send'));
        add_action('init', array(__CLASS__, 'reschedule'), 21);
    }

    /**
     * Keep the cron entry in step with the frequency setting.
     */
    public static function reschedule() {
        $frequency = (string) ECP_Agent_Settings::get('digest_frequency', 'weekly');
        $enabled = ECP_Agent_Settings::is_on('digest_enabled') && 'off' !== $frequency;
        $scheduled = wp_next_scheduled(self::HOOK);

        if (!$enabled) {
            if ($scheduled) {
                wp_unschedule_event($scheduled, self::HOOK);
            }

            return;
        }

        $recurrence = 'daily' === $frequency ? 'daily' : 'weekly';
        $current = wp_get_schedule(self::HOOK);

        if ($scheduled && $current === $recurrence) {
            return;
        }

        if ($scheduled) {
            wp_unschedule_event($scheduled, self::HOOK);
        }

        // Aim for 8am local on the next run.
        $next = strtotime('tomorrow 08:00', (int) current_time('timestamp'));
        wp_schedule_event($next - (int) (get_option('gmt_offset') * HOUR_IN_SECONDS), $recurrence, self::HOOK);
    }

    /**
     * @return array<int,string>
     */
    public static function recipients() {
        $configured = trim((string) ECP_Agent_Settings::get('digest_recipients', ''));

        if ($configured) {
            $emails = array_filter(array_map('trim', explode(',', $configured)), 'is_email');

            if ($emails) {
                return array_values($emails);
            }
        }

        return array(get_option('admin_email'));
    }

    /**
     * Build and send the digest. Skips silently when there is nothing to say.
     *
     * @return bool Whether an email was sent.
     */
    public function send() {
        if (!ECP_Agent_Settings::is_on('digest_enabled') || !ECP_DB::tables_exist()) {
            return false;
        }

        $data = self::gather();

        // Don't email someone to tell them nothing happened. A weekly
        // send with a plan to talk about is the strategy digest and goes
        // out even when the queue is empty; a daily send stays activity-only
        // so an unchanged roadmap never becomes a daily nag.
        $has_strategy = 7 === (int) $data['window_days'] && !empty($data['roadmap']);

        if (0 === $data['pending'] && 0 === $data['applied_recent'] && !$data['failures'] && !$has_strategy) {
            return false;
        }

        $subject = self::subject($data);
        $body = self::body($data);

        $headers = array('Content-Type: text/html; charset=UTF-8');

        return wp_mail(self::recipients(), $subject, $body, $headers);
    }

    /**
     * Send a one-off copy right now, for the "Send a test" button.
     *
     * @return bool
     */
    public static function send_test() {
        $data = self::gather();

        return wp_mail(
            self::recipients(),
            '[' . get_bloginfo('name') . '] ' . __('Enhanced Content — test digest', 'enhanced-content-plugin'),
            self::body($data),
            array('Content-Type: text/html; charset=UTF-8')
        );
    }

    /* --------------------------------------------------------------------
     * Data
     * ----------------------------------------------------------------- */

    /**
     * @return array
     */
    public static function gather() {
        global $wpdb;

        $window_days = 'daily' === ECP_Agent_Settings::get('digest_frequency', 'weekly') ? 1 : 7;
        $since = gmdate('Y-m-d H:i:s', strtotime("-{$window_days} days", (int) current_time('timestamp', true)));
        $table = ECP_DB::proposals_table();

        $applied_recent = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE status = %s AND applied_at >= %s",
            ECP_Proposals::APPLIED,
            $since
        ));

        $counts = ECP_Proposals::counts();

        $failures = $wpdb->get_results($wpdb->prepare(
            "SELECT message, created_at FROM " . ECP_DB::events_table() . "
             WHERE level = 'error' AND created_at >= %s
             ORDER BY id DESC LIMIT 5",
            $since
        ), ARRAY_A);

        return array(
            'window_days'    => $window_days,
            'pending'        => ECP_Proposals::pending_count(),
            'pending_by_risk' => isset($counts['pending_by_risk']) ? $counts['pending_by_risk'] : array(),
            'applied_recent' => $applied_recent,
            'top_pending'    => ECP_Proposals::pending_posts(5),
            'performance'    => self::performance_highlights(),
            'budget'         => ECP_AI_Client::budget_status(),
            'failures'       => $failures ? $failures : array(),
            'suggestions'    => ECP_Trust_Ladder::suggestions(),
            'roadmap'        => ECP_Roadmap::next_steps(3),
            'roadmap_done'   => ECP_Roadmap::completed_since($since),
            'roadmap_stats'  => ECP_Roadmap::stats(),
        );
    }

    /**
     * Applied changes with enough elapsed time to say something about.
     *
     * @return array[]
     */
    private static function performance_highlights($limit = 5) {
        global $wpdb;

        if (!ECP_Search_Data::is_connected()) {
            return array();
        }

        $cutoff = gmdate('Y-m-d H:i:s', strtotime('-7 days', (int) current_time('timestamp', true)));

        $ids = $wpdb->get_col($wpdb->prepare(
            'SELECT id FROM ' . ECP_DB::proposals_table() . '
             WHERE status = %s AND applied_at <= %s
             ORDER BY applied_at DESC LIMIT 40',
            ECP_Proposals::APPLIED,
            $cutoff
        ));

        $out = array();

        foreach ((array) $ids as $id) {
            $performance = ECP_Applier::performance((int) $id);

            if (!$performance || 'too_early' === $performance['verdict']) {
                continue;
            }

            $proposal = ECP_Proposals::get((int) $id);
            if (!$proposal) {
                continue;
            }

            $out[] = array(
                'post_title'  => get_the_title((int) $proposal['post_id']),
                'post_id'     => (int) $proposal['post_id'],
                'change_type' => ECP_Proposals::type_label($proposal['change_type']),
                'performance' => $performance,
            );

            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /* --------------------------------------------------------------------
     * Rendering
     * ----------------------------------------------------------------- */

    private static function subject(array $data) {
        $site = get_bloginfo('name');

        if ($data['pending'] > 0) {
            return sprintf(
                /* translators: 1: site name, 2: number of changes waiting */
                _n('[%1$s] %2$d content change waiting for you', '[%1$s] %2$d content changes waiting for you', $data['pending'], 'enhanced-content-plugin'),
                $site,
                $data['pending']
            );
        }

        return sprintf(
            /* translators: %s: site name */
            __('[%s] Enhanced Content update', 'enhanced-content-plugin'),
            $site
        );
    }

    private static function body(array $data) {
        $review_url = admin_url('admin.php?page=ecp-review');
        $settings_url = admin_url('admin.php?page=ecp-agent-settings');

        ob_start();
        ?>
        <div style="font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.6;color:#1d2327;max-width:620px;margin:0 auto;">

            <h2 style="font-size:20px;margin:0 0 4px;"><?php echo esc_html(get_bloginfo('name')); ?></h2>
            <p style="margin:0 0 24px;color:#646970;">
                <?php
                printf(
                    /* translators: %d: number of days covered */
                    esc_html(_n('Enhanced Content, last %d day', 'Enhanced Content, last %d days', (int) $data['window_days'], 'enhanced-content-plugin')),
                    (int) $data['window_days']
                );
                ?>
            </p>

            <?php if ($data['pending'] > 0) : ?>
                <div style="background:#f0f6fc;border-left:4px solid #2271b1;padding:16px 18px;margin-bottom:24px;">
                    <p style="margin:0 0 10px;font-size:17px;font-weight:600;">
                        <?php
                        printf(
                            /* translators: %d: number of changes */
                            esc_html(_n('%d change is waiting for your review', '%d changes are waiting for your review', (int) $data['pending'], 'enhanced-content-plugin')),
                            (int) $data['pending']
                        );
                        ?>
                    </p>
                    <?php if (!empty($data['pending_by_risk'])) : ?>
                        <p style="margin:0 0 14px;color:#50575e;font-size:14px;">
                            <?php
                            $parts = array();
                            foreach ($data['pending_by_risk'] as $risk => $count) {
                                $parts[] = sprintf('%d %s', (int) $count, ECP_Proposals::risk_label($risk));
                            }
                            echo esc_html(implode(' · ', $parts));
                            ?>
                        </p>
                    <?php endif; ?>
                    <a href="<?php echo esc_url($review_url); ?>"
                       style="display:inline-block;background:#2271b1;color:#fff;text-decoration:none;padding:9px 18px;border-radius:3px;font-weight:600;">
                        <?php esc_html_e('Review changes', 'enhanced-content-plugin'); ?>
                    </a>
                </div>

                <?php if (!empty($data['top_pending'])) : ?>
                    <h3 style="font-size:15px;margin:0 0 8px;"><?php esc_html_e('Pages with the most waiting', 'enhanced-content-plugin'); ?></h3>
                    <ul style="margin:0 0 24px;padding-left:20px;color:#50575e;">
                        <?php foreach ($data['top_pending'] as $row) : ?>
                            <li style="margin-bottom:4px;">
                                <a href="<?php echo esc_url(add_query_arg('post', (int) $row['post_id'], $review_url)); ?>" style="color:#2271b1;">
                                    <?php echo esc_html($row['post_title']); ?>
                                </a>
                                —
                                <?php
                                printf(
                                    /* translators: %d: number of changes */
                                    esc_html(_n('%d change', '%d changes', (int) $row['total'], 'enhanced-content-plugin')),
                                    (int) $row['total']
                                );
                                ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($data['applied_recent'] > 0) : ?>
                <h3 style="font-size:15px;margin:0 0 8px;"><?php esc_html_e('Published', 'enhanced-content-plugin'); ?></h3>
                <p style="margin:0 0 24px;color:#50575e;">
                    <?php
                    printf(
                        /* translators: %d: number of changes applied */
                        esc_html(_n('%d approved change went live.', '%d approved changes went live.', (int) $data['applied_recent'], 'enhanced-content-plugin')),
                        (int) $data['applied_recent']
                    );
                    ?>
                </p>
            <?php endif; ?>

            <?php if (!empty($data['roadmap'])) : ?>
                <h3 style="font-size:15px;margin:0 0 8px;"><?php esc_html_e('The plan', 'enhanced-content-plugin'); ?></h3>
                <?php if (!empty($data['roadmap_done'])) : ?>
                    <p style="margin:0 0 8px;color:#50575e;">
                        <?php
                        printf(
                            /* translators: %d: number of completed roadmap steps */
                            esc_html(_n('%d roadmap step was completed in this period. Next up:', '%d roadmap steps were completed in this period. Next up:', (int) $data['roadmap_done'], 'enhanced-content-plugin')),
                            (int) $data['roadmap_done']
                        );
                        ?>
                    </p>
                <?php endif; ?>
                <ol style="margin:0 0 8px;padding-left:20px;color:#50575e;">
                    <?php foreach ($data['roadmap'] as $step) : ?>
                        <li style="margin-bottom:6px;">
                            <strong><?php echo esc_html($step['title']); ?></strong>
                            <?php if (isset($step['why']['primary']) && $step['why']['primary']) : ?>
                                — <?php echo esc_html(ECP_Opportunity_Engine::reason_label($step['why']['primary'])); ?>
                            <?php endif; ?>
                            <?php if ((float) $step['potential_clicks'] > 0) : ?>
                                <span style="color:#646970;font-size:13px;">
                                    <?php
                                    printf(
                                        /* translators: %s: click estimate */
                                        esc_html__('(worth roughly %s extra monthly clicks — an estimate, not a promise)', 'enhanced-content-plugin'),
                                        esc_html(number_format_i18n((int) round((float) $step['potential_clicks'])))
                                    );
                                    ?>
                                </span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
                <p style="margin:0 0 24px;">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=ecp-roadmap')); ?>" style="color:#2271b1;">
                        <?php
                        printf(
                            /* translators: %d: number of active roadmap steps */
                            esc_html(_n('See the full plan — %d step', 'See the full plan — %d steps', (int) $data['roadmap_stats']['active'], 'enhanced-content-plugin')),
                            (int) $data['roadmap_stats']['active']
                        );
                        ?>
                    </a>
                </p>
            <?php endif; ?>

            <?php if (!empty($data['performance'])) : ?>
                <h3 style="font-size:15px;margin:0 0 8px;"><?php esc_html_e('How earlier changes are doing', 'enhanced-content-plugin'); ?></h3>
                <p style="margin:0 0 8px;color:#646970;font-size:13px;">
                    <?php esc_html_e('Search performance since each change went live. Movement after a change is not proof the change caused it.', 'enhanced-content-plugin'); ?>
                </p>
                <ul style="margin:0 0 24px;padding-left:20px;color:#50575e;">
                    <?php foreach ($data['performance'] as $item) : ?>
                        <li style="margin-bottom:6px;">
                            <strong><?php echo esc_html($item['post_title']); ?></strong> —
                            <?php echo esc_html(ECP_Applier::verdict_label($item['performance']['verdict'])); ?>
                            <?php
                            printf(
                                ' (%s%d %s, %s%.1f %s)',
                                $item['performance']['clicks_delta'] >= 0 ? '+' : '',
                                (int) $item['performance']['clicks_delta'],
                                esc_html__('clicks', 'enhanced-content-plugin'),
                                $item['performance']['position_delta'] >= 0 ? '+' : '',
                                (float) $item['performance']['position_delta'],
                                esc_html__('positions', 'enhanced-content-plugin')
                            );
                            ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if (!empty($data['suggestions'])) : ?>
                <div style="background:#f6f7f7;border:1px solid #dcdcde;padding:14px 16px;margin-bottom:24px;">
                    <p style="margin:0 0 8px;font-weight:600;"><?php esc_html_e('Ready to save you time', 'enhanced-content-plugin'); ?></p>
                    <p style="margin:0 0 10px;color:#50575e;font-size:14px;">
                        <?php esc_html_e('You have approved these kinds of change consistently. You can let the agent apply them without asking:', 'enhanced-content-plugin'); ?>
                    </p>
                    <ul style="margin:0 0 10px;padding-left:20px;color:#50575e;">
                        <?php foreach ($data['suggestions'] as $type => $stats) : ?>
                            <li>
                                <?php echo esc_html(ECP_Proposals::type_label($type)); ?>
                                — <?php printf(esc_html__('%1$d approved, %2$d rejected', 'enhanced-content-plugin'), (int) $stats['approved'], (int) $stats['rejected']); ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <a href="<?php echo esc_url($settings_url . '#ecp-section-approval'); ?>" style="color:#2271b1;">
                        <?php esc_html_e('Turn on auto-apply for these', 'enhanced-content-plugin'); ?>
                    </a>
                </div>
            <?php endif; ?>

            <?php if (!empty($data['failures'])) : ?>
                <h3 style="font-size:15px;margin:0 0 8px;color:#d63638;"><?php esc_html_e('Problems', 'enhanced-content-plugin'); ?></h3>
                <ul style="margin:0 0 24px;padding-left:20px;color:#50575e;">
                    <?php foreach ($data['failures'] as $failure) : ?>
                        <li style="margin-bottom:4px;"><?php echo esc_html($failure['message']); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if (!empty($data['budget']['priced']) && $data['budget']['monthly_cap'] > 0) : ?>
                <p style="margin:0 0 24px;color:#646970;font-size:13px;">
                    <?php
                    printf(
                        /* translators: 1: amount spent, 2: monthly cap */
                        esc_html__('AI spend this month: $%1$.2f of $%2$.2f.', 'enhanced-content-plugin'),
                        (float) $data['budget']['monthly_spent'],
                        (float) $data['budget']['monthly_cap']
                    );
                    ?>
                </p>
            <?php endif; ?>

            <hr style="border:none;border-top:1px solid #dcdcde;margin:24px 0 12px;">
            <p style="margin:0;color:#8c8f94;font-size:12px;">
                <?php esc_html_e('Sent by the Enhanced Content plugin. No change is ever published without your approval unless you have explicitly enabled auto-apply.', 'enhanced-content-plugin'); ?>
                <a href="<?php echo esc_url($settings_url); ?>" style="color:#8c8f94;"><?php esc_html_e('Change email settings', 'enhanced-content-plugin'); ?></a>
            </p>
        </div>
        <?php

        return (string) ob_get_clean();
    }
}
