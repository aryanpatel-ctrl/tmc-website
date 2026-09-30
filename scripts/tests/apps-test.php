<?php
/**
 * TMC application gateway (R-4.4-1..3, R-4.12-1..3, R-4.8-1), front ends, map and share links.
 *
 * Registers its own services (pointing at the DEMO mock backend tmc-apps-mock on the tmc_apps
 * network), exercises them through the public REST interface, then restores the registry and removes
 * everything it created. Needs the mock container and TMC_APPS_MOCK_KEY (docker-compose.yml).
 *
 *   docker compose run --rm -T wpcli --url=tmh.<base> eval-file - < scripts/tests/apps-test.php
 */

global $wpdb;
$pass = 0;
$fail = 0;
$t    = function ( $label, $ok ) use ( &$pass, &$fail ) {
	WP_CLI::log( ( $ok ? '  PASS  ' : '  FAIL  ' ) . $label );
	$ok ? $pass++ : $fail++;
};

if ( ! function_exists( 'tmc_apps_call' ) || ! function_exists( 'tmc_render_app_donate' ) ) {
	WP_CLI::error( 'tmc-core application gateway or TMC theme not loaded' );
}
if ( '' === (string) getenv( 'TMC_APP_RESULTS_KEY' ) ) {
	WP_CLI::error( 'TMC_APP_RESULTS_KEY is not set in this container (TMC_APPS_MOCK_KEY missing from .env?)' );
}

/* ---------------------------------------------------------------- fixtures */

$tag    = strtolower( wp_generate_password( 6, false ) );
$marker = 'Zq' . implode( '', array_map( fn() => chr( wp_rand( 97, 122 ) ), range( 1, 14 ) ) ); // letters only: passes name rules
$email  = strtolower( $marker ) . '@example.com';
$mobile = '9876543210';

$_SERVER['REMOTE_ADDR'] = '203.0.113.' . wp_rand( 1, 254 ); // documentation range: own rate-limit buckets
unset( $_SERVER['HTTP_ORIGIN'] );

$saved_registry = get_site_option( TMC_APPS_OPTION, null );
$audit_start    = (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id),0) FROM ' . tmc_audit_table() ); // phpcs:ignore WordPress.DB.PreparedSQL
$names          = array();
foreach ( array( 'appt', 'res', 'form', 'pay', 'rate', 'down', 'nokey' ) as $short ) {
	$names[ $short ] = "w4t_{$tag}_{$short}";
}
$service = fn( $type, array $actions, array $extra = array() ) => $extra + array(
	'type'           => $type,
	'label'          => "Test $type",
	'base_url'       => "http://tmc-apps-mock:8080/v1/$type",
	'actions'        => $actions,
	'timeout'        => 5,
	'rate_limit'     => 60,
	'enabled'        => true,
	'demo'           => true,
	'key_env'        => 'TMC_APP_' . strtoupper( $type ) . '_KEY',
	'redirect_hosts' => array( 'self' ),
);
update_site_option(
	TMC_APPS_OPTION,
	array_merge(
		is_array( $saved_registry ) ? $saved_registry : array(),
		array(
			$names['appt']  => $service( 'appointments', array( 'departments', 'slots', 'request' ) ),
			$names['res']   => $service( 'results', array( 'lookup' ) ),
			$names['form']  => $service( 'forms', array( 'schema', 'submit' ) ),
			$names['pay']   => $service( 'payments', array( 'initiate', 'status', 'checkout', 'simulate' ) ),
			$names['rate']  => $service( 'results', array( 'lookup' ), array( 'rate_limit' => 3 ) ),
			$names['down']  => $service( 'appointments', array( 'departments' ), array( 'base_url' => 'http://tmc-apps-mock:9/v1/appointments', 'timeout' => 2 ) ),
			$names['nokey'] => $service( 'forms', array( 'schema' ), array( 'key_env' => 'TMC_APP_W4TEST_NOT_SET_KEY' ) ),
		)
	)
);

