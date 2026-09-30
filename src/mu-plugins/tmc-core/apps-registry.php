<?php
/**
 * TMC application gateway — action catalogue and service registry (R-4.4-2).
 *
 * The catalogue fixes, per service type, each action's method, path and input schema; the network
 * registry (Network Admin → Settings → TMC applications) decides which services exist, where they are
 * and which actions are enabled. The gateway itself is in apps-gateway.php.
 */

defined( 'ABSPATH' ) || exit;

/* ================================================================ action catalogue */

/**
 * What each type of TMC service can do. The registry decides which services exist, where they are
 * and which of these actions are enabled; this catalogue fixes the method, path and input schema.
 *
 * Field types: text, name, textarea, email, mobile, tel, date, code, token, enum, consent, bool,
 * amount, integer, url. Path placeholders {field} are filled from validated input.
 */
function tmc_apps_catalogue() {
	$department = array( 'type' => 'code', 'label' => __( 'Department', 'tmc' ), 'required' => true, 'choice' => true );
	$date       = array( 'type' => 'date', 'label' => __( 'Preferred date', 'tmc' ), 'required' => true, 'min_days' => 1, 'max_days' => 90 );
	$order      = array( 'type' => 'token', 'label' => __( 'Order reference', 'tmc' ), 'required' => true );

	return array(
		'appointments' => array(
			'label'   => 'Appointments',
			'actions' => array(
				'departments' => array(
					'method' => 'GET',
					'path'   => 'departments',
					'map'    => 'tmc_apps_map_departments',
					'cache'  => 10 * MINUTE_IN_SECONDS,
				),
				'slots'       => array(
					'method' => 'GET',
					'path'   => 'slots',
					'fields' => array( 'department' => $department, 'date' => $date ),
					'map'    => 'tmc_apps_map_slots',
					'errors' => array( 404 => __( 'Appointments are not available for this department online. Please contact the hospital.', 'tmc' ) ),
				),
				'request'     => array(
					'method'  => 'POST',
					'path'    => 'requests',
					'fields'  => array(
						'department'      => $department,
						'date'            => $date,
						'slot'            => array( 'type' => 'code', 'label' => __( 'Appointment time', 'tmc' ), 'required' => true, 'choice' => true ),
						'patient_type'    => array(
							'type'     => 'enum',
							'label'    => __( 'Patient type', 'tmc' ),
							'required' => true,
							'options'  => array(
								'new'        => __( 'New patient', 'tmc' ),
								'registered' => __( 'Already registered at this hospital', 'tmc' ),
							),
						),
						'registration_no' => array( 'type' => 'text', 'label' => __( 'Hospital registration number', 'tmc' ), 'max' => 20, 'pattern' => '/^[A-Za-z0-9\/-]+$/', 'upper' => true ),
						'patient_name'    => array( 'type' => 'name', 'label' => __( "Patient's full name", 'tmc' ), 'required' => true, 'max' => 100 ),
						'mobile'          => array( 'type' => 'mobile', 'label' => __( 'Mobile number', 'tmc' ), 'required' => true ),
						'email'           => array( 'type' => 'email', 'label' => __( 'Email address', 'tmc' ) ),
						'consent'         => array( 'type' => 'consent', 'label' => __( 'Consent', 'tmc' ), 'required' => true ),
					),
					'map'     => 'tmc_apps_map_reference',
					'success' => __( 'Your appointment request has been sent.', 'tmc' ),
					'errors'  => array( 409 => __( 'The selected time is no longer available. Please choose another time.', 'tmc' ) ),
				),
			),
		),
		'results'      => array(
			'label'   => 'Examination and selection results',
			'actions' => array(
				// POST although it only reads: the date of birth must not appear in URLs or access logs.
				'lookup' => array(
					'method'  => 'POST',
					'path'    => 'lookup',
					'fields'  => array(
						'roll_number'   => array( 'type' => 'text', 'label' => __( 'Roll number', 'tmc' ), 'required' => true, 'max' => 20, 'pattern' => '/^[A-Za-z0-9-]{3,20}$/', 'upper' => true ),
						'date_of_birth' => array( 'type' => 'date', 'label' => __( 'Date of birth', 'tmc' ), 'required' => true, 'past' => true ),
					),
					'map'     => 'tmc_apps_map_result',
					'success' => __( 'Result found.', 'tmc' ),
					'errors'  => array( 404 => __( 'No result was found for these details. Check the roll number and date of birth and try again.', 'tmc' ) ),
				),
			),
		),
		'forms'        => array(
			'label'   => 'Online forms',
			'actions' => array(
				'schema' => array(
					'method' => 'GET',
					'path'   => '{form}/schema',
					'fields' => array( 'form' => array( 'type' => 'code', 'label' => __( 'Form', 'tmc' ), 'required' => true ) ),
					'map'    => 'tmc_apps_map_schema',
					'cache'  => 5 * MINUTE_IN_SECONDS,
					'errors' => array( 404 => __( 'This form is not available.', 'tmc' ) ),
				),
				'submit' => array(
					'method'      => 'POST',
					'path'        => '{form}/submissions',
					'fields'      => array( 'form' => array( 'type' => 'code', 'label' => __( 'Form', 'tmc' ), 'required' => true ) ),
					'schema_from' => 'schema', // the remaining fields come from the backend's form schema
					'map'         => 'tmc_apps_map_reference',
					'success'     => __( 'Thank you. Your form has been submitted.', 'tmc' ),
					'errors'      => array( 404 => __( 'This form is not available.', 'tmc' ) ),
				),
			),
		),
		'payments'     => array(
			'label'   => 'Payments (donations)',
			'actions' => array(
				'initiate' => array(
					'method'  => 'POST',
					'path'    => 'orders',
					'fields'  => array(
						'amount'     => array( 'type' => 'amount', 'label' => __( 'Amount (₹)', 'tmc' ), 'required' => true, 'min' => 1, 'max' => 1000000 ),
						'donor_name' => array( 'type' => 'name', 'label' => __( 'Full name', 'tmc' ), 'required' => true, 'max' => 100 ),
						'email'      => array( 'type' => 'email', 'label' => __( 'Email address', 'tmc' ), 'required' => true ),
						'mobile'     => array( 'type' => 'mobile', 'label' => __( 'Mobile number', 'tmc' ), 'required' => true ),
						'pan'        => array( 'type' => 'text', 'label' => __( 'PAN', 'tmc' ), 'max' => 10, 'pattern' => '/^[A-Z]{5}[0-9]{4}[A-Z]$/', 'upper' => true ),
						'address'    => array( 'type' => 'textarea', 'label' => __( 'Postal address', 'tmc' ), 'max' => 300 ),
						'consent'    => array( 'type' => 'consent', 'label' => __( 'Consent', 'tmc' ), 'required' => true ),
					),
					'server'  => array( 'return_url' ), // added by the website, never taken from the visitor
					'map'     => 'tmc_apps_map_initiate',
					'success' => __( 'Taking you to the payment gateway…', 'tmc' ),
				),
				'status'   => array(
					'method' => 'GET',
					'path'   => 'orders/{order_id}',
					'fields' => array( 'order_id' => $order ),
					'map'    => 'tmc_apps_map_status',
					'errors' => array( 404 => __( 'We could not find this payment. If money was deducted from your account, please contact us with the date and amount.', 'tmc' ) ),
				),
				// Demo gateway page only (the mock is not reachable from browsers); never exposed over REST.
				'checkout' => array(
					'method'    => 'GET',
					'path'      => 'orders/{order_id}/checkout',
					'fields'    => array( 'order_id' => $order ),
					'map'       => 'tmc_apps_map_checkout',
					'internal'  => true,
					'demo_only' => true,
				),
				'simulate' => array(
					'method'    => 'POST',
					'path'      => 'orders/{order_id}/simulate',
					'fields'    => array(
						'order_id' => $order,
						'outcome'  => array( 'type' => 'enum', 'label' => 'Outcome', 'required' => true, 'options' => array( 'success' => 'success', 'failure' => 'failure', 'cancel' => 'cancel' ) ),
					),
					'map'       => 'tmc_apps_map_checkout',
					'internal'  => true,
					'demo_only' => true,
				),
			),
		),
	);
}

