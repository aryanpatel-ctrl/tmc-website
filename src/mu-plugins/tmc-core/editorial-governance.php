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
 *   - Locked template and home sections keep their structure: a save that removes, moves, unlocks
 *     or restructures one is refused (the editor's "Edit pattern" mode and crafted requests included).
 *   - All of this is enforced on the server whenever a signed-in user saves: REST API (the block
 *     editor) and every other web path (classic form, Quick Edit, bulk edit), so a crafted request
 *     cannot bypass it. Blocks already in a page (placed by an administrator or imported by the
 *     migration toolkit) do not stop others from editing the text around them.
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

/** How often each unapproved block occurs in $content (block name => count). */
function tmc_unapproved_block_counts( $content ) {
	$approved = tmc_approved_blocks();
	$counts   = array();
	$walk     = function ( array $blocks ) use ( &$walk, &$counts, $approved ) {
		foreach ( $blocks as $block ) {
			$name = $block['blockName'];
			if ( null !== $name && ! in_array( $name, $approved, true ) ) {
				$counts[ $name ] = ( $counts[ $name ] ?? 0 ) + 1;
			}
			$walk( $block['innerBlocks'] );
		}
	};
	$walk( parse_blocks( (string) $content ) );
	return $counts;
}

/**
 * Unapproved block names that $content adds. Text outside block delimiters (plain text sent by API
 * clients, legacy content) is not a block choice and stays subject to kses like all content; the
 * Classic block itself is not in the inserter. Blocks already in $previous (content placed by a Site
 * Administrator or imported by the migration toolkit) do not stop others from editing the text around
 * them; adding more of them does.
 *
 * @param string $content  Content about to be saved.
 * @param string $previous Content before this save ('' for new content).
 * @return string[]
 */
function tmc_unapproved_blocks( $content, $previous = '' ) {
	$before = '' === (string) $previous ? array() : tmc_unapproved_block_counts( $previous );
	$added  = array();
	foreach ( tmc_unapproved_block_counts( $content ) as $name => $count ) {
		if ( $count > ( $before[ $name ] ?? 0 ) ) {
			$added[] = $name;
		}
	}
	return $added;
}

/* ---------------------------------------------------------------- locked sections */

/**
 * Blocks whose children editors may add or remove inside a locked section (WordPress treats them as
 * content: list items, buttons, the text inside a disclosure).
 *
 * @return string[]
 */
function tmc_content_containers() {
	return (array) apply_filters( 'tmc_content_containers', array( 'core/list', 'core/buttons', 'core/details', 'core/accordion', 'core/accordion-item', 'core/accordion-panel', 'core/social-links' ) );
}

/** Attributes that shape a block's design; they are fixed inside locked sections (content attributes are free). */
const TMC_DESIGN_ATTRIBUTES = array( 'align', 'backgroundColor', 'className', 'fontFamily', 'fontSize', 'gradient', 'layout', 'lock', 'style', 'tagName', 'templateLock', 'textColor', 'width' );

/** Block name + design attributes of one block, and of its descendants unless it is a content container. */
function tmc_block_structure( array $block, $with_children = true ) {
	$attrs = array_intersect_key( (array) $block['attrs'], array_flip( TMC_DESIGN_ATTRIBUTES ) );
	ksort( $attrs );
	$children = array();
	if ( $with_children && ! in_array( $block['blockName'], tmc_content_containers(), true ) ) {
		foreach ( $block['innerBlocks'] as $inner ) {
			$children[] = tmc_block_structure( $inner );
		}
	}
	return array( $block['blockName'], $attrs, $children );
}

/**
 * Top-level blocks of $content with their lock state (locked = page-template section or area, or a
 * home section).
 *
 * @return array<int,array{key:string,label:string,index:int,locked:bool,remove:bool,move:bool,fixed:bool,shell:string,structure:string}>
 */
function tmc_locked_sections( $content ) {
	$sections = array();
	foreach ( array_values( array_filter( parse_blocks( (string) $content ), fn( $b ) => null !== $b['blockName'] ) ) as $index => $block ) {
		$lock       = is_array( $block['attrs']['lock'] ?? null ) ? $block['attrs']['lock'] : array();
		$fixed      = is_string( $block['attrs']['templateLock'] ?? null ) && '' !== $block['attrs']['templateLock'];
		$class      = is_string( $block['attrs']['className'] ?? null ) ? $block['attrs']['className'] : '';
		$sections[] = array(
			'key'       => $block['blockName'] . ' ' . $class,
			'label'     => '' !== $class ? $class : $block['blockName'],
			'index'     => $index,
			'locked'    => $fixed || ! empty( $lock['remove'] ) || ! empty( $lock['move'] ),
			'remove'    => ! empty( $lock['remove'] ),
			'move'      => ! empty( $lock['move'] ),
			'fixed'     => $fixed,
			'shell'     => md5( (string) wp_json_encode( tmc_block_structure( $block, false ) ) ),
			'structure' => md5( (string) wp_json_encode( tmc_block_structure( $block ) ) ),
		);
	}
	return $sections;
}

/**
 * Locked sections of $previous that $content removes, moves, unlocks or restructures. Matching is by
 * block and CSS class (unique per template), so renaming a section in the List View is harmless.
 *
 * @return string[] Problems, e.g. "removed: tmc-tpl-section tmc-tpl-intro".
 */
function tmc_locked_section_changes( $previous, $content ) {
	$old = array_filter( tmc_locked_sections( $previous ), fn( $s ) => $s['locked'] );
	if ( ! $old ) {
		return array();
	}
	$new      = tmc_locked_sections( $content );
	$used     = array();
	$problems = array();
	$order    = array();
	foreach ( $old as $section ) {
		$match = null;
		foreach ( $new as $candidate ) {
			if ( $candidate['key'] === $section['key'] && ! isset( $used[ $candidate['index'] ] ) ) {
				$match = $candidate;
				break;
			}
		}
		$label = $section['label'];
		if ( ! $match ) {
			if ( $section['remove'] ) {
				$problems[] = "removed: $label";
			}
			continue;
		}
		$used[ $match['index'] ] = true;
		if ( $match['shell'] !== $section['shell'] ) {
			$problems[] = "lock or layout changed: $label";
		} elseif ( $section['fixed'] && $match['structure'] !== $section['structure'] ) {
			$problems[] = "structure changed: $label";
		}
		if ( $section['move'] ) {
			$order[] = array( $label, $match['index'] );
		}
	}
	for ( $i = 1, $n = count( $order ); $i < $n; $i++ ) {
		if ( $order[ $i ][1] < $order[ $i - 1 ][1] ) {
			$problems[] = 'moved: ' . $order[ $i ][0];
		}
	}
	return $problems;
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

/**
 * Content editors write: public post types with the block editor, plus reusable patterns (wp_block).
 * Theme data (navigation, templates, global styles) is not editor content and is not covered.
 *
 * @return string[]
 */
function tmc_governed_post_types() {
	$types = array( 'wp_block' );
	foreach ( get_post_types( array( 'public' => true ) ) as $type ) {
		if ( 'attachment' !== $type && post_type_supports( $type, 'editor' ) ) {
			$types[] = $type;
		}
	}
	return $types;
}

add_action( 'rest_api_init', 'tmc_editorial_rest_hooks' );
function tmc_editorial_rest_hooks() {
	foreach ( tmc_governed_post_types() as $type ) {
		add_filter( "rest_pre_insert_{$type}", 'tmc_editorial_rest_check', 20, 2 );
	}
}

/**
 * The governance rules for one save by the current user.
 *
 * @param string       $type     Post type.
 * @param string|null  $content  Content about to be saved (unslashed); null when the save leaves it unchanged.
 * @param string       $status   Status after the save.
 * @param WP_Post|null $existing The post before the save (null for new content).
 * @return WP_Error|null Null when the save is allowed.
 */
function tmc_editorial_save_error( $type, $content, $status, $existing = null ) {
	$full     = tmc_user_has_full_block_palette();
	$previous = $existing ? (string) $existing->post_content : '';

	if ( 'wp_block' === $type && ! $full ) {
		return new WP_Error( 'tmc_patterns_restricted', 'Only Site Administrators can create reusable patterns. Use the TMC patterns in the block inserter.', array( 'status' => 403 ) );
	}

	if ( ! $full && null !== $content && ( ! $existing || $content !== $previous ) ) {
		$blocked = tmc_unapproved_blocks( $content, $previous );
		if ( $blocked ) {
			return new WP_Error(
				'tmc_block_not_allowed',
				sprintf( 'These blocks are not part of the approved TMC design system: %s. Remove them, or ask a Site Administrator.', implode( ', ', $blocked ) ),
				array( 'status' => 400, 'blocks' => $blocked )
			);
		}
		$changes = $existing ? tmc_locked_section_changes( $previous, $content ) : array();
		if ( $changes ) {
			return new WP_Error(
				'tmc_template_locked',
				sprintf( 'The fixed sections of this page cannot be removed, moved or restructured (%s). Change only their text and links, or ask a Site Administrator.', implode( '; ', $changes ) ),
				array( 'status' => 400, 'sections' => $changes )
			);
		}
	}

	$effective = null !== $content ? $content : $previous;
	if ( in_array( $status, array( 'publish', 'future' ), true ) && tmc_has_template_prompts( $effective ) ) {
		return new WP_Error(
			'tmc_template_incomplete',
			'This content still contains template prompts ("[Replace: …]") or links that point to "#". Replace them with the real text and links before publishing.',
			array( 'status' => 400 )
		);
	}
	return null;
}

/**
 * Runs before the REST API writes a post (create, update). Autosaves never go live; they are
 * checked when the editor saves.
 *
 * @param stdClass|WP_Error $prepared Post about to be saved.
 * @param WP_REST_Request   $request  Request.
 * @return stdClass|WP_Error
 */
function tmc_editorial_rest_check( $prepared, $request ) {
	if ( is_wp_error( $prepared ) || ( $request instanceof WP_REST_Request && str_contains( $request->get_route(), '/autosaves' ) ) ) {
		return $prepared;
	}
	$existing = empty( $prepared->ID ) ? null : get_post( (int) $prepared->ID );
	$error    = tmc_editorial_save_error(
		(string) ( $prepared->post_type ?? ( $existing ? $existing->post_type : '' ) ),
		isset( $prepared->post_content ) ? (string) $prepared->post_content : null,
		(string) ( $prepared->post_status ?? ( $existing ? $existing->post_status : 'draft' ) ),
		$existing
	);
	return $error ? $error : $prepared;
}

/**
 * The same rules for every other way a signed-in user saves content (classic post.php form, Quick
 * Edit, bulk edit), so nothing bypasses the REST check. The network-publishing sync writes copies of
 * already-checked originals and is not a user edit.
 *
 * @param array $data    Slashed post data about to be written (wp_insert_post_data).
 * @param array $postarr Raw arguments of wp_insert_post().
 * @return WP_Error|null
 */
function tmc_editorial_check_insert( array $data, array $postarr ) {
	if ( ! is_user_logged_in() || ! in_array( (string) ( $data['post_type'] ?? '' ), tmc_governed_post_types(), true ) ) {
		return null;
	}
	if ( function_exists( 'tmc_syndication_busy' ) && tmc_syndication_busy() ) {
		return null;
	}
	$existing = empty( $postarr['ID'] ) ? null : get_post( (int) $postarr['ID'] );
	$content  = wp_unslash( (string) ( $data['post_content'] ?? '' ) );
	return tmc_editorial_save_error(
		(string) $data['post_type'],
		( $existing && $content === $existing->post_content ) ? null : $content,
		(string) ( $data['post_status'] ?? 'draft' ),
		$existing
	);
}

// Web requests only: REST has its own check (above); WP-CLI is operators and seeding; restoring a
// revision brings back the post's own history.
add_filter( 'wp_insert_post_data', 'tmc_editorial_enforce_on_save', 99, 2 );
function tmc_editorial_enforce_on_save( $data, $postarr ) {
	if ( ( defined( 'WP_CLI' ) && WP_CLI ) || wp_is_serving_rest_request() || 'revision.php' === ( $GLOBALS['pagenow'] ?? '' ) ) {
		return $data;
	}
	$error = tmc_editorial_check_insert( (array) $data, (array) $postarr );
	if ( $error ) {
		$status = (int) ( $error->get_error_data()['status'] ?? 400 );
		wp_die( esc_html( $error->get_error_message() ), 'Not saved', array( 'response' => $status, 'back_link' => true ) );
	}
	return $data;
}
