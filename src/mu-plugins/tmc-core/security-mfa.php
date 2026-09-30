<?php
/**
 * Multi-factor authentication for privileged accounts (tender §4.8; RTM R-4.8-3, R-4.6-8).
 *
 * The WordPress.org "Two Factor" plugin (GPL-2.0-or-later, pinned in scripts/setup.sh) provides the
 * second factor: time-based one-time passwords (TOTP, RFC 6238) from an authenticator app, plus
 * single-use backup codes. This module sets the policy around it:
 *
 *   - Methods: TOTP and backup codes only. E-mailed codes are switched off (weaker: a mailbox
 *     compromise would defeat both factors). The plugin's per-site settings page is removed.
 *   - Who: Super Admins, Site Administrators and Reviewer / Publishers (on any site) MUST use TOTP.
 *   - How: until they enrol, every admin screen redirects to the profile page where they set it up
 *     (optionally after a grace period), and REST calls other than the enrolment ones are refused.
 *   - Accounts flagged with a weak password (security-session.php) are sent to the same page.
 *   - Every enrolment, removal, backup-code event and 2FA sign-in is written to the audit log.
 *
 *   TMC_ENFORCE_MFA       on (default) | off    off only for automated test environments that need it
 *   TMC_MFA_GRACE_HOURS   0 (default)           hours a new privileged account may work before enrolling
 */

defined( 'ABSPATH' ) || exit;

/** Two Factor provider keys allowed on the network. */
const TMC_MFA_PROVIDERS = array( 'Two_Factor_Totp', 'Two_Factor_Backup_Codes' );

/** The provider that satisfies the policy. */
const TMC_MFA_REQUIRED_PROVIDER = 'Two_Factor_Totp';

/** User meta: first time the account was seen needing MFA (starts the grace period). */
const TMC_MFA_SINCE_META = 'tmc_mfa_required_since';

/** User meta: set when a privileged account signs in with a password that fails the policy. */
const TMC_PASSWORD_CHANGE_META = 'tmc_password_change_required';

function tmc_mfa_enforced() {
	return tmc_env_flag( 'TMC_ENFORCE_MFA', true );
}

function tmc_mfa_grace_seconds() {
	return tmc_env_int( 'TMC_MFA_GRACE_HOURS', 0, 0, 30 * 24 ) * HOUR_IN_SECONDS;
}

function tmc_mfa_plugin_active() {
	return class_exists( 'Two_Factor_Core' );
}

/** Whether the account must use two-factor authentication. */
function tmc_mfa_required_for( $user ) {
	return tmc_mfa_enforced() && tmc_user_is_privileged( $user );
}

/** Whether the account has a working TOTP authenticator configured. */
function tmc_mfa_user_enrolled( $user ) {
	$user = $user instanceof WP_User ? $user : get_userdata( (int) $user );
	if ( ! $user || ! tmc_mfa_plugin_active() ) {
		return false;
	}
	$providers = Two_Factor_Core::get_available_providers_for_user( $user );
	return ! is_wp_error( $providers ) && isset( $providers[ TMC_MFA_REQUIRED_PROVIDER ] );
}

/**
 * MFA state of an account: 'not-required', 'enrolled', 'grace' (may work, must enrol soon) or
 * 'blocked' (must enrol before doing anything else).
 *
 * @param WP_User|int $user User.
 * @param int|null    $now  Current time (tests).
 */
function tmc_mfa_status( $user, $now = null ) {
	$user = $user instanceof WP_User ? $user : get_userdata( (int) $user );
	if ( ! $user || ! tmc_mfa_required_for( $user ) ) {
		return 'not-required';
	}
	if ( tmc_mfa_user_enrolled( $user ) ) {
		return 'enrolled';
	}
	$now   = null === $now ? time() : (int) $now;
	$since = (int) get_user_meta( $user->ID, TMC_MFA_SINCE_META, true );
	if ( ! $since ) {
		$since = $now;
		update_user_meta( $user->ID, TMC_MFA_SINCE_META, $since );
	}
	return ( $now - $since ) < tmc_mfa_grace_seconds() ? 'grace' : 'blocked';
}

/** Seconds of grace left (0 if none). */
function tmc_mfa_grace_left( $user, $now = null ) {
	$now   = null === $now ? time() : (int) $now;
	$since = (int) get_user_meta( $user->ID, TMC_MFA_SINCE_META, true );
	return $since ? max( 0, $since + tmc_mfa_grace_seconds() - $now ) : 0;
}

/**
 * Why this account may not use the admin area yet: 'mfa', 'password' or '' (nothing outstanding).
 */
