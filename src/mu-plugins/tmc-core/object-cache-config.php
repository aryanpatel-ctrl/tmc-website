<?php
/**
 * Redis object cache settings (tender §4.7 peak load; CONVENTIONS: "object cache is Redis").
 *
 * The Redis Object Cache plugin (redis-cache, GPL-3.0, pinned in scripts/setup-cache.sh) provides
 * the wp-content/object-cache.php drop-in. Connection settings (WP_REDIS_HOST/PORT,
 * WP_CACHE_KEY_SALT) are in wp-config via docker-compose.yml. The constants below are read by the
 * plugin itself, which loads after must-use plugins:
 *   - no HTML comment in public pages and no promotional banners (tender §13: no vendor branding);
 *   - no per-request metrics written to Redis (avoidable load).
 */

defined( 'ABSPATH' ) || exit;

foreach ( array( 'WP_REDIS_DISABLE_COMMENT', 'WP_REDIS_DISABLE_BANNERS', 'WP_REDIS_DISABLE_DROPIN_BANNERS', 'WP_REDIS_DISABLE_METRICS' ) as $tmc_constant ) {
	if ( ! defined( $tmc_constant ) ) {
		define( $tmc_constant, true );
	}
}
unset( $tmc_constant );

/** True when WordPress is using the Redis drop-in and it is connected. */
function tmc_object_cache_ok() {
	global $wp_object_cache;
	return wp_using_ext_object_cache()
		&& is_object( $wp_object_cache )
		&& method_exists( $wp_object_cache, 'redis_status' )
		&& $wp_object_cache->redis_status();
}
