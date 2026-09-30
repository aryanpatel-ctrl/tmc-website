<?php
/**
 * Network publishing: centralized publishing of TMC-wide content (tender §4.6, R-4.6-2).
 *
 * On the TMC umbrella site, news, notices and events have a "Publish to unit websites" panel.
 * TMC central roles (Reviewer / Publisher and Site Administrator on the TMC site, Super Admins)
 * choose the unit websites; every selected site then holds a synced copy of the item and of its
 * translations (Polylang), which:
 *   - records its origin (site, post, URL) and points its canonical URL at the original;
 *   - is read-only on the unit website (edit, delete and publish are refused for everyone, including
 *     unit Site Administrators) — changes are made once, on the original;
 *   - follows the original: updates, scheduling, unpublishing (and trash) and permanent deletion
 *     propagate automatically; removing a site from the selection deletes that site's copy.
 * Every change is written to the tamper-evident audit log.
 *
 * Loops and repeated saves are harmless: only the origin site syndicates, copies never do, a
 * re-entrancy guard covers the whole run, and each copy stores a hash of what it was built from, so
 * saving an unchanged original writes nothing.
 */

defined( 'ABSPATH' ) || exit;

const TMC_SYND_TARGETS     = '_tmc_syndicate_targets'; // origin: unit site IDs selected by TMC
const TMC_SYND_COPIES      = '_tmc_syndicated_copies'; // origin: site ID => copy post ID
const TMC_SYND_ORIGIN_BLOG = '_tmc_origin_blog';       // copy: origin site ID
const TMC_SYND_ORIGIN_POST = '_tmc_origin_post';       // copy: origin post ID
const TMC_SYND_ORIGIN_URL  = '_tmc_origin_url';        // copy: permalink of the original (canonical)
const TMC_SYND_HASH        = '_tmc_sync_hash';         // copy: hash of the synced data

/** Content that TMC can publish to unit websites. */
function tmc_syndication_post_types() {
	return (array) apply_filters( 'tmc_syndication_post_types', array( 'post', 'tmc_event' ) );
}

function tmc_syndication_origin_site() {
	return (int) get_main_site_id();
}

function tmc_syndication_is_origin_site() {
	return is_multisite() && get_current_blog_id() === tmc_syndication_origin_site();
}

/**
 * Unit websites that can receive copies (every active site except the TMC site).
 *
 * @return array<int,string> site ID => name
 */
function tmc_syndication_sites() {
	$sites = array();
	foreach ( get_sites( array( 'number' => 100, 'archived' => 0, 'deleted' => 0, 'spam' => 0, 'orderby' => 'id' ) ) as $site ) {
		$id = (int) $site->blog_id;
		if ( tmc_syndication_origin_site() !== $id ) {
			$sites[ $id ] = (string) get_blog_option( $id, 'blogname' );
		}
	}
	return $sites;
}

/** Re-entrancy guard: true while copies are being written. */
function tmc_syndication_busy( $set = null ) {
	static $depth = 0;
	if ( true === $set ) {
		++$depth;
	} elseif ( false === $set ) {
		$depth = max( 0, $depth - 1 );
	}
	return $depth > 0;
}

function tmc_syndication_lang( $post_id ) {
	return function_exists( 'pll_get_post_language' ) ? (string) pll_get_post_language( $post_id ) : '';
}

/**
 * The post and its translations on the origin site.
 *
 * @return array<string,int> language slug ('' without Polylang) => post ID
 */
function tmc_syndication_group( $post_id ) {
	$post_id = (int) $post_id;
	$group   = function_exists( 'pll_get_post_translations' ) ? array_filter( array_map( 'intval', (array) pll_get_post_translations( $post_id ) ) ) : array();
	if ( ! in_array( $post_id, $group, true ) ) {
		$group[ tmc_syndication_lang( $post_id ) ] = $post_id;
	}
	return $group;
}

