<?php
/**
 * Template: AI Disclaimer Badge
 * Displays the AI disclosure badge on articles
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Get AI use labels
$ai_use_labels = ECP_Frontend_Display::get_ai_use_labels();
?>

<div class="map-ai-disclaimer-badge-wrapper">
    <?php if ($badge_type === 'no_ai') : ?>
        <!-- No AI Badge -->
        <div class="map-ai-badge map-ai-badge-no-ai" tabindex="0">
            <span class="map-ai-badge-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="currentColor">
                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                </svg>
            </span>
            <span class="map-ai-badge-text"><?php _e('No AI Used', 'enhanced-content-plugin'); ?></span>

            <!-- Popup on hover -->
            <div class="map-ai-badge-popup">
                <div class="map-ai-badge-popup-content">
                    <h4><?php _e('Human-Created Content', 'enhanced-content-plugin'); ?></h4>
                    <p><?php _e('This article was created entirely without the use of artificial intelligence. All research, writing, editing, and creative elements were produced by human authors and editors.', 'enhanced-content-plugin'); ?></p>
                    <?php if (!empty($ai_disclaimer_url)) : ?>
                        <a href="<?php echo esc_url($ai_disclaimer_url); ?>" class="map-ai-badge-link">
                            <?php _e('Learn more about our AI policy', 'enhanced-content-plugin'); ?> &rarr;
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    <?php elseif ($badge_type === 'ai_enhanced') : ?>
        <!-- AI Enhanced Badge -->
        <div class="map-ai-badge map-ai-badge-enhanced" tabindex="0">
            <span class="map-ai-badge-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="currentColor">
                    <path d="M21 10.12h-6.78l2.74-2.82c-2.73-2.7-7.15-2.8-9.88-.1-2.73 2.71-2.73 7.08 0 9.79s7.15 2.71 9.88 0C18.32 15.65 19 14.08 19 12.1h2c0 1.98-.88 4.55-2.64 6.29-3.51 3.48-9.21 3.48-12.72 0-3.5-3.47-3.53-9.11-.02-12.58s9.14-3.47 12.65 0L21 3v7.12zM12.5 8v4.25l3.5 2.08-.72 1.21L11 13V8h1.5z"/>
                </svg>
            </span>
            <span class="map-ai-badge-text"><?php _e('AI Enhanced', 'enhanced-content-plugin'); ?></span>

            <!-- Popup on hover -->
            <div class="map-ai-badge-popup">
                <div class="map-ai-badge-popup-content">
                    <h4><?php _e('AI-Assisted Content', 'enhanced-content-plugin'); ?></h4>
                    <p><?php _e('Artificial intelligence tools were used in the creation of this article. Human editors reviewed and verified all content for accuracy and quality.', 'enhanced-content-plugin'); ?></p>

                    <?php if (!empty($ai_uses) || !empty($custom_uses)) : ?>
                        <div class="map-ai-uses-list">
                            <strong><?php _e('AI was used for:', 'enhanced-content-plugin'); ?></strong>
                            <ul>
                                <?php foreach ($ai_uses as $use_key) : ?>
                                    <?php if (isset($ai_use_labels[$use_key])) : ?>
                                        <li><?php echo esc_html($ai_use_labels[$use_key]); ?></li>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                <?php foreach ($custom_uses as $custom_use) : ?>
                                    <li><?php echo esc_html($custom_use); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($ai_disclaimer_url)) : ?>
                        <a href="<?php echo esc_url($ai_disclaimer_url); ?>" class="map-ai-badge-link">
                            <?php _e('Read our full AI disclosure policy', 'enhanced-content-plugin'); ?> &rarr;
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
