<?php
/**
 * Location map block tmc/location-map (R-4.3-6, R-4.12-4).
 *
 *   - The address (per-site setting) is always shown as text, with a "Get directions" link.
 *   - The OpenStreetMap map is NOT loaded with the page: no request goes to a third party until the
 *     visitor presses "Show map" (privacy; no API key; no external script — only an iframe on request).
 *     Without JavaScript, "View on OpenStreetMap" opens the map on openstreetmap.org instead.
 *   - Coordinates are per site (Appearance → Customize → TMC contact details) and can be overridden per
 *     block. The seeded values are city-level and are labelled as approximate until TMC confirms them.
 *
 * Front-end behaviour: assets/js/features/map.js. Editor: assets/js/apps-editor.js.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', 'tmc_register_map_block' );
function tmc_register_map_block() {
	register_block_type(
		'tmc/location-map',
		array(
			'render_callback' => 'tmc_render_location_map',
			'attributes'      => array(
				'heading' => array( 'type' => 'string', 'default' => '' ),
				'lat'     => array( 'type' => 'string', 'default' => '' ),
				'lon'     => array( 'type' => 'string', 'default' => '' ),
				'zoom'    => array( 'type' => 'number', 'default' => 0 ),
			),
		)
	);
}

/** A coordinate within range, as a string with at most 6 decimals, or ''. */
function tmc_map_coordinate( $value, $limit ) {
	$value = trim( (string) $value );
	if ( ! preg_match( '/^-?\d{1,3}(\.\d{1,10})?$/', $value ) || abs( (float) $value ) > $limit ) {
		return '';
	}
	return rtrim( rtrim( number_format( (float) $value, 6, '.', '' ), '0' ), '.' );
}

function tmc_map_sanitize_lat( $value ) {
	return tmc_map_coordinate( $value, 90 );
}

function tmc_map_sanitize_lon( $value ) {
	return tmc_map_coordinate( $value, 180 );
}

function tmc_map_sanitize_zoom( $value ) {
	return (string) max( 3, min( 18, (int) $value ) );
}

// Per-site map settings, next to the contact details (section created in inc/setup.php).
add_action( 'customize_register', 'tmc_map_customize_register', 20 );
function tmc_map_customize_register( WP_Customize_Manager $wp_customize ) {
	$fields = array(
		'tmc_map_lat'         => array( __( 'Map latitude (e.g. 19.0760)', 'tmc' ), 'text', 'tmc_map_sanitize_lat' ),
		'tmc_map_lon'         => array( __( 'Map longitude (e.g. 72.8777)', 'tmc' ), 'text', 'tmc_map_sanitize_lon' ),
		'tmc_map_zoom'        => array( __( 'Map zoom (3–18)', 'tmc' ), 'number', 'tmc_map_sanitize_zoom' ),
		'tmc_map_approximate' => array( __( 'Map position is approximate (show a note)', 'tmc' ), 'checkbox', 'rest_sanitize_boolean' ),
	);
	foreach ( $fields as $id => list( $label, $type, $sanitize ) ) {
		$wp_customize->add_setting( $id, array( 'sanitize_callback' => $sanitize ) );
		$wp_customize->add_control( $id, array( 'label' => $label, 'section' => 'tmc_contact', 'type' => $type ) );
	}
}

/** [ lat, lon, zoom, approximate ] from the block, falling back to the site setting; lat/lon '' when unknown. */
function tmc_map_location( array $attributes ) {
	$lat = tmc_map_sanitize_lat( $attributes['lat'] ?? '' );
	$lon = tmc_map_sanitize_lon( $attributes['lon'] ?? '' );
	$own = '' !== $lat && '' !== $lon;
	if ( ! $own ) {
		$lat = tmc_map_sanitize_lat( get_theme_mod( 'tmc_map_lat', '' ) );
		$lon = tmc_map_sanitize_lon( get_theme_mod( 'tmc_map_lon', '' ) );
	}
	$zoom = (int) ( $attributes['zoom'] ?? 0 ) ? (int) $attributes['zoom'] : (int) get_theme_mod( 'tmc_map_zoom', 12 );
	return array( $lat, $lon, max( 3, min( 18, $zoom ) ), ! $own && (bool) get_theme_mod( 'tmc_map_approximate', true ) );
}

