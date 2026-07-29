/**
 * Admin JavaScript for Multi-Author Plugin
 */

(function($) {
    'use strict';

    // Translatable strings localized from PHP, with English fallbacks
    var i18n = $.extend({
        searchPrompt: 'Enter a name or email to search...',
        searchMinChars: 'Enter at least 2 characters to search...',
        searching: 'Searching...',
        noUsersFound: 'No users found.',
        searchError: 'Error searching users.',
        alreadyAdded: ' (Already added)',
        remove: 'Remove',
        noContributors: 'No contributors added yet.',
        multiRoleWarningSingle: 'is credited in more than one role on this post. Self-review can undermine reader trust.',
        multiRoleWarningPlural: 'are credited in more than one role on this post. Self-review can undermine reader trust.'
    }, (window.mapAdmin && mapAdmin.i18n) || {});

    var MAP_Admin = {
        currentContributorType: '',
        selectedUsers: [],

        /**
         * Initialize
         */
        init: function() {
            this.initSortable();
            this.initContributorButtons();
            this.initUserSearchModal();
            this.initSourcesRepeater();
            this.renumberSources();
            this.initAiDisclaimer();
            this.initFAQ();
            this.initCorrections();
            this.updateEmptyStates();
        },

        /**
         * Toggle the is-empty class on contributor lists so the CSS
         * empty-state message (data-empty-message) can show
         */
        updateEmptyStates: function() {
            $('.map-contributor-list').each(function() {
                $(this).toggleClass('is-empty', $(this).children('.map-contributor-item').length === 0);
            });
        },

        /**
         * Initialize sortable for contributors
         */
        initSortable: function() {
            $('.map-contributor-list').sortable({
                handle: '.map-contributor-drag-handle',
                placeholder: 'map-contributor-placeholder',
                opacity: 0.8,
                cursor: 'move',
                tolerance: 'pointer'
            });
        },

        /**
         * Initialize contributor add buttons
         */
        initContributorButtons: function() {
            var self = this;

            // Add contributor button
            $('.map-add-contributor').on('click', function(e) {
                e.preventDefault();
                self.currentContributorType = $(this).data('type');
                self.openUserSearchModal();
            });

            // Remove contributor button
            $(document).on('click', '.map-remove-contributor', function(e) {
                e.preventDefault();
                $(this).closest('.map-contributor-item').fadeOut(300, function() {
                    $(this).remove();
                    self.updateEmptyStates();
                });
            });
        },

        /**
         * Initialize user search modal
         */
        initUserSearchModal: function() {
            var self = this;
            var $modal = $('#map-user-search-modal');
            var $searchInput = $('#map-user-search-input');
            var $results = $('#map-user-search-results');
            var searchTimeout;

            // Close modal
            $('.map-modal-close').on('click', function() {
                self.closeUserSearchModal();
            });

            // Close on outside click
            $(window).on('click', function(e) {
                if ($(e.target).is('#map-user-search-modal')) {
                    self.closeUserSearchModal();
                }
            });

            // Search input
            $searchInput.on('input', function() {
                clearTimeout(searchTimeout);
                var search = $(this).val();

                if (search.length < 2) {
                    $results.empty().append($('<div>', { 'class': 'map-user-search-loading', text: i18n.searchMinChars }));
                    return;
                }

                $results.empty().append($('<div>', { 'class': 'map-user-search-loading', text: i18n.searching }));

                searchTimeout = setTimeout(function() {
                    self.searchUsers(search);
                }, 300);
            });

            // Select user
            $(document).on('click', '.map-user-result', function(e) {
                if ($(this).hasClass('disabled')) {
                    return;
                }
                // Clicking the checkbox itself already toggles it natively;
                // only invert when the click landed elsewhere on the row.
                var checkbox = $(this).find('input[type="checkbox"]');
                if (!$(e.target).is(checkbox)) {
                    checkbox.prop('checked', !checkbox.prop('checked'));
                }
                $(this).toggleClass('selected', checkbox.prop('checked'));
            });

            // Add selected users
            $('#map-user-search-select').on('click', function() {
                var selectedUsers = [];
                $('.map-user-result.selected').each(function() {
                    selectedUsers.push({
                        id: $(this).data('user-id'),
                        name: $(this).data('user-name'),
                        email: $(this).data('user-email'),
                        avatar: $(this).data('user-avatar')
                    });
                });

                if (selectedUsers.length > 0) {
                    self.addContributors(selectedUsers);
                    self.closeUserSearchModal();
                }
            });
        },

        /**
         * Open user search modal
         */
        openUserSearchModal: function() {
            var self = this;
            $('#map-user-search-modal').fadeIn(200);
            $('#map-user-search-input').val('').focus();
            $('#map-user-search-results').empty().append(
                $('<div>', { 'class': 'map-user-search-loading', text: i18n.searchPrompt })
            );

            // Offer recently used contributors before the user types
            $.ajax({
                url: mapAdmin.ajaxurl,
                type: 'POST',
                data: {
                    action: 'map_search_users',
                    nonce: mapAdmin.nonce,
                    search: '',
                    initial: 1
                },
                success: function(response) {
                    // Don't clobber results if the user already started typing
                    if ($('#map-user-search-input').val().length > 0) {
                        return;
                    }
                    if (response.success && response.data.length > 0) {
                        self.renderUserResults(response.data);
                    }
                }
            });
        },

        /**
         * Close user search modal
         */
        closeUserSearchModal: function() {
            $('#map-user-search-modal').fadeOut(200);
            $('#map-user-search-input').val('');
            $('#map-user-search-results').empty();
            $('.map-user-result').removeClass('selected');
        },

        /**
         * Search users via AJAX
         */
        searchUsers: function(search) {
            var self = this;

            $.ajax({
                url: mapAdmin.ajaxurl,
                type: 'POST',
                data: {
                    action: 'map_search_users',
                    nonce: mapAdmin.nonce,
                    search: search
                },
                success: function(response) {
                    if (response.success && response.data.length > 0) {
                        self.renderUserResults(response.data);
                    } else {
                        $('#map-user-search-results').empty().append(
                            $('<div>', { 'class': 'map-user-search-loading', text: i18n.noUsersFound })
                        );
                    }
                },
                error: function() {
                    $('#map-user-search-results').empty().append(
                        $('<div>', { 'class': 'map-user-search-loading', text: i18n.searchError })
                    );
                }
            });
        },

        /**
         * Render user search results
         */
        renderUserResults: function(users) {
            var $results = $('#map-user-search-results');
            $results.empty();

            // Get existing contributor IDs to avoid duplicates
            var existingIds = [];
            var listId = this.currentContributorType.replace('_', '-');
            $('#map-' + listId + '-list .map-contributor-item').each(function() {
                existingIds.push(parseInt($(this).data('user-id')));
            });

            $.each(users, function(index, user) {
                var isExisting = existingIds.indexOf(user.id) !== -1;

                // Build with .text()/.attr() so user-controlled values
                // (display name, email) can never be parsed as HTML.
                var $result = $('<div>', {
                    'class': 'map-user-result' + (isExisting ? ' disabled' : ''),
                    'data-user-id': user.id,
                    'data-user-name': user.name,
                    'data-user-email': user.email,
                    'data-user-avatar': user.avatar
                });

                $result.append($('<input>', { type: 'checkbox', disabled: isExisting }));
                $result.append($('<img>', { src: user.avatar, alt: '' }));

                var $info = $('<div>', { 'class': 'map-user-result-info' });
                $info.append($('<span>', { 'class': 'map-user-result-name', text: user.name }));
                $info.append($('<span>', { 'class': 'map-user-result-email', text: user.email }));
                if (isExisting) {
                    $info.append($('<span>', {
                        'class': 'map-user-result-email',
                        css: { color: '#a00' },
                        text: i18n.alreadyAdded
                    }));
                }
                $result.append($info);

                $results.append($result);
            });
        },

        /**
         * Add contributors to list
         */
        addContributors: function(users) {
            var self = this;
            var listId = this.currentContributorType.replace('_', '-');
            var $list = $('#map-' + listId + '-list');
            var multiRoleUsers = [];

            $.each(users, function(index, user) {
                // Check if user already exists
                if ($list.find('[data-user-id="' + user.id + '"]').length > 0) {
                    return;
                }

                // Warn (non-blocking) when the same person holds another role
                if ($('.map-contributor-list').not($list).find('[data-user-id="' + user.id + '"]').length > 0) {
                    multiRoleUsers.push(user.name);
                }

                var $item = $('<div>', { 'class': 'map-contributor-item', 'data-user-id': user.id });
                $item.append($('<span>', { 'class': 'map-contributor-drag-handle', text: '⋮⋮' }));
                $item.append($('<img>', { src: user.avatar, alt: '', 'class': 'map-contributor-avatar' }));
                $item.append($('<span>', { 'class': 'map-contributor-name', text: user.name }));
                $item.append($('<span>', { 'class': 'map-contributor-email', text: '(' + user.email + ')' }));
                $item.append(
                    $('<button>', { type: 'button', 'class': 'button-link map-remove-contributor', 'aria-label': i18n.remove })
                        .append($('<span>', { 'class': 'dashicons dashicons-no-alt' }))
                );
                $item.append($('<input>', {
                    type: 'hidden',
                    name: 'map_contributors[' + self.currentContributorType + '][]',
                    value: user.id
                }));

                $list.append($item);
            });

            // Refresh sortable
            $list.sortable('refresh');
            this.updateEmptyStates();

            if (multiRoleUsers.length > 0) {
                this.showRoleWarning(multiRoleUsers);
            }
        },

        /**
         * Show a dismissible notice when a person is credited in
         * multiple roles (e.g. both author and reviewer)
         */
        showRoleWarning: function(names) {
            $('.map-role-warning').remove();

            var $warning = $('<div>', {
                'class': 'notice notice-warning map-role-warning',
                css: { margin: '10px 0', padding: '8px 12px' }
            });
            $warning.append($('<p>', {
                css: { margin: 0 },
                text: names.join(', ') + ' ' + (names.length === 1 ? i18n.multiRoleWarningSingle : i18n.multiRoleWarningPlural)
            }));
            $warning.append(
                $('<button>', { type: 'button', 'class': 'notice-dismiss' }).on('click', function() {
                    $warning.remove();
                })
            );

            $('.map-contributors-wrapper').prepend($warning);
        },

        /**
         * Initialize sources repeater
         */
        initSourcesRepeater: function() {
            var self = this;

            // Add source
            $('#map-add-source').on('click', function(e) {
                e.preventDefault();
                self.addSource();
            });

            // Remove source
            $(document).on('click', '.map-remove-source', function(e) {
                e.preventDefault();
                $(this).closest('.map-source-item').fadeOut(300, function() {
                    $(this).remove();
                    self.renumberSources();
                });
            });

            // Drag to reorder sources
            if ($('#map-sources-list').length) {
                $('#map-sources-list').sortable({
                    handle: '.map-source-drag-handle',
                    placeholder: 'map-contributor-placeholder',
                    opacity: 0.8,
                    cursor: 'move',
                    tolerance: 'pointer',
                    update: function() {
                        self.renumberSources();
                    }
                });
            }
        },

        /**
         * Initialize corrections repeater
         */
        initCorrections: function() {
            var self = this;

            $('#map-add-correction').on('click', function(e) {
                e.preventDefault();
                var template = $('#map-correction-template').html();
                var index = $('#map-corrections-list').children().length;
                var $item = $(template.replace(/\{\{INDEX\}\}/g, index));
                $('#map-corrections-list').append($item);
                self.renumberCorrections();
                $item.find('.map-correction-date').focus();
            });

            $(document).on('click', '.map-remove-correction', function(e) {
                e.preventDefault();
                $(this).closest('.map-correction-item').fadeOut(300, function() {
                    $(this).remove();
                    self.renumberCorrections();
                });
            });
        },

        /**
         * Renumber correction field names after add/remove
         */
        renumberCorrections: function() {
            $('#map-corrections-list .map-correction-item').each(function(index) {
                $(this).find('.map-correction-date').attr('name', 'map_corrections[' + index + '][date]');
                $(this).find('.map-correction-text').attr('name', 'map_corrections[' + index + '][text]');
            });
        },

        /**
         * Add source item
         */
        addSource: function() {
            var $list = $('#map-sources-list');
            var index = $list.children().length;
            var template = $('#map-source-template').html();
            var $item = $(template.replace(/\{\{INDEX\}\}/g, index));

            $list.append($item);
            this.renumberSources();

            // Focus on the new URL field
            $item.find('.map-source-url').focus();
        },

        /**
         * Renumber sources
         */
        renumberSources: function() {
            $('#map-sources-list .map-source-item').each(function(index) {
                $(this).find('.map-source-number').text((index + 1) + '.');

                // Update field names
                $(this).find('.map-source-url').attr('name', 'map_sources[' + index + '][url]');
                $(this).find('.map-source-label').attr('name', 'map_sources[' + index + '][label]');
                $(this).find('.map-source-description').attr('name', 'map_sources[' + index + '][description]');
            });
        },

        /**
         * Initialize AI Disclaimer functionality
         */
        initAiDisclaimer: function() {
            var self = this;

            // Toggle AI uses section visibility based on badge type
            $('input[name="map_ai_badge_type"]').on('change', function() {
                var badgeType = $(this).val();
                if (badgeType === 'ai_enhanced') {
                    $('#map-ai-uses-section').slideDown(300);
                } else {
                    $('#map-ai-uses-section').slideUp(300);
                }
            });

            // Add custom AI use
            $('#map-add-custom-ai-use').on('click', function(e) {
                e.preventDefault();
                self.addCustomAiUse();
            });

            // Remove custom AI use
            $(document).on('click', '.map-remove-custom-use', function(e) {
                e.preventDefault();
                $(this).closest('.map-ai-custom-use-item').fadeOut(300, function() {
                    $(this).remove();
                });
            });
        },

        /**
         * Add custom AI use item
         */
        addCustomAiUse: function() {
            var template = $('#map-ai-custom-use-template').html();
            var $item = $(template);

            $('#map-ai-custom-uses-list').append($item);

            // Focus on the new input
            $item.find('input').focus();
        },

        /**
         * Initialize FAQ functionality
         */
        initFAQ: function() {
            var self = this;

            // Toggle FAQ settings visibility based on enabled checkbox
            $('input[name="map_faq_enabled"]').on('change', function() {
                if ($(this).is(':checked')) {
                    $('#map-faq-settings').slideDown(300);
                } else {
                    $('#map-faq-settings').slideUp(300);
                }
            });

            // Make FAQ list sortable
            if ($('#map-faq-list').length) {
                $('#map-faq-list').sortable({
                    handle: '.map-faq-drag-handle',
                    placeholder: 'map-faq-placeholder',
                    opacity: 0.8,
                    cursor: 'move',
                    tolerance: 'pointer',
                    update: function() {
                        self.renumberFAQs();
                    }
                });
            }

            // Add FAQ item
            $('#map-add-faq').on('click', function(e) {
                e.preventDefault();
                self.addFAQItem();
            });

            // Remove FAQ item
            $(document).on('click', '.map-remove-faq', function(e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).closest('.map-faq-item').fadeOut(300, function() {
                    $(this).remove();
                    self.renumberFAQs();
                });
            });

            // Toggle FAQ item expand/collapse
            $(document).on('click', '.map-faq-toggle, .map-faq-item-header', function(e) {
                if ($(e.target).closest('.map-remove-faq').length || $(e.target).closest('.map-faq-drag-handle').length) {
                    return;
                }
                e.preventDefault();
                var $item = $(this).closest('.map-faq-item');
                var $content = $item.find('.map-faq-item-content');
                var $icon = $item.find('.map-faq-toggle .dashicons');

                $content.slideToggle(200);
                $icon.toggleClass('dashicons-arrow-down-alt2 dashicons-arrow-up-alt2');
            });

            // Update preview when question changes
            $(document).on('input', '.map-faq-question', function() {
                var question = $(this).val();
                var preview = question.length > 50 ? question.substring(0, 50) + '...' : question;
                $(this).closest('.map-faq-item').find('.map-faq-preview').text(preview);
            });
        },

        /**
         * Add FAQ item
         */
        addFAQItem: function() {
            var $list = $('#map-faq-list');
            var index = $list.children().length;
            var template = $('#map-faq-template').html();
            var $item = $(template.replace(/\{\{INDEX\}\}/g, index).replace(/\{\{NUMBER\}\}/g, index + 1));

            $list.append($item);

            // Expand the new item
            $item.find('.map-faq-item-content').show();
            $item.find('.map-faq-toggle .dashicons').removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-up-alt2');

            // Focus on the question field
            $item.find('.map-faq-question').focus();

            // Refresh sortable
            $list.sortable('refresh');
        },

        /**
         * Renumber FAQ items
         */
        renumberFAQs: function() {
            $('#map-faq-list .map-faq-item').each(function(index) {
                $(this).attr('data-index', index);
                $(this).find('.map-faq-number').text((index + 1) + '.');

                // Update field names
                $(this).find('.map-faq-question').attr('name', 'map_faq[' + index + '][question]');
                $(this).find('.map-faq-answer').attr('name', 'map_faq[' + index + '][answer]');
            });
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        MAP_Admin.init();
    });

})(jQuery);