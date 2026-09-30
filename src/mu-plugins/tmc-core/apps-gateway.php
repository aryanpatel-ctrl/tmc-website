<?php
/**
 * TMC application gateway (tender §4.4, §4.8, §4.12 — R-4.4-1..3, R-4.12-1..3, R-4.8-1).
 *
 * TMC builds and runs the backends (appointments, results, online forms, payments). The website only
 * presents them. Every call goes through this gateway, server-side:
 *
 *   browser ──► /wp-json/tmc/v1/apps/<service>/<action> ──► TMC-approved endpoint (internal URL)
 *
 *   - Only services in the network registry (Network Admin → Settings → TMC applications) and only the
 *     actions allowed for them are reachable; everything else is refused with the same 404.
 *   - Input is validated and sanitised against the action's schema; unknown fields are dropped.
 *   - API keys come only from the environment (TMC_APP_<SERVICE>_KEY), never from the database.
 *   - Upstream URLs, host names, status texts and error bodies never reach the visitor: responses are
 *     rebuilt from an explicit allow-list of fields and our own messages.
 *   - Per-IP rate limit per service; writes need a page token (CSRF) and a same-origin request.
 *   - Each call is logged to the audit trail with metadata only (service, action, status, latency) —
 *     never the submitted values. Nothing that a visitor submits is stored by the website.
 *
 * Interface specification: docs/integration/gateway.md.
 */

defined( 'ABSPATH' ) || exit;

const TMC_APPS_OPTION = 'tmc_apps_registry';

/** Demo environments (local, CI, UAT) set TMC_DEMO=1: demo services and data are shown and labelled. */
function tmc_is_demo() {
	return '1' === (string) getenv( 'TMC_DEMO' );
}

/* ================================================================ action catalogue */

/**
 * What each type of TMC service can do. The registry decides which services exist, where they are
 * and which of these actions are enabled; this catalogue fixes the method, path and input schema.
 *
 * Field types: text, name, textarea, email, mobile, tel, date, code, token, enum, consent, bool,
 * amount, integer, url. Path placeholders {field} are filled from validated input.
 */
function tmc_apps_catalogue() {
	$department = array( 'type' => 'code', 'label' => __( 'Department', 'tmc' ), 'required' => true );
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
						'slot'            => array( 'type' => 'code', 'label' => __( 'Appointment time', 'tmc' ), 'required' => true ),
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

/* ================================================================ request protection */

/** Page token for write actions: same for every visitor (pages stay cacheable), valid 12–24 hours. */
function tmc_apps_token( $ticks_ago = 0 ) {
	$tick = (int) ceil( time() / ( DAY_IN_SECONDS / 2 ) ) - (int) $ticks_ago;
	return substr( hash_hmac( 'sha256', 'tmc_apps|' . get_current_blog_id() . '|' . $tick, wp_salt( 'nonce' ) ), 0, 24 );
}

function tmc_apps_verify_token( $token ) {
	if ( ! is_string( $token ) || '' === $token ) {
		return false;
	}
	return hash_equals( tmc_apps_token( 0 ), $token ) || hash_equals( tmc_apps_token( 1 ), $token );
}

/** Browsers send Origin on cross-site POSTs; refuse any that is not this site. */
function tmc_apps_same_origin() {
	$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? (string) wp_unslash( $_SERVER['HTTP_ORIGIN'] ) : '';
	if ( '' === $origin ) {
		return true;
	}
	$host = wp_parse_url( $origin, PHP_URL_HOST );
	return is_string( $host ) && strtolower( $host ) === strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
}

function tmc_apps_client_ip() {
	if ( defined( 'WP_CLI' ) && WP_CLI && empty( $_SERVER['REMOTE_ADDR'] ) ) {
		return 'cli';
	}
	return substr( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ), 0, 45 ); // mod_remoteip gives the real client IP
}

/** Transient name for a rate-limit bucket; the IP is kept only as a keyed hash. */
function tmc_apps_rate_key( $bucket ) {
	return 'tmc_apps_rl_' . substr( hash_hmac( 'sha256', $bucket . '|' . tmc_apps_client_ip(), wp_salt( 'nonce' ) ), 0, 24 );
}

/**
 * Count one request against a per-IP, per-minute limit.
 *
 * @return int 0 when allowed, otherwise how many requests over the limit (1 = first refusal).
 */
