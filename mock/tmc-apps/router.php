<?php
/**
 * DEMO ONLY — stand-in for the TMC application backends (appointments, examination results,
 * online forms, donations/payments). TMC builds and runs the real systems; this mock exists so
 * the website's presentation layer and integration gateway can be demonstrated and tested in CI.
 *
 * It holds NO real data: departments, slots, results and forms below are made-up demo values.
 * It runs on the isolated "tmc_apps" Docker network (no internet, no database, no host port) and
 * only answers requests that carry the shared API key (header X-API-Key = $MOCK_API_KEY).
 *
 *   php -S 0.0.0.0:8080 /app/router.php          (see docker-compose.yml, service tmc-apps-mock)
 *
 * Interface contract: docs/integration/gateway.md. Every response body deliberately includes the
 * internal host name ("server": "tmc-apps-mock") and verbose error details, so tests can prove that
 * the website's gateway never passes upstream hosts or error text on to visitors.
 *
 * Only payment orders are kept (amount, status, return URL — no donor details) in a tmpfs, so the
 * demo checkout and status check work across requests. They vanish when the container restarts.
 */

declare(strict_types=1);

const MOCK_HOST = 'tmc-apps-mock';

/** Send a JSON response and stop. */
function mock_out( int $status, array $body ): void {
	http_response_code( $status );
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Cache-Control: no-store' );
	$body += array(
		'server' => MOCK_HOST,
		'demo'   => true,
	);
	echo json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	exit;
}

function mock_valid_date( $value ): bool {
	if ( ! is_string( $value ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) ) {
		return false;
	}
	return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
}

/* ---------------------------------------------------------------- demo data */

function mock_departments(): array {
	return array(
		array( 'code' => 'MED-ONC', 'name' => 'Medical Oncology' ),
		array( 'code' => 'SURG-ONC', 'name' => 'Surgical Oncology' ),
		array( 'code' => 'RAD-ONC', 'name' => 'Radiation Oncology' ),
		array( 'code' => 'PAED-ONC', 'name' => 'Paediatric Oncology' ),
		array( 'code' => 'PALL-MED', 'name' => 'Palliative Medicine' ),
		array( 'code' => 'PREV-ONC', 'name' => 'Preventive Oncology' ),
	);
}

/** Demo OPD slots: Monday–Saturday, 09:00–12:30 every 30 minutes; some are "booked" (deterministic). */
function mock_slots( string $department, string $date ): array {
	if ( '7' === gmdate( 'N', (int) strtotime( $date . ' 00:00:00 UTC' ) ) ) {
		return array(); // no OPD on Sundays
	}
	$slots = array();
	for ( $minutes = 9 * 60; $minutes <= 12 * 60 + 30; $minutes += 30 ) {
		$time    = sprintf( '%02d:%02d', intdiv( $minutes, 60 ), $minutes % 60 );
		$slots[] = array(
			'id'        => 'S' . str_replace( '-', '', $date ) . str_replace( ':', '', $time ),
			'time'      => $time,
			'available' => 0 !== crc32( $department . $date . $time ) % 4,
		);
	}
	return $slots;
}

function mock_results(): array {
	return array(
		'DEMO1001' => array(
			'date_of_birth'  => '2000-01-15',
			'candidate_name' => 'Demo Candidate One',
			'examination'    => 'Sample examination (demo data)',
			'result'         => 'Qualified',
			'published_on'   => '2026-09-01',
		),
		'DEMO1002' => array(
			'date_of_birth'  => '1999-07-30',
			'candidate_name' => 'Demo Candidate Two',
			'examination'    => 'Sample examination (demo data)',
			'result'         => 'Not qualified',
			'published_on'   => '2026-09-01',
		),
	);
}

