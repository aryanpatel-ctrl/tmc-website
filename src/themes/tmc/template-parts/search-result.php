<?php
/**
 * One search result: content type, date (documents: document type, date, file format and size),
 * title and text snippet with the search words highlighted. Pass ['terms' => string[]].
 */

$tmc_post     = get_post();
$tmc_terms    = $args['terms'] ?? array();
$tmc_is_doc   = 'attachment' === $tmc_post->post_type;
$tmc_current  = function_exists( 'tmc_search_current' ) ? tmc_search_current() : null;
$tmc_snippet  = (string) ( $tmc_current['snippets'][ $tmc_post->ID ] ?? '' );
$tmc_title    = wp_strip_all_tags( html_entity_decode( get_the_title( $tmc_post ), ENT_QUOTES, 'UTF-8' ) );
$tmc_file     = '';
$tmc_doc_type = '';
if ( $tmc_is_doc ) {
	$tmc_url      = wp_get_attachment_url( $tmc_post->ID );
	$tmc_date     = tmc_document_date( $tmc_post->ID ) . ' 00:00:00';
	$tmc_info     = tmc_document_file_info( $tmc_post->ID );
	$tmc_file     = implode( ', ', array_filter( array( $tmc_info['ext'], $tmc_info['size'] ) ) );
	$tmc_doc_type = tmc_doc_type_label( tmc_document_type( $tmc_post->ID ) );
	$tmc_title    = '' !== $tmc_title ? $tmc_title : wp_basename( (string) get_attached_file( $tmc_post->ID ) );
} else {
	$tmc_url  = get_permalink( $tmc_post );
	$tmc_date = get_the_date( 'Y-m-d H:i:s', $tmc_post );
}
?>
<li class="card search-result search-result-<?php echo esc_attr( sanitize_html_class( $tmc_post->post_type ) ); ?>">
	<p class="card-meta">
		<span class="result-type"><?php echo esc_html( tmc_search_type_label( $tmc_post->post_type ) ); ?></span>
		<?php if ( $tmc_doc_type ) : ?>
			<span class="result-sep" aria-hidden="true">·</span> <span class="result-doc-type"><?php echo esc_html( $tmc_doc_type ); ?></span>
		<?php endif; ?>
		<span class="result-sep" aria-hidden="true">·</span> <?php echo tmc_time_tag( $tmc_date, false ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in tmc_time_tag() ?>
		<?php if ( $tmc_file ) : ?>
			<span class="result-sep" aria-hidden="true">·</span> <span class="doc-meta"><?php echo esc_html( $tmc_file ); ?></span>
		<?php endif; ?>
	</p>
	<h2 class="card-title">
		<a href="<?php echo esc_url( (string) $tmc_url ); ?>"><?php echo tmc_search_highlight( $tmc_title, $tmc_terms ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapes every segment ?><?php if ( $tmc_file ) : ?><span class="screen-reader-text"> (<?php echo esc_html( $tmc_file ); ?>)</span><?php endif; ?></a>
	</h2>
	<?php if ( '' !== $tmc_snippet ) : ?>
		<p class="card-text"><?php echo tmc_search_highlight( $tmc_snippet, $tmc_terms ); // phpcs:ignore WordPress.Security.EscapeOutput -- escapes every segment ?></p>
	<?php endif; ?>
</li>
