<?php
/**
 * Events: upcoming (default), month calendar, past.
 */

get_header();

$tmc_view = tmc_view();
$tmc_view = in_array( $tmc_view, array( 'calendar', 'past' ), true ) ? $tmc_view : 'upcoming';
$tmc_base = get_post_type_archive_link( 'tmc_event' );
$tmc_tabs = array(
	'upcoming' => array( __( 'Upcoming', 'tmc' ), $tmc_base ),
	'calendar' => array( __( 'Calendar', 'tmc' ), add_query_arg( 'view', 'calendar', $tmc_base ) ),
	'past'     => array( __( 'Past', 'tmc' ), add_query_arg( 'view', 'past', $tmc_base ) ),
);

get_template_part( 'template-parts/page-header', null, array( 'title' => esc_html__( 'Events', 'tmc' ), 'intro' => __( 'Awareness programmes, academic meetings and other events.', 'tmc' ) ) );
?>
<div class="container page-body is-wide">
	<nav class="view-tabs" aria-label="<?php esc_attr_e( 'Events', 'tmc' ); ?>">
		<ul>
			<?php foreach ( $tmc_tabs as $tmc_key => list( $tmc_label, $tmc_url ) ) : ?>
				<li><a href="<?php echo esc_url( $tmc_url ); ?>"<?php echo $tmc_key === $tmc_view ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $tmc_label ); ?></a></li>
			<?php endforeach; ?>
		</ul>
	</nav>

	<?php if ( 'calendar' === $tmc_view ) : ?>
		<?php get_template_part( 'template-parts/event-calendar' ); ?>
	<?php elseif ( have_posts() ) : ?>
		<ul class="event-cards">
			<?php
			while ( have_posts() ) :
				the_post();
				$tmc_start = tmc_ts( tmc_field( get_the_ID(), 'tmc_start_at' ) );
				?>
				<li class="card event-card">
					<p class="event-date" aria-hidden="true"><span class="event-day"><?php echo esc_html( wp_date( 'd', $tmc_start ) ); ?></span> <span class="event-month"><?php echo esc_html( wp_date( 'M Y', $tmc_start ) ); ?></span></p>
					<div>
						<h2 class="card-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
						<p class="card-meta"><?php echo tmc_time_tag( tmc_field( get_the_ID(), 'tmc_start_at' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?><?php echo tmc_field( get_the_ID(), 'tmc_venue' ) ? ' · ' . esc_html( tmc_field( get_the_ID(), 'tmc_venue' ) ) : ''; ?></p>
						<p class="card-text"><?php echo esc_html( wp_trim_words( get_the_excerpt(), 24 ) ); ?></p>
					</div>
				</li>
			<?php endwhile; ?>
		</ul>
		<?php the_posts_pagination( array( 'prev_text' => __( 'Previous', 'tmc' ), 'next_text' => __( 'Next', 'tmc' ), 'aria_label' => __( 'Pages', 'tmc' ) ) ); ?>
	<?php else : ?>
		<p class="empty-state"><?php echo esc_html( 'past' === $tmc_view ? __( 'There are no past events.', 'tmc' ) : __( 'There are no upcoming events at present.', 'tmc' ) ); ?></p>
	<?php endif; ?>
</div>
<?php
get_footer();
