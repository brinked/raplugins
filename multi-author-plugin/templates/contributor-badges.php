<?php
/**
 * Template: Contributor Badges
 * Displays contributor information at the top of the article
 * With instant hover popups (pre-rendered inline)
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

global $post;

// Get post author (primary author)
$primary_author_id = $post->post_author;
$primary_author = get_userdata($primary_author_id);
$primary_author_url = get_author_posts_url($primary_author_id);
$primary_author_title = get_user_meta($primary_author_id, 'job_title', true);

// Get post dates - use get_post_time to bypass any filters
$published_date = date_i18n('F jS, Y', get_post_time('U', false, $post));
$modified_date = date_i18n('F jS, Y', get_post_modified_time('U', false, $post));
$show_updated = ($modified_date !== $published_date);

// Get contributors
if (empty($contributors) || !is_array($contributors)) {
    $contributors = array(
        'authors' => array(),
        'reviewers' => array(),
        'fact_checkers' => array()
    );
}

// Check if we should skip the primary author (let theme handle it)
$skip_primary_author = MAP_Settings::get_setting('skip_primary_author', 0);

// Collect all contributors with their roles
$all_contributors = array();

// Add primary author (unless skipped or the account was deleted)
if (!$skip_primary_author && $primary_author) {
    $all_contributors[] = array(
        'user_id' => $primary_author_id,
        'user' => $primary_author,
        'role' => 'author',
        'role_label' => MAP_Settings::get_role_label('author'),
        'title' => $primary_author_title,
        'is_primary' => true,
        'published_date' => $published_date,
        'modified_date' => $show_updated ? $modified_date : null
    );
}

// Add co-authors
if (!empty($contributors['authors'])) {
    foreach ($contributors['authors'] as $user_id) {
        $user = get_userdata($user_id);
        if ($user) {
            $all_contributors[] = array(
                'user_id' => $user_id,
                'user' => $user,
                'role' => 'coauthor',
                'role_label' => MAP_Settings::get_role_label('coauthor'),
                'title' => get_user_meta($user_id, 'job_title', true)
            );
        }
    }
}

// Add reviewers
if (!empty($contributors['reviewers'])) {
    foreach ($contributors['reviewers'] as $user_id) {
        $user = get_userdata($user_id);
        if ($user) {
            $all_contributors[] = array(
                'user_id' => $user_id,
                'user' => $user,
                'role' => 'reviewer',
                'role_label' => MAP_Settings::get_role_label('reviewer'),
                'title' => get_user_meta($user_id, 'job_title', true)
            );
        }
    }
}

// Add fact checkers
if (!empty($contributors['fact_checkers'])) {
    foreach ($contributors['fact_checkers'] as $user_id) {
        $user = get_userdata($user_id);
        if ($user) {
            $all_contributors[] = array(
                'user_id' => $user_id,
                'user' => $user,
                'role' => 'fact_checker',
                'role_label' => MAP_Settings::get_role_label('fact_checker'),
                'title' => get_user_meta($user_id, 'job_title', true)
            );
        }
    }
}

// Get editorial process link if available
$editorial_link = '';
if (!empty($contributors['reviewers'])) {
    foreach ($contributors['reviewers'] as $user_id) {
        $link = get_user_meta($user_id, '_user_editorial_process_link', true);
        if ($link) {
            $editorial_link = $link;
            break;
        }
    }
}

// Get Expert Verified settings - check BOTH global setting AND post-level meta
$expert_verified_global = MAP_Settings::get_setting('expert_verified_enabled', 0);
$expert_verified_post = get_post_meta($post->ID, '_map_expert_verified', true);
// Badge shows only if: global is enabled AND post-level is checked
$show_expert_verified = ($expert_verified_global && $expert_verified_post === '1');

$expert_verified_label = MAP_Settings::get_setting('expert_verified_label', __('Expert verified', 'multi-author-plugin'));
$expert_verified_title = MAP_Settings::get_setting('expert_verified_title', __('How is this page expert verified?', 'multi-author-plugin'));
$expert_verified_content = MAP_Settings::get_setting('expert_verified_content', '');
$expert_verified_link_text = MAP_Settings::get_setting('expert_verified_link_text', __('About our Review Board', 'multi-author-plugin'));
$expert_verified_link_url = MAP_Settings::get_setting('expert_verified_link_url', '');

// Get AI Disclaimer settings
$ai_settings = get_post_meta($post->ID, '_map_ai_disclaimer', true);
$ai_badge_type = (is_array($ai_settings) && !empty($ai_settings['badge_type'])) ? $ai_settings['badge_type'] : 'none';
$ai_uses = (is_array($ai_settings) && !empty($ai_settings['ai_uses'])) ? $ai_settings['ai_uses'] : array();
$ai_custom_uses = (is_array($ai_settings) && !empty($ai_settings['custom_uses'])) ? $ai_settings['custom_uses'] : array();
$ai_disclaimer_url = MAP_Settings::get_setting('ai_disclaimer_page', '');
$ai_use_labels = MAP_Frontend_Display::get_ai_use_labels();

// If nothing to display, exit early
if (empty($all_contributors) && empty($editorial_link) && !$show_expert_verified && 'none' === $ai_badge_type) {
    return;
}

if (!function_exists('map_render_inline_popup')) :
/**
 * Helper function to render inline popup for a contributor
 * @param int $user_id The user ID
 * @param string $role The contributor role (author, coauthor, reviewer, fact_checker)
 */
