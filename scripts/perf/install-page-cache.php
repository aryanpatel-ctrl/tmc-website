<?php
/**
 * Install and verify the caching drop-ins, then purge the page cache (idempotent; run by
 * scripts/setup-cache.sh on every environment and every deploy).
 *
 *   wp-content/advanced-cache.php  two-line stub → mu-plugins/tmc-page-cache/engine.php (code stays in Git)
 *   wp-content/object-cache.php    Redis Object Cache drop-in (copied by `wp redis update-dropin` beforehand)
 *
 *   docker compose run --rm -T wpcli --url=<main site> eval-file /tmc-scripts/perf/install-page-cache.php
 */

$stub = <<<'PHP'
<?php
/**
 * TMC page cache drop-in — installed by scripts/perf/install-page-cache.php; do not edit here.
 * The engine is version-controlled in wp-content/mu-plugins/tmc-page-cache/engine.php.
 */
if ( is_readable( WP_CONTENT_DIR . '/mu-plugins/tmc-page-cache/engine.php' ) ) {
	require WP_CONTENT_DIR . '/mu-plugins/tmc-page-cache/engine.php';
}

PHP;

$target = WP_CONTENT_DIR . '/advanced-cache.php';
if ( ! is_file( $target ) || file_get_contents( $target ) !== $stub ) {
	if ( false === file_put_contents( $target, $stub, LOCK_EX ) ) {
		WP_CLI::error( "cannot write $target" );
	}
	chmod( $target, 0644 );
	WP_CLI::log( '  page cache drop-in installed' );
}

if ( ! defined( 'WP_CACHE' ) || ! WP_CACHE ) {
	WP_CLI::error( 'WP_CACHE is not true — check WORDPRESS_CONFIG_EXTRA in docker-compose.yml' );
}
if ( ! function_exists( 'tmc_pc_purge_network' ) ) {
	WP_CLI::error( 'page cache functions missing — is src/mu-plugins/tmc-page-cache mounted?' );
}
if ( 'PONG' !== tmc_pc_cmd( array( 'PING' ) ) ) {
	WP_CLI::error( 'Redis is not reachable for the page cache' );
}
if ( ! function_exists( 'tmc_object_cache_ok' ) || ! tmc_object_cache_ok() ) {
	WP_CLI::error( 'Redis object cache is not connected (run: wp redis status)' );
}

// Templates or code may have changed with this deploy: start every site with an empty page cache.
tmc_pc_purge_network();
WP_CLI::success( 'object cache connected; page cache installed and purged' );
