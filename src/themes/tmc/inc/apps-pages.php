<?php
/**
 * Put the application front ends and the location map on each site's seeded pages (English and
 * Hindi) and set the site's map position. Used by scripts/migrations/040-app-gateway.php (existing
 * sites) and scripts/seed-site-structure.php (fresh installs, where the pages are created after the
 * migrations have run).
 *
 * Each page is handled once (site option tmc_w4_placed), so editors can later move or remove a block
 * without a deploy putting it back.
 */

defined( 'ABSPATH' ) || exit;

/**
 * City-level coordinates per site (sub-domain => [ lat, lon ]). Deliberately approximate: the map
 * shows "approximate location (city level)" until TMC confirms exact positions in the Customizer.
 */
function tmc_w4_city_coordinates() {
	return array(
		''           => array( '19.0760', '72.8777' ), // Mumbai
		'tmh'        => array( '19.0760', '72.8777' ), // Mumbai
		'hbchrcv'    => array( '17.6868', '83.2185' ), // Visakhapatnam
		'mpmmcc'     => array( '25.3176', '82.9739' ), // Varanasi
		'hbchrcmzp'  => array( '26.1209', '85.3647' ), // Muzaffarpur
		'hbchpunjab' => array( '30.7333', '76.7794' ), // Chandigarh tricity (New Chandigarh lies within it)
	);
}

/** English page path => block markup. */
function tmc_w4_page_blocks() {
	return array(
		'patient-care/appointments' => '<!-- wp:tmc/app-appointment /-->',
		'education/results'         => '<!-- wp:tmc/app-results /-->',
		'feedback'                  => '<!-- wp:tmc/app-form {"form":"feedback"} /-->',
		'donate'                    => '<!-- wp:tmc/app-donate /-->',
		'contact-us'                => '<!-- wp:tmc/location-map /-->',
	);
}

/**
 * @return string[] What was done (for the log).
 */
function tmc_w4_seed_site() {
	$done = array();
	$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	$unit = DOMAIN_CURRENT_SITE === $host ? '' : (string) strstr( $host, '.', true );

	$coordinates = tmc_w4_city_coordinates()[ $unit ] ?? null;
	if ( $coordinates && '' === (string) get_theme_mod( 'tmc_map_lat', '' ) ) {
		set_theme_mod( 'tmc_map_lat', $coordinates[0] );
		set_theme_mod( 'tmc_map_lon', $coordinates[1] );
		set_theme_mod( 'tmc_map_zoom', '12' );
		set_theme_mod( 'tmc_map_approximate', true );
		$done[] = 'map position set (city level, approximate)';
	}

	$placed = (array) get_option( 'tmc_w4_placed', array() );
	foreach ( tmc_w4_page_blocks() as $path => $block ) {
		$en = get_page_by_path( $path );
		if ( in_array( $path, $placed, true ) || ! $en ) {
			continue; // already handled, or the page does not exist yet (fresh install: the seed calls again)
		}
		preg_match( '/<!-- wp:([a-z0-9-]+\/[a-z0-9-]+)/', $block, $m );
		$ids = array( (int) $en->ID );
		if ( function_exists( 'pll_get_post' ) && pll_get_post( $en->ID, 'hi' ) ) {
			$ids[] = (int) pll_get_post( $en->ID, 'hi' );
		}
		foreach ( $ids as $id ) {
			$content = (string) get_post_field( 'post_content', $id );
			if ( has_block( $m[1], $content ) ) {
				continue;
			}
			// Before the "content will be provided by TMC" callout when there is one, else at the end.
			$callout = strpos( $content, '<!-- wp:paragraph {"className":"callout"} -->' );
			$content = false === $callout ? $content . $block : substr_replace( $content, $block, $callout, 0 );
			wp_update_post( array( 'ID' => $id, 'post_content' => wp_slash( $content ) ) );
		}
		$placed[] = $path;
		$done[]   = "$m[1] placed on /$path/" . ( count( $ids ) > 1 ? ' (EN + HI)' : '' );
	}
	update_option( 'tmc_w4_placed', $placed, false );
	return $done;
}