function tmc_render_location_map( $attributes ) {
	static $count = 0;
	$id                                   = 'tmc-map-' . ( ++$count );
	$heading                              = '' !== (string) ( $attributes['heading'] ?? '' ) ? (string) $attributes['heading'] : __( 'How to reach us', 'tmc' );
	list( $lat, $lon, $zoom, $approximate ) = tmc_map_location( $attributes );
	$address                              = tmc_translated_mod( 'tmc_address' );
	$name                                 = tmc_site_name();
	$has_point                            = '' !== $lat && '' !== $lon;

	if ( $has_point ) {
		$directions = 'https://www.openstreetmap.org/directions?to=' . rawurlencode( $lat . ',' . $lon );
		$view       = sprintf( 'https://www.openstreetmap.org/?mlat=%1$s&mlon=%2$s#map=%3$d/%1$s/%2$s', $lat, $lon, $zoom );
		$span_lon   = 720 / pow( 2, $zoom );
		$span_lat   = $span_lon * 0.6;
		$embed      = add_query_arg(
			array(
				'bbox'   => implode( ',', array_map( fn( $n ) => round( $n, 5 ), array( $lon - $span_lon / 2, $lat - $span_lat / 2, $lon + $span_lon / 2, $lat + $span_lat / 2 ) ) ),
				'layer'  => 'mapnik',
				'marker' => $lat . ',' . $lon,
			),
			'https://www.openstreetmap.org/export/embed.html'
		);
	} elseif ( $address ) {
		$directions = 'https://www.openstreetmap.org/search?query=' . rawurlencode( str_replace( "\n", ', ', $address ) );
	} else {
		return '';
	}

	$html  = sprintf( '<section class="tmc-map" aria-labelledby="%1$s-title"><h2 class="tmc-map-title" id="%1$s-title">%2$s</h2><div class="tmc-map-grid"><div class="tmc-map-text">', esc_attr( $id ), esc_html( $heading ) );
	$html .= '<p class="tmc-map-name"><strong>' . esc_html( $name ) . '</strong></p>';
	if ( $address ) {
		$html .= '<address class="tmc-map-address">' . nl2br( esc_html( $address ) ) . '</address>';
	}
	$html .= '<p class="tmc-map-actions">' . tmc_external_link( $directions, __( 'Get directions', 'tmc' ), 'button' ) . '</p>';
	if ( $has_point && $approximate ) {
		$html .= '<p class="tmc-map-note">' . esc_html__( 'The map marker shows the approximate location (city level).', 'tmc' ) . '</p>';
	}
	$html .= '</div>';

	if ( $has_point ) {
		/* translators: %s: organisation name */
		$frame_title = sprintf( __( 'Map showing the location of %s (OpenStreetMap)', 'tmc' ), $name );
		$html       .= sprintf(
			'<div class="tmc-map-frame" id="%1$s-frame"><div class="tmc-map-consent"><p>%2$s</p><p class="tmc-map-actions"><button type="button" class="button is-outline tmc-map-load" data-embed="%3$s" data-title="%4$s" aria-controls="%1$s-frame" hidden>%5$s</button> %6$s</p><p class="tmc-map-credit">%7$s</p></div><p class="screen-reader-text tmc-map-status" role="status"></p></div>',
			esc_attr( $id ),
			esc_html__( 'The map is provided by OpenStreetMap. It is loaded only if you choose to show it, because loading it sends your IP address to OpenStreetMap.', 'tmc' ),
			esc_url( $embed ),
			esc_attr( $frame_title ),
			esc_html__( 'Show map', 'tmc' ),
			tmc_external_link( $view, __( 'View on OpenStreetMap', 'tmc' ) ),
			esc_html__( 'Map data © OpenStreetMap contributors.', 'tmc' )
		);
	}
	return $html . '</div></section>';
}

add_action( 'wp_enqueue_scripts', 'tmc_map_script_strings', 20 );
function tmc_map_script_strings() {
	wp_localize_script( 'tmc-map', 'tmcMap', array( 'loaded' => __( 'Map loaded.', 'tmc' ) ) );
}

/**
 * Content-Security-Policy (tmc-core/security-headers.php): allow exactly the OpenStreetMap embed
 * origin as a frame source, so the map the visitor asks for can load. Nothing else is loosened.
 */
add_filter( 'tmc_csp_directives', 'tmc_map_csp_frame_src', 10, 2 );
function tmc_map_csp_frame_src( $directives, $context ) {
	if ( 'front' === $context ) {
		$directives['frame-src'] = array_merge( (array) ( $directives['frame-src'] ?? array( "'self'" ) ), array( 'https://www.openstreetmap.org' ) );
	}
	return $directives;
}

/**
 * Page templates (inc/page-templates.php): the Contact page template's "map" slot gets the location
 * map block, which shows the site's address and map position from the Customizer.
 */
add_filter( 'tmc_page_template_slot', 'tmc_map_template_slot', 10, 2 );
function tmc_map_template_slot( $blocks, $slot ) {
	if ( 'map' === $slot && function_exists( 'tmc_b_dynamic' ) ) {
		return array( tmc_b_dynamic( 'tmc/location-map' ) );
	}
	return $blocks;
}
