<?php
/**
 * Theme supports, menu locations, translations and per-site contact details.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'after_setup_theme', 'tmc_setup' );
function tmc_setup() {
	load_theme_textdomain( 'tmc', get_template_directory() . '/languages' );

	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'editor-styles' );
	add_theme_support( 'html5', array( 'search-form', 'gallery', 'caption', 'style', 'script', 'navigation-widgets' ) );
	remove_theme_support( 'core-block-patterns' ); // only TMC patterns, so editors stay within the design system

	add_editor_style( 'assets/css/main.css' ); // editors see exactly what visitors see

	register_nav_menus(
		array(
			'primary'         => __( 'Main menu', 'tmc' ),
			'footer-quick'    => __( 'Footer: quick links', 'tmc' ),
			'footer-policies' => __( 'Footer: website policies', 'tmc' ),
		)
	);

	add_image_size( 'tmc-card', 640, 400, true );
}

// Per-block CSS: only the styles for blocks actually on the page are loaded.
add_filter( 'should_load_separate_core_block_assets', '__return_true' );

add_action( 'init', 'tmc_register_pattern_category' );
function tmc_register_pattern_category() {
	register_block_pattern_category( 'tmc-home', array( 'label' => __( 'TMC: Home page sections', 'tmc' ) ) );
	register_block_pattern_category( 'tmc-page', array( 'label' => __( 'TMC: Page sections', 'tmc' ) ) );
}

/**
 * Per-site contact details and social links (Appearance → Customize → TMC contact details).
 * Kept in the CMS so each unit can maintain its own without code changes.
 */
function tmc_contact_fields() {
	return array(
		'tmc_address'   => array( __( 'Address', 'tmc' ), 'textarea' ),
		'tmc_phone'     => array( __( 'Phone', 'tmc' ), 'text' ),
		'tmc_email'     => array( __( 'Email', 'tmc' ), 'email' ),
		'tmc_facebook'  => array( 'Facebook URL', 'url' ),
		'tmc_x'         => array( 'X (Twitter) URL', 'url' ),
		'tmc_youtube'   => array( 'YouTube URL', 'url' ),
		'tmc_instagram' => array( 'Instagram URL', 'url' ),
		'tmc_linkedin'  => array( 'LinkedIn URL', 'url' ),
	);
}

// The address can be translated in Languages → Translations (group "TMC contact details").
add_action( 'init', 'tmc_register_translatable_mods' );
function tmc_register_translatable_mods() {
	$address = get_theme_mod( 'tmc_address' );
	if ( $address && function_exists( 'pll_register_string' ) ) {
		pll_register_string( 'tmc_address', $address, 'TMC contact details', true );
	}
}

function tmc_translated_mod( $mod ) {
	$value = (string) get_theme_mod( $mod );
	return ( $value && function_exists( 'pll__' ) ) ? pll__( $value ) : $value;
}

add_action( 'customize_register', 'tmc_customize_register' );
function tmc_customize_register( WP_Customize_Manager $wp_customize ) {
	$wp_customize->add_section( 'tmc_contact', array( 'title' => __( 'TMC contact details', 'tmc' ), 'priority' => 30 ) );
	$sanitizers = array(
		'textarea' => 'sanitize_textarea_field',
		'text'     => 'sanitize_text_field',
		'email'    => 'sanitize_email',
		'url'      => 'esc_url_raw',
	);
	foreach ( tmc_contact_fields() as $id => list( $label, $type ) ) {
		$wp_customize->add_setting( $id, array( 'sanitize_callback' => $sanitizers[ $type ] ) );
		$wp_customize->add_control( $id, array( 'label' => $label, 'section' => 'tmc_contact', 'type' => $type ) );
	}
}
