<?php
/**
 * Security framework: shared helpers (tender §4.8; RTM R-4.8-3, R-4.8-6, R-4.6-8).
 *
 *   security.php           helpers: environment settings, client IP, CIDR matching, privileged users
 *   security-network.php   admin / login network allow-list (TMC_ADMIN_ALLOW_CIDRS)
 *   security-login.php     login throttling and lockout, generic errors, no user enumeration
 *   security-mfa.php       two-factor authentication (TOTP) policy and enforcement for privileged roles
 *   security-session.php   idle timeout, session length, password policy, application passwords, file mods
 *   security-headers.php   Content-Security-Policy (nonce), HSTS, security.txt
 *
 * Every setting comes from the environment (docker-compose passes it to the containers), never from
 * the database, so someone with database or admin access alone cannot weaken the controls.
 * The controls and their evidence are listed in docs/security/owasp-top10.md.
 */

defined( 'ABSPATH' ) || exit;

/** Roles that must use two-factor authentication and a strong password (see roles.php for labels). */
const TMC_PRIVILEGED_ROLES = array( 'administrator', 'editor' );

/** String setting from the environment; $fallback when unset or empty. */
function tmc_security_env( $name, $fallback = '' ) {
	$value = getenv( $name );
	return ( false === $value || '' === trim( $value ) ) ? $fallback : trim( $value );
}

/** On/off setting from the environment: "0", "off", "false", "no" mean off; anything else means on. */
function tmc_security_flag( $name, $fallback ) {
	$value = getenv( $name );
	if ( false === $value || '' === trim( $value ) ) {
		return (bool) $fallback;
	}
	return ! in_array( strtolower( trim( $value ) ), array( '0', 'off', 'false', 'no' ), true );
}

/** Whole-number setting from the environment, clamped to [$min, $max]. */
function tmc_security_int( $name, $fallback, $min = 0, $max = PHP_INT_MAX ) {
	$value = getenv( $name );
	if ( false === $value || ! preg_match( '/^\s*-?\d+\s*$/', $value ) ) {
		return (int) $fallback;
	}
	return max( (int) $min, min( (int) $max, (int) $value ) );
}

function tmc_security_is_cli() {
	return defined( 'WP_CLI' ) && WP_CLI;
}

/**
 * Canonical form of an IP address ('' if invalid). IPv4-mapped IPv6 (::ffff:10.1.2.3) becomes IPv4.
 */
function tmc_ip_normalise( $ip ) {
	$ip = trim( (string) $ip );
	if ( 0 === stripos( $ip, '::ffff:' ) && false !== filter_var( substr( $ip, 7 ), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
		$ip = substr( $ip, 7 );
	}
	return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
}

/**
 * The client's IP address. Apache's mod_remoteip has already replaced REMOTE_ADDR with the real
 * client address from X-Forwarded-For when the request came through a trusted reverse proxy
 * (wordpress/apache-tmc.conf), so no forwarding header is read here.
 */
function tmc_security_client_ip() {
	return tmc_ip_normalise( isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated as an IP address
}

/**
 * Whether $ip lies in $cidr ("10.0.0.0/8", "2001:db8::/32" or a single address). IPv4 and IPv6;
 * an address never matches a range of the other family. Invalid input never matches.
 */
function tmc_ip_in_cidr( $ip, $cidr ) {
	$ip   = tmc_ip_normalise( $ip );
	$cidr = trim( (string) $cidr );
	if ( '' === $ip || '' === $cidr ) {
		return false;
	}
	$bits = null;
	$net  = $cidr;
	if ( false !== strpos( $cidr, '/' ) ) {
		list( $net, $bits ) = explode( '/', $cidr, 2 );
		if ( ! preg_match( '/^\d{1,3}$/', $bits ) ) {
			return false;
		}
		$bits = (int) $bits;
	}
	$net = tmc_ip_normalise( $net );
	if ( '' === $net ) {
		return false;
	}
	$ip_bin  = inet_pton( $ip );
	$net_bin = inet_pton( $net );
	if ( false === $ip_bin || false === $net_bin || strlen( $ip_bin ) !== strlen( $net_bin ) ) {
		return false;
	}
	$max  = strlen( $ip_bin ) * 8;
	$bits = null === $bits ? $max : $bits;
	if ( $bits > $max ) {
		return false;
	}
	$bytes = intdiv( $bits, 8 );
	$rest  = $bits % 8;
	if ( $bytes && substr( $ip_bin, 0, $bytes ) !== substr( $net_bin, 0, $bytes ) ) {
		return false;
	}
	if ( $rest ) {
		$mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;
		if ( ( ord( $ip_bin[ $bytes ] ) & $mask ) !== ( ord( $net_bin[ $bytes ] ) & $mask ) ) {
			return false;
		}
	}
	return true;
}

/** A syntactically valid CIDR range or single address. */
function tmc_cidr_is_valid( $cidr ) {
	$cidr = trim( (string) $cidr );
	$net  = false !== strpos( $cidr, '/' ) ? strstr( $cidr, '/', true ) : $cidr;
	return tmc_ip_in_cidr( $net, $cidr );
}

function tmc_ip_in_cidrs( $ip, array $cidrs ) {
	foreach ( $cidrs as $cidr ) {
		if ( tmc_ip_in_cidr( $ip, $cidr ) ) {
			return true;
		}
	}
	return false;
}

function tmc_privileged_roles() {
	return (array) apply_filters( 'tmc_privileged_roles', TMC_PRIVILEGED_ROLES );
}

/**
 * Super Admin, or Site Administrator / Reviewer-Publisher on ANY site of the network (a user who is
 * privileged on one site must meet the privileged-account rules wherever they sign in).
 *
 * @param WP_User|int $user User object or ID.
 */
function tmc_user_is_privileged( $user ) {
	$user = $user instanceof WP_User ? $user : get_userdata( (int) $user );
	if ( ! $user || ! $user->exists() ) {
		return false;
	}
	if ( is_multisite() && is_super_admin( $user->ID ) ) {
		return true;
	}
	$roles = tmc_privileged_roles();
	if ( ! is_multisite() ) {
		return (bool) array_intersect( $roles, (array) $user->roles );
	}
	global $wpdb;
	foreach ( array_keys( get_blogs_of_user( $user->ID, true ) ) as $blog_id ) {
		$caps = get_user_meta( $user->ID, $wpdb->get_blog_prefix( $blog_id ) . 'capabilities', true );
		if ( is_array( $caps ) && array_intersect( $roles, array_keys( array_filter( $caps ) ) ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Write an audit entry at most once per $ttl seconds for the same action + key, so a flood of
 * identical security events (blocked requests, failed API logins) cannot flood the audit log.
 *
 * @return bool Whether an entry was written.
 */
function tmc_security_audit_throttled( $key, $ttl, $action, array $args = array() ) {
	$transient = 'tmc_sa_' . substr( hash( 'sha256', $action . '|' . $key ), 0, 40 );
	if ( get_site_transient( $transient ) ) {
		return false;
	}
	set_site_transient( $transient, 1, $ttl );
	tmc_audit( $action, $args );
	return true;
}

/** Path of the current request (for audit details), without the query string. */
function tmc_security_request_path() {
	$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised below
	$path = (string) wp_parse_url( (string) $uri, PHP_URL_PATH );
	return mb_substr( sanitize_text_field( $path ), 0, 200 );
}
