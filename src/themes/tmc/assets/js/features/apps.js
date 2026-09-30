/**
 * TMC application forms (inc/apps-blocks.php): in-page requests through the application gateway
 * (/wp-json/tmc/v1/apps/<service>/<action>). Without JavaScript the same forms post to the page and
 * are processed on the server, so this file only enhances.
 *
 *   - error summary linked to each field, inline errors (aria-describedby, aria-invalid), focus moved
 *     to the summary or the result
 *   - results announced in the section's live region (role="status")
 *   - appointment times loaded in place; donation hands over to the payment gateway
 */
( function () {
	'use strict';

	const t = window.tmcApps || {};
	const internal = /^(tmc_app|tmc_step|_tmc_token)/;

	function describedElement( wrap ) {
		return wrap.querySelector( 'fieldset' ) || wrap.querySelector( 'input, select, textarea' );
	}

	function describe( element, id, on ) {
		if ( ! element ) {
			return;
		}
		const ids = ( element.getAttribute( 'aria-describedby' ) || '' ).split( /\s+/ ).filter( ( x ) => x && x !== id );
		if ( on ) {
			ids.push( id );
		}
		if ( ids.length ) {
			element.setAttribute( 'aria-describedby', ids.join( ' ' ) );
		} else {
			element.removeAttribute( 'aria-describedby' );
		}
	}

	function message( region, text, kind ) {
		const box = document.createElement( 'div' );
		const p = document.createElement( 'p' );
		box.className = 'tmc-app-message is-' + kind;
		p.textContent = text;
		box.appendChild( p );
		region.textContent = '';
		region.appendChild( box );
	}

	function clearErrors( form ) {
		form.querySelectorAll( '.tmc-app-field.has-error' ).forEach( ( wrap ) => {
			const error = wrap.querySelector( '.tmc-app-field-error' );
			wrap.classList.remove( 'has-error' );
			if ( error ) {
				error.hidden = true;
				error.textContent = '';
				describe( describedElement( wrap ), error.id, false );
			}
			wrap.querySelectorAll( '[aria-invalid]' ).forEach( ( el ) => el.removeAttribute( 'aria-invalid' ) );
		} );
		const summary = form.querySelector( '.tmc-app-errors' );
		if ( summary ) {
			summary.hidden = true;
			summary.removeAttribute( 'autofocus' );
			summary.querySelector( 'ul' ).textContent = '';
		}
	}

	function showErrors( form, errors ) {
		const summary = form.querySelector( '.tmc-app-errors' );
		const list = summary.querySelector( 'ul' );
		Object.keys( errors ).forEach( ( name ) => {
			const item = document.createElement( 'li' );
			const link = document.createElement( 'a' );
			link.href = '#' + form.id + '-' + name;
			link.textContent = errors[ name ];
			item.appendChild( link );
			list.appendChild( item );

			const wrap = form.querySelector( '.tmc-app-field[data-field="' + CSS.escape( name ) + '"]' );
			const error = wrap && wrap.querySelector( '.tmc-app-field-error' );
			if ( ! error ) {
				return;
			}
			const hidden = document.createElement( 'span' );
			hidden.className = 'screen-reader-text';
			hidden.textContent = ( t.error || 'Error:' ) + ' ';
			error.textContent = '';
			error.append( hidden, errors[ name ] );
			error.hidden = false;
			wrap.classList.add( 'has-error' );
			describe( describedElement( wrap ), error.id, true );
			wrap.querySelectorAll( 'input:not([type="hidden"]), select, textarea' ).forEach( ( el ) => el.setAttribute( 'aria-invalid', 'true' ) );
		} );
		summary.hidden = false;
		summary.focus();
	}

	function renderSlots( form, region, slots ) {
		const box = form.querySelector( '.tmc-app-slots' );
		const group = box.closest( 'fieldset' );
		const free = slots.filter( ( slot ) => slot.available );
		box.textContent = '';
		if ( ! free.length ) {
			const p = document.createElement( 'p' );
			p.textContent = t.noTimes || '';
			box.appendChild( p );
			message( region, t.noTimes || '', 'info' );
		} else {
			free.forEach( ( slot, i ) => {
				const choice = document.createElement( 'div' );
				const input = document.createElement( 'input' );
				const label = document.createElement( 'label' );
				choice.className = 'tmc-app-choice';
				input.type = 'radio';
				input.name = 'slot';
				input.id = group.id + '-' + i;
				input.value = slot.id;
				input.required = true;
				label.htmlFor = input.id;
				label.textContent = slot.time;
				choice.append( input, label );
				box.appendChild( choice );
			} );
			message( region, ( t.times || '%d' ).replace( '%d', String( free.length ) ), 'info' );
		}
		group.focus();
	}

	function payload( form ) {
		const data = {};
		new window.FormData( form ).forEach( ( value, key ) => {
			if ( ! internal.test( key ) ) {
				data[ key ] = value;
			}
		} );
		return data;
	}

	async function send( form, action, method, data ) {
		const url = new URL( form.dataset.endpoint + action, window.location.href );
		const extra = { lang: document.documentElement.lang || '' };
		const init = { method, credentials: 'same-origin', headers: { Accept: 'application/json' } };
		if ( form.dataset.page ) {
			extra.page = form.dataset.page;
		}
		if ( 'GET' === method ) {
			Object.entries( Object.assign( {}, data, extra ) ).forEach( ( [ key, value ] ) => url.searchParams.set( key, value ) );
		} else {
			init.headers[ 'Content-Type' ] = 'application/json';
			init.headers[ 'X-TMC-Token' ] = form.elements._tmc_token ? form.elements._tmc_token.value : '';
			init.body = JSON.stringify( Object.assign( {}, data, extra ) );
		}
		const response = await window.fetch( url.toString(), init );
		return response.json();
	}

	function busy( form, on ) {
		form.setAttribute( 'aria-busy', on ? 'true' : 'false' );
		form.querySelectorAll( 'button[type="submit"]' ).forEach( ( button ) => {
			button.disabled = on;
		} );
	}

	function handle( form, region, step, json ) {
		const errors = json.errors || {};
		if ( ! json.ok ) {
			if ( Object.keys( errors ).length ) {
				region.textContent = '';
				showErrors( form, errors );
			} else {
				message( region, json.message || t.network || '', 'error' );
				region.focus();
			}
			return;
		}
		if ( 'slots' === step ) {
			renderSlots( form, region, ( json.data && json.data.slots ) || [] );
			return;
		}
		if ( json.data && typeof json.data.redirect_url === 'string' && /^https?:\/\//.test( json.data.redirect_url ) ) {
			message( region, t.redirect || '', 'info' );
			window.location.assign( json.data.redirect_url );
			return;
		}
		if ( json.html ) {
			region.innerHTML = json.html; // rendered and escaped by the server (tmc_app_result_html)
		} else {
			message( region, json.message || '', 'success' );
		}
		if ( 'results' !== form.dataset.tmcApp ) {
			form.reset();
		}
		region.focus();
	}

	document.querySelectorAll( 'form[data-tmc-app]' ).forEach( ( form ) => {
		const region = form.closest( '.tmc-app' ).querySelector( '.tmc-app-result' );
		form.addEventListener( 'submit', async ( event ) => {
			event.preventDefault();
			if ( 'true' === form.getAttribute( 'aria-busy' ) ) {
				return;
			}
			const step = event.submitter && 'slots' === event.submitter.value ? 'slots' : 'submit';
			let data = payload( form );
			let action = form.dataset.action;
			let method = 'POST';
			if ( 'slots' === step ) {
				action = form.dataset.slotsAction;
				method = 'GET';
				data = { department: data.department || '', date: data.date || '' };
			}
			clearErrors( form );
			message( region, t.sending || '', 'info' );
			busy( form, true );
			let json;
			try {
				json = await send( form, action, method, data );
			} catch ( e ) {
				json = { ok: false, message: t.network, errors: {} };
			}
			busy( form, false );
			handle( form, region, step, json );
		} );
	} );

	// Error summary links: move focus to the field and bring its label into view.
	document.addEventListener( 'click', ( event ) => {
		const link = event.target.closest( '.tmc-app-errors a' );
		const target = link && document.getElementById( link.getAttribute( 'href' ).slice( 1 ) );
		if ( ! target ) {
			return;
		}
		event.preventDefault();
		( target.closest( '.tmc-app-field' ) || target ).scrollIntoView( { block: 'start' } );
		target.focus( { preventScroll: true } );
	} );
} )();
