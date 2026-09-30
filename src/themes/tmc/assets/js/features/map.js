/**
 * Location map (inc/location-map.php): load the OpenStreetMap map only when the visitor asks for it.
 * Nothing is requested from openstreetmap.org before the button is pressed. Without JavaScript the
 * button stays hidden and "View on OpenStreetMap" opens the map on openstreetmap.org.
 */
( function () {
	'use strict';

	const loaded = ( window.tmcMap && window.tmcMap.loaded ) || 'Map loaded.';

	document.querySelectorAll( '.tmc-map-load' ).forEach( ( button ) => {
		button.hidden = false;
		button.addEventListener( 'click', () => {
			const frame = button.closest( '.tmc-map-frame' );
			const status = frame.querySelector( '.tmc-map-status' );
			const iframe = document.createElement( 'iframe' );
			iframe.src = button.getAttribute( 'data-embed' );
			iframe.title = button.getAttribute( 'data-title' );
			iframe.className = 'tmc-map-iframe';
			iframe.loading = 'lazy';
			iframe.referrerPolicy = 'strict-origin-when-cross-origin';
			frame.querySelector( '.tmc-map-consent' ).replaceWith( iframe );
			frame.classList.add( 'is-loaded' );
			status.textContent = loaded;
			iframe.focus();
		} );
	} );
} )();