/**
 * Origin of a synced copy on the current site, or null for ordinary content.
 *
 * @return array{blog:int,post:int,url:string}|null
 */
function tmc_syndication_origin( $post_id ) {
	$blog = (int) get_post_meta( $post_id, TMC_SYND_ORIGIN_BLOG, true );
	if ( ! $blog || get_current_blog_id() === $blog ) {
		return null;
	}
	return array(
		'blog' => $blog,
		'post' => (int) get_post_meta( $post_id, TMC_SYND_ORIGIN_POST, true ),
		'url'  => (string) get_post_meta( $post_id, TMC_SYND_ORIGIN_URL, true ),
	);
}

/** Selected unit sites. A translation without its own selection uses its group's. */
function tmc_syndication_get_targets( $post_id ) {
	$ids = array_unique( array_merge( array( (int) $post_id ), array_values( tmc_syndication_group( $post_id ) ) ) );
	foreach ( $ids as $id ) {
		if ( metadata_exists( 'post', $id, TMC_SYND_TARGETS ) ) {
			return array_values( array_filter( array_map( 'intval', (array) get_post_meta( $id, TMC_SYND_TARGETS, true ) ) ) );
		}
	}
	return array();
}

/**
 * Save the selection for the post and all its translations. Callers check tmc_network_publish.
 *
 * @return int[] The stored selection.
 */
function tmc_syndication_set_targets( $post_id, array $blog_ids ) {
	$sites = tmc_syndication_sites();
	$new   = array_values( array_intersect( array_unique( array_map( 'intval', $blog_ids ) ), array_keys( $sites ) ) );
	sort( $new );
	$old = tmc_syndication_get_targets( $post_id );
	sort( $old );
	foreach ( tmc_syndication_group( $post_id ) as $member ) {
		update_post_meta( $member, TMC_SYND_TARGETS, $new );
	}
	if ( $new !== $old && function_exists( 'tmc_audit' ) ) {
		$names = fn( array $ids ) => array_values( array_map( fn( $id ) => $sites[ $id ] ?? "Site #$id", $ids ) );
		$post  = get_post( $post_id );
		tmc_audit(
			'network_publish_targets_changed',
			array(
				'object_type'  => $post ? $post->post_type : '',
				'object_id'    => (int) $post_id,
				'object_title' => $post ? $post->post_title : '',
				'details'      => array(
					'added'   => $names( array_diff( $new, $old ) ),
					'removed' => $names( array_diff( $old, $new ) ),
				),
			)
		);
	}
	return $new;
}

/** @return array<int,int> site ID => copy post ID */
function tmc_syndication_copies( $post_id ) {
	$copies = get_post_meta( $post_id, TMC_SYND_COPIES, true );
	$out    = array();
	foreach ( is_array( $copies ) ? $copies : array() as $blog => $copy ) {
		if ( (int) $blog > 0 && (int) $copy > 0 ) {
			$out[ (int) $blog ] = (int) $copy;
		}
	}
	return $out;
}

function tmc_syndication_store_copies( $post_id, array $copies ) {
	if ( $copies ) {
		update_post_meta( $post_id, TMC_SYND_COPIES, $copies );
	} else {
		delete_post_meta( $post_id, TMC_SYND_COPIES );
	}
}

/* ================================================================ permissions */

// TMC central roles: anyone who can publish on the TMC site (Reviewer / Publisher, Site
// Administrator); Super Admins everywhere. Unit-site roles never get it.
add_filter( 'user_has_cap', 'tmc_syndication_grant_cap', 10, 4 );
function tmc_syndication_grant_cap( $allcaps, $caps, $args, $user ) {
	if ( in_array( 'tmc_network_publish', $caps, true ) && tmc_syndication_is_origin_site() && ! empty( $allcaps['publish_posts'] ) ) {
		$allcaps['tmc_network_publish'] = true;
	}
	return $allcaps;
}

