<?php
/**
 * Template: FAQ Section
 * Displays the FAQ section at the end of articles
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Ensure we have FAQ data
if (empty($faq_data) || !is_array($faq_data)) {
    return;
}

// Generate unique ID for this FAQ section
$faq_id = 'map-faq-' . get_the_ID();
?>

<section class="map-faq-section" id="<?php echo esc_attr($faq_id); ?>">
    <h2 class="map-faq-title"><?php echo esc_html($faq_title); ?></h2>

    <?php // Structured data is emitted once as JSON-LD in the head (ECP_FAQ::output_faq_schema).
          // No microdata here — duplicate FAQPage markup triggers Search Console conflicts. ?>
    <div class="map-faq-accordion">
        <?php foreach ($faq_data as $index => $faq) : ?>
            <?php if (!empty($faq['question']) && !empty($faq['answer'])) : ?>
                <div class="map-faq-accordion-item" data-faq-index="<?php echo esc_attr($index); ?>">
                    <button type="button" class="map-faq-accordion-header" aria-expanded="false" aria-controls="<?php echo esc_attr($faq_id); ?>-answer-<?php echo esc_attr($index); ?>">
                        <span class="map-faq-question-text"><?php echo esc_html($faq['question']); ?></span>
                        <span class="map-faq-accordion-icon" aria-hidden="true">
                            <svg class="map-faq-icon-plus" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="currentColor">
                                <path d="M12 4C11.4477 4 11 4.44772 11 5V11H5C4.44772 11 4 11.4477 4 12C4 12.5523 4.44772 13 5 13H11V19C11 19.5523 11.4477 20 12 20C12.5523 20 13 19.5523 13 19V13H19C19.5523 13 20 12.5523 20 12C20 11.4477 19.5523 11 19 11H13V5C13 4.44772 12.5523 4 12 4Z"/>
                            </svg>
                            <svg class="map-faq-icon-minus" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="currentColor">
                                <path d="M5 11C4.44772 11 4 11.4477 4 12C4 12.5523 4.44772 13 5 13H19C19.5523 13 20 12.5523 20 12C20 11.4477 19.5523 11 19 11H5Z"/>
                            </svg>
                        </span>
                    </button>
                    <div class="map-faq-accordion-content" id="<?php echo esc_attr($faq_id); ?>-answer-<?php echo esc_attr($index); ?>" hidden>
                        <div class="map-faq-answer">
                            <?php echo wp_kses_post(wpautop($faq['answer'])); ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</section>

<script type="text/javascript">
(function() {
    'use strict';

    var faqId = '<?php echo esc_js($faq_id); ?>';
    var debounceMs = 300;

    function toggleFAQ(item) {
        if (!item) return;

        var header = item.querySelector('.map-faq-accordion-header');
        var content = item.querySelector('.map-faq-accordion-content');
        var isOpen = item.classList.contains('is-open');

        if (isOpen) {
            item.classList.remove('is-open');
            if (header) header.setAttribute('aria-expanded', 'false');
            if (content) content.setAttribute('hidden', '');
        } else {
            item.classList.add('is-open');
            if (header) header.setAttribute('aria-expanded', 'true');
            if (content) content.removeAttribute('hidden');
        }
    }

    function handleFAQClick(e) {
        // Find the header button
        var header = e.target.closest('.map-faq-accordion-header');
        if (!header) return;

        // Prevent any default behavior
        e.preventDefault();
        e.stopPropagation();
        e.stopImmediatePropagation();

        // Find the parent item and toggle
        var item = header.closest('.map-faq-accordion-item');
        if (!item) return false;

        // Debounce rapid clicks per item, so a quick click on a
        // DIFFERENT question is never swallowed
        var now = Date.now();
        var lastClick = parseInt(item.dataset.faqLastClick || '0', 10);
        if (now - lastClick < debounceMs) {
            return false;
        }
        item.dataset.faqLastClick = String(now);

        toggleFAQ(item);

        return false;
    }

    function initFAQAccordion() {
        var faqSection = document.getElementById(faqId);
        if (!faqSection) return;
        if (faqSection.dataset.faqInitialized === 'true') return;

        var accordion = faqSection.querySelector('.map-faq-accordion');
        if (!accordion) return;

        // Use capture phase to intercept before other handlers
        accordion.addEventListener('click', handleFAQClick, true);

        // Mark as initialized
        faqSection.dataset.faqInitialized = 'true';
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initFAQAccordion);
    } else {
        // Small delay to ensure we run after theme scripts
        setTimeout(initFAQAccordion, 10);
    }
})();
</script>
