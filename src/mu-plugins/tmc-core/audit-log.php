<?php
/**
 * Tamper-evident audit log (tender §4.8).
 *
 * One network-wide table records who did what, where and when. Every entry stores an
 * HMAC-SHA256 over its own fields plus the previous entry's hash, so editing, deleting or
 * re-ordering any row breaks the chain from that row onwards. The HMAC key (TMC_AUDIT_KEY)
 * lives in the environment, not the database, so someone with database access alone cannot
 * rebuild a valid chain. Web requests also copy each entry to the container log
 * (`docker logs tmc-wp`) as an independent second record.
 *
 * Super Admins get Network Admin → Audit Log: filter, verify integrity, export CSV.
 */

defined( 'ABSPATH' ) || exit;

const TMC_AUDIT_DB_VERSION = 1;
const TMC_AUDIT_GENESIS    = '0000000000000000000000000000000000000000000000000000000000000000';

function tmc_audit_table() {
	global $wpdb;
	return $wpdb->base_prefix . 'tmc_audit_log';
}

function tmc_audit_key() {
	$key = getenv( 'TMC_AUDIT_KEY' );
	return $key ? $key : AUTH_KEY;
}

function tmc_audit_install() {
	if ( (int) get_site_option( 'tmc_audit_db_version' ) === TMC_AUDIT_DB_VERSION ) {
		return;
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$table = tmc_audit_table();
	dbDelta(
		"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			blog_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_login varchar(60) NOT NULL DEFAULT '',
			ip varchar(45) NOT NULL DEFAULT '',
			action varchar(64) NOT NULL,
			object_type varchar(32) NOT NULL DEFAULT '',
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			object_title varchar(255) NOT NULL DEFAULT '',
			details text NOT NULL,
			prev_hash char(64) NOT NULL,
			hash char(64) NOT NULL,
			PRIMARY KEY  (id),
			KEY blog_created (blog_id,created_at),
			KEY action (action),
			KEY user_login (user_login)
		) {$wpdb->get_charset_collate()};"
	);
	update_site_option( 'tmc_audit_db_version', TMC_AUDIT_DB_VERSION );
}
add_action( 'init', 'tmc_audit_install', 0 );

/** Fields covered by the hash, in a fixed order. */
function tmc_audit_hash( array $row, $prev_hash ) {
	$fields = array( 'created_at', 'blog_id', 'user_id', 'user_login', 'ip', 'action', 'object_type', 'object_id', 'object_title', 'details' );
	$parts  = array( $prev_hash );
	foreach ( $fields as $field ) {
		$parts[] = (string) $row[ $field ];
	}
	return hash_hmac( 'sha256', implode( "\x1f", $parts ), tmc_audit_key() );
}

function tmc_audit_clean( $value, $max ) {
	$value = wp_check_invalid_utf8( (string) $value, true );
	return mb_substr( $value, 0, $max );
}

/**
 * Record an event.
 *
 * @param string $action Event name, e.g. "content_published".
 * @param array  $args   object_type, object_id, object_title, details (array), user_id, user_login.
 */
