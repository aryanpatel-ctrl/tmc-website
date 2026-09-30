<?php
/**
 * Keeps Polylang's static front page data correct inside switch_to_blog() (needed by network
 * publishing, which writes copies on unit sites from the TMC site).
 *
 * Polylang (3.8.x) reads the site's page_on_front / page_for_posts once per request
 * (PLL_Static_Pages::init()) and does not refresh them when the blog is switched. Creating a post
 * while switched changes the language term counts, which clears the switched site's cached
 * language list; Polylang then rebuilds and stores that list with the *other* site's front page ID,
 * and the switched site's home page can turn into a 404. Re-reading the static pages on every blog
 * switch (and on the way back) keeps each site's cache built from its own settings.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'switch_blog', 'tmc_polylang_follow_blog_switch', 20, 2 );
function tmc_polylang_follow_blog_switch( $new_blog_id, $prev_blog_id ) {
	if ( (int) $new_blog_id === (int) $prev_blog_id || ! function_exists( 'PLL' ) ) {
		return;
	}
	$polylang = PLL();
	if ( is_object( $polylang ) && isset( $polylang->static_pages ) && $polylang->static_pages instanceof PLL_Static_Pages ) {
		$polylang->static_pages->init();
	}
}
