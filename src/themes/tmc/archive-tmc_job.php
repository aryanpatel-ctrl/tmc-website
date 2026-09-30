<?php
/**
 * Careers: current openings and archive.
 */

get_header();
get_template_part(
	'template-parts/notice-table',
	null,
	array(
		'title'           => __( 'Careers', 'tmc' ),
		'intro'           => __( 'Recruitment advertisements, forms and results. Openings move to the archive automatically after the last date to apply.', 'tmc' ),
		'ref_label'       => __( 'Advt. no.', 'tmc' ),
		'title_label'     => __( 'Post', 'tmc' ),
		'caption_current' => __( 'Current openings', 'tmc' ),
		'caption_archive' => __( 'Archived openings', 'tmc' ),
	)
);
get_footer();
