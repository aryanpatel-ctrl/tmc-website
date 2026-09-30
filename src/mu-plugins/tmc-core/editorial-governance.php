<?php
/**
 * Editorial governance: design conformance by construction (tender §4.3, §4.6, §5; R-4.3-2, R-5-1).
 *
 *   - Blocks: Content Editors and Reviewer / Publishers can insert only the approved design-system
 *     blocks (tmc_approved_blocks(): core text/layout blocks plus every "tmc/*" block). Site
 *     Administrators and Super Admins keep the full palette.
 *   - No custom HTML or CSS for editors: no Custom HTML / Classic blocks, no code editor, no
 *     "Edit as HTML", no Advanced panel (CSS classes, HTML anchors), no unlocking of locked
 *     template sections. unfiltered_html and edit_css (Additional CSS) are Super Admin only.
 *   - Patterns: only TMC patterns ("tmc/*"). Core, remote (wordpress.org) and Openverse sources are
 *     switched off; editors cannot create their own reusable patterns.
 *   - The block rules are also enforced on the server when content is saved through the REST API
 *     (which the block editor uses), so they cannot be bypassed with a crafted request.
 *   - Quality gate: content that still holds page-template prompts ("[Replace: …]") or placeholder
 *     links (href="#") cannot be published or scheduled.
 */

defined( 'ABSPATH' ) || exit;

/** Page templates mark every text an editor must replace with this prefix (see theme inc/page-templates.php). */
const TMC_TEMPLATE_PROMPT = '[Replace:';

/**
 * Blocks that make up the TMC design system. Every "tmc/*" block is included automatically, so
 * blocks added by other modules (maps, document library, application gateway) need no change here.
 *
 * @return string[]
 */
function tmc_approved_blocks() {
	$core = array(
		'core/paragraph',
		'core/heading',
		'core/list',
		'core/list-item',
		'core/quote',
		'core/table',
		'core/separator',
		'core/image',
		'core/gallery',
		'core/file',
		'core/video',
		'core/buttons',
		'core/button',
		'core/group',
		'core/columns',
		'core/column',
		'core/details',
	);
	$tmc = array_filter(
		array_keys( WP_Block_Type_Registry::get_instance()->get_all_registered() ),
		fn( $name ) => str_starts_with( $name, 'tmc/' )
	);
	/**
	 * Filters the approved design-system blocks available to non-administrators.
	 *
	 * @param string[] $blocks Block names.
	 */
	return array_values( array_unique( (array) apply_filters( 'tmc_approved_blocks', array_merge( $core, array_values( $tmc ) ) ) ) );
}

/** Site Administrators (manage_options) and Super Admins are not restricted to the approved blocks. */
function tmc_user_has_full_block_palette( $user_id = 0 ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();
	return $user_id > 0 && user_can( $user_id, 'manage_options' );
}

/**
 * Block names in $content that are not approved. Text outside block delimiters (plain text sent by
 * API clients, legacy content) is not a block choice and stays subject to kses like all content;
 * the Classic block itself is not in the inserter.
 *
 * @return string[]
 */
function tmc_unapproved_blocks( $content ) {
	$approved = tmc_approved_blocks();
	$found    = array();
	$walk     = function ( array $blocks ) use ( &$walk, &$found, $approved ) {
		foreach ( $blocks as $block ) {
			$name = $block['blockName'];
			if ( null !== $name && ! in_array( $name, $approved, true ) ) {
				$found[] = $name;
			}
			$walk( $block['innerBlocks'] );
		}
	};
	$walk( parse_blocks( (string) $content ) );
	return array_values( array_unique( $found ) );
}

/** True while page-template prompts or placeholder links remain in the content. */
function tmc_has_template_prompts( $content ) {
	$content = (string) $content;
	return false !== strpos( $content, TMC_TEMPLATE_PROMPT ) || 1 === preg_match( '/href=(["\'])#\1/', $content );
}

/* ---------------------------------------------------------------- block inserter */

add_filter( 'allowed_block_types_all', 'tmc_allowed_block_types', 20, 2 );
function tmc_allowed_block_types( $allowed, $context = null ) {
	if ( tmc_user_has_full_block_palette() ) {
		return $allowed;
	}
	if ( false === $allowed ) {
		return false;
	}
	$approved = tmc_approved_blocks();
	return is_array( $allowed ) ? array_values( array_intersect( $allowed, $approved ) ) : $approved;
}

