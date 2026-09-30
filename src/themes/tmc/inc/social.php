<?php
/**
 * Social media (R-4.3-7, R-4.12-4).
 *
 *   Footer "Follow us" — each site's official accounts (Appearance → Customize → TMC contact details).
 *   The URLs are empty by default: TMC provides them. In demo environments (TMC_DEMO=1) and only while
 *   no URL is configured, the footer shows the platforms' home pages with a visible "demo links" note
 *   so the layout can be reviewed; nothing is written to the database. See docs/integration/gateway.md.
 *
 *   Share links on news/notices, events and tenders — plain links to each platform's share page
 *   (no third-party scripts, nothing loaded until the visitor clicks), e-mail, and "Copy link".
 */

defined( 'ABSPATH' ) || exit;

function tmc_social_networks() {
	return array(
		'tmc_facebook'  => 'Facebook',
		'tmc_x'         => 'X',
		'tmc_youtube'   => 'YouTube',
		'tmc_instagram' => 'Instagram',
		'tmc_linkedin'  => 'LinkedIn',
	);
}

/**
 * Configured social profiles, or clearly flagged demo placeholders in demo environments.
 *
 * @return array{0: array<string,string>, 1: bool} [ label => URL, is demo ]
 */
function tmc_social_profiles() {
	$links = array();
	foreach ( tmc_social_networks() as $mod => $label ) {
		$url = esc_url_raw( (string) get_theme_mod( $mod, '' ), array( 'https', 'http' ) );
		if ( '' !== $url ) {
			$links[ $label ] = $url;
		}
	}
	if ( $links || ! function_exists( 'tmc_is_demo' ) || ! tmc_is_demo() ) {
		return array( $links, false );
	}
	return array(
		array(
			'Facebook'  => 'https://www.facebook.com/',
			'X'         => 'https://x.com/',
			'YouTube'   => 'https://www.youtube.com/',
			'Instagram' => 'https://www.instagram.com/',
			'LinkedIn'  => 'https://www.linkedin.com/',
		),
		true,
	);
}

/** Footer "Follow us" list (called from footer.php). */
function tmc_social_links() {
	list( $links, $demo ) = tmc_social_profiles();
	if ( ! $links ) {
		return;
	}
	echo '<h3 class="footer-heading footer-social-heading">' . esc_html__( 'Follow us', 'tmc' ) . '</h3><ul class="social-links">';
	foreach ( $links as $label => $url ) {
		echo '<li>' . tmc_external_link( $url, $label, 'social social-' . sanitize_html_class( strtolower( $label ) ) ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in tmc_external_link()
	}
	echo '</ul>';
	if ( $demo ) {
		echo '<p class="social-demo-note">' . esc_html__( 'Demo links: the official Tata Memorial Centre accounts will be added by TMC.', 'tmc' ) . '</p>';
	}
}

/* ---------------------------------------------------------------- share links */

function tmc_share_post_types() {
	return (array) apply_filters( 'tmc_share_post_types', array( 'post', 'tmc_event', 'tmc_tender' ) );
}

/** One share link; the accessible name keeps the visible platform name (WCAG 2.5.3). */
function tmc_share_link( $url, $label, $accessible, $class, $external = true ) {
	return sprintf(
		'<li><a href="%s" class="share-link %s"%s aria-label="%s">%s</a></li>',
		esc_url( $url, array( 'https', 'mailto' ) ),
		esc_attr( $class . ( $external ? ' is-external' : '' ) ),
		$external ? ' target="_blank" rel="noopener noreferrer"' : '',
		esc_attr( $external ? $accessible . ' ' . __( '(opens external website in a new tab)', 'tmc' ) : $accessible ),
		esc_html( $label )
	);
}

function tmc_share_links( $post_id ) {
	$url   = get_permalink( $post_id );
	$title = html_entity_decode( wp_strip_all_tags( get_the_title( $post_id ) ), ENT_QUOTES, 'UTF-8' );
	if ( ! $url ) {
		return '';
	}
	/* translators: %s: social network name */
	$on    = fn( $network ) => sprintf( __( 'Share on %s', 'tmc' ), $network );
	$items = tmc_share_link( 'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode( $url ), 'Facebook', $on( 'Facebook' ), 'share-facebook' )
		. tmc_share_link( 'https://x.com/intent/post?url=' . rawurlencode( $url ) . '&text=' . rawurlencode( $title ), 'X', $on( 'X' ), 'share-x' )
		. tmc_share_link( 'https://www.linkedin.com/sharing/share-offsite/?url=' . rawurlencode( $url ), 'LinkedIn', $on( 'LinkedIn' ), 'share-linkedin' )
		. tmc_share_link( 'https://wa.me/?text=' . rawurlencode( $title . ' ' . $url ), 'WhatsApp', $on( 'WhatsApp' ), 'share-whatsapp' )
		. tmc_share_link( 'mailto:?subject=' . rawurlencode( $title ) . '&body=' . rawurlencode( $url ), __( 'Email', 'tmc' ), __( 'Share by email', 'tmc' ), 'share-email', false );

	return sprintf(
		'<div class="share-links"><h2 class="share-title">%s</h2><ul>%s<li><button type="button" class="share-link share-copy" data-url="%s" hidden>%s</button></li></ul><p class="screen-reader-text share-status" role="status"></p></div>',
		esc_html__( 'Share this page', 'tmc' ),
		$items,
		esc_url( $url ),
		esc_html__( 'Copy link', 'tmc' )
	);
}

// After the main content of a single news item, event or tender (not in excerpts, feeds or widgets).
add_filter( 'the_content', 'tmc_share_links_append', 25 );
function tmc_share_links_append( $content ) {
	if ( is_feed() || ! is_singular( tmc_share_post_types() ) || ! in_the_loop() || ! is_main_query() || doing_filter( 'get_the_excerpt' ) || get_the_ID() !== get_queried_object_id() ) {
		return $content;
	}
	return $content . tmc_share_links( get_queried_object_id() );
}

add_action( 'wp_enqueue_scripts', 'tmc_social_script_strings', 20 );
function tmc_social_script_strings() {
	wp_localize_script( 'tmc-social', 'tmcSocial', array( 'copied' => __( 'Link copied', 'tmc' ) ) );
}