$donate_page = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => "Test donate $tag",
		'post_content' => '<!-- wp:tmc/app-donate {"service":"' . $names['pay'] . '"} /-->',
	)
);
if ( function_exists( 'pll_set_post_language' ) ) {
	pll_set_post_language( $donate_page, 'en' );
}

$cleaned = false;
$cleanup = function () use ( &$cleaned, $saved_registry, $names, $donate_page ) {
	if ( $cleaned ) {
		return;
	}
	$cleaned = true;
	if ( null === $saved_registry ) {
		delete_site_option( TMC_APPS_OPTION );
	} else {
		update_site_option( TMC_APPS_OPTION, $saved_registry );
	}
	foreach ( array_merge( array_values( $names ), array( '_unknown' ) ) as $bucket ) {
		tmc_rate_limit_reset( 'apps_' . $bucket );
	}
	delete_transient( 'tmc_apps_c_' . md5( $names['appt'] . '|departments|' . wp_json_encode( array() ) ) );
	delete_transient( 'tmc_apps_c_' . md5( $names['form'] . '|schema|' . wp_json_encode( array( 'form' => 'feedback' ) ) ) );
	wp_delete_post( $donate_page, true );
	unset( $_GET['tmc_donation'], $_GET['order'] );
};
register_shutdown_function( $cleanup ); // also runs if WP_CLI::error() exits early

/* ---------------------------------------------------------------- helpers */

$seen = ''; // everything the public interface returned, for the "no upstream details" checks
$rest = function ( $method, $svc, $action, array $params = array(), $token = true ) use ( &$seen ) {
	$request = new WP_REST_Request( $method, "/tmc/v1/apps/$svc/$action" );
	if ( 'GET' === $method ) {
		$request->set_query_params( $params );
	} else {
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $params ) );
		if ( true === $token ) {
			$request->set_header( 'X-TMC-Token', tmc_apps_token() );
		} elseif ( is_string( $token ) ) {
			$request->set_header( 'X-TMC-Token', $token );
		}
	}
	$response = rest_do_request( $request );
	$json     = wp_json_encode( $response->get_data() );
	$seen    .= $json . wp_json_encode( $response->get_headers() );
	return array( $response->get_status(), (array) json_decode( $json, true ), $response->get_headers() );
};

WP_CLI::log( '— Mock backend reachable' );
$probe = tmc_apps_call( $names['appt'], 'departments', array(), array( 'internal' => true ) );
if ( ! $probe['ok'] ) {
	WP_CLI::error( 'the DEMO mock backend (tmc-apps-mock:8080 on network tmc_apps) did not answer: ' . $probe['code'] );
}
$t( 'server-side call to the mock returns departments', count( $probe['data']['departments'] ) > 0 );

/* ---------------------------------------------------------------- allow-listed calls */

WP_CLI::log( '— Allow-listed calls succeed through the gateway' );
list( $status, $body ) = $rest( 'GET', $names['appt'], 'departments' );
$t( "GET departments → 200 ($status)", 200 === $status && true === $body['ok'] && ! empty( $body['data']['departments'] ) );
$department = $body['data']['departments'][0]['code'] ?? 'MED-ONC';

// A date (tomorrow … +30 days) that has both free and booked times.
$date  = '';
$free  = array();
$taken = array();
for ( $day = 1; $day <= 30 && ( ! $free || ! $taken ); $day++ ) {
	$try = wp_date( 'Y-m-d', time() + $day * DAY_IN_SECONDS );
	list( $status, $body ) = $rest( 'GET', $names['appt'], 'slots', array( 'department' => $department, 'date' => $try ) );
	$slots = 200 === $status ? $body['data']['slots'] : array();
	$free  = array_values( array_filter( $slots, fn( $s ) => $s['available'] ) );
	$taken = array_values( array_filter( $slots, fn( $s ) => ! $s['available'] ) );
	$date  = $try;
}
$t( "GET slots → free and booked times found ($date)", (bool) $free && (bool) $taken );

