<?php
/**
 * Site search (tender §4.12): full text across all published content and documents.
 *
 * Each site keeps a search index table ({prefix}tmc_search_index) with one row per public item:
 * pages, posts, every public content type (tenders, events, careers, departments, doctors, …) and
 * documents (PDF/Office attachments, including the text extracted by document-text.php). Rows hold
 * clean text (no markup, block comments or shortcodes), the Polylang language ('' for documents,
 * which are shared by all languages) and the date. Only published, non-password-protected content
 * is indexed; documents only when unattached or attached to such content. So the index — and
 * everything built on it (results page, suggestions) — never contains private or draft data.
 *
 * Kept current by hooks (save, status change, language change, delete, attach/detach, field
 * changes); reconciled daily by cron and rebuildable with `wp tmc-search rebuild`.
 *
 * Ranking: every word must match (title or text); items whose title contains the phrase or the
 * words rank first, then text matches, then newer first. Results are cached per query in the
 * object cache and invalidated whenever the index changes.
 *
 * The main search query (/?s=…) is answered from the index (posts_pre_query), so pagination and
 * templates work as usual; the theme renders facets, snippets and highlighting.
 */

defined( 'ABSPATH' ) || exit;

const TMC_SEARCH_DB_VERSION = 1;
const TMC_SEARCH_PER_PAGE   = 10;
const TMC_SEARCH_MAX_QUERY  = 100; // characters
const TMC_SEARCH_MAX_TERMS  = 8;

function tmc_search_table() {
	global $wpdb;
	return $wpdb->prefix . 'tmc_search_index';
}

/** Create/upgrade the index table of the current site. */
function tmc_search_install() {
	if ( (int) get_option( 'tmc_search_db_version' ) === TMC_SEARCH_DB_VERSION ) {
		return;
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$table = tmc_search_table();
	dbDelta(
		"CREATE TABLE {$table} (
			post_id bigint(20) unsigned NOT NULL,
			post_type varchar(20) NOT NULL DEFAULT '',
			lang varchar(10) NOT NULL DEFAULT '',
			post_date datetime NOT NULL,
			title text NOT NULL,
			body mediumtext NOT NULL,
			mime varchar(100) NOT NULL DEFAULT '',
			PRIMARY KEY  (post_id),
			KEY type_lang (post_type,lang),
			KEY post_date (post_date)
		) {$wpdb->get_charset_collate()};"
	);
	update_option( 'tmc_search_db_version', TMC_SEARCH_DB_VERSION );
}
add_action( 'init', 'tmc_search_install', 1 );

/** Searchable post types: every public type not excluded from search (attachments = documents only). */
function tmc_search_post_types() {
	$types = array_values( get_post_types( array( 'public' => true, 'exclude_from_search' => false ) ) );
	return array_values( array_unique( (array) apply_filters( 'tmc_search_post_types', $types ) ) );
}

/** Label of a content type, e.g. "Tender / EOI" (singular) or "Tenders & EOIs" (plural). The theme translates it. */
function tmc_search_type_label( $type, $plural = false ) {
	if ( 'attachment' === $type ) {
		$label = $plural ? 'Documents' : 'Document';
	} else {
		$object = get_post_type_object( $type );
		$label  = $object ? ( $plural ? $object->labels->name : $object->labels->singular_name ) : $type;
	}
	return (string) apply_filters( 'tmc_search_type_label', $label, $type, $plural );
}

/** Language of the current request ('' without Polylang). */
function tmc_search_current_lang() {
	if ( ! function_exists( 'pll_current_language' ) ) {
		return '';
	}
	$lang = pll_current_language();
	return $lang ? $lang : (string) pll_default_language();
}

/* ---------------------------------------------------------------- indexing */

