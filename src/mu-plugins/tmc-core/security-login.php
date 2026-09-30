<?php
/**
 * Login protection and user-enumeration defences (tender §4.8; RTM R-4.8-3, R-4.8-6: OWASP A07).
 *
 * Throttling. Failed sign-ins are counted per username and per client IP. After the limit, sign-in
 * is refused for a lockout period that doubles with every further failure (exponential backoff):
 *
 *   TMC_LOGIN_MAX_ATTEMPTS     5      failures per username before lockout
 *   TMC_LOGIN_MAX_ATTEMPTS_IP  20     failures per IP (higher: many staff share one hospital NAT address)
 *   TMC_LOGIN_WINDOW           900    seconds of quiet after which the failure count starts again
 *   TMC_LOGIN_LOCKOUT_BASE     60     seconds of the first lockout; then 2x, 4x, …
 *   TMC_LOGIN_LOCKOUT_MAX      3600   longest single lockout, in seconds
 *
 * While locked, the password is not even checked, so a correct guess is indistinguishable from a
 * wrong one. Counters live in network-wide transients (Redis object cache when present, otherwise
 * the database). Every lockout is written to the audit log.
 *
 * No user enumeration: one generic error for every failed sign-in; the lost-password form answers
 * the same whether or not the account exists; no author archives or ?author=N redirects for the
 * public; no /wp/v2/users REST endpoints for anonymous callers; no author data in oEmbed; no users
 * sitemap; bylines never show a login name.
 */

defined( 'ABSPATH' ) || exit;

function tmc_login_policy() {
	return array(
		'user_max' => tmc_env_int( 'TMC_LOGIN_MAX_ATTEMPTS', 5, 1, 100 ),
		'ip_max'   => tmc_env_int( 'TMC_LOGIN_MAX_ATTEMPTS_IP', 20, 1, 1000 ),
		'window'   => tmc_env_int( 'TMC_LOGIN_WINDOW', 15 * MINUTE_IN_SECONDS, MINUTE_IN_SECONDS, DAY_IN_SECONDS ),
		'base'     => tmc_env_int( 'TMC_LOGIN_LOCKOUT_BASE', MINUTE_IN_SECONDS, 1, HOUR_IN_SECONDS ),
		'cap'      => tmc_env_int( 'TMC_LOGIN_LOCKOUT_MAX', HOUR_IN_SECONDS, MINUTE_IN_SECONDS, WEEK_IN_SECONDS ),
	);
}

function tmc_login_generic_error() {
	return '<strong>Error:</strong> The username, email address or password is incorrect, or sign-in is paused after repeated failed attempts. Please wait a few minutes and try again.';
}

/**
 * Lockout length after $failures failures: 0 below the limit, then base, 2×base, 4×base … up to cap.
 */
function tmc_login_backoff( $failures, $max, $base, $cap ) {
	if ( $failures < $max ) {
		return 0;
	}
	return (int) min( $cap, $base * ( 2 ** min( 30, $failures - $max ) ) );
}

/**
 * Throttle buckets for this attempt. The username bucket is keyed by account ID when the name or
 * email belongs to an account, so switching between username and email does not double the budget.
 * Non-existent names get their own bucket and behave identically (no enumeration).
 *
 * @return array<string,array{key:string,max:int,label:string}>
 */
function tmc_login_buckets( $ip, $username ) {
	$policy   = tmc_login_policy();
	$buckets  = array();
	$username = trim( (string) $username );
	if ( '' !== $username ) {
		$user = is_email( $username ) ? get_user_by( 'email', $username ) : false;
		$user = $user ? $user : get_user_by( 'login', $username );
		$id   = $user ? 'id:' . $user->ID : 'name:' . strtolower( $username );

		$buckets['user'] = array( 'key' => 'tmc_lt_u_' . md5( $id ), 'max' => $policy['user_max'], 'label' => $user ? $user->user_login : $username );
	}
	$ip = tmc_ip_normalise( $ip );
	if ( '' !== $ip ) {
		$buckets['ip'] = array( 'key' => 'tmc_lt_ip_' . md5( $ip ), 'max' => $policy['ip_max'], 'label' => $ip );
	}
	return $buckets;
}

/** @return array{n:int,last:int,until:int} */
function tmc_login_bucket_state( $key ) {
	$state = get_site_transient( $key );
	return is_array( $state ) ? array_merge( array( 'n' => 0, 'last' => 0, 'until' => 0 ), $state ) : array( 'n' => 0, 'last' => 0, 'until' => 0 );
}

/** Seconds until sign-in is possible again for this IP + username (0 = not locked). */
function tmc_login_lock_remaining( $ip, $username, $now = null ) {
	$now       = null === $now ? time() : $now;
	$remaining = 0;
	foreach ( tmc_login_buckets( $ip, $username ) as $bucket ) {
		$state     = tmc_login_bucket_state( $bucket['key'] );
		$remaining = max( $remaining, $state['until'] - $now );
	}
	return max( 0, (int) $remaining );
}

