<?php
/**
 * Site search and document library (R-4.12-5, R-4.6-6): PDF text extraction and indexing, ranking,
 * type facets, language, visibility of drafts/private content, pagination, suggestion endpoint
 * (cache, validation, rate limit, no private data), document types and dates, Documents listing and
 * block, network media overview, index rebuild. Creates its own fixtures and removes them afterwards.
 *
 *   docker compose run --rm -T wpcli --url=tmh.<base> eval-file - < scripts/tests/search-test.php
 */

global $wpdb;
$pass        = 0;
$fail        = 0;
$cleanup     = array(); // posts
$attachments = array();
$t           = function ( $label, $ok ) use ( &$pass, &$fail ) {
	WP_CLI::log( ( $ok ? '  PASS  ' : '  FAIL  ' ) . $label );
	$ok ? $pass++ : $fail++;
};
$tag   = strtolower( wp_generate_password( 6, false ) );
$word  = 'zephyr' . $tag;   // only inside the PDF text
$word2 = 'quokka' . $tag;   // ranking, facets, language, visibility
$word3 = 'pangolin' . $tag; // pagination
$word4 = 'narwhal' . $tag;  // document of a draft

$make = function ( $type, $title, $content = '', $lang = 'en', array $extra = array() ) use ( &$cleanup ) {
	$id = wp_insert_post( array_merge( array( 'post_type' => $type, 'post_status' => 'publish', 'post_title' => $title, 'post_content' => $content ), $extra ), true );
	if ( is_wp_error( $id ) ) {
		WP_CLI::error( $id->get_error_message() );
	}
	if ( $lang ) {
		pll_set_post_language( $id, $lang );
	}
	$cleanup[] = $id;
	return $id;
};

/** Minimal one-page PDF (Helvetica) with the given lines; optionally FlateDecode-compressed. */
$pdf = function ( array $lines, $compress = false ) {
	$stream = "BT /F1 12 Tf 72 770 Td 16 TL\n";
	foreach ( $lines as $line ) {
		$stream .= '(' . str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), $line ) . ") Tj T*\n";
	}
	$stream .= 'ET';
	$filter  = '';
	if ( $compress ) {
		$stream = gzcompress( $stream );
		$filter = ' /Filter /FlateDecode';
	}
	$objects = array(
		'<< /Type /Catalog /Pages 2 0 R >>',
		'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
		'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
		'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
		'<< /Length ' . strlen( $stream ) . $filter . " >>\nstream\n" . $stream . "\nendstream",
	);
	$out     = "%PDF-1.4\n";
	$offsets = array();
	foreach ( $objects as $i => $object ) {
		$offsets[] = strlen( $out );
		$out      .= ( $i + 1 ) . " 0 obj\n" . $object . "\nendobj\n";
	}
	$xref = strlen( $out );
	$out .= "xref\n0 " . ( count( $objects ) + 1 ) . "\n0000000000 65535 f \n";
	foreach ( $offsets as $offset ) {
		$out .= sprintf( "%010d 00000 n \n", $offset );
	}
	return $out . 'trailer << /Size ' . ( count( $objects ) + 1 ) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
};

$attach = function ( $filename, $title, $bytes, $parent = 0 ) use ( &$attachments ) {
	$upload = wp_upload_bits( $filename, null, $bytes );
	if ( ! empty( $upload['error'] ) ) {
		WP_CLI::error( $upload['error'] );
	}
	$id = wp_insert_attachment( array( 'post_title' => $title, 'post_mime_type' => 'application/pdf', 'post_status' => 'inherit' ), $upload['file'], $parent, true );
	if ( is_wp_error( $id ) ) {
		WP_CLI::error( $id->get_error_message() );
	}
	$attachments[] = $id;
	return $id;
};
$search   = fn( $q, $lang = 'en', $type = '', $page = 1 ) => tmc_search_query( array( 'q' => $q, 'lang' => $lang, 'type' => $type, 'page' => $page ) );
$suggest  = function ( array $params ) {
	$request = new WP_REST_Request( 'GET', '/tmc/v1/suggest' );
	$request->set_query_params( $params );
	return rest_do_request( $request );
};
$in_index = fn( $id ) => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . tmc_search_table() . ' WHERE post_id = %d', $id ) ) > 0;