$appointment = array(
	'department'   => $department,
	'date'         => $date,
	'slot'         => $free[0]['id'] ?? '',
	'patient_type' => 'new',
	'patient_name' => "Test $marker",
	'mobile'       => $mobile,
	'email'        => $email,
	'consent'      => true,
	'unexpected'   => "dropped $marker",
);
list( $status, $body ) = $rest( 'POST', $names['appt'], 'request', $appointment );
$t( "POST appointment request → 200 with reference ($status)", 200 === $status && 0 === strpos( $body['data']['reference'] ?? '', 'DEMO-APT-' ) );
$t( 'result HTML rendered by the theme shows the reference', false !== strpos( $body['html'] ?? '', (string) ( $body['data']['reference'] ?? '-' ) ) );

list( $status, $body ) = $rest( 'POST', $names['appt'], 'request', array( 'slot' => $taken[0]['id'] ?? '' ) + $appointment );
$t( "booked time → 409 with our own message ($status)", 409 === $status && 'conflict' === $body['code'] );

list( $status, $body ) = $rest( 'POST', $names['res'], 'lookup', array( 'roll_number' => 'demo1001', 'date_of_birth' => '2000-01-15' ) );
$t( "POST results lookup → 200 ($status)", 200 === $status && 'Qualified' === ( $body['data']['result'] ?? '' ) && false !== strpos( $body['html'] ?? '', 'Qualified' ) );
list( $status, $body ) = $rest( 'POST', $names['res'], 'lookup', array( 'roll_number' => 'DEMO1001', 'date_of_birth' => '2000-01-16' ) );
$t( "wrong date of birth → 404 not_found ($status)", 404 === $status && 'not_found' === $body['code'] );

list( $status, $body ) = $rest( 'GET', $names['form'], 'schema', array( 'form' => 'feedback' ) );
$t( "GET form schema → 200 with fields ($status)", 200 === $status && count( $body['data']['fields'] ?? array() ) >= 3 );
$feedback = array( 'form' => 'feedback', 'full_name' => "Test $marker", 'email' => $email, 'topic' => 'other', 'message' => "Feedback $marker", 'consent' => true );
list( $status, $body ) = $rest( 'POST', $names['form'], 'submit', $feedback );
$t( "POST online form → 200 with reference ($status)", 200 === $status && 0 === strpos( $body['data']['reference'] ?? '', 'DEMO-FRM-' ) );
list( $status, $body ) = $rest( 'POST', $names['form'], 'submit', array( 'topic' => 'nonsense' ) + $feedback );
$t( "value outside the backend schema options → 422 on that field ($status)", 422 === $status && isset( $body['errors']['topic'] ) );

WP_CLI::log( '— Donation: initiate → gateway → verified status' );
$donation              = array( 'amount' => '1,500', 'donor_name' => "Donor $marker", 'email' => $email, 'mobile' => $mobile, 'consent' => true, 'page' => $donate_page );
list( $status, $body ) = $rest( 'POST', $names['pay'], 'initiate', $donation );
$redirect              = (string) ( $body['data']['redirect_url'] ?? '' );
$order                 = (string) ( $body['data']['order_id'] ?? '' );
$t( "POST initiate → 200 with redirect to an allowed host ($status)", 200 === $status && wp_parse_url( $redirect, PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST ) && false !== strpos( $redirect, 'tmc_demo_checkout=' ) );
list( $status, $body ) = $rest( 'POST', $names['pay'], 'initiate', array( 'page' => 0 ) + $donation );
$t( "initiate without a donate page to return to → refused ($status)", 503 === $status );
list( $status, $body ) = $rest( 'GET', $names['pay'], 'status', array( 'order_id' => $order ) );
$t( "status before payment = created ($status)", 200 === $status && 'created' === ( $body['data']['status'] ?? '' ) && 1500 === ( $body['data']['amount'] ?? 0 ) );
list( $status ) = $rest( 'POST', $names['pay'], 'simulate', array( 'order_id' => $order, 'outcome' => 'success' ) );
$t( "demo-only simulate action is not reachable over REST ($status)", 404 === $status );
$simulated = tmc_apps_call( $names['pay'], 'simulate', array( 'order_id' => $order, 'outcome' => 'success' ), array( 'internal' => true ) );
$t( 'demo gateway (server side) completes the payment and returns to the donate page', $simulated['ok'] && false !== strpos( $simulated['data']['return_url'], 'tmc_donation=return' ) );
$_GET['tmc_donation'] = 'return';
$_GET['order']        = $order;
$page_html            = render_block( parse_blocks( '<!-- wp:tmc/app-donate {"service":"' . $names['pay'] . '"} /-->' )[0] );
unset( $_GET['tmc_donation'], $_GET['order'] );
$seen .= $page_html;
$t( 'return page verifies the status with the backend: "Thank you for your donation"', false !== strpos( $page_html, 'Thank you for your donation' ) && false !== strpos( $page_html, 'DEMO-TXN-' ) );