/**
 * Count one failed sign-in. Returns the lockout (seconds) now in force, 0 if none.
 */
function tmc_login_register_failure( $ip, $username, $now = null ) {
	$now    = null === $now ? time() : $now;
	$policy = tmc_login_policy();
	$locked = 0;
	foreach ( tmc_login_buckets( $ip, $username ) as $scope => $bucket ) {
		$state = tmc_login_bucket_state( $bucket['key'] );
		// Quiet for a whole window after the last failure AND after the last lockout ended: start again.
		// (Measuring from the end of the lockout keeps the backoff growing for persistent attackers.)
		if ( $now - max( $state['last'], $state['until'] ) > $policy['window'] ) {
			$state['n'] = 0;
		}
		++$state['n'];
		$state['last'] = $now;
		$lock          = tmc_login_backoff( $state['n'], $bucket['max'], $policy['base'], $policy['cap'] );
		if ( $lock ) {
			$state['until'] = $now + $lock;
			$locked         = max( $locked, $lock );
			tmc_audit(
				'login_lockout',
				array(
					'user_id'      => 0,
					'user_login'   => '',
					'object_type'  => 'ip' === $scope ? 'ip' : 'user',
					'object_title' => $bucket['label'],
					'details'      => array( 'scope' => $scope, 'failures' => $state['n'], 'locked_seconds' => $lock ),
				)
			);
		}
		// Kept until the count would be reset anyway (end of lockout + one quiet window).
		set_site_transient( $bucket['key'], $state, max( 0, $state['until'] - $now ) + $policy['window'] + MINUTE_IN_SECONDS );
	}
	return $locked;
}

/** Clear the username bucket after a successful sign-in. The IP bucket is kept on purpose. */
function tmc_login_reset_user( $username ) {
	$buckets = tmc_login_buckets( '', $username );
	if ( isset( $buckets['user'] ) ) {
		delete_site_transient( $buckets['user']['key'] );
	}
}

/** Clear both buckets (used by administrators via WP-CLI and by the test suite). */
function tmc_login_reset( $ip, $username ) {
	foreach ( tmc_login_buckets( $ip, $username ) as $bucket ) {
		delete_site_transient( $bucket['key'] );
	}
}

/* ------------------------------------------------------------------ enforcement */

// Before the password check: if locked, take the password handlers out for this attempt only.
add_filter( 'authenticate', 'tmc_login_precheck', 1, 3 );
function tmc_login_precheck( $user, $username, $password ) {
	$GLOBALS['tmc_login_lock'] = array();
	if ( '' === trim( (string) $username ) ) {
		return $user;
	}
	if ( tmc_login_lock_remaining( tmc_client_ip(), (string) $username ) > 0 ) {
		$removed = array();
		foreach ( array( 'wp_authenticate_username_password', 'wp_authenticate_email_password' ) as $handler ) {
			if ( remove_filter( 'authenticate', $handler, 20 ) ) {
				$removed[] = $handler;
			}
		}
		$GLOBALS['tmc_login_lock'] = array( 'removed' => $removed );
	}
	return $user;
}

// After every handler (core runs its spam check at 99): refuse while locked and restore the handlers.
add_filter( 'authenticate', 'tmc_login_enforce', 100, 3 );
function tmc_login_enforce( $user, $username, $password ) {
	if ( empty( $GLOBALS['tmc_login_lock'] ) ) {
		return $user;
	}
	foreach ( $GLOBALS['tmc_login_lock']['removed'] as $handler ) {
		add_filter( 'authenticate', $handler, 20, 3 );
	}
	$GLOBALS['tmc_login_lock'] = array();
	return new WP_Error( 'tmc_login_locked', tmc_login_generic_error() );
}

add_action( 'wp_login_failed', 'tmc_login_on_failure', 5, 2 );
function tmc_login_on_failure( $username, $error = null ) {
	if ( $error instanceof WP_Error && 'tmc_login_locked' === $error->get_error_code() ) {
		return; // attempts during a lockout do not extend it (the audit log still records them)
	}
	tmc_login_register_failure( tmc_client_ip(), (string) $username );
}

add_action( 'wp_login', 'tmc_login_on_success', 5, 2 );
function tmc_login_on_success( $login, $user ) {
	tmc_login_reset_user( $login );
}

/* ------------------------------------------------------------------ generic messages */

const TMC_LOGIN_AUTH_ERROR_CODES = array( 'invalid_username', 'invalid_email', 'incorrect_password', 'invalidcombo', 'tmc_login_locked', 'spammer_account', 'authentication_failed' );

/**
 * Replace every "wrong username / wrong password / locked" message with one generic message.
 * Unrelated notices (e.g. "you are now logged out", two-factor notices) are kept.
 */
