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
 * Interface specification: docs/integration/gateway.md. Module files: apps-registry.php (catalogue and
 * registry), apps-validation.php (input), apps-responses.php (allow-lists), apps-admin.php (settings
 * screen), the theme's inc/apps-blocks.php (front ends) and this file (protection, the call, REST
 * interface).
 */

defined( 'ABSPATH' ) || exit;

const TMC_APPS_OPTION = 'tmc_apps_registry';

/** Demo environments (local, CI, UAT) set TMC_DEMO=1: demo services and data are shown and labelled. */
function tmc_is_demo() {
	return '1' === (string) getenv( 'TMC_DEMO' );
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

/** Count a request against the service's per-IP, per-minute limit (shared limiter: rate-limit.php). */
function tmc_apps_rate_hit( $bucket, $limit ) {
	return tmc_rate_limit( 'apps_' . $bucket, $limit )['over'];
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
		// Refusals count against the rate limit too, so a flood cannot fill the audit log.
		$meta = array( 'service' => $service, 'action' => $action, 'channel' => 'rest', 'request_id' => '' );
		$over = tmc_apps_rate_hit( $service, $svc['rate_limit'] );
		if ( $over ) {
			$result = tmc_apps_result( 429, 'rate' );
			if ( 1 === $over ) {
				tmc_apps_log( $meta, $result, 'rate_limited' );
			}
		} else {
			$result = $refuse;
			tmc_apps_log( $meta, $result, 405 === $result['status'] ? 'refused_method' : 'refused_token' );
		}
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
