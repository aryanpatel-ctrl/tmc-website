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
	if ( (int) get_option( 'tmc_redirects_db_version' ) === TMC_REDIRECTS_DB_VERSION ) {
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
	$raw   = strtok( $raw, '#' );
	$parts = explode( '?', (string) $raw, 2 );
	return array( 'path' => '/' . ltrim( str_replace( ' ', '%20', $parts[0] ), '/' ), 'query' => (string) ( $parts[1] ?? '' ) );
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
	$parts = tmc_redirect_split( strtok( $target, '#' ) );
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
		$next         = tmc_redirect_match( strtok( $target, '#' ) );
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
		$next            = tmc_redirect_match( strtok( $current, '#' ) );
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

/* ================================================================ CSV import / export */

const TMC_REDIRECT_CSV_COLUMNS = array( 'source', 'target', 'status', 'regex', 'note' );

/** Undo the formula protection added on export ("'=..." → "=..."). */
function tmc_redirect_csv_value( $value ) {
	$value = trim( (string) $value );
	return preg_match( "/^'[=+\-@]/", $value ) ? substr( $value, 1 ) : $value;
}

/**
 * Import rules from a CSV file with the header source,target,status[,regex][,note].
 *
 * @param string $file            Path to the CSV file.
 * @param bool   $update_existing Update rules whose source already exists (otherwise skip them).
 * @param string $label           File name for the audit log.
 * @return array{created:int,updated:int,skipped:int,errors:array<int,string>,warnings:array<int,string>}|WP_Error
 */
function tmc_redirects_import_csv( $file, $update_existing = false, $label = '' ) {
	global $wpdb;
	$handle = is_readable( $file ) ? fopen( $file, 'r' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( ! $handle ) {
		return new WP_Error( 'tmc_redirect_csv', 'The file could not be read.' );
	}
	$header = fgetcsv( $handle, 0, ',', '"', '' );
	if ( ! $header ) {
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return new WP_Error( 'tmc_redirect_csv', 'The file is empty.' );
	}
	$header    = array_map( fn( $h ) => strtolower( trim( preg_replace( '/^\xEF\xBB\xBF/', '', (string) $h ) ) ), $header );
	$positions = array_flip( $header );
	if ( ! isset( $positions['source'], $positions['status'] ) || ! isset( $positions['target'] ) ) {
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return new WP_Error( 'tmc_redirect_csv', 'The first line must be the header: source,target,status (optional: regex,note).' );
	}
	$result = array( 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => array(), 'warnings' => array() );
	$table  = tmc_redirects_table();
	$line   = 1;
	while ( ( $cells = fgetcsv( $handle, 0, ',', '"', '' ) ) !== false ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
		++$line;
		if ( array( null ) === $cells || '' === trim( implode( '', $cells ) ) ) {
			continue;
		}
		if ( $line > 20001 ) {
			$result['errors'][ $line ] = 'Stopped: at most 20,000 rules per file.';
			break;
		}
		$get      = fn( $column ) => isset( $positions[ $column ] ) ? tmc_redirect_csv_value( $cells[ $positions[ $column ] ] ?? '' ) : '';
		$is_regex = in_array( strtolower( $get( 'regex' ) ), array( '1', 'yes', 'true' ), true );
		$data     = array(
			'source'   => $get( 'source' ),
			'target'   => $get( 'target' ),
			'status'   => (int) $get( 'status' ),
			'is_regex' => $is_regex,
			'note'     => $get( 'note' ),
		);
		$existing_id = 0;
		if ( ! $is_regex ) {
			$parts = tmc_redirect_split( $data['source'] );
			if ( ! is_wp_error( $parts ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$existing_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE source_key = %s", sha1( tmc_redirect_key( $parts['path'], $parts['query'] ) ) ) );
			}
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$existing_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE source_key = %s", sha1( 'regex:' . trim( $data['source'] ) ) ) );
		}
		if ( $existing_id && ! $update_existing ) {
			++$result['skipped'];
			continue;
		}
		$saved = tmc_redirect_save( $data, $existing_id, false );
		if ( is_wp_error( $saved ) ) {
			$result['errors'][ $line ] = $saved->get_error_message();
			continue;
		}
		$saved['created'] ? ++$result['created'] : ++$result['updated'];
		foreach ( $saved['warnings'] as $warning ) {
			$result['warnings'][ $line ] = $warning;
		}
	}
	fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( function_exists( 'tmc_audit' ) ) {
		tmc_audit(
			'redirects_imported',
			array(
				'object_type'  => 'redirect',
				'object_title' => $label ? $label : basename( $file ),
				'details'      => array(
					'created'  => $result['created'],
					'updated'  => $result['updated'],
					'skipped'  => $result['skipped'],
					'errors'   => count( $result['errors'] ),
					'sha256'   => hash_file( 'sha256', $file ),
				),
			)
		);
	}
	return $result;
}

/** Rows for export, oldest first. */
function tmc_redirects_all() {
	global $wpdb;
	$table = tmc_redirects_table();
	return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

/** Write all rules as CSV to a stream (formula-looking cells are neutralised). */
function tmc_redirects_write_csv( $out ) {
	$safe = static fn( $v ) => preg_match( '/^[=+\-@\t\r]/', (string) $v ) ? "'" . $v : $v;
	fputcsv( $out, array_merge( TMC_REDIRECT_CSV_COLUMNS, array( 'hits', 'last_hit_utc', 'created_utc', 'updated_utc' ) ), ',', '"', '' );
	foreach ( tmc_redirects_all() as $rule ) {
		fputcsv(
			$out,
			array_map( $safe, array( $rule->source, $rule->target, $rule->status, $rule->is_regex ? '1' : '0', $rule->note, $rule->hits, (string) $rule->last_hit, $rule->created_at, $rule->updated_at ) ),
			',',
			'"',
			''
		);
	}
}

/* ================================================================ admin screen: Tools → Redirects */

add_action(
	'admin_menu',
	function () {
		add_management_page( 'Redirects', 'Redirects', 'manage_options', 'tmc-redirects', 'tmc_redirects_page' );
	}
);

function tmc_redirects_admin_url( array $args = array() ) {
	return add_query_arg( $args + array( 'page' => 'tmc-redirects' ), admin_url( 'tools.php' ) );
}

/** Messages survive the redirect after a POST (per user, short-lived). */
function tmc_redirects_notice( $type, $message ) {
	$key              = 'tmc_redirects_notices_' . get_current_user_id();
	$notices          = (array) get_transient( $key );
	$notices[]        = array( $type, $message );
	set_transient( $key, array_filter( $notices ), 5 * MINUTE_IN_SECONDS );
}

function tmc_redirects_print_notices() {
	$key     = 'tmc_redirects_notices_' . get_current_user_id();
	$notices = array_filter( (array) get_transient( $key ) );
	delete_transient( $key );
	foreach ( $notices as list( $type, $message ) ) {
		printf(
			'<div class="notice notice-%s" role="%s"><p>%s</p></div>',
			esc_attr( $type ),
			'error' === $type ? 'alert' : 'status',
			wp_kses( $message, array( 'br' => array(), 'strong' => array(), 'code' => array() ) )
		);
	}
}

function tmc_redirects_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'You are not allowed to manage redirects.', 403 );
	}
	tmc_redirects_install();
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view parameters
	$view   = sanitize_key( $_GET['view'] ?? '' );
	$edit   = $view === 'edit' ? tmc_redirect_get( absint( $_GET['id'] ?? 0 ) ) : null;
	$search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
	$filter = absint( $_GET['status'] ?? 0 );
	$paged  = max( 1, absint( $_GET['paged'] ?? 1 ) );
	// phpcs:enable

	echo '<div class="wrap tmc-redirects"><h1 class="wp-heading-inline">Redirects</h1>';
	printf( ' <a class="page-title-action" href="%s">Add redirect</a>', esc_url( tmc_redirects_admin_url( array( 'view' => 'add' ) ) . '#tmc-redirect-form' ) );
	printf( ' <a class="page-title-action" href="%s">Export CSV</a>', esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tmc_redirects_export' ), 'tmc_redirects_export' ) ) );
	echo '<hr class="wp-header-end">';
	tmc_redirects_print_notices();
	echo '<p>Send visitors and search engines from old or removed addresses to the right page. Rules apply only to addresses that do not exist on this website, so they can never hide a live page. <strong>301</strong> = moved permanently, <strong>302</strong> = moved temporarily, <strong>410</strong> = removed on purpose.</p>';

	if ( 'add' === $view || $edit ) {
		tmc_redirects_form( $edit );
	}
	tmc_redirects_list( $search, $filter, $paged );
	tmc_redirects_import_form();
	tmc_redirects_settings_form();
	echo '</div>';
}

function tmc_redirects_form( $rule ) {
	$regex_on = tmc_redirects_regex_enabled();
	$status   = $rule ? (int) $rule->status : 301;
	echo '<div class="card" style="max-width:none" id="tmc-redirect-form">';
	printf( '<h2>%s</h2>', $rule ? 'Edit redirect' : 'Add redirect' );
	printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
	wp_nonce_field( 'tmc_redirect_save' );
	echo '<input type="hidden" name="action" value="tmc_redirect_save">';
	printf( '<input type="hidden" name="id" value="%d">', $rule ? (int) $rule->id : 0 );
	echo '<table class="form-table" role="presentation">';
	printf(
		'<tr><th scope="row"><label for="tmc-redirect-source">Old address <span aria-hidden="true">*</span><span class="screen-reader-text">(required)</span></label></th><td><input type="text" id="tmc-redirect-source" name="source" class="large-text code" required value="%s" aria-describedby="tmc-redirect-source-help"><p class="description" id="tmc-redirect-source-help">The path that no longer exists, e.g. <code>/old-section/page.html</code> or <code>/showpage.php?id=12</code>. A full address (https://…) is accepted; only the path is used.</p></td></tr>',
		esc_attr( $rule ? $rule->source : '' )
	);
	if ( $regex_on ) {
		printf(
			'<tr><th scope="row">Match type</th><td><label><input type="checkbox" name="is_regex" value="1"%s aria-describedby="tmc-redirect-regex-help"> The old address is a regular expression</label><p class="description" id="tmc-redirect-regex-help">For advanced use only. Example: <code>^/news/(\d+)$</code> with the target <code>/media/news/?id=$1</code>. Matching is case-insensitive against the lower-case path.</p></td></tr>',
			checked( $rule && $rule->is_regex, true, false )
		);
	}
	printf(
		'<tr><th scope="row"><label for="tmc-redirect-target">New address</label></th><td><input type="text" id="tmc-redirect-target" name="target" class="large-text code" value="%s" aria-describedby="tmc-redirect-target-help"><p class="description" id="tmc-redirect-target-help">A path on this website (<code>/departments/</code>) or a full address of another website (<code>https://…</code>). Not needed for 410.</p></td></tr>',
		esc_attr( $rule ? $rule->target : '' )
	);
	echo '<tr><th scope="row">Type</th><td><fieldset><legend class="screen-reader-text">Redirect type</legend>';
	foreach ( TMC_REDIRECT_STATUSES as $code => $label ) {
		printf( '<label style="display:block;margin:.25em 0"><input type="radio" name="status" value="%d"%s> %s</label>', (int) $code, checked( $status, $code, false ), esc_html( $label ) );
	}
	echo '</fieldset></td></tr>';
	printf(
		'<tr><th scope="row"><label for="tmc-redirect-note">Note</label></th><td><input type="text" id="tmc-redirect-note" name="note" class="large-text" maxlength="255" value="%s"><p class="description">Optional: why this redirect exists (e.g. "migrated from old website").</p></td></tr>',
		esc_attr( $rule ? $rule->note : '' )
	);
	echo '</table>';
	submit_button( $rule ? 'Update redirect' : 'Add redirect', 'primary', 'submit', false );
	printf( ' <a class="button button-secondary" href="%s">Cancel</a>', esc_url( tmc_redirects_admin_url() ) );
	echo '</form></div>';
}

function tmc_redirects_list( $search, $filter, $paged ) {
	global $wpdb;
	$table    = tmc_redirects_table();
	$per_page = 50;
	$where    = array( '1=1' );
	$args     = array();
	if ( '' !== $search ) {
		$like    = '%' . $wpdb->esc_like( $search ) . '%';
		$where[] = '(source LIKE %s OR target LIKE %s OR note LIKE %s)';
		array_push( $args, $like, $like, $like );
	}
	if ( isset( TMC_REDIRECT_STATUSES[ $filter ] ) ) {
		$where[] = 'status = %d';
		$args[]  = $filter;
	}
	$where = implode( ' AND ', $where );
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders
	$total = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $args ) ) : $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) );
	$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d", array_merge( $args, array( $per_page, ( $paged - 1 ) * $per_page ) ) ) );
	// phpcs:enable

	echo '<h2>Rules</h2>';
	echo '<form method="get" class="tmc-redirects-filter" style="margin:.5em 0 1em"><input type="hidden" name="page" value="tmc-redirects">';
	printf( '<label for="tmc-redirect-search">Search addresses and notes</label> <input type="search" id="tmc-redirect-search" name="s" value="%s"> ', esc_attr( $search ) );
	echo '<label for="tmc-redirect-status">Type</label> <select id="tmc-redirect-status" name="status"><option value="0">All types</option>';
	foreach ( TMC_REDIRECT_STATUSES as $code => $label ) {
		printf( '<option value="%d"%s>%s</option>', (int) $code, selected( $filter, $code, false ), esc_html( $label ) );
	}
	echo '</select> ';
	submit_button( 'Filter', 'secondary', '', false );
	echo '</form>';

	printf( '<p role="status">%s</p>', esc_html( sprintf( 1 === $total ? '%s redirect' : '%s redirects', number_format_i18n( $total ) ) ) );
	echo '<table class="widefat striped"><caption class="screen-reader-text">Redirect rules</caption><thead><tr><th scope="col">Old address</th><th scope="col">New address</th><th scope="col">Type</th><th scope="col">Hits</th><th scope="col">Last hit (IST)</th><th scope="col">Note</th><th scope="col">Actions</th></tr></thead><tbody>';
	if ( ! $rows ) {
		echo '<tr><td colspan="7">No redirects found.</td></tr>';
	}
	foreach ( $rows as $rule ) {
		$flags = array();
		if ( $rule->is_regex ) {
			$flags[] = '<span class="tmc-flag">regex</span>';
		}
		if ( 410 !== (int) $rule->status && tmc_redirect_is_local( $rule->target ) && tmc_redirect_match( strtok( $rule->target, '#' ) ) ) {
			$flags[] = '<span class="tmc-flag tmc-flag-warn">chain: the new address is redirected again</span>';
		}
		printf(
			'<tr><td><code>%s</code> %s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>',
			esc_html( $rule->source ),
			wp_kses( implode( ' ', $flags ), array( 'span' => array( 'class' => true ) ) ),
			410 === (int) $rule->status ? '—' : '<code>' . esc_html( $rule->target ) . '</code>',
			esc_html( TMC_REDIRECT_STATUSES[ (int) $rule->status ] ?? (string) $rule->status ),
			esc_html( number_format_i18n( (int) $rule->hits ) ),
			esc_html( $rule->last_hit ? wp_date( 'd/m/Y H:i', strtotime( $rule->last_hit . ' UTC' ) ) : 'never' ),
			esc_html( $rule->note )
		);
		printf(
			'<a href="%s">Edit<span class="screen-reader-text"> redirect %s</span></a> ',
			esc_url( tmc_redirects_admin_url( array( 'view' => 'edit', 'id' => (int) $rule->id ) ) . '#tmc-redirect-form' ),
			esc_html( $rule->source )
		);
		printf( '<form method="post" action="%s" style="display:inline">', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( 'tmc_redirect_delete_' . (int) $rule->id, '_wpnonce', false );
		printf(
			'<input type="hidden" name="action" value="tmc_redirect_delete"><input type="hidden" name="id" value="%d"><button type="submit" class="button-link button-link-delete">Delete<span class="screen-reader-text"> redirect %s</span></button></form>',
			(int) $rule->id,
			esc_html( $rule->source )
		);
		echo '</td></tr>';
	}
	echo '</tbody></table>';

	$pages = (int) ceil( $total / $per_page );
	if ( $pages > 1 ) {
		echo '<nav class="tablenav" aria-label="Redirect pages"><div class="tablenav-pages">' . wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => $pages ) ) ) . '</div></nav>';
	}
}

