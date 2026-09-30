<?php
/**
 * Shared per-client rate limiting (tender §4.12: "authentication, rate control, and logging").
 *
 * One fixed window per bucket and client, starting at the client's first request. Used by the search
 * suggestions and the application gateway. The client IP (the real address, via mod_remoteip) is never
 * stored: keys hold a keyed hash of it. Counters are atomic in Redis when the object cache is
 * persistent, and fall back to transients otherwise.
 *
 * (Login throttling is a different mechanism — escalating lockouts per username and IP — and lives
 * in security-login.php.)
 */

defined( 'ABSPATH' ) || exit;

function tmc_rate_client_ip() {
	if ( defined( 'WP_CLI' ) && WP_CLI && empty( $_SERVER['REMOTE_ADDR'] ) ) {
		return 'cli';
	}
	return substr( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) ), 0, 45 );
}

function tmc_rate_key( $bucket ) {
	return 'tmc_rl_' . substr( hash_hmac( 'sha256', $bucket . '|' . tmc_rate_client_ip(), wp_salt( 'nonce' ) ), 0, 32 );
}

/**
 * Count one request from the current client against $limit per $window seconds.
 *
 * @return array{count:int,over:int,retry_after:int} over = requests beyond the limit in this window
 *         (0 = allowed, 1 = first refusal); retry_after = seconds until the window resets (upper bound).
 */
function tmc_rate_limit( $bucket, $limit, $window = MINUTE_IN_SECONDS ) {
	$key    = tmc_rate_key( $bucket );
	$window = max( 1, (int) $window );
	$now    = time();

	if ( wp_using_ext_object_cache() ) {
		$count = wp_cache_incr( $key, 1, 'tmc_rate' );
		if ( false === $count ) {
			// First request of the window; if another request created it first, count on that one.
			$count = wp_cache_add( $key, 1, 'tmc_rate', $window ) ? 1 : (int) wp_cache_incr( $key, 1, 'tmc_rate' );
		}
		$retry_after = $window;
	} else {
		$state = get_transient( $key );
		if ( ! is_array( $state ) || (int) ( $state['start'] ?? 0 ) + $window <= $now ) {
			$state = array( 'start' => $now, 'count' => 0 );
		}
		++$state['count'];
		$retry_after = max( 1, (int) $state['start'] + $window - $now );
		set_transient( $key, $state, $retry_after );
		$count = $state['count'];
	}

	$count = (int) $count;
	return array(
		'count'       => $count,
		'over'        => max( 0, $count - (int) $limit ),
		'retry_after' => (int) $retry_after,
	);
}

/** Forget the current client's count for a bucket (tests, and an administrator lifting a limit). */
function tmc_rate_limit_reset( $bucket ) {
	$key = tmc_rate_key( $bucket );
	wp_cache_delete( $key, 'tmc_rate' );
	delete_transient( $key );
}
