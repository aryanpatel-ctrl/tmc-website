<?php
/**
 * Redirect manager (tender §4.10 "correct handling of redirects", §4.11 "redirects for superseded
 * URLs").
 *
 * One table per site ({prefix}tmc_redirects). Site Administrators manage it in Tools → Redirects:
 * list, search, add, edit, delete, CSV import and export. Each rule sends an old address to a new
 * one with 301 (permanent) or 302 (temporary), or answers 410 (gone: removed on purpose).
 *
 *   - Rules apply only where the site would otherwise answer 404 — a rule can never hide a live
 *     page. Old addresses with a query string ("/showpage.php?id=12") also match exactly, because
 *     WordPress may answer those with the home page instead of a 404.
 *   - Matching ignores letter case and a trailing slash; same-site targets are stored as paths
 *     ("/departments/") so a rule works unchanged on local, UAT and production.
 *   - Regular-expression rules are off by default; a Super Admin can allow them per site. Patterns
 *     are compiled and checked when saved.
 *   - Loops (A → B → A) are refused when saving. Chains (A → B → C) are reported to the editor and
 *     followed on the server, so visitors get one redirect straight to the final address.
 *   - Lookups are one indexed query (or an object-cache hit) and only run on 404s; the hit counter
 *     and last-hit time show which old addresses are still in use.
 *   - Every change is recorded in the audit log.
 *
 * Module files: this file (table, matching, lookups, applying and changing rules), redirects-csv.php
 * (CSV import/export) and redirects-admin.php (the Tools → Redirects screen).
 */

defined( 'ABSPATH' ) || exit;

const TMC_REDIRECTS_DB_VERSION = 1;
const TMC_REDIRECT_MAX_HOPS    = 5;
const TMC_REDIRECT_STATUSES    = array(
	301 => 'Permanent (301)',
	302 => 'Temporary (302)',
	410 => 'Gone (410)',
);

function tmc_redirects_table() {
	global $wpdb;
	return $wpdb->prefix . 'tmc_redirects';
}

/** Create or upgrade the table of the current site (cheap no-op when up to date). */
function tmc_redirects_install() {
	// Not while WordPress or a site is still being installed (its tables do not exist yet).
	if ( ( function_exists( 'tmc_site_installed' ) && ! tmc_site_installed() ) || (int) get_option( 'tmc_redirects_db_version' ) === TMC_REDIRECTS_DB_VERSION ) {
		return true;
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$table = tmc_redirects_table();
	dbDelta(
		"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source varchar(2000) NOT NULL,
			source_key char(40) NOT NULL,
			is_regex tinyint(1) NOT NULL DEFAULT 0,
			target varchar(2000) NOT NULL DEFAULT '',
			target_key char(40) NOT NULL DEFAULT '',
			status smallint(3) unsigned NOT NULL DEFAULT 301,
			hits bigint(20) unsigned NOT NULL DEFAULT 0,
			last_hit datetime DEFAULT NULL,
			note varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			updated_at datetime NOT NULL,
			updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY source_key (source_key),
			KEY target_key (target_key),
			KEY is_regex (is_regex)
		) {$wpdb->get_charset_collate()};"
	);
	$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
	if ( $exists ) {
		update_option( 'tmc_redirects_db_version', TMC_REDIRECTS_DB_VERSION, true );
	}
	return $exists;
}
add_action( 'init', 'tmc_redirects_install', 1 );

/** May this site use regular-expression rules? (Off by default; Super Admins switch it on.) */
function tmc_redirects_regex_enabled() {
	return (bool) get_option( 'tmc_redirects_regex' );
}

/* ================================================================ normalising addresses */

/**
 * Match key of a site-relative address: decoded, lower case, no trailing slash, query kept.
 * "/Old/Page/?ID=5" → "/old/page?id=5".
 */
