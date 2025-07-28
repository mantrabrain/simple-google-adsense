/**
 * Simple Google AdSense Gutenberg Blocks
 *
 * @package Simple_Google_Adsense
 * @since   1.2.0
 */

(function() {
    'use strict';

    const { registerBlockType } = wp.blocks;
    const { createElement } = wp.element;
    const { InspectorControls, useBlockProps } = wp.blockEditor;
    const { PanelBody, TextControl, SelectControl, ToggleControl } = wp.components;
    const { __ } = wp.i18n;

    registerBlockType('simple-google-adsense/adsense-ad', {
        title: __('AdFlow Ad', 'simple-google-adsense'),
        description: __('Add Google AdSense ads to your content with AdFlow.', 'simple-google-adsense'),
        category: 'widgets',
        icon: 'money-alt',
        keywords: [
            __('adsense', 'simple-google-adsense'),
            __('ad', 'simple-google-adsense'),
            __('google', 'simple-google-adsense'),
            __('monetization', 'simple-google-adsense')
        ],
        supports: {
            html: false,
            align: ['wide', 'full']
        },
        attributes: {
            adSlot: {
                type: 'string',
                default: ''
            },
            adType: {
                type: 'string',
                default: 'banner'
            },
            adFormat: {
                type: 'string',
                default: 'auto'
            },
            fullWidthResponsive: {
                type: 'boolean',
                default: true
            }
        },
        edit: function(props) {
            const { attributes, setAttributes } = props;
            const { adSlot, adType, adFormat, fullWidthResponsive } = attributes;
            const blockProps = useBlockProps();

            const adTypes = [
                { label: __('Banner Ad', 'simple-google-adsense'), value: 'banner' },
                { label: __('In-Article Ad', 'simple-google-adsense'), value: 'inarticle' },
                { label: __('In-Feed Ad', 'simple-google-adsense'), value: 'infeed' },
                { label: __('Matched Content', 'simple-google-adsense'), value: 'matched_content' }
            ];

            const adFormats = [
                { label: __('Auto', 'simple-google-adsense'), value: 'auto' },
                { label: __('Fluid', 'simple-google-adsense'), value: 'fluid' },
                { label: __('Auto Relaxed', 'simple-google-adsense'), value: 'autorelaxed' },
                { label: __('Rectangle', 'simple-google-adsense'), value: 'rectangle' },
                { label: __('Horizontal', 'simple-google-adsense'), value: 'horizontal' },
                { label: __('Vertical', 'simple-google-adsense'), value: 'vertical' }
            ];

            return createElement('div', blockProps, [
                createElement(InspectorControls, { key: 'inspector' }, [
                    createElement(PanelBody, {
                        title: __('AdSense Settings', 'simple-google-adsense'),
                        initialOpen: true
                    }, [
                        createElement(TextControl, {
                            label: __('Ad Slot ID', 'simple-google-adsense'),
                            value: adSlot,
                            onChange: (value) => setAttributes({ adSlot: value }),
                            help: __('Enter your Google AdSense ad slot ID (e.g., 1234567890). Get this from your AdSense account under "Ads" → "By ad unit". Replace the example with your actual Ad Slot ID.', 'simple-google-adsense'),
                            placeholder: 'YOUR_AD_SLOT_ID'
                        }),
                        createElement(SelectControl, {
                            label: __('Ad Type', 'simple-google-adsense'),
                            value: adType,
                            options: adTypes,
                            onChange: (value) => setAttributes({ adType: value })
                        }),
                        createElement(SelectControl, {
                            label: __('Ad Format', 'simple-google-adsense'),
                            value: adFormat,
                            options: adFormats,
                            onChange: (value) => setAttributes({ adFormat: value })
                        }),
                        createElement(ToggleControl, {
                            label: __('Full Width Responsive', 'simple-google-adsense'),
                            checked: fullWidthResponsive,
                            onChange: (value) => setAttributes({ fullWidthResponsive: value }),
                            help: __('Enable responsive ad sizing.', 'simple-google-adsense')
                        })
                    ])
                ]),
                createElement('div', {
                    className: 'adsense-block-preview',
                    style: {
                        padding: '20px',
                        border: '2px dashed #ccc',
                        borderRadius: '4px',
                        textAlign: 'center',
                        backgroundColor: '#f9f9f9'
                    }
                }, [
                    createElement('div', {
                        style: {
                            fontSize: '16px',
                            fontWeight: 'bold',
                            marginBottom: '10px',
                            color: '#333'
                        }
                    }, __('AdSense Ad', 'simple-google-adsense')),
                    createElement('div', {
                        style: {
                            fontSize: '14px',
                            color: '#666'
                        }
                    }, adSlot ? 
                        __('Ad Slot: ', 'simple-google-adsense') + adSlot :
                        __('⚠️ Configure Ad Slot ID in block settings', 'simple-google-adsense')
                    ),
                    createElement('div', {
                        style: {
                            fontSize: '12px',
                            color: '#999',
                            marginTop: '5px'
                        }
                    }, __('Type: ', 'simple-google-adsense') + adType + ' | ' + __('Format: ', 'simple-google-adsense') + adFormat)
                ])
            ]);
        },
        save: function() {
            // Dynamic block - rendered on PHP side
            return null;
        }
    });

})(); 