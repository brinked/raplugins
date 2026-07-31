<?php
/**
 * Trust Foundations screen: the checklist that gates everything else.
 */

if (!defined('ABSPATH')) {
    exit;
}

class ECP_Screen_Trust {

    public static function render() {
        if (!ECP_Capabilities::can_view()) {
            wp_die(esc_html__('You do not have permission to view this page.', 'enhanced-content-plugin'));
        }

        $fresh = isset($_GET['refresh']) && check_admin_referer('ecp_trust_refresh');  // phpcs:ignore WordPress.Security.NonceVerification
        $checks = ECP_Trust_Audit::run($fresh);
        $summary = ECP_Trust_Audit::summary();

        $groups = array();

        foreach ($checks as $check) {
            $groups[$check['group']][] = $check;
        }

        ?>
        <div class="wrap ecp-wrap">
            <h1><?php esc_html_e('Trust Foundations', 'enhanced-content-plugin'); ?><?php ECP_Admin_Menu::help(__('The checks a reader — and a search quality rater — runs before believing a word: who wrote this, can I verify they exist, is the site honest about how it operates and earns. Content on an untrustworthy site underperforms no matter how good it is. Failing checks appear at the top of the Growth Roadmap.', 'enhanced-content-plugin')); ?></h1>

            <?php ECP_Admin_Menu::header('ecp-trust'); ?>

            <p class="ecp-narrative">
                <?php
                if ($summary['failing'] > 0) {
                    printf(
                        /* translators: 1: in place, 2: total, 3: failing */
                        esc_html__('%1$d of %2$d foundations are in place. %3$d are missing — every article on this site earns less until they exist, no matter how good the content is.', 'enhanced-content-plugin'),
                        (int) $summary['in_place'],
                        (int) $summary['total'],
                        (int) $summary['failing']
                    );
                } else {
                    printf(
                        /* translators: %d: total checks */
                        esc_html__('All %d applicable trust foundations are in place. This is the floor the content stands on — keep it that way.', 'enhanced-content-plugin'),
                        (int) $summary['total']
                    );
                }
                ?>
            </p>

            <p>
                <a class="button" href="<?php echo esc_url(wp_nonce_url(add_query_arg('refresh', 1, admin_url('admin.php?page=ecp-trust')), 'ecp_trust_refresh')); ?>">
                    <?php esc_html_e('Re-check now', 'enhanced-content-plugin'); ?>
                </a>
                <span class="ecp-muted"><?php esc_html_e('Free — reads your site and your author profiles; no AI.', 'enhanced-content-plugin'); ?></span>
            </p>

            <?php foreach ($groups as $group => $items) : ?>
                <div class="ecp-panel">
                    <h2><?php echo esc_html(ECP_Trust_Audit::group_label($group)); ?></h2>

                    <table class="widefat striped ecp-trust-table">
                        <tbody>
                            <?php foreach ($items as $check) : ?>
                                <tr class="ecp-trust-<?php echo esc_attr($check['status']); ?>">
                                    <td class="ecp-trust-status-cell">
                                        <span class="ecp-chip ecp-chip-<?php echo esc_attr(self::chip($check['status'])); ?>">
                                            <?php echo esc_html(ECP_Trust_Audit::status_label($check['status'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong><?php echo esc_html($check['label']); ?></strong>
                                        <p class="ecp-muted"><?php echo esc_html($check['detail']); ?></p>
                                    </td>
                                    <td class="ecp-cell-action">
                                        <?php if ($check['fix_url'] && ECP_Trust_Audit::PASS !== $check['status'] && ECP_Trust_Audit::NA !== $check['status']) : ?>
                                            <a class="button button-small" href="<?php echo esc_url($check['fix_url']); ?>">
                                                <?php echo esc_html($check['fix_label']); ?>
                                            </a>

                                            <?php $draftable = array_key_exists($check['id'], ECP_Policy_Drafter::draftable()); ?>
                                            <?php if ($draftable && ECP_Capabilities::can_review() && ECP_Agent_Settings::is_ready()) : ?>
                                                <?php $draft = ECP_Policy_Drafter::existing_draft($check['id']); ?>
                                                <?php if ($draft) : ?>
                                                    <a class="button button-small button-primary" href="<?php echo esc_url(get_edit_post_link($draft->ID)); ?>">
                                                        <?php esc_html_e('Review the draft', 'enhanced-content-plugin'); ?>
                                                    </a>
                                                <?php else : ?>
                                                    <button type="button" class="button button-small button-primary ecp-draft-policy" data-check="<?php echo esc_attr($check['id']); ?>"
                                                            title="<?php esc_attr_e('One AI call drafts this page from what the plugin verifiably knows about your site. Anything only you can answer arrives as an [OWNER: …] prompt. Created as an unpublished draft — the check passes once you publish it.', 'enhanced-content-plugin'); ?>">
                                                        <?php esc_html_e('Draft it for me', 'enhanced-content-plugin'); ?>
                                                    </button>
                                                <?php endif; ?>
                                                <span class="ecp-row-status" aria-live="polite"></span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>

            <div class="ecp-panel">
                <h2><?php esc_html_e('Why this gates everything', 'enhanced-content-plugin'); ?></h2>
                <p class="ecp-muted">
                    <?php esc_html_e('Search quality raters are literally handed a checklist like this one. A site that cannot answer "who wrote this, can I verify them, how does this site operate and earn" gets discounted before the content is even weighed — which is why the missing items above also appear at the very top of your Growth Roadmap, ahead of every content improvement. Fix the floor first.', 'enhanced-content-plugin'); ?>
                </p>
            </div>
        </div>
        <?php
    }

    private static function chip($status) {
        $map = array(
            ECP_Trust_Audit::PASS => 'safe',
            ECP_Trust_Audit::WARN => 'moderate',
            ECP_Trust_Audit::FAIL => 'sensitive',
            ECP_Trust_Audit::NA   => 'safe',
        );

        return isset($map[$status]) ? $map[$status] : 'moderate';
    }
}
