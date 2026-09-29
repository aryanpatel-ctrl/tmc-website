<?php
/**
 * End-to-end check of roles, review workflow, Hindi translation and the audit log.
 * Drives the real REST API as each demo user, so WordPress's own permission checks apply.
 * Cleans up its test content afterwards (the audit log keeps the history, by design).
 *
 *   docker compose run --rm -T wpcli --url=tmh.<base> eval-file - < scripts/tests/workflow-test.php
 */

global $wpdb;
$pass = 0;
$fail = 0;
$t    = function ( $label, $ok ) use ( &$pass, &$fail ) {
	WP_CLI::log( ( $ok ? '  PASS  ' : '  FAIL  ' ) . $label );
	$ok ? $pass++ : $fail++;
};
$rest = function ( $method, $route, array $params = array() ) {
	$request = new WP_REST_Request( $method, $route );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	return rest_do_request( $request );
};
$as = function ( $login ) {
	$user = get_user_by( 'login', $login );
	if ( ! $user ) {
		WP_CLI::error( "missing demo user $login" );
	}
	wp_set_current_user( $user->ID );
	return $user;
};
$widget = function ( $callback ) {
	ob_start();
	$callback();
	return ob_get_clean();
};

$first_audit_id = (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id),0) FROM ' . tmc_audit_table() );
$title          = 'Workflow test ' . wp_generate_password( 6, false );

WP_CLI::log( '— Content Editor (tmheditor)' );
$editor = $as( 'tmheditor' );
$res    = $rest( 'POST', '/wp/v2/pages', array( 'title' => $title, 'content' => 'Draft by content editor.', 'status' => 'publish' ) );
$t( 'cannot publish directly (HTTP ' . $res->get_status() . ')', 403 === $res->get_status() );

$res = $rest( 'POST', '/wp/v2/pages', array( 'title' => $title, 'content' => 'Draft by content editor.', 'status' => 'pending' ) );
$id  = (int) ( $res->get_data()['id'] ?? 0 );
$t( 'can submit a new page for review (HTTP ' . $res->get_status() . ')', 201 === $res->get_status() && 'pending' === get_post_status( $id ) );
pll_set_post_language( $id, 'en' );

$res = $rest( 'POST', "/wp/v2/pages/$id", array( 'status' => 'publish' ) );
$t( 'cannot approve own submission (HTTP ' . $res->get_status() . ')', 403 === $res->get_status() );

WP_CLI::log( '— Reviewer / Publisher (tmhreviewer)' );
$reviewer = $as( 'tmhreviewer' );
$t( 'review queue counter shows the item', tmc_pending_count() >= 1 );
$t( 'dashboard review queue lists the page', false !== strpos( $widget( 'tmc_widget_review_queue' ), $title ) );

update_post_meta( $id, TMC_META_REVIEW_NOTE, 'Please add the OPD timings before publishing.' ); // what the meta box saves
$res      = $rest( 'POST', "/wp/v2/pages/$id", array( 'status' => 'draft' ) );
$returned = get_post_meta( $id, TMC_META_RETURNED, true );
$t( 'can return it to the author for changes', 200 === $res->get_status() && 'draft' === get_post_status( $id ) && (int) ( $returned['by'] ?? 0 ) === $reviewer->ID );

WP_CLI::log( '— Content Editor sees the note and resubmits' );
$as( 'tmheditor' );
$html = $widget( 'tmc_widget_my_submissions' );
$t( '"My submissions" shows Returned for changes + note', false !== strpos( $html, 'Returned for changes' ) && false !== strpos( $html, 'OPD timings' ) );
$res = $rest( 'POST', "/wp/v2/pages/$id", array( 'content' => 'OPD: Mon–Sat, 9:00 AM – 1:00 PM.', 'status' => 'pending' ) );
$t( 'resubmits; returned flag cleared', 200 === $res->get_status() && 'pending' === get_post_status( $id ) && ! get_post_meta( $id, TMC_META_RETURNED, true ) );

WP_CLI::log( '— Reviewer approves' );
$as( 'tmhreviewer' );
$res = $rest( 'POST', "/wp/v2/pages/$id", array( 'status' => 'publish' ) );
$t( 'approves and publishes (HTTP ' . $res->get_status() . ')', 200 === $res->get_status() && 'publish' === get_post_status( $id ) );