/* ---------------------------------------------------------------- refusals */

WP_CLI::log( '— Unknown services and actions are refused' );
list( $status, $unknown ) = $rest( 'GET', "nosuch_$tag", 'departments' );
$t( "unknown service → 404 ($status)", 404 === $status && 'unknown' === $unknown['code'] );
list( $status, $body ) = $rest( 'POST', $names['res'], 'submit', array() );
$t( "action not allowed for the service → same 404 ($status)", 404 === $status && $body['message'] === $unknown['message'] );
list( $status ) = $rest( 'GET', $names['down'], 'slots', array( 'department' => $department, 'date' => $date ) );
$t( "catalogue action not enabled in the registry → 404 ($status)", 404 === $status );
list( $status ) = $rest( 'GET', $names['pay'], 'checkout', array( 'order_id' => $order ) );
$t( "server-only action (checkout) → 404 over REST ($status)", 404 === $status );
list( $status, , $headers ) = $rest( 'GET', $names['res'], 'lookup', array( 'roll_number' => 'DEMO1001', 'date_of_birth' => '2000-01-15' ) );
$t( "wrong method → 405 with Allow: POST ($status)", 405 === $status && 'POST' === ( $headers['Allow'] ?? '' ) );

WP_CLI::log( '— Write protection and input validation' );
list( $status ) = $rest( 'POST', $names['res'], 'lookup', array( 'roll_number' => 'DEMO1001', 'date_of_birth' => '2000-01-15' ), false );
$t( "POST without page token → 403 ($status)", 403 === $status );
list( $status ) = $rest( 'POST', $names['res'], 'lookup', array( 'roll_number' => 'DEMO1001', 'date_of_birth' => '2000-01-15' ), 'forged-token' );
$t( "POST with a forged token → 403 ($status)", 403 === $status );
$_SERVER['HTTP_ORIGIN'] = 'https://attacker.example';
list( $status )         = $rest( 'POST', $names['res'], 'lookup', array( 'roll_number' => 'DEMO1001', 'date_of_birth' => '2000-01-15' ) );
unset( $_SERVER['HTTP_ORIGIN'] );
$t( "cross-site Origin → 403 ($status)", 403 === $status );
$t( 'token of the previous 12-hour period still valid, older ones not', tmc_apps_verify_token( tmc_apps_token( 1 ) ) && ! tmc_apps_verify_token( tmc_apps_token( 2 ) ) );
list( $status, $body ) = $rest( 'POST', $names['res'], 'lookup', array( 'roll_number' => 'x', 'date_of_birth' => wp_date( 'Y-m-d', time() + DAY_IN_SECONDS ) ) );
$t( "invalid input → 422 with an error per field ($status)", 422 === $status && isset( $body['errors']['roll_number'], $body['errors']['date_of_birth'] ) );
list( $status, $body ) = $rest( 'POST', $names['appt'], 'request', array( 'mobile' => '12345', 'consent' => false ) + $appointment );
$t( "bad mobile + missing consent → field errors ($status)", 422 === $status && isset( $body['errors']['mobile'], $body['errors']['consent'] ) );
list( $valid ) = tmc_apps_validate( array( 'm' => array( 'type' => 'mobile', 'label' => 'M' ) ), array( 'm' => '+91 98765-43210' ) );
$t( 'mobile number normalised (+91 / spaces / hyphens removed)', '9876543210' === ( $valid['m'] ?? '' ) );

