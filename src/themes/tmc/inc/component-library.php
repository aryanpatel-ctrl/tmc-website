<?php
/**
 * Living component library (M3 "component library coded", R-4.3-1, R-3-1, R-7-3).
 *
 * /component-library/ on the TMC site renders every token and component of the design system with
 * the real PHP functions, CSS classes, blocks and patterns — so what TMC signs off at M3 is exactly
 * what the websites use. Tokens are read from theme.json and main.css at request time: when TMC's
 * Annexure A tokens replace the provisional ones, this page (and every site) changes with them.
 * The page is kept out of search engines, the XML sitemap, the HTML sitemap and site search.
 */

defined( 'ABSPATH' ) || exit;

/* ---------------------------------------------------------------- page identity */

function tmc_component_library_page_id() {
	static $ids = array(); // per site: callers may switch_to_blog()
	$blog = get_current_blog_id();
	if ( ! isset( $ids[ $blog ] ) ) {
		$page         = is_main_site() ? get_page_by_path( 'component-library' ) : null;
		$ids[ $blog ] = $page ? (int) $page->ID : 0;
	}
	return $ids[ $blog ];
}

function tmc_is_component_library() {
	$id = tmc_component_library_page_id();
	return $id && is_page( $id );
}

add_filter( 'wp_robots', 'tmc_component_library_robots' );
function tmc_component_library_robots( array $robots ) {
	if ( tmc_is_component_library() ) {
		unset( $robots['max-image-preview'] );
		$robots['noindex']  = true;
		$robots['nofollow'] = true;
	}
	return $robots;
}

add_action( 'template_redirect', 'tmc_component_library_headers' );
function tmc_component_library_headers() {
	if ( tmc_is_component_library() && ! headers_sent() ) {
		header( 'X-Robots-Tag: noindex, nofollow' );
	}
}

add_filter( 'wp_sitemaps_posts_query_args', 'tmc_component_library_sitemap_exclude', 10, 2 );
function tmc_component_library_sitemap_exclude( $args, $post_type ) {
	$id = tmc_component_library_page_id();
	if ( 'page' === $post_type && $id ) {
		$args['post__not_in'] = array_merge( array_filter( (array) ( $args['post__not_in'] ?? array() ) ), array( $id ) );
	}
	return $args;
}

add_filter( 'wp_list_pages_excludes', 'tmc_component_library_list_exclude' );
function tmc_component_library_list_exclude( $exclude ) {
	$id = tmc_component_library_page_id();
	return $id ? array_merge( (array) $exclude, array( $id ) ) : $exclude;
}

add_action( 'pre_get_posts', 'tmc_component_library_search_exclude' );
function tmc_component_library_search_exclude( WP_Query $query ) {
	if ( is_admin() || ! $query->is_main_query() || ! $query->is_search() ) {
		return;
	}
	$id = tmc_component_library_page_id();
	if ( $id ) {
		$query->set( 'post__not_in', array_merge( array_filter( (array) $query->get( 'post__not_in' ) ), array( $id ) ) );
	}
}

/* ---------------------------------------------------------------- colour contrast (WCAG 2.2) */

/** @return int[]|null [r, g, b] */
function tmc_hex_to_rgb( $hex ) {
	$hex = ltrim( trim( (string) $hex ), '#' );
	if ( 3 === strlen( $hex ) ) {
		$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
	}
	return preg_match( '/^[0-9a-f]{6}$/i', $hex ) ? array_map( 'hexdec', str_split( $hex, 2 ) ) : null;
}

function tmc_relative_luminance( $hex ) {
	$rgb = tmc_hex_to_rgb( $hex );
	if ( ! $rgb ) {
		return null;
	}
	$linear = array_map(
		function ( $channel ) {
			$channel /= 255;
			return $channel <= 0.03928 ? $channel / 12.92 : ( ( $channel + 0.055 ) / 1.055 ) ** 2.4;
		},
		$rgb
	);
	return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
}

/** Contrast ratio, truncated (not rounded) to 2 decimals so 4.499 never shows as a pass. */
function tmc_contrast_ratio( $a, $b ) {
	$la = tmc_relative_luminance( $a );
	$lb = tmc_relative_luminance( $b );
	if ( null === $la || null === $lb ) {
		return 0.0;
	}
	$ratio = ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
	return floor( $ratio * 100 + 1e-9 ) / 100;
}

