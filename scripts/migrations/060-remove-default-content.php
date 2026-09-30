<?php
/**
 * 060 — Remove WordPress's default install content (defect found by the W6 link crawler).
 *
 * Every new site gets a "Hello world!" post, a "Sample Page" (the "bike messenger" example text)
 * and a draft "Privacy Policy" page. The first two were published on all six government websites:
 * orphaned (no menu links to them) but reachable, and listed in search results. The draft Privacy
 * Policy also shadowed the TMC Privacy Policy: seed-site-structure.php looks pages up by slug, found
 * the draft, and never created the TMC page, so the footer "Privacy Policy" link on every English
 * page pointed to the draft and returned 404.
 *
 * Only items that still contain WordPress's default text are removed. When the draft Privacy
 * Policy is removed, the footer policy menus are deleted too; seed-site-structure.php runs after the
 * migrations (scripts/setup.sh) and recreates the Privacy Policy page and both menus. On a fresh
 * install this migration runs before the first seeding, so the menus do not exist yet.
 */

$defaults = array(
	// type, slug, statuses, text that only WordPress's default content contains
	array( 'post', 'hello-world', array( 'publish', 'draft', 'private', 'pending', 'future' ), array( 'This is your first post.' ) ),
	array( 'page', 'sample-page', array( 'publish', 'draft', 'private', 'pending', 'future' ), array( 'This is an example page.' ) ),
	array( 'page', 'privacy-policy', array( 'draft' ), array( 'privacy-policy-tutorial', 'Our website address is' ) ),
);

$privacy_removed = false;
foreach ( $defaults as list( $type, $slug, $statuses, $markers ) ) {
	$posts = get_posts(
		array(
			'post_type'        => $type,
			'name'             => $slug,
			'post_status'      => $statuses,
			'posts_per_page'   => 10,
			'lang'             => '', // Polylang: any language
			'suppress_filters' => true,
		)
	);
	foreach ( $posts as $post ) {
		$is_default = false;
		foreach ( $markers as $marker ) {
			if ( false !== strpos( (string) $post->post_content, $marker ) ) {
				$is_default = true;
				break;
			}
		}
		if ( ! $is_default ) {
			WP_CLI::log( "    kept $type /$slug/ (its content has been edited)" );
			continue;
		}
		if ( ! wp_delete_post( $post->ID, true ) ) {
			WP_CLI::warning( "could not remove $type /$slug/ (ID {$post->ID})" );
			return false;
		}
		WP_CLI::log( "    removed WordPress default $type /$slug/" );
		if ( 'privacy-policy' === $slug ) {
			$privacy_removed = true;
		}
	}
}

if ( $privacy_removed ) {
	if ( ! get_post_status( (int) get_option( 'wp_page_for_privacy_policy' ) ) ) {
		update_option( 'wp_page_for_privacy_policy', 0 ); // seed-site-structure.php sets the new page
	}
	foreach ( wp_get_nav_menus() as $menu ) {
		if ( preg_match( '/^Footer policies \(/u', $menu->name ) ) {
			wp_delete_nav_menu( $menu->term_id );
			WP_CLI::log( "    removed menu \"{$menu->name}\" (rebuilt by seed-site-structure.php)" );
		}
	}
}

return true;
