<?php
/**
 * Scheduled backups as WordPress sees them: the backup container has produced a verified backup
 * recently, its runs reach the tamper-evident audit log exactly once, and failures are recorded.
 * (Restoring a backup into a separate stack is proven by .github/workflows/dr-drill.yml.)
 *
 *   docker compose run --rm -T wpcli --url=tmh.<base> eval-file - < scripts/tests/backup-test.php
 */

global $wpdb;
$pass  = 0;
$fail  = 0;
$t     = function ( $label, $ok ) use ( &$pass, &$fail ) {
	WP_CLI::log( ( $ok ? '  PASS  ' : '  FAIL  ' ) . $label );
	$ok ? $pass++ : $fail++;
};
$table = tmc_backup_log_table();

WP_CLI::log( '— Backups produced by the backup container' );
$t( "backup log table $table exists", tmc_backup_log_exists() );
$last = tmc_backup_last( 'success' );
$t( 'at least one successful backup recorded', (bool) $last );
if ( $last ) {
	$age = tmc_backup_age( $last->finished_at );
	$t( "newest good backup {$last->name} finished {$age}s ago (limit " . tmc_health_limits()['backup_max_age'] . 's)', null !== $age && $age <= tmc_health_limits()['backup_max_age'] );
	$t( 'snapshot name is a UTC timestamp', 1 === preg_match( '/^[0-9]{8}T[0-9]{6}Z$/', $last->name ) );
	$t( 'checksums verified before the run was recorded', 1 === (int) $last->verified );
	$t( "database dump has content ({$last->db_bytes} bytes compressed)", (int) $last->db_bytes > 0 );
	$t( 'backup volume free space recorded (' . (int) $last->volume_free_percent . '%)', (int) $last->volume_free_percent > 0 );
	$t( 'retention keeps at least one snapshot', (int) $last->snapshots >= 1 );
	$gaps = $wpdb->get_col( $wpdb->prepare( "SELECT TIMESTAMPDIFF(SECOND, LAG(finished_at) OVER (ORDER BY id), finished_at) FROM {$table} WHERE status = 'success' AND finished_at > %s ORDER BY id", gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$gaps = array_map( 'intval', array_filter( $gaps, 'is_numeric' ) );
	// Information only: a developer machine that was switched off has gaps; production evidence is the DR drill report.
	WP_CLI::log( '  info  largest gap between good backups in the last 48 h: ' . ( $gaps ? max( $gaps ) . 's' : 'n/a (single run)' ) );
}

WP_CLI::log( '— Audit trail' );
$name   = 'test-' . strtolower( wp_generate_password( 8, false ) );
$before = (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id),0) FROM ' . tmc_audit_table() ); // before the insert: the cron heartbeat may import the row at any moment
$wpdb->insert(
	$table,
	array(
		'started_at'  => gmdate( 'Y-m-d H:i:s', time() - 5 ),
		'finished_at' => gmdate( 'Y-m-d H:i:s' ),
		'status'      => 'failed',
		'name'        => $name,
		'tier'        => 'test',
		'message'     => 'failed at step: database (test row)',
	)
);
$row_id = (int) $wpdb->insert_id;
tmc_backup_audit_import();
$logged = $wpdb->get_col( $wpdb->prepare( 'SELECT action FROM ' . tmc_audit_table() . ' WHERE id > %d AND object_type = %s AND object_title = %s', $before, 'backup', $name ) );
$t( 'a failed run is recorded in the audit log as backup_failed', array( 'backup_failed' ) === $logged );
$t( 'the run is marked as recorded', 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT audited FROM {$table} WHERE id = %d", $row_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
tmc_backup_audit_import();
$again = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . tmc_audit_table() . ' WHERE id > %d AND object_title = %s', $before, $name ) );
$t( 'importing again does not duplicate it', 1 === $again );
$t( 'real backup runs reach the audit log (backup_completed present)', (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . tmc_audit_table() . ' WHERE action = %s LIMIT 1', 'backup_completed' ) ) );
$newest = tmc_backup_last( 'success' ); // a scheduled backup may finish meanwhile: only the test row must never be it
$t( 'a failed run does not count as the newest good backup', ! $last || ( $newest && (int) $newest->id !== $row_id && 'success' === $newest->status ) );
$t( 'audit chain intact', tmc_audit_verify()['ok'] );
$wpdb->delete( $table, array( 'id' => $row_id ) ); // test row only; the audit entry stays, by design

