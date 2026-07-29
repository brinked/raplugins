<?php
/**
 * Template: Corrections & Updates Log
 * Displays dated corrections at the end of the article for transparency
 *
 * Available variables:
 *   $corrections - array of arrays with 'date' (Y-m-d or '') and 'text'
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

if (empty($corrections) || !is_array($corrections)) {
    return;
}
?>

<div class="map-corrections-section">
    <h3 class="map-corrections-title">
        <?php echo esc_html(apply_filters('map_corrections_title', __('Corrections & Updates', 'multi-author-plugin'))); ?>
    </h3>

    <ul class="map-corrections-list">
        <?php foreach ($corrections as $correction) : ?>
            <?php if (!empty($correction['text'])) : ?>
                <li class="map-correction-entry">
                    <?php if (!empty($correction['date'])) : ?>
                        <time class="map-correction-date" datetime="<?php echo esc_attr($correction['date']); ?>">
                            <?php echo esc_html(date_i18n(get_option('date_format'), strtotime($correction['date']))); ?>
                        </time>
                        <span class="map-correction-separator" aria-hidden="true">—</span>
                    <?php endif; ?>
                    <span class="map-correction-text"><?php echo esc_html($correction['text']); ?></span>
                </li>
            <?php endif; ?>
        <?php endforeach; ?>
    </ul>
</div>
