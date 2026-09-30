<?php
/**
 * Content types (tender §4.3, §4.6): Tenders & EOIs, Events, Careers, Departments, Doctors.
 *
 * The data model lives here (not in the theme) so content survives any change of design.
 * All types use the "post" capabilities, so the editorial roles and review workflow apply
 * unchanged: Content Editors draft and submit, Reviewer / Publishers approve.
 *
 * Fields are described once in tmc_field_schema(); the same schema drives meta registration
 * (REST-enabled), the editor meta boxes (content-fields.php) and validation.
 */

defined( 'ABSPATH' ) || exit;

function tmc_content_types() {
	return array(
		'tmc_tender'     => array( 'Tender / EOI', 'Tenders & EOIs', 'tenders', 'dashicons-media-document', array( 'title', 'editor', 'excerpt', 'revisions', 'author' ) ),
		'tmc_event'      => array( 'Event', 'Events', 'events', 'dashicons-calendar-alt', array( 'title', 'editor', 'excerpt', 'thumbnail', 'revisions', 'author' ) ),
		'tmc_job'        => array( 'Job opening', 'Careers', 'careers', 'dashicons-id-alt', array( 'title', 'editor', 'excerpt', 'revisions', 'author' ) ),
		'tmc_department' => array( 'Department', 'Departments', 'departments', 'dashicons-building', array( 'title', 'editor', 'excerpt', 'thumbnail', 'revisions', 'author', 'page-attributes' ) ),
		'tmc_doctor'     => array( 'Doctor', 'Doctors', 'doctors', 'dashicons-groups', array( 'title', 'editor', 'thumbnail', 'revisions', 'author', 'page-attributes' ) ),
	);
}

/**
 * Structured fields per post type. Keys are stored as protected meta ("_" + key).
 * Types: text, url, email, number, datetime (site time, "Y-m-d H:i:s"), select, documents, departments.
 */
function tmc_field_schema() {
	return array(
		'tmc_tender'     => array(
			'tmc_ref_no'     => array( 'label' => 'Reference number', 'type' => 'text', 'required' => true ),
			'tmc_kind'       => array(
				'label'   => 'Type',
				'type'    => 'select',
				'options' => array(
					'tender'      => 'Tender',
					'eoi'         => 'Expression of Interest (EOI)',
					'rfp'         => 'Request for Proposal (RFP)',
					'corrigendum' => 'Corrigendum',
				),
			),
			'tmc_closing_at' => array( 'label' => 'Last date and time of submission', 'type' => 'datetime', 'required' => true, 'help' => 'After this the tender moves to the archive automatically.' ),
			'tmc_opening_at' => array( 'label' => 'Bid opening date and time', 'type' => 'datetime' ),
			'tmc_portal_url' => array( 'label' => 'e-Procurement portal link (CPP / GeM)', 'type' => 'url' ),
			'tmc_documents'  => array( 'label' => 'Documents (tender document, corrigenda, annexures)', 'type' => 'documents' ),
		),
		'tmc_event'      => array(
			'tmc_start_at'         => array( 'label' => 'Starts', 'type' => 'datetime', 'required' => true ),
			'tmc_end_at'           => array( 'label' => 'Ends', 'type' => 'datetime' ),
			'tmc_venue'            => array( 'label' => 'Venue', 'type' => 'text' ),
			'tmc_registration_url' => array( 'label' => 'Registration link', 'type' => 'url' ),
		),
		'tmc_job'        => array(
			'tmc_ref_no'     => array( 'label' => 'Advertisement number', 'type' => 'text', 'required' => true ),
			'tmc_closing_at' => array( 'label' => 'Last date to apply', 'type' => 'datetime', 'required' => true, 'help' => 'After this the opening moves to the archive automatically.' ),
			'tmc_vacancies'  => array( 'label' => 'Number of posts', 'type' => 'number' ),
			'tmc_apply_url'  => array( 'label' => 'Online application link', 'type' => 'url' ),
			'tmc_documents'  => array( 'label' => 'Documents (advertisement, forms, results)', 'type' => 'documents' ),
		),
		'tmc_department' => array(
			'tmc_hod'      => array( 'label' => 'Head of department', 'type' => 'text' ),
			'tmc_location' => array( 'label' => 'Location (building / floor)', 'type' => 'text' ),
			'tmc_opd_days' => array( 'label' => 'OPD days and timings', 'type' => 'text' ),
			'tmc_phone'    => array( 'label' => 'Phone', 'type' => 'text' ),
			'tmc_email'    => array( 'label' => 'Email', 'type' => 'email' ),
		),
		'tmc_doctor'     => array(
			'tmc_designation'    => array( 'label' => 'Designation', 'type' => 'text', 'required' => true ),
			'tmc_department_ids' => array( 'label' => 'Departments', 'type' => 'departments' ),
			'tmc_qualifications' => array( 'label' => 'Qualifications', 'type' => 'text' ),
			'tmc_specialisation' => array( 'label' => 'Areas of specialisation', 'type' => 'text' ),
			'tmc_opd_days'       => array( 'label' => 'OPD days', 'type' => 'text' ),
		),
		'post'           => array(
			'tmc_expires_at' => array( 'label' => 'Expires on', 'type' => 'datetime', 'help' => 'Time-bound notices leave the notice board and news lists automatically after this. Leave empty to keep it current.' ),
		),
	);
}