function tmc_redirects_import_form() {
	echo '<h2 id="tmc-redirects-import">Import from CSV</h2>';
	echo '<p>First line: <code>source,target,status</code> and optionally <code>regex,note</code>. One rule per line; status is 301, 302 or 410. The export above uses the same format. Every line is checked (loops are refused) and the result is shown here.</p>';
	printf( '<form method="post" enctype="multipart/form-data" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
	wp_nonce_field( 'tmc_redirects_import' );
	echo '<input type="hidden" name="action" value="tmc_redirects_import">';
	echo '<p><label for="tmc-redirects-file">CSV file</label><br><input type="file" id="tmc-redirects-file" name="file" accept=".csv,text/csv" required></p>';
	echo '<p><label><input type="checkbox" name="update_existing" value="1"> Replace rules that already exist for the same old address</label></p>';
	submit_button( 'Import redirects', 'secondary', 'submit', false );
	echo '</form>';
}

function tmc_redirects_settings_form() {
	echo '<h2>Settings</h2>';
	$enabled = tmc_redirects_regex_enabled();
	if ( ! current_user_can( 'manage_network_options' ) ) {
		printf( '<p>Regular-expression rules are <strong>%s</strong> on this site. Only a Super Admin can change this.</p>', $enabled ? 'allowed' : 'not allowed' );
		return;
	}
	printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
	wp_nonce_field( 'tmc_redirects_settings' );
	echo '<input type="hidden" name="action" value="tmc_redirects_settings">';
	printf( '<p><label><input type="checkbox" name="regex" value="1"%s> Allow regular-expression rules on this site</label><br><span class="description">Off by default. Patterns are powerful and easy to get wrong; use them only for large, regular URL changes.</span></p>', checked( $enabled, true, false ) );
	submit_button( 'Save settings', 'secondary', 'submit', false );
	echo '</form>';
}

add_action( 'admin_head-tools_page_tmc-redirects', function () {
	echo '<style>.tmc-redirects .tmc-flag{display:inline-block;padding:0 .4em;border:1px solid currentColor;border-radius:3px;font-size:12px}.tmc-redirects .tmc-flag-warn{color:#8a4b00}.tmc-redirects td code{word-break:break-all}.tmc-redirects .card{padding:0 1em 1em;margin-bottom:1.5em}</style>';
} );

/* ---------------------------------------------------------------- handlers */

function tmc_redirects_check( $nonce_action ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'You are not allowed to manage redirects.', 403 );
	}
	check_admin_referer( $nonce_action );
}

