<?php
/**
 * Fallback meta description (defect found by the W6 Lighthouse gate: "Document does not have a
 * meta description" on every page).
 *
 * Editor-managed metadata is the SEO module's job (R-4.10-2). This fallback only fills the gap:
 * wp_head output is buffered, and a description is added only when nothing else printed one, so
 * it steps aside automatically as soon as an SEO module outputs its own.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_head', 'tmc_meta_description_start', -1000 );
function tmc_meta_description_start() {
	ob_start();
}

add_action( 'wp_head', 'tmc_meta_description_end', PHP_INT_MAX );
function tmc_meta_description_end() {
	$head = ob_get_clean();
	if ( false === $head ) {
		return;
	}
	echo $head; // phpcs:ignore WordPress.Security.EscapeOutput -- output of other wp_head callbacks, unchanged
	if ( preg_match( '/<meta\s[^>]*name\s*=\s*["\']?description["\'\s>]/i', $head ) ) {
		return;
	}
	$description = tmc_fallback_meta_description();
	if ( '' !== $description ) {
		printf( '<meta name="description" content="%s">' . "\n", esc_attr( $description ) );
	}
}

/** Plain-text summary of the current view, at most about 160 characters. */
function tmc_fallback_meta_description() {
	$site = tmc_site_name();
	/* translators: 1: page or listing title, 2: organisation name */
	$pair = __( '%1$s — %2$s', 'tmc' );
	$text = '';

	if ( is_front_page() ) {
		$text = sprintf( $pair, $site, tmc_site_tagline() );
	} elseif ( is_singular() ) {
		$post = get_queried_object();
		if ( $post instanceof WP_Post && '' !== $post->post_password ) {
			$text = sprintf( $pair, single_post_title( '', false ), $site ); // never summarise protected content
		} elseif ( $post instanceof WP_Post ) {
			$text = has_excerpt( $post ) ? $post->post_excerpt : $post->post_content;
			$text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( strip_shortcodes( $text ) ) ) );
			if ( '' === $text ) {
				$text = sprintf( $pair, single_post_title( '', false ), $site );
			}
		}
	} elseif ( is_search() ) {
		/* translators: 1: search terms, 2: organisation name */
		$text = sprintf( __( 'Search results for “%1$s” on the website of %2$s.', 'tmc' ), get_search_query( false ), $site );
	} elseif ( is_404() ) {
		/* translators: %s: organisation name */
		$text = sprintf( __( 'Page not found on the website of %s.', 'tmc' ), $site );
	} elseif ( is_post_type_archive() ) {
		$text = get_the_post_type_description();
		$text = $text ? $text : sprintf( $pair, post_type_archive_title( '', false ), $site );
	} elseif ( is_category() || is_tag() || is_tax() ) {
		$text = term_description();
		$text = $text ? $text : sprintf( $pair, single_term_title( '', false ), $site );
	} elseif ( is_archive() || is_home() ) {
		$text = sprintf( $pair, wp_strip_all_tags( is_home() ? single_post_title( '', false ) : get_the_archive_title() ), $site );
	}

	$text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' ) ) ) );
	if ( mb_strlen( $text ) > 160 ) {
		$text = preg_replace( '/[\s,.;:\x{2013}\x{2014}-]+$/u', '', mb_substr( $text, 0, 157 ) ) . '…';
	}
	return $text;
}
