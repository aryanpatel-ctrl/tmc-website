<?php
/**
 * Performance layer: Redis object cache, full-page cache rules, purge wiring, and end-to-end HTTP
 * behaviour against the web container — MISS → HIT, 304, bypass for logged-in users / forms / POST /
 * search / 404 / password pages / unknown query strings, and purge on update, meta change, menu
 * change, theme settings, trash, across languages, for the umbrella site and for copies saved on
 * another unit site. Creates its own content and removes it.
 *
 *   docker compose run --rm -T wpcli --url=tmh.<base> eval-file - < scripts/tests/perf-test.php
 *
 * HTTP goes straight to the "wordpress" service on the compose network (TMC_TEST_HTTP_HOST overrides).
 */

$pass    = 0;
$fail    = 0;
$cleanup = array();
$t       = function ( $label, $ok ) use ( &$pass, &$fail ) {
	WP_CLI::log( ( $ok ? '  PASS  ' : '  FAIL  ' ) . $label );
	$ok ? $pass++ : $fail++;
};
$tag    = strtolower( wp_generate_password( 6, false ) );
$origin = getenv( 'TMC_TEST_HTTP_HOST' ) ? getenv( 'TMC_TEST_HTTP_HOST' ) : 'wordpress';

/** Minimal HTTP/1.0 client: exact control over Host and Cookie headers, no library quirks. */
$http = function ( $url, array $headers = array(), $method = 'GET', $body = '' ) use ( $origin ) {
	$parts = wp_parse_url( $url );
	$path  = ( $parts['path'] ?? '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
	$errno = 0;
	$error = '';
	$fp    = fsockopen( $origin, 80, $errno, $error, 10 );
	if ( ! $fp ) {
		return array( 'status' => 0, 'headers' => array(), 'body' => "connection failed: $error" );
	}
	stream_set_timeout( $fp, 60 );
	$request = "$method $path HTTP/1.0\r\nHost: {$parts['host']}\r\nUser-Agent: tmc-perf-test\r\nConnection: close\r\n";
	foreach ( $headers as $name => $value ) {
		$request .= "$name: $value\r\n";
	}
	if ( 'POST' === $method ) {
		$request .= "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen( $body ) . "\r\n";
	}
	fwrite( $fp, $request . "\r\n" . $body );
	$raw = (string) stream_get_contents( $fp );
	fclose( $fp );
	list( $head, $content ) = array_pad( explode( "\r\n\r\n", $raw, 2 ), 2, '' );
	$lines   = explode( "\r\n", $head );
	$status  = (int) ( explode( ' ', (string) array_shift( $lines ) )[1] ?? 0 );
	$parsed  = array();
	foreach ( $lines as $line ) {
		$colon = strpos( $line, ':' );
		if ( $colon ) {
			$parsed[ strtolower( substr( $line, 0, $colon ) ) ] = trim( substr( $line, $colon + 1 ) );
		}
	}
	return array( 'status' => $status, 'headers' => $parsed, 'body' => $content );
};
$state = fn( $r ) => $r['headers']['x-tmc-cache'] ?? 'none';
$why   = fn( $r ) => $state( $r ) . ( isset( $r['headers']['x-tmc-cache-reason'] ) ? ':' . $r['headers']['x-tmc-cache-reason'] : '' );
/** Request until the page is stored (first view may already be a HIT from an earlier request). */
$warm = function ( $url ) use ( $http, $state ) {
	$http( $url );
	return $state( $http( $url ) );
};

WP_CLI::log( '— Installation' );
$t( 'WP_CACHE is on and the page-cache drop-in is ours', tmc_page_cache_installed() );
$t( 'Redis reachable from WordPress (page cache DB)', 'PONG' === tmc_pc_cmd( array( 'PING' ) ) );
$t( 'Redis object cache drop-in connected' . ( defined( 'WP_REDIS_VERSION' ) ? ' (redis-cache ' . WP_REDIS_VERSION . ')' : '' ), tmc_object_cache_ok() );
wp_cache_set( "perf-$tag", 'persisted', 'tmc', 60 );
if ( method_exists( $GLOBALS['wp_object_cache'], 'flush_runtime' ) ) {
	$GLOBALS['wp_object_cache']->flush_runtime(); // forget the in-process copy: the next read must come from Redis
}
$t( 'object cache value survives outside this process (read back from Redis)', 'persisted' === wp_cache_get( "perf-$tag", 'tmc' ) );
wp_cache_delete( "perf-$tag", 'tmc' );
$t( 'no vendor HTML comment / banners / metrics from redis-cache', WP_REDIS_DISABLE_COMMENT && WP_REDIS_DISABLE_BANNERS && WP_REDIS_DISABLE_METRICS );
$t( 'Polylang language cookie disabled (language comes from the URL)', false === PLL_COOKIE );

WP_CLI::log( '— Request rules' );
$server = array( 'SCRIPT_FILENAME' => ABSPATH . 'index.php', 'REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'TMH.example.org:80', 'REQUEST_URI' => '/events/?month=2026-09&view=calendar&utm_source=x' );
$r      = tmc_pc_request( $server, array( 'pll_language' => 'en' ) );
$t( 'allowed parameters sorted into the key, tracking ignored, host normalised', $r['cacheable'] && 'http://tmh.example.org/events/?month=2026-09&view=calendar' === $r['url'] && ! $r['store'] );
$t( 'search, previews and unknown parameters are not cached', ! tmc_pc_request( array( 'REQUEST_URI' => '/?s=x' ) + $server, array() )['cacheable'] && ! tmc_pc_request( array( 'REQUEST_URI' => '/?p=1&preview=true' ) + $server, array() )['cacheable'] );
$t( 'logged-in / password cookies are never cached', 'cookie' === tmc_pc_request( $server, array( 'wordpress_logged_in_x' => '1' ) )['reason'] && 'cookie' === tmc_pc_request( $server, array( 'wp-postpass_x' => '1' ) )['reason'] );
$t( 'POST, REST API and wp-login.php are never cached', 'method' === tmc_pc_request( array( 'REQUEST_METHOD' => 'POST' ) + $server, array() )['reason'] && 'path' === tmc_pc_request( array( 'REQUEST_URI' => '/wp-json/wp/v2/pages' ) + $server, array() )['reason'] && 'script' === tmc_pc_request( array( 'SCRIPT_FILENAME' => ABSPATH . 'wp-login.php' ) + $server, array() )['reason'] );
$GLOBALS['tmc_page_cache_bypass'] = '';
wp_set_current_user( 0 );
wp_create_nonce( 'tmc-perf-test' );
$t( 'a nonce created for an anonymous visitor (a form) makes the page uncacheable', 'nonce' === tmc_page_cache_bypass_reason() );
$GLOBALS['tmc_page_cache_bypass'] = '';
foreach ( array( 'save_post', 'transition_post_status', 'updated_post_meta', 'set_object_terms', 'wp_update_nav_menu', 'customize_save_after', 'updated_option', 'wp_update_site' ) as $hook ) {
	$t( "purge wired to $hook", false !== has_action( $hook ) );
}

WP_CLI::log( '— End to end: MISS → HIT' );
$page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => "Perf test page $tag", 'post_content' => "<p>Perf body $tag</p>" ) );
pll_set_post_language( $page, 'en' );
$cleanup[] = $page;
$url       = get_permalink( $page );
$r1        = $http( $url );
$r2        = $http( $url );
$t( "first view MISS (HTTP {$r1['status']}, " . $why( $r1 ) . ')', 200 === $r1['status'] && 'MISS' === $state( $r1 ) && false !== strpos( $r1['body'], "Perf test page $tag" ) );
$t( 'second view HIT, identical page (' . $why( $r2 ) . ', Age ' . ( $r2['headers']['age'] ?? '?' ) . ')', 'HIT' === $state( $r2 ) && $r2['body'] === $r1['body'] );
$r = $http( $url, array( 'If-None-Match' => $r2['headers']['etag'] ?? 'none' ) );
$t( 'conditional request answered 304 from the cache (HTTP ' . $r['status'] . ')', 304 === $r['status'] && 'HIT' === $state( $r ) );
$r = $http( $url . '?utm_source=perf-test' );
$t( 'tracking parameters served from the cache (' . $why( $r ) . ')', 'HIT' === $state( $r ) );

