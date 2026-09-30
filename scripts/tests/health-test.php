<?php
/**
 * Health endpoint (/wp-json/tmc/v1/health): shape, no secrets, every check green on a provisioned
 * stack, 503 when a check fails, never cached over HTTP; cron heartbeat; Network Admin screen.
 *
 *   docker compose run --rm -T wpcli --url=tmh.<base> eval-file - < scripts/tests/health-test.php
 */

global $wpdb;
$pass = 0;
$fail = 0;
$t    = function ( $label, $ok ) use ( &$pass, &$fail ) {
	WP_CLI::log( ( $ok ? '  PASS  ' : '  FAIL  ' ) . $label );
	$ok ? $pass++ : $fail++;
};
$rest = function () {
	return rest_do_request( new WP_REST_Request( 'GET', '/tmc/v1/health' ) );
};

WP_CLI::log( '— Cron heartbeat' );
switch_to_blog( get_main_site_id() );
$scheduled = wp_next_scheduled( 'tmc_cron_heartbeat' );
restore_current_blog();
$t( 'heartbeat scheduled every minute on the main site', (bool) $scheduled );
$beat = (int) get_site_option( 'tmc_cron_heartbeat', 0 );
$t( 'cron container has run the heartbeat (' . ( $beat ? ( time() - $beat ) . 's ago' : 'never' ) . ')', $beat && time() - $beat <= tmc_health_limits()['cron_max_age'] );

WP_CLI::log( '— Report' );
$report = tmc_health_report( true );
$checks = $report['checks'];
$expected = array( 'database', 'redis', 'object_cache', 'page_cache', 'cron', 'backup', 'disk' );
if ( tmc_backup_offsite_configured() ) {
	$expected[] = 'offsite'; // only where scripts/backup/offsite-copy.sh has run
}
$t( 'reports ' . implode( ', ', $expected ), $expected === array_keys( $checks ) );
$shape = true;
foreach ( $checks as $check ) {
	$shape = $shape && is_bool( $check['ok'] ) && ! array_diff( array_keys( $check ), array( 'ok', 'age_seconds' ) );
	if ( array_key_exists( 'age_seconds', $check ) ) {
		$shape = $shape && ( null === $check['age_seconds'] || is_int( $check['age_seconds'] ) );
	}
}
$t( 'every check is a boolean, optionally with an age in seconds — nothing else', $shape );
foreach ( $checks as $name => $check ) {
	$t( "$name: " . ( $check['ok'] ? 'ok' : 'FAILING' ) . ( isset( $check['age_seconds'] ) ? " (age {$check['age_seconds']}s)" : '' ), $check['ok'] );
}
$t( 'overall status ok', 'ok' === $report['status'] );

$json   = wp_json_encode( $rest()->get_data() );
$leaks  = array_filter( array( DB_PASSWORD, DB_USER, DB_HOST, ABSPATH, get_bloginfo( 'version' ), getenv( 'TMC_AUDIT_KEY' ), defined( 'WP_REDIS_HOST' ) ? WP_REDIS_HOST . ':' : '' ) );
$leaked = array_filter( $leaks, fn( $needle ) => false !== strpos( $json, (string) $needle ) );
$t( 'response contains no credentials, host names, paths or versions', ! $leaked );

WP_CLI::log( '— Failure is visible to monitors' );
update_site_option( 'tmc_cron_heartbeat', time() - 3600 );
$stale = tmc_health_report( true );
wp_cache_delete( 'report', 'tmc_health' );
$response = $rest();
$t( 'stale heartbeat → cron not ok, status fail', false === $stale['checks']['cron']['ok'] && 'fail' === $stale['status'] );
$t( 'REST answers HTTP 503 while a check fails (got ' . $response->get_status() . ')', 503 === $response->get_status() );
tmc_cron_heartbeat(); // what the cron container does every minute
wp_cache_delete( 'report', 'tmc_health' );
$response = $rest();
$t( 'heartbeat restored → HTTP ' . $response->get_status(), 200 === $response->get_status() );
$t( 'REST response is marked no-store', false !== strpos( (string) ( $response->get_headers()['Cache-Control'] ?? '' ), 'no-store' ) );

WP_CLI::log( '— Over HTTP (through the page cache engine)' );
$host  = wp_parse_url( home_url(), PHP_URL_HOST );
$fp    = fsockopen( getenv( 'TMC_TEST_HTTP_HOST' ) ? getenv( 'TMC_TEST_HTTP_HOST' ) : 'wordpress', 80, $errno, $errstr, 10 );
$raw   = '';
if ( $fp ) {
	fwrite( $fp, "GET /wp-json/tmc/v1/health HTTP/1.0\r\nHost: $host\r\nConnection: close\r\n\r\n" );
	$raw = (string) stream_get_contents( $fp );
	fclose( $fp );
}
list( $head, $body ) = array_pad( explode( "\r\n\r\n", $raw, 2 ), 2, '' );
$data                = json_decode( $body, true );
$t( 'GET /wp-json/tmc/v1/health → 200 with JSON status ok', 0 === strpos( $head, 'HTTP/1.1 200' ) && 'ok' === ( $data['status'] ?? '' ) );
$t( 'never served from the page cache', 1 === preg_match( '/^X-TMC-Cache: BYPASS/mi', $head ) );

WP_CLI::log( '— Network Admin screen' );
$admins = get_super_admins();
wp_set_current_user( get_user_by( 'login', reset( $admins ) )->ID );
ob_start();
tmc_health_admin_page();
$html = ob_get_clean();
wp_set_current_user( 0 );
$t( 'Health & Backups screen lists the checks and recent backups', false !== strpos( $html, 'Health &amp; Backups' ) && false !== strpos( $html, 'Newest good backup' ) && false !== strpos( $html, 'Recent backups' ) );
$t( 'tables have captions and header cells (accessibility)', substr_count( $html, '<caption' ) >= 2 && false !== strpos( $html, 'scope="col"' ) );
$before = (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id),0) FROM ' . tmc_audit_table() );
$t( 'purge button action purges every site', tmc_health_purge_all() );
$t( 'purge recorded in the audit log', 'page_cache_purged' === $wpdb->get_var( $wpdb->prepare( 'SELECT action FROM ' . tmc_audit_table() . ' WHERE id > %d ORDER BY id DESC LIMIT 1', $before ) ) );

WP_CLI::log( '' );
$fail ? WP_CLI::error( "$fail failed, $pass passed" ) : WP_CLI::success( "all $pass checks passed" );
