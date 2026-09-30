/**
 * Search suggestions: turns every search box in a form marked data-tmc-suggest into an
 * ARIA 1.2 combobox with a listbox popup (progressive enhancement — without JavaScript, or if
 * the suggestion service fails, the form simply submits to the results page).
 *
 *   type 2+ characters   suggestions load (debounced, cached per query); a live region
 *                        announces how many are available
 *   Down / Up            open the list and move through suggestions (aria-activedescendant;
 *                        focus stays in the text box)
 *   Enter                open the highlighted suggestion; with none highlighted, search
 *   Escape               close the list; press again to clear the box
 *   Tab / click outside  close the list
 */
( function () {
	'use strict';

	const settings = window.tmcSearch;
	if ( ! settings || ! settings.endpoint || ! window.fetch ) {
		return;
	}
	const minChars = parseInt( settings.minChars, 10 ) || 2; // localized values arrive as strings

	document.querySelectorAll( 'form[data-tmc-suggest]' ).forEach( ( form, formIndex ) => {
		const input = form.querySelector( 'input[name="s"]' );
		if ( input ) {
			enhance( form, input, formIndex );
		}
	} );

	function enhance( form, input, formIndex ) {
		const baseId = ( input.id || 'tmc-search-' + formIndex ) + '-suggest';
		const host = input.parentElement;
		host.classList.add( 'suggest-host' );

		const list = document.createElement( 'ul' );
		list.id = baseId + '-list';
		list.className = 'suggest-list';
		list.setAttribute( 'role', 'listbox' );
		list.setAttribute( 'aria-label', settings.label || 'Search suggestions' );
		list.hidden = true;

		const status = document.createElement( 'div' );
		status.className = 'screen-reader-text';
		status.setAttribute( 'role', 'status' );
		status.setAttribute( 'aria-live', 'polite' );
		status.setAttribute( 'aria-atomic', 'true' );

		host.appendChild( list );
		host.appendChild( status );

		input.setAttribute( 'role', 'combobox' );
		input.setAttribute( 'aria-autocomplete', 'list' );
		input.setAttribute( 'aria-expanded', 'false' );
		input.setAttribute( 'aria-controls', list.id );

		const cache = new Map();
		let items = [];
		let active = -1;
		let timer = 0;
		let controller = null;
		let announceTimer = 0;

		function announce( message ) {
			window.clearTimeout( announceTimer );
			status.textContent = '';
			announceTimer = window.setTimeout( () => {
				status.textContent = message;
			}, 150 );
		}

		function options() {
			return list.querySelectorAll( '[role="option"]' );
		}

		function setActive( index ) {
			const all = options();
			active = index;
			all.forEach( ( option, n ) => option.setAttribute( 'aria-selected', String( n === index ) ) );
			if ( index >= 0 && all[ index ] ) {
				input.setAttribute( 'aria-activedescendant', all[ index ].id );
				all[ index ].scrollIntoView( { block: 'nearest' } );
			} else {
				input.removeAttribute( 'aria-activedescendant' );
			}
		}

		function open() {
			if ( items.length ) {
				list.hidden = false;
				input.setAttribute( 'aria-expanded', 'true' );
			}
		}

		function close() {
			list.hidden = true;
			input.setAttribute( 'aria-expanded', 'false' );
			setActive( -1 );
		}

		function go( index ) {
			const item = items[ index ];
			if ( item && item.url ) {
				input.value = item.title;
				close();
				window.location.assign( item.url );
			}
		}

		function render( data ) {
			items = Array.isArray( data.items ) ? data.items : [];
			list.textContent = '';
			items.forEach( ( item, n ) => {
				const option = document.createElement( 'li' );
				option.id = baseId + '-' + n;
				option.className = 'suggest-option';
				option.setAttribute( 'role', 'option' );
				option.setAttribute( 'aria-selected', 'false' );

				const title = document.createElement( 'span' );
				title.className = 'suggest-title';
				title.textContent = item.title;
				const separator = document.createElement( 'span' );
				separator.className = 'screen-reader-text';
				separator.textContent = ', ';
				const type = document.createElement( 'span' );
				type.className = 'suggest-type';
				type.textContent = item.type;
				option.append( title, separator, type );

				// Keep focus in the text box while clicking an option.
				option.addEventListener( 'mousedown', ( event ) => event.preventDefault() );
				option.addEventListener( 'click', () => go( n ) );
				list.appendChild( option );
			} );
			active = -1;
			input.removeAttribute( 'aria-activedescendant' );
			if ( items.length ) {
				open();
				announce( 1 === items.length ? settings.one : ( settings.count || '%d' ).replace( '%d', items.length ) );
			} else {
				close();
				announce( settings.none || '' );
			}
		}

		function load( query ) {
			if ( cache.has( query ) ) {
				render( cache.get( query ) );
				return;
			}
			if ( controller ) {
				controller.abort();
			}
			controller = window.AbortController ? new window.AbortController() : null;
			const url =
				settings.endpoint +
				( settings.endpoint.indexOf( '?' ) === -1 ? '?' : '&' ) +
				'q=' +
				encodeURIComponent( query ) +
				( settings.lang ? '&lang=' + encodeURIComponent( settings.lang ) : '' );
			window
				.fetch( url, {
					credentials: 'omit',
					headers: { Accept: 'application/json' },
					signal: controller ? controller.signal : undefined,
				} )
				.then( ( response ) => ( response.ok ? response.json() : Promise.reject( response.status ) ) )
				.then( ( data ) => {
					cache.set( query, data );
					if ( input.value.trim() === query && document.activeElement === input ) {
						render( data );
					}
				} )
				.catch( () => {
					// Network error, rate limit or abort: stay quiet; the form still submits.
				} );
		}

		input.addEventListener( 'input', () => {
			window.clearTimeout( timer );
			const query = input.value.trim();
			if ( query.length < minChars ) {
				items = [];
				list.textContent = '';
				close();
				return;
			}
			timer = window.setTimeout( () => load( query ), 200 );
		} );

		input.addEventListener( 'keydown', ( event ) => {
			const count = items.length;
			switch ( event.key ) {
				case 'ArrowDown':
				case 'Down':
					if ( ! count ) {
						return;
					}
					event.preventDefault();
					if ( list.hidden ) {
						open();
						setActive( 0 );
					} else {
						setActive( active + 1 >= count ? 0 : active + 1 );
					}
					break;
				case 'ArrowUp':
				case 'Up':
					if ( ! count ) {
						return;
					}
					event.preventDefault();
					if ( list.hidden ) {
						open();
						setActive( count - 1 );
					} else {
						setActive( active <= 0 ? count - 1 : active - 1 );
					}
					break;
				case 'Enter':
					if ( ! list.hidden && active >= 0 ) {
						event.preventDefault();
						go( active );
					}
					break;
				case 'Escape':
				case 'Esc':
					if ( ! list.hidden ) {
						event.preventDefault();
						close();
					} else if ( input.value ) {
						event.preventDefault();
						input.value = '';
						items = [];
						list.textContent = '';
					}
					break;
				case 'Tab':
					close();
					break;
			}
		} );

		input.addEventListener( 'blur', () => close() );
		input.addEventListener( 'focus', () => {
			if ( items.length && input.value.trim().length >= minChars ) {
				open();
			}
		} );
	}
} )();
