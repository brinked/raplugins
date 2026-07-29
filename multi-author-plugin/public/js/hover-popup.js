/**
 * Public JavaScript for Multi-Author Plugin
 * Handles contributor hover popups - INSTANT (no AJAX delay)
 */

(function($) {
    'use strict';

    var MAP_Public = {
        activePopup: null,
        hideTimeout: null,

        /**
         * Initialize
         */
        init: function() {
            this.initHoverPopups();
            this.initTouchSupport();
            this.initKeyboardSupport();
        },

        /**
         * Initialize hover popups - instant show on hover
         */
        initHoverPopups: function() {
            var self = this;

            // Mouse enters contributor wrapper (contains both link and popup)
            $(document).on('mouseenter', '.map-contributor-wrapper', function() {
                clearTimeout(self.hideTimeout);
                var $wrapper = $(this);
                var $popup = $wrapper.find('.map-popup-content');

                if ($popup.length) {
                    // Hide any other open popups instantly
                    $('.map-popup-content.is-visible').not($popup).removeClass('is-visible');

                    // Show this popup
                    $popup.addClass('is-visible');
                    self.activePopup = $popup;

                    // Position the popup
                    self.positionPopup($popup, $wrapper);
                }
            });

            // Mouse leaves contributor wrapper
            $(document).on('mouseleave', '.map-contributor-wrapper', function() {
                var $popup = $(this).find('.map-popup-content');

                // Small delay before hiding to allow moving to popup
                self.hideTimeout = setTimeout(function() {
                    $popup.removeClass('is-visible');
                    if (self.activePopup && self.activePopup[0] === $popup[0]) {
                        self.activePopup = null;
                    }
                }, 100);
            });

            // Keep popup open when hovering over it
            $(document).on('mouseenter', '.map-popup-content', function() {
                clearTimeout(self.hideTimeout);
            });

            // Handle escape key
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape' && self.activePopup) {
                    self.activePopup.removeClass('is-visible');
                    self.activePopup = null;
                }
            });
        },

        /**
         * Initialize touch/mobile support
         */
        initTouchSupport: function() {
            var self = this;
            // Only hijack clicks on devices with no hover capability
            // (phones/tablets). Touch-capable laptops keep normal clicks.
            var isTouchOnly = window.matchMedia
                ? window.matchMedia('(hover: none)').matches
                : (('ontouchstart' in window) || (navigator.maxTouchPoints > 0));

            if (isTouchOnly) {
                // First tap opens the popup; a second tap on the link
                // follows it, so the profile page stays reachable.
                $(document).on('click', '.map-contributor-wrapper .map-contributor-link', function(e) {
                    var $wrapper = $(this).closest('.map-contributor-wrapper');
                    var $popup = $wrapper.find('.map-popup-content');

                    if ($popup.length && !$popup.hasClass('is-visible')) {
                        e.preventDefault();

                        // Hide other popups
                        $('.map-popup-content.is-visible').removeClass('is-visible');
                        $popup.addClass('is-visible');
                        self.positionPopup($popup, $wrapper);
                    }
                });

                // Close popup when tapping outside
                $(document).on('click', function(e) {
                    if (!$(e.target).closest('.map-contributor-wrapper').length) {
                        $('.map-popup-content.is-visible').removeClass('is-visible');
                    }
                });
            }

        },

        /**
         * Keyboard accessibility: show the popup while focus is inside the
         * wrapper, hide it when focus leaves (Escape already handled above)
         */
        initKeyboardSupport: function() {
            var self = this;

            $('.map-contributor-wrapper .map-contributor-link').attr('aria-haspopup', 'true');

            $(document).on('focusin', '.map-contributor-wrapper', function() {
                clearTimeout(self.hideTimeout);
                var $wrapper = $(this);
                var $popup = $wrapper.find('.map-popup-content');

                if ($popup.length && !$popup.hasClass('is-visible')) {
                    $('.map-popup-content.is-visible').not($popup).removeClass('is-visible');
                    $popup.addClass('is-visible');
                    self.activePopup = $popup;
                    self.positionPopup($popup, $wrapper);
                }
            });

            $(document).on('focusout', '.map-contributor-wrapper', function() {
                var $wrapper = $(this);
                var $popup = $wrapper.find('.map-popup-content');

                // Wait a tick so focus can land on the next element first
                self.hideTimeout = setTimeout(function() {
                    if (!$.contains($wrapper[0], document.activeElement)) {
                        $popup.removeClass('is-visible');
                        if (self.activePopup && self.activePopup[0] === $popup[0]) {
                            self.activePopup = null;
                        }
                    }
                }, 100);
            });
        },

        /**
         * Position popup to avoid going off screen
         */
        positionPopup: function($popup, $wrapper) {
            var wrapperOffset = $wrapper.offset();
            var popupWidth = $popup.outerWidth();
            var popupHeight = $popup.outerHeight();
            var windowWidth = $(window).width();
            var windowHeight = $(window).height();
            var scrollTop = $(window).scrollTop();

            // Reset any previous positioning
            $popup.removeClass('map-popup-above map-popup-left map-popup-right');
            $popup.css({ left: '', right: '' });

            // Check horizontal overflow
            var wrapperLeft = wrapperOffset.left;
            var wrapperWidth = $wrapper.outerWidth();
            var popupLeft = wrapperLeft + (wrapperWidth / 2) - (popupWidth / 2);

            if (popupLeft < 10) {
                // Popup would go off left edge - align to left
                $popup.addClass('map-popup-left');
            } else if (popupLeft + popupWidth > windowWidth - 10) {
                // Popup would go off right edge - align to right
                $popup.addClass('map-popup-right');
            }

            // Check vertical overflow - if not enough space below, show above
            var wrapperBottom = wrapperOffset.top + $wrapper.outerHeight();
            var spaceBelow = (scrollTop + windowHeight) - wrapperBottom;

            if (spaceBelow < popupHeight + 20) {
                $popup.addClass('map-popup-above');
            }
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        MAP_Public.init();
    });

})(jQuery);
