<?php
/**
 * Standard page.
 */

get_header();

while ( have_posts() ) :
	the_post();
	get_template_part( 'template-parts/page-header', null, array( 'title' => get_the_title() ) );
	?>
	<div class="container page-body">
		<?php
		$tmc_children = get_pages( array( 'parent' => get_the_ID(), 'sort_column' => 'menu_order,post_title' ) );
		if ( $tmc_children ) :
			?>
			<aside class="section-nav" aria-labelledby="section-nav-title">
				<h2 class="section-nav-title" id="section-nav-title"><?php the_title(); ?></h2>
				<ul>
					<?php foreach ( $tmc_children as $tmc_child ) : ?>
						<li><a href="<?php echo esc_url( get_permalink( $tmc_child ) ); ?>"><?php echo esc_html( get_the_title( $tmc_child ) ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			</aside>
		<?php endif; ?>

		<article <?php post_class( 'entry' ); ?>>
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
