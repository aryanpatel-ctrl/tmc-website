<?php
/**
 * Presentation of centrally published items on unit websites (data: tmc-core/network-publishing.php):
 * a source line under the content that names and links the original on the TMC website.
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'the_content', 'tmc_syndicated_source_note', 30 );
function tmc_syndicated_source_note( $content ) {
	if ( ! function_exists( 'tmc_syndication_origin' ) || ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}
	$origin = tmc_syndication_origin( get_the_ID() );
	if ( ! $origin ) {
		return $content;
	}
	$name   = tmc_site_name( $origin['blog'] );
	$source = $origin['url'] ? sprintf( '<a href="%s">%s</a>', esc_url( $origin['url'] ), esc_html( $name ) ) : esc_html( $name );
	/* translators: %s: name of the website that published the original, linked to it */
	return $content . '<p class="syndicated-note">' . sprintf( esc_html__( 'Published by %s.', 'tmc' ), $source ) . '</p>';
}
