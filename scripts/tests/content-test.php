<?php
/**
 * Content types: lifecycle, automatic expiry (+ audit), workflow on new types, doctor filter,
 * Hindi URLs, migration + menu. Creates its own test items and removes them afterwards.
 *
 *   docker compose run --rm -T wpcli --url=tmh.<base> eval-file - < scripts/tests/content-test.php
 */

global $wpdb;
$pass    = 0;
$fail    = 0;
$cleanup = array();
$t       = function ( $label, $ok ) use ( &$pass, &$fail ) {
	WP_CLI::log( ( $ok ? '  PASS  ' : '  FAIL  ' ) . $label );
	$ok ? $pass++ : $fail++;
};
$make = function ( $type, $title, array $meta = array(), $lang = 'en', array $extra = array() ) use ( &$cleanup ) {
	$id = wp_insert_post( array( 'post_type' => $type, 'post_status' => 'publish', 'post_title' => $title ) + $extra );
	pll_set_post_language( $id, $lang );
	foreach ( $meta as $key => $value ) {
		is_array( $value ) ? array_map( fn( $v ) => add_post_meta( $id, '_' . $key, $v ), $value ) : update_post_meta( $id, '_' . $key, $value );
	}
	$cleanup[] = $id;
	return $id;
};
$in    = fn( $id, array $posts ) => in_array( $id, wp_list_pluck( $posts, 'ID' ), true );
$stamp = fn( $days ) => wp_date( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS );
$tag   = wp_generate_password( 5, false );

WP_CLI::log( '— Types and migration' );
foreach ( array_keys( tmc_content_types() ) as $type ) {
	$t( "$type registered and translatable (EN + HI)", post_type_exists( $type ) && pll_is_translated_post_type( $type ) );
}
$t( 'migration 001 recorded for this site', in_array( '001-content-types', (array) get_option( 'tmc_migrations' ), true ) );
$menu_id = get_nav_menu_locations()['primary'] ?? 0;
$objects = wp_list_pluck( (array) wp_get_nav_menu_items( $menu_id ), 'object' );
$t( 'main menu links to Tenders, Careers, Events, Departments, Find a Doctor', ! array_diff( array( 'tmc_tender', 'tmc_job', 'tmc_event', 'tmc_department', 'tmc_doctor' ), $objects ) );
$t( 'placeholder Tenders page removed (listing serves /tenders/)', ! get_page_by_path( 'tenders' ) );

WP_CLI::log( '— Tender / job lifecycle' );
$open   = $make( 'tmc_tender', "Test open tender $tag", array( 'tmc_ref_no' => "TEST/$tag/1", 'tmc_closing_at' => $stamp( 1 ) ) );
$closed = $make( 'tmc_tender', "Test closed tender $tag", array( 'tmc_ref_no' => "TEST/$tag/2", 'tmc_closing_at' => $stamp( -1 ) ) );
$t( 'future closing date → open', 'open' === tmc_lifecycle( $open ) );
$t( 'past closing date → closed', 'closed' === tmc_lifecycle( $closed ) );
$current = tmc_open_items( 'tmc_tender', 10 );
$t( 'open tender listed as current', $in( $open, $current ) );
$t( 'closed tender not listed as current', ! $in( $closed, $current ) );
$job = $make( 'tmc_job', "Test closed job $tag", array( 'tmc_ref_no' => "TEST/$tag/J", 'tmc_closing_at' => $stamp( -2 ) ) );
$t( 'closed job opening not listed as current', ! $in( $job, tmc_open_items( 'tmc_job', 10 ) ) );

WP_CLI::log( '— Automatic expiry of notices' );
$notices = get_category_by_slug( 'notices' );
$expired = $make( 'post', "Test expired notice $tag", array( 'tmc_expires_at' => $stamp( -1 ) ), 'en', array( 'post_category' => array( $notices->term_id ) ) );
$live    = $make( 'post', "Test live notice $tag", array( 'tmc_expires_at' => $stamp( 5 ) ), 'en', array( 'post_category' => array( $notices->term_id ) ) );
list( $board ) = tmc_category_posts( 'notices', 20 );
$t( 'expired notice leaves the notice board', ! $in( $expired, $board ) );
$t( 'notice with future expiry stays on the board', $in( $live, $board ) );

