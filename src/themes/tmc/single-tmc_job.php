<?php
/**
 * Single tender / job opening (see template-parts/single-notice.php).
 */

get_header();
while ( have_posts() ) :
	the_post();
	get_template_part( 'template-parts/single-notice' );
endwhile;
get_footer();
