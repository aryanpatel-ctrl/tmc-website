<?php
/**
 * Search results.
 */

get_header();

global $wp_query;
/* translators: %s: search terms */
$tmc_title = sprintf( __( 'Search results for “%s”', 'tmc' ), get_search_query() );
get_template_part( 'template-parts/page-header', null, array( 'title' => esc_html( $tmc_title ) ) );
?>
<div class="container page-body is-narrow">
	<?php get_search_form(); ?>
	<p class="search-count" role="status">
		<?php
		$tmc_found = (int) $wp_query->found_posts;
		/* translators: %s: number of results */
		echo esc_html( sprintf( _n( '%s result found.', '%s results found.', $tmc_found, 'tmc' ), number_format_i18n( $tmc_found ) ) );
		?>
	</p>
	<?php if ( have_posts() ) : ?>
		<ul class="result-list">
			<?php
			while ( have_posts() ) :
				the_post();
				get_template_part( 'template-parts/content-card' );
			endwhile;
			?>
		</ul>
		<?php
		the_posts_pagination(
			array(
				'prev_text'  => __( 'Previous', 'tmc' ),
				'next_text'  => __( 'Next', 'tmc' ),
				'aria_label' => __( 'Pages', 'tmc' ),
			)
		);
		?>
	<?php else : ?>
		<p><?php esc_html_e( 'No results found. Please try different keywords.', 'tmc' ); ?></p>
	<?php endif; ?>
</div>
<?php
get_footer();
