<?php
/**
 * Network Admin → Media & documents (tender §4.6: central management of media and document libraries).
 *
 * One screen for Super Admins across all six websites:
 *   Overview        per site: documents, images, other files, disk space, PDF text status; totals
 *   Recent uploads  the latest uploads on every site, with links to edit
 *   Find a file     search all sites by title or file name (optionally documents / images only)
 * Plus two maintenance actions: refresh the statistics, and extract PDF text with pdftotext for
 * documents that were read with the basic reader (e.g. by a migration run in the WP-CLI container).
 *
 * Read-only queries use each site's own tables through $wpdb->get_blog_prefix(); statistics are
 * cached for an hour in a network transient. Actions need manage_network_options and a nonce and
 * are recorded in the audit log.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'network_admin_menu', 'tmc_media_overview_menu' );
function tmc_media_overview_menu() {
	add_menu_page( 'Media & documents', 'Media & documents', 'manage_network_options', 'tmc-media', 'tmc_media_overview_page', 'dashicons-admin-media', 24 );
}

/** Blog IDs of all live sites. */
function tmc_media_site_ids() {
	return array_map( 'intval', get_sites( array( 'number' => 500, 'fields' => 'ids', 'deleted' => 0, 'archived' => 0, 'spam' => 0, 'orderby' => 'id' ) ) );
}

/**
 * Statistics of one site.
 *
 * @return array{documents:int,images:int,other:int,bytes:int,pdf:array<string,int>}
 */
