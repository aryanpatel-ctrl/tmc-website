<?php
/**
 * Full-page cache for anonymous visitors — WordPress side (tender §4.7 peak load, §4.10 performance).
 *
 * The engine (mu-plugins/tmc-page-cache/engine.php, loaded as advanced-cache.php) serves anonymous
 * page views from Redis. This module:
 *   - marks responses that must never be stored: logged-in users, previews, the customizer, search,
 *     404s, password-protected content, and any page that created a nonce for an anonymous visitor
 *     (i.e. a form) — see also tmc_pc_response_bypass_reason();
 *   - purges when content changes: posts/pages/any type (publish, update, unpublish, trash, delete,
 *     meta, terms), menus, widgets, customizer/theme settings, selected options, comments, sites.
 *     A change purges the whole site it happened on — every language and every listing — plus the
 *     TMC umbrella site, which aggregates unit content. Copies published to other sites (network
 *     publishing) are saved in those sites, so they purge those sites too;
 *   - gives features a small API: tmc_page_cache_bypass( 'reason' ) for a page that must stay
 *     dynamic (or simply send nocache_headers()), tmc_page_cache_purge_blog() / _network();
 *   - adds `wp tmc-cache status|purge` and the network-admin purge button (see health.php).
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/tmc-page-cache/functions.php';

// Polylang takes the language from the URL (/hi/), so its cookie is not needed; left on, it would be
// set on every first visit and make those responses uncacheable. Must be defined before Polylang loads.
if ( ! defined( 'PLL_COOKIE' ) ) {
	define( 'PLL_COOKIE', false );
}

/* ================================================================ bypass */

/** Keep the current response out of the page cache. */
function tmc_page_cache_bypass( $reason = 'custom' ) {
	if ( empty( $GLOBALS['tmc_page_cache_bypass'] ) ) {
		$reason                            = preg_replace( '/[^a-z0-9-]/', '', strtolower( (string) $reason ) );
		$GLOBALS['tmc_page_cache_bypass'] = '' !== $reason ? $reason : 'custom';
	}
}

function tmc_page_cache_bypass_reason() {
	return (string) ( $GLOBALS['tmc_page_cache_bypass'] ?? '' );
}

/**
 * Nonce actions that do not make a page uncacheable: nothing on a public page relies on them.
 */
function tmc_page_cache_nonce_exempt_actions() {
	return (array) apply_filters( 'tmc_page_cache_nonce_exempt_actions', array( 'wp_rest' ) );
}

/*
 * WordPress core creates nonces while it sets up its script registry, on every page and whether or not
 * the scripts are printed (the inline configuration of wp-api-fetch, wp-api-request, user-profile …).
 * They are not forms, so nonces created during the "wp_default_scripts" action are ignored.
 */
add_action( 'wp_default_scripts', fn() => $GLOBALS['tmc_page_cache_in_registry'] = true, PHP_INT_MIN );
add_action( 'wp_default_scripts', fn() => $GLOBALS['tmc_page_cache_in_registry'] = false, PHP_INT_MAX );

// A nonce for a logged-out visitor means a form: a cached copy would hand everyone the same nonce.
add_filter(
	'nonce_user_logged_out',
	function ( $uid, $action = -1 ) {
		if ( empty( $GLOBALS['tmc_page_cache_in_registry'] ) && ! in_array( $action, tmc_page_cache_nonce_exempt_actions(), true ) ) {
			tmc_page_cache_bypass( 'nonce' );
			// Outside production, name the first nonce action that made the page uncacheable (diagnosis).
			if ( ! headers_sent() && 'production' !== wp_get_environment_type() && empty( $GLOBALS['tmc_page_cache_nonce_action'] ) ) {
				$GLOBALS['tmc_page_cache_nonce_action'] = substr( preg_replace( '/[^A-Za-z0-9_.:-]/', '', (string) $action ), 0, 64 );
				header( 'X-TMC-Cache-Nonce: ' . $GLOBALS['tmc_page_cache_nonce_action'] );
			}
		}
		return $uid;
	},
	10,
	2
);

