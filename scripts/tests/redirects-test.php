<?php
/**
 * Redirect manager (tender §4.10, §4.11): 301 / 302 / 410, matching rules, loop refusal, chain
 * detection, regular expressions (off by default), CSV import/export, hit counter, audit trail and
 * — when the web container is reachable — real HTTP responses. Removes its own rules afterwards.
 *
 *   docker compose run --rm -T wpcli --url=tmh.<base> eval-file - < scripts/tests/redirects-test.php
 */

global $wpdb;
$pass  = 0;
$fail  = 0;
$ids   = array();
$t     = function ( $label, $ok ) use ( &$pass, &$fail ) {
	WP_CLI::log( ( $ok ? '  PASS  ' : '  FAIL  ' ) . $label );
	$ok ? $pass++ : $fail++;
};
$tag   = strtolower( wp_generate_password( 6, false ) );
$base  = "/w3-redirect-test-$tag";
$save  = function ( array $data, $id = 0 ) use ( &$ids ) {
	$result = tmc_redirect_save( $data, $id );
	if ( ! is_wp_error( $result ) ) {
		$ids[] = $result['id'];
	}
	return $result;
};
$code  = fn( $result ) => is_wp_error( $result ) ? $result->get_error_code() : 'saved';
$table = tmc_redirects_table();
$first_audit = (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id),0) FROM ' . tmc_audit_table() );
$regex_was   = get_option( 'tmc_redirects_regex' );
delete_option( 'tmc_redirects_regex' );

