<?php
/**
 * TMC full-page cache — request engine.
 *
 * Loaded by wp-content/advanced-cache.php (a two-line stub installed by
 * scripts/perf/install-page-cache.php) when WP_CACHE is true, i.e. before WordPress connects to
 * the database. For an anonymous GET/HEAD page view that matches config.php it serves the page
 * from Redis ("HIT") and stops; otherwise WordPress runs as usual and, when every rule in
 * tmc_pc_response_bypass_reason() allows it, the finished page is stored ("MISS").
 * Everything else is labelled "BYPASS". Any Redis problem fails open (the page is rendered).
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/functions.php';

// WP-CLI and the cron container load WordPress too — never cache there.
if ( 'cli' === PHP_SAPI || ( defined( 'TMC_PAGE_CACHE_DISABLED' ) && TMC_PAGE_CACHE_DISABLED ) ) {
	return;
}

$tmc_pc_request = tmc_pc_request( $_SERVER, $_COOKIE );
if ( ! $tmc_pc_request['cacheable'] ) {
	if ( $tmc_pc_request['frontend'] ) {
		tmc_pc_label( 'BYPASS', $tmc_pc_request['reason'] );
	}
	unset( $tmc_pc_request );
	return;
}

$tmc_pc_generations = tmc_pc_generations( $tmc_pc_request['host'] );
if ( ! $tmc_pc_generations ) {
	tmc_pc_label( 'BYPASS', 'redis-unavailable' );
	unset( $tmc_pc_request, $tmc_pc_generations );
	return;
}

$tmc_pc_key    = tmc_pc_page_key( $tmc_pc_generations, $tmc_pc_request['url'] );
$tmc_pc_method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );
$tmc_pc_raw    = tmc_pc_cmd( array( 'GET', $tmc_pc_key ) );
$tmc_pc_entry  = is_string( $tmc_pc_raw ) ? tmc_pc_decode( $tmc_pc_raw ) : null;
if ( $tmc_pc_entry ) {
	tmc_pc_serve( $tmc_pc_entry, $tmc_pc_method );
	exit;
}

if ( 'HEAD' === $tmc_pc_method ) {
	// A HEAD response has no body to store; let WordPress answer it.
	tmc_pc_label( 'BYPASS', 'head' );
} else {
	$GLOBALS['tmc_page_cache'] = array(
		'key'   => $tmc_pc_key,
		'url'   => $tmc_pc_request['url'],
		'host'  => $tmc_pc_request['host'],
		'store' => $tmc_pc_request['store'],
	);
	tmc_pc_label( 'MISS' );
	ob_start( 'tmc_pc_ob_callback' );
}
unset( $tmc_pc_request, $tmc_pc_generations, $tmc_pc_key, $tmc_pc_method, $tmc_pc_raw, $tmc_pc_entry );
