/**
 * Gutenberg blocks for Multi-Author Plugin (server-side rendered)
 */
(function (blocks, element, components, serverSideRender, blockEditor, i18n) {
    'use strict';

    var el = element.createElement;
    var __ = i18n.__;
    var ServerSideRender = serverSideRender;
    var InspectorControls = blockEditor.InspectorControls;
    var PanelBody = components.PanelBody;
    var TextControl = components.TextControl;
    var RangeControl = components.RangeControl;

    var simpleBlocks = [
        {
            name: 'multi-author-plugin/contributors',
            title: __('Contributors', 'enhanced-content-plugin'),
            description: __('Contributor badges (authors, reviewers, fact checkers) for this post.', 'enhanced-content-plugin'),
            icon: 'groups'
        },
        {
            name: 'multi-author-plugin/sources',
            title: __('Sources & Citations', 'enhanced-content-plugin'),
            description: __('The sources list for this post.', 'enhanced-content-plugin'),
            icon: 'admin-links'
        },
        {
            name: 'multi-author-plugin/faq',
            title: __('FAQ Section', 'enhanced-content-plugin'),
            description: __('The FAQ accordion for this post.', 'enhanced-content-plugin'),
            icon: 'editor-help'
        },
        {
            name: 'multi-author-plugin/corrections',
            title: __('Corrections Log', 'enhanced-content-plugin'),
            description: __('The corrections & updates log for this post.', 'enhanced-content-plugin'),
            icon: 'edit'
        }
    ];

    simpleBlocks.forEach(function (config) {
        blocks.registerBlockType(config.name, {
            title: config.title,
            description: config.description,
            icon: config.icon,
            category: 'widgets',
            supports: { html: false, multiple: false },
            edit: function () {
                return el(ServerSideRender, { block: config.name });
            },
            save: function () {
                return null;
            }
        });
    });

    blocks.registerBlockType('multi-author-plugin/editorial-team', {
        title: __('Editorial Team', 'enhanced-content-plugin'),
        description: __('Cards for your editorial team. Members are chosen on user profiles or via the include list.', 'enhanced-content-plugin'),
        icon: 'businessperson',
        category: 'widgets',
        supports: { html: false },
        attributes: {
            include: { type: 'string', default: '' },
            columns: { type: 'number', default: 3 }
        },
        edit: function (props) {
            return el(
                element.Fragment,
                {},
                el(
                    InspectorControls,
                    {},
                    el(
                        PanelBody,
                        { title: __('Team Settings', 'enhanced-content-plugin') },
                        el(TextControl, {
                            label: __('Include user IDs', 'enhanced-content-plugin'),
                            help: __('Comma-separated user IDs. Leave empty to show users with "Show on Editorial Team page" enabled on their profile.', 'enhanced-content-plugin'),
                            value: props.attributes.include,
                            onChange: function (value) {
                                props.setAttributes({ include: value });
                            }
                        }),
                        el(RangeControl, {
                            label: __('Columns', 'enhanced-content-plugin'),
                            min: 1,
                            max: 4,
                            value: props.attributes.columns,
                            onChange: function (value) {
                                props.setAttributes({ columns: value });
                            }
                        })
                    )
                ),
                el(ServerSideRender, {
                    block: 'multi-author-plugin/editorial-team',
                    attributes: props.attributes
                })
            );
        },
        save: function () {
            return null;
        }
    });
})(
    window.wp.blocks,
    window.wp.element,
    window.wp.components,
    window.wp.serverSideRender,
    window.wp.blockEditor,
    window.wp.i18n
);