function tmc_apps_rate_hit( $bucket, $limit ) {
	$key   = tmc_apps_rate_key( $bucket );
	$now   = time();
	$state = get_transient( $key );
	if ( ! is_array( $state ) || (int) ( $state['start'] ?? 0 ) + MINUTE_IN_SECONDS <= $now ) {
		$state = array( 'start' => $now, 'count' => 0 );
	}
	++$state['count'];
	set_transient( $key, $state, max( 1, MINUTE_IN_SECONDS - ( $now - (int) $state['start'] ) ) );
	return max( 0, $state['count'] - (int) $limit );
}

/* ================================================================ input validation */

/**
 * Validate input against field rules. Unknown input keys are ignored (never forwarded).
 * Expects unslashed input.
 *
 * @return array{0:array,1:array} [ clean values, field => error message ]
 */
function tmc_apps_validate( array $rules, array $input ) {
	$values = array();
	$errors = array();
	foreach ( $rules as $name => $rule ) {
		$rule += array( 'required' => false, 'label' => $name, 'type' => 'text' );
		$raw   = $input[ $name ] ?? null;
		if ( is_bool( $raw ) ) {
			$raw = $raw ? '1' : '';
		}
		if ( null !== $raw && ! is_scalar( $raw ) ) {
			$errors[ $name ] = tmc_apps_invalid_message( $rule );
			continue;
		}
		$raw   = trim( (string) $raw );
		$empty = '' === $raw || ( in_array( $rule['type'], array( 'consent', 'bool' ), true ) && ! in_array( strtolower( $raw ), array( '1', 'true', 'on', 'yes' ), true ) );
		if ( $empty ) {
			if ( 'bool' === $rule['type'] ) {
				$values[ $name ] = false;
			} elseif ( $rule['required'] ) {
				$errors[ $name ] = tmc_apps_required_message( $rule );
			}
			continue;
		}
		$checked = tmc_apps_check_value( $raw, $rule );
		if ( is_array( $checked ) ) {
			$values[ $name ] = $checked[0];
		} else {
			$errors[ $name ] = $checked;
		}
	}
	return array( $values, $errors );
}

function tmc_apps_required_message( array $rule ) {
	switch ( $rule['type'] ) {
		case 'consent':
			return __( 'Tick the box to confirm that you agree.', 'tmc' );
		case 'enum':
			/* translators: %s: field label */
			return sprintf( __( 'Select an option for “%s”.', 'tmc' ), $rule['label'] );
		default:
			/* translators: %s: field label */
			return sprintf( __( 'Enter “%s”.', 'tmc' ), $rule['label'] );
	}
}

function tmc_apps_invalid_message( array $rule ) {
	/* translators: %s: field label */
	return sprintf( __( 'Enter a valid value for “%s”.', 'tmc' ), $rule['label'] ?? '' );
}

/**
 * Check one non-empty value.
 *
 * @return array|string [ clean value ] or an error message.
 */