add_filter( 'wp_login_errors', 'tmc_login_generic_errors', 20 );
function tmc_login_generic_errors( $errors ) {
	if ( ! $errors instanceof WP_Error || ! array_intersect( $errors->get_error_codes(), TMC_LOGIN_AUTH_ERROR_CODES ) ) {
		return $errors;
	}
	$clean = new WP_Error();
	foreach ( $errors->get_error_codes() as $code ) {
		if ( in_array( $code, TMC_LOGIN_AUTH_ERROR_CODES, true ) ) {
			continue;
		}
		foreach ( $errors->get_error_messages( $code ) as $message ) {
			$clean->add( $code, $message, $errors->get_error_data( $code ) );
		}
	}
	$clean->add( 'tmc_login_failed', tmc_login_generic_error() );
	return $clean;
}

add_filter( 'shake_error_codes', fn( $codes ) => array_merge( (array) $codes, array( 'tmc_login_failed', 'tmc_login_locked' ) ) );

/**
 * Lost password: for an unknown username or email, behave exactly as for a known one (redirect to
 * "check your email") instead of showing "There is no account with that username or email".
 */
add_action( 'lostpassword_post', 'tmc_lostpassword_no_enumeration', 10, 2 );
function tmc_lostpassword_no_enumeration( $errors, $user_data ) {
	if ( $user_data instanceof WP_User || 'wp-login.php' !== ( $GLOBALS['pagenow'] ?? '' ) || ! $errors instanceof WP_Error ) {
		return;
	}
	if ( array_diff( $errors->get_error_codes(), array( 'invalid_email' ) ) ) {
		return; // e.g. the field was empty: let WordPress say so
	}
	$login = isset( $_POST['user_login'] ) && is_string( $_POST['user_login'] ) ? trim( wp_unslash( $_POST['user_login'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- core form; value only compared
	if ( '' === $login ) {
		return;
	}
	tmc_security_audit_throttled( tmc_client_ip(), 10 * MINUTE_IN_SECONDS, 'password_reset_unknown_account', array( 'object_type' => 'user', 'details' => array( 'note' => 'reset requested for an account that does not exist' ) ) );
	// Same destination as core after a successful request (wp-login.php, case 'lostpassword').
	$redirect = ! empty( $_REQUEST['redirect_to'] ) && is_string( $_REQUEST['redirect_to'] ) ? wp_unslash( $_REQUEST['redirect_to'] ) : 'wp-login.php?checkemail=confirm'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- wp_safe_redirect validates
	wp_safe_redirect( $redirect );
	exit;
}

/* ------------------------------------------------------------------ no user enumeration */

/** Anyone who may list users anyway (Site Administrators, Super Admins) keeps author archives. */
function tmc_can_see_user_names() {
	return is_user_logged_in() && current_user_can( 'list_users' );
}

// /?author=1 would redirect to /author/<login>/; author archives confirm that a login exists.
add_filter( 'request', 'tmc_block_author_queries', 1 );
function tmc_block_author_queries( $query_vars ) {
	if ( is_admin() || ! is_array( $query_vars ) || tmc_can_see_user_names() ) {
		return $query_vars;
	}
	if ( isset( $query_vars['author'] ) || isset( $query_vars['author_name'] ) ) {
		return array( 'error' => '404' );
	}
	return $query_vars;
}

// Author links would point at the (blocked) archives and contain the login-derived slug.
add_filter( 'author_link', fn( $link ) => tmc_can_see_user_names() ? $link : home_url( '/' ), 20 );

// REST: no user listing or lookup for anonymous callers (includes _embed of post authors).
add_filter( 'rest_pre_dispatch', 'tmc_rest_block_anonymous_users', 5, 3 );
function tmc_rest_block_anonymous_users( $result, $server, $request ) {
	if ( null !== $result || is_user_logged_in() || ! $request instanceof WP_REST_Request ) {
		return $result;
	}
	if ( preg_match( '#^/wp/v2/users(?:/|$)#i', (string) $request->get_route() ) ) {
		return new WP_Error( 'rest_user_cannot_view', 'Sorry, you are not allowed to list users.', array( 'status' => rest_authorization_required_code() ) );
	}
	return $result;
}

// oEmbed responses carry author_name and author_url (the author archive).
add_filter( 'oembed_response_data', 'tmc_oembed_strip_author', 20 );
function tmc_oembed_strip_author( $data ) {
	unset( $data['author_name'], $data['author_url'] );
	return $data;
}

// No /wp-sitemap-users-1.xml.
add_filter( 'wp_sitemaps_add_provider', fn( $provider, $name ) => 'users' === $name ? false : $provider, 10, 2 );

// Bylines and feeds (dc:creator) must not show a login name when the display name was never changed.
add_filter( 'the_author', 'tmc_mask_login_as_author', 20 );
function tmc_mask_login_as_author( $name ) {
	global $authordata;
	if ( is_admin() || ! $authordata instanceof WP_User ) {
		return $name;
	}
	if ( 0 === strcasecmp( (string) $name, $authordata->user_login ) || 0 === strcasecmp( (string) $name, $authordata->user_nicename ) ) {
		return get_bloginfo( 'name' );
	}
	return $name;
}
