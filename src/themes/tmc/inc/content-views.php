<?php
/**
 * Presentation of the TMC content types (data model: mu-plugins/tmc-core/content-types.php).
 * Listing rules, formatting helpers, document links, status badges and .ics downloads.
 */

defined( 'ABSPATH' ) || exit;

/* ---------------------------------------------------------------- formatting */

function tmc_ts( $mysql ) {
	$date = $mysql ? date_create_immutable( $mysql, wp_timezone() ) : false;
	return $date ? $date->getTimestamp() : 0;
}

function tmc_format_datetime( $mysql, $with_time = true ) {
	$ts = tmc_ts( $mysql );
	return $ts ? wp_date( $with_time ? 'd/m/Y, h:i A' : 'd/m/Y', $ts ) : '';
}

function tmc_time_tag( $mysql, $with_time = true ) {
	$ts = tmc_ts( $mysql );
	return $ts ? sprintf( '<time datetime="%s">%s</time>', esc_attr( wp_date( 'c', $ts ) ), esc_html( tmc_format_datetime( $mysql, $with_time ) ) ) : '—';
}

function tmc_view() {
	return sanitize_key( wp_unslash( $_GET['view'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
}

/** Status badge for tenders, jobs and events. */
function tmc_status_badge( $post_id ) {
	$labels = array(
		'open'     => __( 'Open', 'tmc' ),
		'closed'   => __( 'Closed', 'tmc' ),
		'upcoming' => __( 'Upcoming', 'tmc' ),
		'ongoing'  => __( 'Ongoing', 'tmc' ),
		'past'     => __( 'Past', 'tmc' ),
	);
	$state = tmc_lifecycle( $post_id );
	return isset( $labels[ $state ] ) ? sprintf( '<span class="badge badge-%s">%s</span>', esc_attr( $state ), esc_html( $labels[ $state ] ) ) : '';
}

/** Documents with file type and size (GIGW: users must know what they are downloading). */
function tmc_documents_list( $ids ) {
	$ids = array_filter( array_map( 'intval', (array) $ids ) );
	if ( ! $ids ) {
		return '';
	}
	$html = '<ul class="doc-list">';
	foreach ( $ids as $id ) {
		$file = get_attached_file( $id );
		$ext  = $file ? strtoupper( pathinfo( $file, PATHINFO_EXTENSION ) ) : '';
		$size = ( $file && file_exists( $file ) ) ? size_format( filesize( $file ), 1 ) : '';
		$html .= sprintf(
			'<li><a class="doc-link" href="%s">%s</a> <span class="doc-meta">(%s)</span></li>',
			esc_url( wp_get_attachment_url( $id ) ),
			esc_html( get_the_title( $id ) ),
			esc_html( implode( ', ', array_filter( array( $ext, $size ) ) ) )
		);
	}
	return $html . '</ul>';
}

/** <dl> of label → value rows, skipping empty values. Values must already be escaped. */
function tmc_details_list( array $rows ) {
	$rows = array_filter( $rows, fn( $value ) => '' !== (string) $value && '—' !== $value );
	if ( ! $rows ) {
		return '';
	}
	$html = '<dl class="details">';
	foreach ( $rows as $label => $value ) {
		$html .= '<div><dt>' . esc_html( $label ) . '</dt><dd>' . $value . '</dd></div>';
	}
	return $html . '</dl>';
}

function tmc_department_links( $post_id ) {
	$links = array();
	foreach ( tmc_field( $post_id, 'tmc_department_ids' ) as $department_id ) {
		if ( 'publish' === get_post_status( $department_id ) ) {
			$links[] = sprintf( '<a href="%s">%s</a>', esc_url( get_permalink( $department_id ) ), esc_html( get_the_title( $department_id ) ) );
		}
	}
	return implode( ', ', $links );
}

/** Initials avatar for doctors without a photo. */
function tmc_initials( $name ) {
	$name  = trim( preg_replace( '/^(dr\.?|डॉ\.?)\s+/iu', '', $name ) );
	$parts = preg_split( '/\s+/u', $name );
	$first = mb_substr( $parts[0] ?? '', 0, 1 );
	$last  = count( $parts ) > 1 ? mb_substr( end( $parts ), 0, 1 ) : '';
	return mb_strtoupper( $first . $last );
}

/* ---------------------------------------------------------------- listing rules */

add_action( 'pre_get_posts', 'tmc_archive_queries' );
function tmc_archive_queries( WP_Query $query ) {
	if ( is_admin() || ! $query->is_main_query() ) {
		return;
	}

	if ( $query->is_post_type_archive( array( 'tmc_tender', 'tmc_job' ) ) ) {
		$archive = 'archive' === tmc_view();
		$query->set( 'posts_per_page', 20 );
		$query->set(
			'meta_query',
			$archive
				? array( array( 'key' => '_tmc_closing_at', 'value' => tmc_now(), 'compare' => '<', 'type' => 'DATETIME' ) )
				: array( tmc_current_clause( 'tmc_closing_at' ) )
		);
		$query->set( 'orderby', 'date' );
		$query->set( 'order', 'DESC' );
	}

	if ( $query->is_post_type_archive( 'tmc_event' ) ) {
		$past = 'past' === tmc_view();
		$query->set( 'posts_per_page', 20 );
		$query->set( 'meta_key', '_tmc_start_at' );
		$query->set( 'orderby', 'meta_value' );
		$query->set( 'order', $past ? 'DESC' : 'ASC' );
		$today = wp_date( 'Y-m-d 00:00:00' );
		$query->set(
			'meta_query',
			$past
				? array( array( 'key' => '_tmc_start_at', 'value' => $today, 'compare' => '<', 'type' => 'DATETIME' ) )
				: array( array( 'key' => '_tmc_start_at', 'value' => $today, 'compare' => '>=', 'type' => 'DATETIME' ) )
		);
	}

	if ( $query->is_post_type_archive( 'tmc_department' ) ) {
		$query->set( 'posts_per_page', 100 );
		$query->set( 'orderby', array( 'menu_order' => 'ASC', 'title' => 'ASC' ) );
	}

	if ( $query->is_post_type_archive( 'tmc_doctor' ) ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public filter form
		$department = absint( $_GET['department'] ?? 0 );
		$name       = sanitize_text_field( wp_unslash( $_GET['name'] ?? '' ) );
		// phpcs:enable
		$query->set( 'posts_per_page', 24 );
		$query->set( 'orderby', array( 'menu_order' => 'ASC', 'title' => 'ASC' ) );
		if ( $department ) {
			$query->set( 'meta_query', array( array( 'key' => '_tmc_department_ids', 'value' => $department, 'type' => 'NUMERIC' ) ) );
		}
		if ( '' !== $name ) {
			$query->set( 's', $name );
		}
	}
}

/* ---------------------------------------------------------------- iCalendar */

add_action( 'template_redirect', 'tmc_event_ics' );
function tmc_event_ics() {
	if ( ! is_singular( 'tmc_event' ) || ! isset( $_GET['ics'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}
	$post  = get_queried_object();
	$start = tmc_ts( tmc_field( $post->ID, 'tmc_start_at' ) );
	$end   = tmc_ts( tmc_field( $post->ID, 'tmc_end_at' ) );
	$end   = $end ? $end : $start + HOUR_IN_SECONDS;
	$text  = static fn( $value ) => str_replace( array( '\\', ';', ',', "\r\n", "\n" ), array( '\\\\', '\;', '\,', '\n', '\n' ), wp_strip_all_tags( html_entity_decode( (string) $value, ENT_QUOTES ) ) );
	$lines = array(
		'BEGIN:VCALENDAR',
		'VERSION:2.0',
		'PRODID:-//Tata Memorial Centre//Website//EN',
		'CALSCALE:GREGORIAN',
		'BEGIN:VEVENT',
		'UID:event-' . $post->ID . '@' . wp_parse_url( home_url(), PHP_URL_HOST ),
		'DTSTAMP:' . gmdate( 'Ymd\THis\Z' ),
		'DTSTART:' . gmdate( 'Ymd\THis\Z', $start ),
		'DTEND:' . gmdate( 'Ymd\THis\Z', $end ),
		'SUMMARY:' . $text( get_the_title( $post ) ),
		'LOCATION:' . $text( tmc_field( $post->ID, 'tmc_venue' ) ),
		'DESCRIPTION:' . $text( get_the_excerpt( $post ) ),
		'URL:' . get_permalink( $post ),
		'END:VEVENT',
		'END:VCALENDAR',
	);
	nocache_headers();
	header( 'Content-Type: text/calendar; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $post->post_name ) . '.ics"' );
	echo implode( "\r\n", $lines ) . "\r\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	exit;
}
