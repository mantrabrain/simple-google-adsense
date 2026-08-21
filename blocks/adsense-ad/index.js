/**
 * AdFlow Ad block.
 *
 * Block metadata (title, category, attributes, supports) lives in block.json and
 * is bootstrapped into the editor by the server registration, so only the editor
 * behaviour is defined here.
 *
 * @package Simple_Google_Adsense
 * @since   1.2.0
 */

( function () {
	'use strict';

	const { registerBlockType } = wp.blocks;
	const { createElement } = wp.element;
	const { InspectorControls, useBlockProps } = wp.blockEditor;
	const { PanelBody, TextControl, SelectControl, ToggleControl } = wp.components;
	const { __ } = wp.i18n;

	const AD_TYPES = [
		{ label: __( 'Banner Ad', 'simple-google-adsense' ), value: 'banner' },
		{ label: __( 'In-Article Ad', 'simple-google-adsense' ), value: 'inarticle' },
		{ label: __( 'In-Feed Ad', 'simple-google-adsense' ), value: 'infeed' },
		{ label: __( 'Matched Content', 'simple-google-adsense' ), value: 'matched_content' },
	];

	const AD_FORMATS = [
		{ label: __( 'Auto', 'simple-google-adsense' ), value: 'auto' },
		{ label: __( 'Fluid', 'simple-google-adsense' ), value: 'fluid' },
		{ label: __( 'Auto Relaxed', 'simple-google-adsense' ), value: 'autorelaxed' },
		{ label: __( 'Rectangle', 'simple-google-adsense' ), value: 'rectangle' },
		{ label: __( 'Horizontal', 'simple-google-adsense' ), value: 'horizontal' },
		{ label: __( 'Vertical', 'simple-google-adsense' ), value: 'vertical' },
	];

	registerBlockType( 'simple-google-adsense/adsense-ad', {
		edit: function ( props ) {
			const { attributes, setAttributes } = props;
			const { adSlot, adType, adFormat, fullWidthResponsive } = attributes;
			const blockProps = useBlockProps();

			return createElement( 'div', blockProps, [
				createElement( InspectorControls, { key: 'inspector' },
					createElement( PanelBody, {
						title: __( 'AdSense Settings', 'simple-google-adsense' ),
						initialOpen: true,
					}, [
						createElement( TextControl, {
							key: 'ad-slot',
							label: __( 'Ad Slot ID', 'simple-google-adsense' ),
							value: adSlot,
							onChange: ( value ) => setAttributes( { adSlot: value } ),
							help: __( 'Enter your Google AdSense ad slot ID (e.g., 1234567890). Get this from your AdSense account under "Ads" → "By ad unit". Replace the example with your actual Ad Slot ID.', 'simple-google-adsense' ),
							placeholder: 'YOUR_AD_SLOT_ID',
						} ),
						createElement( SelectControl, {
							key: 'ad-type',
							label: __( 'Ad Type', 'simple-google-adsense' ),
							value: adType,
							options: AD_TYPES,
							onChange: ( value ) => setAttributes( { adType: value } ),
						} ),
						createElement( SelectControl, {
							key: 'ad-format',
							label: __( 'Ad Format', 'simple-google-adsense' ),
							value: adFormat,
							options: AD_FORMATS,
							onChange: ( value ) => setAttributes( { adFormat: value } ),
						} ),
						createElement( ToggleControl, {
							key: 'full-width',
							label: __( 'Full Width Responsive', 'simple-google-adsense' ),
							checked: fullWidthResponsive,
							onChange: ( value ) => setAttributes( { fullWidthResponsive: value } ),
							help: __( 'Enable responsive ad sizing.', 'simple-google-adsense' ),
						} ),
					] )
				),
				createElement( 'div', {
					key: 'preview',
					className: 'adsense-block-preview',
					style: {
						padding: '20px',
						border: '2px dashed #ccc',
						borderRadius: '4px',
						textAlign: 'center',
						backgroundColor: '#f9f9f9',
					},
				}, [
					createElement( 'div', {
						key: 'title',
						style: {
							fontSize: '16px',
							fontWeight: 'bold',
							marginBottom: '10px',
							color: '#333',
						},
					}, __( 'AdSense Ad', 'simple-google-adsense' ) ),
					createElement( 'div', {
						key: 'slot',
						style: {
							fontSize: '14px',
							color: '#666',
						},
					}, adSlot
						? __( 'Ad Slot: ', 'simple-google-adsense' ) + adSlot
						: __( '⚠️ Configure Ad Slot ID in block settings', 'simple-google-adsense' )
					),
					createElement( 'div', {
						key: 'meta',
						style: {
							fontSize: '12px',
							color: '#999',
							marginTop: '5px',
						},
					}, __( 'Type: ', 'simple-google-adsense' ) + adType + ' | ' + __( 'Format: ', 'simple-google-adsense' ) + adFormat ),
				] ),
			] );
		},
		save: function () {
			// Dynamic block - rendered on PHP side.
			return null;
		},
	} );
} )();
