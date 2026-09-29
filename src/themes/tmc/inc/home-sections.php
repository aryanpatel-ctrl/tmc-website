<?php
/**
 * Builders for the home-page sections (component library → block markup).
 *
 * Each section is a group locked with templateLock "contentOnly": editors can change the text,
 * links and numbers, but not the structure or styling. Markup is produced with WordPress's own
 * serializer so it always matches what the block editor expects. Used by the patterns in
 * /patterns and by the site seeding script.
 */

defined( 'ABSPATH' ) || exit;

function tmc_block( $name, array $attrs = array(), array $inner = array(), $before = '', $after = '' ) {
	$content = array( $before );
	foreach ( $inner as $index => $unused ) {
		$content[] = $index ? "\n" : '';
		$content[] = null;
	}
	$content[] = $after;
	return array(
		'blockName'    => $name,
		'attrs'        => $attrs,
		'innerBlocks'  => $inner,
		'innerHTML'    => $before . $after,
		'innerContent' => array_values( array_filter( $content, fn( $chunk ) => null === $chunk || '' !== $chunk ) ),
	);
}

function tmc_b_paragraph( $html, $class = '' ) {
	return tmc_block( 'core/paragraph', $class ? array( 'className' => $class ) : array(), array(), sprintf( '<p%s>%s</p>', $class ? ' class="' . esc_attr( $class ) . '"' : '', $html ) );
}

function tmc_b_heading( $text, $class = '', $level = 2 ) {
	$attrs = array();
	if ( 2 !== $level ) {
		$attrs['level'] = $level;
	}
	if ( $class ) {
		$attrs['className'] = $class;
	}
	return tmc_block( 'core/heading', $attrs, array(), sprintf( '<h%1$d class="wp-block-heading%2$s">%3$s</h%1$d>', $level, $class ? ' ' . esc_attr( $class ) : '', esc_html( $text ) ) );
}

function tmc_b_group( array $inner, $class = '', array $attrs = array() ) {
	if ( $class ) {
		$attrs['className'] = $class;
	}
	$classes = trim( 'wp-block-group ' . $class );
	if ( ! empty( $attrs['backgroundColor'] ) ) {
		$classes .= ' has-' . $attrs['backgroundColor'] . '-background-color has-background';
	}
	$tag = $attrs['tagName'] ?? 'div';
	$id  = empty( $attrs['anchor'] ) ? '' : ' id="' . esc_attr( $attrs['anchor'] ) . '"';
	return tmc_block( 'core/group', $attrs, $inner, sprintf( '<%s%s class="%s">', $tag, $id, esc_attr( $classes ) ), "</$tag>" );
}

/** A locked, full-width home section with a 1200px content area. */
function tmc_b_section( array $inner, $class, array $attrs = array() ) {
	$attrs += array(
		'tagName'      => 'section',
		'templateLock' => 'contentOnly',
		'layout'       => array( 'type' => 'constrained', 'contentSize' => '1200px' ),
	);
	return tmc_b_group( $inner, $class, $attrs );
}

/** @param array $buttons [ [ text, url, outline(bool) ], ... ] */
function tmc_b_buttons( array $buttons ) {
	$inner = array();
	foreach ( $buttons as $button ) {
		list( $text, $url ) = $button;
		$outline            = ! empty( $button[2] );
		$inner[]            = tmc_block(
			'core/button',
			$outline ? array( 'className' => 'is-style-outline' ) : array(),
			array(),
			sprintf(
				'<div class="wp-block-button%s"><a class="wp-block-button__link wp-element-button" href="%s">%s</a></div>',
				$outline ? ' is-style-outline' : '',
				esc_url( $url ),
				esc_html( $text )
			)
		);
	}
	return tmc_block( 'core/buttons', array(), $inner, '<div class="wp-block-buttons">', '</div>' );
}

