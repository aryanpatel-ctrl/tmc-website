<?php
/**
 * Document library (tender §4.6: central management of media and document libraries).
 *
 * Documents are ordinary media-library attachments (PDF, Office, OpenDocument, text) with:
 *   - a "Document type" (taxonomy tmc_doc_type: Annual report, Circular, Office order, Form,
 *     Policy, Tender document, Result, Other), shown as a Media → Library column with a filter
 *   - a "Document date" (meta _tmc_doc_date, Y-m-d; defaults to the upload date) for the year filter
 * Both are set in the media modal / attachment screen. New uploads get defaults (Tender document when
 * uploaded to or attached to a tender, otherwise Other) so every document appears in the library.
 *
 * Public: /documents/ page (block tmc/documents, theme inc/documents.php). Only documents that are
 * unattached or attached to published, non-password-protected content are listed.
 */

defined( 'ABSPATH' ) || exit;

const TMC_DOC_TYPES_VERSION = 1;

/** Slug → name of the standard document types (names are data; the theme translates known slugs). */
function tmc_document_types() {
	return array(
		'annual-report'   => 'Annual report',
		'circular'        => 'Circular',
		'office-order'    => 'Office order',
		'form'            => 'Form',
		'policy'          => 'Policy',
		'tender-document' => 'Tender document',
		'result'          => 'Result',
		'other'           => 'Other',
	);
}

add_action( 'init', 'tmc_register_document_taxonomy', 6 );
function tmc_register_document_taxonomy() {
	register_taxonomy(
		'tmc_doc_type',
		'attachment',
		array(
			'labels'             => array(
				'name'          => 'Document types',
				'singular_name' => 'Document type',
				'menu_name'     => 'Document types',
				'all_items'     => 'All document types',
				'edit_item'     => 'Edit document type',
				'view_item'     => 'View document type',
				'update_item'   => 'Update document type',
				'add_new_item'  => 'Add document type',
				'new_item_name' => 'New document type name',
				'search_items'  => 'Search document types',
				'not_found'     => 'No document types found',
				'back_to_items' => '← Back to document types',
			),
			'public'             => false, // no term archives; the public list is the Documents page
			'publicly_queryable' => false,
			'show_ui'            => true,  // Media → Document types
			'show_in_menu'       => true,
			'show_in_nav_menus'  => false,
			'show_tagcloud'      => false,
			'show_in_quick_edit' => false,
			'show_admin_column'  => true,  // Media → Library (list view) column
			'show_in_rest'       => false,
			'meta_box_cb'        => false, // edited through the "Document type" field (tmc_document_fields)
			'hierarchical'       => false,
			'query_var'          => 'tmc_doc_type', // admin-only (not publicly queryable): the Library filter
			'rewrite'            => false,
			'capabilities'       => array(
				'manage_terms' => 'manage_options',
				'edit_terms'   => 'manage_options',
				'delete_terms' => 'manage_options',
				'assign_terms' => 'upload_files',
			),
		)
	);
}

// One set of document types for all languages (media is shared: Polylang media_support is off).
add_filter(
	'pll_get_taxonomies',
	function ( $taxonomies ) {
		unset( $taxonomies['tmc_doc_type'] );
		return $taxonomies;
	}
);

/** Create the standard document types on each site (once per TMC_DOC_TYPES_VERSION). */
add_action( 'init', 'tmc_document_types_install', 20 );
function tmc_document_types_install() {
	if ( (int) get_option( 'tmc_doc_types_version' ) === TMC_DOC_TYPES_VERSION ) {
		return;
	}
	foreach ( tmc_document_types() as $slug => $name ) {
		if ( ! term_exists( $slug, 'tmc_doc_type' ) ) {
			wp_insert_term( $name, 'tmc_doc_type', array( 'slug' => $slug ) );
		}
	}
	update_option( 'tmc_doc_types_version', TMC_DOC_TYPES_VERSION );
}

/* ---------------------------------------------------------------- per-document data */

/** Document type term of an attachment, or null. */
function tmc_document_type( $attachment_id ) {
	$terms = get_the_terms( $attachment_id, 'tmc_doc_type' );
	return ( is_array( $terms ) && $terms ) ? $terms[0] : null;
}

/** Document date (Y-m-d): the editor-set date of issue, else the upload date. */
function tmc_document_date( $attachment_id ) {
	$date = (string) get_post_meta( $attachment_id, '_tmc_doc_date', true );
	return $date ? $date : substr( (string) get_post_field( 'post_date', $attachment_id ), 0, 10 );
}

/**
 * File format and size for display (GIGW: say what is being downloaded).
 *
 * @return array{ext:string,size:string,bytes:int}
 */