function tmc_media_site_stats( $blog_id ) {
	global $wpdb;
	$prefix = $wpdb->get_blog_prefix( $blog_id );
	$docs   = tmc_document_mime_types();
	$stats  = array( 'documents' => 0, 'images' => 0, 'other' => 0, 'bytes' => 0, 'pdf' => array() );
	foreach ( (array) $wpdb->get_results( "SELECT post_mime_type AS mime, COUNT(*) AS n FROM {$prefix}posts WHERE post_type = 'attachment' GROUP BY post_mime_type", ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( in_array( $row['mime'], $docs, true ) ) {
			$stats['documents'] += (int) $row['n'];
		} elseif ( str_starts_with( (string) $row['mime'], 'image/' ) ) {
			$stats['images'] += (int) $row['n'];
		} else {
			$stats['other'] += (int) $row['n'];
		}
	}
	$methods = $wpdb->get_results(
		"SELECT COALESCE(m.meta_value, 'pending') AS method, COUNT(*) AS n FROM {$prefix}posts p
		LEFT JOIN {$prefix}postmeta m ON m.post_id = p.ID AND m.meta_key = '_tmc_doc_text_method'
		WHERE p.post_type = 'attachment' AND p.post_mime_type = 'application/pdf' GROUP BY method", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		ARRAY_A
	);
	foreach ( (array) $methods as $row ) {
		$stats['pdf'][ $row['method'] ] = (int) $row['n'];
	}

	switch_to_blog( $blog_id );
	$uploads = wp_upload_dir( null, false );
	// The main site's upload folder also holds the other sites' folders (uploads/sites/N).
	$bytes          = recurse_dirsize( $uploads['basedir'], is_main_site() ? $uploads['basedir'] . '/sites' : null );
	$stats['bytes'] = (int) $bytes;
	restore_current_blog();
	return $stats;
}

/** Statistics of all sites (cached for an hour). */
function tmc_media_network_stats( $refresh = false ) {
	$stats = $refresh ? false : get_site_transient( 'tmc_media_stats' );
	if ( ! is_array( $stats ) ) {
		$stats = array( 'generated' => time(), 'sites' => array() );
		foreach ( tmc_media_site_ids() as $blog_id ) {
			$stats['sites'][ $blog_id ] = tmc_media_site_stats( $blog_id );
		}
		set_site_transient( 'tmc_media_stats', $stats, HOUR_IN_SECONDS );
	}
	return $stats;
}

/**
 * Attachments of every site, newest first: all (recent uploads) or matching a title / file name.
 *
 * @param string $search Title or file-name fragment ('' = no filter).
 * @param string $kind   all | documents | images.
 * @return array<int, array{blog_id:int,ID:int,post_title:string,post_date_gmt:string,post_mime_type:string,file:string}>
 */
function tmc_media_find( $search = '', $kind = 'all', $limit = 25 ) {
	global $wpdb;
	$limit = max( 1, min( 200, (int) $limit ) );
	$rows  = array();
	foreach ( tmc_media_site_ids() as $blog_id ) {
		$prefix = $wpdb->get_blog_prefix( $blog_id );
		$sql    = "SELECT p.ID, p.post_title, p.post_date_gmt, p.post_mime_type, COALESCE(f.meta_value, '') AS file
			FROM {$prefix}posts p LEFT JOIN {$prefix}postmeta f ON f.post_id = p.ID AND f.meta_key = '_wp_attached_file'
			WHERE p.post_type = 'attachment'";
		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$sql .= $wpdb->prepare( ' AND ( p.post_title LIKE %s OR f.meta_value LIKE %s )', $like, $like );
		}
		if ( 'documents' === $kind ) {
			$mimes = array_values( tmc_document_mime_types() );
			$sql  .= $wpdb->prepare( ' AND p.post_mime_type IN (' . implode( ',', array_fill( 0, count( $mimes ), '%s' ) ) . ')', $mimes ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.NotPrepared
		} elseif ( 'images' === $kind ) {
			$sql .= $wpdb->prepare( ' AND p.post_mime_type LIKE %s', 'image/%' );
		}
		$sql .= ' ORDER BY p.post_date_gmt DESC LIMIT ' . $limit;
		foreach ( (array) $wpdb->get_results( $sql, ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared fragments, integer limit
			$rows[] = array( 'blog_id' => $blog_id, 'ID' => (int) $row['ID'] ) + $row;
		}
	}
	usort( $rows, fn( $a, $b ) => strcmp( $b['post_date_gmt'], $a['post_date_gmt'] ) );
	return array_slice( $rows, 0, $limit );
}

/* ---------------------------------------------------------------- actions */

add_action( 'network_admin_edit_tmc_media_refresh', 'tmc_media_refresh_action' );
function tmc_media_refresh_action() {
	check_admin_referer( 'tmc_media_refresh' );
	if ( ! current_user_can( 'manage_network_options' ) ) {
		wp_die( 'You are not allowed to do this.', '', array( 'response' => 403 ) );
	}
	tmc_media_network_stats( true );
	wp_safe_redirect( add_query_arg( array( 'page' => 'tmc-media', 'refreshed' => 1 ), network_admin_url( 'admin.php' ) ) );
	exit;
}

add_action( 'network_admin_edit_tmc_media_extract', 'tmc_media_extract_action' );
function tmc_media_extract_action() {
	check_admin_referer( 'tmc_media_extract' );
	if ( ! current_user_can( 'manage_network_options' ) ) {
		wp_die( 'You are not allowed to do this.', '', array( 'response' => 403 ) );
	}
	$budget   = 20;
	$done     = 0;
	$deadline = time() + 45;
	if ( tmc_pdftotext_binary() ) {
		foreach ( tmc_media_site_ids() as $blog_id ) {
			if ( $budget <= 0 || time() > $deadline ) {
				break;
			}
			switch_to_blog( $blog_id );
			foreach ( tmc_documents_needing_text( false, $budget ) as $attachment_id ) {
				tmc_document_extract( $attachment_id );
				tmc_search_index_post( $attachment_id );
				--$budget;
				++$done;
				if ( time() > $deadline ) {
					break;
				}
			}
			restore_current_blog();
		}
	}
	if ( function_exists( 'tmc_audit' ) ) {
		tmc_audit( 'documents_text_extracted', array( 'object_type' => 'media', 'object_title' => 'Media & documents', 'details' => array( 'documents' => $done ) ) );
	}
	tmc_media_network_stats( true );
	wp_safe_redirect( add_query_arg( array( 'page' => 'tmc-media', 'extracted' => $done ), network_admin_url( 'admin.php' ) ) );
	exit;
}

/* ---------------------------------------------------------------- screen */

function tmc_media_overview_page() {
	if ( ! current_user_can( 'manage_network_options' ) ) {
		wp_die( 'You are not allowed to view this page.', '', array( 'response' => 403 ) );
	}
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen parameters
	$tab    = sanitize_key( wp_unslash( $_GET['tab'] ?? 'overview' ) );
	$tab    = in_array( $tab, array( 'overview', 'recent', 'find' ), true ) ? $tab : 'overview';
	$search = mb_substr( sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) ), 0, 100 );
	$kind   = sanitize_key( wp_unslash( $_GET['kind'] ?? 'all' ) );
	$kind   = in_array( $kind, array( 'all', 'documents', 'images' ), true ) ? $kind : 'all';
	$notice = '';
	if ( isset( $_GET['refreshed'] ) ) {
		$notice = 'Statistics refreshed.';
	} elseif ( isset( $_GET['extracted'] ) ) {
		$notice = sprintf( 'Text extracted from %d document(s).', absint( $_GET['extracted'] ) );
	}
	// phpcs:enable
	$base = network_admin_url( 'admin.php?page=tmc-media' );
	$tabs = array( 'overview' => 'Overview', 'recent' => 'Recent uploads', 'find' => 'Find a file' );

	echo '<div class="wrap"><h1>Media &amp; documents</h1>';
	if ( $notice ) {
		printf( '<div class="notice notice-success"><p>%s</p></div>', esc_html( $notice ) );
	}
	echo '<nav class="nav-tab-wrapper" aria-label="Media and documents sections">';
	foreach ( $tabs as $slug => $label ) {
		printf(
			'<a href="%s" class="nav-tab%s"%s>%s</a>',
			esc_url( add_query_arg( 'tab', $slug, $base ) ),
			$tab === $slug ? ' nav-tab-active' : '',
			$tab === $slug ? ' aria-current="page"' : '',
			esc_html( $label )
		);
	}
	echo '</nav>';

	if ( 'overview' === $tab ) {
		tmc_media_render_overview();
	} elseif ( 'recent' === $tab ) {
		echo '<h2>Recent uploads on all websites</h2>';
		tmc_media_render_files( tmc_media_find( '', 'all', 25 ), 'The 25 most recent uploads across all websites' );
	} else {
		tmc_media_render_find( $search, $kind );
	}
	echo '</div>';
}