function tmc_apps_check_value( $raw, array $rule ) {
	$label = $rule['label'];
	$max   = (int) ( $rule['max'] ?? ( 'textarea' === $rule['type'] ? 2000 : 200 ) );
	/* translators: 1: field label, 2: number of characters */
	$too_long = sprintf( __( '“%1$s” must be %2$d characters or fewer.', 'tmc' ), $label, $max );

	switch ( $rule['type'] ) {
		case 'text':
		case 'name':
		case 'textarea':
			$value = 'textarea' === $rule['type'] ? sanitize_textarea_field( $raw ) : sanitize_text_field( $raw );
			if ( ! empty( $rule['upper'] ) ) {
				$value = strtoupper( $value );
			}
			if ( '' === $value ) {
				return tmc_apps_invalid_message( $rule );
			}
			if ( mb_strlen( $value ) > $max ) {
				return $too_long;
			}
			if ( 'name' === $rule['type'] && ! preg_match( "/^[\p{L}\p{M} .'-]+$/u", $value ) ) {
				/* translators: %s: field label */
				return sprintf( __( '“%s” can contain only letters, spaces, full stops, apostrophes and hyphens.', 'tmc' ), $label );
			}
			if ( ! empty( $rule['pattern'] ) && ! preg_match( $rule['pattern'], $value ) ) {
				return tmc_apps_invalid_message( $rule );
			}
			return array( $value );

		case 'email':
			$value = sanitize_email( $raw );
			if ( strlen( $raw ) > 254 || ! is_email( $value ) ) {
				return __( 'Enter an email address in the correct format, like name@example.com.', 'tmc' );
			}
			return array( $value );

		case 'mobile':
			$digits = preg_replace( '/[\s().-]/', '', $raw );
			$digits = preg_replace( '/^(\+91|0091|0)(?=\d{10}$)/', '', $digits );
			if ( ! preg_match( '/^[6-9]\d{9}$/', $digits ) ) {
				return __( 'Enter a 10-digit mobile number, like 98765 43210.', 'tmc' );
			}
			return array( $digits );

		case 'tel':
			$digits = preg_replace( '/[\s().-]/', '', $raw );
			if ( ! preg_match( '/^\+?\d{6,15}$/', $digits ) ) {
				return __( 'Enter a telephone number using digits only, like 022 2417 7000.', 'tmc' );
			}
			return array( $digits );

		case 'date':
			if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
				/* translators: %s: field label */
				return sprintf( __( '“%s” must be a real date.', 'tmc' ), $label );
			}
			$today = wp_date( 'Y-m-d' );
			if ( ! empty( $rule['past'] ) && ( $raw >= $today || $raw < '1900-01-01' ) ) {
				/* translators: %s: field label */
				return sprintf( __( '“%s” must be in the past.', 'tmc' ), $label );
			}
			if ( isset( $rule['min_days'], $rule['max_days'] ) ) {
				$from = wp_date( 'Y-m-d', time() + (int) $rule['min_days'] * DAY_IN_SECONDS );
				$to   = wp_date( 'Y-m-d', time() + (int) $rule['max_days'] * DAY_IN_SECONDS );
				if ( $raw < $from || $raw > $to ) {
					/* translators: 1: field label, 2: first date, 3: last date */
					return sprintf( __( '“%1$s” must be between %2$s and %3$s.', 'tmc' ), $label, wp_date( 'd/m/Y', strtotime( $from . ' 12:00' ) ), wp_date( 'd/m/Y', strtotime( $to . ' 12:00' ) ) );
				}
			}
			return array( $raw );

		case 'code':
			return preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $raw ) ? array( $raw ) : tmc_apps_invalid_message( $rule );

		case 'token':
			return preg_match( '/^[A-Za-z0-9_-]{8,64}$/', $raw ) ? array( $raw ) : tmc_apps_invalid_message( $rule );

		case 'enum':
			return array_key_exists( $raw, (array) ( $rule['options'] ?? array() ) ) ? array( (string) $raw ) : tmc_apps_invalid_message( $rule );

		case 'consent':
		case 'bool':
			return array( true );

		case 'amount':
		case 'integer':
			$number = str_replace( array( ',', ' ' ), '', $raw );
			$min    = (int) ( $rule['min'] ?? 0 );
			$lim    = (int) ( $rule['max'] ?? PHP_INT_MAX );
			if ( ! preg_match( '/^\d{1,9}$/', $number ) || (int) $number < $min || (int) $number > $lim ) {
				/* translators: 1: field label, 2: minimum, 3: maximum */
				return sprintf( __( '“%1$s” must be a whole number from %2$s to %3$s.', 'tmc' ), $label, number_format_i18n( $min ), number_format_i18n( $lim ) );
			}
			return array( (int) $number );

		case 'url':
			$value = esc_url_raw( $raw, array( 'http', 'https' ) );
			if ( '' === $value || ! wp_parse_url( $value, PHP_URL_HOST ) ) {
				return __( 'Enter a web address starting with http:// or https://.', 'tmc' );
			}
			if ( strlen( $value ) > $max ) {
				return $too_long;
			}
			return array( $value );
	}
	return tmc_apps_invalid_message( $rule );
}

/* ================================================================ backend-provided form schemas */

/** Field rules for a form schema returned by a forms backend (see tmc_apps_map_schema). */
function tmc_apps_schema_rules( array $schema ) {
	$types = array(
		'text'     => 'text',
		'textarea' => 'textarea',
		'email'    => 'email',
		'tel'      => 'tel',
		'number'   => 'integer',
		'date'     => 'date',
		'select'   => 'enum',
		'radio'    => 'enum',
		'url'      => 'url',
	);
	$rules = array();
	foreach ( $schema['fields'] as $field ) {
		$rule = array(
			'type'     => 'checkbox' === $field['type'] ? ( $field['required'] ? 'consent' : 'bool' ) : $types[ $field['type'] ],
			'label'    => $field['label'],
			'required' => $field['required'],
			'max'      => $field['max_length'],
		);
		if ( 'enum' === $rule['type'] ) {
			$rule['options'] = wp_list_pluck( $field['options'], 'label', 'value' );
		}
		if ( 'number' === $field['type'] ) {
			$rule['min'] = $field['min'];
			$rule['max'] = $field['max'];
		}
		$rules[ $field['name'] ] = $rule;
	}
	return $rules;
}

