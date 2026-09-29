<?php
/**
 * Departments.
 */

get_header();
get_template_part( 'template-parts/page-header', null, array( 'title' => esc_html__( 'Departments', 'tmc' ), 'intro' => __( 'Clinical and support departments, their services, OPD timings and contact details.', 'tmc' ) ) );
?>
<div class="container page-body is-wide">
	<?php if ( have_posts() ) : ?>
		<ul class="card-grid">
			<?php
			while ( have_posts() ) :
				the_post();
				$tmc_opd = tmc_field( get_the_ID(), 'tmc_opd_days' );
				?>
				<li class="card">
					<h2 class="card-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
					<p class="card-text"><?php echo esc_html( wp_trim_words( get_the_excerpt(), 22 ) ); ?></p>
					<?php if ( $tmc_opd ) : ?>
						<p class="card-meta"><?php esc_html_e( 'OPD', 'tmc' ); ?>: <?php echo esc_html( $tmc_opd ); ?></p>
					<?php endif; ?>
				</li>
			<?php endwhile; ?>
		</ul>
	<?php else : ?>
		<p class="empty-state"><?php esc_html_e( 'Nothing has been published here yet.', 'tmc' ); ?></p>
	<?php endif; ?>
	<p class="back-link"><a href="<?php echo esc_url( get_post_type_archive_link( 'tmc_doctor' ) ); ?>"><?php esc_html_e( 'Find a doctor', 'tmc' ); ?></a></p>
</div>
<?php
get_footer();
