<?php
/**
 * TMC theme bootstrap.
 *
 *   inc/setup.php          theme supports, menus, text domain, customizer fields
 *   inc/assets.php         CSS/JS/fonts, front-end clean-up
 *   inc/template-tags.php  breadcrumbs, language switcher, network sites, helpers
 *   inc/navigation.php     accessible disclosure mega-menu
 *   inc/blocks.php         dynamic blocks: TMC network, sitemap, notice board, latest news
 *   inc/content.php        content filters (external links)
 *   inc/home-sections.php  component library → locked block sections (patterns + seeding)
 *   inc/content-views.php  tenders, events, careers, departments, doctors: listings, formatting, .ics
 *
 * Feature styles/scripts: assets/css/features/*.css and assets/js/features/*.js load automatically.
 */

defined( 'ABSPATH' ) || exit;

define( 'TMC_THEME_VERSION', '0.1.0' );

// Every file in inc/ is loaded (sorted); files only declare functions and hooks.
foreach ( glob( get_template_directory() . '/inc/*.php' ) as $tmc_file ) {
	require $tmc_file;
}
unset( $tmc_file );
