<?php
/**
 * Page not found.
 */

get_header();
get_template_part( 'template-parts/page-header', null, array( 'title' => esc_html__( 'Page not found', 'tmc' ) ) );
?>
<div class="container page-body is-narrow">
	<p><?php esc_html_e( 'Sorry, the page you are looking for does not exist or has been moved.', 'tmc' ); ?></p>
	<p><?php esc_html_e( 'Try searching, or go to the home page.', 'tmc' ); ?></p>
	<?php get_search_form(); ?>
	<p><a class="button" href="<?php echo esc_url( tmc_home_url() ); ?>"><?php esc_html_e( 'Go to home page', 'tmc' ); ?></a></p>
</div>
<?php
get_footer();
