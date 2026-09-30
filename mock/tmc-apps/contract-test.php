<?php
/**
 * Contract test for the DEMO mock backend (no WordPress needed). Starts the mock with PHP's built-in
 * server inside a throwaway container and checks the interface described in docs/integration/gateway.md.
 *
 *   docker run --rm -v "$PWD/mock/tmc-apps":/app:ro -w /app php:8.3.35-cli-alpine php contract-test.php
 *
 * Used by .github/workflows/apps-gateway.yml.
 */

declare(strict_types=1);

$key  = bin2hex( random_bytes( 16 ) );
$port = 18080;
$cmd  = sprintf( 'MOCK_API_KEY=%s php -S 127.0.0.1:%d %s/router.php > /dev/null 2>&1 & echo $!', escapeshellarg( $key ), $port, escapeshellarg( __DIR__ ) );
$pid  = (int) shell_exec( $cmd );
register_shutdown_function( static fn() => $pid && posix_kill( $pid, 15 ) );

$base = "http://127.0.0.1:$port";
for ( $i = 0; $i < 50 && false === @file_get_contents( "$base/health" ); $i++ ) {
	usleep( 100000 );
}

$pass = 0;
$fail = 0;
$t    = function ( string $label, bool $ok ) use ( &$pass, &$fail ): void {
	echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . $label . PHP_EOL;
	$ok ? $pass++ : $fail++;
};

/** @return array{0:int,1:array} */
$call = function ( string $method, string $path, ?array $body = null, ?string $api_key = null ) use ( $base, $key ): array {
	$headers = array( 'Accept: application/json', 'X-API-Key: ' . ( $api_key ?? $key ) );
	if ( null !== $body ) {
		$headers[] = 'Content-Type: application/json';
	}
	$context = stream_context_create(
		array(
			'http' => array(
				'method'        => $method,
				'header'        => implode( "\r\n", $headers ),
				'content'       => null === $body ? '' : json_encode( $body ),
				'ignore_errors' => true,
				'timeout'       => 5,
			),
		)
	);
	$raw    = (string) @file_get_contents( $base . $path, false, $context );
	$status = 0;
	foreach ( $http_response_header ?? array() as $line ) {
		if ( preg_match( '#^HTTP/\S+ (\d{3})#', $line, $m ) ) {
			$status = (int) $m[1];
		}
	}
	return array( $status, (array) json_decode( $raw, true ) );
};

echo "— authentication\n";
[ $s ] = $call( 'GET', '/v1/appointments/departments', null, 'wrong-key' );
$t( 'wrong API key → 401', 401 === $s );
[ $s, $b ] = $call( 'GET', '/v1/appointments/departments' );
$t( 'correct API key → 200 with departments', 200 === $s && count( $b['departments'] ?? array() ) > 0 );
$t( 'responses are labelled demo', true === ( $b['demo'] ?? null ) );

echo "— appointments\n";
$date = gmdate( 'Y-m-d', strtotime( 'next monday' ) );
[ $s, $b ] = $call( 'GET', "/v1/appointments/slots?department=MED-ONC&date=$date" );
$free      = array_values( array_filter( $b['slots'] ?? array(), static fn( $x ) => $x['available'] ) );
$taken     = array_values( array_filter( $b['slots'] ?? array(), static fn( $x ) => ! $x['available'] ) );
$t( 'Monday has slots, some free', 200 === $s && count( $free ) > 0 );
[ $s, $b ] = $call( 'GET', '/v1/appointments/slots?department=MED-ONC&date=' . gmdate( 'Y-m-d', strtotime( 'next sunday' ) ) );
$t( 'Sunday has no slots', 200 === $s && array() === $b['slots'] );
[ $s ] = $call( 'GET', "/v1/appointments/slots?department=NOPE&date=$date" );
$t( 'unknown department → 404', 404 === $s );
$request = array( 'department' => 'MED-ONC', 'date' => $date, 'slot' => $free[0]['id'] ?? '', 'patient_name' => 'Test', 'mobile' => '9876543210', 'patient_type' => 'new', 'consent' => true );
[ $s, $b ] = $call( 'POST', '/v1/appointments/requests', $request );
$t( 'request for a free slot → 201 with reference', 201 === $s && str_starts_with( $b['reference'] ?? '', 'DEMO-APT-' ) );
if ( $taken ) {
	[ $s ] = $call( 'POST', '/v1/appointments/requests', array( 'slot' => $taken[0]['id'] ) + $request );
	$t( 'request for a booked slot → 409', 409 === $s );
}
[ $s ] = $call( 'POST', '/v1/appointments/requests', array( 'patient_name' => 'DEMO-FAIL-500' ) + $request );
$t( 'DEMO-FAIL-500 simulates a backend fault → 500', 500 === $s );

