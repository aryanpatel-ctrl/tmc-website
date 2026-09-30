<?php
/**
 * Editorial platform (W5): page templates, block governance, network publishing, audience entry
 * points and the component library. Uses its own users and content (created here, removed at the
 * end; the audit log keeps the history, by design). Runs against the TMH site.
 *
 *   docker compose run --rm -T wpcli --url=tmh.<base> eval-file - < scripts/tests/editorial-test.php
 */

global $wpdb;
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once ABSPATH . 'wp-admin/includes/ms.php';

$pass = 0;
$fail = 0;
$t    = function ( $label, $ok ) use ( &$pass, &$fail ) {
	WP_CLI::log( ( $ok ? '  PASS  ' : '  FAIL  ' ) . $label );
	$ok ? $pass++ : $fail++;
};
$rest = function ( $method, $route, array $params = array() ) {
	$request = new WP_REST_Request( $method, $route );
	foreach ( $params as $key => $value ) {
		$request->set_param( $key, $value );
	}
	return rest_do_request( $request );
};
$code      = fn( $response ) => (string) ( $response->get_data()['code'] ?? '' );
$audit_max = fn() => (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id),0) FROM ' . tmc_audit_table() );
$audit_since = function ( $after, $like ) use ( $wpdb ) {
	return $wpdb->get_col( $wpdb->prepare( 'SELECT action FROM ' . tmc_audit_table() . ' WHERE id > %d AND action LIKE %s ORDER BY id', $after, $like ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
};

$tag  = strtolower( wp_generate_password( 6, false ) );
$main = (int) get_main_site_id();
$tmh  = (int) get_current_blog_id();
$viz  = get_site_by_path( 'hbchrcv.' . DOMAIN_CURRENT_SITE, '/' );
$viz  = $viz ? (int) $viz->blog_id : 0;
if ( $main === $tmh || ! $viz ) {
	WP_CLI::error( 'Run this test against the TMH site of the full six-site network.' );
}

$first_audit = $audit_max();
$users       = array();
$pages       = array(); // on TMH
$origins     = array(); // on the TMC site

$make_user = function ( $key, array $roles ) use ( &$users, $tag ) {
	$login = 'w5' . $key . $tag;
	$id    = wpmu_create_user( $login, wp_generate_password( 32 ), $login . '@example.com' );
	if ( ! $id ) {
		return null;
	}
	$users[] = (int) $id;
	foreach ( $roles as $blog => $role ) {
		add_user_to_blog( $blog, $id, $role );
	}
	return get_userdata( $id );
};

try {
	$central         = $make_user( 'tmcrev', array( $main => 'editor' ) );
	$central_editor  = $make_user( 'tmced', array( $main => 'contributor' ) );
	$unit_reviewer   = $make_user( 'unitrev', array( $tmh => 'editor' ) );
	$unit_admin      = $make_user( 'unitadm', array( $tmh => 'administrator' ) );
	$unit_editor     = $make_user( 'united', array( $tmh => 'contributor' ) );
	$t( 'fixture users created (TMC reviewer + content editor; unit reviewer, admin, content editor)', $central && $central_editor && $unit_reviewer && $unit_admin && $unit_editor );

	/* ============================================================ page templates */
	WP_CLI::log( '— Page templates (R-4.3-2)' );
	wp_set_current_user( $unit_editor->ID );
	$res     = $rest( 'GET', '/wp/v2/block-patterns/patterns' ); // what the block editor loads
	$offered = array();
	foreach ( (array) $res->get_data() as $pattern ) {
		if ( in_array( 'core/post-content', (array) ( $pattern['block_types'] ?? array() ), true ) ) {
			$offered[] = $pattern['name'];
		}
	}
	$foreign = array_filter( wp_list_pluck( (array) $res->get_data(), 'name' ), fn( $name ) => ! str_starts_with( (string) $name, 'tmc/' ) );
	$t( 'block editor (REST) offers the Content Editor the page templates (' . count( $offered ) . ') and only TMC patterns', 200 === $res->get_status() && ! array_diff( array( 'tmc/page-standard', 'tmc/page-landing', 'tmc/page-contact', 'tmc/page-documents', 'tmc/page-service', 'tmc/page-people', 'tmc/page-faq' ), $offered ) && ! $foreign );
	wp_set_current_user( 0 );
	tmc_register_page_templates(); // no-op when rest_api_init already registered them
	$registry  = WP_Block_Patterns_Registry::get_instance();
	$templates = tmc_page_templates();
	$names     = function ( array $blocks ) use ( &$names ) {
		$out = array();
		foreach ( $blocks as $block ) {
			if ( $block['blockName'] ) {
				$out[] = $block['blockName'];
			}
			$out = array_merge( $out, $names( $block['innerBlocks'] ) );
		}
		return $out;
	};
	$t( 'seven templates: standard, landing, contact, documents, service, people, faq', array( 'standard', 'landing', 'contact', 'documents', 'service', 'people', 'faq' ) === array_keys( $templates ) );
	$approved = tmc_approved_blocks();
	$content  = array();
	foreach ( $templates as $slug => list( $title ) ) {
		$pattern          = $registry->get_registered( "tmc/page-$slug" );
		$content[ $slug ] = (string) ( $pattern['content'] ?? '' );
		$blocks           = parse_blocks( $content[ $slug ] );
		$top              = array_values( array_filter( $blocks, fn( $b ) => null !== $b['blockName'] ) );
		$t( "$title: offered when a page is created (starter pattern for core/post-content, pages)", $pattern && in_array( 'core/post-content', (array) ( $pattern['blockTypes'] ?? array() ), true ) && in_array( 'page', (array) ( $pattern['postTypes'] ?? array() ), true ) );
		$t(
			"$title: every section is protected from removal, fixed sections are contentOnly",
			$top && ! array_filter( $top, fn( $b ) => empty( $b['attrs']['lock']['remove'] ) || empty( $b['attrs']['metadata']['name'] ) )
				&& array_filter( $top, fn( $b ) => 'contentOnly' === ( $b['attrs']['templateLock'] ?? '' ) )
		);
		$unapproved = array_diff( $names( $blocks ), $approved );
		$t( "$title: approved design-system blocks only" . ( $unapproved ? ' (' . implode( ', ', $unapproved ) . ')' : '' ), ! $unapproved );
		$t( "$title: prompts block publishing until replaced", tmc_has_template_prompts( $content[ $slug ] ) );
	}
	$t( 'component patterns registered (person, question, card, callout)', $registry->is_registered( 'tmc/component-person' ) && $registry->is_registered( 'tmc/component-question' ) && $registry->is_registered( 'tmc/component-card' ) && $registry->is_registered( 'tmc/component-callout' ) );
	$t( 'placeholder links (href="#") also count as unfinished', tmc_has_template_prompts( '<p><a href="#">Read more</a></p>' ) && ! tmc_has_template_prompts( '<p><a href="#top">Top</a></p>' ) );

	WP_CLI::log( '— Creating pages from templates (Content Editor → Reviewer)' );
	wp_set_current_user( $unit_editor->ID );
	$created = 0;
	foreach ( $content as $slug => $markup ) {
		$res = $rest( 'POST', '/wp/v2/pages', array( 'title' => "W5 $slug page $tag", 'content' => $markup, 'status' => 'pending' ) );
		$id  = (int) ( $res->get_data()['id'] ?? 0 );
		if ( $id ) {
			$pages[ $slug ] = $id;
		}
		if ( 201 === $res->get_status() && false !== strpos( get_post_field( 'post_content', $id ), '"templateLock":"contentOnly"' ) ) {
			++$created;
		} else {
			WP_CLI::log( "        $slug: HTTP " . $res->get_status() . ' ' . $code( $res ) );
		}
	}
	$t( "Content Editor creates a page from each template and submits it ($created/7, locks kept)", 7 === $created );

	wp_set_current_user( $unit_reviewer->ID );
	$res = $rest( 'POST', '/wp/v2/pages/' . ( $pages['standard'] ?? 0 ), array( 'status' => 'publish' ) );
	$t( 'Reviewer cannot publish while prompts remain (HTTP ' . $res->get_status() . ' ' . $code( $res ) . ')', 400 === $res->get_status() && 'tmc_template_incomplete' === $code( $res ) );
	$filled = preg_replace( '/\[Replace: ([^\]]*)\]/', '$1', (string) get_post_field( 'post_content', $pages['standard'] ?? 0 ) );
	$res    = $rest( 'POST', '/wp/v2/pages/' . ( $pages['standard'] ?? 0 ), array( 'content' => $filled, 'status' => 'publish' ) );
	$t( 'Reviewer publishes once every field is filled (HTTP ' . $res->get_status() . ')', 200 === $res->get_status() && 'publish' === get_post_status( $pages['standard'] ?? 0 ) );

	/* ============================================================ block governance */
	WP_CLI::log( '— Block governance (R-5-1)' );
	$context = new WP_Block_Editor_Context( array( 'name' => 'core/edit-post' ) );
	wp_set_current_user( $unit_editor->ID );
	$allowed = get_allowed_block_types( $context );
	$t( 'Content Editor: inserter offers the approved blocks, incl. TMC blocks', is_array( $allowed ) && ! array_diff( array( 'core/paragraph', 'core/heading', 'core/table', 'core/details', 'core/file', 'tmc/notice-board', 'tmc/network' ), $allowed ) );
	$t( 'Content Editor: no Custom HTML, Classic, shortcode, embed or reusable-pattern blocks', is_array( $allowed ) && ! array_intersect( array( 'core/html', 'core/freeform', 'core/shortcode', 'core/embed', 'core/block' ), $allowed ) );
	$settings = tmc_editorial_editor_settings( array(), $context );
	$t( 'Content Editor: code editor, "Edit as HTML" and unlocking disabled; no Openverse', has_filter( 'block_editor_settings_all', 'tmc_editorial_editor_settings' ) && false === $settings['codeEditingEnabled'] && false === $settings['canLockBlocks'] && false === $settings['enableOpenverseMediaCategory'] );
	$t( 'Content Editor: no unfiltered HTML, no Additional CSS', ! current_user_can( 'unfiltered_html' ) && ! current_user_can( 'edit_css' ) );
	$res = $rest( 'POST', '/wp/v2/pages', array( 'title' => "W5 html $tag", 'status' => 'draft', 'content' => '<!-- wp:html --><div onclick="x()">raw</div><!-- /wp:html -->' ) );
	if ( ! empty( $res->get_data()['id'] ) ) {
		$pages['html'] = (int) $res->get_data()['id'];
	}
	$t( 'REST: a crafted Custom HTML block is refused (HTTP ' . $res->get_status() . ' ' . $code( $res ) . ')', 400 === $res->get_status() && 'tmc_block_not_allowed' === $code( $res ) );
	$res = $rest( 'POST', '/wp/v2/blocks', array( 'title' => "W5 pattern $tag", 'status' => 'publish', 'content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' ) );
	if ( ! empty( $res->get_data()['id'] ) ) {
		$pages['pattern'] = (int) $res->get_data()['id'];
	}
	$t( 'REST: Content Editor cannot create reusable patterns (HTTP ' . $res->get_status() . ')', in_array( $res->get_status(), array( 401, 403 ), true ) );
	$t( 'nested unapproved blocks are found; plain text is left to kses', array( 'core/html' ) === tmc_unapproved_blocks( '<!-- wp:group --><div class="wp-block-group"><!-- wp:html --><b>x</b><!-- /wp:html --></div><!-- /wp:group -->' ) && array() === tmc_unapproved_blocks( 'Plain text from an API client.' ) );
	$legacy = '<!-- wp:html --><b>x</b><!-- /wp:html -->';
	$t( 'blocks already in a page (administrator, migration) do not block edits; adding more does', array() === tmc_unapproved_blocks( $legacy . '<!-- wp:paragraph --><p>New text</p><!-- /wp:paragraph -->', $legacy ) && array( 'core/html' ) === tmc_unapproved_blocks( $legacy . $legacy, $legacy ) );

	WP_CLI::log( '— Locked sections keep their structure' );
	$top  = fn( $id ) => array_values( array_filter( parse_blocks( (string) get_post_field( 'post_content', $id ) ), fn( $b ) => null !== $b['blockName'] ) );
	$save = fn( $id, array $blocks ) => $rest( 'POST', "/wp/v2/pages/$id", array( 'content' => serialize_blocks( $blocks ) ) );
	$contact = (int) ( $pages['contact'] ?? 0 );
	$before  = (string) get_post_field( 'post_content', $contact );
	$b       = $top( $contact ); // Introduction, Contact details, Location map (area), Directions
	$t( 'contact page has its four sections', 4 === count( $b ) && 'Contact details' === ( $b[1]['attrs']['metadata']['name'] ?? '' ) );
	wp_set_current_user( $unit_editor->ID );
	$edit       = $b;
	$edit[0]    = tmc_b_locked( array( tmc_b_paragraph( 'Sample: who to contact for what.', 'tmc-lead' ) ), 'tmc-tpl-intro', 'Introduction' );
	$phones     = &$edit[1]['innerBlocks'][0]['innerBlocks'][1]['innerBlocks'];
	$phones[1]  = tmc_b_list( array( 'Reception: sample number', 'Helpline: sample number', 'Enquiries: sample number' ) );
	unset( $phones );
	$edit[2]    = tmc_b_area( array( tmc_b_heading( 'Sample map heading' ), tmc_b_paragraph( 'Sample map text.' ) ), 'tmc-slot tmc-slot-map', 'Location map' );
	$res        = $save( $contact, $edit );
	$t( 'Content Editor edits text, adds a telephone number and fills the map area (HTTP ' . $res->get_status() . ' ' . $code( $res ) . ')', 200 === $res->get_status() && false !== strpos( get_post_field( 'post_content', $contact ), 'Enquiries: sample number' ) );
	$edited = (string) get_post_field( 'post_content', $contact );
	$b      = $top( $contact );
	$cases  = array(
		'removing a section'          => array_slice( $b, 1 ),
		'unlocking a section'         => array_replace( $b, array( 1 => array_replace( $b[1], array( 'attrs' => array_diff_key( $b[1]['attrs'], array( 'templateLock' => 1 ) ) ) ) ) ),
		'adding a block to a section' => array_replace( $b, array( 0 => tmc_b_locked( array_merge( $b[0]['innerBlocks'], array( tmc_b_paragraph( 'Extra' ) ) ), 'tmc-tpl-intro', 'Introduction' ) ) ),
		'moving a section'            => array( $b[0], $b[1], $b[3], $b[2] ),
		'removing a section\'s class' => array_replace( $b, array( 3 => tmc_b_group( $b[3]['innerBlocks'], '', array_diff_key( $b[3]['attrs'], array( 'className' => 1 ) ) ) ) ),
	);
	foreach ( $cases as $label => $blocks ) {
		$res = $save( $contact, $blocks );
		$t( "Content Editor: $label is refused (HTTP " . $res->get_status() . ' ' . $code( $res ) . ')', 400 === $res->get_status() && 'tmc_template_locked' === $code( $res ) );
	}
	$after = (string) get_post_field( 'post_content', $contact );
	$t( 'refused saves leave the page unchanged', $edited !== $before && $after === $edited );

	// Other web paths (classic form, Quick Edit) run the same rules through wp_insert_post_data.
	$insert = fn( $id, $content, $status ) => tmc_editorial_check_insert( array( 'post_type' => 'page', 'post_content' => wp_slash( $content ), 'post_status' => $status ), array( 'ID' => $id ) );
	$error  = $insert( 0, '<!-- wp:html --><b>x</b><!-- /wp:html -->', 'draft' );
	$t( 'classic form: Content Editor cannot add Custom HTML', has_filter( 'wp_insert_post_data', 'tmc_editorial_enforce_on_save' ) && is_wp_error( $error ) && 'tmc_block_not_allowed' === $error->get_error_code() );
	$error = $insert( $contact, serialize_blocks( array_slice( $b, 1 ) ), 'pending' );
	$t( 'classic form: Content Editor cannot remove a locked section', is_wp_error( $error ) && 'tmc_template_locked' === $error->get_error_code() );
	wp_set_current_user( $unit_reviewer->ID );
	$error = $insert( $contact, $after, 'publish' );
	$t( 'Quick Edit: Reviewer cannot publish a page with prompts', is_wp_error( $error ) && 'tmc_template_incomplete' === $error->get_error_code() );
	$t( 'autosaves are not refused (checked when the editor saves)', ( (object) array( 'ID' => $contact ) ) == tmc_editorial_rest_check( (object) array( 'ID' => $contact ), new WP_REST_Request( 'POST', "/wp/v2/pages/$contact/autosaves" ) ) );
	wp_set_current_user( $unit_admin->ID );
	$res = $save( $contact, array_slice( $b, 1 ) );
	$t( 'Site Administrator may restructure a template page (HTTP ' . $res->get_status() . ')', 200 === $res->get_status() && 3 === count( $top( $contact ) ) );
	$t( 'Site Administrator: classic form accepts it too', null === $insert( $contact, serialize_blocks( $b ), 'pending' ) );

	// Home-page style sections (locked, not pinned): text changes and removal are fine, restructuring is not.
	$home_like     = array( tmc_section_hero( 'Sample', 'Sample heading', 'Sample text.', array( array( 'Sample action', '/patient-care/' ) ) ), tmc_section_about( 'Sample about', 'Sample text.', array( 'Read more', '/about-us/' ) ) );
	$pages['home'] = (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => "W5 sections $tag", 'post_content' => wp_slash( serialize_blocks( $home_like ) ) ) );
	wp_set_current_user( $unit_reviewer->ID );
	$h      = $top( $pages['home'] );
	$h[0]   = tmc_section_hero( 'Sample', 'Changed heading', 'Changed text.', array( array( 'Changed action', '/patient-care/' ), array( 'Second action', '/contact-us/', true ) ) );
	$res    = $save( $pages['home'], $h );
	$ok     = 200 === $res->get_status();
	$h[0]   = tmc_b_section( array_merge( $h[0]['innerBlocks'], array( tmc_b_paragraph( 'Extra' ) ) ), 'tmc-hero' );
	$res    = $save( $pages['home'], $h );
	$locked = 400 === $res->get_status() && 'tmc_template_locked' === $code( $res );
	$res    = $save( $pages['home'], array( $top( $pages['home'] )[0] ) );
	$t( 'home sections: Reviewer edits text and adds a button, cannot restructure, may remove an unpinned section', $ok && $locked && 200 === $res->get_status() && 1 === count( $top( $pages['home'] ) ) );
	wp_set_current_user( $unit_editor->ID );

	wp_set_current_user( $unit_reviewer->ID );
	$t( 'Reviewer / Publisher: same restriction', is_array( get_allowed_block_types( $context ) ) );
	wp_set_current_user( $unit_admin->ID );
	$settings = tmc_editorial_editor_settings( array(), $context );
	$t( 'Site Administrator: full palette, can unlock sections', true === get_allowed_block_types( $context ) && ! isset( $settings['canLockBlocks'] ) );
	$t( 'Site Administrator: still no unfiltered HTML (Super Admin only)', ! current_user_can( 'unfiltered_html' ) );

	tmc_limit_block_patterns();
	$foreign = array_filter( wp_list_pluck( $registry->get_all_registered(), 'name' ), fn( $name ) => ! str_starts_with( $name, 'tmc/' ) );
	$t( 'only TMC patterns are registered' . ( $foreign ? ' (' . implode( ', ', $foreign ) . ')' : '' ), ! $foreign );
	$t( 'remote pattern directory switched off', false === apply_filters( 'should_load_remote_block_patterns', true ) );

	/* ============================================================ network publishing */
	WP_CLI::log( '— Network publishing: who may publish to unit websites (R-4.6-2)' );
	switch_to_blog( $main );
	wp_set_current_user( $central->ID );
	$t( 'TMC Reviewer / Publisher: yes', current_user_can( 'tmc_network_publish' ) );
	wp_set_current_user( $central_editor->ID );
	$t( 'TMC Content Editor: no', ! current_user_can( 'tmc_network_publish' ) );
	wp_set_current_user( $unit_admin->ID );
	$t( 'unit Site Administrator: no', ! current_user_can( 'tmc_network_publish' ) );
	restore_current_blog();
	wp_set_current_user( $unit_reviewer->ID );
	$t( 'unit Reviewer on their own site: no', ! current_user_can( 'tmc_network_publish' ) );

	WP_CLI::log( '— Network publishing: create' );
	switch_to_blog( $main );
	wp_set_current_user( $central->ID );
	$cat_en  = get_category_by_slug( 'notices' );
	$cat_hi  = get_category_by_slug( 'suchnayen' );
	$expires = wp_date( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS );
	$en      = (int) wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => "W5 network notice $tag", 'post_content' => '<!-- wp:paragraph --><p>Network publishing test.</p><!-- /wp:paragraph -->', 'post_category' => $cat_en ? array( $cat_en->term_id ) : array() ) );
	$hi      = (int) wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => "W5 network notice hi $tag", 'post_name' => "w5-network-notice-hi-$tag", 'post_content' => '<!-- wp:paragraph --><p>Network publishing test (Hindi version).</p><!-- /wp:paragraph -->', 'post_category' => $cat_hi ? array( $cat_hi->term_id ) : array() ) );
	$origins = array_filter( array( $en, $hi ) );
	pll_set_post_language( $en, 'en' );
	pll_set_post_language( $hi, 'hi' );
	pll_save_post_translations( array( 'en' => $en, 'hi' => $hi ) );
	update_post_meta( $en, '_tmc_expires_at', $expires );

	// The editor panel, submitted first by someone without the right, then by a TMC reviewer.
	$expected = array( $tmh, $viz );
	sort( $expected );
	wp_set_current_user( $central_editor->ID );
	$_POST = array( 'tmc_syndication_present' => '1', 'tmc_syndication_nonce' => wp_create_nonce( 'tmc_syndication' ), 'tmc_syndication_targets' => array( (string) $tmh, (string) $viz ) );
	tmc_syndication_save_box( $en, get_post( $en ) );
	$t( 'panel submission by a TMC Content Editor is ignored', array() === tmc_syndication_get_targets( $en ) );
	wp_set_current_user( $central->ID );
	$_POST['tmc_syndication_nonce'] = wp_create_nonce( 'tmc_syndication' );
	tmc_syndication_save_box( $en, get_post( $en ) );
	$_POST = array();
	$t( 'panel saves the selection for the item and its translation', $expected === tmc_syndication_get_targets( $en ) && $expected === array_map( 'intval', (array) get_post_meta( $hi, TMC_SYND_TARGETS, true ) ) );
	$t( 'nothing is copied while the original is a draft', ! tmc_syndication_copies( $en ) );

	wp_update_post( array( 'ID' => $en, 'post_status' => 'publish' ) );
	wp_update_post( array( 'ID' => $hi, 'post_status' => 'publish' ) );
	$origin_url = get_permalink( $en );
	$copies_en  = tmc_syndication_copies( $en );
	$copies_hi  = tmc_syndication_copies( $hi );
	ob_start();
	tmc_syndication_box( get_post( $en ) );
	$box = (string) ob_get_clean();
	$t( 'panel lists every unit website with its sync state', substr_count( $box, 'name="tmc_syndication_targets[]"' ) === count( tmc_syndication_sites() ) && false !== strpos( $box, '(synced)' ) && false === strpos( $box, 'disabled' ) );
	wp_set_current_user( $central_editor->ID );
	ob_start();
	tmc_syndication_box( get_post( $en ) );
	$box = (string) ob_get_clean();
	$t( 'panel is read-only for a TMC Content Editor', false !== strpos( $box, "disabled='disabled'" ) );
	wp_set_current_user( $central->ID );
	restore_current_blog();

	$c_en = $copies_en[ $tmh ] ?? 0;
	$c_hi = $copies_hi[ $tmh ] ?? 0;
	$t( 'publishing creates copies on both selected sites, in both languages', $c_en && $c_hi && ! empty( $copies_en[ $viz ] ) && ! empty( $copies_hi[ $viz ] ) );
	$t( 'copy is published with the original title', 'publish' === get_post_status( $c_en ) && "W5 network notice $tag" === get_post_field( 'post_title', $c_en ) );
	$t( 'copy records its origin (site, post, URL)', (int) get_post_meta( $c_en, TMC_SYND_ORIGIN_BLOG, true ) === $main && (int) get_post_meta( $c_en, TMC_SYND_ORIGIN_POST, true ) === $en && $origin_url === ( tmc_syndication_origin( $c_en )['url'] ?? '' ) );
	$t( 'copies keep their languages and are linked as translations', 'en' === pll_get_post_language( $c_en ) && 'hi' === pll_get_post_language( $c_hi ) && pll_get_post( $c_en, 'hi' ) === $c_hi );
	$t( 'copy is filed under Notices on the unit site', has_category( 'notices', $c_en ) && has_category( 'suchnayen', $c_hi ) );
	$t( 'expiry date travels with the copy', $expires === get_post_meta( $c_en, '_tmc_expires_at', true ) );
	$t( 'canonical URL of the copy is the original', $origin_url === wp_get_canonical_url( $c_en ) );
	$t( 'copies never syndicate further (sync is a no-op on unit sites)', array() === tmc_syndication_sync( $c_en ) && ! tmc_syndication_busy() );
	$t( 'unit site lists the copy as "Synced from … (read-only)"', isset( tmc_syndication_post_states( array(), get_post( $c_en ) )['tmc_synced'] ) );

	WP_CLI::log( '— Network publishing: copies are read-only on unit sites' );
	wp_set_current_user( $unit_reviewer->ID );
	$res = $rest( 'POST', "/wp/v2/posts/$c_en", array( 'title' => 'Edited on the unit site' ) );
	$t( 'unit Reviewer cannot edit a copy (HTTP ' . $res->get_status() . ')', 403 === $res->get_status() );
	$res = $rest( 'DELETE', "/wp/v2/posts/$c_en" );
	$t( 'unit Reviewer cannot delete a copy (HTTP ' . $res->get_status() . ')', 403 === $res->get_status() );
	wp_set_current_user( $unit_admin->ID );
	$t( 'unit Site Administrator cannot edit, delete or re-publish a copy', ! current_user_can( 'edit_post', $c_en ) && ! current_user_can( 'delete_post', $c_en ) && ! current_user_can( 'publish_post', $c_en ) );
	$t( 'copy unchanged', "W5 network notice $tag" === get_post_field( 'post_title', $c_en ) && 'publish' === get_post_status( $c_en ) );
	wp_set_current_user( 0 );

	WP_CLI::log( '— Network publishing: updates, repeated saves, deselection, unpublishing' );
	switch_to_blog( $main );
	wp_set_current_user( $central->ID );
	$mark = $audit_max();
	wp_update_post( array( 'ID' => $en, 'post_title' => "W5 network notice $tag (updated)" ) );
	$updates = $audit_since( $mark, 'network\_copy\_%' );
	restore_current_blog();
	switch_to_blog( $viz );
	$viz_title = get_post_field( 'post_title', $copies_en[ $viz ] ?? 0 );
	restore_current_blog();
	$t( 'an update reaches the copies on every selected site', "W5 network notice $tag (updated)" === get_post_field( 'post_title', $c_en ) && "W5 network notice $tag (updated)" === $viz_title );
	$t( 'only the changed copies are written (' . implode( ', ', $updates ) . ')', array( 'network_copy_updated', 'network_copy_updated' ) === $updates );

	switch_to_blog( $main );
	$mark = $audit_max();
	wp_update_post( array( 'ID' => $en ) ); // saved again without changes
	wp_update_post( array( 'ID' => $en ) );
	$repeat = $audit_since( $mark, 'network\_%' );
	restore_current_blog();
	$t( 'saving an unchanged original writes nothing to the copies', ! $repeat );

	switch_to_blog( $main );
	tmc_syndication_set_targets( $en, array( $tmh ) );
	wp_update_post( array( 'ID' => $en ) );
	restore_current_blog();
	switch_to_blog( $viz );
	$viz_gone = ! get_post( $copies_en[ $viz ] ?? 0 ) && ! get_post( $copies_hi[ $viz ] ?? 0 );
	restore_current_blog();
	$t( 'clearing a site deletes its copies (both languages)', $viz_gone );
	$t( 'the remaining site keeps its copies', get_post( $c_en ) && get_post( $c_hi ) );

	switch_to_blog( $main );
	wp_update_post( array( 'ID' => $en, 'post_status' => 'draft' ) );
	restore_current_blog();
	$t( 'unpublishing the original unpublishes its copy', 'draft' === get_post_status( $c_en ) );
	$t( 'the translation\'s copy is unaffected', 'publish' === get_post_status( $c_hi ) );
	switch_to_blog( $main );
	wp_update_post( array( 'ID' => $en, 'post_status' => 'publish' ) );
	$en_date = get_post_field( 'post_date', $en );
	restore_current_blog();
	$t( 'publishing it again restores the copy, with the original\'s date', 'publish' === get_post_status( $c_en ) && $en_date === get_post_field( 'post_date', $c_en ) );

	WP_CLI::log( '— Network publishing: scheduling' );
	switch_to_blog( $main );
	$when      = wp_date( 'Y-m-d H:i:s', time() + 3 * DAY_IN_SECONDS );
	$scheduled = (int) wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => "W5 scheduled notice $tag", 'post_content' => 'Scheduled notice.' ) );
	$origins[] = $scheduled;
	pll_set_post_language( $scheduled, 'en' );
	tmc_syndication_set_targets( $scheduled, array( $tmh ) );
	wp_update_post( array( 'ID' => $scheduled, 'post_status' => 'future', 'post_date' => $when, 'edit_date' => true ) );
	$sched_copy = tmc_syndication_copies( $scheduled )[ $tmh ] ?? 0;
	restore_current_blog();
	$t( 'a scheduled original gives a copy scheduled for the same time', $sched_copy && 'future' === get_post_status( $sched_copy ) && $when === get_post_field( 'post_date', $sched_copy ) );
	switch_to_blog( $main );
	wp_publish_post( $scheduled ); // what the scheduler, or "publish now", does
	restore_current_blog();
	$t( 'when the original goes live, so does the copy', 'publish' === get_post_status( $sched_copy ) );

	WP_CLI::log( '— Network publishing: events' );
	switch_to_blog( $main );
	$event     = (int) wp_insert_post( array( 'post_type' => 'tmc_event', 'post_status' => 'draft', 'post_title' => "W5 network event $tag" ) );
	$origins[] = $event;
	pll_set_post_language( $event, 'en' );
	$start = wp_date( 'Y-m-d 10:00:00', time() + 10 * DAY_IN_SECONDS );
	update_post_meta( $event, '_tmc_start_at', $start );
	update_post_meta( $event, '_tmc_venue', 'Test venue' );
	tmc_syndication_set_targets( $event, array( $tmh ) );
	wp_update_post( array( 'ID' => $event, 'post_status' => 'publish' ) );
	$event_copy = tmc_syndication_copies( $event )[ $tmh ] ?? 0;
	restore_current_blog();
	$t( 'an event is copied with its date and venue', $event_copy && 'tmc_event' === get_post_type( $event_copy ) && $start === get_post_meta( $event_copy, '_tmc_start_at', true ) && 'Test venue' === get_post_meta( $event_copy, '_tmc_venue', true ) );

	WP_CLI::log( '— Network publishing: deletion and audit trail' );
	switch_to_blog( $main );
	foreach ( $origins as $origin_id ) {
		wp_delete_post( $origin_id, true );
	}
	$origins = array();
	restore_current_blog();
	$t( 'deleting the originals deletes every copy', ! get_post( $c_en ) && ! get_post( $c_hi ) && ! get_post( $event_copy ) && ! get_post( $sched_copy ) );
	$logged = array_unique( $audit_since( $first_audit, 'network\_%' ) );
	$t( 'audit log records selection, create, update, unpublish, delete (' . implode( ', ', $logged ) . ')', ! array_diff( array( 'network_publish_targets_changed', 'network_copy_created', 'network_copy_updated', 'network_copy_unpublished', 'network_copy_deleted' ), $logged ) );
	$t( 'audit chain intact', tmc_audit_verify()['ok'] );
	wp_set_current_user( 0 );

	// Writing on other sites must not corrupt their Polylang language cache (front page IDs).
	$front_ok = function ( $blog ) use ( $wpdb ) {
		switch_to_blog( $blog );
		$front  = (int) $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'page_on_front'" ); // raw: Polylang filters get_option()
		$cached = get_transient( 'pll_languages_list' );
		$en     = null;
		foreach ( is_array( $cached ) ? $cached : array() as $language ) {
			if ( 'en' === ( $language['slug'] ?? '' ) ) {
				$en = (int) ( $language['page_on_front'] ?? 0 );
			}
		}
		restore_current_blog();
		return null === $en || $en === $front; // null: not cached (rebuilt correctly on next use)
	};
	$t( 'Polylang front-page cache stays correct on the TMC site and both unit sites', $front_ok( $main ) && $front_ok( $tmh ) && $front_ok( $viz ) );

	/* ============================================================ information architecture */
	WP_CLI::log( '— Audience entry points (R-1-1)' );
	$t( 'migration 050 recorded for this site', in_array( '050-editorial-platform', (array) get_option( 'tmc_migrations' ), true ) );
	$referring = get_page_by_path( 'for-referring-doctors' );
	$students  = get_page_by_path( 'students-and-researchers' );
	$t( '"For referring doctors" and "Students & researchers" pages published', $referring && $students && 'publish' === $referring->post_status && 'publish' === $students->post_status );
	$menu_items = (array) wp_get_nav_menu_items( tmc_ia_menu_id( 'primary' ) );
	$by_object  = array();
	$by_id      = array();
	foreach ( $menu_items as $item ) {
		if ( 'page' === $item->object ) {
			$by_object[ (int) $item->object_id ] = $item;
		}
		$by_id[ (int) $item->ID ] = $item;
	}
	$parent_of = function ( $page ) use ( $by_object, $by_id ) {
		$item   = $page ? ( $by_object[ $page->ID ] ?? null ) : null;
		$parent = $item ? ( $by_id[ (int) $item->menu_item_parent ] ?? null ) : null;
		return $parent ? get_post_field( 'post_name', (int) $parent->object_id ) : '';
	};
	$t( 'main menu: referring doctors under Patient Care, students under Education', 'patient-care' === $parent_of( $referring ) && 'education' === $parent_of( $students ) );
	$quick = array();
	foreach ( (array) wp_get_nav_menu_items( tmc_ia_menu_id( 'footer-quick' ) ) as $item ) {
		if ( 'page' === $item->object ) {
			$quick[] = (int) $item->object_id;
		}
	}
	$t( 'footer quick links include both', $referring && $students && in_array( $referring->ID, $quick, true ) && in_array( $students->ID, $quick, true ) );

	/* ============================================================ component library */
	WP_CLI::log( '— Component library (M3)' );
	$t( 'contrast ratio: black on white is 21:1', 21.0 === tmc_contrast_ratio( '#000', '#ffffff' ) );
	$t( 'contrast ratio: #767676 on white passes AA (4.54:1), #777777 does not (4.47:1)', tmc_contrast_ratio( '#767676', '#fff' ) >= 4.5 && tmc_contrast_ratio( '#777777', '#fff' ) < 4.5 );
	switch_to_blog( $main );
	$library = tmc_component_library_page_id();
	$t( 'component library page exists on the TMC site', $library > 0 );
	if ( $library ) {
		$GLOBALS['wp_query']     = new WP_Query( array( 'page_id' => $library ) ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['wp_the_query'] = $GLOBALS['wp_query']; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$robots                  = tmc_component_library_robots( array( 'max-image-preview' => 'large' ) );
		$t( 'robots: noindex, nofollow', ! empty( $robots['noindex'] ) && ! empty( $robots['nofollow'] ) && ! isset( $robots['max-image-preview'] ) );
		$sitemap = tmc_component_library_sitemap_exclude( array(), 'page' );
		$t( 'excluded from the XML sitemap and the HTML sitemap', in_array( $library, $sitemap['post__not_in'], true ) && in_array( $library, tmc_component_library_list_exclude( array() ), true ) );
		$ids = array();
		foreach ( tmc_component_library_sections() as $group ) {
			foreach ( $group['items'] as $item ) {
				$ids[] = $item[0];
			}
		}
		$missing = array_diff( array( 'colours', 'typography', 'spacing', 'buttons', 'links', 'forms', 'tables', 'badges', 'callouts', 'breadcrumbs', 'tabs', 'pagination', 'cards', 'notice-board', 'dated-list', 'calendar', 'details-list', 'documents', 'faq', 'people', 'section-cards', 'page-templates', 'home-sections' ), $ids );
		$t( 'library covers every component' . ( $missing ? ' (missing: ' . implode( ', ', $missing ) . ')' : '' ), ! $missing );
		$html = tmc_component_library_html();
		$t( 'library renders with contrast grades and template previews (' . strlen( $html ) . ' bytes)', strlen( $html ) > 10000 && false !== strpos( $html, 'cl-grade' ) && false !== strpos( $html, 'tmc/page-faq' ) );
		$GLOBALS['wp_query']     = new WP_Query(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['wp_the_query'] = $GLOBALS['wp_query']; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}
	restore_current_blog();
} finally {
	WP_CLI::log( '— Cleanup' );
	$_POST = array();
	wp_set_current_user( 0 );
	while ( ms_is_switched() ) {
		restore_current_blog();
	}
	foreach ( $pages as $id ) {
		wp_delete_post( $id, true );
	}
	if ( $origins ) {
		switch_to_blog( $main );
		foreach ( $origins as $id ) {
			wp_delete_post( $id, true ); // also removes their copies
		}
		restore_current_blog();
	}
	foreach ( $users as $id ) {
		wpmu_delete_user( $id );
	}
}

WP_CLI::log( '' );
$fail ? WP_CLI::error( "$fail failed, $pass passed" ) : WP_CLI::success( "all $pass checks passed" );