function tmc_redirect_key( $path, $query = '' ) {
	$path = rawurldecode( (string) $path );
	$path = '/' . ltrim( $path, '/' );
	$path = '/' === $path ? '/' : rtrim( $path, '/' );
	$key  = mb_strtolower( $path );
	$query = trim( (string) $query );
	if ( '' !== $query ) {
		$key .= '?' . mb_strtolower( rawurldecode( $query ) );
	}
	return $key;
}

/**
 * Split an address typed by an editor (or taken from a request) into path and query.
 * Accepts "/path", "path", "/path?x=1" or a full URL (the host is ignored).
 *
 * @return array{path:string,query:string}|WP_Error
 */
function tmc_redirect_split( $raw ) {
	$raw = trim( (string) $raw );
	if ( '' === $raw ) {
		return new WP_Error( 'tmc_redirect_empty', 'The address is empty.' );
	}
	if ( preg_match( '/[\x00-\x1F\x7F]/', $raw ) ) {
		return new WP_Error( 'tmc_redirect_invalid', 'The address contains control characters.' );
	}
	if ( strlen( $raw ) > 2000 ) {
		return new WP_Error( 'tmc_redirect_invalid', 'The address is longer than 2000 characters.' );
	}
	if ( preg_match( '~^https?://~i', $raw ) ) {
		$parts = wp_parse_url( $raw );
		if ( ! $parts || empty( $parts['host'] ) ) {
			return new WP_Error( 'tmc_redirect_invalid', 'The address is not a valid URL.' );
		}
		return array( 'path' => '/' . ltrim( $parts['path'] ?? '/', '/' ), 'query' => (string) ( $parts['query'] ?? '' ) );
	}
	$parts = explode( '?', tmc_redirect_without_fragment( $raw ), 2 );
	return array( 'path' => '/' . ltrim( str_replace( ' ', '%20', $parts[0] ), '/' ), 'query' => (string) ( $parts[1] ?? '' ) );
}

/** The address without its "#fragment". */
function tmc_redirect_without_fragment( $address ) {
	return explode( '#', (string) $address, 2 )[0];
}

/** PCRE for a stored pattern: case-insensitive, UTF-8, "~" delimiters. */
function tmc_redirect_regex( $pattern ) {
	return '~' . str_replace( '~', '\~', (string) $pattern ) . '~iu';
}

/** Is this target an address on the current site (a path)? */
function tmc_redirect_is_local( $target ) {
	return '' !== $target && '/' === $target[0] && ( ! isset( $target[1] ) || '/' !== $target[1] );
}

/**
 * Validate a target. Same-site absolute URLs become paths; other sites must be http(s).
 *
 * @return string|WP_Error
 */
function tmc_redirect_clean_target( $raw ) {
	$raw = trim( (string) $raw );
	if ( '' === $raw ) {
		return new WP_Error( 'tmc_redirect_target', 'A target address is required for 301 and 302 redirects.' );
	}
	if ( preg_match( '/[\x00-\x1F\x7F]/', $raw ) || strlen( $raw ) > 2000 ) {
		return new WP_Error( 'tmc_redirect_target', 'The target address is not valid.' );
	}
	if ( preg_match( '~^https?://~i', $raw ) ) {
		$url  = esc_url_raw( $raw, array( 'http', 'https' ) );
		$host = $url ? wp_parse_url( $url, PHP_URL_HOST ) : '';
		if ( ! $host ) {
			return new WP_Error( 'tmc_redirect_target', 'The target address is not a valid URL.' );
		}
		if ( strtolower( $host ) === strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
			$parts = wp_parse_url( $url );
			return '/' . ltrim( $parts['path'] ?? '/', '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' ) . ( isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '' );
		}
		return $url;
	}
	if ( '/' !== $raw[0] || str_starts_with( $raw, '//' ) ) {
		return new WP_Error( 'tmc_redirect_target', 'The target must start with "/" (this website) or "https://" (another website).' );
	}
	return str_replace( ' ', '%20', $raw );
}

/** Key of a same-site target ('' for other websites). */
function tmc_redirect_target_key( $target ) {
	if ( ! tmc_redirect_is_local( $target ) ) {
		return '';
	}
	$parts = tmc_redirect_split( tmc_redirect_without_fragment( $target ) );
	return is_wp_error( $parts ) ? '' : tmc_redirect_key( $parts['path'], $parts['query'] );
}

