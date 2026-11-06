/**
 * AdFlow admin: searchable picker and extra in-content ads. No dependencies.
 *
 * The picker is an ARIA combobox: arrows move through the results, Enter
 * chooses, Escape closes. It adds chosen IDs to a text field (which stays
 * editable) or stores them as hidden inputs.
 *
 * @package Simple_Google_Adsense
 * @since   1.6.0
 */
( function () {
	'use strict';

	var cfg = window.adflowPicker || {};
	var i18n = cfg.i18n || {};
	var doc = document;

	function format( text, value ) {
		return String( text || '' ).replace( /%[sd]/, value );
	}

	function request( params ) {
		var query = new URLSearchParams( params );
		query.set( 'action', 'adflow_picker' );
		query.set( 'nonce', cfg.nonce );

		return window.fetch( cfg.ajaxUrl + '?' + query.toString(), { credentials: 'same-origin' } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( body ) {
				return body && body.success ? body.data : [];
			} );
	}

	function parseIds( text ) {
		var seen = {};

		return String( text || '' ).split( /[\s,]+/ ).map( function ( part ) {
			return parseInt( part, 10 );
		} ).filter( function ( id ) {
			if ( ! id || id < 0 || seen[ id ] ) {
				return false;
			}
			seen[ id ] = true;
			return true;
		} );
	}

	function initPicker( root ) {
		if ( root.adflowReady ) {
			return;
		}
		root.adflowReady = true;

		var box = root.querySelector( '.adflow-picker__box' );
		var input = root.querySelector( '.adflow-picker__input' );
		var list = root.querySelector( '.adflow-picker__list' );
		var chips = root.querySelector( '.adflow-picker__chips' );
		var status = root.querySelector( '.adflow-picker__status' );
		var target = root.getAttribute( 'data-target' ) ? doc.getElementById( root.getAttribute( 'data-target' ) ) : null;
		var name = root.getAttribute( 'data-name' );
		var source = root.getAttribute( 'data-source' );
		var active = -1;
		var timer = null;
		var token = 0;

		box.hidden = false;
		Array.prototype.forEach.call( chips.querySelectorAll( '.adflow-picker__remove' ), function ( button ) {
			button.hidden = false;
		} );

		function close() {
			list.hidden = true;
			list.textContent = '';
			active = -1;
			input.setAttribute( 'aria-expanded', 'false' );
			input.removeAttribute( 'aria-activedescendant' );
		}

		function chosen() {
			return Array.prototype.map.call( chips.children, function ( chip ) {
				return parseInt( chip.getAttribute( 'data-id' ), 10 );
			} );
		}

		function syncTarget() {
			if ( target ) {
				target.value = chosen().join( ', ' );
			}
		}

		function addChip( id, label, missing ) {
			var chip = doc.createElement( 'li' );
			var text = doc.createElement( 'span' );
			var remove = doc.createElement( 'button' );

			chip.className = 'adflow-picker__chip' + ( missing ? ' is-missing' : '' );
			chip.setAttribute( 'data-id', id );
			text.textContent = label;
			remove.type = 'button';
			remove.className = 'adflow-picker__remove';
			remove.setAttribute( 'aria-label', format( i18n.remove, label ) );
			remove.textContent = '×';
			chip.appendChild( text );
			chip.appendChild( remove );

			if ( name ) {
				var hidden = doc.createElement( 'input' );
				hidden.type = 'hidden';
				hidden.name = name;
				hidden.value = id;
				chip.appendChild( hidden );
			}

			chips.appendChild( chip );
		}

		function setActive( index ) {
			var options = list.querySelectorAll( '[role="option"]' );

			if ( ! options.length ) {
				return;
			}

			active = ( index + options.length ) % options.length;
			Array.prototype.forEach.call( options, function ( option, i ) {
				option.setAttribute( 'aria-selected', i === active ? 'true' : 'false' );
			} );
			input.setAttribute( 'aria-activedescendant', options[ active ].id );
			options[ active ].scrollIntoView( { block: 'nearest' } );
		}

		function choose( option ) {
			var id = parseInt( option.getAttribute( 'data-id' ), 10 );
			var label = option.getAttribute( 'data-label' );

			if ( chosen().indexOf( id ) === -1 ) {
				addChip( id, label );
				syncTarget();
			}

			status.textContent = format( i18n.added, label );
			input.value = '';
			close();
			input.focus();
		}

		function show( items ) {
			var existing = chosen();

			list.textContent = '';
			active = -1;
			input.removeAttribute( 'aria-activedescendant' );

			items = items.filter( function ( item ) {
				return existing.indexOf( item.id ) === -1;
			} );

			if ( ! items.length ) {
				close();
				status.textContent = i18n.none;
				return;
			}

			items.forEach( function ( item, index ) {
				var option = doc.createElement( 'li' );
				option.id = list.id + '-' + index;
				option.className = 'adflow-picker__option';
				option.setAttribute( 'role', 'option' );
				option.setAttribute( 'aria-selected', 'false' );
				option.setAttribute( 'data-id', item.id );
				option.setAttribute( 'data-label', item.label );
				option.textContent = item.label + ( item.meta ? ' · ' + item.meta : '' );
				list.appendChild( option );
			} );

			list.hidden = false;
			input.setAttribute( 'aria-expanded', 'true' );
			status.textContent = format( i18n.found, items.length );
		}

		input.addEventListener( 'input', function () {
			var query = input.value.trim();

			window.clearTimeout( timer );

			if ( ! query ) {
				close();
				return;
			}

			timer = window.setTimeout( function () {
				var mine = ++token;

				status.textContent = i18n.searching;
				request( { source: source, q: query } ).then( function ( items ) {
					if ( mine === token && input.value.trim() === query ) {
						show( items );
					}
				} );
			}, 200 );
		} );

		input.addEventListener( 'keydown', function ( event ) {
			if ( 'ArrowDown' === event.key || 'ArrowUp' === event.key ) {
				event.preventDefault();
				setActive( active + ( 'ArrowDown' === event.key ? 1 : -1 ) );
			} else if ( 'Enter' === event.key ) {
				// Never submit the settings form from the search box.
				event.preventDefault();
				var options = list.querySelectorAll( '[role="option"]' );
				if ( options.length ) {
					choose( options[ active >= 0 ? active : 0 ] );
				}
			} else if ( 'Escape' === event.key && ! list.hidden ) {
				event.preventDefault();
				close();
			}
		} );

		input.addEventListener( 'blur', function () {
			window.setTimeout( close, 150 );
		} );

		// mousedown, so the option is chosen before the box loses focus.
		list.addEventListener( 'mousedown', function ( event ) {
			var option = event.target.closest( '[role="option"]' );

			if ( option ) {
				event.preventDefault();
				choose( option );
			}
		} );

		chips.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '.adflow-picker__remove' );

			if ( button ) {
				button.closest( '.adflow-picker__chip' ).remove();
				syncTarget();
				input.focus();
			}
		} );

		// IDs typed by hand: show what they point to.
		if ( target ) {
			target.addEventListener( 'change', function () {
				var ids = parseIds( target.value );

				if ( ! ids.length ) {
					chips.textContent = '';
					return;
				}

				request( { source: source, ids: ids.join( ',' ) } ).then( function ( items ) {
					chips.textContent = '';
					items.forEach( function ( item ) {
						addChip( item.id, item.label || format( i18n.missing, item.id ), ! item.label );
					} );
				} );
			} );
		}
	}

	function initPickers( scope ) {
		Array.prototype.forEach.call( scope.querySelectorAll( '.adflow-picker' ), initPicker );
	}

	/* ---------- Extra in-content ads ---------- */

	function syncModes( card ) {
		var select = card.querySelector( '[data-adflow-slot-mode]' );

		if ( ! select ) {
			return;
		}

		Array.prototype.forEach.call( card.querySelectorAll( '[data-adflow-modes]' ), function ( el ) {
			el.hidden = el.getAttribute( 'data-adflow-modes' ).split( ' ' ).indexOf( select.value ) === -1;
		} );
	}

	function checkSelector( input ) {
		var help = input.parentNode.querySelector( '[data-adflow-selector-help]' );
		var value = input.value.trim();
		var valid = true;

		if ( value ) {
			try {
				doc.createDocumentFragment().querySelector( value );
			} catch ( e ) {
				valid = false;
			}
		}

		if ( ! help.getAttribute( 'data-default' ) ) {
			help.setAttribute( 'data-default', help.textContent );
		}

		help.textContent = valid ? help.getAttribute( 'data-default' ) : i18n.badSelector;
		help.classList.toggle( 'adflow-field__error', ! valid );

		if ( valid ) {
			input.removeAttribute( 'aria-invalid' );
		} else {
			input.setAttribute( 'aria-invalid', 'true' );
		}
	}

	function initSlots( wrap ) {
		var list = wrap.querySelector( '[data-adflow-slots-list]' );
		var template = wrap.querySelector( '[data-adflow-slot-template]' );
		var add = wrap.querySelector( '[data-adflow-slot-add]' );
		var max = parseInt( wrap.getAttribute( 'data-max' ), 10 ) || 20;
		var next = parseInt( wrap.getAttribute( 'data-next' ), 10 ) || 1;

		function syncAdd() {
			add.disabled = list.children.length >= max;
		}

		add.addEventListener( 'click', function () {
			var holder = doc.createElement( 'div' );

			holder.innerHTML = template.innerHTML.replace( /__N__/g, String( next ) );
			next++;

			var card = holder.firstElementChild;
			list.appendChild( card );
			initPickers( card );
			syncModes( card );
			syncAdd();

			var field = card.querySelector( '.adflow-placement__unit select' );
			if ( field ) {
				field.focus();
			}
		} );

		wrap.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '[data-adflow-slot-remove]' );

			if ( button ) {
				button.closest( '.adflow-slot' ).remove();
				syncAdd();
				add.focus();
			}
		} );

		wrap.addEventListener( 'change', function ( event ) {
			if ( event.target.matches( '[data-adflow-slot-mode]' ) ) {
				syncModes( event.target.closest( '.adflow-slot' ) );
			}
			if ( event.target.matches( '[data-adflow-selector]' ) ) {
				checkSelector( event.target );
			}
		} );
	}

	initPickers( doc );
	Array.prototype.forEach.call( doc.querySelectorAll( '[data-adflow-slots]' ), initSlots );
} )();