add_action( 'template_redirect', 'tmc_page_cache_request_rules', PHP_INT_MAX );
function tmc_page_cache_request_rules() {
	$rules = array(
		'logged-in'  => is_user_logged_in(),
		'preview'    => is_preview(),
		'customizer' => is_customize_preview(),
		'search'     => is_search(),
		'404'        => is_404(),
		'feed'       => is_feed(),
		'trackback'  => is_trackback(),
		'robots'     => is_robots(),
		'password'   => is_singular() && post_password_required(),
	);
	foreach ( $rules as $reason => $applies ) {
		if ( $applies ) {
			tmc_page_cache_bypass( $reason );
			return;
		}
	}
}

/* ================================================================ purge API */

function tmc_page_cache_blog_host( $blog_id ) {
	if ( ! function_exists( 'get_site' ) ) {
		return ''; // single-site bootstrap of the one-time network install: nothing is cached yet
	}
	$site = get_site( (int) $blog_id );
	return $site ? tmc_pc_normalize_host( $site->domain ) : '';
}

/**
 * Purge one site (default: the current one) and the umbrella site.
 * The purge happens at once and again at the end of the request, so a page rendered while the
 * change was being saved cannot survive.
 */
function tmc_page_cache_purge_blog( $blog_id = 0 ) {
	if ( wp_installing() ) {
		return; // network or site being installed: no page of it can be cached yet
	}
	$blog_id = $blog_id ? (int) $blog_id : get_current_blog_id();
	foreach ( array_unique( array( $blog_id, (int) get_main_site_id() ) ) as $id ) {
		$host = tmc_page_cache_blog_host( $id );
		if ( '' !== $host ) {
			tmc_page_cache_purge_target( 'host:' . $host );
		}
	}
}

function tmc_page_cache_purge_network() {
	tmc_page_cache_purge_target( 'network' );
}

function tmc_page_cache_purge_target( $target ) {
	$ok = 'network' === $target ? tmc_pc_purge_network() : tmc_pc_purge_host( substr( $target, 5 ) );
	if ( ! isset( $GLOBALS['tmc_page_cache_purged'] ) ) {
		$GLOBALS['tmc_page_cache_purged'] = array();
		add_action( 'shutdown', 'tmc_page_cache_purge_again', 0 );
	}
	$GLOBALS['tmc_page_cache_purged'][ $target ] = true;
	return $ok;
}

/** Shutdown: purge every target touched during this request once more (after all writes are done). */
function tmc_page_cache_purge_again() {
	foreach ( array_keys( $GLOBALS['tmc_page_cache_purged'] ?? array() ) as $target ) {
		'network' === $target ? tmc_pc_purge_network() : tmc_pc_purge_host( substr( $target, 5 ) );
	}
	$GLOBALS['tmc_page_cache_purged'] = array();
}

/* ================================================================ purge triggers */

/**
 * Post types whose changes never alter a public page by themselves: caches and requests WordPress
 * writes on its own, sometimes while rendering a page for a visitor (oEmbed results). Purging on
 * them would empty the cache on ordinary page views. Customizer changes purge via customize_save_after.
 */
function tmc_page_cache_ignored_post_types() {
	return (array) apply_filters( 'tmc_page_cache_ignored_post_types', array( 'oembed_cache', 'customize_changeset', 'user_request', 'revision' ) );
}

/** A post change matters to visitors when the post is (or was) public, or is a menu item / media item. */
function tmc_page_cache_post_changed( $post ) {
	$post = get_post( $post );
	if ( ! $post || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) || in_array( $post->post_type, tmc_page_cache_ignored_post_types(), true ) ) {
		return;
	}
	if ( in_array( $post->post_status, array( 'publish', 'private' ), true ) || in_array( $post->post_type, array( 'nav_menu_item', 'attachment' ), true ) ) {
		tmc_page_cache_purge_blog();
	}
}