/* ================================================================ registry */

/** Demo registry: the four services on the DEMO mock backend (docker-compose service tmc-apps-mock). */
function tmc_apps_demo_registry() {
	$base = 'http://tmc-apps-mock:8080/v1/';
	$make = fn( $type, $label, array $actions, $rate, array $hosts = array() ) => array(
		'type'           => $type,
		'label'          => $label . ' (DEMO backend)',
		'base_url'       => $base . $type,
		'actions'        => $actions,
		'timeout'        => 8,
		'rate_limit'     => $rate,
		'enabled'        => true,
		'demo'           => true,
		'key_env'        => 'TMC_APP_' . strtoupper( $type ) . '_KEY',
		'redirect_hosts' => $hosts,
	);
	return array(
		'appointments' => $make( 'appointments', 'Appointments', array( 'departments', 'slots', 'request' ), 30 ),
		'results'      => $make( 'results', 'Examination results', array( 'lookup' ), 10 ),
		'forms'        => $make( 'forms', 'Online forms', array( 'schema', 'submit' ), 20 ),
		'payments'     => $make( 'payments', 'Donations', array( 'initiate', 'status', 'checkout', 'simulate' ), 20, array( 'self' ) ),
	);
}

/**
 * Validate and normalise one registry entry.
 *
 * @return array|WP_Error
 */
