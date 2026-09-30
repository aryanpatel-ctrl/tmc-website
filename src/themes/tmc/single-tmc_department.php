<?php
/**
 * Single department: overview, contact details, doctors.
 */

get_header();
while ( have_posts() ) :
	the_post();
	$tmc_id      = get_the_ID();
	$tmc_email   = tmc_field( $tmc_id, 'tmc_email' );
	$tmc_phone   = tmc_field( $tmc_id, 'tmc_phone' );
	$tmc_doctors = new WP_Query(
		array(
			'post_type'      => 'tmc_doctor',
			'posts_per_page' => 50,
			'orderby'        => array( 'menu_order' => 'ASC', 'title' => 'ASC' ),
			'meta_query'     => array( array( 'key' => '_tmc_department_ids', 'value' => $tmc_id, 'type' => 'NUMERIC' ) ),
		)
	);
	get_template_part( 'template-parts/page-header', null, array( 'title' => get_the_title() ) );
	?>
	<div class="container page-body">
		<article <?php post_class( 'entry' ); ?>>
			<div class="entry-content"><?php the_content(); ?></div>
			<?php
			echo tmc_details_list( // phpcs:ignore WordPress.Security.EscapeOutput
				array(
					__( 'Head of department', 'tmc' ) => esc_html( tmc_field( $tmc_id, 'tmc_hod' ) ),
					__( 'Location', 'tmc' )           => esc_html( tmc_field( $tmc_id, 'tmc_location' ) ),
					__( 'OPD days and timings', 'tmc' ) => esc_html( tmc_field( $tmc_id, 'tmc_opd_days' ) ),
					__( 'Phone', 'tmc' )              => $tmc_phone ? sprintf( '<a href="tel:%s">%s</a>', esc_attr( preg_replace( '/[^0-9+]/', '', $tmc_phone ) ), esc_html( $tmc_phone ) ) : '',
					__( 'Email', 'tmc' )              => $tmc_email ? sprintf( '<a href="mailto:%1$s">%1$s</a>', esc_html( antispambot( $tmc_email ) ) ) : '',
				)
			);
			?>
			<?php if ( $tmc_doctors->have_posts() ) : ?>
				<h2 class="section-heading"><?php esc_html_e( 'Doctors', 'tmc' ); ?></h2>
				<ul class="card-grid doctor-grid">
					<?php
					while ( $tmc_doctors->have_posts() ) :
						$tmc_doctors->the_post();
						get_template_part( 'template-parts/doctor-card' );
					endwhile;
					wp_reset_postdata();
					?>
				</ul>
			<?php endif; ?>
			<p class="back-link"><a href="<?php echo esc_url( get_post_type_archive_link( 'tmc_department' ) ); ?>"><?php esc_html_e( 'All departments', 'tmc' ); ?></a></p>
		</article>
	</div>
	<?php
endwhile;
get_footer();