add_action(
	'save_post',
	function ( $post_id, $post ) {
		tmc_page_cache_post_changed( $post );
	},
	10,
	2
);

// Publish, unpublish, schedule → publish, trash, restore.
add_action(
	'transition_post_status',
	function ( $new_status, $old_status, $post ) {
		$public = array( 'publish', 'private' );
		if ( $post instanceof WP_Post && in_array( $post->post_type, tmc_page_cache_ignored_post_types(), true ) ) {
			return;
		}
		if ( $new_status !== $old_status && ( in_array( $new_status, $public, true ) || in_array( $old_status, $public, true ) ) ) {
			tmc_page_cache_purge_blog();
		}
	},
	10,
	3
);

add_action(
	'before_delete_post',
	function ( $post_id ) {
		tmc_page_cache_post_changed( $post_id );
	}
);
add_action( 'edit_attachment', 'tmc_page_cache_post_changed' );
add_action( 'delete_attachment', 'tmc_page_cache_post_changed' );

/** Meta of a public post (content fields, closing dates, the automatic-expiry flags …). */
function tmc_page_cache_meta_changed( $meta_ids, $object_id, $meta_key ) {
	// Editor bookkeeping, and oEmbed results WordPress caches in post meta while rendering a page.
	if ( preg_match( '/^_(edit_lock|edit_last|wp_old_slug|wp_old_date|encloseme|pingme|wp_trash_meta_|oembed_)/', (string) $meta_key ) ) {
		return;
	}
	if ( in_array( get_post_status( $object_id ), array( 'publish', 'private' ), true ) ) {
		tmc_page_cache_purge_blog();
	}
}
foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $tmc_hook ) {
	add_action( $tmc_hook, 'tmc_page_cache_meta_changed', 10, 3 );
}

add_action(
	'set_object_terms',
	function ( $object_id ) {
		if ( in_array( get_post_status( $object_id ), array( 'publish', 'private' ), true ) ) {
			tmc_page_cache_purge_blog();
		}
	}
);

// Categories, tags, Polylang language/string terms, menus as terms, and their meta.
$tmc_purge_blog = static function () {
	tmc_page_cache_purge_blog();
};
foreach ( array( 'created_term', 'edited_term', 'delete_term', 'added_term_meta', 'updated_term_meta', 'deleted_term_meta', 'wp_update_nav_menu', 'wp_delete_nav_menu', 'wp_update_nav_menu_item', 'customize_save_after', 'switch_theme', 'comment_post', 'edit_comment', 'wp_set_comment_status', 'deleted_comment' ) as $tmc_hook ) {
	add_action( $tmc_hook, $tmc_purge_blog );
}

$tmc_purge_network = static function () {
	tmc_page_cache_purge_network();
};
foreach ( array( 'wp_initialize_site', 'wp_update_site', 'wp_delete_site', 'activated_plugin', 'deactivated_plugin', 'upgrader_process_complete', 'profile_update', 'deleted_user' ) as $tmc_hook ) {
	add_action( $tmc_hook, $tmc_purge_network );
}
unset( $tmc_hook, $tmc_purge_blog, $tmc_purge_network );

/**
 * Site options that change what visitors see. An explicit list (extendable with the filter) rather
 * than a prefix, so an option written on every request can never keep emptying the cache.
 */
function tmc_page_cache_option_matters( $option ) {
	$options = array( 'blogname', 'blogdescription', 'siteurl', 'home', 'show_on_front', 'page_on_front', 'page_for_posts', 'posts_per_page', 'date_format', 'time_format', 'timezone_string', 'gmt_offset', 'start_of_week', 'WPLANG', 'permalink_structure', 'category_base', 'tag_base', 'sidebars_widgets', 'nav_menu_options', 'site_icon', 'stylesheet', 'template', 'blog_public', 'polylang', 'tmc_migrations' );
	$options = (array) apply_filters( 'tmc_page_cache_purge_options', $options );
	return in_array( $option, $options, true ) || 0 === strpos( $option, 'theme_mods_' ) || 0 === strpos( $option, 'widget_' );
}
foreach ( array( 'added_option', 'updated_option', 'deleted_option' ) as $tmc_hook ) {
	add_action(
		$tmc_hook,
		function ( $option ) {
			if ( tmc_page_cache_option_matters( (string) $option ) ) {
				tmc_page_cache_purge_blog();
			}
		}
	);
}