/** The cleaned schema of a backend form, or null when unavailable. Cached briefly (not personal data). */
function tmc_apps_form_schema( $service, $form ) {
	$result = tmc_apps_call( $service, 'schema', array( 'form' => $form ), array( 'internal' => true, 'channel' => 'server' ) );
	return $result['ok'] ? $result['data'] : null;
}

/* ================================================================ response allow-lists */

function tmc_apps_text( $value, $max = 200 ) {
	return is_scalar( $value ) ? mb_substr( sanitize_text_field( (string) $value ), 0, $max ) : '';
}

function tmc_apps_map_departments( array $body ) {
	if ( ! isset( $body['departments'] ) || ! is_array( $body['departments'] ) ) {
		return null;
	}
	$list = array();
	foreach ( array_slice( $body['departments'], 0, 200 ) as $item ) {
		$code = is_array( $item ) ? (string) ( $item['code'] ?? '' ) : '';
		$name = is_array( $item ) ? tmc_apps_text( $item['name'] ?? '', 100 ) : '';
		if ( preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $code ) && '' !== $name ) {
			$list[] = array( 'code' => $code, 'name' => $name );
		}
	}
	return array( 'departments' => $list );
}

function tmc_apps_map_slots( array $body ) {
	if ( ! isset( $body['slots'] ) || ! is_array( $body['slots'] ) ) {
		return null;
	}
	$slots = array();
	foreach ( array_slice( $body['slots'], 0, 200 ) as $slot ) {
		if ( is_array( $slot ) && preg_match( '/^[A-Za-z0-9_-]{1,64}$/', (string) ( $slot['id'] ?? '' ) ) && preg_match( '/^\d{2}:\d{2}$/', (string) ( $slot['time'] ?? '' ) ) ) {
			$slots[] = array( 'id' => (string) $slot['id'], 'time' => (string) $slot['time'], 'available' => ! empty( $slot['available'] ) );
		}
	}
	return array( 'slots' => $slots );
}

function tmc_apps_map_reference( array $body ) {
	$reference = (string) ( $body['reference'] ?? '' );
	return preg_match( '/^[A-Za-z0-9\/_-]{1,40}$/', $reference ) ? array( 'reference' => $reference ) : null;
}

function tmc_apps_map_result( array $body ) {
	$data = array(
		'roll_number'    => tmc_apps_text( $body['roll_number'] ?? '', 20 ),
		'candidate_name' => tmc_apps_text( $body['candidate_name'] ?? '', 100 ),
		'examination'    => tmc_apps_text( $body['examination'] ?? '', 200 ),
		'result'         => tmc_apps_text( $body['result'] ?? '', 100 ),
		'published_on'   => preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $body['published_on'] ?? '' ) ) ? (string) $body['published_on'] : '',
	);
	return '' === $data['result'] ? null : $data;
}

function tmc_apps_map_schema( array $body ) {
	$form = $body['form'] ?? null;
	if ( ! is_array( $form ) || ! is_array( $form['fields'] ?? null ) ) {
		return null;
	}
	$allowed  = array( 'text', 'textarea', 'email', 'tel', 'number', 'date', 'select', 'radio', 'checkbox', 'url' );
	$reserved = array( 'form', 'page', 'lang', 'return_url' );
	$fields   = array();
	foreach ( array_slice( $form['fields'], 0, 40 ) as $field ) {
		$name = is_array( $field ) ? (string) ( $field['name'] ?? '' ) : '';
		$type = is_array( $field ) ? (string) ( $field['type'] ?? '' ) : '';
		if ( ! preg_match( '/^[a-z][a-z0-9_]{0,31}$/', $name ) || 0 === strpos( $name, 'tmc_' ) || in_array( $name, $reserved, true ) || ! in_array( $type, $allowed, true ) ) {
			continue;
		}
		$clean = array(
			'name'         => $name,
			'label'        => tmc_apps_text( $field['label'] ?? '', 200 ) ?: $name,
			'type'         => $type,
			'required'     => ! empty( $field['required'] ),
			'help'         => tmc_apps_text( $field['help'] ?? '', 300 ),
			'max_length'   => max( 1, min( 5000, (int) ( $field['max_length'] ?? ( 'textarea' === $type ? 2000 : 200 ) ) ) ),
			'autocomplete' => preg_match( '/^[a-z][a-z -]{0,39}$/', (string) ( $field['autocomplete'] ?? '' ) ) ? (string) $field['autocomplete'] : '',
			'options'      => array(),
			'min'          => (int) ( $field['min'] ?? 0 ),
			'max'          => (int) ( $field['max'] ?? 999999999 ),
		);
		if ( in_array( $type, array( 'select', 'radio' ), true ) ) {
			foreach ( array_slice( (array) ( $field['options'] ?? array() ), 0, 100 ) as $option ) {
				$value = is_array( $option ) ? (string) ( $option['value'] ?? '' ) : '';
				if ( preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $value ) ) {
					$clean['options'][] = array( 'value' => $value, 'label' => tmc_apps_text( $option['label'] ?? $value, 200 ) ?: $value );
				}
			}
			if ( ! $clean['options'] ) {
				continue;
			}
		}
		$fields[ $name ] = $clean;
	}
	if ( ! $fields ) {
		return null;
	}
	return array(
		'id'          => sanitize_key( (string) ( $form['id'] ?? '' ) ),
		'title'       => tmc_apps_text( $form['title'] ?? '', 200 ),
		'description' => tmc_apps_text( $form['description'] ?? '', 500 ),
		'fields'      => array_values( $fields ),
	);
}

