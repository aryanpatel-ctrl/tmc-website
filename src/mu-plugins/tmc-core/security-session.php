<?php
/**
 * Sessions, passwords, API credentials and code changes (tender §4.8; RTM R-4.8-3, R-4.8-6, R-4.6-8).
 *
 *   Idle timeout        signed-in sessions end after TMC_ADMIN_IDLE_MINUTES (default 30) without
 *                       activity. The admin "heartbeat" does not count as activity, so an open but
 *                       unattended editor tab still times out (WordPress then shows its login dialog).
 *   Session length      privileged accounts: at most TMC_ADMIN_SESSION_HOURS (default 12) per sign-in,
 *                       even with "Remember me".
 *   Password policy     privileged accounts (and anyone being given a privileged role): at least
 *                       TMC_PASSWORD_MIN_LENGTH (default 12) characters, three of four character
 *                       classes, no username / e-mail name, no common base word, no long repeats.
 *                       A privileged account that signs in with a password below the policy is sent to
 *                       its profile to change it (see security-mfa.php, tmc_account_gate()).
 *   Application passwords  off, except for roles listed in TMC_APP_PASSWORD_ROLES (comma-separated).
 *   File modifications  plugin/theme/core installs and updates from the admin screens are disabled on
 *                       the server (TMC_ENV=server) or when TMC_DISALLOW_FILE_MODS=1; releases arrive
 *                       only through the CI/CD pipeline. WP-CLI (used by that pipeline) is unaffected.
 *   Avatars             never fetched from Gravatar (would send e-mail hashes to a third party abroad).
 */

defined( 'ABSPATH' ) || exit;

/* ================================================================ idle timeout */

function tmc_session_idle_limit() {
	return tmc_env_int( 'TMC_ADMIN_IDLE_MINUTES', 30, 0, 24 * 60 ) * MINUTE_IN_SECONDS; // 0 = off
}

add_filter(
	'attach_session_information',
	function ( $info ) {
		$info['tmc_last_activity'] = time();
		return $info;
	}
);

/**
 * Check a session for inactivity and record activity.
 *
 * @param int    $user_id  User.
 * @param string $token    Session token.
 * @param int    $now      Current time.
 * @param bool   $activity Whether this request counts as user activity.
 * @return string 'disabled' | 'missing' | 'expired' (session destroyed) | 'active'
 */
function tmc_session_touch( $user_id, $token, $now, $activity = true ) {
	$limit = tmc_session_idle_limit();
	if ( ! $limit ) {
		return 'disabled';
	}
	$manager = WP_Session_Tokens::get_instance( $user_id );
	$session = $manager->get( $token );
	if ( ! $session ) {
		return 'missing';
	}
	$last = (int) ( $session['tmc_last_activity'] ?? ( $session['login'] ?? $now ) );
	if ( $now - $last > $limit ) {
		$manager->destroy( $token );
		return 'expired';
	}
	if ( $activity && $now - $last >= MINUTE_IN_SECONDS ) { // at most one write per minute
		$session['tmc_last_activity'] = $now;
		$manager->update( $token, $session );
	}
	return 'active';
}

add_action( 'init', 'tmc_session_idle_check', 1 );
function tmc_session_idle_check() {
	if ( tmc_is_cli() || wp_doing_cron() || ! is_user_logged_in() ) {
		return;
	}
	$token = wp_get_session_token();
	if ( ! $token ) {
		return;
	}
	$heartbeat = wp_doing_ajax() && isset( $_POST['action'] ) && 'heartbeat' === $_POST['action']; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only routing check
	$user      = wp_get_current_user();
	if ( 'expired' !== tmc_session_touch( $user->ID, $token, time(), ! $heartbeat ) ) {
		return;
	}
	tmc_audit( 'session_idle_timeout', array( 'object_type' => 'user', 'object_id' => $user->ID, 'object_title' => $user->user_login, 'details' => array( 'idle_minutes' => (int) ( tmc_session_idle_limit() / MINUTE_IN_SECONDS ) ) ) );
	wp_clear_auth_cookie();
	wp_set_current_user( 0 );
	if ( is_admin() && ! wp_doing_ajax() ) {
		$back = set_url_scheme( 'http://' . wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) . wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- only used as redirect_to, which wp-login.php validates
		wp_safe_redirect( add_query_arg( 'tmc-idle', '1', wp_login_url( $back ) ) );
		exit;
	}
}