/** @param array $columns [ [ width|null, [blocks] ], ... ] */
function tmc_b_columns( array $columns, $class = '' ) {
	$inner = array();
	foreach ( $columns as list( $width, $blocks ) ) {
		$inner[] = tmc_block(
			'core/column',
			$width ? array( 'width' => $width ) : array(),
			$blocks,
			$width ? '<div class="wp-block-column" style="flex-basis:' . esc_attr( $width ) . '">' : '<div class="wp-block-column">',
			'</div>'
		);
	}
	return tmc_block( 'core/columns', $class ? array( 'className' => $class ) : array(), $inner, '<div class="wp-block-columns' . ( $class ? ' ' . esc_attr( $class ) : '' ) . '">', '</div>' );
}

function tmc_b_dynamic( $name, array $attrs = array() ) {
	return array(
		'blockName'    => $name,
		'attrs'        => $attrs,
		'innerBlocks'  => array(),
		'innerHTML'    => '',
		'innerContent' => array(),
	);
}

/* ---------------------------------------------------------------- sections */

function tmc_section_hero( $kicker, $title, $text, array $buttons ) {
	return tmc_b_section(
		array(
			tmc_b_paragraph( esc_html( $kicker ), 'tmc-kicker' ),
			tmc_b_heading( $title ),
			tmc_b_paragraph( esc_html( $text ) ),
			tmc_b_buttons( $buttons ),
		),
		'tmc-hero'
	);
}

/** @param array $tiles [ [ icon, label, url ], ... ] */
function tmc_section_quick( $title, array $tiles ) {
	$inner = array();
	foreach ( $tiles as list( $icon, $label, $url ) ) {
		$inner[] = tmc_b_group( array( tmc_b_paragraph( sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( $label ) ) ) ), 'tmc-tile tmc-icon-' . $icon );
	}
	return tmc_b_section( array( tmc_b_heading( $title, 'screen-reader-text' ), tmc_b_group( $inner, 'tmc-tiles' ) ), 'tmc-quick' );
}

function tmc_section_updates( $notices_title, $news_title ) {
	return tmc_b_section(
		array(
			tmc_b_columns(
				array(
					array( '40%', array( tmc_b_heading( $notices_title, 'tmc-section-title' ), tmc_b_dynamic( 'tmc/notice-board', array( 'category' => 'notices', 'count' => 6 ) ) ) ),
					array( '60%', array( tmc_b_heading( $news_title, 'tmc-section-title' ), tmc_b_dynamic( 'tmc/latest-news', array( 'category' => 'news', 'count' => 2 ) ) ) ),
				),
				'tmc-split'
			),
		),
		'tmc-section tmc-updates'
	);
}

/** @param array $stats [ [ value, label ], ... ] */
function tmc_section_stats( $title, array $stats ) {
	$columns = array();
	foreach ( $stats as list( $value, $label ) ) {
		$columns[] = array( null, array( tmc_b_group( array( tmc_b_paragraph( esc_html( $value ), 'tmc-stat-value' ), tmc_b_paragraph( esc_html( $label ), 'tmc-stat-label' ) ), 'tmc-stat' ) ) );
	}
	return tmc_b_section( array( tmc_b_heading( $title, 'screen-reader-text' ), tmc_b_columns( $columns ) ), 'tmc-stats' );
}

function tmc_section_about( $title, $text, array $button ) {
	return tmc_b_section(
		array(
			tmc_b_heading( $title, 'tmc-section-title' ),
			tmc_b_paragraph( esc_html( $text ) ),
			tmc_b_buttons( array( $button ) ),
		),
		'tmc-section tmc-about',
		array( 'backgroundColor' => 'surface' )
	);
}

function tmc_section_network( $title, $text ) {
	return tmc_b_section(
		array(
			tmc_b_heading( $title, 'tmc-section-title' ),
			tmc_b_paragraph( esc_html( $text ) ),
			tmc_b_dynamic( 'tmc/network' ),
		),
		'tmc-section tmc-network',
		array( 'anchor' => 'tmc-network' )
	);
}
