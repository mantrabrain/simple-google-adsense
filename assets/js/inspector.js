/**
 * AdFlow Ad Inspector (administrators only).
 *
 * Outlines every ad on the page and labels it with its unit, slot, placement
 * and fill status. Labels sit above the ad, never on top of it.
 *
 * @package Simple_Google_Adsense
 * @since   1.4.0
 */
( function () {
	'use strict';

	var cfg = window.adflowInspector || {};
	var t = cfg.i18n || {};
	var KEY = 'adflow_inspect';
	var root = document.documentElement;

	function storage( value ) {
		try {
			if ( undefined === value ) {
				return window.sessionStorage.getItem( KEY );
			}
			window.sessionStorage.setItem( KEY, value );
		} catch ( e ) {}
		return null;
	}

	function status( ins ) {
		if ( ! ins ) {
			return 'custom';
		}
		var fill = ins.getAttribute( 'data-ad-status' );
		if ( 'filled' === fill || 'unfilled' === fill ) {
			return fill;
		}
		return ins.hasAttribute( 'data-adsbygoogle-status' ) ? 'pending' : 'notLoaded';
	}

	function clear() {
		Array.prototype.forEach.call( document.querySelectorAll( '.adflow-inspect-badge' ), function ( el ) {
			el.parentNode.removeChild( el );
		} );
	}

	function badge( target, parts, state ) {
		var el = document.createElement( 'div' );
		el.className = 'adflow-inspect-badge is-' + state;
		el.textContent = parts.join( ' · ' );
		target.parentNode.insertBefore( el, target );
	}

	function inspect() {
		clear();

		var counts = { total: 0, unfilled: 0, notLoaded: 0 };
		var seen = [];

		Array.prototype.forEach.call( document.querySelectorAll( '.adflow-ad, .afx-unit' ), function ( wrapper ) {
			var ins = wrapper.querySelector( 'ins' );
			var state = status( ins );
			var parts = [ 'AdFlow' ];

			if ( wrapper.getAttribute( 'data-adflow-unit' ) ) {
				parts.push( ( t.unit || 'Unit' ) + ' #' + wrapper.getAttribute( 'data-adflow-unit' ) );
			}
			if ( ins && ins.getAttribute( 'data-ad-slot' ) ) {
				parts.push( ( t.slot || 'Slot' ) + ' ' + ins.getAttribute( 'data-ad-slot' ) );
			}
			if ( wrapper.getAttribute( 'data-adflow-placement' ) ) {
				parts.push( ( t.placement || 'Placement' ) + ': ' + wrapper.getAttribute( 'data-adflow-placement' ) );
			}
			parts.push( 'custom' === state ? t.custom : ( 'filled' === state ? t.filled : t[ state ] ) );

			badge( wrapper, parts, state );
			counts.total++;
			if ( 'unfilled' === state ) {
				counts.unfilled++;
			}
			if ( 'notLoaded' === state ) {
				counts.notLoaded++;
			}
			if ( ins ) {
				seen.push( ins );
			}
		} );

		// Auto ads placed by Google.
		Array.prototype.forEach.call( document.querySelectorAll( 'ins.adsbygoogle' ), function ( ins ) {
			if ( seen.indexOf( ins ) !== -1 ) {
				return;
			}
			var state = status( ins );
			badge( ins, [ 'AdFlow', t.autoAd || 'Auto ad', 'filled' === state ? t.filled : t[ state ] ], state );
			counts.total++;
			if ( 'unfilled' === state ) {
				counts.unfilled++;
			}
		} );

		var node = document.querySelector( '#wp-admin-bar-adflow-inspect > .ab-item' );
		var summary = counts.total
			? ( t.summary || '' ).replace( '%1$d', counts.total ).replace( '%2$d', counts.unfilled ).replace( '%3$d', counts.notLoaded )
			: ( cfg.hideForAdmins ? t.hidden : t.none );

		if ( node ) {
			node.setAttribute( 'title', summary );
		}

		var bar = document.querySelector( '.adflow-inspect-summary' ) || document.createElement( 'div' );
		bar.className = 'adflow-inspect-summary';
		bar.setAttribute( 'role', 'status' );
		bar.textContent = summary;
		document.body.appendChild( bar );
	}

	function setActive( on ) {
		storage( on ? '1' : '0' );
		root.classList.toggle( 'adflow-inspecting', on );

		if ( on ) {
			inspect();
			// Fill status arrives asynchronously; refresh a few times.
			[ 1500, 4000, 8000 ].forEach( function ( delay ) {
				window.setTimeout( function () {
					if ( root.classList.contains( 'adflow-inspecting' ) ) {
						inspect();
					}
				}, delay );
			} );
		} else {
			clear();
			var bar = document.querySelector( '.adflow-inspect-summary' );
			if ( bar ) {
				bar.parentNode.removeChild( bar );
			}
		}
	}

	document.addEventListener( 'click', function ( event ) {
		var link = event.target.closest && event.target.closest( '#wp-admin-bar-adflow-inspect a' );
		if ( link ) {
			event.preventDefault();
			setActive( ! root.classList.contains( 'adflow-inspecting' ) );
		}
	} );

	function init() {
		// Opened from the Dashboard's "Inspect ads on your site" shortcut.
		if ( /[?&]adflow-inspect=1/.test( window.location.search ) ) {
			storage( '1' );
		}

		if ( '1' === storage() ) {
			setActive( true );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
