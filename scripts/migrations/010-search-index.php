<?php
/**
 * 010 — Site search across content and documents goes live on existing sites (R-4.12-5, R-4.6-6).
 *
 *   1. Create the search-index and extracted-text tables and the standard document types.
 *   2. Give every existing document a document date and a type (Tender document for files listed
 *      in a tender's Documents field, otherwise Other).
 *   3. Extract the text of every document that was never processed. This runs in the WP-CLI
 *      container: pdftotext is used if present, otherwise the built-in reader; Network Admin →
 *      Media & documents → "Extract text now" upgrades those to full pdftotext text.
 *   4. Build the search index.
 */

tmc_search_install();
tmc_document_text_install();
tmc_document_types_install();

$typed = tmc_documents_backfill();

$tenders = get_posts(
	array(
		'post_type'      => 'tmc_tender',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'lang'           => '',
		'meta_key'       => '_tmc_documents', // phpcs:ignore WordPress.DB.SlowDBQuery
	)
);
foreach ( $tenders as $tender_id ) {
	tmc_document_tender_link( 0, $tender_id, '_tmc_documents', get_post_meta( $tender_id, '_tmc_documents', true ) );
}

$extracted = 0;
foreach ( tmc_documents_needing_text( false, 100000 ) as $attachment_id ) {
	tmc_document_extract( $attachment_id );
	++$extracted;
}

$indexed = tmc_search_rebuild();
WP_CLI::log( "    documents dated/typed: $typed, text extracted: $extracted, items indexed: $indexed" );

return true;
