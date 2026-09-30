<?php
/**
 * Page removed on purpose (HTTP 410 "Gone"), set by a redirect rule (tmc-core/redirects.php).
 */

get_header();
get_template_part( 'template-parts/page-header', null, array( 'title' => esc_html__( 'This page has been removed', 'tmc' ) ) );
?>
<div class="container page-body is-narrow">
	<p><?php esc_html_e( 'The page you are looking for has been permanently removed from this website.', 'tmc' ); ?></p>
	<p><?php esc_html_e( 'Try searching for what you need, or go to the home page.', 'tmc' ); ?></p>
	<?php get_search_form(); ?>
	<p><a class="button" href="<?php echo esc_url( tmc_home_url() ); ?>"><?php esc_html_e( 'Go to home page', 'tmc' ); ?></a></p>
</div>
<?php
get_footer();
