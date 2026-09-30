<?php
/**
 * Operational health: endpoint for uptime monitors, cron heartbeat, backup status and audit trail
 * (tender §4.7 RPO/RTO and scheduled backups, §6 SLA 99.5 % availability).
 *
 * GET /wp-json/tmc/v1/health — public (monitors need no login), never cached, cheap (result reused
 * for 10 seconds). It reports booleans and ages only: no versions, host names, paths or secrets.
 *
 *   {"status":"ok","time":"2026-09-30T10:00:00Z","checks":{
 *     "database":{"ok":true}, "redis":{"ok":true}, "object_cache":{"ok":true}, "page_cache":{"ok":true},
 *     "cron":{"ok":true,"age_seconds":41}, "backup":{"ok":true,"age_seconds":312}, "disk":{"ok":true}}}
 *
 * HTTP 200 when every check passes, 503 when any fails (so a plain HTTP monitor alerts). Limits:
 * cron heartbeat ≤ 10 min, newest good backup ≤ 30 min (two 15-minute intervals), ≥ 10 % free disk
 * for WordPress files and for the backup volume. See docs/operations/monitoring.md.
 *
 * Also here:
 *   - a one-minute cron heartbeat on the main site (proves the cron container is running);
 *   - copying each backup run (written by the backup container to <prefix>tmc_backup_log) into the
 *     tamper-evident audit log as backup_completed / backup_failed;
 *   - Network Admin → Health & Backups: current checks, recent backups, purge page cache.
 */

defined( 'ABSPATH' ) || exit;

/** Limits used by the checks (seconds / percent). Filterable for other environments. */
function tmc_health_limits() {
	return (array) apply_filters(
		'tmc_health_limits',
		array(
			'cron_max_age'     => 10 * MINUTE_IN_SECONDS,
			'backup_max_age'   => 30 * MINUTE_IN_SECONDS,
			'disk_min_free_pc' => 10,
		)
	);
}

/* ================================================================ backup log (written by backup/tmc-backup.sh) */

function tmc_backup_log_table() {
	global $wpdb;
	return $wpdb->base_prefix . 'tmc_backup_log';
}

function tmc_backup_log_exists() {
	global $wpdb;
	$table = tmc_backup_log_table();
	return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
}