/** Is a gateway redirect target allowed for this service? "self" = this website's host. */
function tmc_apps_redirect_allowed( $url, array $service ) {
	$parts = wp_parse_url( (string) $url );
	if ( ! $parts || empty( $parts['host'] ) || ! in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) || isset( $parts['user'] ) ) {
		return false;
	}
	$host = strtolower( $parts['host'] );
	$self = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	foreach ( $service['redirect_hosts'] as $allowed ) {
		if ( 'self' === $allowed ? $host === $self : ( $host === $allowed && 'https' === $parts['scheme'] ) ) {
			return true;
		}
	}
	return false;
}

function tmc_apps_map_initiate( array $body, array $service ) {
	$order = (string) ( $body['order_id'] ?? '' );
	$url   = (string) ( $body['redirect_url'] ?? '' );
	if ( ! preg_match( '/^[A-Za-z0-9_-]{8,64}$/', $order ) || ! tmc_apps_redirect_allowed( $url, $service ) ) {
		return null;
	}
	return array( 'order_id' => $order, 'redirect_url' => esc_url_raw( $url ) );
}

function tmc_apps_map_status( array $body ) {
	$order    = (string) ( $body['order_id'] ?? '' );
	$status   = (string) ( $body['status'] ?? '' );
	$currency = (string) ( $body['currency'] ?? 'INR' );
	if ( ! preg_match( '/^[A-Za-z0-9_-]{8,64}$/', $order ) ) {
		return null;
	}
	$reference = (string) ( $body['transaction_ref'] ?? '' );
	return array(
		'order_id'        => $order,
		'status'          => in_array( $status, array( 'created', 'pending', 'paid', 'failed', 'cancelled' ), true ) ? $status : 'pending',
		'amount'          => max( 0, (int) ( $body['amount'] ?? 0 ) ),
		'currency'        => preg_match( '/^[A-Z]{3}$/', $currency ) ? $currency : 'INR',
		'transaction_ref' => preg_match( '/^[A-Za-z0-9\/_-]{1,40}$/', $reference ) ? $reference : '',
	);
}

/** Demo checkout / simulate: the return URL must point back at this website. */
function tmc_apps_map_checkout( array $body ) {
	$status = tmc_apps_map_status( $body );
	$return = (string) ( $body['return_url'] ?? '' );
	if ( ! $status || strtolower( (string) wp_parse_url( $return, PHP_URL_HOST ) ) !== strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
		return null;
	}
	return $status + array( 'return_url' => esc_url_raw( $return ) );
}

/* ================================================================ the call */

function tmc_apps_messages() {
	return array(
		'unknown'     => __( 'This online service is not available.', 'tmc' ),
		'method'      => __( 'This request is not allowed.', 'tmc' ),
		'forbidden'   => __( 'Your session has expired. Please reload the page and try again.', 'tmc' ),
		'rate'        => __( 'Too many requests. Please wait a minute and try again.', 'tmc' ),
		'invalid'     => __( 'Please correct the errors in the form.', 'tmc' ),
		'rejected'    => __( 'The service could not accept these details. Please check them and try again.', 'tmc' ),
		'unavailable' => __( 'The service is temporarily unavailable. Please try again later.', 'tmc' ),
		'busy'        => __( 'The service is busy. Please try again in a few minutes.', 'tmc' ),
	);
}