WP_CLI::log( '— Upstream problems never leak' );
list( $status, $body ) = $rest( 'GET', $names['down'], 'departments' );
$t( "backend unreachable → 503 generic message ($status)", 503 === $status && 'unavailable' === $body['code'] );
list( $status, $body ) = $rest( 'POST', $names['appt'], 'request', array( 'patient_name' => 'Demo Server Error' ) + $appointment );
$t( "backend fault (500 with stack trace) → 503 generic message ($status)", 503 === $status && 'unavailable' === $body['code'] );
list( $status, $body ) = $rest( 'GET', $names['nokey'], 'schema', array( 'form' => 'feedback' ) );
$t( "service without an API key in the environment → 503 ($status)", 503 === $status );
$rendered = render_block( parse_blocks( '<!-- wp:tmc/app-appointment {"service":"' . $names['appt'] . '"} /-->' )[0] );
$seen    .= $rendered;
$t( 'appointment block renders the form with departments from the backend', false !== strpos( $rendered, 'data-tmc-app="appointment"' ) && false !== strpos( $rendered, 'value="' . $department . '"' ) );
foreach ( array( 'tmc-apps-mock', ':8080', '/v1/appointments', '/v1/results', '/v1/forms', '/v1/payments', 'PDOException', 'X-API-Key', (string) getenv( 'TMC_APP_RESULTS_KEY' ) ) as $needle ) {
	$t( "no response or page contains '" . ( strlen( $needle ) > 20 ? 'the API key' : $needle ) . "'", false === stripos( $seen, $needle ) );
}

/* ---------------------------------------------------------------- rate limit */

WP_CLI::log( '— Rate limit per IP' );
$codes = array();
for ( $i = 0; $i < 5; $i++ ) {
	list( $status, , $headers ) = $rest( 'POST', $names['rate'], 'lookup', array( 'roll_number' => 'DEMO1002', 'date_of_birth' => '1999-07-30' ) );
	$codes[]                    = $status;
}
$t( 'limit 3/min: requests 1–3 answered, 4–5 refused (' . implode( ',', $codes ) . ')', array( 200, 200, 200, 429, 429 ) === $codes );
$t( 'refusal carries Retry-After', '60' === (string) ( $headers['Retry-After'] ?? '' ) );
$_SERVER['REMOTE_ADDR'] = '198.51.100.' . wp_rand( 1, 254 );
list( $status )         = $rest( 'POST', $names['rate'], 'lookup', array( 'roll_number' => 'DEMO1002', 'date_of_birth' => '1999-07-30' ) );
$t( "another IP is not affected ($status)", 200 === $status );
tmc_rate_limit_reset( 'apps_' . $names['rate'] );

/* ---------------------------------------------------------------- no-JavaScript form post */

WP_CLI::log( '— No-JavaScript form post is processed on the server' );
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST                     = wp_slash(
	array(
		'tmc_app'          => 'results',
		'tmc_app_service'  => $names['res'],
		'tmc_app_instance' => 'tmc-app-results-1',
		'_tmc_token'       => tmc_apps_token(),
		'roll_number'      => 'DEMO1001',
		'date_of_birth'    => '2000-01-15',
	)
);
tmc_app_handle_post();
$nojs = render_block( parse_blocks( '<!-- wp:tmc/app-results {"service":"' . $names['res'] . '"} /-->' )[0] );
$_POST = array();
$_SERVER['REQUEST_METHOD'] = 'GET';
unset( $GLOBALS['tmc_app_posted'] );
$t( 'result shown in the page without JavaScript', false !== strpos( $nojs, 'Qualified' ) && false !== strpos( $nojs, 'role="status"' ) );

/* ---------------------------------------------------------------- nothing stored */