/** Network options that change what visitors of every site see. */
function tmc_page_cache_network_option_matters( $option ) {
	return in_array( $option, (array) apply_filters( 'tmc_page_cache_purge_network_options', array( 'site_name' ) ), true );
}
foreach ( array( 'add_site_option', 'update_site_option', 'delete_site_option' ) as $tmc_hook ) {
	add_action(
		$tmc_hook,
		function ( $option ) {
			if ( tmc_page_cache_network_option_matters( (string) $option ) ) {
				tmc_page_cache_purge_network();
			}
		}
	);
}
unset( $tmc_hook );

/* ================================================================ status, Site Health, WP-CLI */

/** Drop-in state for health checks: the stub must be ours and WP_CACHE on. */
function tmc_page_cache_installed() {
	$file = WP_CONTENT_DIR . '/advanced-cache.php';
	return defined( 'WP_CACHE' ) && WP_CACHE && is_readable( $file ) && false !== strpos( (string) file_get_contents( $file ), 'tmc-page-cache/engine.php' );
}

// Tools → Site Health recognises our header as a page cache.
add_filter(
	'site_status_page_cache_supported_cache_headers',
	function ( $headers ) {
		$headers[ strtolower( TMC_PC_HEADER ) ] = static function ( $value ) {
			return false !== stripos( (string) $value, 'hit' );
		};
		return $headers;
	}
);

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	/**
	 * TMC full-page cache.
	 */
	class TMC_Page_Cache_CLI {
		/**
		 * Show whether the page cache is installed and reachable.
		 *
		 * ## EXAMPLES
		 *
		 *     wp tmc-cache status
		 */
		public function status() {
			$ping = tmc_pc_cmd( array( 'PING' ) );
			WP_CLI::log( 'Drop-in installed: ' . ( tmc_page_cache_installed() ? 'yes' : 'no' ) );
			WP_CLI::log( 'Redis reachable:   ' . ( 'PONG' === $ping ? 'yes' : 'no' ) );
			WP_CLI::log( 'Keys in cache DB:  ' . (int) tmc_pc_cmd( array( 'DBSIZE' ) ) );
			WP_CLI::log( 'TTL (seconds):     ' . (int) tmc_pc_config()['ttl'] );
		}

		/**
		 * Purge the page cache of the current site (--url) or of every site.
		 *
		 * ## OPTIONS
		 *
		 * [--network]
		 * : Purge all sites of the network.
		 *
		 * ## EXAMPLES
		 *
		 *     wp tmc-cache purge --url=tmh.tmc.localhost
		 *     wp tmc-cache purge --network
		 *
		 * @param array $args       Positional arguments.
		 * @param array $assoc_args Flags.
		 */
		public function purge( $args, $assoc_args ) {
			$network = ! empty( $assoc_args['network'] );
			$ok      = $network ? tmc_pc_purge_network() : tmc_pc_purge_host( tmc_page_cache_blog_host( get_current_blog_id() ) );
			if ( ! $ok ) {
				WP_CLI::error( 'purge failed: Redis unreachable' );
			}
			tmc_audit( 'page_cache_purged', array( 'object_type' => 'page_cache', 'object_title' => $network ? 'network' : home_url( '/' ) ) );
			WP_CLI::success( $network ? 'page cache purged on every site' : 'page cache purged for ' . home_url( '/' ) );
		}
	}
	WP_CLI::add_command( 'tmc-cache', 'TMC_Page_Cache_CLI' );
}
