<?php
/**
 * 061 — Descriptive link text on the unit home pages (defect found by the W6 Lighthouse gate:
 * "Links do not have descriptive text"; GIGW asks for link text that makes sense on its own).
 *
 * The "About us" section of the English home page of each unit site ended with a "Read more"
 * button. It now says "More about us". seed-site-structure.php builds new home pages with the new
 * text; this migration updates home pages that already exist. Only the button that links to the
 * About Us page is changed, and only while it still has the seeded text.
 */

$home_id = (int) get_option( 'page_on_front' );
if ( ! $home_id ) {
	return true; // fresh install: seed-site-structure.php builds the home page with the new text
}
$ids = array( $home_id );
if ( function_exists( 'pll_get_post' ) ) {
	$english = pll_get_post( $home_id, 'en' );
	$ids     = $english ? array( (int) $english ) : $ids;
}

foreach ( $ids as $id ) {
	$content = (string) get_post_field( 'post_content', $id );
	$about   = wp_make_link_relative( home_url( '/about-us/' ) );
	$pattern = '#(<a\b[^>]*\bhref="[^"]*' . preg_quote( $about, '#' ) . '"[^>]*>)Read more(</a>)#';
	$updated = preg_replace( $pattern, '$1More about us$2', $content, -1, $count );
	if ( null === $updated ) {
		WP_CLI::warning( 'could not update the home page link text' );
		return false;
	}
	if ( $count ) {
		wp_update_post( array( 'ID' => $id, 'post_content' => wp_slash( $updated ) ) );
		WP_CLI::log( "    home page: \"Read more\" → \"More about us\" ($count)" );
	}
}

return true;
