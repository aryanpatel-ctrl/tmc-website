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
 */

defined( 'ABSPATH' ) || exit;

define( 'TMC_THEME_VERSION', '0.1.0' );

foreach ( array( 'setup', 'assets', 'template-tags', 'navigation', 'blocks', 'content', 'home-sections' ) as $tmc_file ) {
	require get_template_directory() . "/inc/{$tmc_file}.php";
}
unset( $tmc_file );
