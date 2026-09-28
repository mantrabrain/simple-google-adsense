/**
 * AdFlow Ad block.
 *
 * Block metadata (title, category, attributes, supports) lives in block.json and
 * is bootstrapped into the editor by the server registration, so only the editor
 * behaviour is defined here.
 *
 * Since 1.4.0 the block can reference a saved Ad Unit (`adId`). Blocks saved
 * before that keep their inline slot/type/format settings, which still work.
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
	const { useSelect } = wp.data;
	const { __ } = wp.i18n;

	const AD_TYPES = [
		{ label: __( 'Banner Ad', 'simple-google-adsense' ), value: 'banner' },
		{ label: __( 'In-Article Ad', 'simple-google-adsense' ), value: 'inarticle' },
		{ label: __( 'In-Feed Ad', 'simple-google-adsense' ), value: 'infeed' },
		{ label: __( 'Multiplex (Matched Content)', 'simple-google-adsense' ), value: 'matched_content' },
	];

	const AD_FORMATS = [
		{ label: __( 'Auto', 'simple-google-adsense' ), value: 'auto' },
		{ label: __( 'Fluid', 'simple-google-adsense' ), value: 'fluid' },
		{ label: __( 'Auto Relaxed', 'simple-google-adsense' ), value: 'autorelaxed' },
		{ label: __( 'Rectangle', 'simple-google-adsense' ), value: 'rectangle' },
		{ label: __( 'Horizontal', 'simple-google-adsense' ), value: 'horizontal' },
		{ label: __( 'Vertical', 'simple-google-adsense' ), value: 'vertical' },
	];

	// The AdFlow glyph (rising bars and growth arrow).
	const icon = createElement( 'svg', { xmlns: 'http://www.w3.org/2000/svg', viewBox: '0 0 20 20', width: 24, height: 24 },
		createElement( 'path', { fill: 'currentColor', d: 'M2.2 12.6h3.4a.9.9 0 0 1 .9.9v4.6a.9.9 0 0 1-.9.9H2.2a.9.9 0 0 1-.9-.9v-4.6a.9.9 0 0 1 .9-.9zm6.1-3.2h3.4a.9.9 0 0 1 .9.9v7.8a.9.9 0 0 1-.9.9H8.3a.9.9 0 0 1-.9-.9v-7.8a.9.9 0 0 1 .9-.9zm6.1-2.6h3.4a.9.9 0 0 1 .9.9v10.4a.9.9 0 0 1-.9.9h-3.4a.9.9 0 0 1-.9-.9V7.7a.9.9 0 0 1 .9-.9zM1.8 8.55C6.1 8.15 9.57 6.36 14.57 2.56l1.27 1.68C10.84 8.04 6.3 10.25 2 10.65a1.05 1.05 0 0 1-.2-2.1zM17.59 1.58l-1.24 3.83-2.78-3.66z' } )
	);

	registerBlockType( 'simple-google-adsense/adsense-ad', {
		icon: icon,
		edit: function ( props ) {
			const { attributes, setAttributes } = props;
			const { adId, adSlot, adType, adFormat, fullWidthResponsive, layoutKey } = attributes;
			const blockProps = useBlockProps();

			// Saved Ad Units (titles only). Readable by anyone who can write posts.
			const units = useSelect( function ( select ) {
				return select( 'core' ).getEntityRecords( 'postType', 'adflow_ad', {
					per_page: 100,
					status: 'publish',
					context: 'view',
					_fields: 'id,title',
				} );
			}, [] );

			const unitOptions = [ { label: __( '- Enter the ad manually -', 'simple-google-adsense' ), value: 0 } ].concat(
				( units || [] ).map( function ( unit ) {
					return {
						label: ( unit.title && unit.title.rendered ) || '#' + unit.id,
						value: unit.id,
					};
				} )
			);

			const selectedUnit = ( units || [] ).find( function ( unit ) {
				return unit.id === adId;
			} );

			const manualControls = [
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
					help: __( 'For Google\'s exact in-article or multiplex code, save the ad under AdFlow → Ad Units and pick it above.', 'simple-google-adsense' ),
				} ),
			];

			if ( 'infeed' === adType ) {
				manualControls.push( createElement( TextControl, {
					key: 'layout-key',
					label: __( 'Layout key', 'simple-google-adsense' ),
					value: layoutKey,
					onChange: ( value ) => setAttributes( { layoutKey: value } ),
					help: __( 'Copy data-ad-layout-key from your in-feed ad code. Required for in-feed ads.', 'simple-google-adsense' ),
				} ) );
			}

			// Same controls as before 1.4.0, so existing blocks keep their settings.
			{
				manualControls.push(
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
					} )
				);
			}

			let summary;
			let meta = '';

			if ( adId ) {
				summary = selectedUnit
					? __( 'Ad unit: ', 'simple-google-adsense' ) + selectedUnit.title.rendered
					: __( 'Ad unit #', 'simple-google-adsense' ) + adId;
			} else if ( adSlot ) {
				summary = __( 'Ad Slot: ', 'simple-google-adsense' ) + adSlot;
				meta = __( 'Type: ', 'simple-google-adsense' ) + adType + ' | ' + __( 'Format: ', 'simple-google-adsense' ) + adFormat;
			} else {
				summary = __( '⚠️ Choose an ad unit or enter an Ad Slot ID in the block settings', 'simple-google-adsense' );
			}

			return createElement( 'div', blockProps, [
				createElement( InspectorControls, { key: 'inspector' },
					createElement( PanelBody, {
						title: __( 'Ad settings', 'simple-google-adsense' ),
						initialOpen: true,
					}, [
						createElement( SelectControl, {
							key: 'ad-id',
							label: __( 'Ad unit', 'simple-google-adsense' ),
							value: adId,
							options: unitOptions,
							onChange: ( value ) => setAttributes( { adId: parseInt( value, 10 ) || 0 } ),
							help: __( 'Pick a saved unit from AdFlow → Ad Units, or enter the ad manually below.', 'simple-google-adsense' ),
						} ),
					].concat( adId ? [] : manualControls ) )
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
					}, adId ? __( 'AdFlow Ad', 'simple-google-adsense' ) : __( 'AdSense Ad', 'simple-google-adsense' ) ),
					createElement( 'div', {
						key: 'slot',
						style: {
							fontSize: '14px',
							color: '#666',
						},
					}, summary ),
					meta ? createElement( 'div', {
						key: 'meta',
						style: {
							fontSize: '12px',
							color: '#999',
							marginTop: '5px',
						},
					}, meta ) : null,
				] ),
			] );
		},
		save: function () {
			// Dynamic block - rendered on PHP side.
			return null;
		},
	} );
} )();
