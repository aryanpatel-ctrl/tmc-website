<?php
/**
 * Document library presentation (data model: mu-plugins/tmc-core/documents.php).
 *
 *   tmc/documents  dynamic block. Fixed list (optionally one document type, "View all" link) or,
 *                  with "library" on, the full library: type + year filters (plain GET form),
 *                  result count, table with file format and size, pagination. The /documents/ page
 *                  uses the library mode. Filter parameters: doc_type, doc_year, doc_page.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', 'tmc_register_documents_block' );
function tmc_register_documents_block() {
	register_block_type(
		'tmc/documents',
		array(
			'render_callback' => 'tmc_render_documents_block',
			'attributes'      => array(
				'type'    => array( 'type' => 'string', 'default' => '' ),
				'count'   => array( 'type' => 'number', 'default' => 10 ),
				'library' => array( 'type' => 'boolean', 'default' => false ),
			),
		)
	);
}

/** Document type name in the visitor's language (standard types; renamed or added types keep their name). */
function tmc_doc_type_label( $term ) {
	if ( ! $term instanceof WP_Term ) {
		return '';
	}
	$labels = array(
		'annual-report'   => __( 'Annual report', 'tmc' ),
		'circular'        => __( 'Circular', 'tmc' ),
		'office-order'    => __( 'Office order', 'tmc' ),
		'form'            => __( 'Form', 'tmc' ),
		'policy'          => __( 'Policy', 'tmc' ),
		'tender-document' => __( 'Tender document', 'tmc' ),
		'result'          => __( 'Result', 'tmc' ),
		'other'           => __( 'Other', 'tmc' ),
	);
	$defaults = function_exists( 'tmc_document_types' ) ? tmc_document_types() : array();
	return ( isset( $labels[ $term->slug ] ) && ( $defaults[ $term->slug ] ?? '' ) === $term->name ) ? $labels[ $term->slug ] : $term->name;
}

/** URL of the Documents page (optionally filtered by type), or ''. */
function tmc_documents_page_url( $type = '' ) {
	$page = get_page_by_path( 'documents' );
	if ( ! $page || 'publish' !== $page->post_status ) {
		return '';
	}
	$url = (string) get_permalink( $page );
	return '' !== $type ? add_query_arg( 'doc_type', rawurlencode( $type ), $url ) : $url;
}

