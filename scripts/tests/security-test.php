<?php
/**
 * Security controls (W2; RTM R-4.8-3, R-4.8-6, R-4.6-8): admin network allow-list, user-enumeration
 * defences, login lockout, two-factor enforcement for privileged roles, application-password policy,
 * password policy, session timeout, CSP / HSTS headers, security.txt, file-modification lock.
 *
 * Creates its own users and content, switches settings with putenv() only inside this process,
 * and removes everything afterwards (the audit log keeps the history, by design).
 *
 *   docker compose run --rm -T wpcli --url=tmh.<base> eval-file - < scripts/tests/security-test.php
 */

require_once ABSPATH . 'wp-admin/includes/user.php';
require_once ABSPATH . 'wp-admin/includes/ms.php';

global $wpdb;
$pass = 0;
$fail = 0;
$t    = function ( $label, $ok ) use ( &$pass, &$fail ) {
	WP_CLI::log( ( $ok ? '  PASS  ' : '  FAIL  ' ) . $label );
	$ok ? $pass++ : $fail++;
};

// Settings are read from the environment on every call; change them for this process only.
$env_saved = array();
$set_env   = function ( $name, $value ) use ( &$env_saved ) {
	if ( ! array_key_exists( $name, $env_saved ) ) {
		$env_saved[ $name ] = getenv( $name );
	}
	null === $value ? putenv( $name ) : putenv( "$name=$value" );
};
$restore_env = function () use ( &$env_saved ) {
	foreach ( $env_saved as $name => $value ) {
		false === $value ? putenv( $name ) : putenv( "$name=$value" );
	}
	$env_saved = array();
};
$audit_since = function ( $first_id, $action, $where = '', array $args = array() ) use ( $wpdb ) {
	$sql = 'SELECT COUNT(*) FROM ' . tmc_audit_table() . ' WHERE id > %d AND action = %s' . $where;
	return (int) $wpdb->get_var( $wpdb->prepare( $sql, array_merge( array( $first_id, $action ), $args ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
};

if ( ! function_exists( 'tmc_ip_in_cidr' ) ) {
	WP_CLI::error( 'tmc-core security modules are not loaded' );
}

$first_audit_id = (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id),0) FROM ' . tmc_audit_table() );
$saved_ip       = $_SERVER['REMOTE_ADDR'] ?? null;
$suffix         = strtolower( wp_generate_password( 6, false ) );

/* ------------------------------------------------------------------ fixtures */

$strong_password = function ( $login ) {
	do {
		$candidate = 'Sec-' . wp_generate_password( 16, false ) . '-7q!';
	} while ( tmc_password_policy_errors( $candidate, $login, $login . '@example.com' ) );
	return $candidate;
};
$passwords = array();
$make_user = function ( $slug, $role ) use ( $suffix, $strong_password, &$passwords ) {
	$login = "sectest_{$slug}_{$suffix}";
	$pw    = $strong_password( $login );
	$id    = wp_insert_user(
		array(
			'user_login'   => $login,
			'user_email'   => "$login@example.com",
			'user_pass'    => $pw,
			'role'         => $role,
			'display_name' => $login, // deliberately equal to the login (the WordPress default)
		)
	);
	if ( is_wp_error( $id ) ) {
		WP_CLI::error( "fixture $login: " . $id->get_error_message() );
	}
	$passwords[ $id ] = $pw;
	return get_userdata( $id );
};

$contrib = $make_user( 'contrib', 'contributor' );
$editor  = $make_user( 'editor', 'editor' );
$admin   = $make_user( 'admin', 'administrator' );
$super   = $make_user( 'super', 'subscriber' );
grant_super_admin( $super->ID );
$fixtures = array( $contrib, $editor, $admin, $super );

/* ------------------------------------------------------------------ 1. admin network allow-list */

WP_CLI::log( '— Admin network allow-list (TMC_ADMIN_ALLOW_CIDRS)' );
$set_env( 'TMC_ADMIN_ALLOW_CIDRS', null );
$t( 'default allow-list = RFC 1918 + 100.64.0.0/10 + loopback', TMC_ADMIN_DEFAULT_CIDRS === tmc_admin_allowed_cidrs() );
$default_cases = array(
	'10.1.2.3'        => true,
	'172.16.0.1'      => true,
	'172.31.255.255'  => true,
	'192.168.10.20'   => true,
	'100.64.0.1'      => true,
	'100.127.255.254' => true,
	'127.0.0.1'       => true,
	'::1'             => true,
	'::ffff:10.0.0.5' => true,
	'172.32.0.1'      => false,
	'100.128.0.1'     => false,
	'8.8.8.8'         => false,
	'203.0.113.9'     => false,
	'2001:db8::1'     => false,
	'not-an-ip'       => false,
	''                => false,
);
foreach ( $default_cases as $ip => $want ) {
	$t( sprintf( 'default: %-16s %s', '' === $ip ? '(empty)' : $ip, $want ? 'allowed' : 'blocked' ), $want === tmc_admin_access_allowed( 'admin', (string) $ip, true ) );
}
$t( 'CIDR syntax check', tmc_cidr_is_valid( '::/0' ) && tmc_cidr_is_valid( '203.0.113.7' ) && ! tmc_cidr_is_valid( '10.0.0.0/33' ) && ! tmc_cidr_is_valid( '2001:db8::/129' ) && ! tmc_cidr_is_valid( 'bogus/8' ) );

$set_env( 'TMC_ADMIN_ALLOW_CIDRS', '203.0.113.0/24, 2001:db8:abcd::/48 ,bogus/99' );
$t( 'custom list parsed, invalid entry ignored', array( '203.0.113.0/24', '2001:db8:abcd::/48' ) === tmc_admin_allowed_cidrs() );
$t( 'custom: 203.0.113.77 may open wp-admin', tmc_admin_access_allowed( 'admin', '203.0.113.77', true ) );
$t( 'custom: 2001:db8:abcd:12::5 may open wp-login.php', tmc_admin_access_allowed( 'login', '2001:db8:abcd:12::5', false ) );
$t( 'custom: private 10.0.0.1 no longer allowed (list replaces the default)', ! tmc_admin_access_allowed( 'admin', '10.0.0.1', true ) );
$t( 'blocked: wp-login.php from 198.51.100.1', ! tmc_admin_access_allowed( 'login', '198.51.100.1', false ) );
$t( 'blocked: wp-login.php?action=lostpassword from outside', ! tmc_admin_access_allowed( 'login', '198.51.100.1', false, 'lostpassword' ) );
$t( 'public: wp-login.php?action=postpass (password-protected pages)', tmc_admin_access_allowed( 'login', '198.51.100.1', false, 'postpass' ) );
$t( 'public: admin-ajax.php for anonymous visitors from anywhere', tmc_admin_access_allowed( 'ajax', '198.51.100.1', false ) );
$t( 'public: admin-post.php for anonymous visitors from anywhere', tmc_admin_access_allowed( 'admin-post', '198.51.100.1', false ) );
$t( 'blocked: admin-ajax.php with a login from outside', ! tmc_admin_access_allowed( 'ajax', '198.51.100.1', true ) );
$t( 'public pages are never restricted', tmc_admin_access_allowed( '', '198.51.100.1', false ) );
$set_env( 'TMC_ADMIN_ALLOW_CIDRS', 'garbage' );
$t( 'only invalid entries: nobody allowed (fail closed)', array() === tmc_admin_allowed_cidrs() && ! tmc_admin_access_allowed( 'admin', '127.0.0.1', true ) );
$restore_env();

/* ------------------------------------------------------------------ 2. user enumeration */

WP_CLI::log( '— User enumeration' );
wp_set_current_user( 0 );
$res = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/users' ) );
$t( 'REST /wp/v2/users refused for anonymous callers (HTTP ' . $res->get_status() . ')', in_array( $res->get_status(), array( 401, 403 ), true ) );
$res = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/users/' . $admin->ID ) );
$t( 'REST /wp/v2/users/<id> refused for anonymous callers (HTTP ' . $res->get_status() . ')', in_array( $res->get_status(), array( 401, 403 ), true ) );
$res = rest_do_request( new WP_REST_Request( 'GET', '/WP/V2/USERS' ) );
$t( 'upper-case route variant also refused (HTTP ' . $res->get_status() . ')', in_array( $res->get_status(), array( 401, 403 ), true ) );
wp_set_current_user( $admin->ID );
$res = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/users' ) );
$t( 'still available to a signed-in Site Administrator (HTTP ' . $res->get_status() . ')', 200 === $res->get_status() );
wp_set_current_user( 0 );

$t( '?author=N becomes a 404 for the public', array( 'error' => '404' ) === tmc_block_author_queries( array( 'author' => (string) $admin->ID ) ) );
$t( '/author/<name>/ becomes a 404 for the public', array( 'error' => '404' ) === tmc_block_author_queries( array( 'author_name' => $admin->user_nicename ) ) );
$t( 'other queries are untouched', array( 'pagename' => 'about' ) === tmc_block_author_queries( array( 'pagename' => 'about' ) ) );
$t( 'author links do not expose the login-based slug', false === strpos( get_author_posts_url( $admin->ID ), $admin->user_nicename ) );

$page_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Security test ' . $suffix, 'post_content' => 'Security test page.', 'post_author' => $admin->ID ) );
$oembed  = get_oembed_response_data( $page_id, 600 );
$t( 'oEmbed response carries no author name or author URL', is_array( $oembed ) && ! isset( $oembed['author_name'] ) && ! isset( $oembed['author_url'] ) );
$t( 'no users sitemap', false === apply_filters( 'wp_sitemaps_add_provider', new WP_Sitemaps_Users(), 'users' ) );
$t( 'posts sitemap unaffected', false !== apply_filters( 'wp_sitemaps_add_provider', new WP_Sitemaps_Posts(), 'posts' ) );

$saved_authordata      = $GLOBALS['authordata'] ?? null;
$GLOBALS['authordata'] = $admin;
$t( 'bylines never show a login name', get_bloginfo( 'name' ) === apply_filters( 'the_author', $admin->user_login ) );
$t( 'a real display name is shown unchanged', 'Display Name Example' === apply_filters( 'the_author', 'Display Name Example' ) );
$GLOBALS['authordata'] = $saved_authordata;

$errors = apply_filters( 'wp_login_errors', new WP_Error( 'incorrect_password', sprintf( '<strong>Error:</strong> The password you entered for the username %s is incorrect.', $admin->user_login ) ), '' );
$t( 'wrong password: one generic message, username not echoed', array( 'tmc_login_failed' ) === $errors->get_error_codes() && false === strpos( $errors->get_error_message(), $admin->user_login ) );
$errors = apply_filters( 'wp_login_errors', new WP_Error( 'invalid_username', '<strong>Error:</strong> The username is not registered on this site.' ), '' );
$t( 'unknown user: the same generic message', array( 'tmc_login_failed' ) === $errors->get_error_codes() && tmc_login_generic_error() === $errors->get_error_message() );
$errors = apply_filters( 'wp_login_errors', new WP_Error( 'loggedout', 'You are now logged out.', 'message' ), '' );
$t( 'unrelated notices are kept', array( 'loggedout' ) === $errors->get_error_codes() );

$fake = tmc_lostpassword_fake_success_url( new WP_Error( 'invalid_email', 'There is no account with that username or email address.' ), false, 'wp-login.php', 'nobody-' . $suffix . '@example.com' );
$t( 'lost password for an unknown e-mail looks like success (' . $fake . ')', 'wp-login.php?checkemail=confirm' === $fake );
$t( 'lost password for an unknown username looks like success', 'wp-login.php?checkemail=confirm' === tmc_lostpassword_fake_success_url( new WP_Error(), false, 'wp-login.php', 'nobody_' . $suffix ) );
$t( 'lost password for a real account: WordPress sends the e-mail as usual', '' === tmc_lostpassword_fake_success_url( new WP_Error(), $contrib, 'wp-login.php', $contrib->user_login ) );
$t( 'lost password with an empty field: WordPress shows its own message', '' === tmc_lostpassword_fake_success_url( new WP_Error( 'empty_username', 'Enter a username or email address.' ), false, 'wp-login.php', '' ) );

/* ------------------------------------------------------------------ 3. login throttling */

WP_CLI::log( '— Login throttling and lockout' );
$policy = tmc_login_policy();
$ip     = '198.51.100.23';
$ip_b   = '198.51.100.24';
$_SERVER['REMOTE_ADDR'] = $ip;
tmc_login_reset( $ip, $editor->user_login );
tmc_login_reset( $ip_b, $editor->user_login );

$t( 'backoff: none below the limit, then 1, 2, 4 min … capped', 0 === tmc_login_backoff( 4, 5, 60, 3600 ) && 60 === tmc_login_backoff( 5, 5, 60, 3600 ) && 120 === tmc_login_backoff( 6, 5, 60, 3600 ) && 240 === tmc_login_backoff( 7, 5, 60, 3600 ) && 3600 === tmc_login_backoff( 40, 5, 60, 3600 ) );

for ( $i = 1; $i < $policy['user_max']; $i++ ) {
	wp_authenticate( $editor->user_login, 'wrong-password-' . $i );
}
$t( 'not locked below the limit (' . ( $policy['user_max'] - 1 ) . ' failures)', 0 === tmc_login_lock_remaining( $ip, $editor->user_login ) );
wp_authenticate( $editor->user_login, 'wrong-password-last' );
$remaining = tmc_login_lock_remaining( $ip, $editor->user_login );
$t( "locked after {$policy['user_max']} failures ({$remaining} s)", $remaining > 0 && $remaining <= $policy['base'] );
$res = wp_authenticate( $editor->user_login, $passwords[ $editor->ID ] );
$t( 'correct password refused while locked, with the generic message', is_wp_error( $res ) && 'tmc_login_locked' === $res->get_error_code() && tmc_login_generic_error() === $res->get_error_message() );
$t( 'lockout follows the account when signing in with its e-mail address', tmc_login_lock_remaining( $ip_b, $editor->user_email ) > 0 );
wp_authenticate( $editor->user_login, 'wrong-during-lockout' );
$t( 'attempts during a lockout do not extend it', tmc_login_lock_remaining( $ip, $editor->user_login ) <= $remaining );
$t( 'password handlers restored after a refused attempt', 20 === has_filter( 'authenticate', 'wp_authenticate_username_password' ) );
$t( 'lockout recorded in the audit log', $audit_since( $first_audit_id, 'login_lockout', ' AND object_title = %s', array( $editor->user_login ) ) >= 1 );

$ghost = 'nobody_' . $suffix;
for ( $i = 0; $i < $policy['user_max']; $i++ ) {
	wp_authenticate( $ghost, 'x' );
}
$t( 'unknown usernames lock out exactly like real ones (no enumeration)', tmc_login_lock_remaining( $ip_b, $ghost ) > 0 );

$sprayed = array();
$fresh   = 'fresh_' . $suffix;
for ( $n = 0; 0 === tmc_login_lock_remaining( $ip, $fresh ) && $n < $policy['ip_max'] + 5; $n++ ) {
	$sprayed[] = "spray{$n}_{$suffix}";
	wp_authenticate( end( $sprayed ), 'x' );
}
$t( "the IP is locked after password spraying across many usernames ({$n} extra attempts)", tmc_login_lock_remaining( $ip, $fresh ) > 0 );
$t( 'other IPs are not affected', 0 === tmc_login_lock_remaining( $ip_b, $fresh ) );

tmc_login_reset( $ip, $editor->user_login );
$res = wp_authenticate( $editor->user_login, $passwords[ $editor->ID ] );
$t( 'after the lockout is cleared the correct password works', $res instanceof WP_User && $res->ID === $editor->ID );
tmc_login_register_failure( $ip_b, $editor->user_login );
tmc_login_on_success( $editor->user_login, $editor );
$t( 'a successful sign-in clears the account counter', 0 === tmc_login_bucket_state( tmc_login_buckets( '', $editor->user_login )['user']['key'] )['n'] );

tmc_login_reset( $ip, $ghost );
tmc_login_reset( $ip_b, $ghost );
tmc_login_reset( $ip_b, $editor->user_login );
foreach ( $sprayed as $name ) {
	tmc_login_reset( '', $name );
}

/* ------------------------------------------------------------------ 4. two-factor authentication */

WP_CLI::log( '— Two-factor authentication (enforcement ON)' );
$t( 'Two Factor plugin is active', tmc_mfa_plugin_active() );
$methods = tmc_mfa_plugin_active() ? array_keys( Two_Factor_Core::get_providers() ) : array();
sort( $methods );
$t( 'only TOTP and backup codes are offered (' . implode( ', ', $methods ) . ')', array( 'Two_Factor_Backup_Codes', 'Two_Factor_Totp' ) === $methods );

$set_env( 'TMC_ENFORCE_MFA', '1' );
$set_env( 'TMC_MFA_GRACE_HOURS', '0' );
foreach ( array( 'Super Admin' => $super, 'Site Administrator' => $admin, 'Reviewer / Publisher' => $editor ) as $label => $user ) {
	delete_user_meta( $user->ID, TMC_MFA_SINCE_META );
	$t( "$label must use two-factor", tmc_mfa_required_for( $user ) );
	$t( "$label without an authenticator is blocked (" . tmc_mfa_status( $user ) . ')', 'blocked' === tmc_mfa_status( $user ) && 'mfa' === tmc_account_gate( $user ) );
	$t( "$label: dashboard redirects to the profile's two-factor section", false !== strpos( tmc_account_gate_redirect( $user, 'index.php' ), 'profile.php' ) && '' === tmc_account_gate_redirect( $user, 'profile.php' ) );
	$error = tmc_account_gate_rest_error( $user, '/wp/v2/posts' );
	$t( "$label: REST writes refused, enrolment endpoints allowed", $error instanceof WP_Error && 403 === $error->get_error_data()['status'] && null === tmc_account_gate_rest_error( $user, '/two-factor/1.0/totp' ) );
}
$t( 'Content Editor is not forced (optional for this role)', ! tmc_mfa_required_for( $contrib ) && 'not-required' === tmc_mfa_status( $contrib ) && '' === tmc_account_gate_redirect( $contrib, 'index.php' ) );

$set_env( 'TMC_MFA_GRACE_HOURS', '24' );
delete_user_meta( $editor->ID, TMC_MFA_SINCE_META );
$t( 'grace period: a newly privileged account may work for a while', 'grace' === tmc_mfa_status( $editor ) && '' === tmc_account_gate( $editor ) );
update_user_meta( $editor->ID, TMC_MFA_SINCE_META, time() - 25 * HOUR_IN_SECONDS );
$t( 'grace period over: blocked', 'blocked' === tmc_mfa_status( $editor ) );
$set_env( 'TMC_MFA_GRACE_HOURS', '0' );

if ( class_exists( 'Two_Factor_Totp' ) && class_exists( 'Two_Factor_Backup_Codes' ) ) {
	$enrol_from = (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id),0) FROM ' . tmc_audit_table() );
	$key        = Two_Factor_Totp::generate_key();
	Two_Factor_Totp::get_instance()->set_user_totp_key( $editor->ID, $key );
	update_user_meta( $editor->ID, Two_Factor_Core::ENABLED_PROVIDERS_USER_META_KEY, array( 'Two_Factor_Totp', 'Two_Factor_Backup_Codes' ) );
	Two_Factor_Backup_Codes::get_instance()->generate_codes( $editor );

	$t( 'after authenticator set-up the account is enrolled', 'enrolled' === tmc_mfa_status( $editor ) && '' === tmc_account_gate( $editor ) );
	$t( 'Two Factor now asks this account for a code at sign-in', Two_Factor_Core::is_user_using_two_factor( $editor->ID ) );
	$t( 'a current authenticator code is accepted', Two_Factor_Totp::is_valid_authcode( $key, Two_Factor_Totp::calc_totp( $key ) ) );
	$t( '10 single-use backup codes issued', 10 === Two_Factor_Backup_Codes::codes_remaining_for_user( $editor ) );
	$t( 'grace marker cleared on enrolment', '' === get_user_meta( $editor->ID, TMC_MFA_SINCE_META, true ) );
	$mfa_where = ' AND object_id = %d';
	$t(
		'enrolment audited (TOTP, methods, backup codes)',
		$audit_since( $enrol_from, 'mfa_totp_enrolled', $mfa_where, array( $editor->ID ) ) >= 1
			&& $audit_since( $enrol_from, 'mfa_methods_changed', $mfa_where, array( $editor->ID ) ) >= 1
			&& $audit_since( $enrol_from, 'mfa_backup_codes_generated', $mfa_where, array( $editor->ID ) ) >= 1
	);
	Two_Factor_Totp::get_instance()->delete_user_totp_key( $editor->ID );
	$t( 'removing the authenticator blocks the account again', 'blocked' === tmc_mfa_status( $editor ) );
	$t( 'removal audited', $audit_since( $enrol_from, 'mfa_totp_removed', $mfa_where, array( $editor->ID ) ) >= 1 );
} else {
	$t( 'Two Factor provider classes loaded', false );
}

$set_env( 'TMC_ENFORCE_MFA', '0' );
$t( 'TMC_ENFORCE_MFA=0 lifts the requirement (test environments only)', ! tmc_mfa_required_for( $admin ) && 'not-required' === tmc_mfa_status( $admin ) );
$set_env( 'TMC_ENFORCE_MFA', null );
$t( 'enforcement is ON when the variable is not set', tmc_mfa_enforced() && tmc_mfa_required_for( $admin ) );
$restore_env();

/* ------------------------------------------------------------------ 5. application passwords */

WP_CLI::log( '— Application passwords' );
$set_env( 'TMC_APP_PASSWORD_ROLES', null );
$t( 'off network-wide when no role is allow-listed', ! apply_filters( 'wp_is_application_passwords_available', true ) );
$t( 'off for Site Administrators by default', ! apply_filters( 'wp_is_application_passwords_available_for_user', true, $admin ) );
$set_env( 'TMC_APP_PASSWORD_ROLES', 'editor' );
$t( 'allow-listed role (editor) may use them', apply_filters( 'wp_is_application_passwords_available', true ) && apply_filters( 'wp_is_application_passwords_available_for_user', true, get_userdata( $editor->ID ) ) );
$t( 'roles not on the list still may not', ! apply_filters( 'wp_is_application_passwords_available_for_user', true, $admin ) && ! apply_filters( 'wp_is_application_passwords_available_for_user', true, $contrib ) );
$restore_env();

/* ------------------------------------------------------------------ 6. password policy */

WP_CLI::log( '— Password policy (privileged accounts)' );
$t( 'too short: rejected', (bool) tmc_password_policy_errors( 'Ab1!xyz', 'someone', 'someone@example.com' ) );
$t( 'one character class: rejected', (bool) tmc_password_policy_errors( 'abcdefghijklmnopq', 'someone', 'someone@example.com' ) );
$t( 'common base word: rejected', (bool) tmc_password_policy_errors( 'Password@2026!', 'someone', 'someone@example.com' ) );
$t( 'contains the username: rejected', (bool) tmc_password_policy_errors( 'Xy7!' . $admin->user_login . '#', $admin->user_login, $admin->user_email ) );
$t( 'long repeats: rejected', (bool) tmc_password_policy_errors( 'Kq7!aaaaZt9#mw', 'someone', 'someone@example.com' ) );
$t( 'strong password: accepted', array() === tmc_password_policy_errors( 'Vq8#mRt2!pLz6w', 'someone', 'someone@example.com' ) );

$profile_errors = new WP_Error();
tmc_password_policy_on_profile( $profile_errors, true, (object) array( 'ID' => $admin->ID, 'user_login' => $admin->user_login, 'user_email' => $admin->user_email, 'user_pass' => 'weakpass1' ) );
$t( 'profile screen refuses a weak password for a Site Administrator', in_array( 'tmc_password_policy', $profile_errors->get_error_codes(), true ) );
$profile_errors = new WP_Error();
tmc_password_policy_on_profile( $profile_errors, true, (object) array( 'ID' => $contrib->ID, 'user_login' => $contrib->user_login, 'user_email' => $contrib->user_email, 'user_pass' => 'weakpass1', 'role' => 'editor' ) );
$t( 'and when a user is being promoted to Reviewer / Publisher', in_array( 'tmc_password_policy', $profile_errors->get_error_codes(), true ) );

$set_env( 'TMC_ENFORCE_MFA', '0' ); // isolate the password gate from the MFA gate
wp_set_password( 'Summer2026', $admin->ID );
$res = wp_authenticate( $admin->user_login, 'Summer2026' );
$t( 'privileged sign-in with a weak password is flagged', $res instanceof WP_User && '1' === (string) get_user_meta( $admin->ID, TMC_PASSWORD_CHANGE_META, true ) );
$t( '…and the account is sent to its profile to change it', 'password' === tmc_account_gate( $admin ) && false !== strpos( tmc_account_gate_redirect( $admin, 'edit.php' ), '#password' ) );
wp_set_password( $passwords[ $admin->ID ], $admin->ID );
wp_authenticate( $admin->user_login, $passwords[ $admin->ID ] );
$t( 'signing in with a compliant password clears the flag', '' === get_user_meta( $admin->ID, TMC_PASSWORD_CHANGE_META, true ) && '' === tmc_account_gate( $admin ) );
$restore_env();
tmc_login_reset( $ip, $admin->user_login );

/* ------------------------------------------------------------------ 7. sessions */

WP_CLI::log( '— Sessions' );
$set_env( 'TMC_ADMIN_IDLE_MINUTES', '30' );
$manager = WP_Session_Tokens::get_instance( $editor->ID );
$start   = time();
$token   = $manager->create( $start + DAY_IN_SECONDS );
$t( 'new sessions record the time of last activity', isset( $manager->get( $token )['tmc_last_activity'] ) );
$t(
	'idle timeout: the admin heartbeat alone does not keep a session alive',
	'active' === tmc_session_touch( $editor->ID, $token, $start + 29 * MINUTE_IN_SECONDS, false )
		&& 'expired' === tmc_session_touch( $editor->ID, $token, $start + 31 * MINUTE_IN_SECONDS, false )
		&& null === $manager->get( $token )
);
$token2 = $manager->create( $start + DAY_IN_SECONDS );
$t(
	'idle timeout: real activity keeps it alive',
	'active' === tmc_session_touch( $editor->ID, $token2, $start + 20 * MINUTE_IN_SECONDS, true )
		&& 'active' === tmc_session_touch( $editor->ID, $token2, $start + 45 * MINUTE_IN_SECONDS, true )
);
$manager->destroy( $token2 );
$t( 'privileged sessions last at most 12 h, even with "Remember me"', 12 * HOUR_IN_SECONDS === apply_filters( 'auth_cookie_expiration', 14 * DAY_IN_SECONDS, $editor->ID, true ) );
$t( 'Content Editor session length unchanged', 14 * DAY_IN_SECONDS === apply_filters( 'auth_cookie_expiration', 14 * DAY_IN_SECONDS, $contrib->ID, true ) );
$restore_env();

/* ------------------------------------------------------------------ 8. headers, security.txt, hardening */

WP_CLI::log( '— Security headers' );
$front = tmc_security_headers( 'front', false );
$csp   = $front['Content-Security-Policy'];
WP_CLI::log( '        ' . $csp );
$t( 'CSP: scripts only from this site or with the per-request nonce', false !== strpos( $csp, "script-src 'self' 'nonce-" . tmc_csp_nonce() . "'" ) );
$t( 'CSP: no unsafe-eval, no unsafe-inline scripts', false === strpos( $csp, 'unsafe-eval' ) && ! preg_match( "/script-src[^;]*'unsafe-inline'/", $csp ) );
$t( "CSP: object-src 'none', frame-ancestors, base-uri, form-action", false !== strpos( $csp, "object-src 'none'" ) && false !== strpos( $csp, "frame-ancestors 'self'" ) && false !== strpos( $csp, "base-uri 'self'" ) && false !== strpos( $csp, "form-action 'self'" ) );
$t( 'no HSTS over plain HTTP', ! isset( $front['Strict-Transport-Security'] ) );
$https = tmc_security_headers( 'front', true );
$t( 'HTTPS: HSTS one year + upgrade-insecure-requests', 0 === strpos( $https['Strict-Transport-Security'] ?? '', 'max-age=31536000' ) && false !== strpos( $https['Content-Security-Policy'], 'upgrade-insecure-requests' ) );
$login_csp = tmc_security_headers( 'login', false )['Content-Security-Policy'];
$t( 'login/admin: baseline policy (no objects, no framing by other sites)', false !== strpos( $login_csp, "object-src 'none'" ) && false !== strpos( $login_csp, "frame-ancestors 'self'" ) );
$t( 'inline scripts printed by WordPress carry the nonce', false !== strpos( wp_get_inline_script_tag( 'var tmcTest = 1;' ), 'nonce="' . tmc_csp_nonce() . '"' ) );
$boot = '';
if ( function_exists( 'tmc_prefs_boot' ) ) {
	ob_start();
	tmc_prefs_boot();
	$boot = ob_get_clean();
}
$t( "the theme's preference script carries the nonce", false !== strpos( $boot, 'nonce="' . tmc_csp_nonce() . '"' ) && false !== strpos( $boot, 'tmc-font-scale' ) );
$t( 'XML-RPC disabled in WordPress too', false === apply_filters( 'xmlrpc_enabled', true ) );
$t( 'avatars are never loaded from Gravatar', false === strpos( (string) get_avatar_url( $admin->ID ), 'gravatar.com' ) );

WP_CLI::log( '— security.txt and file modifications' );
$set_env( 'TMC_SECURITY_CONTACT', null );
$set_env( 'TMC_SECURITY_TXT_EXPIRES', null );
$body = tmc_security_txt_body( 'https://tmh.example.org/.well-known/security.txt', gmmktime( 0, 0, 0, 1, 1, 2026 ) );
$t( 'security.txt: Contact, Expires (+180 days), Canonical, languages', preg_match( '/^Contact: mailto:\S+$/m', $body ) && preg_match( '/^Expires: 2026-06-30T00:00:00Z$/m', $body ) && preg_match( '#^Canonical: https://tmh\.example\.org/\.well-known/security\.txt$#m', $body ) && false !== strpos( $body, 'Preferred-Languages: en, hi' ) );
$t( 'security.txt: placeholder contact clearly marked until TMC confirms one', false !== strpos( $body, 'PLACEHOLDER' ) && false !== strpos( $body, '.invalid' ) );
$set_env( 'TMC_SECURITY_CONTACT', 'mailto:security@example.org, javascript:alert(1)' );
$body = tmc_security_txt_body( 'https://tmh.example.org/.well-known/security.txt' );
$t( 'security.txt: configured contact used, invalid URI dropped, no placeholder', false !== strpos( $body, 'Contact: mailto:security@example.org' ) && false === strpos( $body, 'javascript' ) && false === strpos( $body, 'PLACEHOLDER' ) );
$set_env( 'TMC_DISALLOW_FILE_MODS', null );
$t( 'server: plugin/theme/core changes from admin screens disabled', tmc_file_mods_blocked( 'server', false ) );
$t( 'server: WP-CLI (the deployment pipeline) unaffected', ! tmc_file_mods_blocked( 'server', true ) );
$t( 'local/CI: not disabled unless TMC_DISALLOW_FILE_MODS=1', ! tmc_file_mods_blocked( 'ci', false ) );
$restore_env();

/* ------------------------------------------------------------------ cleanup */

WP_CLI::log( '— Cleanup' );
wp_set_current_user( 0 );
if ( null === $saved_ip ) {
	unset( $_SERVER['REMOTE_ADDR'] );
} else {
	$_SERVER['REMOTE_ADDR'] = $saved_ip;
}
wp_delete_post( $page_id, true );
revoke_super_admin( $super->ID );
foreach ( $fixtures as $user ) {
	tmc_login_reset( $ip, $user->user_login );
	wpmu_delete_user( $user->ID );
}
$left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE %s", $wpdb->esc_like( 'sectest_' ) . '%\_' . $wpdb->esc_like( $suffix ) ) );
$t( 'fixture accounts removed', 0 === $left );

WP_CLI::log( '' );
$fail ? WP_CLI::error( "$fail failed, $pass passed" ) : WP_CLI::success( "all $pass checks passed" );
