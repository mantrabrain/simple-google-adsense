/**
 * AdFlow: your own ads in the browser.
 *
 * - Hides an ad when a cached page outlives its schedule.
 * - Measures, per ad element (IAB/MRC definitions):
 *     i  impression: the ad is displayed (not hidden, not prerendered);
 *     v  viewable:   >= 50% on screen for >= 1 continuous second, tab visible;
 *     c  click:      a link in the ad was activated (not a scroll).
 * - Sends small batched beacons. No cookies, no IDs.
 *
 * @package Simple_Google_Adsense
 * @since   1.4.0
 */
( function () {
	'use strict';

	var cfg = window.afxCfg || {};
	var doc = document;
	var queue = [];
	var timer = null;
	var mobile = window.matchMedia && window.matchMedia( '(max-width: 767px)' ).matches ? 1 : 0;
	var VIEW_MS = 1000;
	var observer = null;
	var state = typeof WeakMap === 'function' ? new WeakMap() : null;

	function info( el ) {
		if ( ! state ) {
			return el.__afx || ( el.__afx = {} );
		}
		if ( ! state.has( el ) ) {
			state.set( el, {} );
		}
		return state.get( el );
	}

	function running( el ) {
		var now = Date.now() / 1000;
		var start = parseInt( el.getAttribute( 'data-afx-s' ), 10 ) || 0;
		var end = parseInt( el.getAttribute( 'data-afx-e' ), 10 ) || 0;

		return ( ! start || now >= start ) && ( ! end || now <= end );
	}

	/* ---------- Delivery rules (set by AdFlow Pro): dayparting & frequency cap ---------- */

	function siteNow( el ) {
		var tz = parseInt( el.getAttribute( 'data-afx-tz' ), 10 ) || 0;
		// Shift to the site's time zone, then read the UTC fields.
		return new Date( Date.now() + tz * 60000 );
	}

	function capId( el ) {
		return el.getAttribute( 'data-afx' ) || el.getAttribute( 'data-adflow-unit' );
	}

	function capKey( el ) {
		return 'afx_cap_' + capId( el ) + '_' + siteNow( el ).toISOString().slice( 0, 10 );
	}

	function allowed( el ) {
		var now = siteNow( el );
		var days = el.getAttribute( 'data-afx-days' );
		var hours = el.getAttribute( 'data-afx-hours' );

		if ( days && days.split( ',' ).indexOf( String( now.getUTCDay() ) ) === -1 ) {
			return false;
		}

		if ( hours ) {
			var range = hours.split( '-' );
			var hour = now.getUTCHours();
			if ( hour < parseInt( range[ 0 ], 10 ) || hour >= parseInt( range[ 1 ], 10 ) ) {
				return false;
			}
		}

		var cap = parseInt( el.getAttribute( 'data-afx-cap' ), 10 ) || 0;
		if ( cap && capId( el ) ) {
			try {
				if ( ( parseInt( window.localStorage.getItem( capKey( el ) ), 10 ) || 0 ) >= cap ) {
					return false;
				}
			} catch ( e ) {}
		}

		return true;
	}

	/* ---------- Country targeting (AdFlow Pro): resolved once per session ---------- */

	var country = null;
	var countryWaiters = [];

	function withCountry( cb ) {
		if ( null !== country ) {
			return cb( country );
		}
		countryWaiters.push( cb );
		if ( countryWaiters.length > 1 ) {
			return;
		}
		var done = function ( cc, remember ) {
			country = /^[A-Z]{2}$/.test( cc || '' ) ? cc : '';
			if ( remember ) {
				try {
					window.sessionStorage.setItem( 'afx_cc', country || '--' );
				} catch ( e ) {}
			}
			var waiting = countryWaiters;
			countryWaiters = [];
			waiting.forEach( function ( fn ) {
				fn( country );
			} );
		};
		try {
			var saved = window.sessionStorage.getItem( 'afx_cc' );
			if ( saved ) {
				return done( '--' === saved ? '' : saved, false );
			}
		} catch ( e ) {}
		if ( ! cfg.geo || ! window.fetch ) {
			return done( '' );
		}
		window.fetch( cfg.geo, { credentials: 'omit', cache: 'no-store' } )
			.then( function ( r ) {
				if ( ! r.ok ) {
					throw new Error( 'geo' );
				}
				return r.json();
			} )
			.then( function ( d ) {
				done( d && d.c, true );
			} )
			.catch( function () {
				done( '', false );
			} );
	}

	// "+US,CA" = only these countries, "-US" = everywhere except these.
	// An unknown country only sees ads that exclude countries.
	function geoOk( el ) {
		var rule = el.getAttribute( 'data-afx-geo' );
		if ( ! rule ) {
			return true;
		}
		var listed = rule.slice( 1 ).split( ',' ).indexOf( country ) !== -1;
		if ( ! country ) {
			return '-' === rule.charAt( 0 );
		}
		return '+' === rule.charAt( 0 ) ? listed : ! listed;
	}

	function eligible( el ) {
		return ! el || el.nodeType !== 1 || ( running( el ) && allowed( el ) && geoOk( el ) );
	}

	function countCap( el ) {
		if ( ! el.hasAttribute( 'data-afx-cap' ) ) {
			return;
		}
		try {
			var key = capKey( el );
			window.localStorage.setItem( key, String( ( parseInt( window.localStorage.getItem( key ), 10 ) || 0 ) + 1 ) );
		} catch ( e ) {}
	}

	function displayed( el ) {
		return !! ( el.offsetWidth || el.offsetHeight || el.getClientRects().length ) && 'hidden' !== window.getComputedStyle( el ).visibility;
	}

	function consented() {
		if ( ! cfg.consent ) {
			return true;
		}

		return 'function' === typeof window.wp_has_consent && window.wp_has_consent( 'statistics' );
	}

	function send( body ) {
		try {
			if ( navigator.sendBeacon && navigator.sendBeacon( cfg.url, new Blob( [ body ], { type: 'application/json' } ) ) ) {
				return;
			}
		} catch ( e ) {}

		if ( window.fetch ) {
			window.fetch( cfg.url, { method: 'POST', body: body, keepalive: true, credentials: 'same-origin', headers: { 'Content-Type': 'application/json' } } ).catch( function () {} );
		}
	}

	function flush() {
		window.clearTimeout( timer );
		timer = null;

		while ( queue.length && cfg.url ) {
			send( JSON.stringify( queue.splice( 0, 50 ) ) );
		}
	}

	var waitingForConsent = [];

	function record( el, type ) {
		if ( ! cfg.track || ! el.hasAttribute( 'data-afx' ) ) {
			return;
		}

		var s = info( el );

		// A viewable impression or click only counts after its impression.
		if ( 'i' !== type && ! s.i ) {
			return;
		}

		if ( ! consented() ) {
			if ( 'i' === type && waitingForConsent.indexOf( el ) === -1 ) {
				waitingForConsent.push( el );
			}
			return;
		}

		// Each ad element counts at most one impression and one viewable impression per page view.
		if ( 'c' !== type ) {
			if ( s[ type ] ) {
				return;
			}
			s[ type ] = true;
		}

		queue.push( {
			a: parseInt( el.getAttribute( 'data-afx' ), 10 ),
			p: el.getAttribute( 'data-afx-p' ) || '',
			t: type,
			d: mobile,
		} );

		// Send soon rather than at page exit: exit beacons are not reliable on
		// every browser (notably mobile Safari), and quick exits must count.
		if ( ! timer ) {
			timer = window.setTimeout( flush, 400 );
		}

		if ( queue.length >= 50 ) {
			flush();
		}
	}

	/* ---------- Viewability: 50% for 1 continuous second while the tab is visible ---------- */

	function startTimer( el ) {
		var s = info( el );

		if ( s.v || s.t || 'visible' !== doc.visibilityState ) {
			return;
		}

		s.t = window.setTimeout( function () {
			s.t = null;
			if ( s.inView && s.shownAt && 'visible' === doc.visibilityState ) {
				record( el, 'v' );
				if ( observer ) {
					observer.unobserve( el );
				}
			}
		}, VIEW_MS );
	}

	function stopTimer( el ) {
		var s = info( el );

		if ( s.t ) {
			window.clearTimeout( s.t );
			s.t = null;
		}
	}

	var tracked = [];

	/**
	 * Put a <template>'s content in its place and run its scripts one after
	 * another, the way the browser would have while parsing: external scripts
	 * finish loading before the next script runs, and document.write() writes
	 * into the ad instead of replacing the page.
	 */
	function place( tpl, target ) {
		var frag = doc.importNode( tpl.content, true );
		var scripts = Array.prototype.slice.call( frag.querySelectorAll( 'script' ) );

		// Hold the scripts until they are in the page.
		scripts.forEach( function ( sc ) {
			sc.setAttribute( 'data-afx-type', sc.getAttribute( 'type' ) || '' );
			sc.setAttribute( 'type', 'text/plain' );
		} );

		if ( target ) {
			target.appendChild( frag );
		} else {
			tpl.parentNode.replaceChild( frag, tpl );
		}

		var i = 0;
		var next = function () {
			delete doc.write;
			delete doc.writeln;
			if ( i >= scripts.length ) {
				return;
			}
			var old = scripts[ i++ ];
			if ( ! old.parentNode ) {
				return next();
			}
			var js = doc.createElement( 'script' );
			Array.prototype.forEach.call( old.attributes, function ( a ) {
				if ( 'type' !== a.name && 'data-afx-type' !== a.name ) {
					js.setAttribute( a.name, a.value );
				}
			} );
			if ( old.getAttribute( 'data-afx-type' ) ) {
				js.setAttribute( 'type', old.getAttribute( 'data-afx-type' ) );
			}
			doc.write = doc.writeln = function () {
				js.insertAdjacentHTML( 'beforebegin', Array.prototype.join.call( arguments, '' ) );
			};
			if ( old.hasAttribute( 'src' ) ) {
				js.async = false;
				js.onload = js.onerror = next;
				old.parentNode.replaceChild( js, old );
			} else {
				js.text = old.text;
				old.parentNode.replaceChild( js, old );
				next();
			}
		};
		next();
	}

	window.afxPlace = place;

	// Whether a unit (or a group with any eligible member) may show now.
	window.afxCheck = function ( el, cb ) {
		var decide = function () {
			if ( el.classList && el.classList.contains( 'afx-group' ) ) {
				cb( Array.prototype.some.call( el.children, function ( t ) {
					return 'TEMPLATE' === t.tagName && eligible( t.content.firstElementChild );
				} ) );
				return;
			}
			cb( eligible( el ) );
		};
		if ( -1 !== el.outerHTML.indexOf( 'data-afx-geo' ) ) {
			withCountry( decide );
		} else {
			decide();
		}
	};

	// Insert held-back code (consent, schedule, targeting rules passed).
	function reveal( el ) {
		Array.prototype.slice.call( el.children ).forEach( function ( tpl ) {
			if ( 'TEMPLATE' === tpl.tagName && tpl.classList.contains( 'afx-wait' ) ) {
				place( tpl );
			}
		} );
		el.removeAttribute( 'data-afx-hold' );
	}

	// Units hidden when the page loaded (tabs, accordions) count once they appear.
	var appear = 'IntersectionObserver' in window ? new IntersectionObserver( function ( entries ) {
		entries.forEach( function ( entry ) {
			if ( entry.isIntersecting ) {
				appear.unobserve( entry.target );
				info( entry.target ).ready = false;
				setup( entry.target );
			}
		} );
	} ) : null;

	function setup( el ) {
		var s = info( el );

		if ( s.ready ) {
			return;
		}
		s.ready = true;

		// Other networks' code waits for marketing consent.
		if ( el.hasAttribute( 'data-afx-consent' ) && window.afxConsent && ! window.afxConsent.isGranted() ) {
			s.ready = false;
			window.afxConsent.whenGranted( function () {
				el.removeAttribute( 'data-afx-consent' );
				setup( el );
			} );
			return;
		}

		if ( el.hasAttribute( 'data-afx-geo' ) && null === country ) {
			s.ready = false;
			withCountry( function () {
				setup( el );
			} );
			return;
		}

		// Rules decide before any held-back code runs: an ad that may not show
		// never loads (hiding a loaded ad is against ad network policies).
		if ( ! running( el ) || ! allowed( el ) || ! geoOk( el ) ) {
			el.style.display = 'none';
			Array.prototype.slice.call( el.querySelectorAll( 'template.afx-wait' ) ).forEach( function ( tpl ) {
				tpl.parentNode.removeChild( tpl );
			} );
			return;
		}

		if ( el.hasAttribute( 'data-afx-geo' ) ) {
			el.classList.add( 'afx-geo-ok' );
		}

		if ( el.hasAttribute( 'data-afx-hold' ) ) {
			reveal( el );
		}

		// Removed from the page, or not displayed yet (count it once it appears).
		if ( el.isConnected === false ) {
			return;
		}
		if ( ! displayed( el ) ) {
			if ( appear ) {
				appear.observe( el );
			}
			return;
		}

		// Impression = the creative begins to render (IAB/MRC). Banner images
		// are lazy-loaded, so they count when the image has actually loaded.
		var img = el.querySelector( 'img.afx-img' );
		var rendered = function () {
			if ( ! s.shownAt ) {
				s.shownAt = Date.now();
				countCap( el );
				record( el, 'i' );
				if ( s.inView ) {
					startTimer( el );
				}
			}
		};

		if ( img && ! ( img.complete && img.naturalWidth ) ) {
			img.addEventListener( 'load', rendered, { once: true } );
		} else {
			rendered();
		}

		if ( ! el.hasAttribute( 'data-afx' ) ) {
			return;
		}

		tracked.push( el );

		if ( observer ) {
			observer.observe( el );
		}
	}

	/* ---------- Rotation groups: pick one member per page view ---------- */

	function pick( group ) {
		var s = info( group );

		if ( s.picked ) {
			return;
		}
		s.picked = true;

		var templates = Array.prototype.slice.call( group.children ).filter( function ( el ) {
			return 'TEMPLATE' === el.tagName;
		} );

		if ( ! templates.length ) {
			return;
		}

		// Country rules decide who is eligible: wait for the country first.
		if ( null === country && templates.some( function ( el ) {
			return el.innerHTML.indexOf( 'data-afx-geo' ) !== -1;
		} ) ) {
			s.picked = false;
			withCountry( function () {
				scan( group );
			} );
			return;
		}

		// Only members that may show right now (schedule, days/hours, cap, country).
		var options = templates.filter( function ( el ) {
			return eligible( el.content.firstElementChild );
		} );

		if ( ! options.length ) {
			templates.forEach( function ( el ) {
				el.parentNode.removeChild( el );
			} );
			return;
		}

		var chosen = 0;

		if ( 'ordered' === group.getAttribute( 'data-afx-mode' ) ) {
			var key = 'afx_seq_' + group.getAttribute( 'data-afx-g' );
			var next = 0;
			try {
				next = ( parseInt( window.localStorage.getItem( key ), 10 ) || 0 ) % options.length;
				window.localStorage.setItem( key, String( next + 1 ) );
			} catch ( e ) {
				next = Math.floor( Math.random() * options.length );
			}
			chosen = next;
		} else {
			var total = 0;
			options.forEach( function ( el ) {
				total += parseInt( el.getAttribute( 'data-w' ), 10 ) || 1;
			} );
			var roll = Math.random() * total;
			for ( var i = 0; i < options.length; i++ ) {
				roll -= parseInt( options[ i ].getAttribute( 'data-w' ), 10 ) || 1;
				if ( roll < 0 ) {
					chosen = i;
					break;
				}
			}
		}

		var chosenTpl = options[ chosen ];
		templates.forEach( function ( el ) {
			if ( el !== chosenTpl ) {
				el.parentNode.removeChild( el );
			}
		} );
		place( chosenTpl );

		// Let other scripts (e.g. AdFlow Pro targeting) handle what was inserted.
		try {
			doc.dispatchEvent( new CustomEvent( 'afx:inserted', { detail: { root: group } } ) );
		} catch ( e ) {}
	}

	function scan( root ) {
		if ( root.nodeType !== 1 ) {
			return;
		}
		if ( root.classList && root.classList.contains( 'afx-group' ) ) {
			pick( root );
		}
		Array.prototype.forEach.call( root.querySelectorAll( '.afx-group' ), pick );
		if ( root.classList && root.classList.contains( 'afx-unit' ) ) {
			setup( root );
		}
		Array.prototype.forEach.call( root.querySelectorAll( '.afx-unit' ), setup );
	}

	/* ---------- Clicks ---------- */

	function clickOn( unit ) {
		if ( ! unit ) {
			return;
		}

		var s = info( unit );
		var now = Date.now();

		// Ignore clicks on ads that were never shown, within 150 ms of showing
		// (scripted), or repeated within a second (double clicks).
		if ( ! s.shownAt || now - s.shownAt < 150 || ( s.lastClick && now - s.lastClick < 1000 ) ) {
			return;
		}

		s.lastClick = now;
		record( unit, 'c' );
		flush();
	}

	function onClick( event ) {
		if ( event.defaultPrevented && event.type === 'auxclick' ) {
			return;
		}
		if ( 'auxclick' === event.type && 1 !== event.button ) {
			return;
		}

		var link = event.target.closest && event.target.closest( '[data-afx] a[href]' );

		// Redirect links are counted on the server.
		if ( link && ! link.hasAttribute( 'data-afx-go' ) ) {
			clickOn( link.closest( '[data-afx]' ) );
		}
	}

	function init() {
		if ( 'IntersectionObserver' in window ) {
			observer = new IntersectionObserver( function ( entries ) {
				entries.forEach( function ( entry ) {
					var s = info( entry.target );
					s.inView = entry.isIntersecting && entry.intersectionRatio >= 0.5;

					if ( s.inView ) {
						startTimer( entry.target );
					} else {
						stopTimer( entry.target );
					}
				} );
			}, { threshold: [ 0, 0.5, 1 ] } );
		}

		scan( doc.body );

		// Ads added later (infinite scroll, AJAX-loaded content).
		if ( 'MutationObserver' in window ) {
			new MutationObserver( function ( mutations ) {
				mutations.forEach( function ( mutation ) {
					Array.prototype.forEach.call( mutation.addedNodes, scan );
				} );
			} ).observe( doc.body, { childList: true, subtree: true } );
		}

		doc.addEventListener( 'click', onClick, true );
		doc.addEventListener( 'auxclick', onClick, true );

		// Custom code ads usually render an iframe: a click moves focus into it.
		// Keyboard users tabbing into the iframe are not clicks, and the ad
		// must be the one under the pointer.
		var lastTab = 0;
		var hovered = null;
		doc.addEventListener( 'keydown', function ( e ) {
			if ( 'Tab' === e.key ) {
				lastTab = Date.now();
			}
		}, true );
		doc.addEventListener( 'mouseover', function ( e ) {
			hovered = e.target && e.target.closest ? e.target.closest( '[data-afx]' ) : null;
		}, true );
		window.addEventListener( 'blur', function () {
			window.setTimeout( function () {
				var active = doc.activeElement;
				var unit = active && 'IFRAME' === active.tagName && active.closest && active.closest( '[data-afx]' );
				if ( unit && unit === hovered && Date.now() - lastTab > 500 && 'visible' === doc.visibilityState ) {
					clickOn( unit );
				}
			}, 0 );
		} );

		// Impressions held back until statistics consent was given.
		doc.addEventListener( 'wp_listen_for_consent_change', function () {
			if ( consented() ) {
				waitingForConsent.splice( 0 ).forEach( function ( el ) {
					record( el, 'i' );
				} );
			}
		} );

		// Pause viewability while the tab is hidden; send what is queued.
		doc.addEventListener( 'visibilitychange', function () {
			tracked.forEach( function ( el ) {
				if ( 'visible' === doc.visibilityState ) {
					if ( info( el ).inView ) {
						startTimer( el );
					}
				} else {
					stopTimer( el );
				}
			} );

			if ( 'hidden' === doc.visibilityState ) {
				flush();
			}
		} );

		window.addEventListener( 'pagehide', flush );
	}

	function start() {
		// Run after every other DOMContentLoaded handler (targeting rules first).
		var go = function () {
			window.setTimeout( init, 0 );
		};

		// Do not count pages the browser only prerendered.
		if ( doc.prerendering ) {
			doc.addEventListener( 'prerenderingchange', go, { once: true } );
		} else {
			go();
		}
	}

	if ( 'loading' === doc.readyState ) {
		doc.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();