function tmc_media_render_overview() {
	$stats  = tmc_media_network_stats();
	$totals = array( 'documents' => 0, 'images' => 0, 'other' => 0, 'bytes' => 0 );
	$labels = array(
		'pdftotext' => 'full text',
		'basic'     => 'basic reader',
		'none'      => 'no text',
		'failed'    => 'unreadable',
		'missing'   => 'file missing',
		'pending'   => 'not processed',
	);
	echo '<table class="widefat striped"><caption class="screen-reader-text">Media and documents by website</caption><thead><tr>';
	foreach ( array( 'Website', 'Documents', 'Images', 'Other files', 'Disk space', 'PDF text', 'Manage' ) as $heading ) {
		echo '<th scope="col">' . esc_html( $heading ) . '</th>';
	}
	echo '</tr></thead><tbody>';
	foreach ( $stats['sites'] as $blog_id => $site ) {
		foreach ( $totals as $key => $value ) {
			$totals[ $key ] = $value + $site[ $key ];
		}
		$pdf = array();
		foreach ( $site['pdf'] as $method => $count ) {
			$pdf[] = sprintf( '%s: %s', $labels[ $method ] ?? $method, number_format_i18n( $count ) );
		}
		printf(
			'<tr><th scope="row"><strong>%s</strong><br><span class="description">%s</span></th><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td><a href="%s">Media library</a> | <a href="%s">Document types</a> | <a href="%s">Documents page</a></td></tr>',
			esc_html( get_blog_option( $blog_id, 'blogname' ) ),
			esc_html( wp_parse_url( get_home_url( $blog_id ), PHP_URL_HOST ) ),
			esc_html( number_format_i18n( $site['documents'] ) ),
			esc_html( number_format_i18n( $site['images'] ) ),
			esc_html( number_format_i18n( $site['other'] ) ),
			esc_html( size_format( $site['bytes'], 1 ) ? size_format( $site['bytes'], 1 ) : '0 B' ),
			esc_html( $pdf ? implode( ', ', $pdf ) : '—' ),
			esc_url( get_admin_url( $blog_id, 'upload.php?mode=list' ) ),
			esc_url( get_admin_url( $blog_id, 'edit-tags.php?taxonomy=tmc_doc_type&post_type=attachment' ) ),
			esc_url( get_home_url( $blog_id, '/documents/' ) )
		);
	}
	printf(
		'</tbody><tfoot><tr><th scope="row">All websites</th><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td></td><td></td></tr></tfoot></table>',
		esc_html( number_format_i18n( $totals['documents'] ) ),
		esc_html( number_format_i18n( $totals['images'] ) ),
		esc_html( number_format_i18n( $totals['other'] ) ),
		esc_html( size_format( $totals['bytes'], 1 ) ? size_format( $totals['bytes'], 1 ) : '0 B' )
	);

	printf( '<p class="description">Statistics from %s (cached for one hour).</p>', esc_html( wp_date( 'd/m/Y, h:i A', (int) $stats['generated'] ) ) );
	echo '<form method="post" action="' . esc_url( network_admin_url( 'edit.php?action=tmc_media_refresh' ) ) . '" style="display:inline-block;margin-right:1em">';
	wp_nonce_field( 'tmc_media_refresh' );
	submit_button( 'Refresh statistics', 'secondary', 'submit', false );
	echo '</form>';

	echo '<h2>Text of PDF documents (site search)</h2>';
	echo '<p>Words inside PDF files are searchable once their text is extracted. "Basic reader" means the text was read without pdftotext (for example by a data migration); extracting again with pdftotext gives complete text. "No text" usually means a scanned PDF, which needs OCR before upload.</p>';
	if ( tmc_pdftotext_binary() ) {
		echo '<form method="post" action="' . esc_url( network_admin_url( 'edit.php?action=tmc_media_extract' ) ) . '">';
		wp_nonce_field( 'tmc_media_extract' );
		submit_button( 'Extract text now (up to 20 documents)', 'primary', 'submit', false );
		echo '</form>';
	} else {
		echo '<p><strong>pdftotext is not available on this server</strong>, so only the basic reader can be used. It is installed in the TMC WordPress image (poppler-utils).</p>';
	}
}