/** Whether a post belongs in the public search index. */
function tmc_search_is_indexable( $post ) {
	$post = get_post( $post );
	if ( ! $post || ! in_array( $post->post_type, tmc_search_post_types(), true ) ) {
		return false;
	}
	if ( 'attachment' === $post->post_type ) {
		if ( 'inherit' !== $post->post_status || ! tmc_is_document( $post ) ) {
			return false;
		}
		if ( ! $post->post_parent ) {
			return true;
		}
		$parent = get_post( $post->post_parent );
		return $parent && 'publish' === $parent->post_status && '' === $parent->post_password;
	}
	return 'publish' === $post->post_status && '' === $post->post_password;
}

/** Markup, block comments and shortcodes → single-spaced plain text. */
function tmc_search_clean_text( $text ) {
	$text = (string) $text;
	if ( '' === $text ) {
		return '';
	}
	$text = preg_replace( '/<!--.*?-->/s', ' ', $text );
	$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', ' ', (string) $text );
	$text = preg_replace( '/<[^>]*>/', ' ', (string) $text );
	$text = html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$text = preg_replace( '/\s+/u', ' ', $text );
	return trim( (string) $text );
}

/** The index row for a post. */
function tmc_search_build_row( WP_Post $post ) {
	$is_document = 'attachment' === $post->post_type;
	$parts       = array( $post->post_excerpt );
	if ( $is_document ) {
		$parts[] = $post->post_content; // description
		$parts[] = wp_basename( (string) get_attached_file( $post->ID ) );
		$type    = tmc_document_type( $post->ID );
		$parts[] = $type ? $type->name : '';
		$parts[] = tmc_document_get_text( $post->ID );
		$date    = tmc_document_date( $post->ID ) . ' 00:00:00';
		$lang    = ''; // media is shared by all languages
	} else {
		$parts[] = strip_shortcodes( $post->post_content );
		// Structured fields (reference numbers, venues, designations …) are searchable too.
		$schema = function_exists( 'tmc_field_schema' ) ? ( tmc_field_schema()[ $post->post_type ] ?? array() ) : array();
		foreach ( $schema as $key => $field ) {
			$value = get_post_meta( $post->ID, '_' . $key, true );
			if ( 'select' === $field['type'] ) {
				$parts[] = $field['options'][ $value ] ?? '';
			} elseif ( in_array( $field['type'], array( 'text', 'email' ), true ) && is_string( $value ) ) {
				$parts[] = $value;
			}
		}
		$date = $post->post_date;
		$lang = function_exists( 'pll_get_post_language' ) ? (string) pll_get_post_language( $post->ID ) : '';
	}
	return array(
		'post_id'   => (int) $post->ID,
		'post_type' => $post->post_type,
		'lang'      => $lang,
		'post_date' => $date,
		'title'     => tmc_search_clean_text( $post->post_title ),
		'body'      => tmc_search_clean_text( implode( "\n", array_filter( $parts, 'strlen' ) ) ),
		'mime'      => (string) $post->post_mime_type,
	);
}