function map_render_inline_popup($user_id, $role = 'author') {
    global $post;

    $data = MAP_User_Profile::get_contributor_data($user_id);
    if (!$data) {
        return '';
    }

    $bio = !empty($data['short_bio']) ? $data['short_bio'] : (!empty($data['bio']) ? wp_trim_words($data['bio'], 25, '...') : '');

    // Get post-level process link settings
    $post_process_links = get_post_meta($post->ID, '_map_process_links', true);
    if (!is_array($post_process_links)) {
        $post_process_links = array();
    }

    // Determine which process link to show based on role
    $process_url = '';
    $process_text = '';

    if ($role === 'author' || $role === 'coauthor') {
        // Editorial process for authors
        $editorial_enabled = MAP_Settings::get_setting('editorial_process_enabled', 0);
        $show_for_authors = !empty($post_process_links['show_editorial_for_authors']);
        if ($editorial_enabled && $show_for_authors) {
            $process_url = MAP_Settings::get_setting('editorial_process_url', '');
            $process_text = MAP_Settings::get_setting('editorial_process_text', __('Learn more about our editorial process', 'multi-author-plugin'));
        }
    } elseif ($role === 'reviewer') {
        // Review process for reviewers
        $review_enabled = MAP_Settings::get_setting('review_process_enabled', 0);
        $show_for_reviewers = !empty($post_process_links['show_review_for_reviewers']);
        if ($review_enabled && $show_for_reviewers) {
            $process_url = MAP_Settings::get_setting('review_process_url', '');
            $process_text = MAP_Settings::get_setting('review_process_text', __('Learn more about our review process', 'multi-author-plugin'));
        }
    } elseif ($role === 'fact_checker') {
        // Fact check process for fact checkers
        $factcheck_enabled = MAP_Settings::get_setting('factcheck_process_enabled', 0);
        $show_for_factcheckers = !empty($post_process_links['show_factcheck_for_factcheckers']);
        if ($factcheck_enabled && $show_for_factcheckers) {
            $process_url = MAP_Settings::get_setting('factcheck_process_url', '');
            $process_text = MAP_Settings::get_setting('factcheck_process_text', __('Learn more about our fact-checking process', 'multi-author-plugin'));
        }
    }

    ob_start();
    ?>
    <div class="map-popup-content">
        <div class="map-popup-header">
            <img src="<?php echo esc_url($data['avatar_url']); ?>"
                 alt="<?php echo esc_attr($data['display_name']); ?>"
                 class="map-popup-avatar" />
            <div class="map-popup-info">
                <h4 class="map-popup-name"><?php echo esc_html($data['display_name']); ?></h4>
                <?php if (!empty($data['job_title'])) : ?>
                    <p class="map-popup-role"><?php echo esc_html($data['job_title']); ?></p>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($bio) : ?>
            <div class="map-popup-bio">
                <p><?php echo esc_html($bio); ?></p>
            </div>
        <?php endif; ?>

        <?php
        $has_social = !empty($data['twitter']) || !empty($data['linkedin']) ||
                      !empty($data['facebook']) || !empty($data['instagram']);

        if ($has_social) : ?>
            <div class="map-popup-social">
                <?php if (!empty($data['twitter'])) :
                    $twitter_url = (strpos($data['twitter'], 'http') === 0) ?
                                  $data['twitter'] :
                                  'https://twitter.com/' . ltrim($data['twitter'], '@');
                ?>
                    <a href="<?php echo esc_url($twitter_url); ?>" class="map-social-icon" target="_blank" rel="noopener nofollow" title="Twitter/X">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
                    </a>
                <?php endif; ?>
                <?php if (!empty($data['linkedin'])) : ?>
                    <a href="<?php echo esc_url($data['linkedin']); ?>" class="map-social-icon" target="_blank" rel="noopener nofollow" title="LinkedIn">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>
                    </a>
                <?php endif; ?>
                <?php if (!empty($data['facebook'])) : ?>
                    <a href="<?php echo esc_url($data['facebook']); ?>" class="map-social-icon" target="_blank" rel="noopener nofollow" title="Facebook">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                    </a>
                <?php endif; ?>
                <?php if (!empty($data['instagram'])) :
                    $instagram_url = (strpos($data['instagram'], 'http') === 0) ?
                                    $data['instagram'] :
                                    'https://instagram.com/' . ltrim($data['instagram'], '@');
                ?>
                    <a href="<?php echo esc_url($instagram_url); ?>" class="map-social-icon" target="_blank" rel="noopener nofollow" title="Instagram">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/></svg>
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="map-popup-links">
            <a href="<?php echo esc_url($data['profile_url']); ?>" class="map-popup-link">
                <?php _e('View Full Bio', 'multi-author-plugin'); ?>
            </a>
        </div>

        <?php if ($process_url && $process_text) : ?>
            <div class="map-popup-editorial">
                <a href="<?php echo esc_url($process_url); ?>" class="map-popup-editorial-link" target="_blank" rel="noopener">
                    <?php echo esc_html($process_text); ?>
                </a>
            </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}