function tmc_apps_result( $status, $code, $message = '', array $extra = array() ) {
	return array_merge(
		array(
			'ok'         => $status < 300,
			'status'     => (int) $status,
			'code'       => $code,
			'message'    => '' !== $message ? $message : ( tmc_apps_messages()[ $code ] ?? '' ),
			'errors'     => array(),
			'data'       => array(),
			'request_id' => '',
		),
		$extra
	);
}

/** Audit entry with metadata only — never input values or upstream bodies. */
function tmc_apps_log( array $meta, array $result, $outcome, $upstream_status = 0, $latency = 0, array $extra = array() ) {
	if ( ! function_exists( 'tmc_audit' ) ) {
		return;
	}
	tmc_audit(
		'app_gateway_call',
		array(
			'object_type'  => 'app_service',
			'object_title' => $meta['service'] . '/' . $meta['action'],
			'details'      => array(
				'service'         => $meta['service'],
				'action'          => $meta['action'],
				'channel'         => $meta['channel'],
				'status'          => $result['status'],
				'upstream_status' => (int) $upstream_status,
				'latency_ms'      => (int) $latency,
				'outcome'         => $outcome,
				'request_id'      => $meta['request_id'],
			) + $extra,
		)
	);
}

/**
 * Forward a validated request to the service. Returns [ upstream status (0 = no response), decoded JSON|null, latency ms ].
 */
function tmc_apps_forward( array $service, array $def, array $values, $request_id ) {
	$path = preg_replace_callback(
		'/\{([a-z_]+)\}/',
		function ( $m ) use ( &$values ) {
			$value = (string) ( $values[ $m[1] ] ?? '' );
			unset( $values[ $m[1] ] );
			return rawurlencode( $value );
		},
		$def['path']
	);
	$url  = $service['base_url'] . '/' . $path;
	$args = array(
		'method'      => $def['method'],
		'timeout'     => $service['timeout'],
		'redirection' => 0,
		'user-agent'  => 'TMC-Website-Gateway/1.0',
		'headers'     => array(
			'Accept'       => 'application/json',
			'X-API-Key'    => tmc_apps_key( $service ),
			'X-Request-Id' => $request_id,
		),
	);
	if ( 'GET' === $def['method'] ) {
		$url .= $values ? '?' . http_build_query( $values, '', '&', PHP_QUERY_RFC3986 ) : '';
	} else {
		$args['headers']['Content-Type'] = 'application/json';
		$args['body']                    = wp_json_encode( (object) $values );
	}
	$start    = microtime( true );
	$response = wp_remote_request( $url, $args );
	$latency  = (int) round( ( microtime( true ) - $start ) * 1000 );
	if ( is_wp_error( $response ) ) {
		return array( 0, null, $latency );
	}
	$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	return array( (int) wp_remote_retrieve_response_code( $response ), is_array( $json ) ? $json : null, $latency );
}

/**
 * Call a registered TMC service. The single entry point for REST, no-JavaScript form posts and
 * server-side rendering.
 *
 * @param string $service Registry name.
 * @param string $action  Action name.
 * @param array  $input   Unslashed visitor input (validated here).
 * @param array  $opts    internal (bool: server-side call, may use internal actions, no rate limit),
 *                        server_fields (array: values added by the website, e.g. return_url),
 *                        channel (rest|form|server, for the log).
 * @return array{ok:bool,status:int,code:string,message:string,errors:array,data:array,request_id:string}
 */
