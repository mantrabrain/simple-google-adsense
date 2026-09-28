/**
 * AdFlow ad unit edit screen: show only the fields that apply to the chosen type.
 *
 * @package Simple_Google_Adsense
 * @since   1.4.0
 */
( function () {
	'use strict';

	var type = document.getElementById( 'adflow-type' );

	if ( ! type ) {
		return;
	}

	function show( selector, visible ) {
		var el = document.querySelector( selector );
		if ( el ) {
			el.style.display = visible ? '' : 'none';
		}
	}

	function toggle() {
		var format = document.querySelector( '.adflow-field-format' );
		var layoutKey = document.querySelector( '.adflow-field-layout-key' );
		var code = document.querySelector( '.adflow-field-code' );
		var slot = document.querySelector( '.adflow-field-slot' );

		if ( format ) {
			format.style.display = 'display' === type.value ? '' : 'none';
		}
		if ( layoutKey ) {
			layoutKey.style.display = 'infeed' === type.value ? '' : 'none';
		}
		if ( code ) {
			code.style.display = 'custom' === type.value ? '' : 'none';
		}

		var own = [ 'image', 'text', 'custom' ].indexOf( type.value ) !== -1;
		var group = 'group' === type.value;
		show( '.adflow-field-group', group );
		show( '.adflow-field-image', 'image' === type.value );
		show( '.adflow-field-text', 'text' === type.value );
		show( '.adflow-field-link', 'image' === type.value || 'text' === type.value );
		show( '#adflow-ad-unit-delivery', own );
		if ( slot ) {
			slot.style.display = [ 'display', 'inarticle', 'infeed', 'multiplex' ].indexOf( type.value ) !== -1 ? '' : 'none';
		}

		// Fields of types added by add-ons: data-adflow-types="gam,other".
		Array.prototype.forEach.call( document.querySelectorAll( '[data-adflow-types]' ), function ( el ) {
			el.style.display = el.getAttribute( 'data-adflow-types' ).split( ',' ).indexOf( type.value ) !== -1 ? '' : 'none';
		} );
	}

	type.addEventListener( 'change', toggle );
	toggle();

	// Banner image picker (media library).
	var choose = document.getElementById( 'adflow-image-choose' );
	var remove = document.getElementById( 'adflow-image-remove' );
	var idField = document.getElementById( 'adflow-image-id' );
	var preview = document.getElementById( 'adflow-image-preview' );
	var frame;
	var i18n = window.adflowUnit || {};

	if ( choose && window.wp && wp.media ) {
		choose.addEventListener( 'click', function () {
			if ( ! frame ) {
				frame = wp.media( {
					title: i18n.choose || 'Choose image',
					button: { text: i18n.use || 'Use this image' },
					library: { type: 'image' },
					multiple: false,
				} );
				frame.on( 'select', function () {
					var image = frame.state().get( 'selection' ).first().toJSON();
					var size = image.sizes && image.sizes.medium ? image.sizes.medium : image;
					idField.value = image.id;
					preview.src = size.url;
					preview.hidden = false;
					remove.hidden = false;
					var alt = document.getElementById( 'adflow-alt' );
					if ( alt && ! alt.value && image.alt ) {
						alt.value = image.alt;
					}
				} );
			}
			frame.open();
		} );

		remove.addEventListener( 'click', function () {
			idField.value = '0';
			preview.hidden = true;
			preview.removeAttribute( 'src' );
			remove.hidden = true;
		} );
	}
} )();
