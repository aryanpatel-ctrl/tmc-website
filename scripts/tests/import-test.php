<?php
/**
 * Content migration toolkit (tender §4.11): the CSV inventory importer, dry run and real run on a
 * small inventory of its own — parent/child pages, an English/Hindi pair, a job opening with
 * structured fields, a news post, a document, rows for another site and invalid rows. Checks
 * templates/types/parents, translations, 301 redirects from old URLs, link rewriting, the report,
 * idempotency and protection of edited content. Removes everything it created.
 *
 *   docker compose run --rm -T wpcli --url=tmh.<base> eval-file - < scripts/tests/import-test.php
 */

global $wpdb;
$pass = 0;
$fail = 0;
$t    = function ( $label, $ok ) use ( &$pass, &$fail ) {
	WP_CLI::log( ( $ok ? '  PASS  ' : '  FAIL  ' ) . $label );
	$ok ? $pass++ : $fail++;
};
$scripts = getenv( 'TMC_SCRIPTS_DIR' ) ? getenv( 'TMC_SCRIPTS_DIR' ) : '/tmc-scripts';
require_once $scripts . '/import/class-tmc-inventory-importer.php';

$tag  = strtolower( wp_generate_password( 6, false ) );
$dir  = trailingslashit( get_temp_dir() ) . "tmc-import-test-$tag";
$site = TMC_Inventory_Importer::site_key();
$old  = "/old-site-$tag";
wp_mkdir_p( $dir . '/content' );
wp_mkdir_p( $dir . '/files' );
$first_audit = (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id),0) FROM ' . tmc_audit_table() );

// ---- fixtures: content files, a small PDF, two existing pages
file_put_contents(
	"$dir/content/parent.html",
	"<html><head><title>x</title></head><body><h2>About the unit</h2><p>Migrated text. See <a href=\"$old/child.html#staff\">the child page</a> and <a href=\"https://www.old-tmh.example$old/jobs/12\">the job</a>.</p><script>alert(1)</script></body></html>"
);
$pdf = "%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n2 0 obj << /Type /Pages /Kids [] /Count 0 >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
file_put_contents( "$dir/files/notice-$tag.pdf", $pdf . $tag );
file_put_contents( "$dir/files/evil.php", '<?php echo 1;' );

$placeholder = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => "Placeholder $tag", 'post_name' => "imp-$tag-placeholder", 'post_content' => '<!-- wp:paragraph {"className":"callout"} --><p class="callout">Detailed content for this page will be provided by Tata Memorial Centre.</p><!-- /wp:paragraph -->' ) );
$real        = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => "Real page $tag", 'post_name' => "imp-$tag-real", 'post_content' => '<p>Content written by an editor.</p>' ) );
if ( function_exists( 'pll_set_post_language' ) ) {
	pll_set_post_language( $placeholder, 'en' );
	pll_set_post_language( $real, 'en' );
}