add_action( 'init', 'tmc_register_content_types', 5 );
function tmc_register_content_types() {
	foreach ( tmc_content_types() as $type => list( $singular, $plural, $slug, $icon, $supports ) ) {
		register_post_type(
			$type,
			array(
				'labels'          => array(
					'name'               => $plural,
					'singular_name'      => $singular,
					'add_new_item'       => "Add $singular",
					'edit_item'          => "Edit $singular",
					'new_item'           => "New $singular",
					'view_item'          => "View $singular",
					'search_items'       => "Search $plural",
					'not_found'          => "No $plural found",
					'all_items'          => "All $plural",
					'archives'           => $plural,
					'menu_name'          => $plural,
				),
				'public'          => true,
				'has_archive'     => $slug,
				'rewrite'         => array( 'slug' => $slug, 'with_front' => false ),
				'menu_icon'       => $icon,
				'menu_position'   => 21,
				'supports'        => $supports,
				'show_in_rest'    => true,
				'hierarchical'    => false,
				'capability_type' => 'post',
				'map_meta_cap'    => true,
			)
		);
	}

	foreach ( tmc_field_schema() as $type => $fields ) {
		foreach ( $fields as $key => $field ) {
			tmc_register_field( $type, $key, $field );
		}
	}
}

function tmc_register_field( $type, $key, array $field ) {
	$args = array(
		'single'        => true,
		'type'          => 'string',
		'show_in_rest'  => true,
		'auth_callback' => fn( $allowed, $meta_key, $post_id ) => current_user_can( 'edit_post', $post_id ),
	);
	switch ( $field['type'] ) {
		case 'number':
			$args['type']              = 'integer';
			$args['sanitize_callback'] = 'absint';
			break;
		case 'url':
			$args['sanitize_callback'] = 'esc_url_raw';
			break;
		case 'email':
			$args['sanitize_callback'] = 'sanitize_email';
			break;
		case 'datetime':
			$args['sanitize_callback'] = 'tmc_sanitize_datetime';
			break;
		case 'documents':
			$args['type']         = 'array';
			$args['show_in_rest'] = array( 'schema' => array( 'type' => 'array', 'items' => array( 'type' => 'integer' ) ) );
			break;
		case 'departments':
			// One meta row per department, so doctors can be queried by department.
			$args['single']       = false;
			$args['type']         = 'integer';
			$args['show_in_rest'] = true;
			break;
		default:
			$args['sanitize_callback'] = 'sanitize_text_field';
	}
	register_post_meta( $type, '_' . $key, $args );
}

/** Accepts "Y-m-d H:i", "Y-m-d\TH:i" (datetime-local) or "Y-m-d H:i:s"; stores "Y-m-d H:i:s" in site time. */
function tmc_sanitize_datetime( $value ) {
	$value = trim( str_replace( 'T', ' ', (string) $value ) );
	if ( '' === $value ) {
		return '';
	}
	$date = date_create_immutable_from_format( 'Y-m-d H:i:s', strlen( $value ) === 16 ? $value . ':00' : $value, wp_timezone() );
	return $date ? $date->format( 'Y-m-d H:i:s' ) : '';
}

/** Field value (meta key without the leading underscore). */
function tmc_field( $post_id, $key ) {
	$schema = tmc_field_schema()[ get_post_type( $post_id ) ][ $key ] ?? array();
	if ( 'departments' === ( $schema['type'] ?? '' ) ) {
		return array_map( 'intval', get_post_meta( $post_id, '_' . $key, false ) );
	}
	return get_post_meta( $post_id, '_' . $key, true );
}

/** Current site time as "Y-m-d H:i:s" (for comparing with datetime fields). */
function tmc_now() {
	return current_time( 'mysql' );
}

/**
 * Lifecycle state of time-bound content.
 *   tender, job : open | closed
 *   event       : upcoming | ongoing | past
 *   post        : current | expired
 */
function tmc_lifecycle( $post_id ) {
	$now = tmc_now();
	switch ( get_post_type( $post_id ) ) {
		case 'tmc_tender':
		case 'tmc_job':
			$closing = tmc_field( $post_id, 'tmc_closing_at' );
			return ( $closing && $closing < $now ) ? 'closed' : 'open';
		case 'tmc_event':
			$start = tmc_field( $post_id, 'tmc_start_at' );
			$end   = tmc_field( $post_id, 'tmc_end_at' );
			$end   = $end ? $end : ( $start ? substr( $start, 0, 10 ) . ' 23:59:59' : '' );
			if ( $start && $start > $now ) {
				return 'upcoming';
			}
			return ( $end && $end < $now ) ? 'past' : 'ongoing';
		default:
			$expires = tmc_field( $post_id, 'tmc_expires_at' );
			return ( $expires && $expires < $now ) ? 'expired' : 'current';
	}
}

/** meta_query clause for "still current" items of a date field (empty date counts as current). */
function tmc_current_clause( $key, $compare = '>=' ) {
	return array(
		'relation' => 'OR',
		array( 'key' => '_' . $key, 'compare' => 'NOT EXISTS' ),
		array( 'key' => '_' . $key, 'value' => '', 'compare' => '=' ),
		array( 'key' => '_' . $key, 'value' => tmc_now(), 'compare' => $compare, 'type' => 'DATETIME' ),
	);
}

/** English + Hindi for every content type, set in code (not a setting an admin could switch off). */
add_filter(
	'pll_get_post_types',
	function ( $post_types, $is_settings ) {
		foreach ( array_keys( tmc_content_types() ) as $type ) {
			if ( $is_settings ) {
				unset( $post_types[ $type ] ); // shown as "managed programmatically" on the settings screen
			} else {
				$post_types[ $type ] = $type;
			}
		}
		return $post_types;
	},
	10,
	2
);
