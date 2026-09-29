<?php
/**
 * Home page: the sections are blocks in the Home page content (TMC home patterns), so editors
 * change text, links and images in the CMS while the layout stays locked to the design system.
 */

get_header();

while ( have_posts() ) :
	the_post();
	?>
	<h1 class="screen-reader-text"><?php echo esc_html( tmc_site_name() ); ?></h1>
	<div class="home-sections">
		<?php the_content(); ?>
	</div>
	<?php
endwhile;

get_footer();