/** Newest backup run with the given status, or null. */
function tmc_backup_last( $status = 'success' ) {
	global $wpdb;
	if ( ! tmc_backup_log_exists() ) {
		return null;
	}
	$table = tmc_backup_log_table();
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY id DESC LIMIT 1", $status ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

function tmc_backup_recent( $limit = 20 ) {
	global $wpdb;
	if ( ! tmc_backup_log_exists() ) {
		return array();
	}
	$table = tmc_backup_log_table();
	return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

/** Seconds since a UTC DATETIME from the backup log. */
function tmc_backup_age( $utc_datetime ) {
	$time = strtotime( $utc_datetime . ' UTC' );
	return $time ? max( 0, time() - $time ) : null;
}

/**
 * Copy new backup runs into the audit log (each exactly once). Runs from the cron heartbeat.
 *
 * @return int Number of runs recorded.
 */
function tmc_backup_audit_import( $limit = 50 ) {
	global $wpdb;
	if ( ! function_exists( 'tmc_audit' ) || ! tmc_backup_log_exists() ) {
		return 0;
	}
	$table = tmc_backup_log_table();
	$done  = 0;
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->base_prefix
	foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE audited = 0 ORDER BY id ASC LIMIT %d", $limit ) ) as $id ) {
		// Claim the row first, so two overlapping cron runs can never record it twice.
		if ( 1 !== (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET audited = 1 WHERE id = %d AND audited = 0", $id ) ) ) {
			continue;
		}
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
		tmc_audit(
			'success' === $row->status ? 'backup_completed' : 'backup_failed',
			array(
				'user_id'      => 0,
				'user_login'   => 'system',
				'object_type'  => 'backup',
				'object_id'    => (int) $row->id,
				'object_title' => $row->name,
				'details'      => array(
					'tier'             => $row->tier,
					'started_utc'      => $row->started_at,
					'finished_utc'     => $row->finished_at,
					'database_bytes'   => (int) $row->db_bytes,
					'files_total'      => (int) $row->files_total,
					'files_new'        => (int) $row->files_new,
					'duration_seconds' => (int) $row->duration_seconds,
					'snapshots_kept'   => (int) $row->snapshots,
					'verified'         => (bool) $row->verified,
					'message'          => $row->message,
				),
			)
		);
		++$done;
	}
	// phpcs:enable
	return $done;
}

/* ================================================================ cron heartbeat */

add_filter(
	'cron_schedules',
	function ( $schedules ) {
		$schedules['tmc_every_minute'] = array( 'interval' => MINUTE_IN_SECONDS, 'display' => 'Every minute' );
		return $schedules;
	}
);

add_action(
	'init',
	function () {
		if ( is_main_site() && ! wp_next_scheduled( 'tmc_cron_heartbeat' ) ) {
			wp_schedule_event( time(), 'tmc_every_minute', 'tmc_cron_heartbeat' );
		}
	}
);

add_action( 'tmc_cron_heartbeat', 'tmc_cron_heartbeat' );
function tmc_cron_heartbeat() {
	update_site_option( 'tmc_cron_heartbeat', time() );
	tmc_backup_audit_import();
}

/* ================================================================ checks */

/** Free space as a percentage of the file system holding $path, or null if unknown. */
function tmc_health_free_percent( $path ) {
	$free  = function_exists( 'disk_free_space' ) ? @disk_free_space( $path ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
	$total = function_exists( 'disk_total_space' ) ? @disk_total_space( $path ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
	return ( $free && $total ) ? 100 * $free / $total : null;
}

/**
 * Run every check.
 *
 * @param bool $fresh Skip the 10-second cache (tests, admin screen).
 * @return array{status:string,time:string,checks:array}
 */
function tmc_health_report( $fresh = false ) {
	if ( ! $fresh ) {
		$cached = wp_cache_get( 'report', 'tmc_health' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
	}
	global $wpdb;
	$limits = tmc_health_limits();
	$checks = array();

	$checks['database'] = array( 'ok' => '1' === (string) $wpdb->get_var( 'SELECT 1' ) );

	$checks['redis'] = array( 'ok' => 'PONG' === tmc_pc_cmd( array( 'PING' ) ) );

	$checks['object_cache'] = array( 'ok' => function_exists( 'tmc_object_cache_ok' ) && tmc_object_cache_ok() );

	$checks['page_cache'] = array( 'ok' => function_exists( 'tmc_page_cache_installed' ) && tmc_page_cache_installed() && $checks['redis']['ok'] );

	$beat            = (int) get_site_option( 'tmc_cron_heartbeat', 0 );
	$cron_age        = $beat ? max( 0, time() - $beat ) : null;
	$checks['cron']  = array( 'ok' => null !== $cron_age && $cron_age <= $limits['cron_max_age'], 'age_seconds' => $cron_age );

	$backup           = tmc_backup_last( 'success' );
	$backup_age       = $backup ? tmc_backup_age( $backup->finished_at ) : null;
	$checks['backup'] = array( 'ok' => null !== $backup_age && $backup_age <= $limits['backup_max_age'], 'age_seconds' => $backup_age );

	$web_free       = tmc_health_free_percent( WP_CONTENT_DIR );
	$backup_free    = $backup ? (int) $backup->volume_free_percent : null;
	$checks['disk'] = array( 'ok' => null !== $web_free && $web_free >= $limits['disk_min_free_pc'] && ( null === $backup_free || $backup_free >= $limits['disk_min_free_pc'] ) );

	$ok     = ! in_array( false, wp_list_pluck( $checks, 'ok' ), true );
	$report = array(
		'status' => $ok ? 'ok' : 'fail',
		'time'   => gmdate( 'Y-m-d\TH:i:s\Z' ),
		'checks' => $checks,
	);
	wp_cache_set( 'report', $report, 'tmc_health', 10 );
	return $report;
}

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'tmc/v1',
			'/health',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true', // public by design: booleans and ages only
				'callback'            => function () {
					$report   = tmc_health_report();
					$response = new WP_REST_Response( $report, 'ok' === $report['status'] ? 200 : 503 );
					$response->header( 'Cache-Control', 'no-store, max-age=0' );
					return $response;
				},
			)
		);
	}
);

/* ================================================================ Network Admin → Health & Backups */

add_action(
	'network_admin_menu',
	function () {
		add_menu_page( 'Health & Backups', 'Health & Backups', 'manage_network_options', 'tmc-health', 'tmc_health_admin_page', 'dashicons-heart', 5 );
	}
);

/** Purge the page cache of every site (button on the screen below). */
function tmc_health_purge_all() {
	$ok = tmc_pc_purge_network();
	tmc_audit( 'page_cache_purged', array( 'object_type' => 'page_cache', 'object_title' => 'network', 'details' => array( 'ok' => $ok ) ) );
	return $ok;
}

add_action(
	'network_admin_edit_tmc_page_cache_purge',
	function () {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( 'You are not allowed to purge the page cache.', 403 );
		}
		check_admin_referer( 'tmc_page_cache_purge' );
		$ok = tmc_health_purge_all();
		wp_safe_redirect( add_query_arg( array( 'page' => 'tmc-health', 'purged' => $ok ? '1' : '0' ), network_admin_url( 'admin.php' ) ) );
		exit;
	}
);