WP_CLI::log( '— Website stores no submitted data' );
$like   = '%' . $wpdb->esc_like( $marker ) . '%';
$tables = array(
	array( $wpdb->posts, array( 'post_title', 'post_content', 'post_excerpt' ) ),
	array( $wpdb->postmeta, array( 'meta_value' ) ),
	array( $wpdb->comments, array( 'comment_content', 'comment_author', 'comment_author_email' ) ),
	array( $wpdb->commentmeta, array( 'meta_value' ) ),
	array( $wpdb->usermeta, array( 'meta_value' ) ),
	array( $wpdb->users, array( 'user_email', 'display_name' ) ),
	array( $wpdb->sitemeta, array( 'meta_value' ) ),
	array( tmc_audit_table(), array( 'details', 'object_title' ) ),
);
foreach ( get_sites( array( 'number' => 100 ) ) as $site ) {
	$tables[] = array( $wpdb->get_blog_prefix( $site->blog_id ) . 'options', array( 'option_name', 'option_value' ) );
}
$found = array();
foreach ( $tables as list( $table, $columns ) ) {
	$where = implode( ' OR ', array_map( fn( $c ) => "`$c` LIKE %s", $columns ) );
	$hits  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `$table` WHERE $where", array_fill( 0, count( $columns ), $like ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	if ( $hits ) {
		$found[] = "$table ($hits)";
	}
}
$t( 'unique marker from name / email / message found in no table (' . ( $found ? implode( ', ', $found ) : 'posts, meta, options of all sites, users, sitemeta, audit' ) . ')', ! $found );
$key_hits = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->sitemeta} WHERE meta_value LIKE %s", '%' . $wpdb->esc_like( (string) getenv( 'TMC_APP_RESULTS_KEY' ) ) . '%' ) );
$t( 'API key not stored in the database', 0 === $key_hits );

/* ---------------------------------------------------------------- audit: metadata only */

WP_CLI::log( '— Audit trail: metadata only' );
$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT details FROM ' . tmc_audit_table() . " WHERE id > %d AND action = 'app_gateway_call'", $audit_start ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
$ok   = (bool) $rows;
foreach ( $rows as $row ) {
	$details = json_decode( $row['details'], true );
	$ok      = $ok && is_array( $details ) && ! array_diff( array( 'service', 'action', 'status', 'upstream_status', 'latency_ms', 'outcome' ), array_keys( $details ) )
		&& false === strpos( $row['details'], $mobile ) && false === stripos( $row['details'], 'DEMO1001' ) && false === strpos( $row['details'], '2000-01-15' );
}
$t( count( $rows ) . ' gateway calls logged with service/action/status/latency and no submitted values', $ok );
$outcomes = wp_list_pluck( array_map( fn( $r ) => json_decode( $r['details'], true ), $rows ), 'outcome' );
$t( 'rate-limit refusal logged once per window, not per retry', 1 === count( array_keys( $outcomes, 'rate_limited', true ) ) );
$t( 'refusals and upstream failures are logged too', ! array_diff( array( 'refused_unknown', 'invalid_input', 'upstream_unreachable', 'upstream_error', 'not_configured' ), $outcomes ) );
$t( 'audit chain still intact', tmc_audit_verify()['ok'] );

/* ---------------------------------------------------------------- registry validation */

WP_CLI::log( '— Registry validation' );
$t( 'non-http(s) base URL rejected', is_wp_error( tmc_apps_normalize_service( 'ok_name', $service( 'forms', array( 'schema' ), array( 'base_url' => 'ftp://files.example/x' ) ) ) ) );
$t( 'base URL with credentials rejected', is_wp_error( tmc_apps_normalize_service( 'ok_name', $service( 'forms', array( 'schema' ), array( 'base_url' => 'https://u:p@api.example/x' ) ) ) ) );
$t( 'key variable outside TMC_APP_*_KEY rejected (e.g. AUTH_KEY)', is_wp_error( tmc_apps_normalize_service( 'ok_name', $service( 'forms', array( 'schema' ), array( 'key_env' => 'AUTH_KEY' ) ) ) ) );
$t( 'invalid service name rejected', is_wp_error( tmc_apps_normalize_service( 'Bad-Name', $service( 'forms', array( 'schema' ) ) ) ) );
$normalised = tmc_apps_normalize_service( 'ok_name', $service( 'forms', array( 'schema', 'initiate', 'bogus' ) ) );
$t( 'only actions of the service type are kept', array( 'schema' ) === $normalised['actions'] );
$t( 'redirect to a host not on the allow-list refused', ! tmc_apps_redirect_allowed( 'https://evil.example/pay', $service( 'payments', array() ) ) );