add_filter(
	'login_message',
	function ( $message ) {
		if ( isset( $_GET['tmc-idle'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only
			$minutes  = (int) ( tmc_session_idle_limit() / MINUTE_IN_SECONDS );
			$message .= '<p class="message">' . esc_html( sprintf( 'You were signed out after %d minutes without activity. Please sign in again.', $minutes ) ) . '</p>';
		}
		return $message;
	}
);

/* ================================================================ session length */

add_filter( 'auth_cookie_expiration', 'tmc_session_max_length', 20, 3 );
function tmc_session_max_length( $length, $user_id, $remember ) {
	if ( ! tmc_user_is_privileged( (int) $user_id ) ) {
		return $length;
	}
	return min( (int) $length, tmc_env_int( 'TMC_ADMIN_SESSION_HOURS', 12, 1, 14 * 24 ) * HOUR_IN_SECONDS );
}

/* ================================================================ password policy */

function tmc_password_min_length() {
	return tmc_env_int( 'TMC_PASSWORD_MIN_LENGTH', 12, 8, 128 );
}

/** Common base words that must not form the password (checked after removing digits and symbols). */
const TMC_PASSWORD_BANNED_WORDS = array( 'password', 'passw', 'qwerty', 'asdf', 'welcome', 'admin', 'administrator', 'letmein', 'iloveyou', 'changeme', 'default', 'secret', 'login', 'tata', 'tatamemorial', 'memorial', 'tmc', 'tmh', 'hospital', 'india', 'mumbai', 'cancer' );

/**
 * Policy check. Returns the list of problems (empty = acceptable).
 */
function tmc_password_policy_errors( $password, $user_login = '', $email = '' ) {
	$password = (string) $password;
	$errors   = array();
	$min      = tmc_password_min_length();

	if ( mb_strlen( $password ) < $min ) {
		$errors[] = sprintf( 'The password must be at least %d characters long.', $min );
	}
	$classes = (int) preg_match( '/[a-z]/', $password ) + (int) preg_match( '/[A-Z]/', $password ) + (int) preg_match( '/\d/', $password ) + (int) preg_match( '/[^a-zA-Z\d]/', $password );
	if ( $classes < 3 ) {
		$errors[] = 'The password must mix at least three of: lower-case letters, upper-case letters, numbers and symbols.';
	}
	$lower = strtolower( $password );
	$local = strtolower( (string) strstr( (string) $email, '@', true ) );
	foreach ( array( strtolower( (string) $user_login ), $local ) as $personal ) {
		if ( strlen( $personal ) >= 3 && false !== strpos( $lower, $personal ) ) {
			$errors[] = 'The password must not contain your username or e-mail name.';
			break;
		}
	}
	$letters = preg_replace( '/[^a-z]/', '', $lower );
	if ( in_array( $letters, TMC_PASSWORD_BANNED_WORDS, true ) ) {
		$errors[] = 'The password is based on a common word. Choose something less predictable.';
	}
	if ( preg_match( '/(.)\1{3,}/u', $password ) ) {
		$errors[] = 'The password must not repeat the same character four or more times in a row.';
	}
	return $errors;
}

function tmc_role_is_privileged( $role ) {
	return in_array( (string) $role, tmc_privileged_roles(), true );
}

// Profile screen, "Add user" and "Edit user".
add_action( 'user_profile_update_errors', 'tmc_password_policy_on_profile', 10, 3 );
function tmc_password_policy_on_profile( $errors, $update, $user ) {
	if ( empty( $user->user_pass ) ) {
		return; // password not being changed
	}
	$privileged = ( $update && ! empty( $user->ID ) && tmc_user_is_privileged( (int) $user->ID ) ) || ( ! empty( $user->role ) && tmc_role_is_privileged( $user->role ) );
	if ( ! $privileged ) {
		return;
	}
	foreach ( tmc_password_policy_errors( wp_unslash( $user->user_pass ), $user->user_login ?? '', $user->user_email ?? '' ) as $message ) {
		$errors->add( 'tmc_password_policy', '<strong>Error:</strong> ' . esc_html( $message ), array( 'form-field' => 'pass1' ) );
	}
}

// "Reset password" link from e-mail.
add_action( 'validate_password_reset', 'tmc_password_policy_on_reset', 10, 2 );
function tmc_password_policy_on_reset( $errors, $user ) {
	if ( ! $user instanceof WP_User || empty( $_POST['pass1'] ) || ! is_string( $_POST['pass1'] ) || ! tmc_user_is_privileged( $user ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- core verifies the reset key
		return;
	}
	foreach ( tmc_password_policy_errors( wp_unslash( $_POST['pass1'] ), $user->user_login, $user->user_email ) as $message ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- password, checked not stored
		$errors->add( 'tmc_password_policy', esc_html( $message ) );
	}
}

// After a correct password: flag privileged accounts whose password is below the policy.
add_filter( 'check_password', 'tmc_password_policy_on_login', 20, 4 );
function tmc_password_policy_on_login( $check, $password, $hash, $user_id ) {
	if ( ! $check || ! $user_id ) {
		return $check;
	}
	$user = get_userdata( (int) $user_id );
	// Only the account password itself (wp_check_password() also verifies e.g. two-factor backup codes).
	if ( ! $user || ! hash_equals( (string) $user->user_pass, (string) $hash ) || ! tmc_user_is_privileged( $user ) ) {
		return $check;
	}
	if ( tmc_password_policy_errors( $password, $user->user_login, $user->user_email ) ) {
		update_user_meta( $user->ID, TMC_PASSWORD_CHANGE_META, 1 );
	} else {
		delete_user_meta( $user->ID, TMC_PASSWORD_CHANGE_META );
	}
	return $check;
}

// A successful change (already validated above) clears the flag.
add_action(
	'profile_update',
	function ( $user_id, $old ) {
		$new = get_userdata( $user_id );
		if ( $new && $old instanceof WP_User && $new->user_pass !== $old->user_pass ) {
			delete_user_meta( $user_id, TMC_PASSWORD_CHANGE_META );
		}
	},
	10,
	2
);
add_action( 'after_password_reset', fn( $user ) => delete_user_meta( $user->ID, TMC_PASSWORD_CHANGE_META ) );

/* ================================================================ application passwords */

function tmc_app_password_roles() {
	return array_values( array_filter( array_map( 'sanitize_key', explode( ',', tmc_env( 'TMC_APP_PASSWORD_ROLES', '' ) ) ) ) );
}

add_filter( 'wp_is_application_passwords_available', fn( $available ) => $available && (bool) tmc_app_password_roles() );

add_filter( 'wp_is_application_passwords_available_for_user', 'tmc_app_passwords_for_user', 10, 2 );
function tmc_app_passwords_for_user( $available, $user ) {
	if ( ! $available || ! $user instanceof WP_User ) {
		return false;
	}
	return (bool) array_intersect( tmc_app_password_roles(), (array) $user->roles );
}

add_action(
	'wp_create_application_password',
	function ( $user_id, $item ) {
		tmc_audit( 'app_password_created', tmc_audit_user_args( $user_id, array( 'name' => $item['name'] ?? '', 'uuid' => $item['uuid'] ?? '' ) ) );
	},
	10,
	2
);
add_action(
	'wp_delete_application_password',
	function ( $user_id, $item ) {
		tmc_audit( 'app_password_deleted', tmc_audit_user_args( $user_id, array( 'name' => $item['name'] ?? '', 'uuid' => $item['uuid'] ?? '' ) ) );
	},
	10,
	2
);
add_action(
	'application_password_failed_authentication',
	function ( $error ) {
		$code = $error instanceof WP_Error ? $error->get_error_code() : '';
		tmc_security_audit_throttled( tmc_client_ip() . '|' . $code, 10 * MINUTE_IN_SECONDS, 'app_password_auth_failed', array( 'object_type' => 'request', 'details' => array( 'reason' => $code ) ) );
	}
);

/* ================================================================ file modifications */

/**
 * Whether admin-screen installs/updates/edits of code are blocked.
 *
 * @param string $env    TMC_ENV value.
 * @param bool   $is_cli WP-CLI (the deployment pipeline) is never blocked.
 */
function tmc_file_mods_blocked( $env, $is_cli ) {
	if ( $is_cli ) {
		return false;
	}
	$flag = getenv( 'TMC_DISALLOW_FILE_MODS' );
	if ( false !== $flag && '' !== trim( $flag ) ) {
		return tmc_env_flag( 'TMC_DISALLOW_FILE_MODS', false );
	}
	return 'server' === $env;
}

add_filter( 'file_mod_allowed', fn( $allowed ) => tmc_file_mods_blocked( tmc_env( 'TMC_ENV', '' ), tmc_is_cli() ) ? false : $allowed, 20 );

/* ================================================================ avatars */

add_filter(
	'pre_get_avatar_data',
	function ( $args ) {
		$args['url']          = includes_url( 'images/blank.gif' );
		$args['found_avatar'] = false;
		return $args;
	},
	20
);
