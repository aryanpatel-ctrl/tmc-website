<?php
/**
 * Tenders & EOIs: current (closing date not passed) and archive.
 */

get_header();
get_template_part(
	'template-parts/notice-table',
	null,
	array(
		'title'           => __( 'Tenders & EOIs', 'tmc' ),
		'intro'           => __( 'Tenders, Expressions of Interest and corrigenda. Items move to the archive automatically after the last date of submission.', 'tmc' ),
		'ref_label'       => __( 'Reference no.', 'tmc' ),
		'title_label'     => __( 'Title', 'tmc' ),
		'caption_current' => __( 'Open tenders and EOIs', 'tmc' ),
		'caption_archive' => __( 'Archived tenders and EOIs', 'tmc' ),
	)
);
get_footer();