function tmc_audit( $action, array $args = array() ) {
	global $wpdb;
	tmc_audit_install();

	$user    = wp_get_current_user();
	$is_cli  = defined( 'WP_CLI' ) && WP_CLI;
	$details = isset( $args['details'] ) ? wp_json_encode( $args['details'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : '';
	if ( strlen( $details ) > 60000 ) {
		$details = wp_json_encode( array( 'truncated' => true ) );
	}

	$row = array(
		'created_at'   => gmdate( 'Y-m-d H:i:s' ),
		'blog_id'      => (string) get_current_blog_id(),
		'user_id'      => (string) (int) ( $args['user_id'] ?? $user->ID ),
		'user_login'   => tmc_audit_clean( $args['user_login'] ?? ( $user->ID ? $user->user_login : ( $is_cli ? 'wp-cli' : '' ) ), 60 ),
		'ip'           => $is_cli ? 'cli' : tmc_audit_clean( $_SERVER['REMOTE_ADDR'] ?? '', 45 ), // mod_remoteip gives the real client IP
		'action'       => tmc_audit_clean( $action, 64 ),
		'object_type'  => tmc_audit_clean( $args['object_type'] ?? '', 32 ),
		'object_id'    => (string) (int) ( $args['object_id'] ?? 0 ),
		'object_title' => tmc_audit_clean( wp_strip_all_tags( (string) ( $args['object_title'] ?? '' ) ), 255 ),
		'details'      => (string) $details,
	);

	$table = tmc_audit_table();
	$wpdb->query( "SELECT GET_LOCK('tmc_audit_chain', 5)" );
	$prev              = $wpdb->get_var( "SELECT hash FROM {$table} ORDER BY id DESC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$prev              = $prev ? $prev : TMC_AUDIT_GENESIS;
	$row['prev_hash']  = $prev;
	$row['hash']       = tmc_audit_hash( $row, $prev );
	$wpdb->insert( $table, $row );
	$id = (int) $wpdb->insert_id;
	$wpdb->query( "SELECT RELEASE_LOCK('tmc_audit_chain')" );

	if ( ! $is_cli ) {
		error_log( 'TMC-AUDIT ' . wp_json_encode( array( 'id' => $id ) + $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
	}
}

/**
 * Walk the whole chain and recompute every hash.
 *
 * @return array{ok:bool,count:int,broken_at?:int,last_hash?:string}
 */
function tmc_audit_verify() {
	global $wpdb;
	$table   = tmc_audit_table();
	$prev    = TMC_AUDIT_GENESIS;
	$count   = 0;
	$last_id = 0;
	do {
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT 1000", $last_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $rows as $row ) {
			if ( ! hash_equals( $prev, $row['prev_hash'] ) || ! hash_equals( tmc_audit_hash( $row, $prev ), $row['hash'] ) ) {
				return array( 'ok' => false, 'count' => $count, 'broken_at' => (int) $row['id'] );
			}
			$prev    = $row['hash'];
			$last_id = (int) $row['id'];
			++$count;
		}
	} while ( count( $rows ) === 1000 );

	return array( 'ok' => true, 'count' => $count, 'last_hash' => $prev );
}

/* ================================================================ events */

// ---- authentication
add_action(
	'wp_login',
	function ( $login, $user ) {
		tmc_audit( 'login', array( 'user_id' => $user->ID, 'user_login' => $login, 'object_type' => 'user', 'object_id' => $user->ID, 'object_title' => $login ) );
	},
	10,
	2
);
add_action(
	'wp_login_failed',
	function ( $login, $error = null ) {
		tmc_audit(
			'login_failed',
			array(
				'user_id'      => 0,
				'user_login'   => '',
				'object_type'  => 'user',
				'object_title' => $login,
				'details'      => array( 'reason' => $error instanceof WP_Error ? $error->get_error_code() : '' ),
			)
		);
	},
	10,
	2
);
add_action(
	'wp_logout',
	function ( $user_id ) {
		$user = get_userdata( $user_id );
		tmc_audit( 'logout', array( 'user_id' => $user_id, 'user_login' => $user ? $user->user_login : '', 'object_type' => 'user', 'object_id' => $user_id ) );
	}
);

// ---- content
function tmc_audit_tracks_post( WP_Post $post ) {
	return ! in_array( $post->post_type, array( 'revision', 'oembed_cache', 'customize_changeset', 'user_request' ), true );
}

function tmc_audit_post_args( WP_Post $post, array $details = array() ) {
	$lang = function_exists( 'pll_get_post_language' ) ? pll_get_post_language( $post->ID ) : '';
	if ( $lang ) {
		$details['language'] = $lang;
	}
	return array(
		'object_type'  => $post->post_type,
		'object_id'    => $post->ID,
		'object_title' => $post->post_title,
		'details'      => $details,
	);
}

add_action(
	'transition_post_status',
	function ( $new_status, $old_status, $post ) {
		if ( $new_status === $old_status || 'auto-draft' === $new_status || ! tmc_audit_tracks_post( $post ) ) {
			return;
		}
		if ( 'trash' === $old_status ) {
			$action = 'content_restored';
		} elseif ( in_array( $old_status, array( 'new', 'auto-draft' ), true ) && 'draft' === $new_status ) {
			$action = 'content_created';
		} else {
			// Also covers new content created straight into pending / publish / future.
			$map    = array(
				'publish' => 'content_published',
				'pending' => 'content_submitted_for_review',
				'future'  => 'content_scheduled',
				'trash'   => 'content_trashed',
				'private' => 'content_made_private',
				'draft'   => 'pending' === $old_status ? 'content_returned_for_changes' : ( 'publish' === $old_status ? 'content_unpublished' : 'content_status_changed' ),
			);
			$action = $map[ $new_status ] ?? 'content_status_changed';
		}
		tmc_audit( $action, tmc_audit_post_args( $post, array( 'from' => $old_status, 'to' => $new_status ) ) );
	},
	20,
	3
);

add_action(
	'post_updated',
	function ( $post_id, $after, $before ) {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! tmc_audit_tracks_post( $after ) ) {
			return;
		}
		// Status changes are logged above; here only edits to content that is already live or in review.
		if ( $after->post_status !== $before->post_status || ! in_array( $after->post_status, array( 'publish', 'pending', 'future', 'private' ), true ) ) {
			return;
		}
		$changed = array();
		foreach ( array( 'post_title', 'post_content', 'post_excerpt', 'post_name', 'post_parent', 'menu_order', 'post_author', 'post_password' ) as $field ) {
			if ( $after->$field !== $before->$field ) {
				$changed[] = $field;
			}
		}
		if ( $changed ) {
			tmc_audit( 'content_updated', tmc_audit_post_args( $after, array( 'changed' => $changed, 'status' => $after->post_status ) ) );
		}
	},
	10,
	3
);

add_action(
	'before_delete_post',
	function ( $post_id, $post ) {
		if ( $post && tmc_audit_tracks_post( $post ) && 'auto-draft' !== $post->post_status ) {
			tmc_audit( 'content_deleted_permanently', tmc_audit_post_args( $post ) );
		}
	},
	10,
	2
);

add_action(
	'add_attachment',
	function ( $id ) {
		tmc_audit( 'media_uploaded', array( 'object_type' => 'attachment', 'object_id' => $id, 'object_title' => get_the_title( $id ), 'details' => array( 'file' => basename( (string) get_attached_file( $id ) ), 'mime' => get_post_mime_type( $id ) ) ) );
	}
);
add_action(
	'delete_attachment',
	function ( $id ) {
		tmc_audit( 'media_deleted', array( 'object_type' => 'attachment', 'object_id' => $id, 'object_title' => get_the_title( $id ) ) );
	}
);

// ---- users and permissions
function tmc_audit_user_args( $user_id, array $details = array() ) {
	$user = get_userdata( $user_id );
	return array( 'object_type' => 'user', 'object_id' => $user_id, 'object_title' => $user ? $user->user_login : '', 'details' => $details );
}

add_action( 'user_register', fn( $user_id ) => tmc_audit( 'user_created', tmc_audit_user_args( $user_id ) ) );
add_action( 'delete_user', fn( $user_id ) => tmc_audit( 'user_deleted', tmc_audit_user_args( $user_id ) ) );
add_action( 'wpmu_delete_user', fn( $user_id ) => tmc_audit( 'user_deleted_from_network', tmc_audit_user_args( $user_id ) ) );
add_action( 'set_user_role', fn( $user_id, $role, $old_roles ) => tmc_audit( 'user_role_changed', tmc_audit_user_args( $user_id, array( 'from' => implode( ',', (array) $old_roles ), 'to' => $role ) ) ), 10, 3 );
add_action( 'add_user_to_blog', fn( $user_id, $role, $blog_id ) => tmc_audit( 'user_added_to_site', tmc_audit_user_args( $user_id, array( 'site' => $blog_id, 'role' => $role ) ) ), 10, 3 );
add_action( 'remove_user_from_blog', fn( $user_id, $blog_id ) => tmc_audit( 'user_removed_from_site', tmc_audit_user_args( $user_id, array( 'site' => $blog_id ) ) ), 10, 2 );
add_action( 'granted_super_admin', fn( $user_id ) => tmc_audit( 'super_admin_granted', tmc_audit_user_args( $user_id ) ) );
add_action( 'revoked_super_admin', fn( $user_id ) => tmc_audit( 'super_admin_revoked', tmc_audit_user_args( $user_id ) ) );
add_action( 'after_password_reset', fn( $user ) => tmc_audit( 'password_reset', tmc_audit_user_args( $user->ID ) ) );
add_action(
	'profile_update',
	function ( $user_id, $old ) {
		$new = get_userdata( $user_id );
		if ( $new && $old && $new->user_pass !== $old->user_pass ) {
			tmc_audit( 'password_changed', tmc_audit_user_args( $user_id ) );
		}
		if ( $new && $old && $new->user_email !== $old->user_email ) {
			tmc_audit( 'user_email_changed', tmc_audit_user_args( $user_id ) );
		}
	},
	10,
	2
);

// ---- system
add_action( 'activated_plugin', fn( $plugin, $network ) => tmc_audit( 'plugin_activated', array( 'object_type' => 'plugin', 'object_title' => $plugin, 'details' => array( 'network_wide' => (bool) $network ) ) ), 10, 2 );
add_action( 'deactivated_plugin', fn( $plugin, $network ) => tmc_audit( 'plugin_deactivated', array( 'object_type' => 'plugin', 'object_title' => $plugin, 'details' => array( 'network_wide' => (bool) $network ) ) ), 10, 2 );
add_action( 'switch_theme', fn( $name ) => tmc_audit( 'theme_switched', array( 'object_type' => 'theme', 'object_title' => $name ) ) );
add_action( 'wp_initialize_site', fn( $site ) => tmc_audit( 'site_created', array( 'object_type' => 'site', 'object_id' => $site->blog_id, 'object_title' => $site->domain ) ), 100 );
add_action( 'wp_delete_site', fn( $site ) => tmc_audit( 'site_deleted', array( 'object_type' => 'site', 'object_id' => $site->blog_id, 'object_title' => $site->domain ) ) );

const TMC_AUDIT_WATCHED_OPTIONS = array( 'blogname', 'blogdescription', 'siteurl', 'home', 'admin_email', 'users_can_register', 'default_role', 'blog_public', 'WPLANG', 'permalink_structure' );
add_action(
	'updated_option',
	function ( $option, $old, $new ) {
		if ( in_array( $option, TMC_AUDIT_WATCHED_OPTIONS, true ) ) {
			tmc_audit( 'setting_changed', array( 'object_type' => 'option', 'object_title' => $option, 'details' => array( 'from' => $old, 'to' => $new ) ) );
		}
	},
	10,
	3
);

const TMC_AUDIT_WATCHED_NETWORK_OPTIONS = array( 'site_name', 'admin_email', 'registration', 'add_new_users', 'upload_filetypes', 'fileupload_maxk', 'site_admins' );
add_action(
	'update_site_option',
	function ( $option, $new, $old ) {
		if ( in_array( $option, TMC_AUDIT_WATCHED_NETWORK_OPTIONS, true ) ) {
			tmc_audit( 'network_setting_changed', array( 'object_type' => 'network_option', 'object_title' => $option, 'details' => array( 'from' => $old, 'to' => $new ) ) );
		}
	},
	10,
	3
);

/* ================================================================ network admin screen */

add_action(
	'network_admin_menu',
	function () {
		add_menu_page( 'Audit Log', 'Audit Log', 'manage_network', 'tmc-audit-log', 'tmc_audit_page', 'dashicons-shield-alt', 4 );
	}
);

/** Build WHERE clause + args from the current filters. */
function tmc_audit_filters() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters
	$filters = array(
		'site'  => absint( $_GET['site'] ?? 0 ),
		'event' => sanitize_key( $_GET['event'] ?? '' ),
		'user'  => sanitize_user( wp_unslash( $_GET['user'] ?? '' ) ),
	);
	// phpcs:enable
	$where = array( '1=1' );
	$args  = array();
	if ( $filters['site'] ) {
		$where[] = 'blog_id = %d';
		$args[]  = $filters['site'];
	}
	if ( $filters['event'] ) {
		$where[] = 'action = %s';
		$args[]  = $filters['event'];
	}
	if ( '' !== $filters['user'] ) {
		$where[] = 'user_login = %s';
		$args[]  = $filters['user'];
	}
	return array( $filters, implode( ' AND ', $where ), $args );
}

function tmc_audit_site_name( $blog_id ) {
	static $names = array();
	if ( ! isset( $names[ $blog_id ] ) ) {
		$site               = get_site( $blog_id );
		$names[ $blog_id ] = $site ? get_blog_option( $blog_id, 'blogname' ) : "Site #$blog_id";
	}
	return $names[ $blog_id ];
}

function tmc_audit_local_time( $utc ) {
	return wp_date( 'd/m/Y H:i:s', strtotime( $utc . ' UTC' ) );
}

function tmc_audit_details_text( $json ) {
	$data = json_decode( (string) $json, true );
	if ( ! is_array( $data ) ) {
		return '';
	}
	$out = array();
	foreach ( $data as $key => $value ) {
		$out[] = $key . ': ' . ( is_scalar( $value ) || null === $value ? var_export( $value, true ) : wp_json_encode( $value ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
	}
	return implode( ' · ', $out );
}

function tmc_audit_page() {
	global $wpdb;
	$table  = tmc_audit_table();
	$result = null;

	if ( isset( $_POST['tmc_audit_verify'] ) && check_admin_referer( 'tmc_audit_verify' ) ) {
		$result = tmc_audit_verify();
		tmc_audit( 'audit_log_verified', array( 'object_type' => 'audit_log', 'details' => $result ) );
	}

	list( $filters, $where, $args ) = tmc_audit_filters();
	$per_page = 50;
	$paged    = max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders
	$total = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $args ) ) : $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) );
	$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $args, array( $per_page, ( $paged - 1 ) * $per_page ) ) ) );
	$events = $wpdb->get_col( "SELECT DISTINCT action FROM {$table} ORDER BY action" );
	// phpcs:enable

	echo '<div class="wrap"><h1 class="wp-heading-inline">Audit Log</h1>';
	printf(
		'<a class="page-title-action" href="%s">Export CSV</a><hr class="wp-header-end">',
		esc_url( wp_nonce_url( add_query_arg( array_filter( $filters ) + array( 'action' => 'tmc_audit_csv' ), admin_url( 'admin-post.php' ) ), 'tmc_audit_csv' ) )
	);
	echo '<p>Every administrative action on all TMC websites. Entries are chained with HMAC-SHA256: changing or removing any record is detected by <strong>Verify integrity</strong>.</p>';

	if ( $result ) {
		if ( $result['ok'] ) {
			printf( '<div class="notice notice-success"><p><strong>Integrity verified.</strong> All %s entries are intact. Latest hash: <code>%s</code></p></div>', esc_html( number_format_i18n( $result['count'] ) ), esc_html( $result['last_hash'] ) );
		} else {
			printf( '<div class="notice notice-error"><p><strong>Tampering detected at entry #%d.</strong> %s entries before it are intact; the chain is broken from this entry onwards.</p></div>', (int) $result['broken_at'], esc_html( number_format_i18n( $result['count'] ) ) );
		}
	}

	echo '<form method="post" style="margin:12px 0">';
	wp_nonce_field( 'tmc_audit_verify' );
	submit_button( 'Verify integrity', 'primary', 'tmc_audit_verify', false );
	echo '</form>';

	// Filters.
	echo '<form method="get" style="margin:12px 0"><input type="hidden" name="page" value="tmc-audit-log">';
	echo '<select name="site"><option value="0">All sites</option>';
	foreach ( get_sites( array( 'number' => 100 ) ) as $site ) {
		printf( '<option value="%d"%s>%s</option>', (int) $site->blog_id, selected( $filters['site'], (int) $site->blog_id, false ), esc_html( tmc_audit_site_name( (int) $site->blog_id ) ) );
	}
	echo '</select> <select name="event"><option value="">All events</option>';
	foreach ( $events as $event ) {
		printf( '<option value="%s"%s>%s</option>', esc_attr( $event ), selected( $filters['event'], $event, false ), esc_html( $event ) );
	}
	printf( '</select> <input type="search" name="user" placeholder="Username" value="%s"> ', esc_attr( $filters['user'] ) );
	submit_button( 'Filter', 'secondary', '', false );
	echo '</form>';

	echo '<table class="widefat striped"><thead><tr><th>#</th><th>Time (IST)</th><th>Site</th><th>User</th><th>IP</th><th>Event</th><th>Object</th><th>Details</th><th>Hash</th></tr></thead><tbody>';
	if ( ! $rows ) {
		echo '<tr><td colspan="9">No entries.</td></tr>';
	}
	foreach ( $rows as $row ) {
		printf(
			'<tr><td>%d</td><td style="white-space:nowrap">%s</td><td>%s</td><td>%s</td><td>%s</td><td><code>%s</code></td><td>%s</td><td style="max-width:340px;word-break:break-word">%s</td><td><code title="%s">%s…</code></td></tr>',
			(int) $row->id,
			esc_html( tmc_audit_local_time( $row->created_at ) ),
			esc_html( tmc_audit_site_name( (int) $row->blog_id ) ),
			esc_html( $row->user_login ? $row->user_login : '—' ),
			esc_html( $row->ip ),
			esc_html( $row->action ),
			esc_html( trim( $row->object_type . ' ' . ( $row->object_id ? '#' . $row->object_id : '' ) . ' ' . $row->object_title ) ),
			esc_html( tmc_audit_details_text( $row->details ) ),
			esc_attr( $row->hash ),
			esc_html( substr( $row->hash, 0, 10 ) )
		);
	}
	echo '</tbody></table>';

	$pages = (int) ceil( $total / $per_page );
	if ( $pages > 1 ) {
		echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => $pages ) ) ) . '</div></div>';
	}
	printf( '<p>%s entries.</p></div>', esc_html( number_format_i18n( $total ) ) );
}

