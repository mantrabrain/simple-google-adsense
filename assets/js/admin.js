/**
 * AdFlow admin screens.
 *
 * @package Simple_Google_Adsense
 * @since   1.4.0
 */
( function () {
	'use strict';

	var cfg = window.adflowAdmin || {};

	// Placements: reveal a placement's options when it is switched on.
	document.addEventListener( 'change', function ( event ) {
		var input = event.target;

		if ( ! input.matches || ! input.matches( '.adflow-placement__title input[type="checkbox"]' ) ) {
			return;
		}

		var row = input.closest( '.adflow-placement' );

		if ( row ) {
			row.classList.toggle( 'is-enabled', input.checked );
		}
	} );

	// Reports: custom date inputs only for "Custom dates".
	var range = document.getElementById( 'adflow-report-range' );
	var dates = document.querySelector( '.adflow-custom-dates' );

	if ( range && dates ) {
		var syncDates = function () {
			dates.style.display = 'custom' === range.value ? '' : 'none';
		};
		range.addEventListener( 'change', syncDates );
		syncDates();
	}

	// Upgrade screen: yearly / lifetime prices.
	document.querySelectorAll( '[data-adflow-billing]' ).forEach( function ( wrap ) {
		var buttons = wrap.querySelectorAll( '.adflow-billing__btn' );

		buttons.forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var period = button.getAttribute( 'data-period' );

				wrap.setAttribute( 'data-adflow-billing', period );
				buttons.forEach( function ( other ) {
					var on = other === button;
					other.classList.toggle( 'is-active', on );
					other.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
				} );
				wrap.querySelectorAll( '[data-show]' ).forEach( function ( el ) {
					el.hidden = el.getAttribute( 'data-show' ) !== period;
				} );
			} );
		} );
	} );

	// Click to copy (shortcodes, ads.txt lines).
	document.addEventListener( 'click', function ( event ) {
		var el = event.target.closest && event.target.closest( '[data-adflow-copy]' );

		if ( ! el || ! navigator.clipboard ) {
			return;
		}

		event.preventDefault();

		var text = el.getAttribute( 'data-adflow-copy' ) || el.textContent;
		// Keep the real label even when clicked twice within the "Copied" time.
		var original = el.getAttribute( 'data-label' ) || el.textContent;
		el.setAttribute( 'data-label', original );

		navigator.clipboard.writeText( text.trim() ).then( function () {
			el.textContent = cfg.copied || 'Copied';
			window.setTimeout( function () {
				el.textContent = original;
			}, 1500 );
		}, function () {
			// Clipboard refused (e.g. plain http): select the text to copy by hand.
			var range = document.createRange();
			range.selectNodeContents( el );
			window.getSelection().removeAllRanges();
			window.getSelection().addRange( range );
		} );
	} );
} )();