function tmc_apps_call( $service, $action, array $input, array $opts = array() ) {
	$opts    += array( 'internal' => false, 'server_fields' => array(), 'channel' => 'server' );
	$service  = is_string( $service ) ? substr( sanitize_key( $service ), 0, 32 ) : '';
	$action   = is_string( $action ) ? substr( sanitize_key( $action ), 0, 32 ) : '';
	$meta     = array( 'service' => $service, 'action' => $action, 'channel' => $opts['channel'], 'request_id' => wp_generate_uuid4() );
	$extra    = array( 'request_id' => $meta['request_id'] );
	$svc      = tmc_apps_service( $service );
	$def      = tmc_apps_action( $svc, $action, $opts['internal'] );

	if ( ! $def ) {
		if ( ! $opts['internal'] && tmc_apps_rate_hit( '_unknown', 30 ) ) {
			return tmc_apps_result( 429, 'rate', '', $extra );
		}
		$result = tmc_apps_result( 404, 'unknown', '', $extra );
		tmc_apps_log( $meta, $result, 'refused_unknown' );
		return $result;
	}

	if ( ! $opts['internal'] ) {
		$over = tmc_apps_rate_hit( $service, $svc['rate_limit'] );
		if ( $over ) {
			$result = tmc_apps_result( 429, 'rate', '', $extra );
			if ( 1 === $over ) { // log the first refusal of each window, not every retry
				tmc_apps_log( $meta, $result, 'rate_limited' );
			}
			return $result;
		}
	}

	if ( '' === tmc_apps_key( $svc ) ) {
		$result = tmc_apps_result( 503, 'unavailable', '', $extra );
		tmc_apps_log( $meta, $result, 'not_configured' );
		return $result;
	}

	// Validate: the action's own fields, then (online forms) the fields of the backend's form schema.
	$rules                   = $def['fields'];
	list( $values, $errors ) = tmc_apps_validate( $rules, $input );
	if ( ! $errors && ! empty( $def['schema_from'] ) ) {
		$schema = tmc_apps_form_schema( $service, $values['form'] );
		if ( ! $schema ) {
			$result = tmc_apps_result( 404, 'unknown', $def['errors'][404] ?? '', $extra );
			tmc_apps_log( $meta, $result, 'schema_unavailable' );
			return $result;
		}
		$rules                  = tmc_apps_schema_rules( $schema );
		list( $more, $errors ) = tmc_apps_validate( $rules, $input );
		$values                 = array_merge( $values, $more );
	}
	if ( $errors ) {
		$result = tmc_apps_result( 422, 'invalid', '', $extra + array( 'errors' => $errors ) );
		tmc_apps_log( $meta, $result, 'invalid_input', 0, 0, array( 'fields' => array_keys( $errors ) ) );
		return $result;
	}
	foreach ( $def['server'] as $field ) {
		if ( ! isset( $opts['server_fields'][ $field ] ) ) {
			$result = tmc_apps_result( 503, 'unavailable', '', $extra );
			tmc_apps_log( $meta, $result, 'missing_server_field' );
			return $result;
		}
		$values[ $field ] = $opts['server_fields'][ $field ];
	}

	// Short cache for non-personal reference data (department list, form schema).
	$cache_key = '';
	if ( $def['cache'] && 'GET' === $def['method'] ) {
		$cache_key = 'tmc_apps_c_' . md5( $service . '|' . $action . '|' . wp_json_encode( $values ) );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return tmc_apps_result( 200, 'ok', $def['success'], $extra + array( 'data' => $cached ) );
		}
	}

	list( $code, $json, $latency ) = tmc_apps_forward( $svc, $def, $values, $meta['request_id'] );

	if ( $code >= 200 && $code < 300 ) {
		$data = is_array( $json ) ? call_user_func( $def['map'], $json, $svc ) : null;
		if ( ! is_array( $data ) ) {
			$result = tmc_apps_result( 503, 'unavailable', '', $extra );
			tmc_apps_log( $meta, $result, 'upstream_bad_response', $code, $latency );
			return $result;
		}
		if ( $cache_key ) {
			set_transient( $cache_key, $data, $def['cache'] );
		}
		$result = tmc_apps_result( 200, 'ok', $def['success'], $extra + array( 'data' => $data ) );
		tmc_apps_log( $meta, $result, 'ok', $code, $latency );
		return $result;
	}

	if ( in_array( $code, array( 404, 409 ), true ) && isset( $def['errors'][ $code ] ) ) {
		$result  = tmc_apps_result( $code, 404 === $code ? 'not_found' : 'conflict', $def['errors'][ $code ], $extra );
		$outcome = 'upstream_' . ( 404 === $code ? 'not_found' : 'conflict' );
	} elseif ( in_array( $code, array( 400, 422 ), true ) ) {
		// Mark the fields the backend rejected, with our own wording (backend text is never shown).
		$errors = array();
		foreach ( array_keys( (array) ( $json['fields'] ?? array() ) ) as $field ) {
			if ( isset( $rules[ $field ] ) || isset( $def['fields'][ $field ] ) ) {
				$errors[ $field ] = tmc_apps_invalid_message( $rules[ $field ] ?? $def['fields'][ $field ] );
			}
		}
		$result  = tmc_apps_result( 422, 'rejected', '', $extra + array( 'errors' => $errors ) );
		$outcome = 'upstream_rejected';
	} elseif ( 429 === $code ) {
		$result  = tmc_apps_result( 503, 'busy', '', $extra );
		$outcome = 'upstream_busy';
	} else {
		$result  = tmc_apps_result( 503, 'unavailable', '', $extra );
		$outcome = 0 === $code ? 'upstream_unreachable' : ( in_array( $code, array( 401, 403 ), true ) ? 'upstream_auth_failed' : 'upstream_error' );
	}
	tmc_apps_log( $meta, $result, $outcome, $code, $latency );
	return $result;
}

