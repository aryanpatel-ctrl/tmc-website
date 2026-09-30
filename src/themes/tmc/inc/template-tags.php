<?php
/**
 * Template helpers: language, breadcrumbs, network sites, contact details, last-updated.
 */

defined( 'ABSPATH' ) || exit;

/* ---------------------------------------------------------------- language */

function tmc_current_lang() {
	$lang = function_exists( 'pll_current_language' ) ? pll_current_language() : '';
	return $lang ? $lang : 'en';
}

function tmc_home_url() {
	return function_exists( 'pll_home_url' ) ? pll_home_url() : home_url( '/' );
}

/** English | हिन्दी switcher. Links to the translation of the current page when there is one. */
function tmc_language_switcher() {
	if ( ! function_exists( 'pll_the_languages' ) ) {
		return;
	}
	$languages = pll_the_languages( array( 'raw' => 1, 'hide_if_empty' => 0 ) );
	if ( count( $languages ) < 2 ) {
		return;
	}
	echo '<ul class="lang-switch">';
	foreach ( $languages as $language ) {
		printf(
			'<li><a href="%s" lang="%s" hreflang="%s"%s>%s</a></li>',
			esc_url( $language['url'] ),
			esc_attr( $language['locale'] ? str_replace( '_', '-', $language['locale'] ) : $language['slug'] ),
			esc_attr( $language['slug'] ),
			$language['current_lang'] ? ' aria-current="true"' : '',
			esc_html( $language['name'] )
		);
	}
	echo '</ul>';
}

/* ---------------------------------------------------------------- site identity */

/** Site name in the current language, for any site in the network. */
function tmc_site_name( $blog_id = 0, $lang = '' ) {
	$blog_id = $blog_id ? $blog_id : get_current_blog_id();
	$lang    = $lang ? $lang : tmc_current_lang();
	if ( 'hi' === $lang ) {
		$hindi = get_blog_option( $blog_id, 'tmc_name_hi' );
		if ( $hindi ) {
			return $hindi;
		}
	}
	return get_blog_option( $blog_id, 'blogname' );
}

function tmc_site_tagline() {
	return is_main_site()
		? __( 'A Grant-in-Aid Institution under the Department of Atomic Energy, Government of India', 'tmc' )
		: __( 'A unit of Tata Memorial Centre', 'tmc' );
}

/**
 * All websites of the TMC ecosystem, with links in the visitor's current language.
 *
 * @return array<int, array{id:int,name:string,city:string,url:string,current:bool}>
 */
function tmc_network_sites() {
	$lang  = tmc_current_lang();
	$sites = array();
	// Not filtered on "public": that flag mirrors "discourage search engines", which says nothing about visitors.
	foreach ( get_sites( array( 'number' => 50, 'archived' => 0, 'deleted' => 0, 'spam' => 0, 'orderby' => 'id' ) ) as $site ) {
		$id      = (int) $site->blog_id;
		$city    = get_blog_option( $id, 'hi' === $lang ? 'tmc_city_hi' : 'tmc_city' );
		$sites[] = array(
			'id'      => $id,
			'name'    => tmc_site_name( $id, $lang ),
			'city'    => (string) $city,
			'url'     => get_home_url( $id, '/' ) . ( 'hi' === $lang ? 'hi/' : '' ),
			'current' => get_current_blog_id() === $id,
		);
	}
	return $sites;
}

/* ---------------------------------------------------------------- links & contact */

/** Link to another website: opens in a new tab and says so to screen-reader users (GIGW). */
function tmc_external_link( $url, $label, $class = '' ) {
	return sprintf(
		'<a href="%s" target="_blank" rel="noopener noreferrer" class="is-external %s">%s<span class="screen-reader-text"> %s</span></a>',
		esc_url( $url ),
		esc_attr( $class ),
		esc_html( $label ),
		esc_html__( '(opens external website in a new tab)', 'tmc' )
	);
}

function tmc_contact_details() {
	$address = tmc_translated_mod( 'tmc_address' );
	$phone   = get_theme_mod( 'tmc_phone' );
	$email   = get_theme_mod( 'tmc_email' );
	if ( ! $address && ! $phone && ! $email ) {
		return;
	}
	echo '<address class="contact">';
	if ( $address ) {
		printf( '<p><span class="label">%s</span>%s</p>', esc_html__( 'Address', 'tmc' ), nl2br( esc_html( $address ) ) );
	}
	if ( $phone ) {
		printf( '<p><span class="label">%s</span><a href="tel:%s">%s</a></p>', esc_html__( 'Phone', 'tmc' ), esc_attr( preg_replace( '/[^0-9+]/', '', $phone ) ), esc_html( $phone ) );
	}
	if ( $email ) {
		printf( '<p><span class="label">%s</span><a href="mailto:%s">%s</a></p>', esc_html__( 'Email', 'tmc' ), esc_attr( antispambot( $email ) ), esc_html( antispambot( $email ) ) );
	}
	echo '</address>';
}

// tmc_social_links() (footer "Follow us") lives in inc/social.php with the share links.

/* ---------------------------------------------------------------- dates */

function tmc_date( $timestamp ) {
	return wp_date( 'd/m/Y', $timestamp );
}

/** "Last updated" (GIGW): this page's modification date, or the site's latest update elsewhere. */
function tmc_last_updated() {
	if ( is_singular() ) {
		return tmc_date( (int) get_post_modified_time( 'U', true ) );
	}
	global $wpdb;
	$key  = 'tmc_site_last_updated';
	$last = wp_cache_get( $key, 'tmc' );
	if ( false === $last ) {
		$last = $wpdb->get_var( "SELECT MAX(post_modified_gmt) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('page','post')" );
		wp_cache_set( $key, $last, 'tmc', HOUR_IN_SECONDS );
	}
	return $last ? tmc_date( strtotime( $last . ' UTC' ) ) : '';
}

/* ---------------------------------------------------------------- breadcrumbs */

function tmc_breadcrumbs() {
	if ( is_front_page() ) {
		return;
	}
	$crumbs = array( array( __( 'Home', 'tmc' ), tmc_home_url() ) );

	if ( is_page() ) {
		foreach ( array_reverse( get_post_ancestors( get_queried_object_id() ) ) as $ancestor ) {
			$crumbs[] = array( get_the_title( $ancestor ), get_permalink( $ancestor ) );
		}
		$crumbs[] = array( single_post_title( '', false ), '' );
	} elseif ( is_single() ) {
		$category = get_the_category();
		if ( $category ) {
			$crumbs[] = array( $category[0]->name, get_category_link( $category[0] ) );
		}
		$crumbs[] = array( single_post_title( '', false ), '' );
	} elseif ( is_search() ) {
		$crumbs[] = array( __( 'Search results', 'tmc' ), '' );
	} elseif ( is_404() ) {
		$crumbs[] = array( __( 'Page not found', 'tmc' ), '' );
	} elseif ( is_archive() ) {
		$crumbs[] = array( wp_strip_all_tags( get_the_archive_title() ), '' );
	} elseif ( is_home() ) {
		$crumbs[] = array( single_post_title( '', false ), '' );
	}

	echo '<nav class="breadcrumbs" aria-label="' . esc_attr__( 'You are here', 'tmc' ) . '"><ol>';
	foreach ( $crumbs as list( $label, $url ) ) {
		if ( $url ) {
			printf( '<li><a href="%s">%s</a></li>', esc_url( $url ), esc_html( $label ) );
		} else {
			printf( '<li><span aria-current="page">%s</span></li>', esc_html( $label ) );
		}
	}
	echo '</ol></nav>';
}