function tmc_media_render_find( $search, $kind ) {
	echo '<h2>Find a file on any website</h2>';
	echo '<form method="get" action="' . esc_url( network_admin_url( 'admin.php' ) ) . '" class="search-form">';
	echo '<input type="hidden" name="page" value="tmc-media"><input type="hidden" name="tab" value="find">';
	printf( '<p><label for="tmc-media-q">Title or file name</label><br><input type="search" id="tmc-media-q" name="q" value="%s" class="regular-text" maxlength="100"></p>', esc_attr( $search ) );
	echo '<p><label for="tmc-media-kind">Show</label><br><select id="tmc-media-kind" name="kind">';
	foreach ( array( 'all' => 'All files', 'documents' => 'Documents only', 'images' => 'Images only' ) as $value => $label ) {
		printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $kind, $value, false ), esc_html( $label ) );
	}
	echo '</select></p>';
	submit_button( 'Search all websites', 'primary', '', false );
	echo '</form>';
	if ( '' !== $search ) {
		$files = tmc_media_find( $search, $kind, 50 );
		printf( '<p role="status">%s</p>', esc_html( sprintf( '%d file(s) found for "%s"%s.', count( $files ), $search, 50 === count( $files ) ? ' (first 50 shown)' : '' ) ) );
		tmc_media_render_files( $files, 'Files matching the search' );
	}
}

/** Table of files from tmc_media_find(). */
function tmc_media_render_files( array $files, $caption ) {
	if ( ! $files ) {
		echo '<p>No files found.</p>';
		return;
	}
	// Resolve URLs and sizes per site in one switch each.
	$details = array();
	foreach ( array_unique( wp_list_pluck( $files, 'blog_id' ) ) as $blog_id ) {
		switch_to_blog( $blog_id );
		foreach ( $files as $file ) {
			if ( $file['blog_id'] === $blog_id ) {
				$info                                   = tmc_document_file_info( $file['ID'] );
				$details[ $blog_id . ':' . $file['ID'] ] = array( wp_get_attachment_url( $file['ID'] ), $info['ext'], $info['size'] );
			}
		}
		restore_current_blog();
	}
	printf( '<table class="widefat striped"><caption class="screen-reader-text">%s</caption><thead><tr>', esc_html( $caption ) );
	foreach ( array( 'File', 'Website', 'Type', 'Size', 'Uploaded', 'Actions' ) as $heading ) {
		echo '<th scope="col">' . esc_html( $heading ) . '</th>';
	}
	echo '</tr></thead><tbody>';
	foreach ( $files as $file ) {
		list( $url, $ext, $size ) = $details[ $file['blog_id'] . ':' . $file['ID'] ];
		$title                    = '' !== $file['post_title'] ? $file['post_title'] : '(no title)';
		printf(
			'<tr><th scope="row"><a href="%s"><strong>%s</strong></a><br><span class="description">%s</span></th><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td><a href="%s">Edit</a> | <a href="%s">View file<span class="screen-reader-text"> %s</span></a></td></tr>',
			esc_url( get_admin_url( $file['blog_id'], 'post.php?post=' . $file['ID'] . '&action=edit' ) ),
			esc_html( $title ),
			esc_html( wp_basename( $file['file'] ) ),
			esc_html( get_blog_option( $file['blog_id'], 'blogname' ) ),
			esc_html( $ext ? $ext : $file['post_mime_type'] ),
			esc_html( $size ? $size : '—' ),
			esc_html( wp_date( 'd/m/Y', strtotime( $file['post_date_gmt'] . ' UTC' ) ) ),
			esc_url( get_admin_url( $file['blog_id'], 'post.php?post=' . $file['ID'] . '&action=edit' ) ),
			esc_url( (string) $url ),
			esc_html( $title )
		);
	}
	echo '</tbody></table>';
}
