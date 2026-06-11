/**
 * CMM Address Lookup — Gutenberg block editor script.
 * No build step required. Runs entirely via WordPress global wp.* APIs.
 */
( function ( blocks, element, blockEditor, i18n, components ) {
    var el               = element.createElement;
    var __               = i18n.__;
    var InspectorControls = blockEditor.InspectorControls;
    var PanelBody        = components.PanelBody;
    var TextControl      = components.TextControl;
    var ToggleControl    = components.ToggleControl;

    blocks.registerBlockType( 'srfm/cmm-address-lookup', {

        edit: function ( props ) {
            var attrs  = props.attributes;
            var setAttr = props.setAttributes;

            return [
                /* ── Sidebar inspector controls ─────────────────────── */
                el( InspectorControls, { key: 'inspector' },
                    el( PanelBody, {
                            title: __( 'Field Settings', 'cmm' ),
                            initialOpen: true,
                        },
                        el( TextControl, {
                            label:    __( 'Label', 'cmm' ),
                            value:    attrs.label,
                            onChange: function ( v ) { setAttr( { label: v } ); },
                        } ),
                        el( TextControl, {
                            label:    __( 'Placeholder', 'cmm' ),
                            value:    attrs.placeholder,
                            onChange: function ( v ) { setAttr( { placeholder: v } ); },
                        } ),
                        el( ToggleControl, {
                            label:    __( 'Required field', 'cmm' ),
                            checked:  attrs.required,
                            onChange: function ( v ) { setAttr( { required: v } ); },
                        } )
                    )
                ),

                /* ── Editor preview ──────────────────────────────────── */
                el( 'div', { key: 'preview', className: 'cmm-address-block-preview' },
                    el( 'label', { className: 'cmm-block-label' },
                        attrs.label,
                        attrs.required
                            ? el( 'span', { style: { color: '#d63638', marginLeft: '3px' } }, '*' )
                            : null
                    ),
                    el( 'input', {
                        type:        'text',
                        className:   'cmm-block-input',
                        placeholder: attrs.placeholder,
                        disabled:    true,
                        readOnly:    true,
                    } ),
                    el( 'div', { className: 'cmm-block-badge' },
                        el( 'span', { className: 'dashicons dashicons-admin-home' } ),
                        __( ' WPICA Community — Address Lookup (live on frontend)', 'cmm' )
                    )
                ),
            ];
        },

        /* Server-side rendered — save returns null */
        save: function () {
            return null;
        },

    } );

}(
    window.wp.blocks,
    window.wp.element,
    window.wp.blockEditor,
    window.wp.i18n,
    window.wp.components
) );
