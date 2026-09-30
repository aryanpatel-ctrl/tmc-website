<?php
/**
 * Editorial roles (tender §4.6).
 *
 *   Super Admin           TMC IT — whole network: sites, users, plugins, security
 *   Site Administrator    one site — settings, menus, users of that site      (WP "administrator")
 *   Reviewer / Publisher  reviews, approves and publishes content             (WP "editor")
 *   Content Editor        writes drafts and submits them for review;
 *                         cannot publish or change live content               (WP "contributor" + pages, media)
 *
 * Built-in role slugs are kept so core and plugins still recognise them; only labels and a
 * few capabilities change. Roles are stored per site, so they are re-applied on each site
 * whenever TMC_ROLES_VERSION is bumped.
 */

defined( 'ABSPATH' ) || exit;

const TMC_ROLES_VERSION = 1;

const TMC_ROLE_LABELS = array(
	'administrator' => 'Site Administrator',
	'editor'        => 'Reviewer / Publisher',
	'contributor'   => 'Content Editor',
);

add_action( 'init', 'tmc_roles_sync', 1 );
function tmc_roles_sync() {
	// Not while WordPress or a site is still being installed (its tables do not exist yet).
	if ( ( function_exists( 'tmc_site_installed' ) && ! tmc_site_installed() ) || (int) get_option( 'tmc_roles_version' ) === TMC_ROLES_VERSION ) {
		return;
	}

	// "Author" can publish its own work without review, which would bypass the workflow.
	remove_role( 'author' );

	$content_editor = get_role( 'contributor' );
	if ( $content_editor ) {
		// Drafts only: without edit_published_* / publish_* they cannot touch live content.
		foreach ( array( 'upload_files', 'edit_pages', 'edit_others_posts', 'edit_others_pages', 'delete_pages' ) as $cap ) {
			$content_editor->add_cap( $cap );
		}
	}

	update_option( 'tmc_roles_version', TMC_ROLES_VERSION );
}

add_action( 'wp_roles_init', 'tmc_roles_labels' );
function tmc_roles_labels( WP_Roles $wp_roles ) {
	foreach ( TMC_ROLE_LABELS as $role => $label ) {
		if ( isset( $wp_roles->roles[ $role ] ) ) {
			$wp_roles->roles[ $role ]['name'] = $label;
			$wp_roles->role_names[ $role ]    = $label;
		}
	}
}