add_action( 'admin_post_tmc_redirect_save', 'tmc_redirects_handle_save' );
function tmc_redirects_handle_save() {
	tmc_redirects_check( 'tmc_redirect_save' );
	// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- validated in tmc_redirect_save()
	$id   = absint( $_POST['id'] ?? 0 );
	$data = array(
		'source'   => wp_unslash( $_POST['source'] ?? '' ),
		'target'   => wp_unslash( $_POST['target'] ?? '' ),
		'status'   => absint( $_POST['status'] ?? 301 ),
		'is_regex' => ! empty( $_POST['is_regex'] ),
		'note'     => wp_unslash( $_POST['note'] ?? '' ),
	);
	// phpcs:enable
	$result = tmc_redirect_save( $data, $id );
	if ( is_wp_error( $result ) ) {
		tmc_redirects_notice( 'error', '<strong>Not saved.</strong> ' . esc_html( $result->get_error_message() ) );
		wp_safe_redirect( tmc_redirects_admin_url( $id ? array( 'view' => 'edit', 'id' => $id ) : array( 'view' => 'add' ) ) . '#tmc-redirect-form' );
		exit;
	}
	tmc_redirects_notice( 'success', $result['created'] ? 'Redirect added.' : 'Redirect updated.' );
	foreach ( $result['warnings'] as $warning ) {
		tmc_redirects_notice( 'warning', esc_html( $warning ) );
	}
	wp_safe_redirect( tmc_redirects_admin_url() );
	exit;
}

