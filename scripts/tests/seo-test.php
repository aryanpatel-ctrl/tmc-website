<?php
/**
 * SEO (tender §4.10): editor-managed metadata, Open Graph / Twitter tags, one tag of each kind,
 * JSON-LD per content type (organisation, website search, breadcrumbs, Event, JobPosting,
 * Physician), escaping, crawl directives per environment and XML sitemap exclusions.
 * Creates its own items and removes them afterwards.
 *
 *   docker compose run --rm -T wpcli --url=tmh.<base> eval-file - < scripts/tests/seo-test.php
 */

global $wpdb, $wp_query, $wp_the_query, $post;
$pass    = 0;
$fail    = 0;
$cleanup = array();
$t       = function ( $label, $ok ) use ( &$pass, &$fail ) {
	WP_CLI::log( ( $ok ? '  PASS  ' : '  FAIL  ' ) . $label );
	$ok ? $pass++ : $fail++;
};
$tag   = strtolower( wp_generate_password( 6, false ) );
$stamp = fn( $days ) => wp_date( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS );
$make  = function ( $type, $title, array $meta = array(), array $extra = array() ) use ( &$cleanup ) {
	$id = wp_insert_post( array( 'post_type' => $type, 'post_status' => 'publish', 'post_title' => $title ) + $extra, true );
	if ( is_wp_error( $id ) ) {
		WP_CLI::error( $id->get_error_message() );
	}
	if ( function_exists( 'pll_set_post_language' ) ) {
		pll_set_post_language( $id, 'en' );
	}
	foreach ( $meta as $key => $value ) {
		is_array( $value ) ? array_map( fn( $v ) => add_post_meta( $id, $key, $v ), $value ) : update_post_meta( $id, $key, $value );
	}
	$cleanup[] = $id;
	return $id;
};

/** Make $query_vars the main query, as a front-end request would. */
$visit = function ( array $query_vars, $is_404 = false ) {
	global $wp_query, $wp_the_query, $post;
	$wp_the_query = new WP_Query( $query_vars + array( 'lang' => '' ) );
	$wp_query     = $wp_the_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
	if ( $is_404 ) {
		$wp_query->set_404();
	}
	$post = $wp_query->is_singular && $wp_query->post ? $wp_query->post : null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
	if ( $post ) {
		setup_postdata( $post );
	}
};
/** The <head> as printed for the current query. */
$head = function () {
	ob_start();
	do_action( 'wp_head' );
	return (string) ob_get_clean();
};
/** Decoded JSON-LD @graph of a head ([] when missing/invalid). */
$graph = function ( $html ) {
	if ( ! preg_match( '~<script type="application/ld\+json">(.*?)</script>~s', $html, $m ) ) {
		return array();
	}
	$data = json_decode( $m[1], true );
	return is_array( $data['@graph'] ?? null ) ? $data['@graph'] : array();
};
$node = function ( array $graph, $type ) {
	foreach ( $graph as $item ) {
		if ( in_array( $type, (array) ( $item['@type'] ?? array() ), true ) ) {
			return $item;
		}
	}
	return null;
};
$count = fn( $needle, $html ) => substr_count( $html, $needle );

WP_CLI::log( '— Editor fields' );
foreach ( array( 'page', 'post', 'tmc_tender', 'tmc_event', 'tmc_job', 'tmc_department', 'tmc_doctor' ) as $type ) {
	$keys = get_registered_meta_keys( 'post', $type );
	$t( "SEO fields registered for $type", isset( $keys['_tmc_seo_title'], $keys['_tmc_seo_description'], $keys['_tmc_seo_noindex'], $keys['_tmc_seo_canonical'], $keys['_tmc_seo_image'] ) );
}
$page = $make( 'page', "SEO test page $tag", array(), array( 'post_content' => '<!-- wp:paragraph --><p>Body text of the SEO test page.</p><!-- /wp:paragraph -->' ) );
$before_audit = (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id),0) FROM ' . tmc_audit_table() );
tmc_seo_update(
	$page,
	array(
		'title'       => "Custom SEO title $tag",
		'description' => "  Custom   description of the\n test page $tag.  ",
		'canonical'   => 'javascript:alert(1)',
		'noindex'     => false,
	)
);
$t( 'description whitespace collapsed', "Custom description of the test page $tag." === tmc_seo_field( $page, 'tmc_seo_description' ) );
$t( 'unsafe canonical URL rejected', '' === tmc_seo_field( $page, 'tmc_seo_canonical' ) );
$hidden = $make( 'page', "SEO hidden page $tag" );
tmc_seo_update( $hidden, array( 'noindex' => true ) );
$logged = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . tmc_audit_table() . " WHERE id > %d AND action = 'seo_visibility_changed' AND object_id = %d", $before_audit, $hidden ) );
$t( 'hiding a page from search engines is audit-logged', 1 === (int) $logged );
ob_start();
tmc_seo_render_box( get_post( $page ) );
$box = (string) ob_get_clean();
$t( 'meta box: labelled fields and live length guidance', false !== strpos( $box, 'for="tmc-seo-title"' ) && false !== strpos( $box, 'for="tmc-seo-description"' ) && false !== strpos( $box, 'aria-live="polite"' ) && false !== strpos( $box, 'for="tmc-seo-noindex"' ) );
$t( 'length guidance text', false !== strpos( tmc_seo_count_text( str_repeat( 'a', 200 ), 70, 160 ), 'too long' ) && false !== strpos( tmc_seo_count_text( str_repeat( 'a', 100 ), 70, 160 ), 'good' ) );