WP_CLI::log( '— Table and address matching' );
$t( 'redirects table exists on this site', $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
$t( 'migration 030 recorded for this site', in_array( '030-redirects-table', (array) get_option( 'tmc_migrations' ), true ) );
$t( 'match key ignores case and trailing slash, keeps the query', '/old/page?id=5' === tmc_redirect_key( '/Old/Page/', 'ID=5' ) && '/' === tmc_redirect_key( '/' ) );
$t( 'full URL as old address: only path and query are used', array( 'path' => '/a/b.html', 'query' => 'x=1' ) === tmc_redirect_split( 'https://old.example.gov.in/a/b.html?x=1#top' ) );

WP_CLI::log( '— 301, 302 and 410' );
$r301 = $save( array( 'source' => "$base/old-page/", 'target' => home_url( '/about-us/' ), 'status' => 301, 'note' => 'test' ) );
$t( '301 rule saved (' . $code( $r301 ) . ')', ! is_wp_error( $r301 ) );
$rule = is_wp_error( $r301 ) ? null : tmc_redirect_get( $r301['id'] );
$t( 'same-site absolute target stored as a path (works on every environment)', $rule && '/about-us/' === $rule->target );
$hit = tmc_redirect_resolve( strtoupper( "$base/old-page" ) );
$t( 'matched regardless of case / trailing slash → 301 to the new address', $hit && 301 === $hit['status'] && home_url( '/about-us/' ) === $hit['location'] );
$r302 = $save( array( 'source' => "https://www.old-site.example/$base/temp.aspx?id=7", 'target' => 'https://www.india.gov.in/', 'status' => 302 ) );
$hit  = tmc_redirect_resolve( "$base/temp.aspx?id=7" );
$t( 'old address with query string, other-website target → 302', ! is_wp_error( $r302 ) && $hit && 302 === $hit['status'] && 'https://www.india.gov.in/' === $hit['location'] );
$t( 'a different query string does not match', null === tmc_redirect_resolve( "$base/temp.aspx?id=8" ) );
$t( 'exact query-string rule applies on pages WordPress answers (query only)', null !== tmc_redirect_resolve( "$base/temp.aspx?id=7", true ) && null === tmc_redirect_resolve( "$base/old-page", true ) );
$t( 'site remembers that query-string rules exist', (int) get_option( 'tmc_redirects_query_rules' ) >= 1 );
$r410 = $save( array( 'source' => "$base/withdrawn-notice.pdf", 'target' => '', 'status' => 410 ) );
$hit  = tmc_redirect_resolve( "$base/withdrawn-notice.pdf" );
$t( '410 gone: no target needed', ! is_wp_error( $r410 ) && $hit && 410 === $hit['status'] && '' === $hit['location'] );

WP_CLI::log( '— Validation' );
$t( 'status other than 301/302/410 refused', 'tmc_redirect_status' === $code( tmc_redirect_save( array( 'source' => "$base/x", 'target' => '/', 'status' => 303 ) ) ) );
$t( 'home page cannot be redirected', 'tmc_redirect_source' === $code( tmc_redirect_save( array( 'source' => '/', 'target' => '/about-us/' ) ) ) );
$t( 'administration / login addresses cannot be redirected', 'tmc_redirect_source' === $code( tmc_redirect_save( array( 'source' => '/wp-login.php', 'target' => '/' ) ) ) );
$t( 'javascript: target refused', 'tmc_redirect_target' === $code( tmc_redirect_save( array( 'source' => "$base/js", 'target' => 'javascript:alert(1)' ) ) ) );
$t( 'protocol-relative target refused', 'tmc_redirect_target' === $code( tmc_redirect_save( array( 'source' => "$base/pr", 'target' => '//evil.example/' ) ) ) );
$t( '301 without target refused', 'tmc_redirect_target' === $code( tmc_redirect_save( array( 'source' => "$base/nt", 'target' => '', 'status' => 301 ) ) ) );
$t( 'duplicate old address refused', 'tmc_redirect_duplicate' === $code( tmc_redirect_save( array( 'source' => "$base/OLD-PAGE", 'target' => '/contact-us/' ) ) ) );
$live = get_page_by_path( 'about-us' );
if ( $live ) {
	$warn = $save( array( 'source' => wp_make_link_relative( get_permalink( $live ) ) . '?utm_source=w3test' . $tag, 'target' => '/contact-us/' ) );
	$t( 'rule for a live page address comes with a warning', ! is_wp_error( $warn ) && false !== strpos( implode( ' ', $warn['warnings'] ), 'published page' ) );
}

WP_CLI::log( '— Loops and chains' );
$a = $save( array( 'source' => "$base/a", 'target' => "$base/b/" ) );
$t( 'A → B saved', ! is_wp_error( $a ) );
$t( 'B → A refused (loop)', 'tmc_redirect_loop' === $code( tmc_redirect_save( array( 'source' => "$base/b", 'target' => "$base/a" ) ) ) );
$t( 'A → A refused (self-loop)', 'tmc_redirect_loop' === $code( tmc_redirect_save( array( 'source' => "$base/self", 'target' => home_url( "$base/SELF/" ) ) ) ) );
$b = $save( array( 'source' => "$base/b", 'target' => '/contact-us/' ) );
$t( 'B → C saved, reported as a chain (someone already points to B)', ! is_wp_error( $b ) && false !== stripos( implode( ' ', $b['warnings'] ), 'chain' ) );
$c = $save( array( 'source' => "$base/z", 'target' => "$base/a" ) );
$t( 'Z → A reported as a chain (A is redirected again)', ! is_wp_error( $c ) && false !== stripos( implode( ' ', $c['warnings'] ), 'chain' ) );
$hit = tmc_redirect_resolve( "$base/z" );
$t( 'chain followed on the server: Z → C in one redirect', $hit && home_url( '/contact-us/' ) === $hit['location'] && 2 === $hit['hops'] );
$t( 'editing B to point back at Z refused (loop through A)', 'tmc_redirect_loop' === $code( tmc_redirect_save( array( 'source' => "$base/b", 'target' => "$base/z" ), is_wp_error( $b ) ? 0 : $b['id'] ) ) );

WP_CLI::log( '— Regular expressions (off by default)' );
$t( 'regex rule refused while not enabled', 'tmc_redirect_regex_off' === $code( tmc_redirect_save( array( 'source' => "^$base/news/(\\d+)$", 'target' => '/x/', 'is_regex' => true ) ) ) );
update_option( 'tmc_redirects_regex', 1 );
$t( 'invalid pattern refused', 'tmc_redirect_regex' === $code( tmc_redirect_save( array( 'source' => "^$base/(unclosed", 'target' => '/x/', 'is_regex' => true ) ) ) );
$re  = $save( array( 'source' => "^$base/news/(\\d+)$", 'target' => '/media/?item=$1', 'is_regex' => true ) );
$hit = tmc_redirect_resolve( "$base/news/42" );
$t( 'regex rule with a captured group', ! is_wp_error( $re ) && $hit && home_url( '/media/?item=42' ) === $hit['location'] );
$t( 'regex rule does not match other addresses', null === tmc_redirect_resolve( "$base/news/abc" ) );
$t( 'regex that would redirect its own target refused', 'tmc_redirect_loop' === $code( tmc_redirect_save( array( 'source' => "^$base/loop/.*$", 'target' => "$base/loop/again", 'is_regex' => true ) ) ) );
delete_option( 'tmc_redirects_regex' );
tmc_redirects_flush();
$t( 'switching regex off stops regex rules at once', null === tmc_redirect_resolve( "$base/news/42" ) );

WP_CLI::log( '— Hit counter' );
if ( ! is_wp_error( $r301 ) ) {
	tmc_redirect_record_hit( $r301['id'] );
	tmc_redirect_record_hit( $r301['id'] );
	$rule = tmc_redirect_get( $r301['id'] );
	$t( 'hits counted and last hit recorded', 2 === (int) $rule->hits && ! empty( $rule->last_hit ) );
}

WP_CLI::log( '— Editing' );
if ( ! is_wp_error( $r302 ) ) {
	$edited = tmc_redirect_save( array( 'source' => "$base/temp.aspx?id=7", 'target' => 'https://www.india.gov.in/', 'status' => 302, 'note' => 'edited' ), $r302['id'] );
	$t( 'rule edited in place (same ID, note changed)', ! is_wp_error( $edited ) && ! $edited['created'] && $r302['id'] === $edited['id'] && 'edited' === tmc_redirect_get( $r302['id'] )->note );
}

WP_CLI::log( '— CSV import and export' );
$csv = wp_tempnam( 'redirects.csv' );
file_put_contents(
	$csv,
	"\xEF\xBB\xBFsource,target,status,regex,note\n"
	. "$base/csv-one,/departments/,301,0,imported\n"
	. "$base/csv-gone,,410,,\n"
	. "\"$base/csv-two?lang=hi\",/hi/,302,,\"note, with comma\"\n"
	. "$base/old-page,/elsewhere/,301,,already there\n"
	. "$base/csv-bad,/x/,999,,\n"
	. "$base/csv-loop,$base/csv-loop/,301,,\n"
	. "$base/csv-formula,/y/,301,,=1+2\n"
);
$import = tmc_redirects_import_csv( $csv, false, 'test.csv' );
$t( 'import: 4 added, 1 skipped (exists), 2 errors (' . ( is_wp_error( $import ) ? $import->get_error_message() : wp_json_encode( array( $import['created'], $import['skipped'], array_keys( $import['errors'] ) ) ) ) . ')', ! is_wp_error( $import ) && 4 === $import['created'] && 1 === $import['skipped'] && array( 6, 7 ) === array_keys( $import['errors'] ) );
$t( 'imported rules work', tmc_redirect_resolve( "$base/csv-one" ) && 302 === ( tmc_redirect_resolve( "$base/csv-two?lang=hi" )['status'] ?? 0 ) && 410 === ( tmc_redirect_resolve( "$base/csv-gone" )['status'] ?? 0 ) );
$t( 'existing rule untouched without "replace"', home_url( '/about-us/' ) === ( tmc_redirect_resolve( "$base/old-page" )['location'] ?? '' ) );
$again = tmc_redirects_import_csv( $csv, true, 'test.csv' );
$t( 'import with "replace" updates the existing rule', ! is_wp_error( $again ) && $again['updated'] >= 1 && home_url( '/elsewhere/' ) === tmc_redirect_resolve( "$base/old-page" )['location'] );
$header_only = wp_tempnam( 'bad.csv' );
file_put_contents( $header_only, "from,to\n/a,/b\n" );
$t( 'file without the expected header refused', is_wp_error( tmc_redirects_import_csv( $header_only ) ) );
$stream = fopen( 'php://memory', 'w+' );
tmc_redirects_write_csv( $stream );
rewind( $stream );
$export = stream_get_contents( $stream );
fclose( $stream );
$t( 'export contains the rules with header source,target,status,regex,note', str_starts_with( $export, 'source,target,status,regex,note' ) && false !== strpos( $export, "$base/csv-one" ) && false !== strpos( $export, '"note, with comma"' ) );
$t( 'export neutralises formula-looking cells', false !== strpos( $export, "'=1+2" ) );
unlink( $csv );
unlink( $header_only );
foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE source LIKE %s", $wpdb->esc_like( $base ) . '%' ) ) as $imported ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$ids[] = (int) $imported;
}

