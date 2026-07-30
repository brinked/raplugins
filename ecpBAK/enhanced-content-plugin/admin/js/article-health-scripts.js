/**
 * Article Health Dashboard Scripts
 */

(function($) {
    'use strict';

    var ECP_ArticleHealth = {

        /**
         * Initialize
         */
        init: function() {
            this.initFilters();
            this.initSorting();
            this.highlightIssues();
            this.initRecalculate();
        },

        /**
         * Initialize filter functionality
         */
        initFilters: function() {
            // Highlight active filter
            $('.map-health-filters .button').on('click', function() {
                $('.map-health-filters .button').removeClass('button-primary');
                $(this).addClass('button-primary');
            });
        },

        /**
         * Initialize sorting functionality
         */
        initSorting: function() {
            // Toggle sort order when clicking same column
            $('.map-health-sort a:not(.button)').on('click', function(e) {
                var currentOrder = new URLSearchParams(window.location.search).get('order');
                var orderby = new URLSearchParams(window.location.search).get('orderby');
                var clickedOrderby = $(this).attr('href').match(/orderby=([^&]*)/);

                if (clickedOrderby && clickedOrderby[1] === orderby) {
                    // Same column, toggle order
                    e.preventDefault();
                    var newOrder = currentOrder === 'ASC' ? 'DESC' : 'ASC';
                    var newUrl = $(this).attr('href').replace(/order=(ASC|DESC)/, 'order=' + newOrder);
                    window.location.href = newUrl;
                }
            });
        },

        /**
         * Highlight rows with issues
         */
        highlightIssues: function() {
            // Add pulsing animation to warning icons on first load
            $('.map-health-warning .dashicons-warning, .map-health-critical .dashicons-warning').each(function() {
                $(this).css({
                    'animation': 'pulse 2s infinite'
                });
            });

            // Add tooltip behavior (guarded — a missing jQuery UI Tooltip
            // must not throw and abort the rest of init)
            if ($.fn.tooltip) {
                $('[title]').each(function() {
                    $(this).tooltip({
                        position: { my: "center bottom-10", at: "center top" }
                    });
                });
            }
        },

        /**
         * Initialize recalculate button
         */
        initRecalculate: function() {
            var $button = $('#map-recalculate-health');
            var $status = $('#map-recalculate-status');

            $button.on('click', function() {
                var $btn = $(this);
                var originalText = $btn.html();

                // Disable button and show loading
                $btn.prop('disabled', true).html(
                    '<span class="dashicons dashicons-update" style="vertical-align: middle; animation: rotation 1s infinite linear;"></span> ' +
                    'Recalculating...'
                );
                $status.hide();

                function showProgress(text, color) {
                    $status.empty().append(
                        $('<span>', { css: { color: color || '#646970' }, text: text })
                    ).show();
                }

                function fail(message) {
                    showProgress(message, '#d63638');
                    $btn.prop('disabled', false).html(originalText);
                }

                // Process one batch at a time so large sites never time out
                function runBatch(offset) {
                    $.ajax({
                        url: mapArticleHealth.ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'map_recalculate_health',
                            nonce: mapArticleHealth.nonce,
                            offset: offset
                        },
                        success: function(response) {
                            if (!response.success) {
                                fail('Error: ' + response.data);
                                return;
                            }

                            if (response.data.done) {
                                showProgress(response.data.message, '#00a32a');
                                // Reload after a short delay to show updated stats
                                setTimeout(function() {
                                    location.reload();
                                }, 1200);
                            } else {
                                showProgress('Recalculated ' + response.data.offset + ' of ' + response.data.total + '…');
                                runBatch(response.data.offset);
                            }
                        },
                        error: function() {
                            fail('Error occurred. Please try again.');
                        }
                    });
                }

                runBatch(0);
            });
        }
    };

    // Add CSS for rotation animation
    $('<style>@keyframes rotation { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }</style>').appendTo('head');

    // Initialize on document ready
    $(document).ready(function() {
        ECP_ArticleHealth.init();
    });

})(jQuery);