function tmc_health_admin_page() {
	if ( ! current_user_can( 'manage_network_options' ) ) {
		wp_die( 'You are not allowed to view this page.', 403 );
	}
	$report = tmc_health_report( true );
	$labels = array(
		'database'     => 'Database',
		'redis'        => 'Redis (cache server)',
		'object_cache' => 'Object cache',
		'page_cache'   => 'Page cache',
		'cron'         => 'Scheduled jobs (cron container)',
		'backup'       => 'Newest good backup',
		'disk'         => 'Free disk space (web files and backup volume)',
	);
	$age    = static function ( $seconds ) {
		return null === $seconds ? 'never' : human_time_diff( time() - (int) $seconds, time() ) . ' ago';
	};

	echo '<div class="wrap"><h1>Health &amp; Backups</h1>';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display of a redirect flag only
	if ( isset( $_GET['purged'] ) ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$purged = '1' === $_GET['purged'];
		printf( '<div class="notice %s" role="status"><p>%s</p></div>', $purged ? 'notice-success' : 'notice-error', esc_html( $purged ? 'The page cache of every site was purged.' : 'The page cache could not be purged: the cache server is unreachable.' ) );
	}

	printf(
		'<p>Overall: <strong>%s</strong>. Uptime monitors use <code>%s</code> (HTTP 200 when every check passes, 503 otherwise).</p>',
		esc_html( 'ok' === $report['status'] ? 'all checks pass' : 'one or more checks fail' ),
		esc_html( rest_url( 'tmc/v1/health' ) )
	);

	echo '<table class="widefat striped" style="max-width:900px"><caption class="screen-reader-text">Current health checks</caption><thead><tr><th scope="col">Check</th><th scope="col">Result</th><th scope="col">Detail</th></tr></thead><tbody>';
	foreach ( $report['checks'] as $key => $check ) {
		printf(
			'<tr><th scope="row">%s</th><td><span class="dashicons %s" aria-hidden="true"></span> %s</td><td>%s</td></tr>',
			esc_html( $labels[ $key ] ?? $key ),
			$check['ok'] ? 'dashicons-yes-alt' : 'dashicons-warning',
			esc_html( $check['ok'] ? 'OK' : 'Problem' ),
			esc_html( array_key_exists( 'age_seconds', $check ) ? 'Last seen ' . $age( $check['age_seconds'] ) : '' )
		);
	}
	echo '</tbody></table>';

	echo '<h2>Recent backups</h2><p>Taken every 15 minutes by the backup container; kept every 15 minutes for 48 hours, daily for 30 days and monthly for 12 months. Restore procedure: <code>docs/operations/backup-and-dr.md</code>.</p>';
	$runs = tmc_backup_recent( 20 );
	echo '<table class="widefat striped"><caption class="screen-reader-text">Twenty most recent backup runs</caption><thead><tr><th scope="col">Finished (IST)</th><th scope="col">Result</th><th scope="col">Snapshot</th><th scope="col">Type</th><th scope="col">Database</th><th scope="col">Files (new / total)</th><th scope="col">Duration</th><th scope="col">Message</th></tr></thead><tbody>';
	if ( ! $runs ) {
		echo '<tr><td colspan="8">No backup has been recorded yet. Check that the backup container is running.</td></tr>';
	}
	foreach ( $runs as $run ) {
		printf(
			'<tr><td>%s</td><td>%s</td><td><code>%s</code></td><td>%s</td><td>%s</td><td>%s / %s</td><td>%s s</td><td>%s</td></tr>',
			esc_html( wp_date( 'd/m/Y H:i:s', (int) strtotime( $run->finished_at . ' UTC' ) ) ),
			esc_html( 'success' === $run->status ? 'Success (verified)' : 'Failed' ),
			esc_html( $run->name ),
			esc_html( $run->tier ),
			esc_html( size_format( (int) $run->db_bytes ) ),
			esc_html( number_format_i18n( (int) $run->files_new ) ),
			esc_html( number_format_i18n( (int) $run->files_total ) ),
			esc_html( number_format_i18n( (int) $run->duration_seconds ) ),
			esc_html( $run->message )
		);
	}
	echo '</tbody></table>';

	echo '<h2>Page cache</h2><p>Pages are purged automatically when content, menus or settings change. Use this after an emergency change made outside WordPress.</p>';
	printf( '<form method="post" action="%s">', esc_url( network_admin_url( 'edit.php?action=tmc_page_cache_purge' ) ) );
	wp_nonce_field( 'tmc_page_cache_purge' );
	submit_button( 'Purge page cache on all sites', 'secondary', 'submit', false );
	echo '</form></div>';
}
