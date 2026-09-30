/* "Search and social sharing" box (seo.php): live length guidance and social image picker. */
( function ( $ ) {
	'use strict';

	function countText( length, min, max ) {
		if ( 0 === length ) {
			return 'Empty: the default is used.';
		}
		if ( length > max ) {
			return length + ' characters: too long, may be cut off (maximum ' + max + ').';
		}
		if ( min && length < min ) {
			return length + ' characters: rather short (at least ' + min + ' recommended).';
		}
		return length + ' characters: good length.';
	}

	// Update the counter shortly after typing stops, so screen readers are not flooded.
	$( document ).on( 'input', '.tmc-seo [data-tmc-count]', function () {
		const field = $( this );
		const output = $( '#' + field.attr( 'id' ) + '-count' );
		clearTimeout( field.data( 'tmcTimer' ) );
		field.data(
			'tmcTimer',
			setTimeout( () => {
				const length = Array.from( field.val() ).length;
				output.text( countText( length, Number( field.data( 'tmcMin' ) ) || 0, Number( field.data( 'tmcCount' ) ) ) );
			}, 400 )
		);
	} );

	$( document ).on( 'click', '.tmc-seo-image-choose', function () {
		const box = $( this ).closest( '.tmc-seo-image' );
		const frame = wp.media( {
			title: 'Select social sharing image',
			button: { text: 'Use this image' },
			library: { type: 'image' },
			multiple: false,
		} );
		frame.on( 'select', () => {
			const attachment = frame.state().get( 'selection' ).first();
			const sizes = attachment.get( 'sizes' ) || {};
			const url = ( sizes.medium || sizes.full || {} ).url || attachment.get( 'url' );
			box.find( '#tmc-seo-image' ).val( attachment.get( 'id' ) );
			box.find( '.tmc-seo-image-preview' ).empty().append( $( '<img>' ).attr( { src: url, alt: '' } ).css( { maxWidth: '240px', height: 'auto' } ) );
			box.find( '.tmc-seo-image-remove' ).prop( 'hidden', false );
		} );
		frame.open();
	} );

	$( document ).on( 'click', '.tmc-seo-image-remove', function () {
		const box = $( this ).closest( '.tmc-seo-image' );
		box.find( '#tmc-seo-image' ).val( '' );
		box.find( '.tmc-seo-image-preview' ).empty().append( $( '<em>' ).text( 'No image selected.' ) );
		$( this ).prop( 'hidden', true );
		box.find( '.tmc-seo-image-choose' ).trigger( 'focus' );
	} );
} )( jQuery );
