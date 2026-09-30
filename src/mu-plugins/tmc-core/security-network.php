<?php
/**
 * Restricted network access to the administration area (tender §4.8; RTM R-4.8-3, R-4.6-8).
 *
 * wp-login.php and everything under /wp-admin/ answer only to client addresses in the allow-list:
 *
 *   TMC_ADMIN_ALLOW_CIDRS="10.20.0.0/16,203.0.113.10"     comma-separated IPv4/IPv6 ranges or addresses
 *
 * Unset/empty = private networks only: RFC 1918, 100.64.0.0/10 (carrier-grade NAT, used by the
 * Tailscale VPN that reaches UAT) and loopback. If the variable is set but contains no valid entry,
 * nobody is allowed (fail closed); administrators then recover with WP-CLI on the server.
 *
 * Public endpoints stay reachable from anywhere for visitors who are not signed in:
 * admin-ajax.php and admin-post.php (front-end forms), and the wp-login.php actions that serve the
 * public ("postpass" for password-protected pages, "confirmaction" for privacy-request emails).
 *
 * The client address is REMOTE_ADDR as rewritten by Apache mod_remoteip from the reverse proxy's
 * X-Forwarded-For (wordpress/apache-tmc.conf). Blocked requests get HTTP 403 and an audit entry
 * (at most one per address and area every 10 minutes).
 */

defined( 'ABSPATH' ) || exit;

const TMC_ADMIN_DEFAULT_CIDRS = array( '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '100.64.0.0/10', '127.0.0.0/8', '::1/128' );

/** wp-login.php actions that ordinary visitors use. */
const TMC_ADMIN_PUBLIC_LOGIN_ACTIONS = array( 'postpass', 'confirmaction' );

/** The effective allow-list. */
function tmc_admin_allowed_cidrs() {
	$raw = tmc_env( 'TMC_ADMIN_ALLOW_CIDRS', '' );
	if ( '' === $raw ) {
		return TMC_ADMIN_DEFAULT_CIDRS;
	}
	$valid = array();
	foreach ( explode( ',', $raw ) as $entry ) {
		$entry = trim( $entry );
		if ( '' === $entry ) {
			continue;
		}
		if ( tmc_cidr_is_valid( $entry ) ) {
			$valid[] = $entry;
		} else {
			error_log( 'TMC-SECURITY ignoring invalid TMC_ADMIN_ALLOW_CIDRS entry: ' . substr( $entry, 0, 64 ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
	}
	return $valid;
}

/**
 * Which protected area this request is for: 'login', 'ajax', 'admin-post', 'admin' or '' (public).
 */
function tmc_admin_request_area() {
	global $pagenow;
	if ( 'wp-login.php' === $pagenow ) {
		return 'login';
	}
	if ( ! is_admin() ) {
		return '';
	}
	if ( wp_doing_ajax() ) {
		return 'ajax';
	}
	if ( 'admin-post.php' === $pagenow ) {
		return 'admin-post';
	}
	return 'admin';
}

/**
 * The access decision, kept free of globals so the test suite can check every case.
 *
 * @param string $area         Result of tmc_admin_request_area().
 * @param string $ip           Client IP.
 * @param bool   $logged_in    Whether the request carries a valid login.
 * @param string $login_action wp-login.php ?action= value.
 */
function tmc_admin_access_allowed( $area, $ip, $logged_in, $login_action = '' ) {
	if ( '' === $area ) {
		return true;
	}
	if ( ! $logged_in && in_array( $area, array( 'ajax', 'admin-post' ), true ) ) {
		return true; // public front-end endpoints
	}
	if ( 'login' === $area && in_array( $login_action, TMC_ADMIN_PUBLIC_LOGIN_ACTIONS, true ) ) {
		return true;
	}
	return tmc_ip_in_cidrs( $ip, tmc_admin_allowed_cidrs() );
}

// Early on every request, before any admin code runs (and before auth_redirect()).
add_action( 'init', 'tmc_admin_network_guard', -1000 );
function tmc_admin_network_guard() {
	if ( tmc_is_cli() || wp_doing_cron() ) {
		return;
	}
	$area = tmc_admin_request_area();
	if ( '' === $area ) {
		return;
	}
	$ip     = tmc_client_ip();
	$action = isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing only
	if ( tmc_admin_access_allowed( $area, $ip, is_user_logged_in(), 'login' === $area ? $action : '' ) ) {
		return;
	}

	tmc_security_audit_throttled(
		$ip . '|' . $area,
		10 * MINUTE_IN_SECONDS,
		'admin_access_blocked',
		array(
			'object_type'  => 'request',
			'object_title' => $area,
			'details'      => array(
				'path'   => tmc_request_path(),
				'reason' => 'client address not in TMC_ADMIN_ALLOW_CIDRS',
			),
		)
	);

	nocache_headers();
	wp_die(
		'<h1>Access restricted</h1><p>The administration area of this website can only be used from the Tata Memorial Centre network.</p>',
		'Access restricted',
		array( 'response' => 403, 'back_link' => false )
	);
}