function mock_forms(): array {
	return array(
		'feedback' => array(
			'id'          => 'feedback',
			'title'       => 'Website feedback',
			'description' => 'Tell us about a problem with this website or suggest an improvement.',
			'fields'      => array(
				array( 'name' => 'full_name', 'label' => 'Your name', 'type' => 'text', 'required' => false, 'max_length' => 100, 'autocomplete' => 'name' ),
				array( 'name' => 'email', 'label' => 'Email address', 'type' => 'email', 'required' => true, 'max_length' => 254, 'autocomplete' => 'email', 'help' => 'We use this only to reply to you.' ),
				array(
					'name'     => 'topic',
					'label'    => 'What is your feedback about?',
					'type'     => 'select',
					'required' => true,
					'options'  => array(
						array( 'value' => 'content', 'label' => 'Website content' ),
						array( 'value' => 'accessibility', 'label' => 'Accessibility' ),
						array( 'value' => 'technical', 'label' => 'Technical problem' ),
						array( 'value' => 'other', 'label' => 'Something else' ),
					),
				),
				array( 'name' => 'page_url', 'label' => 'Address (URL) of the page', 'type' => 'url', 'required' => false, 'max_length' => 500 ),
				array( 'name' => 'message', 'label' => 'Your feedback', 'type' => 'textarea', 'required' => true, 'max_length' => 2000, 'help' => 'Do not include medical details or other personal information.' ),
				array( 'name' => 'consent', 'label' => 'I agree that TMC may use these details to respond to my feedback.', 'type' => 'checkbox', 'required' => true ),
			),
		),
	);
}

/* ---------------------------------------------------------------- payment orders (tmpfs) */

function mock_orders_dir(): string {
	$dir = sys_get_temp_dir() . '/tmc-mock-orders';
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0700, true );
	}
	return $dir;
}

function mock_order_load( string $id ): ?array {
	if ( ! preg_match( '/^ord_[a-f0-9]{24}$/', $id ) ) {
		return null;
	}
	$file = mock_orders_dir() . "/$id.json";
	if ( ! is_file( $file ) ) {
		return null;
	}
	$order = json_decode( (string) file_get_contents( $file ), true );
	return is_array( $order ) ? $order : null;
}

function mock_order_save( array $order ): void {
	file_put_contents( mock_orders_dir() . '/' . $order['order_id'] . '.json', json_encode( $order ), LOCK_EX );
}

function mock_orders_gc(): void {
	foreach ( glob( mock_orders_dir() . '/ord_*.json' ) ?: array() as $file ) {
		if ( filemtime( $file ) < time() - 86400 ) {
			unlink( $file );
		}
	}
}

/* ---------------------------------------------------------------- request */

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = (string) parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH );

if ( '/health' === $path ) {
	mock_out( 200, array( 'status' => 'ok' ) );
}

$key = (string) getenv( 'MOCK_API_KEY' );
if ( '' === $key ) {
	mock_out( 503, array( 'error' => 'not_configured', 'detail' => 'MOCK_API_KEY is not set on ' . MOCK_HOST ) );
}
if ( ! hash_equals( $key, (string) ( $_SERVER['HTTP_X_API_KEY'] ?? '' ) ) ) {
	mock_out( 401, array( 'error' => 'unauthorized', 'detail' => 'Missing or wrong X-API-Key for http://' . MOCK_HOST . ':8080' ) );
}

$body = array();
if ( 'POST' === $method ) {
	$raw  = (string) file_get_contents( 'php://input' );
	$body = '' === $raw ? array() : json_decode( $raw, true );
	if ( ! is_array( $body ) ) {
		mock_out( 400, array( 'error' => 'bad_json', 'detail' => 'Body is not JSON (parser at ' . MOCK_HOST . ')' ) );
	}
}
$query = $_GET;
$route = function ( string $want_method, string $pattern ) use ( $method, $path ): ?array {
	return ( $want_method === $method && preg_match( '#^' . $pattern . '$#', $path, $m ) ) ? $m : null;
};

/* ---------------------------------------------------------------- appointments */

if ( $route( 'GET', '/v1/appointments/departments' ) ) {
	mock_out( 200, array( 'departments' => mock_departments() ) );
}

if ( $route( 'GET', '/v1/appointments/slots' ) ) {
	$department = (string) ( $query['department'] ?? '' );
	$date       = (string) ( $query['date'] ?? '' );
	if ( ! in_array( $department, array_column( mock_departments(), 'code' ), true ) ) {
		mock_out( 404, array( 'error' => 'unknown_department', 'detail' => "No department '$department' in " . MOCK_HOST . ' database' ) );
	}
	if ( ! mock_valid_date( $date ) ) {
		mock_out( 422, array( 'error' => 'validation_failed', 'fields' => array( 'date' => 'Bad date format at ' . MOCK_HOST ) ) );
	}
	mock_out( 200, array( 'date' => $date, 'department' => $department, 'slots' => mock_slots( $department, $date ) ) );
}