// ---- the inventory (child listed before its parent on purpose)
$rows = array(
	array( 'site', 'language', 'old_url', 'type', 'template', 'parent_path', 'slug', 'title', 'date', 'status', 'excerpt', 'content_html_file', 'content_html', 'documents', 'categories', 'translation_key', 'field:tmc_ref_no', 'field:tmc_closing_at', 'field:tmc_vacancies', 'seo_description' ),
	array( $site, 'en', "http://www.old-tmh.example$old/child.html", 'page', '', "imp-$tag-parent", "imp-$tag-child", "Import child $tag", '', '', '', '', '<p>Child page text.</p>', '', '', '', '', '', '', '' ),
	array( $site, 'en', "$old/about.html", 'page', 'default', '', "imp-$tag-parent", "Import parent $tag", '2024-03-01', 'publish', 'Summary of the parent page.', 'content/parent.html', '', "files/notice-$tag.pdf|Notice $tag|$old/files/notice.pdf", '', "pair-$tag", '', '', '', "SEO description $tag" ),
	array( $site, 'hi', "$old/hi/about.html", 'page', '', '', "imp-$tag-parent-hi", "Import parent HI $tag", '', '', '', '', '<p>Hindi version (test).</p>', '', '', "pair-$tag", '', '', '', '' ),
	array( $site, 'en', "$old/jobs/12", 'tmc_job', '', '', "imp-$tag-job", "Import job $tag", '', '', '', '', '<p>Job details.</p>', '', '', '', "TEST/$tag/J", '31/12/2099 17:00', '3', '' ),
	array( $site, 'en', "$old/news/7", 'post', '', '', "imp-$tag-news", "Import news $tag", '15/08/2024', '', '', '', '<p>News text.</p>', '', 'news', '', '', '', '', '' ),
	array( $site, 'en', '', 'page', '', '', "imp-$tag-placeholder", "Filled placeholder $tag", '', '', '', '', '<p>Real content from TMC.</p>', '', '', '', '', '', '', '' ),
	array( $site, 'en', '', 'page', '', '', "imp-$tag-real", "Overwritten $tag", '', '', '', '', '<p>Importer text.</p>', '', '', '', '', '', '', '' ),
	array( 'some-other-site', 'en', "$old/other.html", 'page', '', '', "imp-$tag-other", "Other site $tag", '', '', '', '', '<p>x</p>', '', '', '', '', '', '', '' ),
	array( $site, 'en', "$old/bad-type", 'unknowntype', '', '', "imp-$tag-bad", "Bad type $tag", '', '', '', '', '', '', '', '', '', '', '', '' ),
	array( $site, 'en', "$old/orphan.html", 'page', '', "imp-$tag-missing-parent", "imp-$tag-orphan", "Orphan $tag", '', '', '', '', '<p>x</p>', '', '', '', '', '', '', '' ),
	array( $site, 'en', "$old/evil.html", 'page', '', '', "imp-$tag-evil", "Evil file $tag", '', '', '', '', '<p>x</p>', 'files/evil.php', '', '', '', '', '', '' ),
	array( $site, 'en', "$old/escape.html", 'page', '', '', "imp-$tag-escape", "Escape $tag", '', '', '', '../../etc/passwd', '', '', '', '', '', '', '', '' ),
);
$csv    = "$dir/inventory.csv";
$handle = fopen( $csv, 'w' );
foreach ( $rows as $row ) {
	fputcsv( $handle, $row, ',', '"', '' );
}
fclose( $handle );
$by_title = fn( array $report, $title ) => array_values( array_filter( $report, fn( $r ) => $r['title'] === $title ) )[0] ?? array();
$count_by_slug = fn( $slug ) => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_name = %s AND post_status NOT IN ('trash','inherit')", $slug ) );