WP_CLI::log( '— <head> of a page' );
$visit( array( 'page_id' => $page ) );
$html = $head();
$t( 'exactly one <title>, with the SEO title and site name', 1 === $count( '<title', $html ) && false !== strpos( $html, "Custom SEO title $tag" ) && false !== strpos( $html, esc_html( get_bloginfo( 'name' ) ) ) );
$t( 'one meta description (custom)', 1 === $count( '<meta name="description"', $html ) && false !== strpos( $html, "content=\"Custom description of the test page $tag.\"" ) );
$t( 'one canonical, pointing at the page', 1 === $count( 'rel="canonical"', $html ) && false !== strpos( $html, 'rel="canonical" href="' . esc_url( get_permalink( $page ) ) . '"' ) );
foreach ( array( 'og:title', 'og:description', 'og:url', 'og:type', 'og:site_name', 'og:locale' ) as $property ) {
	$t( "one $property", 1 === $count( 'property="' . $property . '"', $html ) );
}
foreach ( array( 'twitter:card', 'twitter:title', 'twitter:description' ) as $name ) {
	$t( "one $name", 1 === $count( 'name="' . $name . '"', $html ) );
}
$t( 'one robots meta', 1 === preg_match_all( "~<meta name=['\"]robots['\"]~", $html ) );
$t( 'one JSON-LD block', 1 === $count( 'application/ld+json', $html ) );
$g = $graph( $html );
$t( 'JSON-LD is valid JSON with a @graph', count( $g ) >= 3 );
$org = $node( $g, is_main_site() ? 'GovernmentOrganization' : 'Hospital' );
$t( 'organisation node: name, url, logo, address, parentOrganization', $org && ! empty( $org['name'] ) && ! empty( $org['url'] ) && ! empty( $org['logo']['url'] ) && ! empty( $org['address']['addressCountry'] ) && ! empty( $org['parentOrganization']['name'] ) );
$site = $node( $g, 'WebSite' );
$t( 'WebSite with SearchAction', $site && false !== strpos( $site['potentialAction']['target']['urlTemplate'] ?? '', '?s={search_term_string}' ) );
$crumbs = $node( $g, 'BreadcrumbList' );
$trail  = tmc_breadcrumb_trail();
$t( 'BreadcrumbList matches the visible breadcrumbs', $crumbs && count( $crumbs['itemListElement'] ) === count( $trail ) && end( $crumbs['itemListElement'] )['item'] === get_permalink( $page ) && $crumbs['itemListElement'][0]['name'] === $trail[0][0] );

WP_CLI::log( '— Default descriptions' );
$news = $make( 'post', "SEO test news $tag", array(), array( 'post_excerpt' => "Excerpt of the news item $tag.", 'post_content' => '<p>Longer body.</p>' ) );
$visit( array( 'p' => $news ) );
$t( 'post: description from the excerpt, og:type article', false !== strpos( $head(), "content=\"Excerpt of the news item $tag.\"" ) && 'article' === tmc_seo_context()['og_type'] );
$long = $make( 'post', "SEO test long $tag", array(), array( 'post_content' => '<!-- wp:heading --><h2>Heading</h2><!-- /wp:heading --><!-- wp:paragraph --><p>' . str_repeat( 'Word ', 80 ) . '</p><!-- /wp:paragraph -->' ) );
$visit( array( 'p' => $long ) );
$desc = tmc_seo_context()['description'];
$t( 'no excerpt: start of the content, at most 160 characters, words separated', mb_strlen( $desc ) <= 160 && str_starts_with( $desc, 'Heading Word' ) );
// Security regression: text of a password-protected page must not leak into meta, Open Graph,
// structured data or the theme's fallback description (all of <head>).
$locked = $make( 'page', "SEO locked $tag", array(), array( 'post_password' => "pw-$tag", 'post_excerpt' => "Locked excerpt $tag", 'post_content' => "<p>Locked secret $tag</p>" ) );
$visit( array( 'page_id' => $locked ) );
$locked_head = $head();
$t( 'password-protected page: no excerpt or content anywhere in <head>', '' !== $locked_head && false === strpos( $locked_head, "Locked secret $tag" ) && false === strpos( $locked_head, "Locked excerpt $tag" ) );