add_action( 'admin_post_tmc_redirect_delete', 'tmc_redirects_handle_delete' );
function tmc_redirects_handle_delete() {
	$id = absint( $_POST['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified next
	tmc_redirects_check( 'tmc_redirect_delete_' . $id );
	if ( tmc_redirect_delete( $id ) ) {
		tmc_redirects_notice( 'success', 'Redirect deleted.' );
	} else {
		tmc_redirects_notice( 'error', 'The redirect was not found (it may already have been deleted).' );
	}
	wp_safe_redirect( tmc_redirects_admin_url() );
	exit;
}

add_action( 'admin_post_tmc_redirects_import', 'tmc_redirects_handle_import' );
function tmc_redirects_handle_import() {
	tmc_redirects_check( 'tmc_redirects_import' );
	$file = $_FILES['file'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	if ( ! $file || UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
		tmc_redirects_notice( 'error', 'No file was uploaded.' );
	} elseif ( (int) $file['size'] > 5 * MB_IN_BYTES || ! preg_match( '/\.csv$/i', (string) $file['name'] ) ) {
		tmc_redirects_notice( 'error', 'Please upload a .csv file of at most 5 MB.' );
	} else {
		$result = tmc_redirects_import_csv( $file['tmp_name'], ! empty( $_POST['update_existing'] ), sanitize_file_name( $file['name'] ) );
		if ( is_wp_error( $result ) ) {
			tmc_redirects_notice( 'error', esc_html( $result->get_error_message() ) );
		} else {
			tmc_redirects_notice( $result['errors'] ? 'warning' : 'success', esc_html( sprintf( 'Import finished: %d added, %d updated, %d skipped (already present), %d with errors.', $result['created'], $result['updated'], $result['skipped'], count( $result['errors'] ) ) ) );
			$lines = array();
			foreach ( array_slice( $result['errors'], 0, 20, true ) as $line => $message ) {
				$lines[] = esc_html( "Line $line: $message" );
			}
			if ( $lines ) {
				tmc_redirects_notice( 'error', implode( '<br>', $lines ) . ( count( $result['errors'] ) > 20 ? '<br>…' : '' ) );
			}
			$lines = array();
			foreach ( array_slice( $result['warnings'], 0, 10, true ) as $line => $message ) {
				$lines[] = esc_html( "Line $line: $message" );
			}
			if ( $lines ) {
				tmc_redirects_notice( 'warning', implode( '<br>', $lines ) );
			}
		}
	}
	wp_safe_redirect( tmc_redirects_admin_url() . '#tmc-redirects-import' );
	exit;
}

add_action( 'admin_post_tmc_redirects_export', 'tmc_redirects_handle_export' );
function tmc_redirects_handle_export() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'You are not allowed to export redirects.', 403 );
	}
	check_admin_referer( 'tmc_redirects_export' );
	if ( function_exists( 'tmc_audit' ) ) {
		tmc_audit( 'redirects_exported', array( 'object_type' => 'redirect' ) );
	}
	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=redirects-' . sanitize_file_name( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) . '-' . gmdate( 'Ymd-His' ) . '.csv' );
	$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	tmc_redirects_write_csv( $out );
	fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	exit;
}

add_action( 'admin_post_tmc_redirects_settings', 'tmc_redirects_handle_settings' );
function tmc_redirects_handle_settings() {
	if ( ! current_user_can( 'manage_network_options' ) ) {
		wp_die( 'Only a Super Admin can change this setting.', 403 );
	}
	check_admin_referer( 'tmc_redirects_settings' );
	$enabled = ! empty( $_POST['regex'] );
	if ( $enabled !== tmc_redirects_regex_enabled() ) {
		$enabled ? update_option( 'tmc_redirects_regex', 1 ) : delete_option( 'tmc_redirects_regex' );
		tmc_redirects_flush();
		if ( function_exists( 'tmc_audit' ) ) {
			tmc_audit( 'redirects_regex_' . ( $enabled ? 'enabled' : 'disabled' ), array( 'object_type' => 'option', 'object_title' => 'tmc_redirects_regex' ) );
		}
	}
	tmc_redirects_notice( 'success', 'Settings saved.' );
	wp_safe_redirect( tmc_redirects_admin_url() );
	exit;
}
