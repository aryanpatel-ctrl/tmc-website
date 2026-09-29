<?php
/**
 * Content filters.
 *
 * External links in editor content open in a new tab and are marked as external (GIGW), so
 * editors never have to remember to do it. Links within the TMC network are left alone.
 */

defined( 'ABSPATH' ) || exit;

function tmc_is_internal_host( $host ) {
	$host = strtolower( (string) $host );
	$base = strtolower( DOMAIN_CURRENT_SITE );
	return '' === $host || $host === $base || str_ends_with( $host, '.' . $base );
}

add_filter( 'the_content', 'tmc_mark_external_links', 20 );
function tmc_mark_external_links( $html ) {
	if ( false === stripos( $html, '<a ' ) ) {
		return $html;
	}
	$tags = new WP_HTML_Tag_Processor( $html );
	while ( $tags->next_tag( 'a' ) ) {
		$href = (string) $tags->get_attribute( 'href' );
		if ( ! preg_match( '#^https?://#i', $href ) || tmc_is_internal_host( wp_parse_url( $href, PHP_URL_HOST ) ) ) {
			continue;
		}
		$tags->set_attribute( 'target', '_blank' );
		$tags->set_attribute( 'rel', 'noopener noreferrer' );
		$tags->add_class( 'is-external' );
	}
	return $tags->get_updated_html();
}
