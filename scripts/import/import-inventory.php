<?php
/**
 * Import a CSV content inventory into the current site (tender §4.11). Dry run unless mode=apply.
 * Documentation, column reference and procedure: docs/migration/importer.md.
 *
 *   docker compose run --rm -v "$PWD/migration-data:/import" wpcli --url=tmh.<base> \
 *     eval-file /tmc-scripts/import/import-inventory.php csv=/import/inventory.csv \
 *     [mode=dry-run|apply] [report=/import/report-tmh.csv] [base=/import] [author=<login>] [force=1]
 *
 *   csv     the inventory (UTF-8 CSV, header row; see docs/migration/content-inventory-template.csv)
 *   mode    dry-run (default: nothing is changed) or apply
 *   report  where to write the per-row result CSV (default: printed as a table)
 *   base    folder that content_html_file and documents paths are relative to (default: CSV folder)
 *   author  login that new content is attributed to (default: WP_ADMIN_USER)
 *   force   1 = also overwrite items edited in the CMS since the last import, and existing pages
 *           that the importer did not create
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}
require_once __DIR__ . '/class-tmc-inventory-importer.php';

$tmc_options = array();
foreach ( (array) ( $args ?? array() ) as $tmc_arg ) {
	if ( false !== strpos( (string) $tmc_arg, '=' ) ) {
		list( $tmc_key, $tmc_value )                    = explode( '=', (string) $tmc_arg, 2 );
		$tmc_options[ strtolower( trim( $tmc_key ) ) ] = trim( $tmc_value );
	} elseif ( in_array( $tmc_arg, array( 'apply', 'dry-run' ), true ) ) {
		$tmc_options['mode'] = $tmc_arg;
	}
}
if ( empty( $tmc_options['csv'] ) ) {
	WP_CLI::error( 'Usage: eval-file import-inventory.php csv=<inventory.csv> [mode=dry-run|apply] [report=<file.csv>] [base=<folder>] [author=<login>] [force=1]' );
}

$tmc_importer = new TMC_Inventory_Importer( $tmc_options );
WP_CLI::log( sprintf( '%s: importing %s for site "%s"%s', $tmc_importer->is_dry_run() ? 'DRY RUN' : 'APPLY', $tmc_options['csv'], TMC_Inventory_Importer::site_key(), $tmc_importer->is_dry_run() ? ' — nothing will be changed' : '' ) );
$tmc_rows = $tmc_importer->run();
if ( is_wp_error( $tmc_rows ) ) {
	WP_CLI::error( $tmc_rows->get_error_message() );
}

foreach ( $tmc_importer->warnings() as $tmc_warning ) {
	WP_CLI::warning( $tmc_warning );
}
if ( ! empty( $tmc_options['report'] ) ) {
	if ( $tmc_importer->write_report( $tmc_options['report'] ) ) {
		WP_CLI::log( 'Report written to ' . $tmc_options['report'] );
	} else {
		WP_CLI::warning( 'Could not write the report to ' . $tmc_options['report'] . '; printing it instead.' );
		unset( $tmc_options['report'] );
	}
}
if ( empty( $tmc_options['report'] ) ) {
	$tmc_table = array_map(
		fn( $row ) => array_map( fn( $v ) => is_array( $v ) ? implode( ' | ', $v ) : (string) $v, $row ),
		array_filter( $tmc_rows, fn( $row ) => 'skipped (other site)' !== $row['action'] )
	);
	if ( $tmc_table ) {
		WP_CLI\Utils\format_items( 'table', $tmc_table, TMC_Inventory_Importer::REPORT_COLUMNS );
	}
}

$tmc_counts = $tmc_importer->counts();
ksort( $tmc_counts );
WP_CLI::log( 'Summary: ' . implode( ', ', array_map( fn( $action, $count ) => "$action $count", array_keys( $tmc_counts ), $tmc_counts ) ) );
if ( ! empty( $tmc_counts['error'] ) ) {
	WP_CLI::warning( $tmc_counts['error'] . ' row(s) with errors — see the report.' );
	WP_CLI::halt( 2 );
}
WP_CLI::success( $tmc_importer->is_dry_run() ? 'Dry run complete. Run again with mode=apply to import.' : 'Import complete.' );