function tmc_account_gate( $user ) {
	$user = $user instanceof WP_User ? $user : get_userdata( (int) $user );
	if ( ! $user || ! $user->exists() ) {
		return '';
	}
	if ( 'blocked' === tmc_mfa_status( $user ) ) {
		return 'mfa';
	}
	if ( get_user_meta( $user->ID, TMC_PASSWORD_CHANGE_META, true ) && tmc_user_is_privileged( $user ) ) {
		return 'password';
	}
	return '';
}

/** Admin screens a gated account may still open (its own profile, where it fixes the problem). */
function tmc_account_gate_allows_screen( $pagenow ) {
	return in_array( $pagenow, array( 'profile.php' ), true );
}

/** REST routes a gated account may still call (the Two Factor enrolment endpoints). */
function tmc_account_gate_allows_route( $route ) {
	return 0 === stripos( ltrim( (string) $route, '/' ), 'two-factor/' );
}

function tmc_account_gate_url( $reason = 'mfa' ) {
	return add_query_arg( 'tmc-account', 'action-required', self_admin_url( 'profile.php' ) ) . ( 'password' === $reason ? '#password' : '#two-factor-options' );
}

/* ------------------------------------------------------------------ plugin policy */

// Site-enabled methods: fixed by policy, not by the per-site settings screen.
add_filter( 'pre_option_two_factor_enabled_providers', fn() => TMC_MFA_PROVIDERS );
add_filter(
	'two_factor_providers',
	function ( $providers ) {
		return array_intersect_key( (array) $providers, array_flip( TMC_MFA_PROVIDERS ) );
	},
	100
);
add_action(
	'admin_menu',
	function () {
		remove_submenu_page( 'options-general.php', 'two-factor-settings' );
	},
	100
);
// Authenticator apps show "Tata Memorial Centre: <login>" rather than the site's host name.
add_filter( 'two_factor_totp_issuer', fn( $issuer ) => get_network() ? get_network()->site_name : $issuer );

/* ------------------------------------------------------------------ enforcement */

add_action( 'admin_init', 'tmc_account_gate_admin', 1 );
function tmc_account_gate_admin() {
	global $pagenow;
	if ( tmc_is_cli() || wp_doing_ajax() || wp_doing_cron() || tmc_account_gate_allows_screen( $pagenow ) ) {
		return;
	}
	$user   = wp_get_current_user();
	$reason = tmc_account_gate( $user );
	if ( '' === $reason ) {
		return;
	}
	tmc_security_audit_throttled(
		'gate-' . $user->ID . '-' . $reason,
		HOUR_IN_SECONDS,
		'mfa' === $reason ? 'mfa_enrolment_required' : 'password_change_required',
		array( 'object_type' => 'user', 'object_id' => $user->ID, 'object_title' => $user->user_login, 'details' => array( 'screen' => (string) $pagenow ) )
	);
	wp_safe_redirect( tmc_account_gate_url( $reason ) );
	exit;
}

add_filter( 'rest_pre_dispatch', 'tmc_account_gate_rest', 6, 3 );
function tmc_account_gate_rest( $result, $server, $request ) {
	if ( null !== $result || tmc_is_cli() || ! $request instanceof WP_REST_Request || ! is_user_logged_in() ) {
		return $result;
	}
	if ( tmc_account_gate_allows_route( $request->get_route() ) || '' === tmc_account_gate( wp_get_current_user() ) ) {
		return $result;
	}
	return new WP_Error( 'tmc_account_action_required', 'This account must set up two-factor authentication (or change its password) on its profile page before it can do anything else.', array( 'status' => 403 ) );
}

/* ------------------------------------------------------------------ notices */

foreach ( array( 'admin_notices', 'network_admin_notices', 'user_admin_notices' ) as $tmc_hook ) {
	add_action( $tmc_hook, 'tmc_account_gate_notice' );
}
unset( $tmc_hook );