function tmc_apps_normalize_service( $name, $raw ) {
	if ( ! is_string( $name ) || ! preg_match( '/^[a-z][a-z0-9_]{1,31}$/', $name ) ) {
		return new WP_Error( 'name', 'Service name: 2–32 characters, lower-case letters, digits and "_", starting with a letter.' );
	}
	$raw       = is_array( $raw ) ? $raw : array();
	$catalogue = tmc_apps_catalogue();
	$type      = (string) ( $raw['type'] ?? '' );
	if ( ! isset( $catalogue[ $type ] ) ) {
		return new WP_Error( 'type', "$name: unknown service type." );
	}
	$url   = trim( (string) ( $raw['base_url'] ?? '' ) );
	$parts = wp_parse_url( $url );
	if ( ! $parts || ! in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
		return new WP_Error( 'base_url', "$name: base URL must be an http(s) URL without credentials, query or fragment." );
	}
	$actions = array_values( array_intersect( array_keys( $catalogue[ $type ]['actions'] ), array_map( 'strval', (array) ( $raw['actions'] ?? array() ) ) ) );
	$key_env = strtoupper( (string) ( $raw['key_env'] ?? '' ) );
	if ( '' === $key_env ) {
		$key_env = 'TMC_APP_' . strtoupper( $name ) . '_KEY';
	}
	if ( ! preg_match( '/^TMC_APP_[A-Z0-9_]{1,40}_KEY$/', $key_env ) ) {
		return new WP_Error( 'key_env', "$name: key variable must look like TMC_APP_<NAME>_KEY." );
	}
	$hosts = array();
	foreach ( (array) ( $raw['redirect_hosts'] ?? array() ) as $host ) {
		$host = strtolower( trim( (string) $host ) );
		if ( 'self' === $host || preg_match( '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host ) ) {
			$hosts[] = $host;
		}
	}
	return array(
		'type'           => $type,
		'label'          => mb_substr( sanitize_text_field( (string) ( $raw['label'] ?? '' ) ), 0, 100 ) ?: $name,
		'base_url'       => rtrim( esc_url_raw( $url, array( 'http', 'https' ) ), '/' ),
		'actions'        => $actions,
		'timeout'        => max( 1, min( 30, (int) ( $raw['timeout'] ?? 8 ) ) ),
		'rate_limit'     => max( 1, min( 600, (int) ( $raw['rate_limit'] ?? 20 ) ) ),
		'enabled'        => ! empty( $raw['enabled'] ),
		'demo'           => ! empty( $raw['demo'] ),
		'key_env'        => $key_env,
		'redirect_hosts' => array_values( array_unique( $hosts ) ),
	);
}

/** All valid registry entries (network-wide). */
function tmc_apps_registry() {
	$registry = array();
	foreach ( (array) get_site_option( TMC_APPS_OPTION, array() ) as $name => $raw ) {
		$service = tmc_apps_normalize_service( (string) $name, $raw );
		if ( ! is_wp_error( $service ) ) {
			$registry[ $name ] = $service;
		}
	}
	return $registry;
}

function tmc_apps_service( $name ) {
	$registry = tmc_apps_registry();
	return is_string( $name ) && isset( $registry[ $name ] ) ? $registry[ $name ] : null;
}

/** First enabled service of a type (used by blocks that name no service). */
function tmc_apps_service_of_type( $type ) {
	foreach ( tmc_apps_registry() as $name => $service ) {
		if ( $service['enabled'] && $type === $service['type'] ) {
			return $name;
		}
	}
	return '';
}

/**
 * The action definition, or null when the service/action is unknown, disabled or not allowed.
 */
function tmc_apps_action( $service, $action, $allow_internal = false ) {
	if ( ! $service || ! $service['enabled'] || ! is_string( $action ) || ! in_array( $action, $service['actions'], true ) ) {
		return null;
	}
	$def = tmc_apps_catalogue()[ $service['type'] ]['actions'][ $action ] ?? null;
	if ( ! $def ) {
		return null;
	}
	$def += array( 'fields' => array(), 'errors' => array(), 'cache' => 0, 'internal' => false, 'demo_only' => false, 'server' => array(), 'success' => '' );
	if ( ( $def['demo_only'] && ! $service['demo'] ) || ( $def['internal'] && ! $allow_internal ) ) {
		return null;
	}
	return $def;
}

/** API key for a service — from the environment only. */
function tmc_apps_key( array $service ) {
	return (string) getenv( $service['key_env'] );
}
