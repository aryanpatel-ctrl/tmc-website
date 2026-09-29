<?php
/**
 * Dynamic blocks (rendered in PHP, so they are always up to date and follow the visitor's language):
 *
 *   tmc/network       cards for every website in the TMC ecosystem
 *   tmc/sitemap       page tree of the current site and language (GIGW sitemap)
 *   tmc/notice-board  "What's new" list from a category, with pause/play for the scroll (GIGW)
 *   tmc/latest-news   latest posts from a category as cards
 *
 * The editor side is registered by assets/js/blocks-editor.js.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', 'tmc_register_blocks' );
function tmc_register_blocks() {
	register_block_type( 'tmc/network', array( 'render_callback' => 'tmc_render_network' ) );
	register_block_type( 'tmc/sitemap', array( 'render_callback' => 'tmc_render_sitemap' ) );
	register_block_type(
		'tmc/notice-board',
		array(
			'render_callback' => 'tmc_render_notice_board',
			'attributes'      => array(
				'category' => array( 'type' => 'string', 'default' => 'notices' ),
				'count'    => array( 'type' => 'number', 'default' => 6 ),
			),
		)
	);
	register_block_type(
		'tmc/latest-news',
		array(
			'render_callback' => 'tmc_render_latest_news',
			'attributes'      => array(
				'category' => array( 'type' => 'string', 'default' => 'news' ),
				'count'    => array( 'type' => 'number', 'default' => 3 ),
			),
		)
	);
}

function tmc_render_network() {
	$html = '<ul class="network-grid">';
	foreach ( tmc_network_sites() as $site ) {
		$html .= sprintf(
			'<li class="network-card%s"><a href="%s"%s><span class="network-name">%s</span>%s<span class="network-cta" aria-hidden="true">%s</span></a></li>',
			$site['current'] ? ' is-current' : '',
			esc_url( $site['url'] ),
			$site['current'] ? ' aria-current="page"' : '',
			esc_html( $site['name'] ),
			$site['city'] ? '<span class="network-city">' . esc_html( $site['city'] ) . '</span>' : '',
			esc_html__( 'Visit website', 'tmc' )
		);
	}
	return $html . '</ul>';
}

function tmc_render_sitemap() {
	$args = array(
		'title_li'    => '',
		'echo'        => false,
		'sort_column' => 'menu_order,post_title',
	);
	if ( function_exists( 'pll_current_language' ) ) {
		$args['lang'] = tmc_current_lang();
	}
	return '<ul class="sitemap-tree">' . wp_list_pages( $args ) . '</ul>';
}

function tmc_category_posts( $category_slug, $count ) {
	$category = get_category_by_slug( $category_slug );
	if ( function_exists( 'pll_get_term' ) && $category ) {
		$translated = pll_get_term( $category->term_id );
		$category   = $translated ? get_term( $translated, 'category' ) : $category;
	}
	$posts = get_posts(
		array(
			'posts_per_page'   => max( 1, min( 20, (int) $count ) ),
			'cat'              => $category ? $category->term_id : 0,
			'no_found_rows'    => true,
			'suppress_filters' => false, // lets Polylang limit results to the current language
		)
	);
	return array( $posts, $category );
}

function tmc_render_notice_board( $attributes ) {
	list( $posts, $category ) = tmc_category_posts( $attributes['category'], $attributes['count'] );
	if ( ! $posts ) {
		return '<p class="notice-empty">' . esc_html__( 'There are no notices at the moment.', 'tmc' ) . '</p>';
	}
	$items = '';
	foreach ( $posts as $post ) {
		$is_new = ( time() - (int) get_post_time( 'U', true, $post ) ) < 14 * DAY_IN_SECONDS;
		$items .= sprintf(
			'<li><time datetime="%s">%s</time> <a href="%s">%s</a>%s</li>',
			esc_attr( get_post_time( 'c', true, $post ) ),
			esc_html( tmc_date( (int) get_post_time( 'U', true, $post ) ) ),
			esc_url( get_permalink( $post ) ),
			esc_html( get_the_title( $post ) ),
			$is_new ? ' <span class="badge-new">' . esc_html__( 'New', 'tmc' ) . '</span>' : ''
		);
	}
	$all = $category ? sprintf( '<p class="view-all"><a href="%s">%s</a></p>', esc_url( get_category_link( $category ) ), esc_html__( 'View all', 'tmc' ) ) : '';
	return sprintf(
		'<div class="notice-board" data-scroll><button type="button" class="notice-toggle" aria-pressed="false">%s</button><div class="notice-window"><div class="notice-viewport"><ul class="notice-list">%s</ul></div></div>%s</div>',
		esc_html__( 'Pause', 'tmc' ),
		$items,
		$all
	);
}

function tmc_render_latest_news( $attributes ) {
	list( $posts, $category ) = tmc_category_posts( $attributes['category'], $attributes['count'] );
	if ( ! $posts ) {
		return '<p>' . esc_html__( 'No news yet.', 'tmc' ) . '</p>';
	}
	$html = '<ul class="card-grid">';
	foreach ( $posts as $post ) {
		$html .= sprintf(
			'<li class="card"><p class="card-meta"><time datetime="%s">%s</time></p><h3 class="card-title"><a href="%s">%s</a></h3><p class="card-text">%s</p></li>',
			esc_attr( get_post_time( 'c', true, $post ) ),
			esc_html( tmc_date( (int) get_post_time( 'U', true, $post ) ) ),
			esc_url( get_permalink( $post ) ),
			esc_html( get_the_title( $post ) ),
			esc_html( wp_trim_words( get_the_excerpt( $post ), 24 ) )
		);
	}
	$html .= '</ul>';
	if ( $category ) {
		$html .= sprintf( '<p class="view-all"><a href="%s">%s</a></p>', esc_url( get_category_link( $category ) ), esc_html__( 'View all', 'tmc' ) );
	}
	return $html;
}