function tmc_account_gate_notice() {
	$user = wp_get_current_user();
	if ( ! $user->exists() ) {
		return;
	}
	$status = tmc_mfa_status( $user );

	if ( in_array( $status, array( 'grace', 'blocked' ), true ) && ! tmc_mfa_plugin_active() ) {
		echo '<div class="notice notice-error"><p><strong>Two-factor authentication is required for your account but the Two Factor plugin is not active.</strong> Contact the TMC IT team (network administrator).</p></div>';
		return;
	}
	if ( 'blocked' === $status ) {
		echo '<div class="notice notice-error"><p><strong>Two-factor authentication is required for your account.</strong> Your role can publish or administer content, so TMC policy requires a second sign-in step. Under <a href="#two-factor-options">Two-Factor Options</a> below, set up an authenticator app (time-based one-time password), then generate and safely store your backup codes. The rest of the administration area is available once this is done.</p></div>';
	} elseif ( 'grace' === $status ) {
		printf(
			'<div class="notice notice-warning"><p><strong>Set up two-factor authentication.</strong> Your account must use an authenticator app within %s. <a href="%s">Set it up now</a>.</p></div>',
			esc_html( human_time_diff( time(), time() + tmc_mfa_grace_left( $user ) ) ),
			esc_url( tmc_account_gate_url() )
		);
	} elseif ( 'enrolled' === $status && 'profile.php' === ( $GLOBALS['pagenow'] ?? '' ) && class_exists( 'Two_Factor_Backup_Codes' ) && 0 === Two_Factor_Backup_Codes::codes_remaining_for_user( $user ) ) {
		echo '<div class="notice notice-info"><p>You have no backup codes. Generate them under Two-Factor Options and keep them somewhere safe: they let you sign in if you lose your phone.</p></div>';
	}

	if ( 'password' === tmc_account_gate( $user ) ) {
		printf(
			'<div class="notice notice-error"><p><strong>Your password does not meet the TMC password policy.</strong> Set a new password below: at least %d characters, mixing upper and lower case letters, numbers and symbols, and not containing your username. The rest of the administration area is available once this is done.</p></div>',
			(int) tmc_password_min_length()
		);
	}
}

/* ------------------------------------------------------------------ audit */

/**
 * Two Factor stores everything in user meta, so watching those keys records every change however it
 * was made (profile screen, REST, WP-CLI, another administrator). Secrets are never logged.
 */
function tmc_mfa_meta_event( $meta_key, $change ) {
	$login = 'wp-login.php' === ( $GLOBALS['pagenow'] ?? '' );
	$map   = array(
		'_two_factor_totp_key'          => array( 'set' => 'mfa_totp_enrolled', 'delete' => 'mfa_totp_removed' ),
		'_two_factor_backup_codes'      => array( 'set' => $login ? 'mfa_backup_code_used' : 'mfa_backup_codes_generated', 'delete' => 'mfa_backup_codes_removed' ),
		'_two_factor_enabled_providers' => array( 'set' => 'mfa_methods_changed', 'delete' => 'mfa_disabled' ),
	);
	return $map[ $meta_key ][ $change ] ?? '';
}

function tmc_mfa_audit_meta( $meta_id, $user_id, $meta_key, $value, $change ) {
	$action = tmc_mfa_meta_event( $meta_key, $change );
	if ( '' === $action ) {
		return;
	}
	$details = array();
	if ( '_two_factor_backup_codes' === $meta_key && 'set' === $change ) {
		$details['codes_remaining'] = is_array( $value ) ? count( $value ) : 0;
	} elseif ( '_two_factor_enabled_providers' === $meta_key && 'set' === $change ) {
		$details['methods'] = array_values( array_filter( (array) $value, 'is_string' ) );
	}
	$user = get_userdata( $user_id );
	tmc_audit( $action, array( 'object_type' => 'user', 'object_id' => $user_id, 'object_title' => $user ? $user->user_login : '', 'details' => $details ) );
	if ( 'mfa_totp_enrolled' === $action ) {
		delete_user_meta( $user_id, TMC_MFA_SINCE_META );
	}
}
add_action( 'added_user_meta', fn( $id, $user_id, $key, $value ) => tmc_mfa_audit_meta( $id, $user_id, $key, $value, 'set' ), 10, 4 );
add_action( 'updated_user_meta', fn( $id, $user_id, $key, $value ) => tmc_mfa_audit_meta( $id, $user_id, $key, $value, 'set' ), 10, 4 );
add_action( 'deleted_user_meta', fn( $ids, $user_id, $key, $value ) => tmc_mfa_audit_meta( 0, $user_id, $key, $value, 'delete' ), 10, 4 );

add_action(
	'two_factor_user_authenticated',
	function ( $user, $provider ) {
		$key = is_object( $provider ) && method_exists( $provider, 'get_key' ) ? $provider->get_key() : ( is_object( $provider ) ? get_class( $provider ) : (string) $provider );
		tmc_audit( 'mfa_verified', array( 'user_id' => $user->ID, 'user_login' => $user->user_login, 'object_type' => 'user', 'object_id' => $user->ID, 'object_title' => $user->user_login, 'details' => array( 'method' => $key ) ) );
	},
	10,
	2
);

add_action(
	'two_factor_login_nonce_failed',
	function ( $user_id, $reason = '' ) {
		$user = get_userdata( (int) $user_id );
		tmc_audit( 'mfa_login_nonce_failed', array( 'user_id' => 0, 'user_login' => '', 'object_type' => 'user', 'object_id' => (int) $user_id, 'object_title' => $user ? $user->user_login : '', 'details' => array( 'reason' => is_scalar( $reason ) ? (string) $reason : '' ) ) );
	},
	10,
	2
);
