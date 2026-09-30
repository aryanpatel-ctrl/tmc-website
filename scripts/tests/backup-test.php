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
$t( 'a failed run does not count as the newest good backup', ! $last || tmc_backup_last( 'success' )->name === $last->name );
$t( 'audit chain intact', tmc_audit_verify()['ok'] );
$wpdb->delete( $table, array( 'id' => $row_id ) ); // test row only; the audit entry stays, by design

WP_CLI::log( '' );
$fail ? WP_CLI::error( "$fail failed, $pass passed" ) : WP_CLI::success( "all $pass checks passed" );
