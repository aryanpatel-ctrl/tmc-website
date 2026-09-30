<?php
/**
 * Site search presentation (engine: mu-plugins/tmc-core/search-index.php).
 *
 *   - translatable content-type labels for results, facets and suggestions
 *   - settings for the suggestion combobox (assets/js/features/search.js)
 *   - facet links, result counts and the "no results" help used by search.php
 */

defined( 'ABSPATH' ) || exit;

/** Content-type labels in the visitor's language (the engine's labels are English data). */
add_filter( 'tmc_search_type_label', 'tmc_translate_type_label', 10, 3 );
function tmc_translate_type_label( $label, $type, $plural ) {
	$labels = array(
		'page'           => array( __( 'Page', 'tmc' ), __( 'Pages', 'tmc' ) ),
		'post'           => array( __( 'News / notice', 'tmc' ), __( 'News and notices', 'tmc' ) ),
		'tmc_tender'     => array( __( 'Tender / EOI', 'tmc' ), __( 'Tenders & EOIs', 'tmc' ) ),
		'tmc_event'      => array( __( 'Event', 'tmc' ), __( 'Events', 'tmc' ) ),
		'tmc_job'        => array( __( 'Job opening', 'tmc' ), __( 'Careers', 'tmc' ) ),
		'tmc_department' => array( __( 'Department', 'tmc' ), __( 'Departments', 'tmc' ) ),
		'tmc_doctor'     => array( __( 'Doctor', 'tmc' ), __( 'Doctors', 'tmc' ) ),
		'attachment'     => array( __( 'Document', 'tmc' ), __( 'Documents', 'tmc' ) ),
	);
	return isset( $labels[ $type ] ) ? $labels[ $type ][ $plural ? 1 : 0 ] : $label;
}

/** Suggestion settings for search.js (the script only enhances forms marked data-tmc-suggest). */
add_action( 'wp_enqueue_scripts', 'tmc_search_script_settings', 20 );
function tmc_search_script_settings() {
	if ( ! wp_script_is( 'tmc-search', 'enqueued' ) ) {
		return;
	}
	wp_localize_script(
		'tmc-search',
		'tmcSearch',
		array(
			'endpoint' => esc_url_raw( rest_url( 'tmc/v1/suggest' ) ),
			'lang'     => tmc_current_lang(),
			'minChars' => 2,
			'label'    => __( 'Search suggestions', 'tmc' ),
			/* translators: %d: number of suggestions */
			'count'    => __( '%d suggestions available. Use the up and down arrow keys to choose one and Enter to open it.', 'tmc' ),
			'one'      => __( '1 suggestion available. Use the down arrow key to choose it and Enter to open it.', 'tmc' ),
			'none'     => __( 'No suggestions. Press Enter to search.', 'tmc' ),
		)
	);
}

/** Search URL for the current query with another content-type filter ('' = all). */
function tmc_search_url( $query, $type = '' ) {
	$args = array( 's' => $query );
	if ( '' !== $type ) {
		$args['type'] = $type;
	}
	return add_query_arg( urlencode_deep( $args ), tmc_home_url() );
}

/** Facet links with counts ("All (23) · Pages (5) · Documents (3) …"). */
function tmc_search_facets( array $result ) {
	if ( empty( $result['facets'] ) ) {
		return;
	}
	$all   = array_sum( $result['facets'] );
	$items = array( '' => array( __( 'All results', 'tmc' ), $all ) );
	foreach ( $result['facets'] as $type => $count ) {
		$items[ $type ] = array( tmc_search_type_label( $type, true ), $count );
	}
	echo '<nav class="search-facets" aria-label="' . esc_attr__( 'Filter results by content type', 'tmc' ) . '"><ul>';
	foreach ( $items as $type => list( $label, $count ) ) {
		$current = (string) $type === (string) $result['type'];
		printf(
			'<li><a href="%s"%s>%s <span class="facet-count">(%s)</span></a></li>',
			esc_url( tmc_search_url( $result['query'], (string) $type ) ),
			$current ? ' aria-current="page"' : '',
			esc_html( $label ),
			esc_html( number_format_i18n( $count ) )
		);
	}
	echo '</ul></nav>';
}

/** "Showing 1–10 of 23 results." (announced to screen readers when the page loads). */
function tmc_search_count_text( array $result, $shown ) {
	$total = (int) $result['total'];
	if ( ! $total ) {
		return __( 'No results found.', 'tmc' );
	}
	$first = ( $result['page'] - 1 ) * $result['per_page'] + 1;
	$last  = $first + max( 0, $shown - 1 );
	if ( $total <= $result['per_page'] ) {
		/* translators: %s: number of results */
		return sprintf( _n( '%s result found.', '%s results found.', $total, 'tmc' ), number_format_i18n( $total ) );
	}
	/* translators: 1: first result shown, 2: last result shown, 3: total number of results */
	return sprintf( __( 'Showing %1$s–%2$s of %3$s results.', 'tmc' ), number_format_i18n( $first ), number_format_i18n( $last ), number_format_i18n( $total ) );
}

/** Sitemap page in the current language (for the "no results" help), or ''. */
function tmc_search_sitemap_url() {
	$page = get_page_by_path( 'sitemap' );
	if ( ! $page ) {
		return '';
	}
	$id = function_exists( 'pll_get_post' ) ? (int) pll_get_post( $page->ID ) : (int) $page->ID;
	return $id ? (string) get_permalink( $id ) : '';
}
