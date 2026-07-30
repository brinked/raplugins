<?php
/**
 * Template: Sources List
 * Displays article sources/citations at the bottom of the article
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Check if we have sources
if (empty($sources) || !is_array($sources)) {
    return;
}
?>

<div class="map-sources-section">
    <h3 class="map-sources-title">
        <?php echo esc_html(apply_filters('map_sources_title', __('Sources', 'enhanced-content-plugin'))); ?>
    </h3>

    <ol class="map-sources-list">
        <?php foreach ($sources as $index => $source) : ?>
            <?php if (!empty($source['url'])) : ?>
                <li class="map-source-item">
                    <a href="<?php echo esc_url($source['url']); ?>"
                       class="map-source-link"
                       target="_blank"
                       rel="nofollow noopener"
                       title="<?php echo esc_attr(!empty($source['label']) ? $source['label'] : __('View source', 'enhanced-content-plugin')); ?>">
                        <?php
                        // Display label if available, otherwise show shortened URL
                        if (!empty($source['label'])) {
                            echo esc_html($source['label']);
                        } else {
                            // Display a shortened URL
                            $display_url = $source['url'];
                            $parsed_url = parse_url($source['url']);
                            if (isset($parsed_url['host'])) {
                                $display_url = $parsed_url['host'];
                                if (isset($parsed_url['path']) && $parsed_url['path'] !== '/') {
                                    $display_url .= $parsed_url['path'];
                                }
                            }
                            echo esc_html($display_url);
                        }
                        ?>
                        <span class="map-external-icon" aria-hidden="true">↗</span>
                    </a><?php if (!empty($source['description'])) : ?> - <span class="map-source-description"><?php echo esc_html($source['description']); ?></span><?php endif; ?>
                </li>
            <?php endif; ?>
        <?php endforeach; ?>
    </ol>
</div>