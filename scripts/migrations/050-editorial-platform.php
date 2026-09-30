<?php
/**
 * 050 — Editorial platform on existing sites (W5).
 *
 *   1. Audience entry points (R-1-1): "For referring doctors" under Patient Care and
 *      "Students & researchers" under Education, in the English main menu and footer quick links.
 *   2. The living component library page (/component-library/, noindex) on the TMC site (M3).
 *
 * Idempotent; the same helper runs at the end of seed-site-structure.php for fresh installs
 * (where this migration runs before the menus exist). Page templates, block governance and
 * network publishing are code only and need no data change.
 */

if ( ! function_exists( 'tmc_ensure_editorial_ia' ) ) {
	WP_CLI::warning( 'tmc_ensure_editorial_ia() missing: is the tmc-core mu-plugin up to date?' );
	return false;
}

$result = tmc_ensure_editorial_ia();
if ( is_wp_error( $result ) ) {
	WP_CLI::warning( '050: ' . $result->get_error_message() );
	return false;
}
foreach ( $result as $slug => $id ) {
	WP_CLI::log( "    /$slug/ (page #$id)" );
}
return true;
