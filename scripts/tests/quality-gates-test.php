<?php
/**
 * W6 quality gates: published URL inventory (orphan detection for the link crawler), deterministic
 * order of front-end listings, Find a Doctor name filter. Creates its own items and removes them.
 *
 *   docker compose run --rm -T wpcli --url=tmh.<base> eval-file - < scripts/tests/quality-gates-test.php
 */

global $wpdb;
$pass    = 0;
$fail    = 0;
$cleanup = array();
$t       = function ( $label, $ok ) use ( &$pass, &$fail ) {
	WP_CLI::log( ( $ok ? '  PASS  ' : '  FAIL  ' ) . $label );
	$ok ? $pass++ : $fail++;
};
$make = function ( $type, $status, $title, $lang = 'en', array $extra = array() ) use ( &$cleanup ) {
	$id = wp_insert_post( array( 'post_type' => $type, 'post_status' => $status, 'post_title' => $title ) + $extra, true );
	if ( is_wp_error( $id ) ) {
		WP_CLI::error( "fixture $title: " . $id->get_error_message() );
	}
	if ( $lang && function_exists( 'pll_set_post_language' ) ) {
		pll_set_post_language( $id, $lang );
	}
	$cleanup[] = $id;
	return $id;
};
/** Runs a query as if it were the page's main query (is_main_query() is true while it runs). */
$as_main = function ( array $args ) {
	$previous                = $GLOBALS['wp_the_query'] ?? null;
	$query                   = new WP_Query();
	$GLOBALS['wp_the_query'] = $query;
	$query->query( $args );
	$GLOBALS['wp_the_query'] = $previous;
	return $query;
};
$tag = strtolower( wp_generate_password( 6, false ) );

WP_CLI::log( '— Published URL inventory (orphan detection)' );
$page    = $make( 'page', 'publish', "QG page $tag", 'en', array( 'post_name' => "qg-page-$tag" ) );
$tender  = $make( 'tmc_tender', 'publish', "QG tender $tag", 'hi', array( 'post_name' => "qg-tender-$tag" ) );
$draft   = $make( 'page', 'draft', "QG draft $tag" );
$private = $make( 'post', 'private', "QG private $tag" );
$file    = wp_insert_attachment( array( 'post_title' => "QG file $tag", 'post_mime_type' => 'application/pdf', 'post_status' => 'inherit' ) );
$cleanup[] = $file;

$inventory = tmc_published_urls();
$by_id     = array_column( $inventory, null, 'id' );
$t( 'published page listed with its permalink', isset( $by_id[ $page ] ) && get_permalink( $page ) === $by_id[ $page ]['url'] );
$t( 'published page carries its language and title', 'en' === ( $by_id[ $page ]['lang'] ?? '' ) && "QG page $tag" === ( $by_id[ $page ]['title'] ?? '' ) );
$t( 'published custom post type listed (Hindi tender under /hi/)', 'tmc_tender' === ( $by_id[ $tender ]['type'] ?? '' ) && false !== strpos( $by_id[ $tender ]['url'] ?? '', '/hi/tenders/' ) );
$t( 'draft not listed', ! isset( $by_id[ $draft ] ) );
$t( 'private post not listed', ! isset( $by_id[ $private ] ) );
$t( 'attachments not listed', ! isset( $by_id[ $file ] ) && ! in_array( 'attachment', array_column( $inventory, 'type' ), true ) );
$t( 'home page listed as the site address', in_array( home_url( '/' ), array_column( $inventory, 'url' ), true ) );
$langs = array_unique( array_column( $inventory, 'lang' ) );
$t( 'both languages present', in_array( 'en', $langs, true ) && in_array( 'hi', $langs, true ) );
$t( 'no duplicate URLs', count( $inventory ) === count( array_unique( array_column( $inventory, 'url' ) ) ) );

$script = '/tmc-scripts/published-urls.php';
if ( file_exists( $script ) ) {
	ob_start();
	include $script;
	$json = json_decode( (string) ob_get_clean(), true );
	$t( 'scripts/published-urls.php prints the inventory as JSON', is_array( $json ) && home_url( '/' ) === ( $json['site'] ?? '' ) && count( $json['urls'] ?? array() ) === count( $inventory ) );
} else {
	$t( "scripts/published-urls.php available at $script", false );
}

WP_CLI::log( '— Deterministic order of listings' );
$tied = array();
for ( $i = 0; $i < 3; $i++ ) {
	$tied[] = $make( 'post', 'publish', "QG order $tag", 'en', array( 'post_content' => "qgorder$tag", 'post_date' => '2020-01-01 10:00:00' ) );
}
$search = $as_main( array( 's' => "qgorder$tag", 'post_type' => 'post', 'lang' => '', 'posts_per_page' => 10 ) );
$want   = $tied;
rsort( $want );
$t( 'items that tie on date and relevance are ordered by ID (newest first)', array_map( 'intval', wp_list_pluck( $search->posts, 'ID' ) ) === $want );
$t( 'main query ORDER BY ends with the ID tiebreaker', false !== strpos( $search->request, "{$wpdb->posts}.ID DESC" ) );
$by_id_order = $as_main( array( 'post_type' => 'post', 'lang' => '', 'orderby' => 'ID', 'order' => 'ASC', 'posts_per_page' => 1 ) );
$t( 'queries already ordered by ID are left alone', 1 === substr_count( $by_id_order->request, "{$wpdb->posts}.ID" ) );
$secondary = new WP_Query( array( 's' => "qgorder$tag", 'post_type' => 'post', 'lang' => '' ) );
$t( 'secondary queries are not changed', false === strpos( $secondary->request, "{$wpdb->posts}.ID DESC" ) );

WP_CLI::log( '— Find a Doctor name filter' );
$_GET['doctor_name'] = "QG Doctor $tag";
$doctors             = $as_main( array( 'post_type' => 'tmc_doctor', 'lang' => '' ) );
unset( $_GET['doctor_name'] );
$t( 'doctor listing searches the "doctor_name" field', "QG Doctor $tag" === $doctors->get( 's' ) );
$t( 'doctor listing does not use the reserved "name" query variable', '' === (string) $doctors->get( 'name' ) );

WP_CLI::log( '— Cleanup' );
foreach ( array_filter( $cleanup ) as $id ) {
	wp_delete_post( $id, true );
}

WP_CLI::log( '' );
$fail ? WP_CLI::error( "$fail failed, $pass passed" ) : WP_CLI::success( "all $pass checks passed" );