function tmc_document_file_info( $attachment_id ) {
	$file  = get_attached_file( $attachment_id );
	$meta  = wp_get_attachment_metadata( $attachment_id );
	$bytes = (int) ( is_array( $meta ) ? ( $meta['filesize'] ?? 0 ) : 0 );
	if ( ! $bytes && $file && file_exists( $file ) ) {
		$bytes = (int) filesize( $file );
	}
	return array(
		'ext'   => $file ? strtoupper( pathinfo( $file, PATHINFO_EXTENSION ) ) : '',
		'size'  => $bytes ? (string) size_format( $bytes, $bytes >= MB_IN_BYTES ? 1 : 0 ) : '',
		'bytes' => $bytes,
	);
}

/** Give a document a date and a type if it has none (new uploads, migrations, daily reconcile). */
function tmc_document_apply_defaults( $attachment_id ) {
	if ( ! tmc_is_document( $attachment_id ) ) {
		return false;
	}
	$changed = false;
	if ( ! get_post_meta( $attachment_id, '_tmc_doc_date', true ) ) {
		update_post_meta( $attachment_id, '_tmc_doc_date', substr( (string) get_post_field( 'post_date', $attachment_id ), 0, 10 ) );
		$changed = true;
	}
	$current = wp_get_object_terms( $attachment_id, 'tmc_doc_type', array( 'fields' => 'ids' ) );
	if ( is_array( $current ) && ! $current ) {
		$parent = get_post_parent( $attachment_id );
		$term   = get_term_by( 'slug', ( $parent && 'tmc_tender' === $parent->post_type ) ? 'tender-document' : 'other', 'tmc_doc_type' );
		if ( $term ) {
			wp_set_object_terms( $attachment_id, array( (int) $term->term_id ), 'tmc_doc_type' );
			$changed = true;
		}
	}
	return $changed;
}

add_action( 'add_attachment', 'tmc_document_apply_defaults', 8 );

// Documents added to a tender's "Documents" field become Tender documents (unless typed otherwise).
add_action( 'added_post_meta', 'tmc_document_tender_link', 10, 4 );
add_action( 'updated_post_meta', 'tmc_document_tender_link', 10, 4 );
function tmc_document_tender_link( $meta_id, $post_id, $meta_key, $value ) {
	if ( '_tmc_documents' !== $meta_key || 'tmc_tender' !== get_post_type( $post_id ) ) {
		return;
	}
	$term = get_term_by( 'slug', 'tender-document', 'tmc_doc_type' );
	if ( ! $term ) {
		return;
	}
	foreach ( array_map( 'intval', (array) maybe_unserialize( $value ) ) as $attachment_id ) {
		if ( ! tmc_is_document( $attachment_id ) ) {
			continue;
		}
		$slugs = wp_get_object_terms( $attachment_id, 'tmc_doc_type', array( 'fields' => 'slugs' ) );
		if ( is_array( $slugs ) && ( ! $slugs || array( 'other' ) === $slugs ) ) {
			wp_set_object_terms( $attachment_id, array( (int) $term->term_id ), 'tmc_doc_type' );
		}
	}
}

/** Set defaults on every document of the current site; returns how many changed. */
function tmc_documents_backfill() {
	global $wpdb;
	$mimes   = array_values( tmc_document_mime_types() );
	$in      = implode( ',', array_fill( 0, count( $mimes ), '%s' ) );
	$ids     = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ($in)", $mimes ) ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$changed = 0;
	foreach ( array_chunk( $ids, 200 ) as $chunk ) {
		update_meta_cache( 'post', $chunk );
		update_object_term_cache( $chunk, 'attachment' );
		foreach ( $chunk as $id ) {
			$changed += tmc_document_apply_defaults( $id ) ? 1 : 0;
		}
	}
	return $changed;
}

/* ---------------------------------------------------------------- editing (media modal + attachment screen) */