endif;
?>

<div class="map-contributors-section">
    <?php
    // Separate primary author from other contributors
    $primary_contributor = null;
    $other_contributors = array();

    foreach ($all_contributors as $contributor) {
        if (isset($contributor['is_primary']) && $contributor['is_primary']) {
            $primary_contributor = $contributor;
        } else {
            $other_contributors[] = $contributor;
        }
    }
    ?>

    <?php if ($primary_contributor || $show_expert_verified || 'none' !== $ai_badge_type) : ?>
    <div class="map-primary-row">
        <?php if ($primary_contributor) :
            $user = $primary_contributor['user'];
            $user_id = $primary_contributor['user_id'];
            $author_url = get_author_posts_url($user_id);
            $avatar_url = get_avatar_url($user_id, array('size' => 80));
            $pub_date = isset($primary_contributor['published_date']) ? $primary_contributor['published_date'] : null;
            $mod_date = isset($primary_contributor['modified_date']) ? $primary_contributor['modified_date'] : null;
        ?>
        <span class="map-author-text"><?php esc_html_e('By', 'multi-author-plugin'); ?></span>
        <span class="map-contributor-wrapper">
            <a href="<?php echo esc_url($author_url); ?>" class="map-contributor-link" data-user-id="<?php echo esc_attr($user_id); ?>">
                <img src="<?php echo esc_url($avatar_url); ?>" alt="<?php echo esc_attr($user->display_name); ?>" class="map-contributor-avatar" />
            </a>
            <a href="<?php echo esc_url($author_url); ?>" class="map-author-name map-contributor-link" data-user-id="<?php echo esc_attr($user_id); ?>">
                <?php echo esc_html($user->display_name); ?>
            </a>
            <?php echo map_render_inline_popup($user_id, 'author'); ?>
        </span>
        <?php if ($pub_date) : ?>
            <span class="map-date-separator">|</span>
            <span class="map-date-text"><?php echo esc_html($pub_date); ?></span>
        <?php endif; ?>
        <?php if ($mod_date) : ?>
            <span class="map-date-separator">|</span>
            <span class="map-date-text"><?php printf(esc_html__('Updated %s', 'multi-author-plugin'), esc_html($mod_date)); ?></span>
        <?php endif; ?>
        <?php endif; ?>
        <?php
        $fact_checked_date = get_post_meta($post->ID, '_map_fact_checked_date', true);
        if ($fact_checked_date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fact_checked_date)) : ?>
            <span class="map-date-separator">|</span>
            <span class="map-date-text map-fact-checked-date">
                <svg class="map-fact-checked-icon" width="13" height="13" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
                <?php printf(esc_html__('Fact-checked %s', 'multi-author-plugin'), esc_html(date_i18n('F jS, Y', strtotime($fact_checked_date)))); ?>
            </span>
        <?php endif; ?>
        <?php if ($show_expert_verified) : ?>
            <span class="map-expert-verified-wrapper">
                <span class="map-expert-verified-badge" tabindex="0">
                    <svg class="map-expert-verified-icon" width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm-2 16l-4-4 1.41-1.41L10 14.17l6.59-6.59L18 9l-8 8z"/>
                    </svg>
                    <span class="map-expert-verified-text"><?php echo esc_html($expert_verified_label); ?></span>
                </span>
                <div class="map-expert-verified-popup">
                    <h4 class="map-expert-verified-popup-title"><?php echo esc_html($expert_verified_title); ?></h4>
                    <div class="map-expert-verified-popup-content">
                        <?php echo wp_kses_post(wpautop($expert_verified_content)); ?>
                    </div>
                    <?php if ($expert_verified_link_url) : ?>
                        <a href="<?php echo esc_url($expert_verified_link_url); ?>" class="map-expert-verified-popup-link" target="_blank" rel="noopener">
                            <?php echo esc_html($expert_verified_link_text); ?>
                        </a>
                    <?php endif; ?>
                </div>
            </span>
        <?php endif; ?>
        <?php if ($ai_badge_type === 'no_ai') : ?>
            <span class="map-ai-badge-wrapper">
                <span class="map-ai-badge map-ai-badge-no-ai" tabindex="0">
                    <svg class="map-ai-badge-icon" width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                    </svg>
                    <span class="map-ai-badge-text"><?php _e('No AI', 'multi-author-plugin'); ?></span>
                </span>
                <div class="map-ai-badge-popup">
                    <h4 class="map-ai-badge-popup-title"><?php _e('Human-Created Content', 'multi-author-plugin'); ?></h4>
                    <div class="map-ai-badge-popup-content">
                        <p><?php _e('This article was created entirely without the use of artificial intelligence. All research, writing, editing, and creative elements were produced by human authors and editors.', 'multi-author-plugin'); ?></p>
                    </div>
                    <?php if ($ai_disclaimer_url) : ?>
                        <a href="<?php echo esc_url($ai_disclaimer_url); ?>" class="map-ai-badge-popup-link" target="_blank" rel="noopener">
                            <?php _e('Learn more about our AI policy', 'multi-author-plugin'); ?>
                        </a>
                    <?php endif; ?>
                </div>
            </span>
        <?php elseif ($ai_badge_type === 'ai_enhanced') : ?>
            <span class="map-ai-badge-wrapper">
                <span class="map-ai-badge map-ai-badge-enhanced" tabindex="0">
                    <svg class="map-ai-badge-icon" width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M21 10.12h-6.78l2.74-2.82c-2.73-2.7-7.15-2.8-9.88-.1-2.73 2.71-2.73 7.08 0 9.79s7.15 2.71 9.88 0C18.32 15.65 19 14.08 19 12.1h2c0 1.98-.88 4.55-2.64 6.29-3.51 3.48-9.21 3.48-12.72 0-3.5-3.47-3.53-9.11-.02-12.58s9.14-3.47 12.65 0L21 3v7.12zM12.5 8v4.25l3.5 2.08-.72 1.21L11 13V8h1.5z"/>
                    </svg>
                    <span class="map-ai-badge-text"><?php _e('AI Enhanced', 'multi-author-plugin'); ?></span>
                </span>
                <div class="map-ai-badge-popup">
                    <h4 class="map-ai-badge-popup-title"><?php _e('AI-Assisted Content', 'multi-author-plugin'); ?></h4>
                    <div class="map-ai-badge-popup-content">
                        <p><?php _e('Artificial intelligence tools were used in the creation of this article. Human editors reviewed and verified all content for accuracy and quality.', 'multi-author-plugin'); ?></p>
                        <?php if (!empty($ai_uses) || !empty($ai_custom_uses)) : ?>
                            <div class="map-ai-uses-list">
                                <strong><?php _e('AI was used for:', 'multi-author-plugin'); ?></strong>
                                <ul>
                                    <?php foreach ($ai_uses as $use_key) : ?>
                                        <?php if (isset($ai_use_labels[$use_key])) : ?>
                                            <li><?php echo esc_html($ai_use_labels[$use_key]); ?></li>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                    <?php foreach ($ai_custom_uses as $custom_use) : ?>
                                        <li><?php echo esc_html($custom_use); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if ($ai_disclaimer_url) : ?>
                        <a href="<?php echo esc_url($ai_disclaimer_url); ?>" class="map-ai-badge-popup-link" target="_blank" rel="noopener">
                            <?php _e('Read our full AI disclosure policy', 'multi-author-plugin'); ?>
                        </a>
                    <?php endif; ?>
                </div>
            </span>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($other_contributors)) : ?>
    <div class="map-contributors-inline">
        <?php foreach ($other_contributors as $contributor) :
            $user = $contributor['user'];
            $user_id = $contributor['user_id'];
            $author_url = get_author_posts_url($user_id);
            $avatar_url = get_avatar_url($user_id, array('size' => 80));
            $role_label = $contributor['role_label'];
            $job_title = $contributor['title'];
            $contributor_role = $contributor['role']; // coauthor, reviewer, or fact_checker
        ?>
        <div class="map-contributor-card">
            <span class="map-contributor-wrapper">
                <div class="map-contributor-photo">
                    <a href="<?php echo esc_url($author_url); ?>"
                       class="map-contributor-link"
                       data-user-id="<?php echo esc_attr($user_id); ?>">
                        <img src="<?php echo esc_url($avatar_url); ?>"
                             alt="<?php echo esc_attr($user->display_name); ?>"
                             class="map-contributor-avatar" />
                    </a>
                </div>
                <div class="map-contributor-info">
                    <div class="map-contributor-role-label"><?php echo esc_html($role_label); ?></div>
                    <div class="map-contributor-name">
                        <a href="<?php echo esc_url($author_url); ?>"
                           class="map-contributor-link"
                           data-user-id="<?php echo esc_attr($user_id); ?>">
                            <?php echo esc_html($user->display_name); ?>
                        </a>
                    </div>
                    <?php if ($job_title) : ?>
                        <div class="map-contributor-title"><?php echo esc_html($job_title); ?></div>
                    <?php endif; ?>
                </div>
                <?php echo map_render_inline_popup($user_id, $contributor_role); ?>
            </span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($editorial_link) : ?>
        <div class="map-editorial-process-link">
            <a href="<?php echo esc_url($editorial_link); ?>"
               class="map-editorial-link"
               target="_blank"
               rel="noopener">
                <?php echo esc_html(MAP_Frontend_Display::get_editorial_process_label()); ?>
            </a>
        </div>
    <?php endif; ?>
</div>