WP_CLI::log( '— Expiry job and audit trail' );
$before = (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id),0) FROM ' . tmc_audit_table() );
$count  = tmc_expire_content();
$logged = $wpdb->get_col( $wpdb->prepare( 'SELECT action FROM ' . tmc_audit_table() . ' WHERE id > %d AND object_id IN (%d,%d,%d)', $before, $closed, $expired, $job ) );
$t( "expiry job flagged items ($count)", get_post_meta( $closed, '_tmc_closed', true ) && get_post_meta( $expired, '_tmc_expired', true ) );
$t( 'closures recorded in the audit log (' . implode( ', ', $logged ) . ')', ! array_diff( array( 'tender_closed', 'content_expired', 'job_opening_closed' ), $logged ) );
$t( 'second run finds nothing new (each closure logged once)', 0 === tmc_expire_content() );
update_post_meta( $closed, '_tmc_closing_at', $stamp( 3 ) );
$t( 'moving the date forward re-opens the tender', 'open' === tmc_lifecycle( $closed ) && ! get_post_meta( $closed, '_tmc_closed', true ) );
$t( 'audit chain still intact', tmc_audit_verify()['ok'] );

WP_CLI::log( '— Events' );
$event = $make( 'tmc_event', "Test event $tag", array( 'tmc_start_at' => $stamp( 3 ), 'tmc_end_at' => $stamp( 3.1 ) ) );
$past  = $make( 'tmc_event', "Test past event $tag", array( 'tmc_start_at' => $stamp( -3 ) ) );
$t( 'future event → upcoming', 'upcoming' === tmc_lifecycle( $event ) );
$t( 'past event → past', 'past' === tmc_lifecycle( $past ) );

WP_CLI::log( '— Departments and doctors' );
$dept   = $make( 'tmc_department', "Test department $tag" );
$doctor = $make( 'tmc_doctor', "Dr. Test $tag", array( 'tmc_designation' => 'Tester', 'tmc_department_ids' => array( $dept ) ) );
$found  = get_posts( array( 'post_type' => 'tmc_doctor', 'posts_per_page' => 50, 'lang' => '', 'meta_query' => array( array( 'key' => '_tmc_department_ids', 'value' => $dept, 'type' => 'NUMERIC' ) ) ) );
$t( 'doctor found by department filter', $in( $doctor, $found ) && 1 === count( $found ) );
$t( 'doctor field returns department IDs', array( $dept ) === tmc_field( $doctor, 'tmc_department_ids' ) );

WP_CLI::log( '— Workflow applies to content types' );
$editor = get_user_by( 'login', 'tmheditor' );
if ( $editor ) {
	wp_set_current_user( $editor->ID );
	$rest = function ( $status ) use ( $tag ) {
		$request = new WP_REST_Request( 'POST', '/wp/v2/tmc_tender' );
		$request->set_param( 'title', "Test editor tender $tag" );
		$request->set_param( 'status', $status );
		return rest_do_request( $request );
	};
	$t( 'Content Editor cannot publish a tender (HTTP ' . $rest( 'publish' )->get_status() . ')', 403 === $rest( 'publish' )->get_status() );
	$response = $rest( 'pending' );
	$t( 'Content Editor can submit a tender for review (HTTP ' . $response->get_status() . ')', 201 === $response->get_status() );
	$cleanup[] = (int) ( $response->get_data()['id'] ?? 0 );
	wp_set_current_user( 0 );
} else {
	WP_CLI::log( '  (skipped: demo user tmheditor not present)' );
}

WP_CLI::log( '— Hindi' );
$hi = $make( 'tmc_tender', "परीक्षण निविदा $tag", array( 'tmc_closing_at' => $stamp( 2 ) ), 'hi', array( 'post_name' => "parikshan-nivida-$tag" ) );
$t( 'Hindi tender URL is under /hi/tenders/ (' . wp_parse_url( get_permalink( $hi ), PHP_URL_PATH ) . ')', false !== strpos( get_permalink( $hi ), '/hi/tenders/' ) );

WP_CLI::log( '— Cleanup' );
foreach ( array_filter( $cleanup ) as $id ) {
	wp_delete_post( $id, true );
}

WP_CLI::log( '' );
$fail ? WP_CLI::error( "$fail failed, $pass passed" ) : WP_CLI::success( "all $pass checks passed" );