WP_CLI::log( '— Off-host copies (scripts/backup/offsite-copy.sh → tmc-backup record-offsite)' );
$configured_before = tmc_backup_offsite_configured();
$fresh_name        = gmdate( 'Ymd\THis\Z', time() - 5 * MINUTE_IN_SECONDS );
$stale_name        = gmdate( 'Ymd\THis\Z', time() - 2 * HOUR_IN_SECONDS );
$offsite_row       = function ( $status, $name ) use ( $wpdb, $table ) {
	$wpdb->insert(
		$table,
		array(
			'started_at'  => gmdate( 'Y-m-d H:i:s', time() - 30 ),
			'finished_at' => gmdate( 'Y-m-d H:i:s' ),
			'status'      => $status,
			'name'        => $name,
			'tier'        => 'offsite',
			'verified'    => 'offsite-ok' === $status ? 1 : 0,
			'message'     => 'test row (backup-test.php)',
		)
	);
	return (int) $wpdb->insert_id;
};
$rows   = array();
$rows[] = $offsite_row( 'offsite-ok', $fresh_name );
$check  = tmc_health_report( true )['checks']['offsite'] ?? null;
$t( 'a recorded copy adds the "offsite" health check', is_array( $check ) );
$t( 'fresh verified copy → ok, age measured from the snapshot time (' . ( $check['age_seconds'] ?? 'none' ) . 's)', $check && true === $check['ok'] && abs( $check['age_seconds'] - 5 * MINUTE_IN_SECONDS ) <= 60 );
$rows[] = $offsite_row( 'offsite-failed', '' );
$check  = tmc_health_report( true )['checks']['offsite'];
$t( 'a failed copy after it does not hide the last good one (still ok)', true === $check['ok'] );
$newest = tmc_backup_last( 'success' );
$t( 'off-host copies do not count as backups', ! $last || ( $newest && ! in_array( (int) $newest->id, $rows, true ) && 'success' === $newest->status ) );
$rows[] = $offsite_row( 'offsite-ok', $stale_name );
$check  = tmc_health_report( true )['checks']['offsite'];
$t( 'newest copy holds 2-hour-old data → not ok (limit ' . tmc_health_limits()['offsite_max_age'] . 's)', false === $check['ok'] && $check['age_seconds'] >= 2 * HOUR_IN_SECONDS );
$before = (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id),0) FROM ' . tmc_audit_table() );
tmc_backup_audit_import();
$actions = $wpdb->get_col( $wpdb->prepare( 'SELECT action FROM ' . tmc_audit_table() . ' WHERE id > %d AND object_type = %s AND object_id IN (%d, %d, %d) ORDER BY id', $before, 'backup', $rows[0], $rows[1], $rows[2] ) );
// The cron heartbeat may have imported some of the rows already (before $before): all that were left must map correctly.
$t( 'copies reach the audit log as backup_offsite_copied / backup_offsite_failed', ! array_diff( $actions, array( 'backup_offsite_copied', 'backup_offsite_failed' ) ) && 3 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE audited = 1 AND id IN (%d, %d, %d)", $rows[0], $rows[1], $rows[2] ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
foreach ( $rows as $id ) {
	$wpdb->delete( $table, array( 'id' => $id ) );
}
wp_cache_delete( 'report', 'tmc_health' );
$t( 'without recorded copies the check disappears again (as before this test)', $configured_before === tmc_backup_offsite_configured() );
$t( 'audit chain intact after the imports', tmc_audit_verify()['ok'] );

WP_CLI::log( '' );
$fail ? WP_CLI::error( "$fail failed, $pass passed" ) : WP_CLI::success( "all $pass checks passed" );
