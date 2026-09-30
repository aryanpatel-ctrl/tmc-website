<?php
/**
 * Single event with "Add to calendar" (.ics).
 */

get_header();
while ( have_posts() ) :
	the_post();
	$tmc_id  = get_the_ID();
	$tmc_reg = tmc_field( $tmc_id, 'tmc_registration_url' );
	get_template_part( 'template-parts/page-header', null, array( 'title' => get_the_title() ) );
	?>
	<div class="container page-body is-narrow">
		<article <?php post_class( 'entry' ); ?>>
			<?php
			echo tmc_details_list( // phpcs:ignore WordPress.Security.EscapeOutput
				array(
					__( 'Starts', 'tmc' ) => tmc_time_tag( tmc_field( $tmc_id, 'tmc_start_at' ) ),
					__( 'Ends', 'tmc' )   => tmc_time_tag( tmc_field( $tmc_id, 'tmc_end_at' ) ),
					__( 'Venue', 'tmc' )  => esc_html( tmc_field( $tmc_id, 'tmc_venue' ) ),
					__( 'Status', 'tmc' ) => tmc_status_badge( $tmc_id ),
				)
			);
			?>
			<p class="event-actions">
				<a class="button" href="<?php echo esc_url( add_query_arg( 'ics', '1', get_permalink() ) ); ?>" download><?php esc_html_e( 'Add to calendar', 'tmc' ); ?> <span class="doc-meta">(ICS)</span></a>
				<?php if ( $tmc_reg && 'past' !== tmc_lifecycle( $tmc_id ) ) : ?>
					<?php echo tmc_external_link( $tmc_reg, __( 'Register', 'tmc' ), 'button is-outline' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<?php endif; ?>
			</p>
			<?php if ( has_post_thumbnail() ) : ?>
				<figure class="entry-image"><?php the_post_thumbnail( 'large' ); ?></figure>
			<?php endif; ?>
			<div class="entry-content"><?php the_content(); ?></div>
			<p class="back-link"><a href="<?php echo esc_url( get_post_type_archive_link( 'tmc_event' ) ); ?>"><?php esc_html_e( 'All events', 'tmc' ); ?></a></p>
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
