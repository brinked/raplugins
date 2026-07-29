<?php
/**
 * Template: Editorial Team
 * Cards for the site's editorial team members
 *
 * Available variables:
 *   $team_members - arrays from MAP_User_Profile::get_contributor_data()
 *   $columns      - number of columns (1-4)
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

if (empty($team_members)) {
    return;
}

$columns = isset($columns) ? min(4, max(1, intval($columns))) : 3;
$reviewed_label = apply_filters('map_team_reviewed_label', __('%s articles reviewed', 'multi-author-plugin'));
?>

<div class="map-editorial-team map-team-columns-<?php echo esc_attr($columns); ?>">
    <?php foreach ($team_members as $member) : ?>
        <div class="map-team-card">
            <a href="<?php echo esc_url($member['profile_url']); ?>" class="map-team-avatar-link">
                <img src="<?php echo esc_url(get_avatar_url($member['id'], array('size' => 160))); ?>"
                     alt="<?php echo esc_attr($member['display_name']); ?>"
                     class="map-team-avatar" loading="lazy" />
            </a>

            <h3 class="map-team-name">
                <a href="<?php echo esc_url($member['profile_url']); ?>"><?php echo esc_html($member['display_name']); ?></a>
            </h3>

            <?php if (!empty($member['job_title'])) : ?>
                <p class="map-team-title"><?php echo esc_html($member['job_title']); ?></p>
            <?php endif; ?>

            <?php
            $bio = !empty($member['short_bio']) ? $member['short_bio'] : (!empty($member['bio']) ? wp_trim_words($member['bio'], 30, '...') : '');
            if ($bio) : ?>
                <p class="map-team-bio"><?php echo esc_html($bio); ?></p>
            <?php endif; ?>

            <?php
            $counts = MAP_Frontend_Display::get_credit_counts($member['id']);
            $credit_parts = array();
            if ($counts['reviewed']) {
                /* translators: %s: number of articles */
                $credit_parts[] = sprintf(_n('%s article reviewed', '%s articles reviewed', $counts['reviewed'], 'multi-author-plugin'), number_format_i18n($counts['reviewed']));
            }
            if ($counts['fact_checked']) {
                /* translators: %s: number of articles */
                $credit_parts[] = sprintf(_n('%s article fact-checked', '%s articles fact-checked', $counts['fact_checked'], 'multi-author-plugin'), number_format_i18n($counts['fact_checked']));
            }
            if (!empty($credit_parts)) : ?>
                <p class="map-team-credits"><?php echo esc_html(implode(' · ', $credit_parts)); ?></p>
            <?php endif; ?>

            <div class="map-team-links">
                <a href="<?php echo esc_url($member['profile_url']); ?>" class="map-team-profile-link">
                    <?php _e('View Full Bio', 'multi-author-plugin'); ?>
                </a>
            </div>
        </div>
    <?php endforeach; ?>
</div>
