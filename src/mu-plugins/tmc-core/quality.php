<?php
/**
 * Quality gates support (W6, tender §4.11, §4.14, §7.1).
 *
 *   tmc_published_urls()   inventory of every published public URL of the current site, used by
 *                          the link crawler to find orphaned pages (tests/links/crawl.js,
 *                          scripts/published-urls.php)
 *   tmc_stable_orderby()   deterministic order for front-end listings: rows that tie on the
 *                          requested order (e.g. items published in the same second, equal search
 *                          relevance) are ordered by ID, so pagination never repeats or skips an
 *                          item and the same request always returns the same page
 */

defined( 'ABSPATH' ) || exit;

/**
 * Published, publicly viewable content of the current site in every language.
 *
 * Pages, posts and public custom post types; attachments are files, not pages, and are checked by
 * the crawler as links instead. Password-protected items are included (they have a public URL).
 *
 * @return array<int, array{id:int, type:string, lang:string, title:string, url:string}>
 */
function tmc_published_urls() {
	$types = array_values( array_diff( get_post_types( array( 'public' => true ), 'names' ), array( 'attachment' ) ) );
	sort( $types );
	$ids = get_posts(
		array(
			'post_type'      => $types,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => true,
			'lang'           => '', // Polylang: every language
		)
	);
	$urls = array();
	foreach ( $ids as $id ) {
		$url = get_permalink( $id );
		if ( ! $url ) {
			continue;
		}
		$urls[] = array(
			'id'    => (int) $id,
			'type'  => get_post_type( $id ),
			'lang'  => function_exists( 'pll_get_post_language' ) ? (string) pll_get_post_language( $id ) : '',
			'title' => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
			'url'   => $url,
		);
	}
	/**
	 * Filters the inventory. Pages that are deliberately kept out of the navigation and out of search
	 * engines (for example the component library) remove themselves here, so they are not reported as
	 * orphaned.
	 *
	 * @param array $urls Inventory rows.
	 */
	return array_values( (array) apply_filters( 'tmc_published_urls', $urls ) );
}

add_filter( 'posts_orderby', 'tmc_stable_orderby', 10, 2 );
/**
 * Adds "ID" as the final sort key of front-end main queries that do not already sort by ID.
 *
 * @param string   $orderby ORDER BY clause (without the keywords).
 * @param WP_Query $query   The query.
 * @return string
 */
function tmc_stable_orderby( $orderby, $query ) {
	global $wpdb;
	if ( is_admin() || ! $query instanceof WP_Query || ! $query->is_main_query() || '' === trim( (string) $orderby ) ) {
		return $orderby;
	}
	if ( false !== stripos( $orderby, "{$wpdb->posts}.ID" ) || preg_match( '/^\s*RAND\(/i', $orderby ) ) {
		return $orderby;
	}
	return $orderby . ", {$wpdb->posts}.ID DESC";
}
