<?php
/**
 * Dynamic blocks (rendered in PHP, so they are always up to date and follow the visitor's language):
 *
 *   tmc/network       cards for every website in the TMC ecosystem
 *   tmc/sitemap       page tree of the current site and language (GIGW sitemap)
 *   tmc/notice-board  "What's new" list from a category, with pause/play for the scroll (GIGW)
 *   tmc/latest-news   latest posts from a category as cards
 *   tmc/tenders       open tenders / EOIs (closing date not passed)
 *   tmc/jobs          current job openings
 *   tmc/events        upcoming events
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
	foreach ( array( 'tenders', 'jobs', 'events' ) as $tmc_list ) {
		register_block_type(
			"tmc/$tmc_list",
			array(
				'render_callback' => "tmc_render_{$tmc_list}_block",
				'attributes'      => array( 'count' => array( 'type' => 'number', 'default' => 4 ) ),
			)
		);
	}
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

/** Compact dated list used by the tenders / jobs / events blocks. */
function tmc_dated_list( array $posts, $date_field, $date_label, $archive_link, $empty ) {
	if ( ! $posts ) {
		return '<p class="notice-empty">' . esc_html( $empty ) . '</p>';
	}
	$html = '<ul class="dated-list">';
	foreach ( $posts as $post ) {
		$ref   = get_post_meta( $post->ID, '_tmc_ref_no', true );
		$html .= sprintf(
			'<li><a href="%s">%s</a><span class="dated-meta">%s%s %s</span></li>',
			esc_url( get_permalink( $post ) ),
			esc_html( get_the_title( $post ) ),
			$ref ? esc_html( $ref ) . ' · ' : '',
			esc_html( $date_label ),
			tmc_time_tag( get_post_meta( $post->ID, '_' . $date_field, true ) )
		);
	}
	return $html . sprintf( '</ul><p class="view-all"><a href="%s">%s</a></p>', esc_url( $archive_link ), esc_html__( 'View all', 'tmc' ) );
}

function tmc_open_items( $type, $count ) {
	return get_posts(
		array(
			'post_type'        => $type,
			'posts_per_page'   => max( 1, min( 10, (int) $count ) ),
			'suppress_filters' => false,
			'meta_query'       => array( tmc_current_clause( 'tmc_closing_at' ) ),
		)
	);
}

function tmc_render_tenders_block( $attributes ) {
	return tmc_dated_list( tmc_open_items( 'tmc_tender', $attributes['count'] ), 'tmc_closing_at', __( 'Last date:', 'tmc' ), get_post_type_archive_link( 'tmc_tender' ), __( 'There are no open tenders at present.', 'tmc' ) );
}

function tmc_render_jobs_block( $attributes ) {
	return tmc_dated_list( tmc_open_items( 'tmc_job', $attributes['count'] ), 'tmc_closing_at', __( 'Last date:', 'tmc' ), get_post_type_archive_link( 'tmc_job' ), __( 'There are no current openings.', 'tmc' ) );
}

function tmc_render_events_block( $attributes ) {
	$posts = get_posts(
		array(
			'post_type'        => 'tmc_event',
			'posts_per_page'   => max( 1, min( 10, (int) $attributes['count'] ) ),
			'suppress_filters' => false,
			'meta_key'         => '_tmc_start_at',
			'orderby'          => 'meta_value',
			'order'            => 'ASC',
			'meta_query'       => array( array( 'key' => '_tmc_start_at', 'value' => wp_date( 'Y-m-d 00:00:00' ), 'compare' => '>=', 'type' => 'DATETIME' ) ),
		)
	);
	return tmc_dated_list( $posts, 'tmc_start_at', '', get_post_type_archive_link( 'tmc_event' ), __( 'There are no upcoming events at present.', 'tmc' ) );
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
			'meta_query'       => array( tmc_current_clause( 'tmc_expires_at' ) ), // automatic expiry
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