WP_CLI::log( '— HTTP (web container)' );
$web_host = getenv( 'TMC_TEST_WEB_HOST' ) ? getenv( 'TMC_TEST_WEB_HOST' ) : 'wordpress';
$request  = function ( $path ) use ( $web_host ) {
	return wp_remote_get(
		'http://' . $web_host . $path,
		array(
			'headers'     => array( 'Host' => wp_parse_url( home_url(), PHP_URL_HOST ) ),
			'redirection' => 0,
			'timeout'     => 15,
		)
	);
};
$probe = $request( '/robots.txt' );
if ( is_wp_error( $probe ) ) {
	WP_CLI::log( '  (skipped: web container not reachable at http://' . $web_host . ' — ' . $probe->get_error_message() . ')' );
} else {
	$response = $request( "$base/Old-Page" );
	$t( 'HTTP: 301 with Location to the new address (' . wp_remote_retrieve_response_code( $response ) . ')', 301 === wp_remote_retrieve_response_code( $response ) && home_url( '/elsewhere/' ) === wp_remote_retrieve_header( $response, 'location' ) );
	$response = $request( "$base/withdrawn-notice.pdf" );
	$t( 'HTTP: 410 Gone page (' . wp_remote_retrieve_response_code( $response ) . ')', 410 === wp_remote_retrieve_response_code( $response ) && false !== strpos( wp_remote_retrieve_body( $response ), 'noindex' ) );
	$response = $request( "$base/z" );
	$t( 'HTTP: chain answered with one redirect to the end', 301 === wp_remote_retrieve_response_code( $response ) && home_url( '/contact-us/' ) === wp_remote_retrieve_header( $response, 'location' ) );
	$response = $request( "$base/temp.aspx?id=7" );
	$t( 'HTTP: query-string rule → 302', 302 === wp_remote_retrieve_response_code( $response ) );
	$response = $request( "$base/no-rule-here" );
	$t( 'HTTP: address without a rule stays 404', 404 === wp_remote_retrieve_response_code( $response ) );
	$rule = is_wp_error( $r301 ) ? null : tmc_redirect_get( $r301['id'] );
	$t( 'HTTP: hit counted by the web request', $rule && (int) $rule->hits >= 3 );
}

WP_CLI::log( '— Audit trail and cleanup' );
$actions = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT action FROM ' . tmc_audit_table() . " WHERE id > %d AND object_type = 'redirect'", $first_audit ) );
$t( 'changes audit-logged (' . implode( ', ', $actions ) . ')', ! array_diff( array( 'redirect_created', 'redirect_updated', 'redirects_imported' ), $actions ) );
foreach ( array_unique( $ids ) as $id ) {
	tmc_redirect_delete( $id );
}
$t( 'rule deletion audit-logged', (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . tmc_audit_table() . " WHERE id > %d AND action = 'redirect_deleted'", $first_audit ) ) );
$left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE source LIKE %s", '%' . $wpdb->esc_like( "w3-redirect-test-$tag" ) . '%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$t( 'test rules removed', 0 === $left && null === tmc_redirect_resolve( "$base/old-page" ) );
$regex_was ? update_option( 'tmc_redirects_regex', $regex_was ) : delete_option( 'tmc_redirects_regex' );
$t( 'audit chain intact', tmc_audit_verify()['ok'] );

WP_CLI::log( '' );
$fail ? WP_CLI::error( "$fail failed, $pass passed" ) : WP_CLI::success( "all $pass checks passed" );
