<?php
/**
 * Single post (news, notices).
 */

get_header();

while ( have_posts() ) :
	the_post();
	get_template_part( 'template-parts/page-header', null, array( 'title' => get_the_title() ) );
	?>
	<div class="container page-body is-narrow">
		<article <?php post_class( 'entry' ); ?>>
			<p class="entry-meta">
				<?php
				/* translators: %s: date */
				printf( esc_html__( 'Published on %s', 'tmc' ), '<time datetime="' . esc_attr( get_the_date( 'c' ) ) . '">' . esc_html( tmc_date( (int) get_post_time( 'U', true ) ) ) . '</time>' );
				?>
			</p>
			<?php if ( has_post_thumbnail() ) : ?>
				<figure class="entry-image"><?php the_post_thumbnail( 'large' ); ?></figure>
			<?php endif; ?>
			<div class="entry-content">
				<?php the_content(); ?>
			</div>
			<p class="entry-updated">
				<?php
				/* translators: %s: date */
				echo esc_html( sprintf( __( 'Last updated: %s', 'tmc' ), tmc_last_updated() ) );
				?>
			</p>
		</article>
	</div>
	<?php
endwhile;

get_footer();
