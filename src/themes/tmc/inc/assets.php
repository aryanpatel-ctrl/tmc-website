<?php
/**
 * Styles, scripts and front-end clean-up (performance budget: 1 CSS, 1 deferred JS, no jQuery).
 */

defined( 'ABSPATH' ) || exit;

function tmc_asset_version( $relative ) {
	$path = get_template_directory() . '/' . $relative;
	return file_exists( $path ) ? (string) filemtime( $path ) : TMC_THEME_VERSION;
}

add_action( 'wp_enqueue_scripts', 'tmc_enqueue_assets' );
function tmc_enqueue_assets() {
	wp_enqueue_style( 'tmc-main', get_template_directory_uri() . '/assets/css/main.css', array(), tmc_asset_version( 'assets/css/main.css' ) );
	wp_enqueue_script(
		'tmc-main',
		get_template_directory_uri() . '/assets/js/main.js',
		array(),
		tmc_asset_version( 'assets/js/main.js' ),
		array( 'strategy' => 'defer', 'in_footer' => true )
	);
	// Feature bundles (one file per feature keeps parallel work conflict-free).
	foreach ( glob( get_template_directory() . '/assets/css/features/*.css' ) as $tmc_css ) {
		$tmc_rel = 'assets/css/features/' . basename( $tmc_css );
		wp_enqueue_style( 'tmc-' . basename( $tmc_css, '.css' ), get_template_directory_uri() . '/' . $tmc_rel, array( 'tmc-main' ), tmc_asset_version( $tmc_rel ) );
	}
	foreach ( glob( get_template_directory() . '/assets/js/features/*.js' ) as $tmc_js ) {
		$tmc_rel = 'assets/js/features/' . basename( $tmc_js );
		wp_enqueue_script( 'tmc-' . basename( $tmc_js, '.js' ), get_template_directory_uri() . '/' . $tmc_rel, array( 'tmc-main' ), tmc_asset_version( $tmc_rel ), array( 'strategy' => 'defer', 'in_footer' => true ) );
	}

	wp_localize_script(
		'tmc-main',
		'tmcI18n',
		array(
			'pause'    => __( 'Pause', 'tmc' ),
			'play'     => __( 'Play', 'tmc' ),
			'external' => __( '(opens external website in a new tab)', 'tmc' ),
		)
	);
}

// Preload the font for the current script so text renders without a swap flash.
add_action( 'wp_head', 'tmc_preload_fonts', 1 );
function tmc_preload_fonts() {
	$font = 'hi' === tmc_current_lang() ? 'noto-sans-devanagari-wght.woff2' : 'noto-sans-latin-wght.woff2';
	printf(
		'<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n",
		esc_url( get_template_directory_uri() . '/assets/fonts/' . $font )
	);
}

// Apply saved text-size / contrast preferences before first paint (no flash). Per-viewer only.
// Printed through wp_print_inline_script_tag() so it carries the Content-Security-Policy nonce
// (src/mu-plugins/tmc-core/security-headers.php); no inline script bypasses the policy.
add_action( 'wp_head', 'tmc_prefs_boot', 0 );
function tmc_prefs_boot() {
	wp_print_inline_script_tag( "(function(){try{var d=document.documentElement,s=localStorage.getItem('tmc-font-scale'),c=localStorage.getItem('tmc-contrast');if(s)d.style.fontSize=s+'%';if(c)d.setAttribute('data-contrast',c);}catch(e){}d.classList.add('js');})();", array( 'id' => 'tmc-prefs-boot' ) );
}

// Front-end clean-up.
remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
remove_action( 'wp_print_styles', 'print_emoji_styles' );
remove_action( 'wp_head', 'wp_generator' );          // don't advertise the WordPress version
remove_action( 'wp_head', 'rsd_link' );
remove_action( 'wp_head', 'wlwmanifest_link' );
add_filter( 'the_generator', '__return_empty_string' );

// Editor: register the dynamic TMC blocks client-side (plain JS, no build step).
add_action( 'enqueue_block_editor_assets', 'tmc_editor_assets' );
function tmc_editor_assets() {
	wp_enqueue_script(
		'tmc-blocks-editor',
		get_template_directory_uri() . '/assets/js/blocks-editor.js',
		array( 'wp-blocks', 'wp-element', 'wp-server-side-render', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
		tmc_asset_version( 'assets/js/blocks-editor.js' ),
		true
	);
}
