/**
 * Multi-Author Plugin - Settings Page Scripts
 */

(function($) {
    'use strict';
    
    $(document).ready(function() {
        // Initialize WordPress color pickers
        if ($.fn.wpColorPicker) {
            $('.map-color-picker').wpColorPicker({
                change: function(event, ui) {
                    // Optional: Add live preview functionality here
                },
                clear: function() {
                    // Optional: Handle color clear
                }
            });
        }
        
        // Add change detection for unsaved changes warning.
        // Scope to the settings form only — binding to every form on the
        // page would arm the warning when e.g. Screen Options is toggled.
        var $settingsForm = $('form[action="options.php"]');
        var formChanged = false;

        $settingsForm.find('input, select, textarea').on('change', function() {
            formChanged = true;
        });

        // Warn user about unsaved changes (browsers show their own generic
        // message; returning any string just enables the prompt)
        $(window).on('beforeunload', function() {
            if (formChanged) {
                return 'You have unsaved changes. Are you sure you want to leave?';
            }
        });

        // Don't warn when submitting the form
        $settingsForm.on('submit', function() {
            formChanged = false;
        });
        
        // ----- Live preview -----
        var $preview = $('#map-live-preview');

        function fieldVal(field) {
            return $settingsForm.find('[name="map_settings[' + field + ']"]').val();
        }

        function fieldChecked(field) {
            return $settingsForm.find('[name="map_settings[' + field + ']"]').is(':checked');
        }

        function updatePreview() {
            if (!$preview.length) {
                return;
            }

            // Primary byline
            $preview.find('.map-preview-primary').css('font-size', parseInt(fieldVal('primary_font_size'), 10) + 'px');
            $preview.find('.map-preview-primary-avatar')
                .css({
                    width: parseInt(fieldVal('primary_avatar_size'), 10) + 'px',
                    height: parseInt(fieldVal('primary_avatar_size'), 10) + 'px'
                })
                .toggle(fieldChecked('primary_show_avatar'));

            // Contributor card
            $preview.find('.map-preview-avatar')
                .css({
                    width: parseInt(fieldVal('avatar_size'), 10) + 'px',
                    height: parseInt(fieldVal('avatar_size'), 10) + 'px'
                })
                .toggle(fieldChecked('show_contributor_avatars'));

            $preview.find('.map-preview-role').css({
                'font-size': parseInt(fieldVal('role_label_font_size'), 10) + 'px',
                color: fieldVal('role_label_color') || '#999999'
            }).text(fieldVal('label_reviewed_by') || 'Reviewed by');

            $preview.find('.map-preview-name').css({
                'font-size': parseInt(fieldVal('name_font_size'), 10) + 'px',
                color: fieldVal('name_color') || '#000000'
            });

            $preview.find('.map-preview-title').css({
                'font-size': parseInt(fieldVal('title_font_size'), 10) + 'px',
                color: fieldVal('title_color') || '#666666'
            });
        }

        if ($preview.length) {
            $settingsForm.on('input change', 'input, select', updatePreview);

            // Color pickers change via their own widget events
            if ($.fn.wpColorPicker) {
                $('.map-color-picker').wpColorPicker('option', 'change', function() {
                    // Widget updates the input value after this event fires
                    setTimeout(updatePreview, 50);
                });
                $('.map-color-picker').wpColorPicker('option', 'clear', function() {
                    setTimeout(updatePreview, 50);
                });
            }
        }

        // Number field validation
        $('input[type="number"]').on('change', function() {
            var $this = $(this);
            var min = parseInt($this.attr('min'));
            var max = parseInt($this.attr('max'));
            var val = parseInt($this.val());
            
            if (val < min) {
                $this.val(min);
            } else if (val > max) {
                $this.val(max);
            }
        });
        
        // Add tooltips or help text if needed
        $('.form-table input[type="number"]').each(function() {
            var $this = $(this);
            var min = $this.attr('min');
            var max = $this.attr('max');
            
            if (min && max) {
                var helpText = 'Value must be between ' + min + ' and ' + max;
                if (!$this.next('.description').length) {
                    $this.after('<p class="description">' + helpText + '</p>');
                }
            }
        });
    });
    
})(jQuery);