if ( $route( 'POST', '/v1/appointments/requests' ) ) {
	$errors = array();
	foreach ( array( 'department', 'date', 'slot', 'patient_name', 'mobile', 'patient_type' ) as $field ) {
		if ( empty( $body[ $field ] ) || ! is_string( $body[ $field ] ) ) {
			$errors[ $field ] = "Field $field required (validator " . MOCK_HOST . ')';
		}
	}
	if ( true !== ( $body['consent'] ?? null ) ) {
		$errors['consent'] = 'Consent missing';
	}
	if ( $errors ) {
		mock_out( 422, array( 'error' => 'validation_failed', 'fields' => $errors ) );
	}
	if ( 'DEMO-FAIL-500' === $body['patient_name'] ) {
		mock_out( 500, array( 'error' => 'internal', 'trace' => 'PDOException at /app/router.php:1 while connecting to db.' . MOCK_HOST . ':5432' ) );
	}
	if ( ! in_array( $body['department'], array_column( mock_departments(), 'code' ), true ) || ! mock_valid_date( $body['date'] ) ) {
		mock_out( 422, array( 'error' => 'validation_failed', 'fields' => array( 'department' => 'Unknown department or date' ) ) );
	}
	$slot = null;
	foreach ( mock_slots( $body['department'], $body['date'] ) as $candidate ) {
		if ( $candidate['id'] === $body['slot'] ) {
			$slot = $candidate;
		}
	}
	if ( ! $slot || ! $slot['available'] ) {
		mock_out( 409, array( 'error' => 'slot_unavailable', 'detail' => 'Slot ' . $body['slot'] . ' taken (checked on ' . MOCK_HOST . ')' ) );
	}
	// A real backend would now create the request in TMC's system. The mock keeps nothing.
	mock_out( 201, array( 'reference' => 'DEMO-APT-' . strtoupper( bin2hex( random_bytes( 4 ) ) ), 'status' => 'received', 'time' => $slot['time'] ) );
}

/* ---------------------------------------------------------------- results */

if ( $route( 'POST', '/v1/results/lookup' ) ) {
	$roll    = strtoupper( (string) ( $body['roll_number'] ?? '' ) );
	$dob     = (string) ( $body['date_of_birth'] ?? '' );
	$records = mock_results();
	if ( '' === $roll || ! mock_valid_date( $dob ) ) {
		mock_out( 422, array( 'error' => 'validation_failed', 'fields' => array( 'roll_number' => 'required', 'date_of_birth' => 'required' ) ) );
	}
	if ( ! isset( $records[ $roll ] ) || $records[ $roll ]['date_of_birth'] !== $dob ) {
		mock_out( 404, array( 'error' => 'not_found', 'detail' => "No record for $roll in results table on " . MOCK_HOST ) );
	}
	$record = $records[ $roll ];
	unset( $record['date_of_birth'] );
	mock_out( 200, array( 'roll_number' => $roll ) + $record );
}

/* ---------------------------------------------------------------- online forms */

if ( $m = $route( 'GET', '/v1/forms/([a-z0-9_-]{1,40})/schema' ) ) {
	$forms = mock_forms();
	if ( ! isset( $forms[ $m[1] ] ) ) {
		mock_out( 404, array( 'error' => 'unknown_form', 'detail' => 'Form ' . $m[1] . ' not on ' . MOCK_HOST ) );
	}
	mock_out( 200, array( 'form' => $forms[ $m[1] ] ) );
}

if ( $m = $route( 'POST', '/v1/forms/([a-z0-9_-]{1,40})/submissions' ) ) {
	$forms = mock_forms();
	if ( ! isset( $forms[ $m[1] ] ) ) {
		mock_out( 404, array( 'error' => 'unknown_form' ) );
	}
	$errors = array();
	foreach ( $forms[ $m[1] ]['fields'] as $field ) {
		$value = $body[ $field['name'] ] ?? null;
		if ( $field['required'] && ( null === $value || '' === $value || false === $value ) ) {
			$errors[ $field['name'] ] = 'required (' . MOCK_HOST . ')';
		}
	}
	if ( $errors ) {
		mock_out( 422, array( 'error' => 'validation_failed', 'fields' => $errors ) );
	}
	mock_out( 201, array( 'reference' => 'DEMO-FRM-' . strtoupper( bin2hex( random_bytes( 4 ) ) ), 'status' => 'received' ) );
}

