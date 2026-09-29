<?php
/**
 * Single tender / job opening: key facts first, then the description and documents.
 */

$tmc_id   = get_the_ID();
$tmc_type = get_post_type();
$tmc_rows = array();
if ( 'tmc_tender' === $tmc_type ) {
	$tmc_kinds = array(
		'tender'      => __( 'Tender', 'tmc' ),
		'eoi'         => __( 'Expression of Interest (EOI)', 'tmc' ),
		'rfp'         => __( 'Request for Proposal (RFP)', 'tmc' ),
		'corrigendum' => __( 'Corrigendum', 'tmc' ),
	);
	$tmc_portal = tmc_field( $tmc_id, 'tmc_portal_url' );
	$tmc_rows   = array(
		__( 'Reference no.', 'tmc' )                    => esc_html( tmc_field( $tmc_id, 'tmc_ref_no' ) ),
		__( 'Type', 'tmc' )                             => esc_html( $tmc_kinds[ tmc_field( $tmc_id, 'tmc_kind' ) ] ?? '' ),
		__( 'Published on', 'tmc' )                     => tmc_time_tag( get_the_date( 'Y-m-d H:i:s' ), false ),
		__( 'Last date and time of submission', 'tmc' ) => tmc_time_tag( tmc_field( $tmc_id, 'tmc_closing_at' ) ),
		__( 'Bid opening', 'tmc' )                      => tmc_time_tag( tmc_field( $tmc_id, 'tmc_opening_at' ) ),
		__( 'Status', 'tmc' )                           => tmc_status_badge( $tmc_id ),
		__( 'Submission', 'tmc' )                       => $tmc_portal ? tmc_external_link( $tmc_portal, __( 'e-Procurement portal', 'tmc' ) ) : '',
	);
} else {
	$tmc_apply = tmc_field( $tmc_id, 'tmc_apply_url' );
	$tmc_rows  = array(
		__( 'Advt. no.', 'tmc' )          => esc_html( tmc_field( $tmc_id, 'tmc_ref_no' ) ),
		__( 'Number of posts', 'tmc' )    => esc_html( tmc_field( $tmc_id, 'tmc_vacancies' ) ),
		__( 'Published on', 'tmc' )       => tmc_time_tag( get_the_date( 'Y-m-d H:i:s' ), false ),
		__( 'Last date to apply', 'tmc' ) => tmc_time_tag( tmc_field( $tmc_id, 'tmc_closing_at' ) ),
		__( 'Status', 'tmc' )             => tmc_status_badge( $tmc_id ),
		__( 'Apply', 'tmc' )              => ( $tmc_apply && 'open' === tmc_lifecycle( $tmc_id ) ) ? tmc_external_link( $tmc_apply, __( 'Online application', 'tmc' ) ) : '',
	);
}
$tmc_docs = tmc_documents_list( tmc_field( $tmc_id, 'tmc_documents' ) );

get_template_part( 'template-parts/page-header', null, array( 'title' => get_the_title() ) );
?>
<div class="container page-body is-narrow">
	<article <?php post_class( 'entry' ); ?>>
		<?php echo tmc_details_list( $tmc_rows ); // phpcs:ignore WordPress.Security.EscapeOutput ?>

		<?php if ( 'closed' === tmc_lifecycle( $tmc_id ) ) : ?>
			<p class="callout"><?php esc_html_e( 'The last date for this item has passed. It is kept here for reference.', 'tmc' ); ?></p>
		<?php endif; ?>

		<div class="entry-content">
			<?php the_content(); ?>
		</div>

		<?php if ( $tmc_docs ) : ?>
			<h2 class="section-heading"><?php esc_html_e( 'Documents', 'tmc' ); ?></h2>
			<?php echo $tmc_docs; // phpcs:ignore WordPress.Security.EscapeOutput ?>
		<?php endif; ?>

		<p class="back-link"><a href="<?php echo esc_url( get_post_type_archive_link( $tmc_type ) ); ?>"><?php echo esc_html( 'tmc_tender' === $tmc_type ? __( 'All tenders and EOIs', 'tmc' ) : __( 'All openings', 'tmc' ) ); ?></a></p>
		<p class="entry-updated">
			<?php
			/* translators: %s: date */
			echo esc_html( sprintf( __( 'Last updated: %s', 'tmc' ), tmc_last_updated() ) );
			?>
		</p>
	</article>
</div>