// Synced copies are read-only on unit websites (applies to every user; only the sync may write).
add_filter( 'map_meta_cap', 'tmc_syndication_protect_copies', 10, 4 );
function tmc_syndication_protect_copies( $caps, $cap, $user_id, $args ) {
	static $guarded = array( 'edit_post', 'delete_post', 'publish_post', 'edit_page', 'delete_page' );
	if ( empty( $args[0] ) || ! in_array( $cap, $guarded, true ) || tmc_syndication_busy() ) {
		return $caps;
	}
	$post = get_post( $args[0] );
	if ( $post && tmc_syndication_origin( $post->ID ) ) {
		return array( 'do_not_allow' );
	}
	return $caps;
}

/* ================================================================ sync */

add_action( 'wp_after_insert_post', 'tmc_syndication_on_save', 20, 2 );
function tmc_syndication_on_save( $post_id, $post ) {
	if ( tmc_syndication_busy() || ! tmc_syndication_is_origin_site() || ! $post instanceof WP_Post ) {
		return;
	}
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || 'auto-draft' === $post->post_status ) {
		return;
	}
	if ( in_array( $post->post_type, tmc_syndication_post_types(), true ) ) {
		tmc_syndication_sync( $post_id );
	}
}

/**
 * Bring every copy of the post and its translations in line with the originals.
 *
 * @return array<int,array<string,int>> site ID => [ language => copy ID ]
 */
function tmc_syndication_sync( $post_id ) {
	if ( tmc_syndication_busy() || ! tmc_syndication_is_origin_site() ) {
		return array();
	}
	$group   = tmc_syndication_group( $post_id );
	$targets = tmc_syndication_get_targets( $post_id );
	$known   = false;
	foreach ( $group as $member ) {
		$known = $known || tmc_syndication_copies( $member );
	}
	if ( ! $targets && ! $known ) {
		return array(); // never published to a unit site
	}

	tmc_syndication_busy( true );
	try {
		$sites  = tmc_syndication_sites();
		$result = array();

		// Sites that were deselected (or no longer exist) lose their copies.
		foreach ( $group as $member ) {
			$copies = tmc_syndication_copies( $member );
			foreach ( $copies as $blog => $copy ) {
				if ( ! in_array( $blog, $targets, true ) || ! isset( $sites[ $blog ] ) ) {
					if ( isset( $sites[ $blog ] ) ) {
						tmc_syndication_delete_copy( $blog, $copy, $member, 'site removed from selection' );
					}
					unset( $copies[ $blog ] );
				}
			}
			tmc_syndication_store_copies( $member, $copies );
		}

		foreach ( $targets as $blog ) {
			if ( ! isset( $sites[ $blog ] ) ) {
				continue;
			}
			$linked = array();
			foreach ( $group as $lang => $member ) {
				$copy = tmc_syndication_upsert( $member, $blog );
				if ( $copy ) {
					$linked[ $lang ] = $copy;
				}
			}
			tmc_syndication_link_translations( $blog, $linked );
			$result[ $blog ] = $linked;
		}
		return $result;
	} finally {
		tmc_syndication_busy( false );
	}
}

/** Everything a copy is built from (read on the origin site). */
function tmc_syndication_payload( WP_Post $post ) {
	$live = in_array( $post->post_status, array( 'publish', 'future' ), true );
	$meta = array();
	foreach ( ( function_exists( 'tmc_field_schema' ) ? ( tmc_field_schema()[ $post->post_type ] ?? array() ) : array() ) as $key => $field ) {
		if ( in_array( $field['type'], array( 'documents', 'departments' ), true ) ) {
			continue; // attachment / department IDs belong to the origin site
		}
		$meta[ '_' . $key ] = (string) get_post_meta( $post->ID, '_' . $key, true );
	}
	$categories = array();
	if ( is_object_in_taxonomy( $post->post_type, 'category' ) ) {
		$slugs      = wp_get_post_terms( $post->ID, 'category', array( 'fields' => 'slugs' ) );
		$categories = is_wp_error( $slugs ) ? array() : array_values( $slugs );
		sort( $categories );
	}
	return array(
		'type'       => $post->post_type,
		'status'     => $live ? $post->post_status : 'draft',
		'title'      => $post->post_title,
		'content'    => $post->post_content,
		'excerpt'    => $post->post_excerpt,
		'name'       => $post->post_name,
		'date'       => $post->post_date,
		'date_gmt'   => $post->post_date_gmt,
		'author'     => (int) $post->post_author,
		'lang'       => tmc_syndication_lang( $post->ID ),
		'categories' => $categories,
		'meta'       => $meta,
		'url'        => $live ? (string) get_permalink( $post ) : '',
	);
}