WP_CLI::log( '— End to end: bypass' );
$r = $http( $url . '?nocache=' . $tag );
$t( 'unknown query string → BYPASS (' . $why( $r ) . ')', 'BYPASS' === $state( $r ) );
$r = $http( $url, array(), 'POST', 'x=1' );
$t( 'POST → BYPASS (' . $why( $r ) . ')', 'BYPASS' === $state( $r ) );
$r = $http( home_url( '/?s=' . $tag ) );
$t( 'search → BYPASS (' . $why( $r ) . ')', 'BYPASS' === $state( $r ) && 200 === $r['status'] );
$r = $http( home_url( "/no-such-page-$tag/" ) );
$t( '404 → BYPASS, never stored (HTTP ' . $r['status'] . ', ' . $why( $r ) . ')', 404 === $r['status'] && 'BYPASS' === $state( $r ) && 'BYPASS' === $state( $http( home_url( "/no-such-page-$tag/" ) ) ) );
$locked    = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => "Perf locked $tag", 'post_password' => 'secret-' . $tag, 'post_content' => "Locked body $tag" ) );
$cleanup[] = $locked;
pll_set_post_language( $locked, 'en' );
$http( get_permalink( $locked ) );
$r = $http( get_permalink( $locked ) );
$t( 'password-protected page → BYPASS on repeat (' . $why( $r ) . ')', 'BYPASS' === $state( $r ) && false === strpos( $r['body'], "Locked body $tag" ) );