add_filter( 'block_editor_settings_all', 'tmc_editorial_editor_settings', 20, 2 );
function tmc_editorial_editor_settings( $settings, $context = null ) {
	$settings['enableOpenverseMediaCategory'] = false; // no third-party image sources
	if ( ! tmc_user_has_full_block_palette() ) {
		$settings['codeEditingEnabled'] = false; // no code editor, no "Edit as HTML"
		$settings['canLockBlocks']      = false; // locked template sections stay locked
	}
	return $settings;
}

// The Advanced panel holds free-text CSS classes and HTML anchors: administrators only.
add_action( 'enqueue_block_editor_assets', 'tmc_editorial_editor_assets' );
function tmc_editorial_editor_assets() {
	if ( tmc_user_has_full_block_palette() ) {
		return;
	}
	wp_register_style( 'tmc-editorial-governance', false, array(), TMC_CORE_VERSION );
	wp_enqueue_style( 'tmc-editorial-governance' );
	wp_add_inline_style( 'tmc-editorial-governance', '.block-editor-block-inspector__advanced{display:none!important}' );
}

// No block installs from wordpress.org inside the editor (all software is pinned in this repository).
remove_action( 'enqueue_block_editor_assets', 'wp_enqueue_editor_block_directory_assets' );

/* ---------------------------------------------------------------- custom HTML / CSS */

add_filter( 'map_meta_cap', 'tmc_editorial_code_caps', 10, 3 );
function tmc_editorial_code_caps( $caps, $cap, $user_id ) {
	if ( in_array( $cap, array( 'unfiltered_html', 'edit_css' ), true ) && ! is_super_admin( $user_id ) ) {
		return array( 'do_not_allow' );
	}
	return $caps;
}

/* ---------------------------------------------------------------- patterns */

add_filter( 'should_load_remote_block_patterns', '__return_false' );

// Patterns are only read by the REST API (block editor) and admin screens; prune them there.
add_action( 'rest_api_init', 'tmc_limit_block_patterns', 999 );
add_action( 'admin_init', 'tmc_limit_block_patterns', 999 );
function tmc_limit_block_patterns() {
	$registry = WP_Block_Patterns_Registry::get_instance();
	foreach ( $registry->get_all_registered() as $pattern ) {
		if ( ! str_starts_with( $pattern['name'], 'tmc/' ) ) {
			$registry->unregister( $pattern['name'] );
		}
	}
}

/* ---------------------------------------------------------------- server-side enforcement */

add_action( 'rest_api_init', 'tmc_editorial_rest_hooks' );
function tmc_editorial_rest_hooks() {
	foreach ( get_post_types( array( 'show_in_rest' => true ) ) as $type ) {
		if ( post_type_supports( $type, 'editor' ) ) {
			add_filter( "rest_pre_insert_{$type}", 'tmc_editorial_rest_check', 20, 2 );
		}
	}
}

/**
 * Runs before the REST API writes a post (create, update, autosave).
 *
 * @param stdClass|WP_Error $prepared Post about to be saved.
 * @param WP_REST_Request   $request  Request.
 * @return stdClass|WP_Error
 */
function tmc_editorial_rest_check( $prepared, $request ) {
	if ( is_wp_error( $prepared ) ) {
		return $prepared;
	}
	$existing = empty( $prepared->ID ) ? null : get_post( (int) $prepared->ID );
	$type     = $prepared->post_type ?? ( $existing ? $existing->post_type : '' );
	$full     = tmc_user_has_full_block_palette();

	if ( 'wp_block' === $type && ! $full ) {
		return new WP_Error( 'tmc_patterns_restricted', 'Only Site Administrators can create reusable patterns. Use the TMC patterns in the block inserter.', array( 'status' => 403 ) );
	}

	if ( ! $full && isset( $prepared->post_content ) ) {
		$blocked = tmc_unapproved_blocks( $prepared->post_content );
		if ( $blocked ) {
			return new WP_Error(
				'tmc_block_not_allowed',
				sprintf( 'These blocks are not part of the approved TMC design system: %s. Remove them, or ask a Site Administrator.', implode( ', ', $blocked ) ),
				array( 'status' => 400, 'blocks' => $blocked )
			);
		}
	}

	$status  = $prepared->post_status ?? ( $existing ? $existing->post_status : 'draft' );
	$content = $prepared->post_content ?? ( $existing ? $existing->post_content : '' );
	if ( in_array( $status, array( 'publish', 'future' ), true ) && tmc_has_template_prompts( $content ) ) {
		return new WP_Error(
			'tmc_template_incomplete',
			'This content still contains template prompts ("[Replace: …]") or links that point to "#". Replace them with the real text and links before publishing.',
			array( 'status' => 400 )
		);
	}
	return $prepared;
}
