/* Documents field: pick / remove files from the media library (content-fields.php). */
( function ( $ ) {
	'use strict';

	function sync( list ) {
		const ids = list.find( 'li' ).map( ( i, li ) => $( li ).data( 'id' ) ).get();
		$( '#' + list.data( 'for' ) ).val( ids.join( ',' ) );
	}

	$( document ).on( 'click', '.tmc-doc-remove', function () {
		const list = $( this ).closest( '.tmc-documents' );
		$( this ).closest( 'li' ).remove();
		sync( list );
	} );

	$( document ).on( 'click', '.tmc-doc-add', function () {
		const target = $( this ).data( 'target' );
		const list = $( '.tmc-documents[data-for="' + target + '"]' );
		const frame = wp.media( {
			title: 'Select documents',
			button: { text: 'Add documents' },
			multiple: true,
		} );
		frame.on( 'select', () => {
			frame.state().get( 'selection' ).each( ( attachment ) => {
				const id = attachment.get( 'id' );
				if ( list.find( 'li[data-id="' + id + '"]' ).length ) {
					return;
				}
				const title = attachment.get( 'title' ) || attachment.get( 'filename' );
				const item = $( '<li>' ).attr( 'data-id', id ).text( title + ' ' );
				const remove = $( '<button type="button" class="button-link tmc-doc-remove">' ).text( 'Remove' );
				item.append( remove );
				list.append( item );
			} );
			sync( list );
		} );
		frame.open();
	} );
} )( jQuery );