/* ---------------------------------------------------------------- payments (donations) */

if ( $route( 'POST', '/v1/payments/orders' ) ) {
	$amount     = $body['amount'] ?? null;
	$return_url = (string) ( $body['return_url'] ?? '' );
	$parts      = parse_url( $return_url );
	if ( ! is_int( $amount ) || $amount < 1 || $amount > 1000000 ) {
		mock_out( 422, array( 'error' => 'validation_failed', 'fields' => array( 'amount' => 'Amount must be an integer 1..1000000' ) ) );
	}
	if ( ! $parts || ! in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) || empty( $parts['host'] ) ) {
		mock_out( 422, array( 'error' => 'validation_failed', 'fields' => array( 'return_url' => 'Invalid return URL' ) ) );
	}
	if ( empty( $body['donor_name'] ) || empty( $body['email'] ) ) {
		mock_out( 422, array( 'error' => 'validation_failed', 'fields' => array( 'donor_name' => 'required', 'email' => 'required' ) ) );
	}
	mock_orders_gc();
	$order = array(
		'order_id'   => 'ord_' . bin2hex( random_bytes( 12 ) ),
		'amount'     => $amount,
		'currency'   => 'INR',
		'status'     => 'created',
		'return_url' => $return_url,
		'updated_at' => gmdate( 'c' ),
	);
	// Donor details are used by a real gateway for the receipt; the mock does not keep them.
	mock_order_save( $order );
	// A real payment gateway returns the URL of its own hosted payment page. The demo "gateway page"
	// is rendered by the website itself (the mock is not reachable from browsers), on the same host.
	$origin = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
	mock_out(
		201,
		array(
			'order_id'     => $order['order_id'],
			'status'       => 'created',
			'redirect_url' => $origin . '/?tmc_demo_checkout=' . $order['order_id'],
		)
	);
}

if ( $m = $route( 'GET', '/v1/payments/orders/(ord_[a-f0-9]{24})/checkout' ) ) {
	$order = mock_order_load( $m[1] );
	if ( ! $order ) {
		mock_out( 404, array( 'error' => 'order_not_found', 'detail' => 'No order ' . $m[1] . ' on ' . MOCK_HOST ) );
	}
	mock_out( 200, $order );
}

if ( $m = $route( 'POST', '/v1/payments/orders/(ord_[a-f0-9]{24})/simulate' ) ) {
	$order    = mock_order_load( $m[1] );
	$outcomes = array( 'success' => 'paid', 'failure' => 'failed', 'cancel' => 'cancelled' );
	if ( ! $order ) {
		mock_out( 404, array( 'error' => 'order_not_found' ) );
	}
	if ( ! isset( $outcomes[ $body['outcome'] ?? '' ] ) ) {
		mock_out( 422, array( 'error' => 'validation_failed', 'fields' => array( 'outcome' => 'success|failure|cancel' ) ) );
	}
	if ( 'created' !== $order['status'] ) {
		mock_out( 409, array( 'error' => 'order_closed', 'detail' => 'Order already ' . $order['status'] ) );
	}
	$order['status']     = $outcomes[ $body['outcome'] ];
	$order['updated_at'] = gmdate( 'c' );
	if ( 'paid' === $order['status'] ) {
		$order['transaction_ref'] = 'DEMO-TXN-' . strtoupper( bin2hex( random_bytes( 5 ) ) );
	}
	mock_order_save( $order );
	mock_out( 200, array( 'order_id' => $order['order_id'], 'status' => $order['status'], 'return_url' => $order['return_url'] ) );
}

if ( $m = $route( 'GET', '/v1/payments/orders/(ord_[a-f0-9]{24})' ) ) {
	$order = mock_order_load( $m[1] );
	if ( ! $order ) {
		mock_out( 404, array( 'error' => 'order_not_found', 'detail' => 'No order ' . $m[1] . ' on ' . MOCK_HOST ) );
	}
	unset( $order['return_url'] );
	mock_out( 200, $order );
}

mock_out( 404, array( 'error' => 'no_route', 'detail' => "$method $path is not served by " . MOCK_HOST ) );
