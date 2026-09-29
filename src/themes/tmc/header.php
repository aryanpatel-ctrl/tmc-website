<?php
/**
 * Site header: skip link, accessibility bar, identity, search, main menu.
 */
?><!doctype html>
<html <?php language_attributes(); ?> data-contrast="normal">
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<?php get_template_part( 'template-parts/a11y-bar' ); ?>

<header class="site-header">
	<div class="container header-row">
		<a class="brand" href="<?php echo esc_url( tmc_home_url() ); ?>" rel="home">
			<img class="brand-mark" src="<?php echo esc_url( get_template_directory_uri() . '/assets/img/logo-mark.svg' ); ?>" width="64" height="64" alt="">
			<span class="brand-text">
				<span class="brand-name"><?php echo esc_html( tmc_site_name() ); ?></span>
				<span class="brand-tagline"><?php echo esc_html( tmc_site_tagline() ); ?></span>
			</span>
		</a>

		<div class="header-tools">
			<?php get_search_form(); ?>
			<button type="button" class="nav-toggle" aria-expanded="false" aria-controls="primary-nav">
				<span class="nav-toggle-bars" aria-hidden="true"></span>
				<span class="nav-toggle-label"><?php esc_html_e( 'Menu', 'tmc' ); ?></span>
			</button>
		</div>
	</div>

	<nav class="primary-nav" id="primary-nav" aria-label="<?php esc_attr_e( 'Main menu', 'tmc' ); ?>">
		<div class="container">
			<?php tmc_primary_menu(); ?>
		</div>
	</nav>
</header>

<main id="main" class="site-main" tabindex="-1">
