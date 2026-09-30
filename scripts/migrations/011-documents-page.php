<?php
/**
 * 011 — Public Documents library page (/documents/, block tmc/documents in library mode) on
 * existing sites, linked from the English footer quick links. Fresh installs get the same from
 * seed-site-structure.php (the menus are created there, after migrations have run).
 */

$page_id = tmc_documents_ensure_page();
if ( ! $page_id ) {
	WP_CLI::warning( 'could not create the Documents page on ' . home_url() );
	return false;
}
if ( tmc_documents_ensure_menu_item( $page_id ) ) {
	WP_CLI::log( '    footer quick links: Documents added' );
}
return true;
