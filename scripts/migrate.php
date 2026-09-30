<?php
/**
 * Versioned, run-once data migrations for the current site (like database migrations).
 *
 * Each file in scripts/migrations/ runs once per site, in name order; applied names are stored in
 * the site option "tmc_migrations", so re-running is safe. A migration returns false to fail.
 * setup.sh runs this on every environment (local, CI, UAT) — changes to existing content ship as
 * migrations, never as manual edits on a server.
 *
 *   docker compose run --rm -T wpcli --url=<site> eval-file /tmc-scripts/migrate.php
 */

$applied = (array) get_option( 'tmc_migrations', array() );
$files   = glob( __DIR__ . '/migrations/*.php' );
sort( $files );

foreach ( $files as $file ) {
	$name = basename( $file, '.php' );
	if ( in_array( $name, $applied, true ) ) {
		continue;
	}
	WP_CLI::log( "  migration $name" );
	$result = ( static fn( $migration ) => require $migration )( $file );
	if ( false === $result ) {
		WP_CLI::error( "migration $name failed on " . home_url() );
	}
	$applied[] = $name;
	update_option( 'tmc_migrations', $applied, false );
	if ( function_exists( 'tmc_audit' ) ) {
		tmc_audit( 'migration_applied', array( 'object_type' => 'migration', 'object_title' => $name ) );
	}
}
WP_CLI::success( wp_parse_url( home_url(), PHP_URL_HOST ) . ': ' . count( $applied ) . ' migration(s) applied' );
