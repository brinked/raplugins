/**
 * Frontend JavaScript
 * WooCommerce Enhanced Product Info
 */

(function($) {
    'use strict';

    // Flag to track if initialized
    var initialized = false;

    // Prevent browser scroll restoration for accordion mode only
    if ('scrollRestoration' in history && document.body && document.body.classList.contains('wcepi-accordion-mode')) {
        history.scrollRestoration = 'manual';
    }

    // Early scroll prevention for accordion mode - runs before DOM ready
    var scrollLocked = false;
    var initialScrollPos = 0;

    function lockScroll() {
        if (!scrollLocked && $('body').hasClass('wcepi-accordion-mode')) {
            scrollLocked = true;
            initialScrollPos = 0;
            window.scrollTo(0, 0);
        }
    }

    // Try to lock scroll immediately
    if (document.body && $('body').hasClass('wcepi-accordion-mode')) {
        lockScroll();
    }

    // Main initialization function
    function initPlugin() {
        if (initialized) {
            return;
        }
        initialized = true;


        // Remove theme accordion classes in tabs mode
        removeThemeAccordionClasses();

        // Initialize accordion mode if enabled
        initAccordionMode();

        // Initialize tabs mode if not accordion
        initTabsMode();

        // Smooth scroll to tabs
        smoothScrollToTabs();

        // Force visibility check on mobile after init
        if (window.innerWidth <= 768) {
            setTimeout(forceMobileVisibility, 100);
            setTimeout(forceMobileVisibility, 500);
        }

        // Initialize FAQ accordion
        initFAQAccordion();
    }

    // Multiple initialization points for better mobile browser support
    $(document).ready(function() {
        initPlugin();
    });
    
    // Backup initialization for slower mobile browsers
    $(window).on('load', function() {
        if (!initialized) {
            initPlugin();
        }
    });
    
    // Re-check on orientation change (mobile specific)
    $(window).on('orientationchange resize', function() {
        if (window.innerWidth <= 768) {
            setTimeout(forceMobileVisibility, 100);
        }
    });
    
    /**
     * Force mobile visibility - aggressive check for Safari/Brave mobile
     */
    function forceMobileVisibility() {
        
        // Force all key elements visible
        $('.wcepi-free-shipping-badge, .wcepi-ships-in, .stock.wcepi-in-stock, .stock.wcepi-out-of-stock').each(function() {
            var $el = $(this);
            $el.css({
                'display': $el.hasClass('wcepi-free-shipping-badge') || $el.hasClass('wcepi-ships-in') ? 'inline-block' : 'block',
                'visibility': 'visible',
                'opacity': '1',
                'position': 'relative',
                'left': 'auto',
                'clip': 'auto',
                'width': 'auto',
                'height': 'auto'
            });
        });
        
        // Force tabs wrapper visible
        $('.woocommerce-tabs').css({
            'display': 'block',
            'visibility': 'visible',
            'opacity': '1',
            'height': 'auto',
            'max-height': 'none'
        });
        
        // Force active panel visible
        $('.woocommerce-Tabs-panel.active').css({
            'display': 'block',
            'visibility': 'visible',
            'opacity': '1',
            'height': 'auto',
            'max-height': 'none',
            'overflow': 'visible'
        });
        
        // Force content sections visible
        $('.wcepi-dimensions-content, .wcepi-specifications-content, .wcepi-downloads-content, .wcepi-warranty-content, .wcepi-faq-content, .wcepi-shipping-returns-content').css({
            'display': 'block',
            'visibility': 'visible',
            'opacity': '1',
            'height': 'auto',
            'max-height': 'none'
        });
        
        // Force tables visible
        $('.wcepi-dimensions-table, .wcepi-specifications-table').css({
            'display': 'table',
            'visibility': 'visible',
            'opacity': '1',
            'width': '100%'
        });
        
    }
    
    /**
     * Initialize tabs mode (if not accordion) - Enhanced for mobile
     */
    function initTabsMode() {
        // Only run if NOT in accordion mode
        if ($('body').hasClass('wcepi-accordion-mode')) {
            return;
        }
        
        
        // Make sure tabs are visible and functional
        $('.woocommerce-tabs ul.tabs').css('display', 'flex').show();
        
        // Hide all panels initially
        $('.woocommerce-tabs .woocommerce-Tabs-panel').removeClass('active').css('display', 'none');
        
        // Show the first panel and activate first tab
        var $firstTab = $('.woocommerce-tabs ul.tabs li').first();
        var firstPanelId = $firstTab.find('a').attr('href');
        
        if (firstPanelId && $(firstPanelId).length) {
            $firstTab.addClass('active');
            $(firstPanelId).addClass('active').css({
                'display': 'block',
                'visibility': 'visible',
                'opacity': '1'
            });
            
            // Force visibility on mobile
            if (window.innerWidth <= 768) {
                setTimeout(function() {
                    $(firstPanelId).css({
                        'display': 'block',
                        'visibility': 'visible',
                        'opacity': '1',
                        'height': 'auto'
                    });
                }, 50);
            }
        }
        
        // Handle tab clicks - Enhanced for mobile
        $('.woocommerce-tabs ul.tabs li a').off('click.wcepi touchend.wcepi').on('click.wcepi touchend.wcepi', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            var $tab = $(this).parent();
            var targetId = $(this).attr('href');
            
            
            // Remove active class from all tabs and panels
            $('.woocommerce-tabs ul.tabs li').removeClass('active');
            $('.woocommerce-tabs .woocommerce-Tabs-panel').removeClass('active').css('display', 'none');
            
            // Add active class to clicked tab and show its panel
            $tab.addClass('active');
            var $targetPanel = $(targetId);
            $targetPanel.addClass('active').css({
                'display': 'block',
                'visibility': 'visible',
                'opacity': '1',
                'height': 'auto',
                'max-height': 'none'
            });
            
            // Additional mobile enforcement
            if (window.innerWidth <= 768) {
                setTimeout(function() {
                    $targetPanel.css({
                        'display': 'block',
                        'visibility': 'visible',
                        'opacity': '1'
                    });
                    
                    // Force content visibility
                    $targetPanel.find('.wcepi-dimensions-content, .wcepi-specifications-content, .wcepi-warranty-content, .wcepi-faq-content, .wcepi-shipping-returns-content').css({
                        'display': 'block',
                        'visibility': 'visible',
                        'opacity': '1'
                    });
                }, 50);
            }
            
            // Trigger WooCommerce event
            $targetPanel.trigger('woocommerce-tabs-show');
            
            return false;
        });
    }
    
    /**
     * Remove theme/plugin accordion classes that interfere with tabs mode
     */
    function removeThemeAccordionClasses() {
        // Only run if NOT in accordion mode
        if ($('body').hasClass('wcepi-accordion-mode')) {
            return;
        }
        
        // Remove accordion classes added by theme or other plugins
        $('.woocommerce-tabs').removeClass('wpt-accordion');
        $('.woocommerce-tabs h2').removeClass('wpt-acc-title');
        $('.woocommerce-tabs .wpt-acc-title').removeClass('wpt-acc-title');
        
        // Make sure tabs are visible
        $('.woocommerce-tabs ul.tabs').show();
    }
    
    /**
     * Initialize accordion mode - Enhanced for mobile Safari/Brave
     */
    function initAccordionMode() {
        // Only run if body has accordion mode class
        if (!$('body').hasClass('wcepi-accordion-mode')) {
            // Make absolutely sure no accordion headers exist in tabs mode
            $('.wcepi-accordion-header').remove();
            return;
        }
        
        
        // Double check - if tabs are visible, don't create accordion
        if ($('.woocommerce-tabs ul.tabs').is(':visible') && $('.woocommerce-tabs ul.tabs').css('display') !== 'none') {
            $('.wcepi-accordion-header').remove();
            return;
        }
        
        // Check if accordion headers already exist
        if ($('.wcepi-accordion-header').length > 0) {
            return;
        }
        
        // Remove active class from all panels initially and clear inline styles
        $('.woocommerce-Tabs-panel').removeClass('active').css('display', 'none');
        
        // Create accordion headers
        $('.woocommerce-Tabs-panel').each(function() {
            var $panel = $(this);
            var panelId = $panel.attr('id');
            var $tab = $('.woocommerce-tabs ul.tabs li a[href="#' + panelId + '"]');
            var title = $tab.text();
            
            // Only create header if we found a matching tab
            if (title) {
                // Create header
                var $header = $('<div class="wcepi-accordion-header">' + title + '</div>');
                $header.insertBefore($panel);
                
                // Click handler with better event handling for all devices
                $header.on('click touchend', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    var $clickedHeader = $(this);
                    var isCurrentlyActive = $clickedHeader.hasClass('active');
                    
                    
                    // Remove active from all headers and panels
                    $('.wcepi-accordion-header').removeClass('active');
                    $('.woocommerce-Tabs-panel').removeClass('active').css({
                        'display': 'none',
                        'visibility': 'hidden',
                        'opacity': '0'
                    });
                    
                    // If it wasn't active, make it active
                    if (!isCurrentlyActive) {
                        $clickedHeader.addClass('active');
                        $panel.addClass('active').css({
                            'display': 'block',
                            'visibility': 'visible',
                            'opacity': '1',
                            'height': 'auto',
                            'max-height': 'none'
                        });
                        
                        // Mobile-specific additional enforcement
                        if (window.innerWidth <= 768) {
                            setTimeout(function() {
                                $panel.css({
                                    'display': 'block',
                                    'visibility': 'visible',
                                    'opacity': '1'
                                });
                                
                                // Force content sections visible
                                $panel.find('.wcepi-dimensions-content, .wcepi-specifications-content, .wcepi-warranty-content, .wcepi-faq-content, .wcepi-shipping-returns-content').css({
                                    'display': 'block',
                                    'visibility': 'visible',
                                    'opacity': '1'
                                });
                            }, 50);
                        }
                        
                        // Re-initialize FAQ accordion when FAQ panel opens
                        if ($clickedHeader.text().toLowerCase().indexOf('faq') !== -1) {
                            setTimeout(function() {
                                initFAQAccordion();
                            }, 100);
                        }
                        
                    } else {
                    }
                    
                    return false;
                });
            }
        });
        
        // Panels start closed to prevent auto-scroll issues. If configured,
        // open the Description section (or the first section) without any
        // scrolling so shoppers see content immediately.
        // Skip when the URL has a hash (e.g. #reviews) so anchor links
        // still land where they intend to.
        if (!window.location.hash && $('body').hasClass('wcepi-accordion-open-first') && $('.wcepi-accordion-header.active').length === 0) {
            var $defaultPanel = $('#tab-description');
            var $defaultHeader = ($defaultPanel.length && $defaultPanel.prev('.wcepi-accordion-header').length)
                ? $defaultPanel.prev('.wcepi-accordion-header')
                : $('.wcepi-accordion-header').first();

            if ($defaultHeader.length) {
                // Pin the current scroll position: expanding the panel can
                // make the browser or another script jump down to it
                var pinnedScrollY = window.pageYOffset;
                var restoreScroll = function() {
                    $('html, body').stop(true);
                    window.scrollTo(0, pinnedScrollY);
                };

                $defaultHeader.addClass('active');
                $defaultHeader.next('.woocommerce-Tabs-panel').addClass('active').css({
                    'display': 'block',
                    'visibility': 'visible',
                    'opacity': '1',
                    'height': 'auto',
                    'max-height': 'none'
                });

                // Restore immediately and again over the next moments to
                // beat any late scroll attempts during page load
                restoreScroll();
                setTimeout(restoreScroll, 50);
                setTimeout(restoreScroll, 250);
            }
        }
    }
    
    /**
     * Smooth scroll to tabs when clicking tab links
     */
    function smoothScrollToTabs() {
        // Tabs are hidden in accordion mode — never scroll there
        if ($('body').hasClass('wcepi-accordion-mode')) {
            return;
        }

        $('.woocommerce-tabs ul.tabs li a').on('click', function(e) {
            // Ignore synthetic clicks fired by scripts (e.g. WooCommerce
            // activating the first tab on page load) — only scroll for
            // genuine user clicks
            if (!e.originalEvent) {
                return;
            }

            var target = $(this).attr('href');

            if ($(target).length) {
                setTimeout(function() {
                    $('html, body').animate({
                        scrollTop: $(target).offset().top - 100
                    }, 500);
                }, 100);
            }
        });
    }
    
    /**
     * Initialize FAQ accordion functionality
     */
    function initFAQAccordion() {
        
        // Wait for FAQ content to be fully loaded
        setTimeout(function() {
            // Initially collapse all FAQ items except the first one
            $('.wcepi-faq-item').each(function(index) {
                if (index !== 0) {
                    $(this).addClass('collapsed');
                }
            });
            
            // Handle FAQ question clicks to toggle answers
            $(document).off('click.faq', '.wcepi-faq-question').on('click.faq', '.wcepi-faq-question', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var $item = $(this).closest('.wcepi-faq-item');
                
                // Toggle collapsed class
                $item.toggleClass('collapsed');
                
            });
            
        }, 500);
        
        // Re-initialize when tab panels become visible
        $('.woocommerce-Tabs-panel').on('woocommerce-tabs-show', function() {
            setTimeout(function() {
                if ($('.wcepi-faq-item:not(.collapsed)').length === 0) {
                    $('.wcepi-faq-item').first().removeClass('collapsed');
                }
            }, 100);
        });
    }
    
    /**
     * Handle download link clicks (optional tracking)
     */
    $('.wcepi-download-link').on('click', function() {
        var downloadTitle = $(this).find('.wcepi-download-title').text();
        
        // You can add analytics tracking here if needed
        if (typeof gtag !== 'undefined') {
            gtag('event', 'download', {
                'event_category': 'Product Downloads',
                'event_label': downloadTitle
            });
        }
    });
    
    /**
     * Lazy load images in tabs (if needed)
     */
    function lazyLoadTabImages() {
        $('.woocommerce-Tabs-panel').on('woocommerce-tabs-show', function() {
            $(this).find('img[data-src]').each(function() {
                var $img = $(this);
                $img.attr('src', $img.data('src'));
                $img.removeAttr('data-src');
            });
        });
    }
    
    /**
     * Print functionality for specific sections
     */
    $('.wcepi-print-section').on('click', function(e) {
        e.preventDefault();
        var sectionId = $(this).data('section');
        var $section = $('#' + sectionId);
        
        if ($section.length) {
            var printWindow = window.open('', '', 'height=600,width=800');
            printWindow.document.write('<html><head><title>Print</title>');
            printWindow.document.write('<link rel="stylesheet" href="' + wcepi_frontend.stylesheet_url + '">');
            printWindow.document.write('</head><body>');
            printWindow.document.write($section.html());
            printWindow.document.write('</body></html>');
            printWindow.document.close();
            printWindow.print();
        }
    });
    
    /**
     * Toggle additional specifications
     */
    $('.wcepi-toggle-specs').on('click', function(e) {
        e.preventDefault();
        var $hiddenSpecs = $('.wcepi-hidden-specs');
        
        if ($hiddenSpecs.is(':visible')) {
            $hiddenSpecs.slideUp(300);
            $(this).text($(this).data('show-text'));
        } else {
            $hiddenSpecs.slideDown(300);
            $(this).text($(this).data('hide-text'));
        }
    });
    
    /**
     * Copy specifications to clipboard
     */
    $('.wcepi-copy-specs').on('click', function(e) {
        e.preventDefault();
        var specsText = '';
        
        $('.wcepi-specifications-table tr').each(function() {
            var label = $(this).find('th').text();
            var value = $(this).find('td').text();
            specsText += label + ': ' + value + '\n';
        });
        
        // Copy to clipboard
        if (navigator.clipboard) {
            navigator.clipboard.writeText(specsText).then(function() {
                showNotification('Specifications copied to clipboard!');
            });
        } else {
            // Fallback for older browsers
            var $temp = $('<textarea>');
            $('body').append($temp);
            $temp.val(specsText).select();
            document.execCommand('copy');
            $temp.remove();
            showNotification('Specifications copied to clipboard!');
        }
    });
    
    /**
     * Show notification
     */
    function showNotification(message) {
        var $notification = $('<div class="wcepi-notification">' + message + '</div>');
        $('body').append($notification);
        
        setTimeout(function() {
            $notification.addClass('show');
        }, 100);
        
        setTimeout(function() {
            $notification.removeClass('show');
            setTimeout(function() {
                $notification.remove();
            }, 300);
        }, 3000);
    }
    
    /**
     * Expand/collapse long descriptions
     */
    $('.wcepi-expand-description').on('click', function(e) {
        e.preventDefault();
        var $description = $(this).prev('.wcepi-long-description');
        
        if ($description.hasClass('expanded')) {
            $description.removeClass('expanded');
            $(this).text($(this).data('expand-text'));
        } else {
            $description.addClass('expanded');
            $(this).text($(this).data('collapse-text'));
        }
    });
    
    /**
     * Image zoom in specifications (if images are present)
     */
    $('.wcepi-spec-image').on('click', function() {
        var imgSrc = $(this).attr('src');
        var $modal = $('<div class="wcepi-image-modal"><div class="wcepi-modal-content"><span class="wcepi-close">&times;</span><img src="' + imgSrc + '"></div></div>');
        
        $('body').append($modal);
        $modal.fadeIn(300);
        
        $modal.find('.wcepi-close, .wcepi-image-modal').on('click', function() {
            $modal.fadeOut(300, function() {
                $modal.remove();
            });
        });
        
        $modal.find('.wcepi-modal-content').on('click', function(e) {
            e.stopPropagation();
        });
    });
    
    /**
     * Compare specifications (if multiple products)
     */
    $('.wcepi-compare-toggle').on('change', function() {
        var productId = $(this).data('product-id');
        
        if ($(this).is(':checked')) {
            addToComparison(productId);
        } else {
            removeFromComparison(productId);
        }
    });
    
    function addToComparison(productId) {
        var comparison = getComparison();
        if (comparison.indexOf(productId) === -1) {
            comparison.push(productId);
            saveComparison(comparison);
        }
    }
    
    function removeFromComparison(productId) {
        var comparison = getComparison();
        var index = comparison.indexOf(productId);
        if (index > -1) {
            comparison.splice(index, 1);
            saveComparison(comparison);
        }
    }
    
    function getComparison() {
        var comparison = localStorage.getItem('wcepi_comparison');
        return comparison ? JSON.parse(comparison) : [];
    }
    
    function saveComparison(comparison) {
        localStorage.setItem('wcepi_comparison', JSON.stringify(comparison));
        updateComparisonCount();
    }
    
    function updateComparisonCount() {
        var count = getComparison().length;
        $('.wcepi-comparison-count').text(count);
        
        if (count > 0) {
            $('.wcepi-comparison-bar').slideDown(300);
        } else {
            $('.wcepi-comparison-bar').slideUp(300);
        }
    }
    
    // Initialize comparison count on page load
    updateComparisonCount();
    
})(jQuery);