/**
 * Create, update or unpublish the copy of one origin post on one unit site.
 *
 * @return int Copy ID (0 when the original is not live and was never copied, or on failure).
 */
function tmc_syndication_upsert( $member, $blog ) {
	$source = get_post( $member );
	if ( ! $source ) {
		return 0;
	}
	$origin  = get_current_blog_id();
	$payload = tmc_syndication_payload( $source );
	$hash    = md5( (string) wp_json_encode( $payload ) );
	$copies  = tmc_syndication_copies( $member );

	switch_to_blog( $blog );
	try {
		$copy_id = tmc_syndication_find_copy( $origin, $member, $copies[ $blog ] ?? 0 );
		if ( ! $copy_id && 'draft' === $payload['status'] ) {
			$copy_id = 0; // not live and never copied: nothing to do
		} elseif ( $copy_id && get_post_meta( $copy_id, TMC_SYND_HASH, true ) === $hash ) {
			// Unchanged since the last sync (repeated save): write nothing.
		} else {
			$copy_id = tmc_syndication_write_copy( $origin, $member, $payload, $hash, $copy_id );
		}
	} finally {
		restore_current_blog();
	}

	if ( $copy_id ) {
		$copies[ $blog ] = $copy_id;
	} else {
		unset( $copies[ $blog ] );
	}
	tmc_syndication_store_copies( $member, $copies );
	return $copy_id;
}

