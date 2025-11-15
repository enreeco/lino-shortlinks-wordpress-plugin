( function( blocks, element, components, editor, data, apiFetch, i18n ) {
    const { registerBlockType } = blocks;
    const { createElement: el, Fragment } = element;
    const { PanelBody, SelectControl, TextControl } = components;
    const { InspectorControls } = editor;
    const { __ } = i18n;

    const blockName = 'wp-sl/short-link';

    registerBlockType( blockName, {
        title: __('LINO Link', 'wp-short-links'),
        icon: 'admin-links',
        category: 'common',
        attributes: {
            slug: {
                type: 'string',
                default: ''
            },
            label: {
                type: 'string',
                default: ''
            }
        },

        edit: function( props ) {
            const { attributes, setAttributes } = props;
            const links = ( window.WPShortLinksBlockData && window.WPShortLinksBlockData.links ) || [];
            const options = [
                { label: __('Select a LINO link', 'wp-short-links'), value: '' }
            ].concat(
                links.map( function( link ) {
                    const title = link.title || link.short_url;
                    return {
                        label: title + ' (' + link.slug + ')',
                        value: link.slug
                    };
                } )
            );

            const selectedLink = links.find( l => l.slug === attributes.slug );

            return el(
                Fragment,
                {},
                el(
                    InspectorControls,
                    {},
                    el(
                        PanelBody,
                        { title: __('LINO Link Settings', 'wp-short-links'), initialOpen: true },
                        el( SelectControl, {
                            label: __('LINO Link', 'wp-short-links'),
                            value: attributes.slug,
                            options: options,
                            onChange: function( value ) {
                                setAttributes( { slug: value } );
                            }
                        } ),
                        el( TextControl, {
                            label: __('Label (optional)', 'wp-short-links'),
                            value: attributes.label,
                            onChange: function( value ) {
                                setAttributes( { label: value } );
                            },
                            help: __('If empty, the short URL will be used as link text.', 'wp-short-links')
                        } )
                    )
                ),
                el(
                    'p',
                    { className: 'wp-sl-short-link-preview' },
                    attributes.slug
                        ? ( attributes.label || ( selectedLink ? selectedLink.short_url : attributes.slug ) )
                        : __('No link selected.', 'wp-short-links')
                )
            );
        },

        save: function() {
            // Server-side rendering in PHP
            return null;
        }
    });

} )(
    window.wp.blocks,
    window.wp.element,
    window.wp.components,
    window.wp.editor || window.wp.blockEditor,
    window.wp.data,
    window.wp.apiFetch,
    window.wp.i18n
);