echo "— results\n";
[ $s, $b ] = $call( 'POST', '/v1/results/lookup', array( 'roll_number' => 'DEMO1001', 'date_of_birth' => '2000-01-15' ) );
$t( 'known roll number + date of birth → 200', 200 === $s && 'Qualified' === ( $b['result'] ?? '' ) && ! isset( $b['date_of_birth'] ) );
[ $s ] = $call( 'POST', '/v1/results/lookup', array( 'roll_number' => 'DEMO1001', 'date_of_birth' => '2000-01-16' ) );
$t( 'wrong date of birth → 404', 404 === $s );

echo "— forms\n";
[ $s, $b ] = $call( 'GET', '/v1/forms/feedback/schema' );
$t( 'feedback schema has fields', 200 === $s && count( $b['form']['fields'] ?? array() ) >= 3 );
[ $s ] = $call( 'POST', '/v1/forms/feedback/submissions', array( 'email' => 'a@example.com', 'topic' => 'other', 'message' => 'Hi', 'consent' => true ) );
$t( 'valid submission → 201', 201 === $s );
[ $s ] = $call( 'POST', '/v1/forms/feedback/submissions', array( 'email' => 'a@example.com' ) );
$t( 'missing required fields → 422', 422 === $s );

echo "— payments\n";
[ $s, $b ] = $call( 'POST', '/v1/payments/orders', array( 'amount' => 500, 'donor_name' => 'Test', 'email' => 'a@example.com', 'return_url' => 'http://tmh.tmc.localhost/donate/?tmc_donation=return' ) );
$order     = (string) ( $b['order_id'] ?? '' );
$t( 'order created with same-host demo redirect', 201 === $s && str_starts_with( $b['redirect_url'] ?? '', 'http://tmh.tmc.localhost/?tmc_demo_checkout=ord_' ) );
[ $s, $b ] = $call( 'GET', "/v1/payments/orders/$order" );
$t( 'new order status = created', 200 === $s && 'created' === ( $b['status'] ?? '' ) && 500 === ( $b['amount'] ?? 0 ) );
[ $s, $b ] = $call( 'POST', "/v1/payments/orders/$order/simulate", array( 'outcome' => 'success' ) );
$t( 'simulated success → paid', 200 === $s && 'paid' === ( $b['status'] ?? '' ) );
[ $s, $b ] = $call( 'GET', "/v1/payments/orders/$order" );
$t( 'status now paid with transaction reference', 'paid' === ( $b['status'] ?? '' ) && str_starts_with( $b['transaction_ref'] ?? '', 'DEMO-TXN-' ) );
[ $s ] = $call( 'POST', "/v1/payments/orders/$order/simulate", array( 'outcome' => 'failure' ) );
$t( 'closed order cannot change → 409', 409 === $s );
[ $s ] = $call( 'POST', '/v1/payments/orders', array( 'amount' => 0, 'donor_name' => 'x', 'email' => 'x', 'return_url' => 'http://x/' ) );
$t( 'amount 0 → 422', 422 === $s );

echo PHP_EOL . ( $fail ? "FAILED: $fail failed, $pass passed" : "OK: all $pass checks passed" ) . PHP_EOL;
exit( $fail ? 1 : 0 );