function tmc_render_documents_block( $attributes ) {
	if ( ! function_exists( 'tmc_documents_query' ) ) {
		return '';
	}
	$library = ! empty( $attributes['library'] );
	$count   = max( 1, min( 50, (int) ( $attributes['count'] ?? 10 ) ) );
	$type    = sanitize_key( (string) ( $attributes['type'] ?? '' ) );
	$year    = 0;
	$page    = 1;
	if ( $library ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public filter form
		$type = sanitize_key( wp_unslash( $_GET['doc_type'] ?? '' ) );
		$year = absint( $_GET['doc_year'] ?? 0 );
		$page = max( 1, absint( $_GET['doc_page'] ?? 1 ) );
		// phpcs:enable
	}
	$terms = get_terms( array( 'taxonomy' => 'tmc_doc_type', 'hide_empty' => false, 'orderby' => 'name' ) );
	$terms = is_array( $terms ) ? $terms : array();
	$types = array();
	foreach ( $terms as $term ) {
		$types[ $term->slug ] = tmc_doc_type_label( $term );
	}
	$type  = isset( $types[ $type ] ) ? $type : '';
	$year  = ( $year >= 1900 && $year <= 2100 ) ? $year : 0;
	$query = tmc_documents_query( array( 'type' => $type, 'year' => $year, 'per_page' => $count, 'page' => $page ) );
	$base  = in_the_loop() ? (string) get_permalink() : '';
	$uid   = wp_unique_id( 'doc-' );

	$html = '<div class="doc-library">';

	if ( $library ) {
		$html .= sprintf( '<form class="filter-form doc-filter" method="get" action="%s">', esc_url( $base ) );
		$html .= sprintf( '<p><label for="%1$s-type">%2$s</label><select id="%1$s-type" name="doc_type"><option value="">%3$s</option>', esc_attr( $uid ), esc_html__( 'Document type', 'tmc' ), esc_html__( 'All types', 'tmc' ) );
		foreach ( $types as $slug => $label ) {
			$html .= sprintf( '<option value="%s"%s>%s</option>', esc_attr( $slug ), selected( $type, $slug, false ), esc_html( $label ) );
		}
		$html .= '</select></p>';
		$html .= sprintf( '<p><label for="%1$s-year">%2$s</label><select id="%1$s-year" name="doc_year"><option value="0">%3$s</option>', esc_attr( $uid ), esc_html__( 'Year', 'tmc' ), esc_html__( 'All years', 'tmc' ) );
		foreach ( tmc_documents_years() as $option ) {
			$html .= sprintf( '<option value="%d"%s>%d</option>', (int) $option, selected( $year, $option, false ), (int) $option );
		}
		$html .= '</select></p>';
		$html .= '<p class="filter-actions"><button type="submit" class="button">' . esc_html__( 'Show documents', 'tmc' ) . '</button>';
		if ( $type || $year ) {
			$html .= sprintf( ' <a href="%s">%s</a>', esc_url( $base ), esc_html__( 'Clear filters', 'tmc' ) );
		}
		$html .= '</p></form>';
		$found = (int) $query->found_posts;
		/* translators: %s: number of documents */
		$html .= '<p class="search-count" role="status">' . esc_html( sprintf( _n( '%s document found.', '%s documents found.', $found, 'tmc' ), number_format_i18n( $found ) ) ) . '</p>';
	}

	if ( ! $query->have_posts() ) {
		return $html . '<p class="empty-state">' . esc_html__( 'No documents found.', 'tmc' ) . '</p></div>';
	}

	$caption = implode( ', ', array_filter( array( __( 'Documents', 'tmc' ), $type ? $types[ $type ] : '', $year ? (string) $year : '' ) ) );
	$html   .= '<div class="table-wrap"><table class="data-table doc-table"><caption>' . esc_html( $caption ) . '</caption><thead><tr>';
	foreach ( array( __( 'Title', 'tmc' ), __( 'Type', 'tmc' ), __( 'Date', 'tmc' ), __( 'File', 'tmc' ) ) as $heading ) {
		$html .= '<th scope="col">' . esc_html( $heading ) . '</th>';
	}
	$html .= '</tr></thead><tbody>';
	foreach ( $query->posts as $document ) {
		$info  = tmc_document_file_info( $document->ID );
		$file  = implode( ', ', array_filter( array( $info['ext'], $info['size'] ) ) );
		$title = get_the_title( $document );
		$title = '' !== $title ? $title : esc_html( wp_basename( (string) get_attached_file( $document->ID ) ) );
		$html .= sprintf(
			'<tr><th scope="row" data-label="%1$s"><a class="doc-link" href="%2$s">%3$s<span class="screen-reader-text"> (%4$s)</span></a></th><td data-label="%5$s">%6$s</td><td data-label="%7$s">%8$s</td><td data-label="%9$s"><span class="doc-meta">%4$s</span></td></tr>',
			esc_attr__( 'Title', 'tmc' ),
			esc_url( (string) wp_get_attachment_url( $document->ID ) ),
			wp_kses_post( $title ),
			esc_html( $file ),
			esc_attr__( 'Type', 'tmc' ),
			esc_html( tmc_doc_type_label( tmc_document_type( $document->ID ) ) ),
			esc_attr__( 'Date', 'tmc' ),
			tmc_time_tag( tmc_document_date( $document->ID ) . ' 00:00:00', false ),
			esc_attr__( 'File', 'tmc' )
		);
	}
	$html .= '</tbody></table></div>';

	if ( $library && $query->max_num_pages > 1 ) {
		$filtered = add_query_arg( array_filter( array( 'doc_type' => $type, 'doc_year' => $year ? $year : '' ) ), $base );
		$links    = paginate_links(
			array(
				'base'               => add_query_arg( 'doc_page', '%#%', $filtered ),
				'format'             => '',
				'current'            => $page,
				'total'              => (int) $query->max_num_pages,
				'prev_text'          => __( 'Previous', 'tmc' ),
				'next_text'          => __( 'Next', 'tmc' ),
				'before_page_number' => '<span class="screen-reader-text">' . __( 'Page', 'tmc' ) . ' </span>',
			)
		);
		$html    .= '<nav class="navigation pagination" aria-label="' . esc_attr__( 'Document pages', 'tmc' ) . '"><div class="nav-links">' . $links . '</div></nav>';
	} elseif ( ! $library ) {
		$all = tmc_documents_page_url( $type );
		if ( $all && $query->found_posts > $count ) {
			$html .= sprintf( '<p class="view-all"><a href="%s">%s</a></p>', esc_url( $all ), esc_html__( 'View all documents', 'tmc' ) );
		}
	}
	return $html . '</div>';
}

/* ---------------------------------------------------------------- editor */

add_action( 'enqueue_block_editor_assets', 'tmc_documents_editor_assets' );
function tmc_documents_editor_assets() {
	wp_enqueue_script(
		'tmc-documents-editor',
		get_template_directory_uri() . '/assets/js/documents-editor.js',
		array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render' ),
		tmc_asset_version( 'assets/js/documents-editor.js' ),
		true
	);
	$types = array( array( 'value' => '', 'label' => __( 'All types', 'tmc' ) ) );
	$terms = get_terms( array( 'taxonomy' => 'tmc_doc_type', 'hide_empty' => false, 'orderby' => 'name' ) );
	foreach ( is_array( $terms ) ? $terms : array() as $term ) {
		$types[] = array( 'value' => $term->slug, 'label' => $term->name );
	}
	wp_add_inline_script( 'tmc-documents-editor', 'window.tmcDocumentTypes = ' . wp_json_encode( $types ) . ';', 'before' );
}

/**
 * Page templates (inc/page-templates.php): the Document listing template's "documents" slot keeps
 * its editable heading and gets the document list block (latest documents from the media library;
 * the editor can choose a document type in the block settings) instead of empty file blocks.
 */
add_filter( 'tmc_page_template_slot', 'tmc_documents_template_slot', 10, 3 );
function tmc_documents_template_slot( $blocks, $slot, $fallback = array() ) {
	if ( 'documents' !== $slot || ! function_exists( 'tmc_b_dynamic' ) ) {
		return $blocks;
	}
	$headings = array_values( array_filter( (array) $fallback, fn( $block ) => 'core/heading' === ( $block['blockName'] ?? '' ) ) );
	return array_merge( array_slice( $headings, 0, 1 ), array( tmc_b_dynamic( 'tmc/documents', array( 'count' => 10 ) ) ) );
}
