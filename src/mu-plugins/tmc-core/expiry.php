<?php
/**
 * Automatic expiry of time-bound content (tender §4.6).
 *
 * Listings decide "current vs archive" from the dates at request time (tmc_lifecycle()), so
 * nothing depends on the job below running on the minute. The job records each automatic
 * closure/expiry once in the tamper-evident audit log, which makes the transitions auditable.
 * Runs every 5 minutes via the "cron" container (real cron; WP-Cron page hits are disabled).
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'cron_schedules',
	function ( $schedules ) {
		$schedules['tmc_five_minutes'] = array( 'interval' => 5 * MINUTE_IN_SECONDS, 'display' => 'Every 5 minutes' );
		return $schedules;
	}
);

add_action(
	'init',
	function () {
		if ( ! wp_next_scheduled( 'tmc_expire_content' ) ) {
			wp_schedule_event( time() + 60, 'tmc_five_minutes', 'tmc_expire_content' );
		}
	}
);

add_action( 'tmc_expire_content', 'tmc_expire_content' );
function tmc_expire_content() {
	$rules = array(
		// post type, date field, flag, audit action
		array( 'post', 'tmc_expires_at', '_tmc_expired', 'content_expired' ),
		array( 'tmc_tender', 'tmc_closing_at', '_tmc_closed', 'tender_closed' ),
		array( 'tmc_job', 'tmc_closing_at', '_tmc_closed', 'job_opening_closed' ),
	);
	$done = 0;
	foreach ( $rules as list( $type, $field, $flag, $action ) ) {
		$ids = get_posts(
			array(
				'post_type'      => $type,
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'lang'           => '',
				'meta_query'     => array(
					'relation' => 'AND',
					array( 'key' => '_' . $field, 'value' => tmc_now(), 'compare' => '<', 'type' => 'DATETIME' ),
					array( 'key' => '_' . $field, 'value' => '', 'compare' => '!=' ),
					array( 'key' => $flag, 'compare' => 'NOT EXISTS' ),
				),
			)
		);
		foreach ( $ids as $id ) {
			update_post_meta( $id, $flag, tmc_now() );
			if ( function_exists( 'tmc_audit' ) ) {
				tmc_audit(
					$action,
					array(
						'user_id'      => 0,
						'user_login'   => 'system',
						'object_type'  => $type,
						'object_id'    => $id,
						'object_title' => get_the_title( $id ),
						'details'      => array( 'date' => tmc_field( $id, $field ), 'automatic' => true ),
					)
				);
			}
			++$done;
		}
	}
	return $done;
}

// Re-opening (date moved into the future) clears the flag so a later closure is logged again.
add_action(
	'updated_post_meta',
	function ( $meta_id, $post_id, $meta_key, $value ) {
		$flags = array( '_tmc_expires_at' => '_tmc_expired', '_tmc_closing_at' => '_tmc_closed' );
		if ( isset( $flags[ $meta_key ] ) && $value && $value > tmc_now() ) {
			delete_post_meta( $post_id, $flags[ $meta_key ] );
		}
	},
	10,
	4
);
