/* Editor registration for the tmc/documents block (rendered by PHP — see inc/documents.php). */
( function ( wp ) {
	const { registerBlockType } = wp.blocks;
	const { createElement: el, Fragment } = wp.element;
	const { useBlockProps, InspectorControls } = wp.blockEditor;
	const { PanelBody, RangeControl, SelectControl, ToggleControl } = wp.components;
	const ServerSideRender = wp.serverSideRender;
	const types = window.tmcDocumentTypes || [ { value: '', label: 'All types' } ];

	registerBlockType( 'tmc/documents', {
		apiVersion: 3,
		title: 'Documents',
		icon: 'media-document',
		category: 'widgets',
		description:
			'Documents from the media library with file format and size. Library mode adds type and year filters and pagination.',
		attributes: {
			type: { type: 'string', default: '' },
			count: { type: 'number', default: 10 },
			library: { type: 'boolean', default: false },
		},
		edit( props ) {
			const { attributes, setAttributes } = props;
			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: 'Settings' },
						el( ToggleControl, {
							label: 'Library mode (filters and pagination)',
							help: 'Visitors can filter by document type and year.',
							checked: attributes.library,
							onChange: ( library ) => setAttributes( { library } ),
						} ),
						attributes.library
							? null
							: el( SelectControl, {
									label: 'Document type',
									value: attributes.type,
									options: types,
									onChange: ( type ) => setAttributes( { type } ),
							  } ),
						el( RangeControl, {
							label: attributes.library ? 'Documents per page' : 'Number of documents',
							min: 1,
							max: 50,
							value: attributes.count,
							onChange: ( count ) => setAttributes( { count } ),
						} )
					)
				),
				el(
					'div',
					useBlockProps(),
					el( ServerSideRender, { block: 'tmc/documents', attributes } )
				)
			);
		},
		save: () => null,
	} );
} )( window.wp );