/* ================================================================ lookups (cached) */

function tmc_redirects_cache_version() {
	return wp_cache_get_last_changed( 'tmc_redirects' );
}

function tmc_redirects_flush() {
	wp_cache_set( 'last_changed', microtime(), 'tmc_redirects' );
	global $wpdb;
	$table = tmc_redirects_table();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$query_rules = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE is_regex = 0 AND source LIKE '%?%'" );
	update_option( 'tmc_redirects_query_rules', $query_rules, true );
}

function tmc_redirect_get( $id ) {
	global $wpdb;
	$table = tmc_redirects_table();
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

/** Exact rule for a match key, or null. */
function tmc_redirect_by_key( $key ) {
	global $wpdb;
	$hash  = sha1( $key );
	$cache = 'exact:' . tmc_redirects_cache_version() . ':' . $hash;
	$rule  = wp_cache_get( $cache, 'tmc_redirects' );
	if ( false === $rule ) {
		$table = tmc_redirects_table();
		$rule  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE source_key = %s AND is_regex = 0", $hash ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rule  = $rule ? $rule : 0;
		wp_cache_set( $cache, $rule, 'tmc_redirects', DAY_IN_SECONDS );
	}
	return $rule ? $rule : null;
}

/** All regular-expression rules (only when allowed on this site). */
function tmc_redirect_regex_rules() {
	if ( ! tmc_redirects_regex_enabled() ) {
		return array();
	}
	global $wpdb;
	$cache = 'regex:' . tmc_redirects_cache_version();
	$rules = wp_cache_get( $cache, 'tmc_redirects' );
	if ( false === $rules ) {
		$table = tmc_redirects_table();
		$rules = $wpdb->get_results( "SELECT * FROM {$table} WHERE is_regex = 1 ORDER BY id ASC LIMIT 500" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		wp_cache_set( $cache, $rules, 'tmc_redirects', DAY_IN_SECONDS );
	}
	return (array) $rules;
}

/**
 * Rule matching an address, and the target with regex groups filled in.
 *
 * @param string $address    "/path?query" (as requested).
 * @param bool   $query_only Only an exact match including the query string counts (used for
 *                           requests WordPress answers with a page).
 * @return array{rule:object,target:string}|null
 */
function tmc_redirect_match( $address, $query_only = false ) {
	$parts = tmc_redirect_split( $address );
	if ( is_wp_error( $parts ) ) {
		return null;
	}
	$keys = array();
	if ( '' !== $parts['query'] ) {
		$keys[] = tmc_redirect_key( $parts['path'], $parts['query'] );
	}
	if ( ! $query_only ) {
		$keys[] = tmc_redirect_key( $parts['path'] );
	}
	foreach ( $keys as $key ) {
		$rule = tmc_redirect_by_key( $key );
		if ( $rule ) {
			return array( 'rule' => $rule, 'target' => $rule->target );
		}
	}
	if ( $query_only ) {
		return null;
	}
	foreach ( tmc_redirect_regex_rules() as $rule ) {
		foreach ( $keys as $key ) {
			if ( 1 === @preg_match( tmc_redirect_regex( $rule->source ), $key, $groups ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- patterns are validated on save; a failure means "no match"
				$target = preg_replace_callback( '/\$(\d)/', fn( $m ) => rawurlencode( $groups[ (int) $m[1] ] ?? '' ), $rule->target );
				$target = str_replace( '%2F', '/', $target );
				// A captured "/example.org" must not turn "/$1" into a protocol-relative "//example.org".
				$target = preg_replace( '~^/{2,}~', '/', $target );
				return array( 'rule' => $rule, 'target' => $target );
			}
		}
	}
	return null;
}

/**
 * Final answer for a requested address: follows chains of same-site rules (at most
 * TMC_REDIRECT_MAX_HOPS), so the visitor is sent straight to the last address.
 *
 * @return array{status:int,location:string,rule_id:int,hops:int}|null
 */
function tmc_redirect_resolve( $address, $query_only = false ) {
	$match = tmc_redirect_match( $address, $query_only );
	if ( ! $match ) {
		return null;
	}
	$first  = $match['rule'];
	$status = (int) $first->status;
	$target = $match['target'];
	$hops   = 0;
	$seen   = array( tmc_redirect_target_key( $address ) => true );
	while ( 410 !== $status && tmc_redirect_is_local( $target ) && $hops < TMC_REDIRECT_MAX_HOPS ) {
		$key = tmc_redirect_target_key( $target );
		if ( isset( $seen[ $key ] ) ) {
			break; // a loop created outside the admin screen: stop at the last good address
		}
		$seen[ $key ] = true;
		$next         = tmc_redirect_match( tmc_redirect_without_fragment( $target ) );
		if ( ! $next ) {
			break;
		}
		++$hops;
		if ( 410 === (int) $next['rule']->status ) {
			$status = 410;
			break;
		}
		// A temporary step anywhere makes the whole answer temporary.
		$status = ( 302 === $status || 302 === (int) $next['rule']->status ) ? 302 : 301;
		$target = $next['target'];
	}
	return array(
		'status'   => $status,
		'location' => 410 === $status ? '' : ( tmc_redirect_is_local( $target ) ? home_url( $target ) : $target ),
		'rule_id'  => (int) $first->id,
		'hops'     => $hops,
	);
}

/* ================================================================ applying rules */

add_action( 'template_redirect', 'tmc_redirects_apply', 1 );
function tmc_redirects_apply() {
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- only compared, never output
	if ( '' === $uri ) {
		return;
	}
	if ( is_404() ) {
		$result = tmc_redirect_resolve( $uri );
	} elseif ( false !== strpos( $uri, '?' ) && (int) get_option( 'tmc_redirects_query_rules' ) > 0 ) {
		$result = tmc_redirect_resolve( $uri, true );
	} else {
		return;
	}
	if ( ! $result ) {
		return;
	}
	tmc_redirect_record_hit( $result['rule_id'] );
	if ( 410 === $result['status'] ) {
		$GLOBALS['tmc_redirect_gone'] = true;
		global $wp_query;
		$wp_query->set_404();
		status_header( 410 );
		nocache_headers();
		return; // the theme renders its 410 (or 404) template
	}
	wp_redirect( $result['location'], $result['status'], 'TMC redirects' ); // phpcs:ignore WordPress.Security.SafeRedirect -- targets are set by site administrators
	exit;
}

/** "Gone" pages use the theme's 410.php when it has one. */
add_filter(
	'template_include',
	function ( $template ) {
		if ( ! empty( $GLOBALS['tmc_redirect_gone'] ) ) {
			$gone = locate_template( '410.php' );
			return $gone ? $gone : $template;
		}
		return $template;
	}
);

function tmc_redirect_record_hit( $id ) {
	global $wpdb;
	$table = tmc_redirects_table();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET hits = hits + 1, last_hit = %s WHERE id = %d", current_time( 'mysql', true ), $id ) );
}

/* ================================================================ changing rules */

/**
 * Follow the rules from a target to see where visitors would end up.
 *
 * @return array{loop:bool,path:string[],ends_gone:bool}
 */
function tmc_redirect_trace( $source_key, $target, $exclude_id = 0, $regex = '' ) {
	$path    = array();
	$visited = array( $source_key => true );
	$current = $target;
	for ( $i = 0; $i < 10 && tmc_redirect_is_local( $current ); $i++ ) {
		$key = tmc_redirect_target_key( $current );
		if ( isset( $visited[ $key ] ) || ( '' !== $regex && 1 === @preg_match( tmc_redirect_regex( $regex ), $key ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return array( 'loop' => true, 'path' => $path, 'ends_gone' => false );
		}
		$visited[ $key ] = true;
		$next            = tmc_redirect_match( tmc_redirect_without_fragment( $current ) );
		if ( ! $next || (int) $next['rule']->id === (int) $exclude_id ) {
			break;
		}
		$path[] = $next['rule']->source;
		if ( 410 === (int) $next['rule']->status ) {
			return array( 'loop' => false, 'path' => $path, 'ends_gone' => true );
		}
		$current = $next['target'];
	}
	return array( 'loop' => false, 'path' => $path, 'ends_gone' => false );
}

/**
 * Create or update a rule (used by the admin screen, CSV import, the content importer and tests).
 *
 * @param array $data  source, target, status (301|302|410), is_regex (bool), note.
 * @param int   $id    Rule to update (0 = new).
 * @param bool  $audit Record the change in the audit log (bulk imports log one summary instead).
 * @return array{id:int,warnings:string[],created:bool}|WP_Error
 */
function tmc_redirect_save( array $data, $id = 0, $audit = true ) {
	global $wpdb;
	tmc_redirects_install();
	$table    = tmc_redirects_table();
	$id       = absint( $id );
	$status   = (int) ( $data['status'] ?? 301 );
	$is_regex = ! empty( $data['is_regex'] );
	$note     = mb_substr( sanitize_text_field( (string) ( $data['note'] ?? '' ) ), 0, 255 );
	$warnings = array();
	$existing = $id ? tmc_redirect_get( $id ) : null;

	if ( $id && ! $existing ) {
		return new WP_Error( 'tmc_redirect_missing', 'The redirect no longer exists.' );
	}
	if ( ! isset( TMC_REDIRECT_STATUSES[ $status ] ) ) {
		return new WP_Error( 'tmc_redirect_status', 'The redirect type must be 301, 302 or 410.' );
	}

	// Source.
	if ( $is_regex ) {
		if ( ! tmc_redirects_regex_enabled() ) {
			return new WP_Error( 'tmc_redirect_regex_off', 'Regular-expression rules are not enabled on this site.' );
		}
		$source = trim( (string) ( $data['source'] ?? '' ) );
		if ( '' === $source || strlen( $source ) > 250 || preg_match( '/[\x00-\x1F\x7F]/', $source ) ) {
			return new WP_Error( 'tmc_redirect_regex', 'The pattern must be 1–250 characters.' );
		}
		if ( false === @preg_match( tmc_redirect_regex( $source ), '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new WP_Error( 'tmc_redirect_regex', 'The regular expression is not valid.' );
		}
		$source_key = 'regex:' . $source;
	} else {
		$parts = tmc_redirect_split( $data['source'] ?? '' );
		if ( is_wp_error( $parts ) ) {
			return new WP_Error( 'tmc_redirect_source', 'Old address: ' . $parts->get_error_message() );
		}
		$source     = $parts['path'] . ( '' !== $parts['query'] ? '?' . $parts['query'] : '' );
		$source_key = tmc_redirect_key( $parts['path'], $parts['query'] );
		if ( '/' === $source_key ) {
			return new WP_Error( 'tmc_redirect_source', 'The home page cannot be redirected.' );
		}
		if ( preg_match( '~^/(wp-admin|wp-login\.php|wp-json)(/|\?|$)~', $source_key ) ) {
			return new WP_Error( 'tmc_redirect_source', 'System addresses (administration, login, API) cannot be redirected.' );
		}
		$post_id = url_to_postid( home_url( $parts['path'] ) );
		if ( $post_id && 'publish' === get_post_status( $post_id ) ) {
			$warnings[] = sprintf( 'The old address %s currently shows a published page, so this rule will not apply while that page exists.', $source );
		}
	}

	// Target.
	$target = '';
	if ( 410 !== $status ) {
		$target = tmc_redirect_clean_target( $data['target'] ?? '' );
		if ( is_wp_error( $target ) ) {
			return $target;
		}
	}
	$target_key = 410 === $status ? '' : tmc_redirect_target_key( $target );

	// Duplicate source.
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$duplicate = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE source_key = %s AND id <> %d", sha1( $source_key ), $id ) );
	if ( $duplicate ) {
		return new WP_Error( 'tmc_redirect_duplicate', sprintf( 'A redirect for %s already exists (rule #%d).', $source, $duplicate ) );
	}

	// Loops and chains.
	if ( 410 !== $status ) {
		if ( ! $is_regex && $target_key === $source_key ) {
			return new WP_Error( 'tmc_redirect_loop', 'The target is the same as the old address (the redirect would loop).' );
		}
		$trace = tmc_redirect_trace( $is_regex ? '' : $source_key, $target, $id, $is_regex ? $source : '' );
		if ( $trace['loop'] ) {
			return new WP_Error( 'tmc_redirect_loop', sprintf( 'Redirect loop: %s → %s leads back to where it started. Change the target.', $source, implode( ' → ', array_merge( array( $target ), $trace['path'] ) ) ) );
		}
		if ( $trace['path'] ) {
			$warnings[] = sprintf(
				'Redirect chain: %s → %s%s. Visitors are sent straight to the end of the chain, but pointing this rule at the final address is clearer.',
				$source,
				implode( ' → ', array_merge( array( $target ), $trace['path'] ) ),
				$trace['ends_gone'] ? ' (which is marked as gone)' : ''
			);
		}
	}
	if ( ! $is_regex ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$incoming = $wpdb->get_col( $wpdb->prepare( "SELECT source FROM {$table} WHERE target_key = %s AND id <> %d AND status <> 410 LIMIT 5", sha1( $source_key ), $id ) );
		if ( $incoming ) {
			$warnings[] = sprintf( 'Redirect chain: %s already redirect to %s. They now continue to the new target.', implode( ', ', $incoming ), $source );
		}
	}

	$now = current_time( 'mysql', true );
	$row = array(
		'source'     => $source,
		'source_key' => sha1( $source_key ),
		'is_regex'   => $is_regex ? 1 : 0,
		'target'     => $target,
		'target_key' => '' !== $target_key ? sha1( $target_key ) : '',
		'status'     => $status,
		'note'       => $note,
		'updated_at' => $now,
		'updated_by' => get_current_user_id(),
	);
	if ( $existing ) {
		$wpdb->update( $table, $row, array( 'id' => $id ) );
	} else {
		$row += array( 'created_at' => $now, 'created_by' => get_current_user_id() );
		if ( false === $wpdb->insert( $table, $row ) ) {
			return new WP_Error( 'tmc_redirect_db', 'The redirect could not be saved: ' . $wpdb->last_error );
		}
		$id = (int) $wpdb->insert_id;
	}
	tmc_redirects_flush();

	if ( $audit && function_exists( 'tmc_audit' ) ) {
		$details = array( 'to' => array( 'source' => $source, 'target' => $target, 'status' => $status, 'regex' => $is_regex ) );
		if ( $existing ) {
			$details['from'] = array( 'source' => $existing->source, 'target' => $existing->target, 'status' => (int) $existing->status, 'regex' => (bool) $existing->is_regex );
		}
		tmc_audit( $existing ? 'redirect_updated' : 'redirect_created', array( 'object_type' => 'redirect', 'object_id' => $id, 'object_title' => $source, 'details' => $details ) );
	}
	return array( 'id' => $id, 'warnings' => $warnings, 'created' => ! $existing );
}

function tmc_redirect_delete( $id, $audit = true ) {
	global $wpdb;
	$rule = tmc_redirect_get( $id );
	if ( ! $rule ) {
		return false;
	}
	$wpdb->delete( tmc_redirects_table(), array( 'id' => (int) $id ) );
	tmc_redirects_flush();
	if ( $audit && function_exists( 'tmc_audit' ) ) {
		tmc_audit( 'redirect_deleted', array( 'object_type' => 'redirect', 'object_id' => (int) $id, 'object_title' => $rule->source, 'details' => array( 'target' => $rule->target, 'status' => (int) $rule->status, 'hits' => (int) $rule->hits ) ) );
	}
	return true;
}