WP_CLI::log( '— Dry run' );
$dry    = new TMC_Inventory_Importer( array( 'csv' => $csv, 'mode' => 'dry-run' ) );
$report = $dry->run();
$t( 'dry run completes', is_array( $report ) && 12 === count( $report ) );
$t( 'nothing created', 0 === $count_by_slug( "imp-$tag-parent" ) && 0 === $count_by_slug( "imp-$tag-job" ) && null === tmc_redirect_resolve( "$old/about.html" ) );
$t( 'no document added', 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_title = %s", "Notice $tag" ) ) );
$child = $by_title( $report, "Import child $tag" );
$t( 'child after its parent, predicted URL under the parent', 'would create' === ( $child['action'] ?? '' ) && "/imp-$tag-parent/imp-$tag-child/" === ( $child['new_url'] ?? '' ) );
$t( 'redirects and translations planned', 'would create' === ( $by_title( $report, "Import parent $tag" )['redirect'] ?? '' ) && 0 === strpos( $by_title( $report, "Import parent $tag" )['translation'] ?? '', 'would link' ) );
$t( 'Hindi row: /hi/ URL predicted', "/hi/imp-$tag-parent-hi/" === ( $by_title( $report, "Import parent HI $tag" )['new_url'] ?? '' ) );
$t( 'placeholder page will be filled, editor page protected', 'would update' === ( $by_title( $report, "Filled placeholder $tag" )['action'] ?? '' ) && 'skipped (exists)' === ( $by_title( $report, "Overwritten $tag" )['action'] ?? '' ) );
$t( 'other site skipped', 'skipped (other site)' === ( $by_title( $report, "Other site $tag" )['action'] ?? '' ) );
$errors = array_column( array_filter( $report, fn( $r ) => 'error' === $r['action'] ), 'title' );
sort( $errors );
$expect = array( "Bad type $tag", "Escape $tag", "Evil file $tag", "Orphan $tag" );
sort( $expect );
$t( 'invalid rows reported: unknown type, missing parent, disallowed file, path outside folder (' . implode( ', ', $errors ) . ')', $expect === $errors );
$t( 'link rewriting previewed', false !== strpos( implode( ' ', (array) ( $by_title( $report, "Import parent $tag" )['messages'] ?? array() ) ), 'would be updated' ) );

WP_CLI::log( '— Real run' );
$apply  = new TMC_Inventory_Importer( array( 'csv' => $csv, 'mode' => 'apply' ) );
$report = $apply->run();
$parent = get_page_by_path( "imp-$tag-parent" );
$childp = get_page_by_path( "imp-$tag-parent/imp-$tag-child" );
$hi     = get_page_by_path( "imp-$tag-parent-hi" );
$jobs   = get_posts( array( 'post_type' => 'tmc_job', 'name' => "imp-$tag-job", 'post_status' => 'any', 'lang' => '' ) );
$news   = get_posts( array( 'post_type' => 'post', 'name' => "imp-$tag-news", 'post_status' => 'any', 'lang' => '' ) );
$job    = $jobs[0] ?? null;
$post   = $news[0] ?? null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
$t( 'pages, job and news created', $parent && $childp && $hi && $job && $post );
$t( 'child placed under its parent (information architecture)', $childp && $parent && (int) $childp->post_parent === $parent->ID );
$t( 'content types mapped (page, tmc_job, post)', $parent && 'page' === $parent->post_type && $job && 'tmc_job' === $job->post_type && $post && 'post' === $post->post_type );
if ( function_exists( 'pll_get_post_language' ) && $hi && $parent ) {
	$t( 'languages set and EN/HI versions linked', 'hi' === pll_get_post_language( $hi->ID ) && 'en' === pll_get_post_language( $parent->ID ) && $hi->ID === (int) pll_get_post( $parent->ID, 'hi' ) );
}
$t( 'job fields: reference, closing date (DD/MM/YYYY), vacancies', $job && "TEST/$tag/J" === tmc_field( $job->ID, 'tmc_ref_no' ) && '2099-12-31 17:00:00' === tmc_field( $job->ID, 'tmc_closing_at' ) && 3 === (int) tmc_field( $job->ID, 'tmc_vacancies' ) );
$t( 'news: date and category', $post && '2024-08-15' === substr( $post->post_date, 0, 10 ) && has_category( 'news', $post ) );
$t( 'parent: date, excerpt and SEO description', $parent && '2024-03-01' === substr( $parent->post_date, 0, 10 ) && 'Summary of the parent page.' === $parent->post_excerpt && "SEO description $tag" === tmc_seo_field( $parent->ID, 'tmc_seo_description' ) );
$content = $parent ? $parent->post_content : '';
$t( 'content: body only, script removed', false !== strpos( $content, 'About the unit' ) && false === strpos( $content, '<script' ) && false === strpos( $content, '<title' ) );
$attachment = (int) $wpdb->get_var( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE m.meta_key = '_tmc_import_file' AND m.meta_value = %s", sha1_file( "$dir/files/notice-$tag.pdf" ) ) );
$doc_url    = $attachment ? wp_make_link_relative( wp_get_attachment_url( $attachment ) ) : '#none';
$t( 'document added to the media library and listed on the page', $attachment && false !== strpos( $content, $doc_url ) && false !== strpos( $content, "Notice $tag" ) );
$child_url = $childp ? wp_make_link_relative( get_permalink( $childp ) ) : '#none';
$job_url   = $job ? wp_make_link_relative( get_permalink( $job ) ) : '#none';
$t( 'links to old addresses rewritten (same host and old host, fragment kept)', false !== strpos( $content, 'href="' . $child_url . '#staff"' ) && false !== strpos( $content, 'href="' . $job_url . '"' ) );

WP_CLI::log( '— Redirects from old URLs' );
$hit = tmc_redirect_resolve( "$old/about.html" );
$t( 'old URL → 301 to the new page', $hit && 301 === $hit['status'] && get_permalink( $parent ) === $hit['location'] );
$hit = tmc_redirect_resolve( "$old/child.html" );
$t( 'old URL on the old domain → path redirected to the new page', $hit && get_permalink( $childp ) === $hit['location'] );
$hit = tmc_redirect_resolve( "$old/hi/about.html" );
$t( 'Hindi old URL → Hindi page', $hit && $hi && get_permalink( $hi ) === $hit['location'] && false !== strpos( $hit['location'], '/hi/' ) );
$hit = tmc_redirect_resolve( "$old/files/notice.pdf" );
$t( 'old document URL → file in the media library', $hit && $attachment && wp_get_attachment_url( $attachment ) === $hit['location'] );
$t( 'no redirect for rows that failed', null === tmc_redirect_resolve( "$old/orphan.html" ) && null === tmc_redirect_resolve( "$old/bad-type" ) );

WP_CLI::log( '— Report' );
$report_file = "$dir/report.csv";
$t( 'report written', $apply->write_report( $report_file ) && is_readable( $report_file ) );
$lines  = array_map( fn( $line ) => str_getcsv( $line, ',', '"', '' ), array_filter( explode( "\n", preg_replace( '/^\xEF\xBB\xBF/', '', (string) file_get_contents( $report_file ) ) ) ) );
$t( 'report: header + one line per inventory row', TMC_Inventory_Importer::REPORT_COLUMNS === $lines[0] && 13 === count( $lines ) );
$counts = $apply->counts();
$t( 'counts: 5 created, 1 updated, 1 skipped (exists), 1 other site, 4 errors (' . wp_json_encode( $counts ) . ')', 5 === ( $counts['created'] ?? 0 ) && 1 === ( $counts['updated'] ?? 0 ) && 1 === ( $counts['skipped (exists)'] ?? 0 ) && 1 === ( $counts['skipped (other site)'] ?? 0 ) && 4 === ( $counts['error'] ?? 0 ) );
$filled = get_post( $placeholder );
$t( 'placeholder page filled; editor page untouched', false !== strpos( $filled->post_content, 'Real content from TMC.' ) && "Real page $tag" === get_post( $real )->post_title );

WP_CLI::log( '— Idempotent re-run' );
$again   = new TMC_Inventory_Importer( array( 'csv' => $csv, 'mode' => 'apply' ) );
$report2 = $again->run();
$counts  = $again->counts();
$t( 'second run: everything unchanged (' . wp_json_encode( $counts ) . ')', 6 === ( $counts['unchanged'] ?? 0 ) && ! isset( $counts['created'] ) && ! isset( $counts['updated'] ) );
$t( 'no duplicates', 1 === $count_by_slug( "imp-$tag-parent" ) && 1 === $count_by_slug( "imp-$tag-child" ) && 1 === $count_by_slug( "imp-$tag-job" ) );
$t( 'redirects reported as existing', 'exists' === ( $by_title( $report2, "Import parent $tag" )['redirect'] ?? '' ) );
$t( 'document not added twice', 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_tmc_import_file' AND meta_value = %s", sha1_file( "$dir/files/notice-$tag.pdf" ) ) ) );

WP_CLI::log( '— Edited in the CMS' );
update_post_meta( $parent->ID, '_tmc_import_at', time() - 3600 ); // the page was changed after the last import
$rows[2][7] = "Import parent $tag";
$rows[2][10] = 'A changed summary.';
$handle = fopen( $csv, 'w' );
foreach ( $rows as $row ) {
	fputcsv( $handle, $row, ',', '"', '' );
}
fclose( $handle );
$third = ( new TMC_Inventory_Importer( array( 'csv' => $csv, 'mode' => 'apply' ) ) )->run();
$t( 'changed source row not applied over editor changes', 'skipped (edited in CMS)' === ( $by_title( $third, "Import parent $tag" )['action'] ?? '' ) && 'Summary of the parent page.' === get_post( $parent->ID )->post_excerpt );
$forced = ( new TMC_Inventory_Importer( array( 'csv' => $csv, 'mode' => 'apply', 'force' => '1' ) ) )->run();
$t( 'force=1 applies it', 'updated' === ( $by_title( $forced, "Import parent $tag" )['action'] ?? '' ) && 'A changed summary.' === get_post( $parent->ID )->post_excerpt );
$t( 'import runs audit-logged', 2 <= (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . tmc_audit_table() . " WHERE id > %d AND action IN ('content_imported','content_import_dry_run')", $first_audit ) ) );

WP_CLI::log( '— Cleanup' );
$created = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_tmc_import_source' AND meta_value LIKE %s", 'inventory.csv:%' ) );
foreach ( array_unique( array_merge( array_map( 'intval', $created ), array( $placeholder, $real ) ) ) as $id ) {
	if ( false !== strpos( (string) get_post_field( 'post_name', $id ), "imp-$tag" ) ) {
		wp_delete_post( $id, true );
	}
}
if ( $attachment ) {
	wp_delete_attachment( $attachment, true );
}
$table = tmc_redirects_table();
foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE source LIKE %s", $wpdb->esc_like( $old ) . '%' ) ) as $rule ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	tmc_redirect_delete( (int) $rule );
}
foreach ( array( 'content/parent.html', "files/notice-$tag.pdf", 'files/evil.php', 'inventory.csv', 'report.csv' ) as $file ) {
	@unlink( "$dir/$file" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
}
@rmdir( "$dir/content" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
@rmdir( "$dir/files" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
$t( 'test content, document and redirects removed', 0 === $count_by_slug( "imp-$tag-parent" ) && null === tmc_redirect_resolve( "$old/about.html" ) && ! get_post( $attachment ) );
$t( 'audit chain intact', tmc_audit_verify()['ok'] );

WP_CLI::log( '' );
$fail ? WP_CLI::error( "$fail failed, $pass passed" ) : WP_CLI::success( "all $pass checks passed" );
