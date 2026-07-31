<?php
/**
 * The agent, present where the writing happens.
 *
 * A sidebar box on the post edit screen: what the agent thinks of this
 * page, what is already waiting in the review queue for it, and the
 * one button that matters — prepare changes for review — without
 * leaving the editor. The plugin's screens are where strategy lives;
 * this box is for the moment someone is already looking at a page and
 * wonders what the agent would do with it.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ECP_Editor_Assist {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        add_action('add_meta_boxes', array($this, 'register'));
    }

    public function register($post_type) {
        if (!in_array($post_type, (array) ECP_Agent_Settings::get('post_types', array('post')), true)) {
            return;
        }

        if (!ECP_Capabilities::can_view()) {
            return;
        }

        add_meta_box(
            'ecp-editor-assist',
            __('Enhanced Content agent', 'enhanced-content-plugin'),
            array($this, 'render'),
            $post_type,
            'side',
            'high'
        );
    }

    public function render($post) {
        global $wpdb;

        if ('publish' !== $post->post_status) {
            ?>
            <p class="ecp-muted">
                <?php esc_html_e('The agent works on published content — once this is live and scanned, its score, issues and improvement tools appear here.', 'enhanced-content-plugin'); ?>
            </p>
            <p class="ecp-muted">
                <?php
                printf(
                    /* translators: %s: link to the Topical Map */
                    esc_html__('Writing something new? The %s decides what deserves to exist and drafts it from an approved brief.', 'enhanced-content-plugin'),
                    '<a href="' . esc_url(admin_url('admin.php?page=ecp-map')) . '">' . esc_html__('Create New flow', 'enhanced-content-plugin') . '</a>'
                );
                ?>
            </p>
            <?php
            return;
        }

        $opportunity = ECP_Opportunity_Engine::get((int) $post->ID);

        $pending = 0;
        $applied = 0;

        if (ECP_DB::tables_exist()) {
            $counts = $wpdb->get_row($wpdb->prepare(
                'SELECT SUM(status = %s) AS pending, SUM(status = %s) AS applied
                   FROM ' . ECP_DB::proposals_table() . '
                  WHERE post_id = %d',
                ECP_Proposals::PENDING,
                ECP_Proposals::APPLIED,
                (int) $post->ID
            ), ARRAY_A);

            $pending = (int) $counts['pending'];
            $applied = (int) $counts['applied'];
        }

        ?>
        <div class="ecp-priority-card ecp-editor-assist">

            <?php if ($opportunity) : ?>
                <p>
                    <strong>
                        <?php
                        printf(
                            /* translators: %s: opportunity score */
                            esc_html__('Opportunity score: %s', 'enhanced-content-plugin'),
                            esc_html(number_format_i18n((float) $opportunity['score'], 1))
                        );
                        ?>
                    </strong><br>
                    <span class="ecp-muted"><?php echo esc_html(ECP_Opportunity_Engine::reason_label($opportunity['primary_reason'])); ?></span>
                </p>

                <?php $issues = is_array($opportunity['reasons']) ? array_slice($opportunity['reasons'], 0, 3) : array(); ?>
                <?php if ($issues) : ?>
                    <ul class="ecp-assist-issues">
                        <?php foreach ($issues as $issue) : ?>
                            <li><?php echo esc_html(isset($issue['label']) ? $issue['label'] : ECP_Opportunity_Engine::reason_label($issue['code'])); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php else : ?>
                <p class="ecp-muted"><?php esc_html_e('Not scanned yet. The hourly scan will score this page, or run one from the dashboard.', 'enhanced-content-plugin'); ?></p>
            <?php endif; ?>

            <?php if ($pending > 0) : ?>
                <p>
                    <a class="button" href="<?php echo esc_url(add_query_arg(array('page' => 'ecp-review', 'post' => (int) $post->ID), admin_url('admin.php'))); ?>">
                        <?php
                        printf(
                            /* translators: %d: number of pending changes */
                            esc_html(_n('Review %d waiting change', 'Review %d waiting changes', $pending, 'enhanced-content-plugin')),
                            $pending
                        );
                        ?>
                    </a>
                </p>
            <?php endif; ?>

            <?php if (ECP_Capabilities::can_analyze((int) $post->ID) && ECP_Agent_Settings::is_ready()) : ?>
                <p>
                    <button type="button" class="button button-primary ecp-build-plan" data-post="<?php echo esc_attr($post->ID); ?>"
                            title="<?php esc_attr_e('Runs one AI analysis of this page and queues its suggested edits in Review Changes. Nothing is applied until you approve each one.', 'enhanced-content-plugin'); ?>">
                        <?php esc_html_e('Prepare changes for my review', 'enhanced-content-plugin'); ?>
                    </button>
                    <span class="ecp-priority-status" aria-live="polite"></span>
                </p>
            <?php endif; ?>

            <?php if ($applied > 0) : ?>
                <p class="ecp-muted">
                    <?php
                    printf(
                        /* translators: %d: number of applied changes */
                        esc_html(_n('%d agent change applied to this page so far — each undoable from History.', '%d agent changes applied to this page so far — each undoable from History.', $applied, 'enhanced-content-plugin')),
                        $applied
                    );
                    ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }
}