/* ================================================================ REST interface */

add_action( 'rest_api_init', 'tmc_apps_register_routes' );
function tmc_apps_register_routes() {
	register_rest_route(
		'tmc/v1',
		'/apps/(?P<service>[a-z0-9_]{1,32})/(?P<action>[a-z0-9_]{1,32})',
		array(
			'methods'             => array( 'GET', 'POST' ),
			'callback'            => 'tmc_apps_rest',
			'permission_callback' => '__return_true', // public by design; protection is inside (see header)
		)
	);
}

/** Return URL for a donation: a published page of this site that holds the donate block. */
function tmc_apps_donation_return_url( $page_id ) {
	$page = get_post( (int) $page_id );
	if ( ! $page || 'publish' !== $page->post_status || ! has_block( 'tmc/app-donate', $page ) ) {
		return '';
	}
	return add_query_arg( 'tmc_donation', 'return', get_permalink( $page ) );
}

/** Values the website adds for an action (never taken from visitor input). */
function tmc_apps_server_fields( array $service, $action, $page_id ) {
	if ( 'payments' === $service['type'] && 'initiate' === $action ) {
		$return = tmc_apps_donation_return_url( $page_id );
		return $return ? array( 'return_url' => $return ) : array();
	}
	return array();
}

function tmc_apps_rest( WP_REST_Request $request ) {
	$service = (string) $request['service'];
	$action  = (string) $request['action'];
	$svc     = tmc_apps_service( $service );
	$def     = tmc_apps_action( $svc, $action );
	$method  = $request->get_method();

	if ( 0 === strpos( (string) $request->get_param( 'lang' ), 'hi' ) ) {
		switch_to_locale( 'hi_IN' );
	}

	$refuse = null;
	if ( $def && $def['method'] !== $method ) {
		$refuse = tmc_apps_result( 405, 'method' );
	} elseif ( $def && 'POST' === $method ) {
		$token = $request->get_header( 'x_tmc_token' ) ?? $request->get_param( '_tmc_token' );
		if ( ! tmc_apps_same_origin() || ! tmc_apps_verify_token( (string) $token ) ) {
			$refuse = tmc_apps_result( 403, 'forbidden' );
		}
	}

	if ( $refuse ) {
		$result = $refuse;
		tmc_apps_log( array( 'service' => $service, 'action' => $action, 'channel' => 'rest', 'request_id' => '' ), $result, 405 === $result['status'] ? 'refused_method' : 'refused_token' );
	} else {
		$input = 'GET' === $method ? $request->get_query_params() : ( $request->get_json_params() ? $request->get_json_params() : $request->get_body_params() );
		unset( $input['_tmc_token'], $input['lang'], $input['page'], $input['return_url'] );
		$server = $svc && $def ? tmc_apps_server_fields( $svc, $action, (int) $request->get_param( 'page' ) ) : array();
		$result = tmc_apps_call( $service, $action, (array) $input, array( 'server_fields' => $server, 'channel' => 'rest' ) );
	}

	$payload = array(
		'ok'         => $result['ok'],
		'code'       => $result['code'],
		'message'    => $result['message'],
		'errors'     => (object) $result['errors'],
		'data'       => (object) $result['data'],
		'request_id' => $result['request_id'],
	);
	if ( $result['ok'] && $svc ) {
		/** Presentation of a successful result (the theme renders it; escaped HTML). */
		$payload['html'] = (string) apply_filters( 'tmc_apps_result_html', '', $svc['type'], $action, $result );
	}
	$response = new WP_REST_Response( $payload, $result['status'] );
	$response->header( 'Cache-Control', 'no-store, private' );
	$response->header( 'X-Robots-Tag', 'noindex' );
	if ( 429 === $result['status'] ) {
		$response->header( 'Retry-After', '60' );
	}
	if ( 405 === $result['status'] && $def ) {
		$response->header( 'Allow', $def['method'] );
	}
	return $response;
}
