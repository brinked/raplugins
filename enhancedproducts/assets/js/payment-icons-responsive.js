/**
 * Payment Icons Responsive Sizing
 * Adjusts icon sizes based on screen width using settings from admin
 */
(function($) {
    'use strict';
    
    function adjustPaymentIconSizes() {
        var icons = $('.wcepi-payment-icon');
        
        if (icons.length === 0) return;
        
        var windowWidth = $(window).width();
        var firstIcon = icons.first();
        var desktopSize = firstIcon.data('desktop-size') || 52;
        var tabletSize = firstIcon.data('tablet-size') || 48;
        var mobileSize = firstIcon.data('mobile-size') || 45;
        
        var size;
        if (windowWidth <= 480) {
            size = mobileSize;
        } else if (windowWidth <= 768) {
            size = tabletSize;
        } else {
            size = desktopSize;
        }
        
        icons.each(function() {
            $(this).css({
                'width': size + 'px',
                'height': size + 'px',
                'max-width': size + 'px',
                'max-height': size + 'px'
            }).attr({
                'width': size,
                'height': size
            });
        });
    }
    
    // Run on page load
    $(document).ready(function() {
        adjustPaymentIconSizes();
    });
    
    // Run on window resize (debounced)
    var resizeTimer;
    $(window).on('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            adjustPaymentIconSizes();
        }, 250);
    });
    
})(jQuery);