add_filter( 'attachment_fields_to_edit', 'tmc_document_fields', 10, 2 );
function tmc_document_fields( $fields, $post ) {
	if ( ! tmc_is_document( $post ) ) {
		return $fields;
	}
	$current = tmc_document_type( $post->ID );
	$options = '<option value="">— Select —</option>';
	foreach ( get_terms( array( 'taxonomy' => 'tmc_doc_type', 'hide_empty' => false, 'orderby' => 'name' ) ) as $term ) {
		$options .= sprintf( '<option value="%s"%s>%s</option>', esc_attr( $term->slug ), selected( $current && $current->term_id === $term->term_id, true, false ), esc_html( $term->name ) );
	}
	// Field keys differ from the taxonomy name on purpose: core would otherwise treat the value as a
	// comma-separated term list and create unknown terms.
	$fields['tmc_doctype'] = array(
		'label' => 'Document type',
		'input' => 'html',
		'html'  => sprintf( '<select name="attachments[%1$d][tmc_doctype]" id="attachments-%1$d-tmc_doctype">%2$s</select>', (int) $post->ID, $options ),
		'helps' => 'Used by the Documents library and its type filter.',
	);
	$fields['tmc_docdate'] = array(
		'label' => 'Document date',
		'input' => 'html',
		'html'  => sprintf( '<input type="date" name="attachments[%1$d][tmc_docdate]" id="attachments-%1$d-tmc_docdate" value="%2$s">', (int) $post->ID, esc_attr( (string) get_post_meta( $post->ID, '_tmc_doc_date', true ) ) ),
		'helps' => 'Date of issue. Used for the year filter. Empty = upload date.',
	);
	$method = (string) get_post_meta( $post->ID, '_tmc_doc_text_method', true );
	$chars  = (int) get_post_meta( $post->ID, '_tmc_doc_text_chars', true );
	$fields['tmc_doctext'] = array(
		'label' => 'Search text',
		'input' => 'html',
		'html'  => '<span>' . esc_html( $method ? sprintf( '%s characters extracted (%s)', number_format_i18n( $chars ), $method ) : 'Not extracted' ) . '</span>',
		'helps' => 0 === $chars && 'application/pdf' === $post->post_mime_type ? 'No text found. A scanned PDF needs OCR before upload to be searchable.' : '',
	);
	return $fields;
}

add_filter( 'attachment_fields_to_save', 'tmc_document_fields_save', 10, 2 );
function tmc_document_fields_save( $post, $attachment ) {
	$id = (int) ( $post['ID'] ?? 0 );
	if ( ! $id || ! tmc_is_document( $id ) || ! current_user_can( 'edit_post', $id ) ) {
		return $post;
	}
	$taxonomy = get_taxonomy( 'tmc_doc_type' );
	if ( isset( $attachment['tmc_doctype'] ) && $taxonomy && current_user_can( $taxonomy->cap->assign_terms ) ) {
		$slug = sanitize_key( wp_unslash( $attachment['tmc_doctype'] ) );
		$term = $slug ? get_term_by( 'slug', $slug, 'tmc_doc_type' ) : false;
		if ( $term || '' === $slug ) {
			wp_set_object_terms( $id, $term ? array( (int) $term->term_id ) : array(), 'tmc_doc_type' );
		}
	}
	if ( isset( $attachment['tmc_docdate'] ) ) {
		$raw  = sanitize_text_field( wp_unslash( $attachment['tmc_docdate'] ) );
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $raw );
		if ( $date && $date->format( 'Y-m-d' ) === $raw ) {
			update_post_meta( $id, '_tmc_doc_date', $raw );
		} elseif ( '' === $raw ) {
			update_post_meta( $id, '_tmc_doc_date', substr( (string) get_post_field( 'post_date', $id ), 0, 10 ) );
		}
	}
	return $post;
}

/* ---------------------------------------------------------------- Media → Library filter */

