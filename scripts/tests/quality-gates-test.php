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
if ( function_exists( 'tmc_component_library_page_id' ) ) {
	switch_to_blog( get_main_site_id() );
	$library      = tmc_component_library_page_id();
	$main_entries = array_column( tmc_published_urls(), 'id' );
	restore_current_blog();
	$t( 'the deliberately unlinked component library (TMC site) is not reported as an orphan', $library && ! in_array( $library, $main_entries, true ) && count( $main_entries ) > 0 );
}

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
// Only the ORDER BY clause matters: other modules may reference the ID column elsewhere (joins, filters).
$order_clause = preg_match( '/ORDER BY\s+(.*?)(?:\s+LIMIT\s|$)/is', $by_id_order->request, $order_match ) ? $order_match[1] : '';
$t( 'queries already ordered by ID are left alone (ORDER BY ' . trim( $order_clause ) . ')', 1 === substr_count( $order_clause, "{$wpdb->posts}.ID" ) );
$secondary = new WP_Query( array( 's' => "qgorder$tag", 'post_type' => 'post', 'lang' => '' ) );
$t( 'secondary queries are not changed', false === strpos( $secondary->request, "{$wpdb->posts}.ID DESC" ) );

WP_CLI::log( '— Find a Doctor name filter' );
$_GET['doctor_name'] = "QG Doctor $tag";
$doctors             = $as_main( array( 'post_type' => 'tmc_doctor', 'lang' => '' ) );
unset( $_GET['doctor_name'] );
$t( 'doctor listing searches the "doctor_name" field', "QG Doctor $tag" === $doctors->get( 's' ) );
$t( 'doctor listing does not use the reserved "name" query variable', '' === (string) $doctors->get( 'name' ) );

WP_CLI::log( '— WordPress default content removed (migration 060)' );
$t( 'migration 060 recorded for this site', in_array( '060-remove-default-content', (array) get_option( 'tmc_migrations' ), true ) );
$leftover = get_posts( array( 'post_type' => array( 'post', 'page' ), 'post_name__in' => array( 'hello-world', 'sample-page' ), 'post_status' => 'any', 'lang' => '', 'posts_per_page' => 10 ) );
$leftover = array_filter( $leftover, fn( $p ) => false !== strpos( $p->post_content, 'This is your first post.' ) || false !== strpos( $p->post_content, 'This is an example page.' ) );
$t( 'no "Hello world!" post or "Sample Page" left', ! $leftover );
$privacy = get_page_by_path( 'privacy-policy' );
$t( 'Privacy Policy page is published', $privacy && 'publish' === $privacy->post_status );
$t( 'Privacy Policy page has the TMC text, not the WordPress template', $privacy && false === strpos( $privacy->post_content, 'privacy-policy-tutorial' ) && false !== strpos( $privacy->post_content, 'personal information' ) );
$t( 'site privacy policy setting points to it', $privacy && (int) get_option( 'wp_page_for_privacy_policy' ) === (int) $privacy->ID );
$policy_menu = wp_get_nav_menu_object( 'Footer policies (English)' );
$policy_ids  = $policy_menu ? array_map( 'intval', wp_list_pluck( (array) wp_get_nav_menu_items( $policy_menu->term_id ), 'object_id' ) ) : array();
$t( 'footer policy menu links to the published Privacy Policy', $privacy && in_array( (int) $privacy->ID, $policy_ids, true ) );
$unpublished = array_filter( $policy_ids, fn( $id ) => 'publish' !== get_post_status( $id ) );
$t( 'every footer policy link points to a published page', $policy_ids && ! $unpublished );

WP_CLI::log( '— Home page link text (migration 061) and network links' );
$t( 'migration 061 recorded for this site', in_array( '061-descriptive-link-text', (array) get_option( 'tmc_migrations' ), true ) );
$home_content = (string) get_post_field( 'post_content', (int) get_option( 'page_on_front' ) );
$t( 'About section button says "More about us"', false !== strpos( $home_content, '>More about us</a>' ) && false === strpos( $home_content, '>Read more</a>' ) );
$network = tmc_network_sites();
$t( 'TMC network lists every site of the network', count( $network ) === count( get_sites( array( 'archived' => 0, 'deleted' => 0, 'spam' => 0 ) ) ) );
$bad_links = array_filter( $network, fn( $s ) => trailingslashit( set_url_scheme( get_blog_option( $s['id'], 'home' ), wp_parse_url( $s['url'], PHP_URL_SCHEME ) ) ) !== preg_replace( '#hi/$#', '', $s['url'] ) || false !== strpos( $s['url'], '/hi/hi/' ) );
$t( 'network links point to each site\'s own home page', $network && ! $bad_links );

WP_CLI::log( '— Cleanup' );
foreach ( array_filter( $cleanup ) as $id ) {
	wp_delete_post( $id, true );
}

WP_CLI::log( '' );
$fail ? WP_CLI::error( "$fail failed, $pass passed" ) : WP_CLI::success( "all $pass checks passed" );
