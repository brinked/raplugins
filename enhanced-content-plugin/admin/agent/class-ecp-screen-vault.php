<?php
/**
 * Knowledge Vault: everything the agent has been told is true, in one
 * place the owner can read, correct and retire.
 *
 * The trust argument for the whole product lives on this screen. "The
 * AI never invents your facts" is a promise; a table showing exactly
 * which facts it has, who confirmed them and when, is proof. Facts not
 * confirmed for a year are flagged for re-confirmation rather than
 * silently treated as current.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ECP_Screen_Vault {

    public static function render() {
        if (!ECP_Capabilities::can_view()) {
            wp_die(esc_html__('You do not have permission to view this page.', 'enhanced-content-plugin'));
        }

        $active = ECP_Vault::query(array('status' => ECP_Vault::ACTIVE, 'limit' => 100));
        $retired = ECP_Vault::query(array('status' => ECP_Vault::RETIRED, 'limit' => 20));
        $questions = ECP_Content_Gaps::open_questions(8);
        $topics = ECP_Inventory::topics(50);
        $can_review = ECP_Capabilities::can_review();

        ?>
        <div class="wrap ecp-wrap">
            <h1><?php esc_html_e('Knowledge Vault', 'enhanced-content-plugin'); ?></h1>

            <?php ECP_Admin_Menu::header('ecp-vault'); ?>

            <p class="ecp-narrative">
                <?php esc_html_e('Everything the agent knows about your business, on the record. It quotes these facts when it writes; it never invents new ones. Retire anything that stops being true and it disappears from every future analysis.', 'enhanced-content-plugin'); ?>
            </p>

            <?php if ($questions) : ?>
                <div class="ecp-panel ecp-panel-questions">
                    <h2><?php esc_html_e('Questions waiting for you', 'enhanced-content-plugin'); ?></h2>
                    <p class="ecp-muted">
                        <?php esc_html_e('The agent found questions readers ask that it refuses to answer on your behalf. Each answer becomes a vault fact.', 'enhanced-content-plugin'); ?>
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
            <?php endif; ?>

            <?php if ($can_review) : ?>
                <div class="ecp-panel">
                    <h2><?php esc_html_e('Tell the agent something', 'enhanced-content-plugin'); ?></h2>
                    <p class="ecp-muted">
                        <?php esc_html_e('Prices, policies, delivery times, dimensions, guarantees — anything you would want quoted accurately. Site-wide facts apply everywhere; a topic fact applies to every page classified under that topic.', 'enhanced-content-plugin'); ?>
                    </p>

                    <div class="ecp-vault-form">
                        <label for="ecp-fact-text"><?php esc_html_e('The fact', 'enhanced-content-plugin'); ?></label>
                        <textarea id="ecp-fact-text" rows="2" class="large-text"
                                  placeholder="<?php esc_attr_e('e.g. Standard delivery is 3–5 business days; express is next-day before 2pm.', 'enhanced-content-plugin'); ?>"></textarea>

                        <label for="ecp-fact-question"><?php esc_html_e('The question it answers (optional)', 'enhanced-content-plugin'); ?></label>
                        <input type="text" id="ecp-fact-question" class="large-text"
                               placeholder="<?php esc_attr_e('e.g. How long does delivery take?', 'enhanced-content-plugin'); ?>">

                        <label for="ecp-fact-topic"><?php esc_html_e('Applies to', 'enhanced-content-plugin'); ?></label>
                        <select id="ecp-fact-topic">
                            <option value=""><?php esc_html_e('The whole site', 'enhanced-content-plugin'); ?></option>
                            <?php foreach ($topics as $topic_row) : ?>
                                <option value="<?php echo esc_attr($topic_row['topic']); ?>">
                                    <?php
                                    printf(
                                        /* translators: 1: topic name, 2: page count */
                                        esc_html__('Topic: %1$s (%2$d pages)', 'enhanced-content-plugin'),
                                        esc_html($topic_row['topic']),
                                        (int) $topic_row['pages']
                                    );
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <p>
                            <button type="button" class="button button-primary" id="ecp-add-fact">
                                <?php esc_html_e('Add to the vault', 'enhanced-content-plugin'); ?>
                            </button>
                            <span class="ecp-vault-form-status" aria-live="polite"></span>
                        </p>
                    </div>
                </div>
            <?php endif; ?>

            <div class="ecp-panel">
                <h2>
                    <?php esc_html_e('What the agent knows', 'enhanced-content-plugin'); ?>
                    <span class="ecp-muted">
                        <?php
                        printf(
                            /* translators: %d: number of facts */
                            esc_html(_n('%d fact', '%d facts', (int) $active['total'], 'enhanced-content-plugin')),
                            (int) $active['total']
                        );
                        ?>
                    </span>
                </h2>

                <?php if (!$active['items']) : ?>
                    <p class="ecp-muted"><?php esc_html_e('Nothing yet. Answer a question above, or add a fact directly — everything the agent may state about your business starts here.', 'enhanced-content-plugin'); ?></p>
                <?php else : ?>
                    <table class="widefat striped ecp-vault-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Fact', 'enhanced-content-plugin'); ?></th>
                                <th><?php esc_html_e('Applies to', 'enhanced-content-plugin'); ?></th>
                                <th><?php esc_html_e('Confirmed', 'enhanced-content-plugin'); ?></th>
                                <?php if ($can_review) : ?><th></th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($active['items'] as $fact) : ?>
                                <?php self::render_fact_row($fact, $can_review); ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <?php if ($retired['items']) : ?>
                <div class="ecp-panel">
                    <h2><?php esc_html_e('Retired facts', 'enhanced-content-plugin'); ?></h2>
                    <p class="ecp-muted"><?php esc_html_e('No longer used anywhere. Kept so you can see what the agent used to be told.', 'enhanced-content-plugin'); ?></p>
                    <table class="widefat striped ecp-vault-table">
                        <tbody>
                            <?php foreach ($retired['items'] as $fact) : ?>
                                <tr data-id="<?php echo esc_attr($fact['id']); ?>">
                                    <td>
                                        <?php if ($fact['question']) : ?>
                                            <strong><?php echo esc_html($fact['question']); ?></strong><br>
                                        <?php endif; ?>
                                        <?php echo esc_html($fact['fact']); ?>
                                    </td>
                                    <?php if ($can_review) : ?>
                                        <td class="ecp-cell-action">
                                            <button type="button" class="button button-small ecp-fact-act" data-act="restore" data-id="<?php echo esc_attr($fact['id']); ?>">
                                                <?php esc_html_e('Restore', 'enhanced-content-plugin'); ?>
                                            </button>
                                            <span class="ecp-row-status" aria-live="polite"></span>
                                        </td>
                                    <?php endif; ?>
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
     * One active fact: the statement, its scope, its age, its controls.
     */
    private static function render_fact_row(array $fact, $can_review) {
        $stale = ECP_Vault::is_stale($fact['verified_at']);
        ?>
        <tr data-id="<?php echo esc_attr($fact['id']); ?>">
            <td class="ecp-fact-cell">
                <?php if ($fact['question']) : ?>
                    <strong><?php echo esc_html($fact['question']); ?></strong><br>
                <?php endif; ?>
                <span class="ecp-fact-text"><?php echo esc_html($fact['fact']); ?></span>
            </td>
            <td>
                <?php
                if ((int) $fact['post_id']) {
                    printf(
                        '<a href="%s">%s</a>',
                        esc_url(get_edit_post_link((int) $fact['post_id'])),
                        esc_html(get_the_title((int) $fact['post_id']))
                    );
                } elseif ('' !== $fact['topic']) {
                    printf(
                        /* translators: %s: topic name */
                        esc_html__('Topic: %s', 'enhanced-content-plugin'),
                        esc_html($fact['topic'])
                    );
                } else {
                    esc_html_e('Whole site', 'enhanced-content-plugin');
                }
                ?>
            </td>
            <td>
                <?php
                if ($fact['verified_at']) {
                    printf(
                        /* translators: %s: human-readable time difference */
                        esc_html__('%s ago', 'enhanced-content-plugin'),
                        esc_html(human_time_diff(strtotime($fact['verified_at']), (int) current_time('timestamp')))
                    );
                }
                ?>
                <?php if ($stale) : ?>
                    <span class="ecp-chip ecp-chip-moderate"><?php esc_html_e('worth re-confirming', 'enhanced-content-plugin'); ?></span>
                <?php endif; ?>
            </td>
            <?php if ($can_review) : ?>
                <td class="ecp-cell-action">
                    <?php if ($stale) : ?>
                        <button type="button" class="button button-small ecp-fact-act" data-act="confirm" data-id="<?php echo esc_attr($fact['id']); ?>">
                            <?php esc_html_e('Still true', 'enhanced-content-plugin'); ?>
                        </button>
                    <?php endif; ?>
                    <button type="button" class="button button-small ecp-fact-edit" data-id="<?php echo esc_attr($fact['id']); ?>">
                        <?php esc_html_e('Edit', 'enhanced-content-plugin'); ?>
                    </button>
                    <button type="button" class="button-link ecp-fact-act" data-act="retire" data-id="<?php echo esc_attr($fact['id']); ?>">
                        <?php esc_html_e('Retire', 'enhanced-content-plugin'); ?>
                    </button>
                    <span class="ecp-row-status" aria-live="polite"></span>
                </td>
            <?php endif; ?>
        </tr>
        <?php
    }
}