/** Add, refresh or remove one post in the index of the current site. */
function tmc_search_index_post( $post_id ) {
	global $wpdb;
	$post = get_post( $post_id );
	if ( ! $post || ! in_array( $post->post_type, tmc_search_post_types(), true ) ) {
		return false;
	}
	tmc_search_install();
	if ( tmc_search_is_indexable( $post ) ) {
		$wpdb->replace( tmc_search_table(), tmc_search_build_row( $post ), array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' ) );
		$indexed = true;
	} else {
		$wpdb->delete( tmc_search_table(), array( 'post_id' => (int) $post->ID ), array( '%d' ) );
		$indexed = false;
	}
	wp_cache_set_last_changed( 'tmc_search' );
	return $indexed;
}

function tmc_search_unindex_post( $post_id ) {
	global $wpdb;
	if ( (int) get_option( 'tmc_search_db_version' ) !== TMC_SEARCH_DB_VERSION ) {
		return;
	}
	$wpdb->delete( tmc_search_table(), array( 'post_id' => (int) $post_id ), array( '%d' ) );
	wp_cache_set_last_changed( 'tmc_search' );
}

/** Re-index the documents attached to a post (they are public only while their post is). */
function tmc_search_index_children( $post_id ) {
	$children = get_children( array( 'post_parent' => (int) $post_id, 'post_type' => 'attachment', 'fields' => 'ids', 'post_status' => 'any' ) );
	foreach ( (array) $children as $child_id ) {
		tmc_search_index_post( $child_id );
	}
}

// Fires after the post, its terms and its meta are saved (block editor, classic editor, REST, WP-CLI).
add_action(
	'wp_after_insert_post',
	function ( $post_id, $post ) {
		if ( ! wp_is_post_revision( $post_id ) && ! wp_is_post_autosave( $post_id ) ) {
			tmc_search_index_post( $post_id );
		}
	},
	20,
	2
);
add_action( 'add_attachment', 'tmc_search_index_post', 20 );
add_action( 'attachment_updated', 'tmc_search_index_post', 20 );
add_action(
	'transition_post_status',
	function ( $new_status, $old_status, $post ) {
		if ( $new_status !== $old_status && 'attachment' !== $post->post_type && 'revision' !== $post->post_type ) {
			tmc_search_index_children( $post->ID );
		}
	},
	20,
	3
);
// Language (Polylang), document type or other term changes.
add_action(
	'set_object_terms',
	function ( $object_id, $terms, $tt_ids, $taxonomy ) {
		if ( in_array( $taxonomy, array( 'language', 'tmc_doc_type' ), true ) ) {
			tmc_search_index_post( $object_id );
		}
	},
	20,
	4
);
// Structured fields and document data changed outside a full save (scripts, the media modal).
function tmc_search_on_meta( $meta_id, $post_id, $meta_key ) {
	static $keys = null;
	if ( null === $keys ) {
		$keys = array( '_tmc_doc_date', '_wp_attached_file' );
		foreach ( function_exists( 'tmc_field_schema' ) ? tmc_field_schema() : array() as $fields ) {
			foreach ( array_keys( $fields ) as $key ) {
				$keys[] = '_' . $key;
			}
		}
	}
	if ( in_array( $meta_key, $keys, true ) ) {
		tmc_search_index_post( $post_id );
	}
}
add_action( 'added_post_meta', 'tmc_search_on_meta', 20, 3 );
add_action( 'updated_post_meta', 'tmc_search_on_meta', 20, 3 );
add_action( 'deleted_post_meta', fn( $meta_ids, $post_id, $meta_key ) => tmc_search_on_meta( 0, $post_id, $meta_key ), 20, 3 );
// Attach / detach in Media → Library (a direct SQL update in core).
add_action( 'wp_media_attach_action', fn( $action, $attachment_id ) => tmc_search_index_post( $attachment_id ), 20, 2 );
// Deletion: the post leaves the index; its documents move to the parent's parent, so re-check them.
add_action(
	'before_delete_post',
	function ( $post_id ) {
		$GLOBALS['tmc_search_orphans'][ $post_id ] = get_children( array( 'post_parent' => (int) $post_id, 'post_type' => 'attachment', 'fields' => 'ids', 'post_status' => 'any' ) );
	}
);
add_action(
	'deleted_post',
	function ( $post_id ) {
		tmc_search_unindex_post( $post_id );
		foreach ( (array) ( $GLOBALS['tmc_search_orphans'][ $post_id ] ?? array() ) as $child_id ) {
			clean_post_cache( $child_id );
			tmc_search_index_post( $child_id );
		}
		unset( $GLOBALS['tmc_search_orphans'][ $post_id ] );
	}
);

/**
 * Rebuild the index of the current site from scratch (no text re-extraction). Rows of items that
 * are no longer public are removed. Returns the number of indexed items.
 */
function tmc_search_rebuild() {
	global $wpdb;
	tmc_search_install();
	$types = tmc_search_post_types();
	$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
	$ids   = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ($in) AND post_status IN ('publish','inherit') ORDER BY ID", $types ) ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$kept  = array();
	foreach ( array_chunk( $ids, 200 ) as $chunk ) {
		_prime_post_caches( $chunk, true, true );
		foreach ( $chunk as $id ) {
			if ( tmc_search_index_post( $id ) ) {
				$kept[ $id ] = true;
			}
		}
	}
	$table = tmc_search_table();
	$stale = array_diff( array_map( 'intval', $wpdb->get_col( "SELECT post_id FROM {$table}" ) ), array_keys( $kept ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	foreach ( array_chunk( $stale, 500 ) as $chunk ) {
		$wpdb->query( "DELETE FROM {$table} WHERE post_id IN (" . implode( ',', array_map( 'intval', $chunk ) ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers only
	}
	wp_cache_set_last_changed( 'tmc_search' );
	return count( $kept );
}

// Daily reconcile (catches changes made by direct SQL, e.g. data migrations). Runs from the cron container.
add_action(
	'init',
	function () {
		if ( ! wp_next_scheduled( 'tmc_search_reconcile' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'tmc_search_reconcile' );
		}
	}
);
add_action(
	'tmc_search_reconcile',
	function () {
		if ( function_exists( 'tmc_documents_backfill' ) ) {
			tmc_documents_backfill();
		}
		tmc_search_rebuild();
	}
);

/* ---------------------------------------------------------------- querying */

/** Trimmed, length-limited query string. */
function tmc_search_normalise_query( $query ) {
	$query = wp_check_invalid_utf8( (string) $query, true );
	$query = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $query ) ) );
	return mb_substr( $query, 0, TMC_SEARCH_MAX_QUERY );
}

/** Words of a query (unique, 2+ characters unless that leaves none, at most TMC_SEARCH_MAX_TERMS). */
function tmc_search_terms( $query ) {
	$terms = array();
	foreach ( preg_split( '/\s+/u', tmc_search_normalise_query( $query ), -1, PREG_SPLIT_NO_EMPTY ) as $word ) {
		$word = trim( $word, "\"'“”‘’.,;:!?()[]{}" );
		if ( '' !== $word ) {
			$terms[ mb_strtolower( $word ) ] = $word;
		}
	}
	$long = array_filter( $terms, fn( $word ) => mb_strlen( $word ) >= 2 );
	return array_slice( array_values( $long ? $long : $terms ), 0, TMC_SEARCH_MAX_TERMS );
}

/**
 * Search the index of the current site.
 *
 * @param array $args q (string), lang ('' = all), type ('' = all searchable types), page (1-based), per_page.
 * @return array{query:string,terms:string[],type:string,lang:string,page:int,per_page:int,ids:int[],total:int,facets:array<string,int>,snippets:array<int,string>}
 */
function tmc_search_query( array $args ) {
	global $wpdb;
	$args     = wp_parse_args( $args, array( 'q' => '', 'lang' => '', 'type' => '', 'page' => 1, 'per_page' => TMC_SEARCH_PER_PAGE ) );
	$query    = tmc_search_normalise_query( $args['q'] );
	$terms    = tmc_search_terms( $query );
	$types    = tmc_search_post_types();
	$type     = in_array( $args['type'], $types, true ) ? $args['type'] : '';
	$page     = max( 1, (int) $args['page'] );
	$per_page = max( 1, min( 50, (int) $args['per_page'] ) );
	$result   = array(
		'query'    => $query,
		'terms'    => $terms,
		'type'     => $type,
		'lang'     => (string) $args['lang'],
		'page'     => $page,
		'per_page' => $per_page,
		'ids'      => array(),
		'total'    => 0,
		'facets'   => array(),
		'snippets' => array(),
	);
	if ( ! $terms || (int) get_option( 'tmc_search_db_version' ) !== TMC_SEARCH_DB_VERSION ) {
		return $result;
	}

	$cache_key = 'q:' . md5( wp_json_encode( array( $query, $result['lang'], $type, $page, $per_page, $types ) ) ) . ':' . wp_cache_get_last_changed( 'tmc_search' );
	$cached    = wp_cache_get( $cache_key, 'tmc_search' );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$table = tmc_search_table();
	$where = tmc_search_where( $terms, $result['lang'], $types );

	// Facet counts per type (independent of the selected type).
	$counts = $wpdb->get_results( "SELECT post_type, COUNT(*) AS n FROM {$table} WHERE {$where} GROUP BY post_type", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prepared in tmc_search_where()
	foreach ( $types as $searchable ) { // stable order: the order of tmc_search_post_types()
		foreach ( $counts as $count ) {
			if ( $count['post_type'] === $searchable && (int) $count['n'] > 0 ) {
				$result['facets'][ $searchable ] = (int) $count['n'];
			}
		}
	}
	$result['total'] = $type ? ( $result['facets'][ $type ] ?? 0 ) : array_sum( $result['facets'] );

	if ( $result['total'] > ( $page - 1 ) * $per_page ) {
		$phrase    = '%' . $wpdb->esc_like( $query ) . '%';
		$score     = array( $wpdb->prepare( 'CASE WHEN title = %s THEN 100 ELSE 0 END', $query ), $wpdb->prepare( 'CASE WHEN title LIKE %s THEN 40 ELSE 0 END', $phrase ) );
		$score_txt = array( $wpdb->prepare( 'CASE WHEN body LIKE %s THEN 8 ELSE 0 END', $phrase ) );
		foreach ( $terms as $term ) {
			$like        = '%' . $wpdb->esc_like( $term ) . '%';
			$score[]     = $wpdb->prepare( 'CASE WHEN title LIKE %s THEN 20 ELSE 0 END', $like );
			$score_txt[] = $wpdb->prepare( 'CASE WHEN body LIKE %s THEN 2 ELSE 0 END', $like );
		}
		$score_sql = implode( ' + ', array_merge( $score, $score_txt ) );
		$type_sql  = $type ? $wpdb->prepare( ' AND post_type = %s', $type ) : '';
		$limit     = sprintf( ' LIMIT %d OFFSET %d', $per_page, ( $page - 1 ) * $per_page );
		// Every fragment was prepared above; the LIMIT values are integers.
		$rows = $wpdb->get_results( "SELECT post_id, body, ({$score_sql}) AS score FROM {$table} WHERE {$where}{$type_sql} ORDER BY score DESC, post_date DESC, post_id DESC{$limit}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $rows as $row ) {
			$id                        = (int) $row['post_id'];
			$result['ids'][]           = $id;
			$result['snippets'][ $id ] = tmc_search_snippet( $row['body'], $terms );
		}
	}

	wp_cache_set( $cache_key, $result, 'tmc_search', 10 * MINUTE_IN_SECONDS );
	return $result;
}

/** WHERE clause (prepared) shared by results and facets: language, public types, every word. */
function tmc_search_where( array $terms, $lang, array $types ) {
	global $wpdb;
	$sql = array( $wpdb->prepare( 'post_type IN (' . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ')', $types ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.NotPrepared
	if ( '' !== $lang ) {
		$sql[] = $wpdb->prepare( "lang IN (%s, '')", $lang );
	}
	foreach ( $terms as $term ) {
		$like  = '%' . $wpdb->esc_like( $term ) . '%';
		$sql[] = $wpdb->prepare( '(title LIKE %s OR body LIKE %s)', $like, $like );
	}
	return implode( ' AND ', $sql );
}

/** Up to ~240 characters of text around the first matched word (plain text; escape before output). */
function tmc_search_snippet( $body, array $terms, $length = 240 ) {
	$body = (string) $body;
	if ( '' === $body ) {
		return '';
	}
	$first = false;
	foreach ( $terms as $term ) {
		$position = mb_stripos( $body, $term );
		if ( false !== $position && ( false === $first || $position < $first ) ) {
			$first = $position;
		}
	}
	$start = ( false === $first ) ? 0 : max( 0, $first - 60 );
	if ( $start > 0 ) {
		$space = mb_strpos( $body, ' ', $start );
		$start = ( false !== $space && $space < $first ) ? $space + 1 : $start;
	}
	$snippet = mb_substr( $body, $start, $length );
	if ( $start + $length < mb_strlen( $body ) ) {
		$space   = mb_strrpos( $snippet, ' ' );
		$snippet = ( false !== $space && $space > $length / 2 ? mb_substr( $snippet, 0, $space ) : $snippet ) . ' …';
	}
	return ( $start > 0 ? '… ' : '' ) . $snippet;
}

/**
 * Escape text for HTML and wrap the matched words in <mark>. Safe: matching runs on the raw text and
 * every segment is escaped separately, so entities and markup can never be split or injected.
 */
function tmc_search_highlight( $text, array $terms ) {
	$text  = (string) $text;
	$terms = array_filter( array_map( 'strval', $terms ), 'strlen' );
	if ( ! $terms ) {
		return esc_html( $text );
	}
	usort( $terms, fn( $a, $b ) => mb_strlen( $b ) - mb_strlen( $a ) ); // longest first
	$pattern = '/(' . implode( '|', array_map( fn( $term ) => preg_quote( $term, '/' ), $terms ) ) . ')/iu';
	$parts   = preg_split( $pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE );
	if ( false === $parts ) {
		return esc_html( $text );
	}
	$html = '';
	foreach ( $parts as $index => $part ) {
		$html .= ( $index % 2 ) ? '<mark>' . esc_html( $part ) . '</mark>' : esc_html( $part );
	}
	return $html;
}

/**
 * Title suggestions for the search box: every word must be in the title; titles starting with the
 * query first, then word starts, then shorter titles.
 *
 * @return array<int, array{post_id:int, post_type:string, title:string}>
 */
function tmc_search_suggest( $query, $lang, $limit = 8 ) {
	global $wpdb;
	$query = tmc_search_normalise_query( $query );
	$terms = tmc_search_terms( $query );
	if ( ! $terms || (int) get_option( 'tmc_search_db_version' ) !== TMC_SEARCH_DB_VERSION ) {
		return array();
	}
	$types = tmc_search_post_types();
	$sql   = array( $wpdb->prepare( 'post_type IN (' . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ')', $types ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.NotPrepared
	if ( '' !== (string) $lang ) {
		$sql[] = $wpdb->prepare( "lang IN (%s, '')", $lang );
	}
	foreach ( $terms as $term ) {
		$sql[] = $wpdb->prepare( 'title LIKE %s', '%' . $wpdb->esc_like( $term ) . '%' );
	}
	$table = tmc_search_table();
	$order = $wpdb->prepare( 'CASE WHEN title LIKE %s THEN 0 WHEN title LIKE %s THEN 1 ELSE 2 END', $wpdb->esc_like( $query ) . '%', '% ' . $wpdb->esc_like( $query ) . '%' );
	$limit = max( 1, min( 20, (int) $limit ) );
	// Every fragment was prepared above; the LIMIT value is an integer.
	$rows = $wpdb->get_results( "SELECT post_id, post_type, title FROM {$table} WHERE " . implode( ' AND ', $sql ) . " ORDER BY {$order}, CHAR_LENGTH(title), post_date DESC LIMIT {$limit}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return array_map(
		fn( $row ) => array( 'post_id' => (int) $row['post_id'], 'post_type' => $row['post_type'], 'title' => $row['title'] ),
		(array) $rows
	);
}

/* ---------------------------------------------------------------- the main search query */

/**
 * The results of the current request's search (set when the main query ran), or null.
 *
 * @param array|null $set Internal: store the result.
 */
function tmc_search_current( $set = null ) {
	static $current = null;
	if ( null !== $set ) {
		$current = $set;
	}
	return $current;
}

add_action( 'pre_get_posts', 'tmc_search_main_query_setup' );
function tmc_search_main_query_setup( WP_Query $query ) {
	if ( is_admin() || ! $query->is_main_query() || ! $query->is_search() ) {
		return;
	}
	$type = sanitize_key( wp_unslash( $_GET['type'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public search filter
	$query->set( 'tmc_search', true );
	$query->set( 'tmc_search_type', in_array( $type, tmc_search_post_types(), true ) ? $type : '' );
	$query->set( 'posts_per_page', TMC_SEARCH_PER_PAGE );
	$query->set( 'cache_results', false ); // results are cached by tmc_search_query()
}

/** Answer queries flagged "tmc_search" from the index (also usable by secondary WP_Query instances). */
add_filter( 'posts_pre_query', 'tmc_search_posts_pre_query', 10, 2 );
function tmc_search_posts_pre_query( $posts, WP_Query $query ) {
	if ( null !== $posts || ! $query->get( 'tmc_search' ) ) {
		return $posts;
	}
	$per_page = (int) $query->get( 'posts_per_page' );
	$per_page = $per_page > 0 ? $per_page : TMC_SEARCH_PER_PAGE;
	$result   = tmc_search_query(
		array(
			'q'        => (string) $query->get( 's' ),
			'lang'     => $query->get( 'tmc_search_lang' ) ? (string) $query->get( 'tmc_search_lang' ) : tmc_search_current_lang(),
			'type'     => (string) $query->get( 'tmc_search_type' ),
			'page'     => max( 1, (int) $query->get( 'paged' ) ),
			'per_page' => $per_page,
		)
	);
	if ( $query->is_main_query() ) {
		tmc_search_current( $result );
	}
	$query->found_posts   = $result['total'];
	$query->max_num_pages = (int) ceil( $result['total'] / $result['per_page'] );
	if ( $result['ids'] ) {
		_prime_post_caches( $result['ids'], false, true );
	}
	return $result['ids'];
}

/* ---------------------------------------------------------------- WP-CLI */

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	// wp tmc-search rebuild --url=<site>   rebuild this site's index (no text re-extraction)
	WP_CLI::add_command(
		'tmc-search rebuild',
		function () {
			$count = tmc_search_rebuild();
			WP_CLI::success( "$count item(s) indexed on " . home_url() );
		},
		array( 'shortdesc' => 'Rebuild the search index of this site.' )
	);
	// wp tmc-search extract [--all] --url=<site>   extract document text again, then re-index
	WP_CLI::add_command(
		'tmc-search extract',
		function ( $args, $assoc_args ) {
			$done = 0;
			foreach ( tmc_documents_needing_text( ! empty( $assoc_args['all'] ), 100000 ) as $id ) {
				WP_CLI::log( sprintf( '  %d %s: %s', $id, get_the_title( $id ), tmc_document_extract( $id ) ) );
				tmc_search_index_post( $id );
				++$done;
			}
			WP_CLI::success( "$done document(s) processed on " . home_url() . ( tmc_pdftotext_binary() ? '' : ' (pdftotext not available here: basic reader used)' ) );
		},
		array(
			'shortdesc' => 'Extract the text of documents without text (or read with the basic reader) and re-index them.',
			'synopsis'  => array(
				array(
					'type'        => 'flag',
					'name'        => 'all',
					'optional'    => true,
					'description' => 'Every document of the site.',
				),
			),
		)
	);
}

/**
 * Documents of the current site whose text should be (re-)extracted: never extracted, or PDFs read
 * with the basic reader (or unreadable then) while pdftotext is now available.
 *
 * @return int[]
 */
function tmc_documents_needing_text( $all = false, $limit = 50 ) {
	global $wpdb;
	$mimes = array_values( tmc_document_mime_types() );
	$in    = implode( ',', array_fill( 0, count( $mimes ), '%s' ) );
	$where = '';
	if ( ! $all ) {
		$where = tmc_pdftotext_binary()
			? " AND ( m.meta_value IS NULL OR ( p.post_mime_type = 'application/pdf' AND m.meta_value IN ('basic','none') ) )"
			: ' AND m.meta_value IS NULL';
	}
	return array_map(
		'intval',
		$wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_tmc_doc_text_method'
				WHERE p.post_type = 'attachment' AND p.post_mime_type IN ($in){$where} ORDER BY p.ID DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( $mimes, array( max( 1, (int) $limit ) ) )
			)
		)
	);
}