WP_CLI::log( '— Setup on this site' );
$t( 'search index table exists', tmc_search_table() === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', tmc_search_table() ) ) );
$t( 'extracted-text table exists', tmc_document_text_table() === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', tmc_document_text_table() ) ) );
$applied = (array) get_option( 'tmc_migrations' );
$t( 'migrations 010 and 011 recorded', in_array( '010-search-index', $applied, true ) && in_array( '011-documents-page', $applied, true ) );
$t( 'the 8 standard document types exist', 8 === count( array_filter( array_keys( tmc_document_types() ), fn( $slug ) => (bool) term_exists( $slug, 'tmc_doc_type' ) ) ) );
$documents_page = get_page_by_path( 'documents' );
$t( 'Documents page published with the library block', $documents_page && 'publish' === $documents_page->post_status && has_block( 'tmc/documents', $documents_page ) );
$quick_menu = wp_get_nav_menu_object( 'Footer quick links (English)' );
$t( 'Documents page linked from the footer quick links', $quick_menu && $documents_page && in_array( (int) $documents_page->ID, array_map( 'intval', wp_list_pluck( (array) wp_get_nav_menu_items( $quick_menu->term_id ), 'object_id' ) ), true ) );
$t( 'tmc/documents block registered', WP_Block_Type_Registry::get_instance()->is_registered( 'tmc/documents' ) );

WP_CLI::log( '— Text extraction from PDFs' );
$doc    = $attach( "circular-$tag.pdf", "Test circular $tag", $pdf( array( 'Office circular', "Circular about $word parking arrangements" ) ) );
$method = (string) get_post_meta( $doc, '_tmc_doc_text_method', true );
$t( "PDF text extracted on upload (method: $method)", false !== strpos( tmc_document_get_text( $doc ), $word ) && in_array( $method, array( 'pdftotext', 'basic' ), true ) );
$t( 'character count stored', (int) get_post_meta( $doc, '_tmc_doc_text_chars', true ) === mb_strlen( tmc_document_get_text( $doc ) ) );
$flate = wp_upload_bits( "flate-$tag.pdf", null, $pdf( array( "Compressed flate$tag stream" ), true ) );
$t( 'built-in reader handles FlateDecode-compressed PDFs', empty( $flate['error'] ) && false !== strpos( tmc_pdf_text_basic( $flate['file'] ), "flate$tag" ) );
if ( ! empty( $flate['file'] ) && file_exists( $flate['file'] ) ) {
	wp_delete_file( $flate['file'] );
}
$t( 'new document gets its upload date as document date', tmc_document_date( $doc ) === substr( (string) get_post_field( 'post_date', $doc ), 0, 10 ) && '' !== (string) get_post_meta( $doc, '_tmc_doc_date', true ) );
$t( 'new unattached document gets type "Other"', 'other' === ( tmc_document_type( $doc )->slug ?? '' ) );

WP_CLI::log( '— Documents in search' );
$r = $search( $word );
$t( 'unique word inside the PDF finds the document (and only it)', in_array( $doc, $r['ids'], true ) && 1 === $r['total'] );
$t( 'documents are found in every language (Hindi too)', in_array( $doc, $search( $word, 'hi' )['ids'], true ) );
$t( 'snippet highlights the matched word', false !== strpos( tmc_search_highlight( $r['snippets'][ $doc ] ?? '', $r['terms'] ), '<mark>' . $word . '</mark>' ) );

WP_CLI::log( '— Ranking, facets, language' );
$title_page = $make( 'page', "Parking $word2 guide", '<!-- wp:paragraph --><p>General text.</p><!-- /wp:paragraph -->', 'en', array( 'post_date' => '2020-01-01 10:00:00' ) );
$body_page  = $make( 'page', "General information $tag", "<!-- wp:paragraph --><p>Details about $word2 here.</p><!-- /wp:paragraph -->" );
$tender     = $make( 'tmc_tender', "Test tender $tag", "<p>Supply related to $word2.</p>" );
update_post_meta( $tender, '_tmc_ref_no', "REF/$tag/9" );
$hindi_post = $make( 'post', "परीक्षण $word2 सूचना", '', 'hi' );
$r          = $search( $word2 );
$t( 'title match ranks first even when older', $title_page === ( $r['ids'][0] ?? 0 ) && in_array( $body_page, $r['ids'], true ) );
$t( 'facet counts per type (pages 2, tenders 1)', 2 === ( $r['facets']['page'] ?? 0 ) && 1 === ( $r['facets']['tmc_tender'] ?? 0 ) && 3 === $r['total'] );
$pages_only = $search( $word2, 'en', 'page' );
$t( 'type filter: pages only', 2 === $pages_only['total'] && ! array_diff( $pages_only['ids'], array( $title_page, $body_page ) ) );
$t( 'type filter: tenders only', array( $tender ) === $search( $word2, 'en', 'tmc_tender' )['ids'] );
$t( 'type filter: documents (none match)', 0 === $search( $word2, 'en', 'attachment' )['total'] );
$t( 'unknown type is ignored (all types)', '' === $search( $word2, 'en', 'no_such_type' )['type'] );
$t( 'Hindi content is not in English results', ! in_array( $hindi_post, $r['ids'], true ) );
$hindi = $search( $word2, 'hi' );
$t( 'Hindi results contain Hindi content only', array( $hindi_post ) === $hindi['ids'] );
$t( 'structured field (tender reference number) is searchable', in_array( $tender, $search( "REF/$tag/9" )['ids'], true ) );
$t( 'highlighting escapes markup and keeps entities intact', '&lt;b&gt;Tom &amp; Jerry&lt;/b&gt; <mark>amp</mark>' === tmc_search_highlight( '<b>Tom & Jerry</b> amp', array( 'amp' ) ) && false === strpos( tmc_search_highlight( '<script>alert(1)</script>', array( 'alert' ) ), '<script>' ) );

WP_CLI::log( '— Only published content' );
$draft     = $make( 'page', "Draft $word2 page", '', 'en', array( 'post_status' => 'draft' ) );
$private   = $make( 'page', "Private $word2 page", '', 'en', array( 'post_status' => 'private' ) );
$protected = $make( 'page', "Protected $word2 page", '', 'en', array( 'post_password' => 'secret' ) );
$ids       = $search( $word2 )['ids'];
$t( 'drafts, private and password-protected pages are not searchable', ! array_intersect( array( $draft, $private, $protected ), $ids ) && ! $in_index( $draft ) && ! $in_index( $private ) && ! $in_index( $protected ) );
wp_update_post( array( 'ID' => $body_page, 'post_status' => 'draft' ) );
$t( 'unpublishing removes a page from search', ! in_array( $body_page, $search( $word2 )['ids'], true ) );
wp_update_post( array( 'ID' => $body_page, 'post_status' => 'publish' ) );
$t( 're-publishing adds it again', in_array( $body_page, $search( $word2 )['ids'], true ) );
$draft_parent = $make( 'page', "Parent $tag", '', 'en', array( 'post_status' => 'draft' ) );
$draft_doc    = $attach( "draft-doc-$tag.pdf", "Draft document $tag", $pdf( array( "Annex $word4" ) ), $draft_parent );
$t( 'document of a draft page is not searchable', 0 === $search( $word4 )['total'] );
wp_update_post( array( 'ID' => $draft_parent, 'post_status' => 'publish' ) );
$t( 'publishing the page makes its document searchable', in_array( $draft_doc, $search( $word4 )['ids'], true ) );

WP_CLI::log( '— Pagination' );
for ( $n = 1; $n <= 12; $n++ ) {
	$make( 'post', "Pangolin $word3 item $n" );
}
$page1 = new WP_Query( array( 's' => $word3, 'tmc_search' => true, 'tmc_search_lang' => 'en', 'posts_per_page' => 10, 'paged' => 1 ) );
$page2 = new WP_Query( array( 's' => $word3, 'tmc_search' => true, 'tmc_search_lang' => 'en', 'posts_per_page' => 10, 'paged' => 2 ) );
$t( 'page 1: 10 of 12 results, 2 pages', 10 === count( $page1->posts ) && 12 === (int) $page1->found_posts && 2 === (int) $page1->max_num_pages );
$t( 'page 2: remaining 2 results, no overlap', 2 === count( $page2->posts ) && ! array_intersect( wp_list_pluck( $page1->posts, 'ID' ), wp_list_pluck( $page2->posts, 'ID' ) ) );
$t( 'results are WP_Post objects of the matching items', $page1->posts && ! array_filter( $page1->posts, fn( $p ) => ! $p instanceof WP_Post || false === strpos( $p->post_title, $word3 ) ) );

WP_CLI::log( '— Suggestions endpoint' );
$response = $suggest( array( 'q' => $word2, 'lang' => 'en' ) );
$data     = $response->get_data();
$items    = $data['items'] ?? array();
$t( 'GET /tmc/v1/suggest returns 200 (cache ' . ( $response->get_headers()['X-TMC-Cache'] ?? '?' ) . ')', 200 === $response->get_status() );
$t( 'suggests the published page whose title matches (only)', 1 === count( $items ) && "Parking $word2 guide" === ( $items[0]['title'] ?? '' ) && get_permalink( $title_page ) === ( $items[0]['url'] ?? '' ) && tmc_search_type_label( 'page' ) === ( $items[0]['type'] ?? '' ) );
$keys_ok = (bool) $items;
foreach ( $items as $item ) {
	$keys = array_keys( $item );
	sort( $keys );
	$keys_ok = $keys_ok && array( 'title', 'type', 'url' ) === $keys;
}
$t( 'each suggestion has exactly title, url, type', $keys_ok );
$t( 'no drafts, private items or internal fields in suggestions', ! preg_match( '/Draft|Private|Protected|"(id|ID|author|body|content|post_status)"/', (string) wp_json_encode( $data ) ) );
$t( 'second identical request is served from cache', 'hit' === ( $suggest( array( 'q' => $word2, 'lang' => 'en' ) )->get_headers()['X-TMC-Cache'] ?? '' ) );
$doc_items = $suggest( array( 'q' => "circular $tag", 'lang' => 'en' ) )->get_data()['items'] ?? array();
$t( 'documents are suggested with their file URL and type', 1 === count( $doc_items ) && wp_get_attachment_url( $doc ) === $doc_items[0]['url'] && tmc_search_type_label( 'attachment' ) === $doc_items[0]['type'] );
$t( 'fewer than 2 characters → 400', 400 === $suggest( array( 'q' => 'a' ) )->get_status() );
$t( 'missing q → 400', 400 === $suggest( array() )->get_status() );
$t( 'more than 100 characters → 400', 400 === $suggest( array( 'q' => str_repeat( 'x', 101 ) ) )->get_status() );
$ip_before              = $_SERVER['REMOTE_ADDR'] ?? null;
$_SERVER['REMOTE_ADDR'] = '192.0.2.' . wp_rand( 10, 250 );
$limit                  = fn() => 3;
add_filter( 'tmc_suggest_rate_limit', $limit );
$statuses = array();
$last     = null;
for ( $n = 0; $n < 4; $n++ ) {
	$last       = $suggest( array( 'q' => $word2, 'lang' => 'en' ) );
	$statuses[] = $last->get_status();
}
$t( 'rate limit per IP: 4th request within the window → 429 (' . implode( ',', $statuses ) . ')', array( 200, 200, 200, 429 ) === $statuses && (int) ( $last->get_headers()['Retry-After'] ?? 0 ) > 0 );
remove_filter( 'tmc_suggest_rate_limit', $limit );
delete_transient( 'tmc_rl_suggest_' . md5( $_SERVER['REMOTE_ADDR'] ) );
delete_transient( 'tmc_rl_suggest_' . md5( (string) $ip_before ) );
if ( null === $ip_before ) {
	unset( $_SERVER['REMOTE_ADDR'] );
} else {
	$_SERVER['REMOTE_ADDR'] = $ip_before;
}

WP_CLI::log( '— Document types, dates and the Documents listing' );
$admins = get_super_admins();
$admin  = $admins ? get_user_by( 'login', $admins[0] ) : false;
wp_set_current_user( $admin ? $admin->ID : 0 );
apply_filters( 'attachment_fields_to_save', get_post( $doc, ARRAY_A ), array( 'tmc_doctype' => 'circular', 'tmc_docdate' => '2019-05-01' ) );
$t( 'type and date saved from the media fields', 'circular' === ( tmc_document_type( $doc )->slug ?? '' ) && '2019-05-01' === tmc_document_date( $doc ) );
apply_filters( 'attachment_fields_to_save', get_post( $doc, ARRAY_A ), array( 'tmc_doctype' => 'bogus-type', 'tmc_docdate' => '2019-02-30' ) );
$t( 'unknown type and invalid date are rejected (no new term)', 'circular' === ( tmc_document_type( $doc )->slug ?? '' ) && '2019-05-01' === tmc_document_date( $doc ) && ! term_exists( 'bogus-type', 'tmc_doc_type' ) );
wp_set_current_user( 0 );
$listed = fn( array $args ) => wp_list_pluck( tmc_documents_query( $args + array( 'per_page' => 100 ) )->posts, 'ID' );
$t( 'listing filtered by type includes the circular', in_array( $doc, $listed( array( 'type' => 'circular' ) ), true ) );
$t( 'listing filtered by another type excludes it', ! in_array( $doc, $listed( array( 'type' => 'form' ) ), true ) );
$t( 'year filter 2019 includes it, 2020 excludes it', in_array( $doc, $listed( array( 'year' => 2019 ) ), true ) && ! in_array( $doc, $listed( array( 'year' => 2020 ) ), true ) );
$t( '2019 offered in the year filter', in_array( 2019, tmc_documents_years(), true ) );
$hidden_parent = $make( 'page', "Hidden parent $tag", '', 'en', array( 'post_status' => 'draft' ) );
$hidden_doc    = $attach( "hidden-$tag.pdf", "Hidden document $tag", $pdf( array( 'Hidden' ) ), $hidden_parent );
$t( 'documents of unpublished pages are not listed', ! in_array( $hidden_doc, $listed( array() ), true ) && in_array( $doc, $listed( array() ), true ) );
$get_before = $_GET;
$_GET       = array( 'doc_type' => 'circular' );
$html       = tmc_render_documents_block( array( 'library' => true, 'count' => 20, 'type' => '' ) );
$_GET       = $get_before;
$info = tmc_document_file_info( $doc );
$t( 'library block: filters, live count, title, file type and size', false !== strpos( $html, 'doc-filter' ) && false !== strpos( $html, 'role="status"' ) && false !== strpos( $html, "Test circular $tag" ) && 'PDF' === $info['ext'] && '' !== $info['size'] && false !== strpos( $html, 'PDF, ' . $info['size'] ) );
$t( 'library block labels every filter control', 2 === substr_count( $html, '<label for="' ) && false === strpos( $html, "Hidden document $tag" ) );
$fixed = do_blocks( '<!-- wp:tmc/documents {"type":"circular","count":5} /-->' );
$t( 'fixed block (one type) lists the document without filters', false !== strpos( $fixed, "Test circular $tag" ) && false === strpos( $fixed, 'doc-filter' ) );
$tender_doc = $attach( "tender-doc-$tag.pdf", "Tender annexure $tag", $pdf( array( 'Annexure' ) ) );
update_post_meta( $tender, '_tmc_documents', array( $tender_doc ) );
$t( 'a document added to a tender becomes a "Tender document"', 'tender-document' === ( tmc_document_type( $tender_doc )->slug ?? '' ) );

WP_CLI::log( '— Network media overview' );
$stats = tmc_media_site_stats( get_current_blog_id() );
$t( 'site statistics count documents (' . $stats['documents'] . ') and disk space', $stats['documents'] >= 4 && is_int( $stats['bytes'] ) );
$found = tmc_media_find( $tag, 'documents', 50 );
$t( 'cross-site file search finds the documents by file name', in_array( $doc, wp_list_pluck( $found, 'ID' ), true ) && get_current_blog_id() === ( $found[0]['blog_id'] ?? 0 ) );
$t( 'cross-site search "images only" excludes them', ! array_intersect( $attachments, wp_list_pluck( tmc_media_find( $tag, 'images', 50 ), 'ID' ) ) );
$t( 'recent uploads include the newest document', in_array( $tender_doc, wp_list_pluck( tmc_media_find( '', 'all', 25 ), 'ID' ), true ) );

WP_CLI::log( '— Rebuild' );
$wpdb->insert( tmc_search_table(), array( 'post_id' => 999999999, 'post_type' => 'page', 'lang' => 'en', 'post_date' => current_time( 'mysql' ), 'title' => 'stale', 'body' => '', 'mime' => '' ) );
$count = tmc_search_rebuild();
$t( "rebuild indexes the site ($count items) and drops stale rows", $count > 0 && ! $in_index( 999999999 ) && $in_index( $title_page ) && $in_index( $doc ) );

WP_CLI::log( '— Cleanup' );
foreach ( $attachments as $id ) {
	wp_delete_attachment( $id, true );
}
foreach ( array_reverse( $cleanup ) as $id ) {
	wp_delete_post( $id, true );
}
$all_ids = array_merge( $attachments, $cleanup );
$left    = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . tmc_search_table() . ' WHERE post_id IN (' . implode( ',', array_map( 'intval', $all_ids ) ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$texts   = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . tmc_document_text_table() . ' WHERE post_id IN (' . implode( ',', array_map( 'intval', $attachments ) ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$t( 'deleted items leave the index and the text store', 0 === $left && 0 === $texts );

WP_CLI::log( '' );
$fail ? WP_CLI::error( "$fail failed, $pass passed" ) : WP_CLI::success( "all $pass checks passed" );
