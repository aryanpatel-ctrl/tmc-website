<?php
/**
 * Redirect manager — CSV import and export (R-4.10-5, R-4.11-4).
 *
 * Rules and the functions they use are in redirects.php; the admin screen is in redirects-admin.php.
 */

defined( 'ABSPATH' ) || exit;

/* ================================================================ CSV import / export */

const TMC_REDIRECT_CSV_COLUMNS = array( 'source', 'target', 'status', 'regex', 'note' );

/** Undo the formula protection added on export ("'=..." → "=..."). */
function tmc_redirect_csv_value( $value ) {
	$value = trim( (string) $value );
	return preg_match( "/^'[=+\-@]/", $value ) ? substr( $value, 1 ) : $value;
}

/**
 * Import rules from a CSV file with the header source,target,status[,regex][,note].
 *
 * @param string $file            Path to the CSV file.
 * @param bool   $update_existing Update rules whose source already exists (otherwise skip them).
 * @param string $label           File name for the audit log.
 * @return array{created:int,updated:int,skipped:int,errors:array<int,string>,warnings:array<int,string>}|WP_Error
 */
function tmc_redirects_import_csv( $file, $update_existing = false, $label = '' ) {
	global $wpdb;
	$handle = is_readable( $file ) ? fopen( $file, 'r' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( ! $handle ) {
		return new WP_Error( 'tmc_redirect_csv', 'The file could not be read.' );
	}
	$header = fgetcsv( $handle, 0, ',', '"', '' );
	if ( ! $header ) {
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return new WP_Error( 'tmc_redirect_csv', 'The file is empty.' );
	}
	$header    = array_map( fn( $h ) => strtolower( trim( preg_replace( '/^\xEF\xBB\xBF/', '', (string) $h ) ) ), $header );
	$positions = array_flip( $header );
	if ( ! isset( $positions['source'], $positions['status'] ) || ! isset( $positions['target'] ) ) {
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return new WP_Error( 'tmc_redirect_csv', 'The first line must be the header: source,target,status (optional: regex,note).' );
	}
	$result = array( 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => array(), 'warnings' => array() );
	$table  = tmc_redirects_table();
	$line   = 1;
	while ( ( $cells = fgetcsv( $handle, 0, ',', '"', '' ) ) !== false ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
		++$line;
		if ( array( null ) === $cells || '' === trim( implode( '', $cells ) ) ) {
			continue;
		}
		if ( $line > 20001 ) {
			$result['errors'][ $line ] = 'Stopped: at most 20,000 rules per file.';
			break;
		}
		$get      = fn( $column ) => isset( $positions[ $column ] ) ? tmc_redirect_csv_value( $cells[ $positions[ $column ] ] ?? '' ) : '';
		$is_regex = in_array( strtolower( $get( 'regex' ) ), array( '1', 'yes', 'true' ), true );
		$data     = array(
			'source'   => $get( 'source' ),
			'target'   => $get( 'target' ),
			'status'   => (int) $get( 'status' ),
			'is_regex' => $is_regex,
			'note'     => $get( 'note' ),
		);
		$existing_id = 0;
		if ( ! $is_regex ) {
			$parts = tmc_redirect_split( $data['source'] );
			if ( ! is_wp_error( $parts ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$existing_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE source_key = %s", sha1( tmc_redirect_key( $parts['path'], $parts['query'] ) ) ) );
			}
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$existing_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE source_key = %s", sha1( 'regex:' . trim( $data['source'] ) ) ) );
		}
		if ( $existing_id && ! $update_existing ) {
			++$result['skipped'];
			continue;
		}
		$saved = tmc_redirect_save( $data, $existing_id, false );
		if ( is_wp_error( $saved ) ) {
			$result['errors'][ $line ] = $saved->get_error_message();
			continue;
		}
		$saved['created'] ? ++$result['created'] : ++$result['updated'];
		foreach ( $saved['warnings'] as $warning ) {
			$result['warnings'][ $line ] = $warning;
		}
	}
	fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( function_exists( 'tmc_audit' ) ) {
		tmc_audit(
			'redirects_imported',
			array(
				'object_type'  => 'redirect',
				'object_title' => $label ? $label : basename( $file ),
				'details'      => array(
					'created'  => $result['created'],
					'updated'  => $result['updated'],
					'skipped'  => $result['skipped'],
					'errors'   => count( $result['errors'] ),
					'sha256'   => hash_file( 'sha256', $file ),
				),
			)
		);
	}
	return $result;
}

/** Rows for export, oldest first. */
function tmc_redirects_all() {
	global $wpdb;
	$table = tmc_redirects_table();
	return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

/** Write all rules as CSV to a stream (formula-looking cells are neutralised). */
function tmc_redirects_write_csv( $out ) {
	$safe = static fn( $v ) => preg_match( '/^[=+\-@\t\r]/', (string) $v ) ? "'" . $v : $v;
	fputcsv( $out, array_merge( TMC_REDIRECT_CSV_COLUMNS, array( 'hits', 'last_hit_utc', 'created_utc', 'updated_utc' ) ), ',', '"', '' );
	foreach ( tmc_redirects_all() as $rule ) {
		fputcsv(
			$out,
			array_map( $safe, array( $rule->source, $rule->target, $rule->status, $rule->is_regex ? '1' : '0', $rule->note, $rule->hits, (string) $rule->last_hit, $rule->created_at, $rule->updated_at ) ),
			',',
			'"',
			''
		);
	}
}
