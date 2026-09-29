<?php
/**
 * One doctor in a list.
 */

$tmc_id    = get_the_ID();
$tmc_depts = tmc_department_links( $tmc_id );
$tmc_opd   = tmc_field( $tmc_id, 'tmc_opd_days' );
?>
<li class="card doctor-card">
	<?php if ( has_post_thumbnail() ) : ?>
		<?php the_post_thumbnail( 'thumbnail', array( 'class' => 'doctor-photo', 'alt' => '' ) ); ?>
	<?php else : ?>
		<span class="doctor-photo doctor-initials" aria-hidden="true"><?php echo esc_html( tmc_initials( get_the_title() ) ); ?></span>
	<?php endif; ?>
	<div>
		<h2 class="card-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
		<p class="card-meta"><?php echo esc_html( tmc_field( $tmc_id, 'tmc_designation' ) ); ?></p>
		<?php if ( $tmc_depts ) : ?>
			<p class="card-text"><?php echo $tmc_depts; // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
		<?php endif; ?>
		<?php if ( $tmc_opd ) : ?>
			<p class="card-text"><?php esc_html_e( 'OPD', 'tmc' ); ?>: <?php echo esc_html( $tmc_opd ); ?></p>
		<?php endif; ?>
	</div>
</li>
