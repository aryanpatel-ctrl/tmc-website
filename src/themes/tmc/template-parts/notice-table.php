<?php
/**
 * Current / archive table for tenders and job openings (main query).
 * Args: title, intro, ref_label, title_label, caption_current, caption_archive.
 */

$tmc_archive = 'archive' === tmc_view();
$tmc_base    = get_post_type_archive_link( get_query_var( 'post_type' ) );
$tmc_type    = get_query_var( 'post_type' );

get_template_part( 'template-parts/page-header', null, array( 'title' => esc_html( $args['title'] ), 'intro' => $args['intro'] ) );
?>
<div class="container page-body is-wide">
	<nav class="view-tabs" aria-label="<?php echo esc_attr( $args['title'] ); ?>">
		<ul>
			<li><a href="<?php echo esc_url( $tmc_base ); ?>"<?php echo $tmc_archive ? '' : ' aria-current="page"'; ?>><?php esc_html_e( 'Current', 'tmc' ); ?></a></li>
			<li><a href="<?php echo esc_url( add_query_arg( 'view', 'archive', $tmc_base ) ); ?>"<?php echo $tmc_archive ? ' aria-current="page"' : ''; ?>><?php esc_html_e( 'Archive', 'tmc' ); ?></a></li>
		</ul>
	</nav>

	<?php if ( have_posts() ) : ?>
		<div class="table-wrap">
			<table class="data-table">
				<caption><?php echo esc_html( $tmc_archive ? $args['caption_archive'] : $args['caption_current'] ); ?></caption>
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'S. No.', 'tmc' ); ?></th>
						<th scope="col"><?php echo esc_html( $args['ref_label'] ); ?></th>
						<th scope="col"><?php echo esc_html( $args['title_label'] ); ?></th>
						<th scope="col"><?php esc_html_e( 'Published on', 'tmc' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Last date', 'tmc' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Documents', 'tmc' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'tmc' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					$tmc_n = ( max( 1, get_query_var( 'paged' ) ) - 1 ) * (int) get_query_var( 'posts_per_page' );
					while ( have_posts() ) :
						the_post();
						$tmc_docs = tmc_documents_list( tmc_field( get_the_ID(), 'tmc_documents' ) );
						?>
						<tr>
							<td data-label="<?php esc_attr_e( 'S. No.', 'tmc' ); ?>"><?php echo (int) ++$tmc_n; ?></td>
							<td data-label="<?php echo esc_attr( $args['ref_label'] ); ?>"><?php echo esc_html( tmc_field( get_the_ID(), 'tmc_ref_no' ) ); ?></td>
							<th scope="row" data-label="<?php echo esc_attr( $args['title_label'] ); ?>"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></th>
							<td data-label="<?php esc_attr_e( 'Published on', 'tmc' ); ?>"><?php echo tmc_time_tag( get_the_date( 'Y-m-d H:i:s' ), false ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
							<td data-label="<?php esc_attr_e( 'Last date', 'tmc' ); ?>"><?php echo tmc_time_tag( tmc_field( get_the_ID(), 'tmc_closing_at' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
							<td data-label="<?php esc_attr_e( 'Documents', 'tmc' ); ?>"><?php echo $tmc_docs ? $tmc_docs : '—'; // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
							<td data-label="<?php esc_attr_e( 'Status', 'tmc' ); ?>"><?php echo tmc_status_badge( get_the_ID() ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						</tr>
					<?php endwhile; ?>
				</tbody>
			</table>
		</div>
		<?php
		the_posts_pagination(
			array(
				'prev_text'  => __( 'Previous', 'tmc' ),
				'next_text'  => __( 'Next', 'tmc' ),
				'aria_label' => __( 'Pages', 'tmc' ),
			)
		);
		?>
	<?php else : ?>
		<p class="empty-state"><?php echo esc_html( $tmc_archive ? __( 'There are no archived items.', 'tmc' ) : __( 'There are no open items at present. Please see the archive for earlier ones.', 'tmc' ) ); ?></p>
	<?php endif; ?>
</div>
