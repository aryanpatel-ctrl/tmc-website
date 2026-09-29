/**
 * TMC theme behaviour. Everything works without JavaScript; this only enhances.
 *   - text size and contrast (remembered per visitor in localStorage)
 *   - main menu: mobile toggle + disclosure submenus (Escape closes, focus returns)
 *   - notice board scroll with pause/play (off for prefers-reduced-motion)
 */
( function () {
	'use strict';

	const root = document.documentElement;
	const i18n = window.tmcI18n || { pause: 'Pause', play: 'Play' };
	const store = {
		get( key ) {
			try {
				return window.localStorage.getItem( key );
			} catch ( e ) {
				return null;
			}
		},
		set( key, value ) {
			try {
				window.localStorage.setItem( key, value );
			} catch ( e ) {}
		},
	};

	/* ------------------------------------------------ text size + contrast */

	function pressOnly( selector, attr, value ) {
		document.querySelectorAll( selector ).forEach( ( button ) => {
			button.setAttribute( 'aria-pressed', String( button.getAttribute( attr ) === value ) );
		} );
	}

	function setFontScale( value ) {
		root.style.fontSize = value === '100' ? '' : value + '%';
		store.set( 'tmc-font-scale', value );
		pressOnly( '[data-tmc-font]', 'data-tmc-font', value );
	}

	function setContrast( value ) {
		root.setAttribute( 'data-contrast', value );
		store.set( 'tmc-contrast', value );
		pressOnly( '[data-tmc-contrast]', 'data-tmc-contrast', value );
	}

	document.addEventListener( 'click', ( event ) => {
		const font = event.target.closest( '[data-tmc-font]' );
		if ( font ) {
			setFontScale( font.getAttribute( 'data-tmc-font' ) );
		}
		const contrast = event.target.closest( '[data-tmc-contrast]' );
		if ( contrast ) {
			setContrast( contrast.getAttribute( 'data-tmc-contrast' ) );
		}
	} );

	pressOnly( '[data-tmc-font]', 'data-tmc-font', store.get( 'tmc-font-scale' ) || '100' );
	pressOnly( '[data-tmc-contrast]', 'data-tmc-contrast', root.getAttribute( 'data-contrast' ) || 'normal' );

	/* ------------------------------------------------ main menu */

	const nav = document.getElementById( 'primary-nav' );
	const navToggle = document.querySelector( '.nav-toggle' );

	if ( nav && navToggle ) {
		navToggle.addEventListener( 'click', () => {
			const open = navToggle.getAttribute( 'aria-expanded' ) !== 'true';
			navToggle.setAttribute( 'aria-expanded', String( open ) );
			nav.classList.toggle( 'is-open', open );
		} );
	}

	function closePanel( toggle, returnFocus ) {
		if ( ! toggle || toggle.getAttribute( 'aria-expanded' ) !== 'true' ) {
			return;
		}
		toggle.setAttribute( 'aria-expanded', 'false' );
		toggle.parentElement.classList.remove( 'is-open' );
		if ( returnFocus ) {
			toggle.focus();
		}
	}

	function closeAll( except ) {
		document.querySelectorAll( '.sub-toggle[aria-expanded="true"]' ).forEach( ( toggle ) => {
			if ( toggle !== except ) {
				closePanel( toggle, false );
			}
		} );
	}

	document.querySelectorAll( '.sub-toggle' ).forEach( ( toggle ) => {
		toggle.addEventListener( 'click', () => {
			const open = toggle.getAttribute( 'aria-expanded' ) !== 'true';
			closeAll( toggle );
			toggle.setAttribute( 'aria-expanded', String( open ) );
			toggle.parentElement.classList.toggle( 'is-open', open );
		} );
	} );

	document.addEventListener( 'keydown', ( event ) => {
		if ( event.key !== 'Escape' ) {
			return;
		}
		const openToggle = document.querySelector( '.sub-toggle[aria-expanded="true"]' );
		if ( openToggle ) {
			closePanel( openToggle, true );
		} else if ( nav && nav.classList.contains( 'is-open' ) ) {
			navToggle.click();
			navToggle.focus();
		}
	} );

	// Close a panel when focus or a click moves outside its menu item (desktop).
	document.addEventListener( 'click', ( event ) => {
		if ( ! event.target.closest( '.nav-item.has-sub' ) ) {
			closeAll( null );
		}
	} );
	document.addEventListener( 'focusin', ( event ) => {
		const item = event.target.closest( '.nav-item.has-sub' );
		document.querySelectorAll( '.nav-item.is-open' ).forEach( ( open ) => {
			if ( open !== item ) {
				closePanel( open.querySelector( '.sub-toggle' ), false );
			}
		} );
	} );

	/* ------------------------------------------------ external links in content */

	// The server marks them (inc/content.php); add the spoken hint for screen-reader users.
	document.querySelectorAll( '.entry-content a.is-external, .home-sections a.is-external' ).forEach( ( link ) => {
		if ( ! link.querySelector( '.screen-reader-text' ) ) {
			const hint = document.createElement( 'span' );
			hint.className = 'screen-reader-text';
			hint.textContent = ' ' + ( i18n.external || '(opens in a new tab)' );
			link.appendChild( hint );
		}
	} );

	/* ------------------------------------------------ notice board */

	const reduceMotion = window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	document.querySelectorAll( '.notice-board[data-scroll]' ).forEach( ( board ) => {
		const toggle = board.querySelector( '.notice-toggle' );
		const viewport = board.querySelector( '.notice-viewport' );
		const list = board.querySelector( '.notice-list' );
		if ( ! toggle || ! viewport || ! list || list.children.length < 4 ) {
			if ( toggle ) {
				toggle.hidden = true;
			}
			return;
		}

		// Duplicate items for a seamless loop; the copy is hidden from assistive tech.
		const copy = list.cloneNode( true );
		copy.setAttribute( 'aria-hidden', 'true' );
		copy.querySelectorAll( 'a' ).forEach( ( a ) => a.setAttribute( 'tabindex', '-1' ) );
		viewport.appendChild( copy );
		board.classList.add( 'is-scrolling' );

		let paused = reduceMotion;
		const setPaused = ( value ) => {
			paused = value;
			board.classList.toggle( 'is-paused', paused );
			toggle.setAttribute( 'aria-pressed', String( paused ) );
			toggle.textContent = paused ? i18n.play : i18n.pause;
		};
		setPaused( paused );

		toggle.addEventListener( 'click', () => setPaused( ! paused ) );
		// Pause while the user is reading or keyboard-navigating the list.
		board.addEventListener( 'mouseenter', () => board.classList.add( 'is-hover' ) );
		board.addEventListener( 'mouseleave', () => board.classList.remove( 'is-hover' ) );
		board.addEventListener( 'focusin', () => board.classList.add( 'is-hover' ) );
		board.addEventListener( 'focusout', () => board.classList.remove( 'is-hover' ) );
	} );
} )();
