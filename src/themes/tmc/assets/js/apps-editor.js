/* Editor registration for the TMC application front ends and the location map (rendered by PHP —
   see inc/apps-blocks.php and inc/location-map.php). Plain JS, no build step. */
( function ( wp ) {
	const { registerBlockType } = wp.blocks;
	const { createElement: el, Fragment } = wp.element;
	const { useBlockProps, InspectorControls } = wp.blockEditor;
	const { PanelBody, TextControl } = wp.components;
	const ServerSideRender = wp.serverSideRender;

	const text = ( props, key, label, help ) =>
		el( TextControl, {
			key,
			label,
			help,
			value: props.attributes[ key ],
			onChange: ( value ) => props.setAttributes( { [ key ]: value } ),
		} );

	const block = ( name, title, icon, description, attributes, controls ) =>
		registerBlockType( name, {
			apiVersion: 3,
			title,
			icon,
			description,
			category: 'widgets',
			attributes,
			edit( props ) {
				return el(
					Fragment,
					null,
					el( InspectorControls, null, el( PanelBody, { title: 'Settings' }, controls( props ) ) ),
					el( 'div', useBlockProps(), el( ServerSideRender, { block: name, attributes: props.attributes } ) )
				);
			},
			save: () => null,
		} );

	const serviceHelp = 'Name of the service in Network Admin → Settings → TMC applications. Leave empty to use the first service of this type.';
	const service = { service: { type: 'string', default: '' } };
	const serviceControl = ( props ) => text( props, 'service', 'Service', serviceHelp );

	block( 'tmc/app-appointment', 'Appointment request', 'calendar-alt', 'OPD appointment request form (TMC appointment system, via the application gateway).', service, serviceControl );
	block( 'tmc/app-results', 'Results lookup', 'awards', 'Examination / selection result lookup by roll number and date of birth.', service, serviceControl );
	block( 'tmc/app-donate', 'Donate', 'heart', 'Donation form that hands over to the approved payment gateway and confirms the payment on return.', service, serviceControl );
	block(
		'tmc/app-form',
		'Online form',
		'feedback',
		'A form whose fields are provided by a TMC forms backend.',
		Object.assign( {}, service, { form: { type: 'string', default: 'feedback' }, heading: { type: 'string', default: '' } } ),
		( props ) => [
			serviceControl( props ),
			text( props, 'form', 'Form ID', 'Identifier of the form in the TMC forms backend.' ),
			text( props, 'heading', 'Heading', 'Leave empty to use the title provided by the backend.' ),
		]
	);
	block(
		'tmc/location-map',
		'Location map',
		'location',
		'Address, directions link and an OpenStreetMap map that loads only when the visitor asks for it.',
		{
			heading: { type: 'string', default: '' },
			lat: { type: 'string', default: '' },
			lon: { type: 'string', default: '' },
			zoom: { type: 'number', default: 0 },
		},
		( props ) => [
			text( props, 'heading', 'Heading', 'Default: "How to reach us".' ),
			text( props, 'lat', 'Latitude', 'Leave empty to use the site setting (Customizer → TMC contact details).' ),
			text( props, 'lon', 'Longitude', 'Leave empty to use the site setting.' ),
		]
	);
} )( window.wp );
