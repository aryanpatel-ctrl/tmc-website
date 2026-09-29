<?php
/**
 * Accessible menus.
 *
 * Main menu follows the W3C "disclosure navigation" pattern: every top-level item is a real link,
 * with a separate button (aria-expanded) that opens its panel. Works with keyboard, touch and
 * screen readers; Escape closes. Second-level items that have children become mega-menu columns.
 */

defined( 'ABSPATH' ) || exit;

/** Menu items for a location in the current language, grouped by parent ID. */
function tmc_menu_tree( $location ) {
	$locations = get_nav_menu_locations();
	if ( empty( $locations[ $location ] ) ) {
		return array();
	}
	$items = wp_get_nav_menu_items( $locations[ $location ], array( 'update_post_term_cache' => false ) );
	if ( ! $items ) {
		return array();
	}
	_wp_menu_item_classes_by_context( $items ); // sets ->current / ->current_item_ancestor
	$tree = array();
	foreach ( $items as $item ) {
		$tree[ (int) $item->menu_item_parent ][] = $item;
	}
	return $tree;
}

function tmc_menu_link( $item, $class ) {
	return sprintf(
		'<a class="%s" href="%s"%s>%s</a>',
		esc_attr( $class ),
		esc_url( $item->url ),
		$item->current ? ' aria-current="page"' : '',
		esc_html( $item->title )
	);
}

function tmc_primary_menu() {
	$tree = tmc_menu_tree( 'primary' );
	if ( empty( $tree[0] ) ) {
		return;
	}
	echo '<ul class="nav-menu">';
	foreach ( $tree[0] as $item ) {
		$children = $tree[ $item->ID ] ?? array();
		$active   = $item->current || $item->current_item_ancestor || $item->current_item_parent;
		printf( '<li class="nav-item%s%s">', $children ? ' has-sub' : '', $active ? ' is-active' : '' );
		echo tmc_menu_link( $item, 'nav-link' ); // phpcs:ignore WordPress.Security.EscapeOutput

		if ( $children ) {
			$panel_id = 'nav-panel-' . $item->ID;
			printf(
				'<button type="button" class="sub-toggle" aria-expanded="false" aria-controls="%s"><span class="screen-reader-text">%s</span></button>',
				esc_attr( $panel_id ),
				/* translators: %s: menu item title */
				esc_html( sprintf( __( 'Show submenu for %s', 'tmc' ), $item->title ) )
			);
			printf( '<div class="nav-panel" id="%s"><div class="nav-panel-inner">', esc_attr( $panel_id ) );
			if ( $item->description ) {
				printf( '<div class="nav-panel-intro"><p class="nav-panel-title">%s</p><p>%s</p></div>', esc_html( $item->title ), esc_html( $item->description ) );
			}
			echo '<ul class="nav-sub">';
			foreach ( $children as $child ) {
				$grandchildren = $tree[ $child->ID ] ?? array();
				echo '<li class="nav-sub-item' . ( $grandchildren ? ' is-column' : '' ) . '">';
				echo tmc_menu_link( $child, $grandchildren ? 'nav-sub-heading' : 'nav-sub-link' ); // phpcs:ignore WordPress.Security.EscapeOutput
				if ( $grandchildren ) {
					echo '<ul>';
					foreach ( $grandchildren as $grandchild ) {
						echo '<li>' . tmc_menu_link( $grandchild, 'nav-sub-link' ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput
					}
					echo '</ul>';
				}
				echo '</li>';
			}
			echo '</ul></div></div>';
		}
		echo '</li>';
	}
	echo '</ul>';
}

/** Flat list for footer menus. */
function tmc_simple_menu( $location, $class ) {
	$tree = tmc_menu_tree( $location );
	if ( empty( $tree[0] ) ) {
		return;
	}
	echo '<ul class="' . esc_attr( $class ) . '">';
	foreach ( $tree[0] as $item ) {
		echo '<li>' . tmc_menu_link( $item, '' ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo '</ul>';
}
