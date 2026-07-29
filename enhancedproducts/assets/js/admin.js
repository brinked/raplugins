/**
 * Admin JavaScript
 * WooCommerce Enhanced Product Info
 */

(function($) {
    'use strict';
    
    $(document).ready(function() {
        
        // Initialize repeater fields
        initRepeaterFields();
        
        // Initialize file uploads
        initFileUploads();
        
        // Initialize settings page
        initSettingsPage();
        
        // Initialize color pickers
        initColorPickers();

        // Initialize warranty templates (product edit page)
        initWarrantyTemplates();

        // Initialize shipping/returns content templates (product edit page)
        initContentTemplates();

    });

    /**
     * Content templates: apply/save/delete reusable rich-text presets
     * (shipping and returns policies) on the product edit page.
     * Each toolbar declares its type and target editor via data attributes.
     */
    function initContentTemplates() {
        if (typeof wcepi_admin === 'undefined' || !wcepi_admin.content_template_nonce) {
            return;
        }

        var allTemplates = wcepi_admin.content_templates || {};

        function slugify(name) {
            return name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
        }

        $('.wcepi-content-templates').each(function() {
            var $toolbar = $(this);
            var type = $toolbar.data('template-type');
            var editorId = $toolbar.data('editor-id');
            var templates = allTemplates[type] || {};
            var $select = $toolbar.find('.wcepi-content-template-select');
            var $status = $toolbar.find('.wcepi-template-status');
            var statusTimer = null;

            function setStatus(message, isError) {
                $status.text(message)
                    .removeClass('wcepi-status-success wcepi-status-error')
                    .addClass(isError ? 'wcepi-status-error' : 'wcepi-status-success');
                if (statusTimer) {
                    clearTimeout(statusTimer);
                }
                statusTimer = setTimeout(function() {
                    $status.text('').removeClass('wcepi-status-success wcepi-status-error');
                }, 6000);
            }

            function getContent() {
                if (typeof tinymce !== 'undefined') {
                    var editor = tinymce.get(editorId);
                    if (editor && !editor.isHidden()) {
                        return editor.getContent();
                    }
                }
                return $('#' + editorId).val() || '';
            }

            function setContent(html) {
                html = html || '';
                if (typeof tinymce !== 'undefined') {
                    var editor = tinymce.get(editorId);
                    if (editor) {
                        editor.setContent(html);
                    }
                }
                $('#' + editorId).val(html);
            }

            // Apply the selected template into the editor (form only —
            // nothing is stored until the product is updated)
            $toolbar.find('.wcepi-content-template-apply').on('click', function() {
                var id = $select.val();
                if (!id || !templates[id]) {
                    setStatus('Select a template to apply.', true);
                    return;
                }
                setContent(templates[id].content);
                setStatus('Template "' + templates[id].name + '" applied — update the product to save.', false);
            });

            // Save the current editor content as a template
            $toolbar.find('.wcepi-content-template-save').on('click', function() {
                var $button = $(this);
                var selectedId = $select.val();
                var defaultName = (selectedId && templates[selectedId]) ? templates[selectedId].name : '';

                var name = window.prompt('Template name (reusing an existing name updates that template):', defaultName);
                if (name === null) {
                    return;
                }
                name = $.trim(name);
                if (!name) {
                    setStatus('Template name is required.', true);
                    return;
                }

                var slug = slugify(name);
                if (templates[slug] && !window.confirm('A template named "' + templates[slug].name + '" already exists. Overwrite it?')) {
                    return;
                }

                $button.prop('disabled', true);

                $.post(ajaxurl, {
                    action: 'wcepi_save_content_template',
                    nonce: wcepi_admin.content_template_nonce,
                    template_type: type,
                    name: name,
                    content: getContent()
                }).done(function(response) {
                    if (response && response.success) {
                        templates[response.data.id] = response.data.template;
                        var $option = $select.find('option[value="' + response.data.id + '"]');
                        if ($option.length) {
                            $option.text(response.data.template.name);
                        } else {
                            $select.append($('<option>').val(response.data.id).text(response.data.template.name));
                        }
                        $select.val(response.data.id);
                        setStatus(response.data.message, false);
                    } else {
                        setStatus((response && response.data && response.data.message) || 'Could not save the template.', true);
                    }
                }).fail(function() {
                    setStatus('Could not save the template. Please try again.', true);
                }).always(function() {
                    $button.prop('disabled', false);
                });
            });

            // Delete the selected template
            $toolbar.find('.wcepi-content-template-delete').on('click', function() {
                var $button = $(this);
                var id = $select.val();
                if (!id || !templates[id]) {
                    setStatus('Select a template to delete.', true);
                    return;
                }
                if (!window.confirm('Delete the template "' + templates[id].name + '"? Products that used it keep their saved content.')) {
                    return;
                }

                $button.prop('disabled', true);

                $.post(ajaxurl, {
                    action: 'wcepi_delete_content_template',
                    nonce: wcepi_admin.content_template_nonce,
                    template_type: type,
                    template_id: id
                }).done(function(response) {
                    if (response && response.success) {
                        delete templates[id];
                        $select.find('option[value="' + id + '"]').remove();
                        $select.val('');
                        setStatus(response.data.message, false);
                    } else {
                        setStatus((response && response.data && response.data.message) || 'Could not delete the template.', true);
                    }
                }).fail(function() {
                    setStatus('Could not delete the template. Please try again.', true);
                }).always(function() {
                    $button.prop('disabled', false);
                });
            });
        });
    }

    /**
     * Warranty templates: apply/save/delete reusable warranty presets
     * on the product edit page.
     */
    function initWarrantyTemplates() {
        var $select = $('#wcepi-warranty-template-select');
        if (!$select.length || typeof wcepi_admin === 'undefined' || !wcepi_admin.warranty_template_nonce) {
            return;
        }

        var templates = wcepi_admin.warranty_templates || {};
        var $status = $('#wcepi-warranty-template-status');
        var statusTimer = null;

        function setStatus(message, isError) {
            $status.text(message)
                .removeClass('wcepi-status-success wcepi-status-error')
                .addClass(isError ? 'wcepi-status-error' : 'wcepi-status-success');
            if (statusTimer) {
                clearTimeout(statusTimer);
            }
            statusTimer = setTimeout(function() {
                $status.text('').removeClass('wcepi-status-success wcepi-status-error');
            }, 6000);
        }

        function getEditorContent() {
            if (typeof tinymce !== 'undefined') {
                var editor = tinymce.get('wcepi_warranty_content');
                if (editor && !editor.isHidden()) {
                    return editor.getContent();
                }
            }
            return $('#wcepi_warranty_content').val() || '';
        }

        function setEditorContent(html) {
            html = html || '';
            if (typeof tinymce !== 'undefined') {
                var editor = tinymce.get('wcepi_warranty_content');
                if (editor) {
                    editor.setContent(html);
                }
            }
            $('#wcepi_warranty_content').val(html);
        }

        // Same slugification WordPress applies server-side (close enough for
        // the overwrite confirmation; the server decides the real key)
        function slugify(name) {
            return name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
        }

        // Apply the selected template to the warranty fields (form only —
        // nothing is stored until the product is updated)
        $('#wcepi-warranty-template-apply').on('click', function() {
            var id = $select.val();
            if (!id || !templates[id]) {
                setStatus('Select a template to apply.', true);
                return;
            }
            var t = templates[id];

            // Set type first without triggering change (the change handler
            // force-checks the lifetime box for LifetimeWarranty; the template
            // itself is the source of truth here)
            $('#wcepi_warranty_type').val(t.type || '');
            $('#wcepi_warranty_period').val(parseInt(t.period, 10) > 0 ? t.period : '');
            $('#wcepi_warranty_unit').val(t.unit || 'years');
            $('#wcepi_warranty_lifetime').prop('checked', t.lifetime === 'yes').trigger('change');
            $('#wcepi_warranty_display_price').prop('checked', t.display_price === 'yes');
            $('#wcepi_warranty_url').val(t.url || '');
            $('#wcepi_warranty_url_text').val(t.url_text || '');
            $('#wcepi_warranty_file').val(t.file || '');
            $('#wcepi_warranty_file_text').val(t.file_text || '');
            setEditorContent(t.content);

            // Keep the warranty file Remove button in sync
            if (t.file) {
                if (!$('.wcepi-remove-warranty-file').length) {
                    $('.wcepi-upload-warranty-file').after('<button type="button" class="button wcepi-remove-warranty-file">Remove</button>');
                }
            } else {
                $('.wcepi-remove-warranty-file').remove();
            }

            setStatus('Template "' + t.name + '" applied — update the product to save.', false);
        });

        // Save the current warranty fields as a template
        $('#wcepi-warranty-template-save').on('click', function() {
            var $button = $(this);
            var selectedId = $select.val();
            var defaultName = (selectedId && templates[selectedId]) ? templates[selectedId].name : '';

            var name = window.prompt('Template name (reusing an existing name updates that template):', defaultName);
            if (name === null) {
                return;
            }
            name = $.trim(name);
            if (!name) {
                setStatus('Template name is required.', true);
                return;
            }

            var slug = slugify(name);
            if (templates[slug] && !window.confirm('A template named "' + templates[slug].name + '" already exists. Overwrite it?')) {
                return;
            }

            $button.prop('disabled', true);

            $.post(ajaxurl, {
                action: 'wcepi_save_warranty_template',
                nonce: wcepi_admin.warranty_template_nonce,
                name: name,
                lifetime: $('#wcepi_warranty_lifetime').is(':checked') ? 'yes' : 'no',
                period: $('#wcepi_warranty_period').val(),
                unit: $('#wcepi_warranty_unit').val(),
                type: $('#wcepi_warranty_type').val(),
                display_price: $('#wcepi_warranty_display_price').is(':checked') ? 'yes' : 'no',
                url: $('#wcepi_warranty_url').val(),
                url_text: $('#wcepi_warranty_url_text').val(),
                file: $('#wcepi_warranty_file').val(),
                file_text: $('#wcepi_warranty_file_text').val(),
                content: getEditorContent()
            }).done(function(response) {
                if (response && response.success) {
                    templates[response.data.id] = response.data.template;
                    var $option = $select.find('option[value="' + response.data.id + '"]');
                    if ($option.length) {
                        $option.text(response.data.template.name);
                    } else {
                        $select.append($('<option>').val(response.data.id).text(response.data.template.name));
                    }
                    $select.val(response.data.id);
                    setStatus(response.data.message, false);
                } else {
                    setStatus((response && response.data && response.data.message) || 'Could not save the template.', true);
                }
            }).fail(function() {
                setStatus('Could not save the template. Please try again.', true);
            }).always(function() {
                $button.prop('disabled', false);
            });
        });

        // Delete the selected template
        $('#wcepi-warranty-template-delete').on('click', function() {
            var $button = $(this);
            var id = $select.val();
            if (!id || !templates[id]) {
                setStatus('Select a template to delete.', true);
                return;
            }
            if (!window.confirm('Delete the template "' + templates[id].name + '"? Products that used it keep their warranty values.')) {
                return;
            }

            $button.prop('disabled', true);

            $.post(ajaxurl, {
                action: 'wcepi_delete_warranty_template',
                nonce: wcepi_admin.warranty_template_nonce,
                template_id: id
            }).done(function(response) {
                if (response && response.success) {
                    delete templates[id];
                    $select.find('option[value="' + id + '"]').remove();
                    $select.val('');
                    setStatus(response.data.message, false);
                } else {
                    setStatus((response && response.data && response.data.message) || 'Could not delete the template.', true);
                }
            }).fail(function() {
                setStatus('Could not delete the template. Please try again.', true);
            }).always(function() {
                $button.prop('disabled', false);
            });
        });
    }
    
    /**
     * Initialize WordPress color pickers
     */
    function initColorPickers() {
        // Initialize all color picker fields
        if (typeof $.fn.wpColorPicker !== 'undefined') {
            $('.wcepi-color-picker').each(function() {
                var $input = $(this);
                $input.wpColorPicker({
                    change: function(event, ui) {
                        // Update the input value when color changes
                        var color = ui.color.toString();
                        $input.val(color);
                        // Trigger change event for unsaved changes detection
                        $input.trigger('change');
                    },
                    clear: function() {
                        // Clear the value when clear button is clicked
                        $input.val('');
                        $input.trigger('change');
                    }
                });
            });
            
            console.log('Color pickers initialized:', $('.wcepi-color-picker').length);
        } else {
            console.error('WordPress color picker not available');
        }
    }
    
    /**
     * Initialize repeater fields
     */
    function initRepeaterFields() {
        
        // Add Dimension
        $('.wcepi-add-dimension').on('click', function(e) {
            e.preventDefault();
            var $container = $('#wcepi-custom-dimensions');
            var index = $container.find('.wcepi-repeater-row').length;
            
            var html = '<div class="wcepi-repeater-row">' +
                '<input type="text" name="wcepi_custom_dimensions[' + index + '][label]" ' +
                'placeholder="Label (e.g., Diameter)" class="regular-text">' +
                '<input type="text" name="wcepi_custom_dimensions[' + index + '][value]" ' +
                'placeholder="Value (e.g., 10 inches)" class="regular-text">' +
                '<button type="button" class="button wcepi-remove-row">Remove</button>' +
                '</div>';
            
            $container.append(html);
        });
        
        // Add Specification
        $('.wcepi-add-specification').on('click', function(e) {
            e.preventDefault();
            var $container = $('#wcepi-specifications');
            var index = $container.find('.wcepi-repeater-row').length;

            var html = '<div class="wcepi-repeater-row">' +
                '<span class="wcepi-drag-handle dashicons dashicons-menu" title="Drag to reorder"></span>' +
                '<input type="text" name="wcepi_specifications[' + index + '][label]" ' +
                'placeholder="Label (e.g., Material)" class="regular-text">' +
                '<input type="text" name="wcepi_specifications[' + index + '][value]" ' +
                'placeholder="Value (e.g., Stainless Steel)" class="regular-text">' +
                '<input type="url" name="wcepi_specifications[' + index + '][url]" ' +
                'placeholder="Link URL (optional)" class="regular-text">' +
                '<button type="button" class="button wcepi-remove-row">Remove</button>' +
                '</div>';

            $container.append(html);
        });
        
        // Add Download
        $('.wcepi-add-download').on('click', function(e) {
            e.preventDefault();
            var $container = $('#wcepi-downloads');
            var index = $container.find('.wcepi-repeater-row').length;
            
            var html = '<div class="wcepi-repeater-row">' +
                '<input type="text" name="wcepi_downloads[' + index + '][title]" ' +
                'placeholder="Title (e.g., User Manual)" class="regular-text">' +
                '<input type="url" name="wcepi_downloads[' + index + '][url]" ' +
                'placeholder="URL or upload PDF" class="regular-text wcepi-download-url">' +
                '<button type="button" class="button wcepi-upload-file" data-index="' + index + '">Upload</button>' +
                '<button type="button" class="button wcepi-remove-row">Remove</button>' +
                '</div>';
            
            $container.append(html);
        });
        
        // Add FAQ
        $('.wcepi-add-faq').on('click', function(e) {
            e.preventDefault();
            var $container = $('#wcepi-faqs');
            var index = $container.find('.wcepi-faq-row').length;
            
            var html = '<div class="wcepi-faq-row">' +
                '<div class="wcepi-faq-question">' +
                '<label>Question:</label>' +
                '<input type="text" name="wcepi_faqs[' + index + '][question]" ' +
                'placeholder="Enter question" class="widefat">' +
                '</div>' +
                '<div class="wcepi-faq-answer">' +
                '<label>Answer:</label>' +
                '<textarea name="wcepi_faqs[' + index + '][answer]" ' +
                'rows="3" placeholder="Enter answer" class="widefat"></textarea>' +
                '</div>' +
                '<button type="button" class="button wcepi-remove-row">Remove FAQ</button>' +
                '</div>';
            
            $container.append(html);
        });
        
        // Add Custom Section
        $('.wcepi-add-custom-section').on('click', function(e) {
            e.preventDefault();
            var $container = $('#wcepi-custom-sections');
            var index = $container.find('.wcepi-custom-section-row').length;
            
            var html = '<div class="wcepi-custom-section-row">' +
                '<div class="wcepi-custom-section-header">' +
                '<input type="text" name="wcepi_custom_sections[' + index + '][name]" ' +
                'placeholder="Section Name (e.g., Technical Details)" class="widefat wcepi-section-name">' +
                '<select name="wcepi_custom_sections[' + index + '][type]" class="wcepi-section-type" data-index="' + index + '">' +
                '<option value="fields">Specification Fields (Label/Value)</option>' +
                '<option value="textarea">Rich Text Area (WYSIWYG)</option>' +
                '</select>' +
                '<button type="button" class="button wcepi-remove-custom-section">Remove Section</button>' +
                '</div>' +
                '<div class="wcepi-section-fields-content">' +
                '<div class="wcepi-section-fields-container" data-section-index="' + index + '"></div>' +
                '<button type="button" class="button wcepi-add-section-field" data-section-index="' + index + '">Add Field</button>' +
                '</div>' +
                '<div class="wcepi-section-textarea-content" style="display:none;">' +
                '<textarea name="wcepi_custom_sections[' + index + '][content]" rows="8" class="widefat"></textarea>' +
                '</div>' +
                '</div>';
            
            $container.append(html);
        });
        
        // Toggle section type
        $(document).on('change', '.wcepi-section-type', function() {
            var $row = $(this).closest('.wcepi-custom-section-row');
            var type = $(this).val();
            
            if (type === 'fields') {
                $row.find('.wcepi-section-fields-content').show();
                $row.find('.wcepi-section-textarea-content').hide();
            } else {
                $row.find('.wcepi-section-fields-content').hide();
                $row.find('.wcepi-section-textarea-content').show();
            }
        });
        
        // Add section field
        $(document).on('click', '.wcepi-add-section-field', function(e) {
            e.preventDefault();
            var sectionIndex = $(this).data('section-index');
            var $container = $(this).siblings('.wcepi-section-fields-container');
            var fieldIndex = $container.find('.wcepi-section-field-row').length;
            
            var html = '<div class="wcepi-section-field-row">' +
                '<input type="text" name="wcepi_custom_sections[' + sectionIndex + '][fields][' + fieldIndex + '][label]" ' +
                'placeholder="Label" class="regular-text">' +
                '<input type="text" name="wcepi_custom_sections[' + sectionIndex + '][fields][' + fieldIndex + '][value]" ' +
                'placeholder="Value" class="regular-text">' +
                '<button type="button" class="button wcepi-remove-section-field">Remove</button>' +
                '</div>';
            
            $container.append(html);
        });
        
        // Remove section field
        $(document).on('click', '.wcepi-remove-section-field', function(e) {
            e.preventDefault();
            $(this).closest('.wcepi-section-field-row').fadeOut(300, function() {
                $(this).remove();
            });
        });
        
        // Remove custom section
        $(document).on('click', '.wcepi-remove-custom-section', function(e) {
            e.preventDefault();
            $(this).closest('.wcepi-custom-section-row').fadeOut(300, function() {
                $(this).remove();
            });
        });
        
        // Remove Row
        $(document).on('click', '.wcepi-remove-row', function(e) {
            e.preventDefault();
            var $row = $(this).closest('.wcepi-repeater-row, .wcepi-faq-row');
            $row.fadeOut(300, function() {
                $(this).remove();
                reindexRepeaterFields();
            });
        });
        
    }
    
    /**
     * Reindex repeater fields after removal
     */
    function reindexRepeaterFields() {
        // Reindex dimensions
        $('#wcepi-custom-dimensions .wcepi-repeater-row').each(function(index) {
            $(this).find('input').each(function() {
                var name = $(this).attr('name');
                if (name) {
                    name = name.replace(/\[\d+\]/, '[' + index + ']');
                    $(this).attr('name', name);
                }
            });
        });
        
        // Reindex specifications
        $('#wcepi-specifications .wcepi-repeater-row').each(function(index) {
            $(this).find('input').each(function() {
                var name = $(this).attr('name');
                if (name) {
                    name = name.replace(/\[\d+\]/, '[' + index + ']');
                    $(this).attr('name', name);
                }
            });
        });
        
        // Reindex downloads
        $('#wcepi-downloads .wcepi-repeater-row').each(function(index) {
            $(this).find('input').each(function() {
                var name = $(this).attr('name');
                if (name) {
                    name = name.replace(/\[\d+\]/, '[' + index + ']');
                    $(this).attr('name', name);
                }
            });
            $(this).find('.wcepi-upload-file').attr('data-index', index);
        });
        
        // Reindex FAQs
        $('#wcepi-faqs .wcepi-faq-row').each(function(index) {
            $(this).find('input, textarea').each(function() {
                var name = $(this).attr('name');
                if (name) {
                    name = name.replace(/\[\d+\]/, '[' + index + ']');
                    $(this).attr('name', name);
                }
            });
        });
    }
    
    /**
     * Initialize file uploads
     */
    function initFileUploads() {
        var fileFrame;
        
        // Download file uploads
        $(document).on('click', '.wcepi-upload-file', function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var $urlField = $button.siblings('.wcepi-download-url');
            
            // If the media frame already exists, reopen it
            if (fileFrame) {
                fileFrame.open();
                return;
            }
            
            // Create the media frame
            fileFrame = wp.media({
                title: 'Select or Upload File',
                button: {
                    text: 'Use this file'
                },
                multiple: false,
                library: {
                    type: ['application/pdf', 'application/zip', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']
                }
            });
            
            // When a file is selected, run a callback
            fileFrame.on('select', function() {
                var attachment = fileFrame.state().get('selection').first().toJSON();
                $urlField.val(attachment.url);
            });
            
            // Open the modal
            fileFrame.open();
        });
        
        // Warranty file upload
        $(document).on('click', '.wcepi-upload-warranty-file', function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var $urlField = $('#wcepi_warranty_file');
            
            var warrantyFrame = wp.media({
                title: 'Select Warranty Document',
                button: {
                    text: 'Use this file'
                },
                multiple: false,
                library: {
                    type: ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']
                }
            });
            
            warrantyFrame.on('select', function() {
                var attachment = warrantyFrame.state().get('selection').first().toJSON();
                $urlField.val(attachment.url);
                
                // Show remove button
                if (!$('.wcepi-remove-warranty-file').length) {
                    $button.after('<button type="button" class="button wcepi-remove-warranty-file">Remove</button>');
                }
            });
            
            warrantyFrame.open();
        });
        
        // Remove warranty file
        $(document).on('click', '.wcepi-remove-warranty-file', function(e) {
            e.preventDefault();
            $('#wcepi_warranty_file').val('');
            $(this).remove();
        });
        
        // Toggle warranty period fields based on lifetime checkbox
        $('#wcepi_warranty_lifetime').on('change', function() {
            if ($(this).is(':checked')) {
                $('#wcepi_warranty_period_fields').slideUp(300);
            } else {
                $('#wcepi_warranty_period_fields').slideDown(300);
            }
        });
        
        // Auto-check lifetime checkbox when LifetimeWarranty type is selected
        $('#wcepi_warranty_type').on('change', function() {
            if ($(this).val() === 'LifetimeWarranty') {
                $('#wcepi_warranty_lifetime').prop('checked', true).trigger('change');
            }
        });
        
        // On page load, check if LifetimeWarranty is selected and auto-check checkbox
        if ($('#wcepi_warranty_type').val() === 'LifetimeWarranty') {
            $('#wcepi_warranty_lifetime').prop('checked', true);
            $('#wcepi_warranty_period_fields').hide();
        }

        // Custom payment method icon upload
        $(document).on('click', '.wcepi-upload-payment-icon', function(e) {
            e.preventDefault();

            var $button = $(this);
            var $row = $button.closest('.wcepi-custom-payment-row');
            var $urlField = $row.find('.wcepi-custom-payment-image-url');

            var paymentIconFrame = wp.media({
                title: 'Select Payment Icon',
                button: {
                    text: 'Use this image'
                },
                multiple: false,
                library: {
                    type: 'image'
                }
            });

            paymentIconFrame.on('select', function() {
                var attachment = paymentIconFrame.state().get('selection').first().toJSON();
                $urlField.val(attachment.url);

                // Update or create preview image
                var $preview = $row.find('img, .wcepi-payment-preview');
                if ($preview.is('img')) {
                    $preview.attr('src', attachment.url);
                } else {
                    $preview.replaceWith('<img src="' + attachment.url + '" alt="" style="width: 40px; height: 40px; object-fit: contain; background: #fff; border: 1px solid #ddd; border-radius: 4px;">');
                }
            });

            paymentIconFrame.open();
        });

        // Add custom payment method row
        $(document).on('click', '.wcepi-add-custom-payment', function(e) {
            e.preventDefault();
            var $container = $('#wcepi-custom-payment-methods');
            var index = $container.find('.wcepi-custom-payment-row').length;

            var html = '<div class="wcepi-custom-payment-row" style="display: flex; gap: 10px; align-items: center; margin-bottom: 10px; padding: 10px; background: #f9f9f9; border-radius: 4px;">' +
                '<span class="wcepi-drag-handle dashicons dashicons-menu" title="Drag to reorder"></span>' +
                '<input type="text" name="wcepi_custom_payment_methods[' + index + '][name]" ' +
                'placeholder="Payment Name (e.g., Zelle)" class="regular-text" style="flex: 1;">' +
                '<input type="hidden" name="wcepi_custom_payment_methods[' + index + '][image]" class="wcepi-custom-payment-image-url">' +
                '<span class="wcepi-payment-preview" style="width: 40px; height: 40px; background: #fff; border: 1px solid #ddd; border-radius: 4px; display: inline-block;"></span>' +
                '<button type="button" class="button wcepi-upload-payment-icon">Upload Icon</button>' +
                '<button type="button" class="button wcepi-remove-custom-payment">Remove</button>' +
                '</div>';

            $container.append(html);
        });

        // Remove custom payment method row
        $(document).on('click', '.wcepi-remove-custom-payment', function(e) {
            e.preventDefault();
            $(this).closest('.wcepi-custom-payment-row').remove();
            reindexCustomPaymentMethods();
        });

        // Make custom payment methods sortable
        if ($.fn.sortable && $('#wcepi-custom-payment-methods').length) {
            $('#wcepi-custom-payment-methods').sortable({
                handle: '.wcepi-drag-handle',
                placeholder: 'wcepi-sortable-placeholder',
                opacity: 0.6,
                cursor: 'move',
                update: function() {
                    reindexCustomPaymentMethods();
                }
            });
        }
    }

    /**
     * Reindex custom payment methods after removal or reorder
     */
    function reindexCustomPaymentMethods() {
        $('#wcepi-custom-payment-methods .wcepi-custom-payment-row').each(function(index) {
            $(this).find('input').each(function() {
                var name = $(this).attr('name');
                if (name) {
                    name = name.replace(/\[\d+\]/, '[' + index + ']');
                    $(this).attr('name', name);
                }
            });
        });
    }

    /**
     * Initialize settings page
     */
    function initSettingsPage() {
        
        // Display mode change preview
        $('#wcepi_display_mode').on('change', function() {
            var mode = $(this).val();
            showDisplayModePreview(mode);
        });
        
        // Toggle dependent fields
        $('input[type="checkbox"]').on('change', function() {
            var $checkbox = $(this);
            var fieldId = $checkbox.attr('id');
            
            // Show/hide dependent fields based on checkbox state
            if (fieldId) {
                var $dependentFields = $('[data-depends-on="' + fieldId + '"]');
                if ($checkbox.is(':checked')) {
                    $dependentFields.slideDown(300);
                } else {
                    $dependentFields.slideUp(300);
                }
            }
        });
        
        // Initialize dependent fields on page load
        $('input[type="checkbox"]').trigger('change');
        
        // Confirm before leaving with unsaved changes
        var formChanged = false;
        
        $('form input, form textarea, form select').on('change', function() {
            formChanged = true;
        });
        
        $('form').on('submit', function() {
            formChanged = false;
        });
        
        $(window).on('beforeunload', function() {
            if (formChanged) {
                return 'You have unsaved changes. Are you sure you want to leave?';
            }
        });

        // Bulk sync attributes button
        $('#wcepi-bulk-sync-btn').on('click', function() {
            var $btn = $(this);
            var $status = $('#wcepi-bulk-sync-status');
            var $progress = $('#wcepi-bulk-sync-progress');
            var $bar = $('#wcepi-bulk-sync-bar');
            var $info = $('#wcepi-bulk-sync-info');

            if ($btn.prop('disabled')) {
                return;
            }

            // Check if at least one sync option is checked on the page
            var syncSpecs = $('#wcepi_sync_specs_to_attributes').is(':checked');
            var syncDims = $('#wcepi_sync_dimensions_to_attributes').is(':checked');

            if (!syncSpecs && !syncDims) {
                $status.html('<span style="color: red;">Please enable at least one "Sync to Attributes" option above first.</span>');
                return;
            }

            // Confirm action
            if (!confirm('This will sync all existing specifications and dimensions to WooCommerce attributes. Continue?')) {
                return;
            }

            $btn.prop('disabled', true).text('Syncing...');
            $status.text('');
            $progress.show();
            $bar.css('width', '0%');
            $info.text('Initializing...');

            function processBatch(offset, total) {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wcepi_bulk_sync_attributes',
                        nonce: wcepi_admin.bulk_sync_nonce,
                        offset: offset,
                        total: total,
                        sync_specs: syncSpecs ? 'yes' : 'no',
                        sync_dims: syncDims ? 'yes' : 'no'
                    },
                    success: function(response) {
                        if (response.success) {
                            var data = response.data;
                            var percent = data.total > 0 ? Math.round((data.offset / data.total) * 100) : 100;

                            $bar.css('width', percent + '%');
                            $info.text(data.message);

                            if (!data.done) {
                                // Process next batch
                                processBatch(data.offset, data.total);
                            } else {
                                // Complete
                                $btn.prop('disabled', false).text('Sync All Existing Products');
                                $status.html('<span style="color: green;">&#10003; ' + data.message + '</span>');
                                setTimeout(function() {
                                    $progress.fadeOut();
                                }, 2000);
                            }
                        } else {
                            $btn.prop('disabled', false).text('Sync All Existing Products');
                            $status.html('<span style="color: red;">' + response.data.message + '</span>');
                            $progress.hide();
                        }
                    },
                    error: function() {
                        $btn.prop('disabled', false).text('Sync All Existing Products');
                        $status.html('<span style="color: red;">An error occurred. Please try again.</span>');
                        $progress.hide();
                    }
                });
            }

            // Start processing
            processBatch(0, 0);
        });

    }

    /**
     * Show display mode preview
     */
    function showDisplayModePreview(mode) {
        var previewHtml = '';
        
        switch(mode) {
            case 'tabs':
                previewHtml = '<div class="wcepi-preview">' +
                    '<strong>Tabs Mode:</strong> Information will be displayed in traditional tabs at the top.' +
                    '</div>';
                break;
            case 'accordion':
                previewHtml = '<div class="wcepi-preview">' +
                    '<strong>Accordion Mode:</strong> Information will be displayed in collapsible accordion sections.' +
                    '</div>';
                break;
            case 'stacked':
                previewHtml = '<div class="wcepi-preview">' +
                    '<strong>Stacked Mode:</strong> All information will be displayed in a single column without tabs.' +
                    '</div>';
                break;
        }
        
        $('#wcepi_display_mode').closest('td').find('.wcepi-preview').remove();
        $('#wcepi_display_mode').closest('td').append(previewHtml);
    }
    
    /**
     * Validate form before submission
     */
    function validateForm() {
        var isValid = true;
        var errors = [];
        
        // Validate URLs
        $('input[type="url"]').each(function() {
            var url = $(this).val();
            if (url && !isValidUrl(url)) {
                isValid = false;
                errors.push('Invalid URL: ' + url);
                $(this).addClass('error');
            } else {
                $(this).removeClass('error');
            }
        });
        
        // Validate numbers
        $('input[type="number"]').each(function() {
            var value = $(this).val();
            var min = $(this).attr('min');
            var max = $(this).attr('max');
            
            if (value) {
                if (min && parseFloat(value) < parseFloat(min)) {
                    isValid = false;
                    errors.push('Value must be at least ' + min);
                    $(this).addClass('error');
                } else if (max && parseFloat(value) > parseFloat(max)) {
                    isValid = false;
                    errors.push('Value must be at most ' + max);
                    $(this).addClass('error');
                } else {
                    $(this).removeClass('error');
                }
            }
        });
        
        if (!isValid) {
            alert('Please fix the following errors:\n\n' + errors.join('\n'));
        }
        
        return isValid;
    }
    
    /**
     * Check if URL is valid
     */
    function isValidUrl(url) {
        try {
            new URL(url);
            return true;
        } catch (e) {
            return false;
        }
    }
    
    /**
     * Show success message
     */
    function showSuccessMessage(message) {
        var $notice = $('<div class="notice notice-success is-dismissible"><p>' + message + '</p></div>');
        $('.wrap h1').after($notice);
        
        setTimeout(function() {
            $notice.fadeOut(300, function() {
                $(this).remove();
            });
        }, 5000);
    }
    
    /**
     * Show error message
     */
    function showErrorMessage(message) {
        var $notice = $('<div class="notice notice-error is-dismissible"><p>' + message + '</p></div>');
        $('.wrap h1').after($notice);
        
        setTimeout(function() {
            $notice.fadeOut(300, function() {
                $(this).remove();
            });
        }, 5000);
    }
    
    /**
     * Copy to clipboard functionality
     */
    $('.wcepi-copy-shortcode').on('click', function(e) {
        e.preventDefault();
        var shortcode = $(this).data('shortcode');
        
        if (navigator.clipboard) {
            navigator.clipboard.writeText(shortcode).then(function() {
                showSuccessMessage('Shortcode copied to clipboard!');
            });
        } else {
            // Fallback
            var $temp = $('<input>');
            $('body').append($temp);
            $temp.val(shortcode).select();
            document.execCommand('copy');
            $temp.remove();
            showSuccessMessage('Shortcode copied to clipboard!');
        }
    });
    
    /**
     * Sortable repeater rows (if needed)
     */
    if ($.fn.sortable) {
        $('#wcepi-custom-dimensions, #wcepi-specifications, #wcepi-downloads, #wcepi-faqs').sortable({
            handle: '.wcepi-drag-handle',
            placeholder: 'wcepi-sortable-placeholder',
            opacity: 0.6,
            cursor: 'move',
            update: function() {
                reindexRepeaterFields();
            }
        });
    }
    
})(jQuery);