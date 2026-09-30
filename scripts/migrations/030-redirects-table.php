<?php
/**
 * 030 — Redirect manager: create the per-site redirects table (tmc-core/redirects.php).
 *
 * The module also creates the table on demand; running it here makes the schema change explicit
 * and recorded (tmc_migrations + audit log) on every existing and new site.
 */

if ( ! function_exists( 'tmc_redirects_install' ) ) {
	WP_CLI::warning( 'tmc-core redirects module not loaded' );
	return false;
}
if ( ! tmc_redirects_install() ) {
	WP_CLI::warning( 'could not create ' . tmc_redirects_table() );
	return false;
}
WP_CLI::log( '    redirects table ready: ' . tmc_redirects_table() );
return true;