WP_CLI::log( '— Structured data per content type' );
$event = $make( 'tmc_event', "SEO test event $tag", array( '_tmc_start_at' => $stamp( 10 ), '_tmc_end_at' => $stamp( 10.2 ), '_tmc_venue' => 'Test auditorium' ) );
$visit( array( 'p' => $event, 'post_type' => 'tmc_event' ) );
$e = $node( $graph( $head() ), 'Event' );
$t( 'Event: name, ISO start date with offset, venue, organiser', $e && "SEO test event $tag" === $e['name'] && preg_match( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+05:30$/', $e['startDate'] ) && 'Test auditorium' === $e['location']['name'] && tmc_schema_org_id() === $e['organizer']['@id'] );

$closing = $stamp( 20 );
$job     = $make( 'tmc_job', "SEO test job $tag", array( '_tmc_ref_no' => "TEST/$tag", '_tmc_closing_at' => $closing, '_tmc_vacancies' => 3 ), array( 'post_content' => '<p>Duties <strong>and</strong> eligibility.</p><script>alert(1)</script>' ) );
$visit( array( 'p' => $job, 'post_type' => 'tmc_job' ) );
$j = $node( $graph( $head() ), 'JobPosting' );
$t( 'JobPosting: validThrough = last date to apply', $j && tmc_schema_iso( $closing ) === $j['validThrough'] );
$t( 'JobPosting: hiring organisation, identifier, openings, location', $j && ! empty( $j['hiringOrganization']['name'] ) && "TEST/$tag" === $j['identifier']['value'] && 3 === $j['totalJobOpenings'] && ! empty( $j['jobLocation']['address'] ) );
$t( 'JobPosting: description keeps basic formatting, drops scripts', $j && false !== strpos( $j['description'], '<strong>and</strong>' ) && false === strpos( $j['description'], 'script' ) );

$department = $make( 'tmc_department', "SEO test department $tag" );
$doctor     = $make( 'tmc_doctor', "Dr. SEO Test $tag", array( '_tmc_designation' => 'Professor', '_tmc_qualifications' => 'MD', '_tmc_department_ids' => array( $department ) ) );
$visit( array( 'p' => $doctor, 'post_type' => 'tmc_doctor' ) );
$d = $node( $graph( $head() ), 'Physician' );
$t( 'Physician: name, designation, department, hospital affiliation', $d && "Dr. SEO Test $tag" === $d['name'] && false !== strpos( $d['description'], 'Professor' ) && false !== strpos( $d['description'], "SEO test department $tag" ) && tmc_schema_org_id() === $d['hospitalAffiliation']['@id'] );

$sample = $make( 'tmc_event', "SEO sample event $tag", array( '_tmc_start_at' => $stamp( 5 ), '_tmc_sample' => 1 ) );
$visit( array( 'p' => $sample, 'post_type' => 'tmc_event' ) );
$sample_head = $head();
$t( 'sample item: no Event structured data', ! $node( $graph( $sample_head ), 'Event' ) );

$evil = $make( 'page', "Test </script><script>alert('x')</script> & \"quotes\" $tag" );
$visit( array( 'page_id' => $evil ) );
$evil_head = $head();
preg_match( '~<script type="application/ld\+json">(.*?)</script>~s', $evil_head, $m );
$t( 'JSON-LD stays valid with quotes, ampersands and markup in a title', isset( $m[1] ) && false === strpos( $m[1], '<' ) && null !== json_decode( $m[1] ) );
$hostile = "</script><script>alert('x')</script> & \"q\"";
$json    = tmc_schema_json( array( array( '@type' => 'Thing', 'name' => $hostile ) ) );
$t( 'JSON-LD escapes "<", ">" and "&" (content cannot close the script element)', false === strpos( $json, '<' ) && false === strpos( $json, '&' ) && false !== strpos( $json, '\u003C/script\u003E' ) && json_decode( $json, true )['@graph'][0]['name'] === $hostile );

WP_CLI::log( '— Main site organisation' );
switch_to_blog( get_main_site_id() );
$main_org = tmc_schema_organization();
restore_current_blog();
$t( 'TMC is a GovernmentOrganization + MedicalOrganization under DAE', in_array( 'GovernmentOrganization', (array) $main_org['@type'], true ) && in_array( 'MedicalOrganization', (array) $main_org['@type'], true ) && false !== strpos( $main_org['parentOrganization']['name'], 'Department of Atomic Energy' ) );
if ( ! is_main_site() ) {
	$t( 'unit site is a Hospital whose parent is TMC', 'Hospital' === tmc_schema_organization()['@type'] && tmc_schema_org_id( get_main_site_id() ) === tmc_schema_organization()['parentOrganization']['@id'] );
}

WP_CLI::log( '— Listings, search, 404' );
$visit( array( 'post_type' => 'tmc_tender' ) );
$archive = tmc_seo_context();
$t( 'tender listing: canonical = listing URL, description mentions the site', get_post_type_archive_link( 'tmc_tender' ) === $archive['canonical'] && false !== strpos( $archive['description'], html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ) );
$t( 'tender listing: CollectionPage', (bool) $node( $graph( $head() ), 'CollectionPage' ) );
$visit( array( 's' => 'anything' ) );
$search = $head();
$t( 'search results: noindex, no canonical', false !== strpos( $search, 'noindex' ) && 0 === $count( 'rel="canonical"', $search ) );
$visit( array( 'page_id' => $page ), true );
$t( '404: noindex, no canonical', false !== strpos( $head(), 'noindex' ) && '' === tmc_seo_canonical() );

WP_CLI::log( '— Crawl directives: this environment (' . wp_get_environment_type() . ')' );
if ( ! tmc_seo_is_production() ) {
	$robots = tmc_seo_robots_txt( '', true );
	$t( 'robots.txt: Disallow all, no sitemap', 1 === preg_match( '~^Disallow: /$~m', $robots ) && false === strpos( $robots, 'Sitemap:' ) );
	$t( 'X-Robots-Tag: noindex header', 'noindex, nofollow' === ( tmc_seo_http_headers( array() )['X-Robots-Tag'] ?? '' ) );
	$visit( array( 'page_id' => $page ) );
	$t( 'robots meta: noindex on a normal page', false !== strpos( $head(), 'noindex' ) );
}

WP_CLI::log( '— Crawl directives: production (simulated)' );
add_filter( 'tmc_seo_is_production', '__return_true' );
add_filter( 'pre_option_blog_public', fn() => '1' );
$robots = tmc_seo_robots_txt( '', true );
$t( 'robots.txt allows crawling and lists the sitemap', 0 === preg_match( '~^Disallow: /$~m', $robots ) && false !== strpos( $robots, 'Sitemap: ' . home_url( '/wp-sitemap.xml' ) ) );
$t( 'no X-Robots-Tag header', ! isset( tmc_seo_http_headers( array() )['X-Robots-Tag'] ) );
$visit( array( 'page_id' => $page ) );
$t( 'normal page is indexable', false === strpos( $head(), 'noindex' ) );
$visit( array( 'page_id' => $hidden ) );
$t( 'page marked "hide from search engines" is noindex', false !== strpos( $head(), 'noindex' ) );
$visit( array( 'p' => $sample, 'post_type' => 'tmc_event' ) );
$t( 'sample item is noindex', false !== strpos( $head(), 'noindex' ) );
remove_all_filters( 'tmc_seo_is_production' );
remove_all_filters( 'pre_option_blog_public' );

WP_CLI::log( '— XML sitemaps' );
$provider = wp_sitemaps_get_server()->registry->get_provider( 'posts' );
$events   = wp_list_pluck( $provider->get_url_list( 1, 'tmc_event' ), 'loc' );
$pages    = wp_list_pluck( $provider->get_url_list( 1, 'page' ), 'loc' );
$t( 'content types have sitemaps', isset( $provider->get_object_subtypes()['tmc_event'], $provider->get_object_subtypes()['tmc_job'], $provider->get_object_subtypes()['tmc_doctor'] ) );
$t( 'real event listed', in_array( get_permalink( $event ), $events, true ) );
$t( 'sample event left out', ! in_array( get_permalink( $sample ), $events, true ) );
$t( 'noindex page left out, normal page listed', ! in_array( get_permalink( $hidden ), $pages, true ) && in_array( get_permalink( $page ), $pages, true ) );
$t( 'no user sitemap (would disclose login names)', null === wp_sitemaps_get_server()->registry->get_provider( 'users' ) );

WP_CLI::log( '— Cleanup' );
wp_reset_postdata();
$wp_the_query = new WP_Query();
$wp_query     = $wp_the_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
foreach ( array_reverse( $cleanup ) as $id ) {
	wp_delete_post( $id, true );
}
$t( 'audit chain intact', tmc_audit_verify()['ok'] );

WP_CLI::log( '' );
$fail ? WP_CLI::error( "$fail failed, $pass passed" ) : WP_CLI::success( "all $pass checks passed" );
