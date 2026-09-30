<?php
/**
 * Month calendar of events. Accessible data table (caption, weekday headers, one cell per day);
 * on small screens the same data is shown as an agenda list instead (CSS).
 */

global $wp_locale;

// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$tmc_month = preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', (string) ( $_GET['month'] ?? '' ) ) ? sanitize_text_field( wp_unslash( $_GET['month'] ) ) : wp_date( 'Y-m' );
$tmc_first = date_create_immutable( $tmc_month . '-01 00:00:00', wp_timezone() );
$tmc_last  = $tmc_first->modify( 'last day of this month' )->setTime( 23, 59, 59 );
$tmc_base  = add_query_arg( 'view', 'calendar', get_post_type_archive_link( 'tmc_event' ) );

$tmc_events = get_posts(
	array(
		'post_type'        => 'tmc_event',
		'posts_per_page'   => 200,
		'meta_key'         => '_tmc_start_at',
		'orderby'          => 'meta_value',
		'order'            => 'ASC',
		'suppress_filters' => false,
		'meta_query'       => array(
			array(
				'key'     => '_tmc_start_at',
				'value'   => array( $tmc_first->format( 'Y-m-d H:i:s' ), $tmc_last->format( 'Y-m-d H:i:s' ) ),
				'compare' => 'BETWEEN',
				'type'    => 'DATETIME',
			),
		),
	)
);
$tmc_by_day = array();
foreach ( $tmc_events as $tmc_event ) {
	$tmc_by_day[ (int) substr( tmc_field( $tmc_event->ID, 'tmc_start_at' ), 8, 2 ) ][] = $tmc_event;
}

$tmc_month_label = wp_date( 'F Y', $tmc_first->getTimestamp() );
$tmc_prev        = $tmc_first->modify( '-1 month' );
$tmc_next        = $tmc_first->modify( '+1 month' );
$tmc_today       = wp_date( 'Y-m-d' );
?>
<div class="calendar">
	<div class="calendar-nav">
		<a href="<?php echo esc_url( add_query_arg( 'month', $tmc_prev->format( 'Y-m' ), $tmc_base ) ); ?>"><span aria-hidden="true">←</span> <?php echo esc_html( wp_date( 'F Y', $tmc_prev->getTimestamp() ) ); ?></a>
		<h2 class="calendar-title" id="calendar-title"><?php echo esc_html( $tmc_month_label ); ?></h2>
		<a href="<?php echo esc_url( add_query_arg( 'month', $tmc_next->format( 'Y-m' ), $tmc_base ) ); ?>"><?php echo esc_html( wp_date( 'F Y', $tmc_next->getTimestamp() ) ); ?> <span aria-hidden="true">→</span></a>
	</div>

	<table class="cal-grid" aria-labelledby="calendar-title">
		<thead>
			<tr>
				<?php for ( $tmc_d = 1; $tmc_d <= 7; $tmc_d++ ) : $tmc_wd = $tmc_d % 7; // Monday first ?>
					<th scope="col"><abbr title="<?php echo esc_attr( $wp_locale->get_weekday( $tmc_wd ) ); ?>"><?php echo esc_html( $wp_locale->get_weekday_abbrev( $wp_locale->get_weekday( $tmc_wd ) ) ); ?></abbr></th>
				<?php endfor; ?>
			</tr>
		</thead>
		<tbody>
			<tr>
			<?php
			$tmc_offset = (int) $tmc_first->format( 'N' ) - 1;
			$tmc_days   = (int) $tmc_first->format( 't' );
			for ( $tmc_i = 0; $tmc_i < $tmc_offset; $tmc_i++ ) {
				echo '<td class="cal-empty"></td>';
			}
			for ( $tmc_day = 1; $tmc_day <= $tmc_days; $tmc_day++ ) {
				$tmc_date  = $tmc_first->setDate( (int) $tmc_first->format( 'Y' ), (int) $tmc_first->format( 'm' ), $tmc_day );
				$tmc_class = array( 'cal-day' );
				if ( $tmc_date->format( 'Y-m-d' ) === $tmc_today ) {
					$tmc_class[] = 'is-today';
				}
				if ( ! empty( $tmc_by_day[ $tmc_day ] ) ) {
					$tmc_class[] = 'has-events';
				}
				printf( '<td class="%s"><span class="cal-date">%d</span>', esc_attr( implode( ' ', $tmc_class ) ), (int) $tmc_day );
				if ( ! empty( $tmc_by_day[ $tmc_day ] ) ) {
					echo '<ul>';
					foreach ( $tmc_by_day[ $tmc_day ] as $tmc_event ) {
						printf( '<li><a href="%s">%s</a></li>', esc_url( get_permalink( $tmc_event ) ), esc_html( get_the_title( $tmc_event ) ) );
					}
					echo '</ul>';
				}
				echo '</td>';
				if ( 0 === ( $tmc_offset + $tmc_day ) % 7 && $tmc_day < $tmc_days ) {
					echo '</tr><tr>';
				}
			}
			$tmc_trailing = ( 7 - ( $tmc_offset + $tmc_days ) % 7 ) % 7;
			for ( $tmc_i = 0; $tmc_i < $tmc_trailing; $tmc_i++ ) {
				echo '<td class="cal-empty"></td>';
			}
			?>
			</tr>
		</tbody>
	</table>

	<div class="cal-agenda">
		<?php if ( ! $tmc_events ) : ?>
			<p><?php esc_html_e( 'No events this month.', 'tmc' ); ?></p>
		<?php else : ?>
			<ul class="event-list">
				<?php foreach ( $tmc_events as $tmc_event ) : ?>
					<li><?php echo tmc_time_tag( tmc_field( $tmc_event->ID, 'tmc_start_at' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?> — <a href="<?php echo esc_url( get_permalink( $tmc_event ) ); ?>"><?php echo esc_html( get_the_title( $tmc_event ) ); ?></a></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</div>
</div>