/* ---------------------------------------------------------------- pages, map, social */

WP_CLI::log( '— Pages, location map, social' );
$t( 'migration 040 recorded for this site', in_array( '040-app-gateway', (array) get_option( 'tmc_migrations' ), true ) );
foreach ( tmc_w4_page_blocks() as $path => $block ) {
	preg_match( '/wp:([a-z0-9-]+\/[a-z0-9-]+)/', $block, $m );
	$page = get_page_by_path( $path );
	$hi   = $page && function_exists( 'pll_get_post' ) ? pll_get_post( $page->ID, 'hi' ) : 0;
	$t( "/$path/ has $m[1] (EN" . ( $hi ? ' + HI' : '' ) . ')', $page && has_block( $m[1], $page ) && ( ! $hi || has_block( $m[1], get_post( $hi ) ) ) );
}
$t( 'site has a city-level map position', '' !== tmc_map_sanitize_lat( get_theme_mod( 'tmc_map_lat', '' ) ) );
$map = render_block( parse_blocks( '<!-- wp:tmc/location-map {"lat":"19.0760","lon":"72.8777","zoom":12} /-->' )[0] );
$t( 'map block: directions link and consent button, no iframe before consent', false !== strpos( $map, 'openstreetmap.org/directions?to=19.076' ) && false !== strpos( $map, 'tmc-map-load' ) && false === stripos( $map, '<iframe' ) );
$t( 'map block: embed URL prepared with marker', false !== strpos( $map, 'export/embed.html' ) && false !== strpos( $map, 'marker=19.076' ) );
if ( function_exists( 'tmc_csp_directives' ) ) {
	$csp_front = tmc_csp_directives( 'front', false );
	$t( 'CSP allows exactly the OpenStreetMap embed as a frame source', array( "'self'", 'https://www.openstreetmap.org' ) === array_values( $csp_front['frame-src'] ) && ! in_array( 'https://www.openstreetmap.org', (array) ( $csp_front['script-src'] ?? array() ), true ) );
	$t( 'CSP on admin screens is not widened by the map', ! isset( tmc_csp_directives( 'admin', false )['frame-src'] ) );
}
$news  = get_posts( array( 'post_type' => 'post', 'posts_per_page' => 1, 'lang' => '' ) );
$share = $news ? tmc_share_links( $news[0]->ID ) : tmc_share_links( $donate_page );
$t( 'share links are plain links (no third-party script)', false !== strpos( $share, 'facebook.com/sharer' ) && false !== strpos( $share, 'mailto:' ) && false === stripos( $share, '<script' ) );
list( $profiles, $is_demo ) = tmc_social_profiles();
$configured                  = array_filter( array_map( fn( $mod ) => get_theme_mod( $mod, '' ), array_keys( tmc_social_networks() ) ) );
$t( 'social links: configured URLs, or labelled demo links only in demo mode', $configured ? ! $is_demo : ( tmc_is_demo() ? ( $is_demo && count( $profiles ) === 5 ) : ! $profiles ) );

/* ---------------------------------------------------------------- done */

WP_CLI::log( '— Cleanup' );
$cleanup();
$t( 'registry restored', get_site_option( TMC_APPS_OPTION, null ) === $saved_registry );

WP_CLI::log( '' );
$fail ? WP_CLI::error( "$fail failed, $pass passed" ) : WP_CLI::success( "all $pass checks passed" );
