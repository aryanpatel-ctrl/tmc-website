/**
 * Share links (inc/social.php): "Copy link" button. Shown only when the Clipboard API is available;
 * the plain share links work without JavaScript.
 */
( function () {
	'use strict';

	if ( ! navigator.clipboard || ! window.isSecureContext ) {
		return;
	}
	const copied = ( window.tmcSocial && window.tmcSocial.copied ) || 'Link copied';

	document.querySelectorAll( '.share-copy' ).forEach( ( button ) => {
		const label = button.textContent;
		button.hidden = false;
		button.addEventListener( 'click', () => {
			navigator.clipboard.writeText( button.getAttribute( 'data-url' ) ).then( () => {
				const status = button.closest( '.share-links' ).querySelector( '.share-status' );
				status.textContent = copied;
				button.textContent = copied;
				window.setTimeout( () => {
					button.textContent = label;
					status.textContent = '';
				}, 3000 );
			} );
		} );
	} );
} )();