WP_CLI::log( '— Live content is protected' );
$as( 'tmheditor' );
$res = $rest( 'POST', "/wp/v2/pages/$id", array( 'title' => 'Changed without review' ) );
$t( 'Content Editor cannot edit the live page (HTTP ' . $res->get_status() . ')', 403 === $res->get_status() );
$res = $rest( 'DELETE', "/wp/v2/pages/$id" );
$t( 'Content Editor cannot delete the live page (HTTP ' . $res->get_status() . ')', 403 === $res->get_status() );

WP_CLI::log( '— Unit isolation' );
$other = get_site_by_path( 'hbchrcv.' . DOMAIN_CURRENT_SITE, '/' );
switch_to_blog( $other->blog_id );
$res = $rest( 'POST', '/wp/v2/pages', array( 'title' => 'Cross-unit attempt', 'status' => 'pending' ) );
$t( 'TMH editor cannot create content on the Visakhapatnam site (HTTP ' . $res->get_status() . ')', in_array( $res->get_status(), array( 401, 403 ), true ) );
restore_current_blog();

WP_CLI::log( '— Hindi translation' );
$as( 'tmhreviewer' );
$hi_id = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'कार्यप्रवाह परीक्षण', 'post_content' => 'ओपीडी: सोम–शनि, सुबह 9:00 – दोपहर 1:00', 'post_status' => 'publish' ) );
pll_set_post_language( $hi_id, 'hi' );
pll_save_post_translations( array( 'en' => $id, 'hi' => $hi_id ) );
$t( 'Hindi page linked as translation', pll_get_post( $id, 'hi' ) === $hi_id );
$t( 'Hindi URL is under /hi/ (' . wp_parse_url( get_permalink( $hi_id ), PHP_URL_PATH ) . ')', false !== strpos( get_permalink( $hi_id ), '/hi/' ) );
$t( 'English URL has no language prefix (' . wp_parse_url( get_permalink( $id ), PHP_URL_PATH ) . ')', false === strpos( get_permalink( $id ), '/en/' ) );

WP_CLI::log( '— Audit trail' );
$actions = $wpdb->get_col( $wpdb->prepare( 'SELECT action FROM ' . tmc_audit_table() . ' WHERE id > %d AND object_id = %d ORDER BY id', $first_audit_id, $id ) );
WP_CLI::log( '        ' . implode( ' → ', $actions ) );
$t(
	'submit → return → resubmit → publish all recorded in order',
	array( 'content_submitted_for_review', 'content_returned_for_changes', 'content_submitted_for_review', 'content_published' ) === array_values( array_intersect( $actions, array( 'content_submitted_for_review', 'content_returned_for_changes', 'content_published' ) ) )
);
$who = $wpdb->get_row( $wpdb->prepare( 'SELECT user_login FROM ' . tmc_audit_table() . " WHERE object_id = %d AND action = 'content_returned_for_changes' AND id > %d", $id, $first_audit_id ) );
$t( 'records who returned it (' . ( $who->user_login ?? '?' ) . ')', 'tmhreviewer' === ( $who->user_login ?? '' ) );

$check = tmc_audit_verify();
$t( "chain intact ({$check['count']} entries)", $check['ok'] );

WP_CLI::log( '— Tamper detection' );
$table  = tmc_audit_table();
$victim = $wpdb->get_row( $wpdb->prepare( "SELECT id, object_title FROM {$table} WHERE id > %d AND object_id = %d AND action = 'content_published'", $first_audit_id, $id ) );
$wpdb->update( $table, array( 'object_title' => 'Innocent looking title' ), array( 'id' => $victim->id ) );
$check = tmc_audit_verify();
$t( "editing entry #{$victim->id} in the DB is detected", ! $check['ok'] && (int) $check['broken_at'] === (int) $victim->id );
$wpdb->update( $table, array( 'object_title' => $victim->object_title ), array( 'id' => $victim->id ) );
$t( 'chain valid again after restoring the original', tmc_audit_verify()['ok'] );

WP_CLI::log( '— Cleanup' );
wp_set_current_user( 0 );
wp_delete_post( $hi_id, true );
wp_delete_post( $id, true );

WP_CLI::log( '' );
$fail ? WP_CLI::error( "$fail failed, $pass passed" ) : WP_CLI::success( "all $pass checks passed" );
