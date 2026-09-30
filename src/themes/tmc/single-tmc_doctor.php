<?php
/**
 * Doctor profile.
 */

get_header();
while ( have_posts() ) :
	the_post();
	$tmc_id = get_the_ID();
	get_template_part( 'template-parts/page-header', null, array( 'title' => get_the_title(), 'intro' => tmc_field( $tmc_id, 'tmc_designation' ) ) );
	?>
	<div class="container page-body is-narrow">
		<article <?php post_class( 'entry doctor-profile' ); ?>>
			<?php if ( has_post_thumbnail() ) : ?>
				<?php the_post_thumbnail( 'medium', array( 'class' => 'doctor-photo is-large', 'alt' => '' ) ); ?>
			<?php else : ?>
				<span class="doctor-photo doctor-initials is-large" aria-hidden="true"><?php echo esc_html( tmc_initials( get_the_title() ) ); ?></span>
			<?php endif; ?>
			<?php
			echo tmc_details_list( // phpcs:ignore WordPress.Security.EscapeOutput
				array(
					__( 'Designation', 'tmc' )             => esc_html( tmc_field( $tmc_id, 'tmc_designation' ) ),
					__( 'Departments', 'tmc' )             => tmc_department_links( $tmc_id ),
					__( 'Qualifications', 'tmc' )          => esc_html( tmc_field( $tmc_id, 'tmc_qualifications' ) ),
					__( 'Areas of specialisation', 'tmc' ) => esc_html( tmc_field( $tmc_id, 'tmc_specialisation' ) ),
					__( 'OPD days', 'tmc' )                => esc_html( tmc_field( $tmc_id, 'tmc_opd_days' ) ),
				)
			);
			?>
			<div class="entry-content"><?php the_content(); ?></div>
			<p class="back-link"><a href="<?php echo esc_url( get_post_type_archive_link( 'tmc_doctor' ) ); ?>"><?php esc_html_e( 'Find a doctor', 'tmc' ); ?></a></p>
		</article>
	</div>
	<?php
endwhile;
get_footer();