/** On the unit site: the copy of $member, trusting the stored ID only if it really is that copy. */
function tmc_syndication_find_copy( $origin, $member, $known ) {
	$is_copy = fn( $id ) => $id && get_post( $id ) && (int) get_post_meta( $id, TMC_SYND_ORIGIN_BLOG, true ) === (int) $origin && (int) get_post_meta( $id, TMC_SYND_ORIGIN_POST, true ) === (int) $member;
	if ( $is_copy( (int) $known ) ) {
		return (int) $known;
	}
	$found = get_posts(
		array(
			'post_type'      => tmc_syndication_post_types(),
			'post_status'    => array( 'publish', 'future', 'draft', 'pending', 'private', 'trash' ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'lang'           => '',
			'meta_query'     => array(
				array( 'key' => TMC_SYND_ORIGIN_BLOG, 'value' => (int) $origin ),
				array( 'key' => TMC_SYND_ORIGIN_POST, 'value' => (int) $member ),
			),
		)
	);
	return $found ? (int) $found[0] : 0;
}

/** On the unit site: write the copy. Returns its ID, or 0 on failure (logged). */
function tmc_syndication_write_copy( $origin, $member, array $payload, $hash, $copy_id ) {
	$was_live = $copy_id && in_array( get_post_status( $copy_id ), array( 'publish', 'future' ), true );
	$postarr  = array(
		'post_type'      => $payload['type'],
		'post_status'    => $payload['status'],
		'post_title'     => $payload['title'],
		'post_content'   => $payload['content'],
		'post_excerpt'   => $payload['excerpt'],
		'post_name'      => $payload['name'],
		'post_date'      => $payload['date'],
		'post_date_gmt'  => $payload['date_gmt'],
		'edit_date'      => true, // keep the original's dates even when a copy moves between draft and live
		'post_author'    => $payload['author'],
		'comment_status' => 'closed',
		'ping_status'    => 'closed',
	);

	// Other save_post handlers (meta boxes, Polylang) must not read the origin editor's form for the copy.
	$form     = $_POST;    // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$request  = $_REQUEST; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$_POST    = array();
	$_REQUEST = array();
	try {
		if ( $copy_id ) {
			$postarr['ID'] = $copy_id;
			$result        = wp_update_post( wp_slash( $postarr ), true );
		} else {
			$result = wp_insert_post( wp_slash( $postarr ), true );
		}
		// An original published before its scheduled date ("publish now") keeps that date;
		// WordPress would schedule the copy instead, so publish it explicitly.
		if ( ! is_wp_error( $result ) && $result && 'publish' === $payload['status'] && 'future' === get_post_status( $result ) ) {
			wp_publish_post( $result );
		}
	} finally {
		$_POST    = $form;
		$_REQUEST = $request;
	}

	$log = function ( $action, $id, array $extra = array() ) use ( $origin, $member, $payload ) {
		if ( function_exists( 'tmc_audit' ) ) {
			tmc_audit(
				$action,
				array(
					'object_type'  => $payload['type'],
					'object_id'    => (int) $id,
					'object_title' => $payload['title'],
					'details'      => array(
						'origin_site' => (int) $origin,
						'origin_post' => (int) $member,
						'language'    => $payload['lang'],
						'status'      => $payload['status'],
					) + $extra,
				)
			);
		}
	};

	if ( is_wp_error( $result ) || ! $result ) {
		$log( 'network_copy_failed', $copy_id, array( 'error' => is_wp_error( $result ) ? $result->get_error_message() : 'unknown' ) );
		return 0;
	}
	$id = (int) $result;

	update_post_meta( $id, TMC_SYND_ORIGIN_BLOG, (int) $origin );
	update_post_meta( $id, TMC_SYND_ORIGIN_POST, (int) $member );
	update_post_meta( $id, TMC_SYND_ORIGIN_URL, esc_url_raw( $payload['url'] ) );
	foreach ( $payload['meta'] as $key => $value ) {
		if ( '' === $value ) {
			delete_post_meta( $id, $key );
		} else {
			update_post_meta( $id, $key, $value );
		}
	}
	if ( $payload['lang'] && function_exists( 'pll_set_post_language' ) ) {
		pll_set_post_language( $id, $payload['lang'] );
	}
	if ( is_object_in_taxonomy( $payload['type'], 'category' ) ) {
		$term_ids = array();
		foreach ( $payload['categories'] as $slug ) {
			$term = get_term_by( 'slug', $slug, 'category' );
			if ( $term ) {
				$term_ids[] = (int) $term->term_id; // same slug on every site (seed-site-structure.php)
			}
		}
		if ( $term_ids ) {
			wp_set_post_categories( $id, $term_ids );
		}
	}
	update_post_meta( $id, TMC_SYND_HASH, $hash );

	if ( ! $copy_id ) {
		$log( 'network_copy_created', $id );
	} elseif ( $was_live && 'draft' === $payload['status'] ) {
		$log( 'network_copy_unpublished', $id );
	} else {
		$log( 'network_copy_updated', $id );
	}
	return $id;
}

/** On each unit site, copies are linked as translations of each other, like their originals. */
function tmc_syndication_link_translations( $blog, array $linked ) {
	unset( $linked[''] );
	if ( count( $linked ) < 2 || ! function_exists( 'pll_save_post_translations' ) ) {
		return;
	}
	switch_to_blog( $blog );
	try {
		$current = array_map( 'intval', (array) pll_get_post_translations( reset( $linked ) ) );
		ksort( $current );
		ksort( $linked );
		if ( $current !== $linked ) {
			pll_save_post_translations( $linked );
		}
	} finally {
		restore_current_blog();
	}
}

/** Permanently delete one copy (only if it really is a copy of $member). */
function tmc_syndication_delete_copy( $blog, $copy_id, $member, $reason ) {
	$origin = get_current_blog_id();
	switch_to_blog( $blog );
	try {
		$copy = get_post( $copy_id );
		if ( $copy && (int) get_post_meta( $copy_id, TMC_SYND_ORIGIN_BLOG, true ) === $origin && (int) get_post_meta( $copy_id, TMC_SYND_ORIGIN_POST, true ) === (int) $member ) {
			wp_delete_post( $copy_id, true );
			if ( function_exists( 'tmc_audit' ) ) {
				tmc_audit(
					'network_copy_deleted',
					array(
						'object_type'  => $copy->post_type,
						'object_id'    => (int) $copy_id,
						'object_title' => $copy->post_title,
						'details'      => array( 'origin_site' => $origin, 'origin_post' => (int) $member, 'reason' => $reason ),
					)
				);
			}
		}
	} finally {
		restore_current_blog();
	}
}

// Deleting the original permanently deletes its copies (trash only unpublishes them, see sync).
add_action( 'before_delete_post', 'tmc_syndication_on_delete', 5, 2 );
function tmc_syndication_on_delete( $post_id, $post = null ) {
	if ( tmc_syndication_busy() || ! tmc_syndication_is_origin_site() || ! $post instanceof WP_Post || ! in_array( $post->post_type, tmc_syndication_post_types(), true ) ) {
		return;
	}
	$copies = tmc_syndication_copies( $post_id );
	if ( ! $copies ) {
		return;
	}
	$sites = tmc_syndication_sites();
	tmc_syndication_busy( true );
	try {
		foreach ( $copies as $blog => $copy ) {
			if ( isset( $sites[ $blog ] ) ) {
				tmc_syndication_delete_copy( $blog, $copy, $post_id, 'original deleted' );
			}
		}
	} finally {
		tmc_syndication_busy( false );
	}
}

// Search engines are pointed at the original.
add_filter( 'get_canonical_url', 'tmc_syndication_canonical', 10, 2 );
function tmc_syndication_canonical( $url, $post ) {
	$origin = $post ? tmc_syndication_origin( $post->ID ) : null;
	return ( $origin && $origin['url'] ) ? $origin['url'] : $url;
}

/* ================================================================ editor UI (origin site) */

add_action( 'add_meta_boxes', 'tmc_syndication_register_box', 10, 2 );
function tmc_syndication_register_box( $post_type, $post ) {
	if ( tmc_syndication_is_origin_site() && in_array( $post_type, tmc_syndication_post_types(), true ) ) {
		add_meta_box( 'tmc_syndication', 'Publish to unit websites', 'tmc_syndication_box', $post_type, 'side', 'default' );
	}
}

function tmc_syndication_box( WP_Post $post ) {
	$sites   = tmc_syndication_sites();
	$targets = tmc_syndication_get_targets( $post->ID );
	$copies  = tmc_syndication_copies( $post->ID );
	$can     = current_user_can( 'tmc_network_publish' );

	wp_nonce_field( 'tmc_syndication', 'tmc_syndication_nonce' );
	echo '<fieldset><legend class="screen-reader-text">Publish to unit websites</legend>';
	foreach ( $sites as $id => $name ) {
		$state = '';
		if ( isset( $copies[ $id ] ) ) {
			switch_to_blog( $id );
			$status = (string) get_post_status( $copies[ $id ] );
			restore_current_blog();
			$state = in_array( $status, array( 'publish', 'future' ), true ) ? ' <span class="description">(synced)</span>' : ' <span class="description">(copy unpublished)</span>';
		}
		printf(
			'<label style="display:block;margin:0 0 .4em"><input type="checkbox" name="tmc_syndication_targets[]" value="%d"%s%s> %s%s</label>',
			(int) $id,
			checked( in_array( $id, $targets, true ), true, false ),
			disabled( $can, false, false ),
			esc_html( $name ),
			$state // phpcs:ignore WordPress.Security.EscapeOutput -- static markup
		);
	}
	echo '</fieldset>';
	if ( $can ) {
		echo '<p class="description">Selected websites show a read-only copy of this item and its translations. Updates, unpublishing and deletion here are applied to the copies automatically; clearing a website deletes its copy.</p>';
	} else {
		echo '<p class="description">Only TMC Reviewer / Publishers and Site Administrators can change where this item is published.</p>';
	}
	echo '<input type="hidden" name="tmc_syndication_present" value="1">';
}

// Priority 5: the selection is stored before wp_after_insert_post runs the sync for the same save.
add_action( 'save_post', 'tmc_syndication_save_box', 5, 2 );
function tmc_syndication_save_box( $post_id, $post ) {
	if ( ! isset( $_POST['tmc_syndication_present'], $_POST['tmc_syndication_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['tmc_syndication_nonce'] ), 'tmc_syndication' ) ) {
		return;
	}
	if ( tmc_syndication_busy() || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! tmc_syndication_is_origin_site() ) {
		return;
	}
	if ( ! in_array( $post->post_type, tmc_syndication_post_types(), true ) || ! current_user_can( 'tmc_network_publish' ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	$ids = isset( $_POST['tmc_syndication_targets'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['tmc_syndication_targets'] ) ) : array();
	tmc_syndication_set_targets( $post_id, $ids );
}

/* ================================================================ unit sites: read-only copies */

add_filter( 'display_post_states', 'tmc_syndication_post_states', 10, 2 );
function tmc_syndication_post_states( $states, $post ) {
	$origin = tmc_syndication_origin( $post->ID );
	if ( $origin ) {
		$states['tmc_synced'] = sprintf( 'Synced from %s (read-only)', get_blog_option( $origin['blog'], 'blogname' ) );
	} elseif ( tmc_syndication_is_origin_site() ) {
		$count = count( tmc_syndication_copies( $post->ID ) );
		if ( $count ) {
			$states['tmc_syndicated'] = sprintf( 'Published to %d unit website%s', $count, 1 === $count ? '' : 's' );
		}
	}
	return $states;
}

add_filter( 'post_row_actions', 'tmc_syndication_row_actions', 10, 2 );
function tmc_syndication_row_actions( $actions, $post ) {
	$origin = tmc_syndication_origin( $post->ID );
	if ( $origin && $origin['url'] ) {
		$actions['tmc_origin'] = sprintf( '<a href="%s">View original</a>', esc_url( $origin['url'] ) );
	}
	return $actions;
}

// Opening a copy in the editor explains why it cannot be edited (instead of a bare permission error).
add_action( 'load-post.php', 'tmc_syndication_readonly_screen' );
function tmc_syndication_readonly_screen() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen selection
	$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
	$action  = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';
	// phpcs:enable
	$origin = ( $post_id && 'edit' === $action ) ? tmc_syndication_origin( $post_id ) : null;
	if ( ! $origin ) {
		return;
	}
	$links = array( sprintf( '<a href="%s">View this copy</a>', esc_url( get_permalink( $post_id ) ) ) );
	if ( $origin['url'] ) {
		$links[] = sprintf( '<a href="%s">View the original</a>', esc_url( $origin['url'] ) );
	}
	wp_die(
		sprintf(
			'<h1>Read-only copy</h1><p>&ldquo;%s&rdquo; is published centrally by %s and is read-only on this website. Changes, unpublishing and deletion are made on the original and appear here automatically.</p><p>%s</p>',
			esc_html( get_the_title( $post_id ) ),
			esc_html( get_blog_option( $origin['blog'], 'blogname' ) ),
			implode( ' · ', $links ) // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above
		),
		'Read-only copy',
		array( 'response' => 403, 'back_link' => true )
	);
}