/* ================================================================ CSV export */

add_action( 'admin_post_tmc_audit_csv', 'tmc_audit_csv' );
function tmc_audit_csv() {
	if ( ! current_user_can( 'manage_network' ) ) {
		wp_die( 'You are not allowed to export the audit log.', 403 );
	}
	check_admin_referer( 'tmc_audit_csv' );
	global $wpdb;
	$table                          = tmc_audit_table();
	list( $filters, $where, $args ) = tmc_audit_filters();

	tmc_audit( 'audit_log_exported', array( 'object_type' => 'audit_log', 'details' => array_filter( $filters ) ) );

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=tmc-audit-log-' . gmdate( 'Ymd-His' ) . '.csv' );

	// Neutralise spreadsheet formulas (CSV injection).
	$safe = static fn( $v ) => preg_match( '/^[=+\-@\t\r]/', (string) $v ) ? "'" . $v : $v;

	$out = fopen( 'php://output', 'w' );
	fwrite( $out, "\xEF\xBB\xBF" ); // UTF-8 BOM so Excel shows Hindi correctly.
	fputcsv( $out, array( 'id', 'time_ist', 'time_utc', 'site', 'user', 'ip', 'event', 'object_type', 'object_id', 'object_title', 'details', 'prev_hash', 'hash' ) );
	$last_id = PHP_INT_MAX;
	do {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} AND id < %d ORDER BY id DESC LIMIT 1000", array_merge( $args, array( $last_id ) ) ) );
		foreach ( $rows as $row ) {
			fputcsv(
				$out,
				array_map(
					$safe,
					array( $row->id, tmc_audit_local_time( $row->created_at ), $row->created_at, tmc_audit_site_name( (int) $row->blog_id ), $row->user_login, $row->ip, $row->action, $row->object_type, $row->object_id, $row->object_title, $row->details, $row->prev_hash, $row->hash )
				)
			);
			$last_id = (int) $row->id;
		}
	} while ( count( $rows ) === 1000 );
	fclose( $out );
	exit;
}