add_action( 'restrict_manage_posts', 'tmc_document_type_filter', 10, 2 );
function tmc_document_type_filter( $post_type, $which = 'top' ) {
	if ( 'attachment' !== $post_type || 'top' !== $which ) {
		return;
	}
	$selected = sanitize_key( wp_unslash( $_GET['tmc_doc_type'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- list filter
	echo '<label class="screen-reader-text" for="filter-by-doc-type">Filter by document type</label>';
	wp_dropdown_categories(
		array(
			'taxonomy'        => 'tmc_doc_type',
			'name'            => 'tmc_doc_type',
			'id'              => 'filter-by-doc-type',
			'value_field'     => 'slug',
			'show_option_all' => 'All document types',
			'hide_empty'      => false,
			'orderby'         => 'name',
			'selected'        => $selected,
		)
	);
}

/* ---------------------------------------------------------------- public listing */

/**
 * Published documents, newest document date first.
 *
 * @param array $args type (slug), year (int), per_page, page.
 */
function tmc_documents_query( array $args ) {
	$args = wp_parse_args( $args, array( 'type' => '', 'year' => 0, 'per_page' => 20, 'page' => 1 ) );
	$year = (int) $args['year'];
	$date = $year
		? array( 'key' => '_tmc_doc_date', 'value' => array( sprintf( '%04d-01-01', $year ), sprintf( '%04d-12-31', $year ) ), 'compare' => 'BETWEEN', 'type' => 'DATE' )
		: array( 'key' => '_tmc_doc_date', 'compare' => 'EXISTS' );

	$query = array(
		'post_type'            => 'attachment',
		'post_status'          => 'inherit',
		'post_mime_type'       => array_values( tmc_document_mime_types() ),
		'posts_per_page'       => max( 1, min( 100, (int) $args['per_page'] ) ),
		'paged'                => max( 1, (int) $args['page'] ),
		'ignore_sticky_posts'  => true,
		'tmc_public_documents' => true,
		'meta_query'           => array( 'doc_date' => $date ),
		'orderby'              => array( 'doc_date' => 'DESC', 'ID' => 'DESC' ),
	);
	if ( '' !== (string) $args['type'] ) {
		$query['tax_query'] = array( array( 'taxonomy' => 'tmc_doc_type', 'field' => 'slug', 'terms' => sanitize_key( $args['type'] ) ) );
	}
	return new WP_Query( $query );
}

// Only documents of published content (or unattached ones) are public.
add_filter( 'posts_where', 'tmc_documents_public_where', 10, 2 );
function tmc_documents_public_where( $where, WP_Query $query ) {
	if ( $query->get( 'tmc_public_documents' ) ) {
		global $wpdb;
		$where .= " AND ( {$wpdb->posts}.post_parent = 0 OR EXISTS ( SELECT 1 FROM {$wpdb->posts} AS tmc_parent WHERE tmc_parent.ID = {$wpdb->posts}.post_parent AND tmc_parent.post_status = 'publish' AND tmc_parent.post_password = '' ) )";
	}
	return $where;
}

/** Years that have documents (for the year filter), newest first. */
function tmc_documents_years() {
	global $wpdb;
	$key   = 'years:' . wp_cache_get_last_changed( 'posts' );
	$years = wp_cache_get( $key, 'tmc_documents' );
	if ( false === $years ) {
		$mimes = array_values( tmc_document_mime_types() );
		$in    = implode( ',', array_fill( 0, count( $mimes ), '%s' ) );
		$years = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT LEFT(m.meta_value, 4) AS y FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
				WHERE m.meta_key = '_tmc_doc_date' AND p.post_type = 'attachment' AND p.post_status = 'inherit' AND p.post_mime_type IN ($in)
				ORDER BY y DESC", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$mimes
			)
		);
		$years = array_values( array_filter( array_map( 'intval', $years ), fn( $year ) => $year >= 1900 && $year <= 2100 ) );
		wp_cache_set( $key, $years, 'tmc_documents', HOUR_IN_SECONDS );
	}
	return $years;
}

/* ---------------------------------------------------------------- Documents page */

/** Create the public Documents page (English) if it does not exist; returns its ID. */
function tmc_documents_ensure_page() {
	$page = get_page_by_path( 'documents' );
	if ( $page ) {
		return (int) $page->ID;
	}
	$admin   = get_user_by( 'login', getenv( 'WP_ADMIN_USER' ) ? getenv( 'WP_ADMIN_USER' ) : 'tmcadmin' );
	$intro   = sprintf( 'Annual reports, circulars, office orders, forms, policies, tender documents and results of %s. Choose a document type or year to narrow the list; every link shows the file format and size.', get_option( 'blogname' ) );
	$content = '<!-- wp:paragraph --><p>' . esc_html( $intro ) . "</p><!-- /wp:paragraph -->\n\n" . '<!-- wp:tmc/documents {"library":true,"count":20} /-->';
	$id      = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_name'    => 'documents',
			'post_title'   => 'Documents',
			'post_content' => wp_slash( $content ),
			'post_author'  => $admin ? (int) $admin->ID : 0,
			'menu_order'   => 90,
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		return 0;
	}
	if ( function_exists( 'pll_set_post_language' ) ) {
		pll_set_post_language( $id, 'en' );
	}
	return (int) $id;
}

/** Link the Documents page from the English footer quick links (if that menu exists). */
function tmc_documents_ensure_menu_item( $page_id ) {
	$menu = wp_get_nav_menu_object( 'Footer quick links (English)' );
	if ( ! $menu || ! $page_id ) {
		return false;
	}
	foreach ( (array) wp_get_nav_menu_items( $menu->term_id ) as $item ) {
		if ( 'page' === $item->object && (int) $item->object_id === (int) $page_id ) {
			return false;
		}
	}
	$item_id = wp_update_nav_menu_item(
		$menu->term_id,
		0,
		array(
			'menu-item-object-id' => (int) $page_id,
			'menu-item-object'    => 'page',
			'menu-item-type'      => 'post_type',
			'menu-item-status'    => 'publish',
		)
	);
	return ! is_wp_error( $item_id );
}