/** [label, css modifier] for a ratio against WCAG 2.2 AA. */
function tmc_contrast_grade( $ratio ) {
	if ( $ratio >= 4.5 ) {
		return array( __( 'Pass: AA, all text', 'tmc' ), 'pass' );
	}
	if ( $ratio >= 3 ) {
		return array( __( 'Large text and graphics only', 'tmc' ), 'partial' );
	}
	return array( __( 'Not for text', 'tmc' ), 'fail' );
}

/* ---------------------------------------------------------------- tokens */

/** Theme.json preset list (palette, fontSizes, spacingSizes) for the theme origin. */
function tmc_theme_presets( array $path ) {
	$presets = wp_get_global_settings( $path );
	if ( isset( $presets['theme'] ) ) {
		return (array) $presets['theme'];
	}
	return isset( $presets[0] ) ? $presets : array();
}

/**
 * Literal custom properties in the :root block of main.css (colours not in theme.json, radius, widths).
 *
 * @return array<string,string> "--name" => value
 */
function tmc_stylesheet_tokens() {
	$css = (string) file_get_contents( get_template_directory() . '/assets/css/main.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local theme file
	if ( ! preg_match( '/:root\s*\{([^}]*)\}/', $css, $root ) ) {
		return array();
	}
	preg_match_all( '/(--[a-z0-9-]+)\s*:\s*([^;]+);/i', $root[1], $matches, PREG_SET_ORDER );
	$tokens = array();
	foreach ( $matches as $match ) {
		$value = trim( $match[2] );
		if ( ! str_starts_with( $value, 'var(' ) ) {
			$tokens[ $match[1] ] = $value;
		}
	}
	return $tokens;
}

/* ---------------------------------------------------------------- specimens */

function tmc_cl_contrast_cell( $hex, $against ) {
	$ratio               = tmc_contrast_ratio( $hex, $against );
	list( $label, $mod ) = tmc_contrast_grade( $ratio );
	return sprintf( '<strong>%s:1</strong> <span class="cl-grade is-%s">%s</span>', esc_html( number_format_i18n( $ratio, 2 ) ), esc_attr( $mod ), esc_html( $label ) );
}

function tmc_cl_colours() {
	$text = '#1b2330';
	$rows = array();
	foreach ( tmc_theme_presets( array( 'color', 'palette' ) ) as $colour ) {
		if ( 'ink' === ( $colour['slug'] ?? '' ) ) {
			$text = $colour['color'];
		}
		$rows[] = array( $colour['name'] ?? $colour['slug'], '--wp--preset--color--' . $colour['slug'], $colour['color'], 'theme.json' );
	}
	foreach ( tmc_stylesheet_tokens() as $name => $value ) {
		if ( tmc_hex_to_rgb( $value ) ) {
			$rows[] = array( $name, $name, $value, 'main.css' );
		}
	}
	$html = '<div class="table-wrap"><table class="data-table cl-colours"><caption>' . esc_html__( 'Colour tokens and their contrast (WCAG 2.2 AA needs 4.5:1 for text, 3:1 for large text and graphics)', 'tmc' ) . '</caption><thead><tr>';
	foreach ( array( __( 'Colour', 'tmc' ), __( 'Token', 'tmc' ), __( 'Value', 'tmc' ), __( 'Against white', 'tmc' ), __( 'Against body text', 'tmc' ), __( 'Defined in', 'tmc' ) ) as $heading ) {
		$html .= '<th scope="col">' . esc_html( $heading ) . '</th>';
	}
	$html .= '</tr></thead><tbody>';
	foreach ( $rows as list( $label, $token, $value, $source ) ) {
		$hex   = tmc_hex_to_rgb( $value ) ? '#' . ltrim( strtolower( $value ), '#' ) : '';
		$html .= sprintf(
			'<tr><th scope="row" data-label="%1$s"><span class="cl-chip" style="--cl-chip:%2$s" aria-hidden="true"></span> %3$s</th><td data-label="%4$s"><code>var(%5$s)</code></td><td data-label="%6$s"><code>%7$s</code></td><td data-label="%8$s">%9$s</td><td data-label="%10$s">%11$s</td><td data-label="%12$s">%13$s</td></tr>',
			esc_attr__( 'Colour', 'tmc' ),
			esc_attr( $hex ),
			esc_html( $label ),
			esc_attr__( 'Token', 'tmc' ),
			esc_html( $token ),
			esc_attr__( 'Value', 'tmc' ),
			esc_html( $value ),
			esc_attr__( 'Against white', 'tmc' ),
			$hex ? tmc_cl_contrast_cell( $hex, '#ffffff' ) : '—',
			esc_attr__( 'Against body text', 'tmc' ),
			$hex ? tmc_cl_contrast_cell( $hex, $text ) : '—',
			esc_attr__( 'Defined in', 'tmc' ),
			esc_html( $source )
		);
	}
	return $html . '</tbody></table></div>';
}

function tmc_cl_typography() {
	$families = tmc_theme_presets( array( 'typography', 'fontFamilies' ) );
	$html     = '';
	foreach ( $families as $family ) {
		$html .= sprintf( '<p><strong>%s</strong> <code>var(--wp--preset--font-family--%s)</code><br><span class="cl-muted">%s</span></p>', esc_html( $family['name'] ?? $family['slug'] ), esc_html( $family['slug'] ), esc_html( $family['fontFamily'] ?? '' ) );
	}
	$html .= '<ul class="cl-type-scale">';
	foreach ( tmc_theme_presets( array( 'typography', 'fontSizes' ) ) as $size ) {
		$html .= sprintf(
			'<li><span class="cl-type-sample" style="font-size:var(--wp--preset--font-size--%1$s)">%2$s</span> <code>%3$s</code> <span class="cl-muted">%4$s · %5$s</span></li>',
			esc_attr( $size['slug'] ),
			esc_html__( 'Cancer care, research and education', 'tmc' ),
			esc_html( 'var(--wp--preset--font-size--' . $size['slug'] . ')' ),
			esc_html( $size['name'] ?? '' ),
			esc_html( $size['size'] )
		);
	}
	$html .= '</ul><p>' . esc_html__( 'Headings (theme.json styles.elements):', 'tmc' ) . '</p><ul class="cl-list">';
	foreach ( array( 'h1', 'h2', 'h3', 'h4' ) as $level ) {
		$html .= sprintf( '<li><code>%s</code> → <code>%s</code></li>', esc_html( $level ), esc_html( (string) wp_get_global_styles( array( 'elements', $level, 'typography', 'fontSize' ) ) ) );
	}
	return $html . '</ul>';
}

function tmc_cl_spacing() {
	$html = '<ul class="cl-spacing">';
	foreach ( tmc_theme_presets( array( 'spacing', 'spacingSizes' ) ) as $space ) {
		$html .= sprintf(
			'<li><span class="cl-space-bar" style="width:var(--wp--preset--spacing--%1$s)" aria-hidden="true"></span> <code>var(--wp--preset--spacing--%2$s)</code> <span class="cl-muted">%3$s · %4$s</span></li>',
			esc_attr( $space['slug'] ),
			esc_html( $space['slug'] ),
			esc_html( $space['name'] ?? '' ),
			esc_html( $space['size'] )
		);
	}
	$html .= '</ul><div class="table-wrap"><table class="data-table"><caption>' . esc_html__( 'Other stylesheet tokens (main.css)', 'tmc' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Token', 'tmc' ) . '</th><th scope="col">' . esc_html__( 'Value', 'tmc' ) . '</th></tr></thead><tbody>';
	foreach ( tmc_stylesheet_tokens() as $name => $value ) {
		if ( ! tmc_hex_to_rgb( $value ) ) {
			$html .= sprintf( '<tr><th scope="row" data-label="%s"><code>%s</code></th><td data-label="%s"><code>%s</code></td></tr>', esc_attr__( 'Token', 'tmc' ), esc_html( $name ), esc_attr__( 'Value', 'tmc' ), esc_html( $value ) );
		}
	}
	return $html . '</tbody></table></div>';
}

/** Render block arrays (tmc_block() builders) exactly as on the live site. */
function tmc_cl_blocks( array $blocks ) {
	return do_blocks( serialize_blocks( $blocks ) );
}

function tmc_cl_buttons() {
	return tmc_cl_blocks( array( tmc_b_buttons( array( array( __( 'Primary action', 'tmc' ), '#buttons' ), array( __( 'Secondary action', 'tmc' ), '#buttons', true ) ) ) ) )
		. sprintf( '<p class="cl-row"><a class="button" href="#buttons">%s</a> <a class="button is-outline" href="#buttons">%s</a> <button type="button" class="button">%s</button></p>', esc_html__( 'Link styled as button', 'tmc' ), esc_html__( 'Outline link button', 'tmc' ), esc_html__( 'Button element', 'tmc' ) );
}

function tmc_cl_links() {
	return sprintf(
		'<p><a href="#links">%s</a></p><p>%s</p>',
		esc_html__( 'Link within the website', 'tmc' ),
		tmc_external_link( 'https://www.india.gov.in/', __( 'National Portal of India', 'tmc' ) )
	);
}

function tmc_cl_forms() {
	return sprintf(
		'<form class="filter-form" action="#forms" method="get" aria-label="%1$s"><p><label for="cl-form-name">%2$s</label><input type="text" id="cl-form-name" name="cl_name" autocomplete="name"></p><p><label for="cl-form-dept">%3$s</label><select id="cl-form-dept" name="cl_dept"><option value="">%4$s</option><option value="1">%5$s</option></select></p><p class="filter-actions"><button type="submit" class="button">%6$s</button></p></form>',
		esc_attr__( 'Example: filter form', 'tmc' ),
		esc_html__( 'Name', 'tmc' ),
		esc_html__( 'Department', 'tmc' ),
		esc_html__( 'All departments', 'tmc' ),
		esc_html__( 'Sample department', 'tmc' ),
		esc_html__( 'Search', 'tmc' )
	);
}

function tmc_cl_table() {
	$head = array( __( 'S. No.', 'tmc' ), __( 'Reference no.', 'tmc' ), __( 'Title', 'tmc' ), __( 'Last date', 'tmc' ) );
	$html = '<div class="table-wrap"><table class="data-table"><caption>' . esc_html__( 'Sample data table', 'tmc' ) . '</caption><thead><tr>';
	foreach ( $head as $cell ) {
		$html .= '<th scope="col">' . esc_html( $cell ) . '</th>';
	}
	$html .= '</tr></thead><tbody>';
	foreach ( array( 1, 2 ) as $n ) {
		$html .= sprintf(
			'<tr><td data-label="%1$s">%2$d</td><td data-label="%3$s">SAMPLE/%2$d</td><th scope="row" data-label="%4$s">%5$s</th><td data-label="%6$s">%7$s</td></tr>',
			esc_attr( $head[0] ),
			$n,
			esc_attr( $head[1] ),
			esc_attr( $head[2] ),
			/* translators: %d: row number */
			esc_html( sprintf( __( 'Sample item %d', 'tmc' ), $n ) ),
			esc_attr( $head[3] ),
			tmc_time_tag( wp_date( 'Y-m-d 17:00:00', time() + $n * WEEK_IN_SECONDS ) )
		);
	}
	return $html . '</tbody></table></div>';
}

function tmc_cl_badges() {
	$badges = array(
		'open'     => __( 'Open', 'tmc' ),
		'closed'   => __( 'Closed', 'tmc' ),
		'upcoming' => __( 'Upcoming', 'tmc' ),
		'ongoing'  => __( 'Ongoing', 'tmc' ),
		'past'     => __( 'Past', 'tmc' ),
	);
	$html = '<p class="cl-row">';
	foreach ( $badges as $state => $label ) {
		$html .= sprintf( '<span class="badge badge-%s">%s</span> ', esc_attr( $state ), esc_html( $label ) );
	}
	return $html . '<span class="badge-new">' . esc_html__( 'New', 'tmc' ) . '</span></p>';
}

function tmc_cl_callouts() {
	return '<p class="callout">' . esc_html__( 'A callout highlights one important piece of information on a page.', 'tmc' ) . '</p><p class="empty-state">' . esc_html__( 'The empty state explains that a list has no items yet.', 'tmc' ) . '</p>';
}

function tmc_cl_tabs() {
	return sprintf(
		'<nav class="view-tabs" aria-label="%s"><ul><li><a href="#tabs" aria-current="page">%s</a></li><li><a href="#tabs">%s</a></li></ul></nav>',
		esc_attr__( 'Example: view tabs', 'tmc' ),
		esc_html__( 'Current', 'tmc' ),
		esc_html__( 'Archive', 'tmc' )
	);
}

function tmc_cl_breadcrumbs() {
	return sprintf(
		'<nav class="breadcrumbs" aria-label="%s"><ol><li><a href="%s">%s</a></li><li><a href="#breadcrumbs">%s</a></li><li><span aria-current="page">%s</span></li></ol></nav>',
		esc_attr__( 'Example: breadcrumb', 'tmc' ),
		esc_url( tmc_home_url() ),
		esc_html__( 'Home', 'tmc' ),
		esc_html__( 'Section', 'tmc' ),
		esc_html__( 'Current page', 'tmc' )
	);
}

function tmc_cl_pagination() {
	$links = paginate_links(
		array(
			'base'      => '#page-%#%',
			'format'    => '',
			'total'     => 5,
			'current'   => 2,
			'prev_text' => __( 'Previous', 'tmc' ),
			'next_text' => __( 'Next', 'tmc' ),
		)
	);
	return sprintf( '<nav class="navigation pagination" aria-label="%s"><div class="nav-links">%s</div></nav>', esc_attr__( 'Example: pagination', 'tmc' ), wp_kses_post( (string) $links ) );
}

function tmc_cl_cards() {
	return tmc_render_latest_news( array( 'category' => 'news', 'count' => 2 ) );
}

function tmc_cl_details_list() {
	return tmc_details_list(
		array(
			__( 'Reference no.', 'tmc' ) => esc_html( 'SAMPLE/2026/001' ),
			__( 'Last date', 'tmc' )     => tmc_time_tag( wp_date( 'Y-m-d 15:00:00', time() + 2 * WEEK_IN_SECONDS ) ),
			__( 'Status', 'tmc' )        => '<span class="badge badge-open">' . esc_html__( 'Open', 'tmc' ) . '</span>',
		)
	);
}

function tmc_cl_documents() {
	$ids = get_posts(
		array(
			'post_type'      => 'attachment',
			'post_mime_type' => 'application/pdf',
			'post_status'    => 'inherit',
			'posts_per_page' => 2,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);
	if ( $ids ) {
		return tmc_documents_list( $ids );
	}
	return sprintf( '<ul class="doc-list"><li><a class="doc-link" href="#documents">%s</a> <span class="doc-meta">(PDF, 120 KB)</span></li></ul>', esc_html__( 'Sample document', 'tmc' ) );
}

function tmc_cl_dated_list() {
	return tmc_render_tenders_block( array( 'count' => 3 ) );
}

function tmc_cl_notice_board() {
	return tmc_render_notice_board( array( 'category' => 'notices', 'count' => 4 ) );
}

function tmc_cl_calendar() {
	ob_start();
	get_template_part( 'template-parts/event-calendar' );
	return (string) ob_get_clean();
}

function tmc_cl_person() {
	return tmc_cl_blocks( array( tmc_b_area( array( tmc_component_person( __( 'Sample name', 'tmc' ), __( 'Sample designation', 'tmc' ), __( 'A short profile in two or three sentences.', 'tmc' ) ), tmc_component_person( __( 'Sample name', 'tmc' ), __( 'Sample designation', 'tmc' ), __( 'A short profile in two or three sentences.', 'tmc' ) ) ), 'tmc-people', __( 'People', 'tmc' ) ) ) );
}

function tmc_cl_faq() {
	return tmc_cl_blocks( array( tmc_component_question( __( 'Sample question?', 'tmc' ), __( 'The answer opens and closes with the keyboard (Enter or Space) and works without JavaScript.', 'tmc' ) ) ) );
}

function tmc_cl_section_cards() {
	return tmc_cl_blocks( array( tmc_b_area( array( tmc_component_card( __( 'Sample card', 'tmc' ), __( 'One sentence about the page this card leads to.', 'tmc' ), __( 'Read more', 'tmc' ), '#section-cards' ), tmc_component_card( __( 'Sample card', 'tmc' ), __( 'One sentence about the page this card leads to.', 'tmc' ), __( 'Read more', 'tmc' ), '#section-cards' ) ), 'tmc-cards', __( 'Section cards', 'tmc' ) ) ) );
}

function tmc_cl_syndicated_note() {
	/* translators: %s: name of the website that published the original */
	return '<p class="syndicated-note">' . sprintf( esc_html__( 'Published by %s.', 'tmc' ), '<a href="#syndicated-note">' . esc_html( tmc_site_name( get_main_site_id() ) ) . '</a>' ) . '</p>';
}

function tmc_cl_page_templates() {
	$html = '';
	foreach ( tmc_page_templates() as $slug => list( $title, $description ) ) {
		$html .= sprintf(
			'<details class="cl-preview"><summary>%s <code>tmc/page-%s</code></summary><p class="cl-muted">%s</p><div class="cl-preview-body entry-content">%s</div></details>',
			esc_html( $title ),
			esc_html( $slug ),
			esc_html( $description ),
			tmc_cl_blocks( tmc_page_template_blocks( $slug ) )
		);
	}
	return $html;
}

function tmc_cl_home_sections() {
	$sample = __( 'Sample', 'tmc' );
	$blocks = array(
		tmc_section_hero( $sample, __( 'Hero banner heading', 'tmc' ), __( 'One or two sentences introducing the website.', 'tmc' ), array( array( __( 'Primary action', 'tmc' ), '#home-sections' ), array( __( 'Secondary action', 'tmc' ), '#home-sections', true ) ) ),
		tmc_section_quick( __( 'Quick links', 'tmc' ), array( array( 'calendar', __( 'Appointments', 'tmc' ), '#home-sections' ), array( 'guide', __( 'Patient guide', 'tmc' ), '#home-sections' ), array( 'department', __( 'Departments', 'tmc' ), '#home-sections' ), array( 'donate', __( 'Donate', 'tmc' ), '#home-sections' ) ) ),
		tmc_section_stats( __( 'Key facts', 'tmc' ), array( array( '0,000', __( 'sample statistic', 'tmc' ) ), array( '00', __( 'sample statistic', 'tmc' ) ), array( '0000', __( 'sample statistic', 'tmc' ) ) ) ),
		tmc_section_about( __( 'About section', 'tmc' ), __( 'A short paragraph about the institution with a link to read more.', 'tmc' ), array( __( 'Read more', 'tmc' ), '#home-sections' ) ),
	);
	return '<div class="cl-home home-sections">' . tmc_cl_blocks( $blocks ) . '</div>';
}

/**
 * The library: groups of components, each with a live specimen and the names to use.
 *
 * @return array<string,array{title:string,items:array<int,array>}>
 */
function tmc_component_library_sections() {
	return array(
		'tokens'     => array(
			'title' => __( 'Design tokens', 'tmc' ),
			'items' => array(
				array( 'colours', __( 'Colours', 'tmc' ), __( 'Every colour of the palette with its contrast ratio. Editors cannot pick other colours (theme.json: custom colours off).', 'tmc' ), 'tmc_cl_colours', array( 'Source' => 'theme.json settings.color.palette; main.css :root', 'CSS' => 'var(--c-primary), var(--wp--preset--color--primary) …' ) ),
				array( 'typography', __( 'Typography', 'tmc' ), __( 'Font family and type scale. Editors choose only from this scale.', 'tmc' ), 'tmc_cl_typography', array( 'Source' => 'theme.json settings.typography; assets/fonts (OFL)' ) ),
				array( 'spacing', __( 'Spacing and layout', 'tmc' ), __( 'Spacing scale, radius, container width and gutters.', 'tmc' ), 'tmc_cl_spacing', array( 'Source' => 'theme.json settings.spacing, settings.layout; main.css :root' ) ),
			),
		),
		'basics'     => array(
			'title' => __( 'Basic elements', 'tmc' ),
			'items' => array(
				array( 'buttons', __( 'Buttons', 'tmc' ), __( 'Primary and outline buttons; at least 44 × 44 px.', 'tmc' ), 'tmc_cl_buttons', array( 'PHP' => 'tmc_b_buttons()', 'CSS' => '.wp-block-button__link, .is-style-outline, .button, .button.is-outline', 'Block' => 'core/buttons' ) ),
				array( 'links', __( 'Links', 'tmc' ), __( 'Links to other websites open in a new tab and say so to screen-reader users (GIGW).', 'tmc' ), 'tmc_cl_links', array( 'PHP' => 'tmc_external_link(), tmc_mark_external_links()', 'CSS' => '.is-external' ) ),
				array( 'forms', __( 'Forms', 'tmc' ), __( 'Every control has a visible label.', 'tmc' ), 'tmc_cl_forms', array( 'CSS' => '.filter-form, .filter-actions', 'Template' => 'archive-tmc_doctor.php' ) ),
				array( 'tables', __( 'Tables', 'tmc' ), __( 'Caption, column and row headers; cells become labelled rows on small screens.', 'tmc' ), 'tmc_cl_table', array( 'CSS' => '.table-wrap, .data-table (td[data-label])', 'Template' => 'template-parts/notice-table.php' ) ),
				array( 'badges', __( 'Status badges', 'tmc' ), __( 'Lifecycle of tenders, openings and events; "New" on recent notices. Text, not colour alone, carries the meaning.', 'tmc' ), 'tmc_cl_badges', array( 'PHP' => 'tmc_status_badge(), tmc_lifecycle()', 'CSS' => '.badge, .badge-open|closed|upcoming|ongoing|past, .badge-new' ) ),
				array( 'callouts', __( 'Callout and empty state', 'tmc' ), __( 'Highlighted note; message for empty lists.', 'tmc' ), 'tmc_cl_callouts', array( 'CSS' => '.callout, .empty-state', 'Pattern' => 'tmc/component-callout' ) ),
			),
		),
		'navigation' => array(
			'title' => __( 'Navigation', 'tmc' ),
			'items' => array(
				array( 'breadcrumbs', __( 'Breadcrumbs', 'tmc' ), __( 'Shown on every page except the home page.', 'tmc' ), 'tmc_cl_breadcrumbs', array( 'PHP' => 'tmc_breadcrumbs()', 'CSS' => '.breadcrumbs' ) ),
				array( 'tabs', __( 'View tabs', 'tmc' ), __( 'Switch between views of a listing (current / archive, upcoming / calendar / past). Plain links: they work without JavaScript.', 'tmc' ), 'tmc_cl_tabs', array( 'CSS' => '.view-tabs (a[aria-current])' ) ),
				array( 'pagination', __( 'Pagination', 'tmc' ), __( 'Numbered pages for long listings.', 'tmc' ), 'tmc_cl_pagination', array( 'PHP' => 'the_posts_pagination(), paginate_links()', 'CSS' => '.nav-links, .page-numbers' ) ),
				array( 'site-chrome', __( 'Accessibility bar, header, main menu and footer', 'tmc' ), __( 'Present on every page of every website (see the top and bottom of this page): skip link, text size, contrast, language switcher, disclosure mega-menu, contact details, social links, policy links.', 'tmc' ), null, array( 'Template' => 'template-parts/a11y-bar.php, header.php, footer.php', 'PHP' => 'tmc_primary_menu(), tmc_language_switcher(), tmc_contact_details(), tmc_social_links(), tmc_simple_menu()' ) ),
			),
		),
		'content'    => array(
			'title' => __( 'Content components', 'tmc' ),
			'items' => array(
				array( 'cards', __( 'News cards', 'tmc' ), __( 'Latest items of a category (live data).', 'tmc' ), 'tmc_cl_cards', array( 'PHP' => 'tmc_render_latest_news()', 'CSS' => '.card-grid, .card', 'Block' => 'tmc/latest-news' ) ),
				array( 'notice-board', __( 'Notice board', 'tmc' ), __( 'What\'s new, with a pause control for the scrolling (GIGW); expired notices leave automatically (live data).', 'tmc' ), 'tmc_cl_notice_board', array( 'PHP' => 'tmc_render_notice_board()', 'CSS' => '.notice-board', 'Block' => 'tmc/notice-board' ) ),
				array( 'dated-list', __( 'Dated list', 'tmc' ), __( 'Open tenders, current openings and upcoming events (live data).', 'tmc' ), 'tmc_cl_dated_list', array( 'PHP' => 'tmc_dated_list(), tmc_render_tenders_block(), tmc_render_jobs_block(), tmc_render_events_block()', 'CSS' => '.dated-list', 'Block' => 'tmc/tenders, tmc/jobs, tmc/events' ) ),
				array( 'calendar', __( 'Event calendar', 'tmc' ), __( 'Month grid as an accessible table; an agenda list on small screens (live data).', 'tmc' ), 'tmc_cl_calendar', array( 'Template' => 'template-parts/event-calendar.php', 'CSS' => '.cal-grid, .cal-agenda' ) ),
				array( 'details-list', __( 'Details list', 'tmc' ), __( 'Key facts of a tender, opening or event.', 'tmc' ), 'tmc_cl_details_list', array( 'PHP' => 'tmc_details_list()', 'CSS' => '.details' ) ),
				array( 'documents', __( 'Document list', 'tmc' ), __( 'Downloads with file type and size (GIGW).', 'tmc' ), 'tmc_cl_documents', array( 'PHP' => 'tmc_documents_list()', 'CSS' => '.doc-list, .doc-meta' ) ),
				array( 'faq', __( 'Question and answer', 'tmc' ), __( 'Native disclosure (details / summary).', 'tmc' ), 'tmc_cl_faq', array( 'PHP' => 'tmc_component_question()', 'Block' => 'core/details', 'Pattern' => 'tmc/component-question' ) ),
				array( 'people', __( 'Person', 'tmc' ), __( 'Profile card for people and leadership pages.', 'tmc' ), 'tmc_cl_person', array( 'PHP' => 'tmc_component_person()', 'CSS' => '.tmc-people, .tmc-person', 'Pattern' => 'tmc/component-person' ) ),
				array( 'section-cards', __( 'Section cards', 'tmc' ), __( 'Cards that lead to the pages of a section.', 'tmc' ), 'tmc_cl_section_cards', array( 'PHP' => 'tmc_component_card()', 'CSS' => '.tmc-cards, .tmc-card', 'Pattern' => 'tmc/component-card' ) ),
				array( 'syndicated-note', __( 'Source note', 'tmc' ), __( 'Shown under items published centrally by TMC on a unit website.', 'tmc' ), 'tmc_cl_syndicated_note', array( 'PHP' => 'tmc_syndicated_source_note()', 'CSS' => '.syndicated-note' ) ),
			),
		),
		'templates'  => array(
			'title' => __( 'Page templates', 'tmc' ),
			'items' => array(
				array( 'page-templates', __( 'Templates offered when a page is created', 'tmc' ), __( 'Locked sections keep the layout; editors fill the fields. Prompts in square brackets must be replaced before a page can be published.', 'tmc' ), 'tmc_cl_page_templates', array( 'PHP' => 'tmc_page_templates(), tmc_page_template_blocks()', 'Patterns' => 'tmc/page-standard, -landing, -contact, -documents, -service, -people, -faq' ) ),
			),
		),
		'home'       => array(
			'title' => __( 'Home page sections', 'tmc' ),
			'items' => array(
				array( 'home-sections', __( 'Hero, quick links, key facts, about', 'tmc' ), __( 'Locked sections of the home page (sample content). The updates, opportunities and network sections use the live components above.', 'tmc' ), 'tmc_cl_home_sections', array( 'PHP' => 'tmc_section_hero(), tmc_section_quick(), tmc_section_stats(), tmc_section_about(), tmc_section_updates(), tmc_section_opportunities(), tmc_section_network()', 'Patterns' => 'tmc/home-*' ) ),
			),
		),
	);
}

/** Whole library as HTML (used by page-component-library.php). */
function tmc_component_library_html() {
	$sections = tmc_component_library_sections();
	$toc      = '';
	$body     = '';
	foreach ( $sections as $group_id => $group ) {
		$toc  .= sprintf( '<li><a href="#cl-%s">%s</a><ul>', esc_attr( $group_id ), esc_html( $group['title'] ) );
		$body .= sprintf( '<section class="cl-group" aria-labelledby="cl-%1$s"><h2 id="cl-%1$s">%2$s</h2>', esc_attr( $group_id ), esc_html( $group['title'] ) );
		foreach ( $group['items'] as list( $id, $title, $description, $render, $usage ) ) {
			$toc  .= sprintf( '<li><a href="#%s">%s</a></li>', esc_attr( $id ), esc_html( $title ) );
			$body .= sprintf( '<article class="cl-item" aria-labelledby="cl-h-%1$s"><h3 id="cl-h-%1$s"><a class="cl-anchor" id="%1$s" href="#%1$s">%2$s</a></h3><p>%3$s</p>', esc_attr( $id ), esc_html( $title ), esc_html( $description ) );
			if ( $render ) {
				$body .= '<div class="cl-specimen">' . call_user_func( $render ) . '</div>';
			}
			$body .= '<dl class="cl-usage">';
			foreach ( $usage as $label => $value ) {
				$body .= sprintf( '<div><dt>%s</dt><dd><code>%s</code></dd></div>', esc_html( $label ), esc_html( $value ) );
			}
			$body .= '</dl></article>';
		}
		$toc  .= '</ul></li>';
		$body .= '</section>';
	}
	return sprintf( '<nav class="cl-toc" aria-label="%s"><ul>%s</ul></nav>%s', esc_attr__( 'Components', 'tmc' ), $toc, $body );
}
