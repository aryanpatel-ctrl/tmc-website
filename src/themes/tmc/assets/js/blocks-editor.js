/* Editor registration for the TMC dynamic blocks (rendered by PHP — see inc/blocks.php). */
( function ( wp ) {
	const { registerBlockType } = wp.blocks;
	const { createElement: el, Fragment } = wp.element;
	const { useBlockProps, InspectorControls } = wp.blockEditor;
	const { PanelBody, RangeControl, TextControl } = wp.components;
	const ServerSideRender = wp.serverSideRender;

	const preview = ( name ) => ( props ) =>
		el( 'div', useBlockProps(), el( ServerSideRender, { block: name, attributes: props.attributes } ) );

	const listBlock = ( name, title, icon, description, defaults ) =>
		registerBlockType( name, {
			apiVersion: 3,
			title,
			icon,
			description,
			category: 'widgets',
			attributes: {
				category: { type: 'string', default: defaults.category },
				count: { type: 'number', default: defaults.count },
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
							el( TextControl, {
								label: 'Category slug',
								value: attributes.category,
								onChange: ( category ) => setAttributes( { category } ),
							} ),
							el( RangeControl, {
								label: 'Number of items',
								min: 1,
								max: 20,
								value: attributes.count,
								onChange: ( count ) => setAttributes( { count } ),
							} )
						)
					),
					preview( name )( props )
				);
			},
			save: () => null,
		} );

	registerBlockType( 'tmc/network', {
		apiVersion: 3,
		title: 'TMC Network',
		icon: 'networking',
		description: 'Cards linking to every website in the TMC ecosystem (updates automatically).',
		category: 'widgets',
		edit: preview( 'tmc/network' ),
		save: () => null,
	} );

	registerBlockType( 'tmc/sitemap', {
		apiVersion: 3,
		title: 'Sitemap',
		icon: 'list-view',
		description: 'All pages of this website in the current language (updates automatically).',
		category: 'widgets',
		edit: preview( 'tmc/sitemap' ),
		save: () => null,
	} );

	listBlock( 'tmc/notice-board', 'Notice Board', 'megaphone', "What's new list with pause/play.", { category: 'notices', count: 6 } );
	listBlock( 'tmc/latest-news', 'Latest News', 'excerpt-view', 'Latest posts from a category as cards.', { category: 'news', count: 3 } );
} )( window.wp );