$login = get_user_by( 'login', 'tmheditor' );
if ( ! $login ) {
	$admins = get_super_admins();
	$login  = get_user_by( 'login', reset( $admins ) );
}
$cookie = wp_generate_auth_cookie( $login->ID, time() + 600, 'logged_in' );
$r      = $http( $url, array( 'Cookie' => LOGGED_IN_COOKIE . '=' . rawurlencode( $cookie ) ) );
$t( "logged-in view ({$login->user_login}) → BYPASS, rendered for the user (" . $why( $r ) . ')', 'BYPASS' === $state( $r ) && preg_match( '/wpadminbar|class="[^"]*\blogged-in\b/', $r['body'] ) );
$r = $http( $url );
$t( 'next anonymous view is not personalised (' . $why( $r ) . ')', 'HIT' === $state( $r ) && ! preg_match( '/wpadminbar|class="[^"]*\blogged-in\b/', $r['body'] ) );
WP_Session_Tokens::get_instance( $login->ID )->destroy( wp_parse_auth_cookie( $cookie, 'logged_in' )['token'] );

WP_CLI::log( '— End to end: purge' );
wp_update_post( array( 'ID' => $page, 'post_title' => "Perf test page $tag updated" ) );
$r = $http( $url );
$t( 'after an update: MISS with the new title (' . $why( $r ) . ')', 'MISS' === $state( $r ) && false !== strpos( $r['body'], "Perf test page $tag updated" ) );
$t( 'then HIT again', 'HIT' === $state( $http( $url ) ) );

update_post_meta( $page, '_tmc_closed', current_time( 'mysql' ) ); // what the automatic-expiry job writes
$t( 'meta change of a published item purges (expiry flags, content fields)', 'MISS' === $state( $http( $url ) ) );

$menu = get_nav_menu_locations()['primary'] ?? 0;
if ( $menu ) {
	$warm( $url );
	$item      = wp_update_nav_menu_item( $menu, 0, array( 'menu-item-title' => "Perf menu $tag", 'menu-item-url' => home_url( '/' ), 'menu-item-type' => 'custom', 'menu-item-status' => 'publish' ) );
	$cleanup[] = $item;
	$r         = $http( $url );
	$t( 'menu change purges; new menu item visible (' . $why( $r ) . ')', 'MISS' === $state( $r ) && false !== strpos( $r['body'], "Perf menu $tag" ) );
} else {
	$t( 'primary menu exists', false );
}

$warm( $url );
set_theme_mod( 'tmc_perf_test', $tag ); // customizer and theme settings are stored as theme mods
$t( 'theme / customizer setting change purges', 'MISS' === $state( $http( $url ) ) );
remove_theme_mod( 'tmc_perf_test' );

$hindi = function_exists( 'pll_home_url' ) ? pll_home_url( 'hi' ) : home_url( '/hi/' );
$t( 'Hindi home page cached (' . $warm( $hindi ) . ')', 'HIT' === $warm( $hindi ) );
wp_update_post( array( 'ID' => $page, 'post_content' => "<p>Perf body $tag v3</p>" ) );
$t( 'English edit purges the Hindi pages of the same site', 'MISS' === $state( $http( $hindi ) ) );

$umbrella = get_home_url( get_main_site_id(), '/' );
$t( 'umbrella home cached (' . $umbrella . ')', 'HIT' === $warm( $umbrella ) );
wp_update_post( array( 'ID' => $page, 'post_excerpt' => "Perf excerpt $tag" ) );
$t( 'a unit edit purges the umbrella site too', 'MISS' === $state( $http( $umbrella ) ) );

$other = 0;
foreach ( get_sites( array( 'number' => 20, 'fields' => 'ids' ) ) as $id ) {
	if ( (int) $id !== get_current_blog_id() && (int) $id !== get_main_site_id() ) {
		$other = (int) $id;
		break;
	}
}
if ( $other ) {
	$other_home = get_home_url( $other, '/' );
	$t( "other unit home cached ($other_home)", 'HIT' === $warm( $other_home ) );
	switch_to_blog( $other ); // what network publishing does: save a copy in the target site
	$copy = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => "Perf network copy $tag" ) );
	restore_current_blog();
	$t( 'a copy saved on another site purges that site', 'MISS' === $state( $http( $other_home ) ) );
	switch_to_blog( $other );
	wp_delete_post( $copy, true );
	restore_current_blog();
}

$warm( $url );
wp_trash_post( $page );
$r = $http( $url );
$t( 'trashed page is gone at once, not served from the cache (HTTP ' . $r['status'] . ')', 404 === $r['status'] );

WP_CLI::log( '— Cleanup' );
foreach ( array_filter( $cleanup ) as $id ) {
	wp_delete_post( $id, true );
}

WP_CLI::log( '' );
$fail ? WP_CLI::error( "$fail failed, $pass passed" ) : WP_CLI::success( "all $pass checks passed" );
