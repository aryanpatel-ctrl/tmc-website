<?php
/**
 * Lists: blog, category and other archives.
 */

get_header();

if ( is_home() && ! is_front_page() ) {
	$tmc_title = single_post_title( '', false );
} elseif ( is_archive() ) {
	$tmc_title = wp_strip_all_tags( get_the_archive_title() );
} else {
	$tmc_title = __( 'Latest updates', 'tmc' );
}
get_template_part( 'template-parts/page-header', null, array( 'title' => esc_html( $tmc_title ), 'intro' => wp_strip_all_tags( get_the_archive_description() ) ) );
?>
<div class="container page-body is-wide">
	<?php if ( have_posts() ) : ?>
		<ul class="card-grid">
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
				'prev_text'          => __( 'Previous', 'tmc' ),
				'next_text'          => __( 'Next', 'tmc' ),
				'before_page_number' => '<span class="screen-reader-text">' . __( 'Page', 'tmc' ) . ' </span>',
				'aria_label'         => __( 'Pages', 'tmc' ),
			)
		);
		?>
	<?php else : ?>
		<p><?php esc_html_e( 'Nothing has been published here yet.', 'tmc' ); ?></p>
	<?php endif; ?>
</div>
<?php
get_footer();
