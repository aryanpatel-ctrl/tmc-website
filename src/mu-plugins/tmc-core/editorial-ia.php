<?php
/**
 * Information architecture additions owned by the editorial platform (R-1-1, M3):
 *
 *   - Audience entry points on every site: "For referring doctors" (under Patient Care) and
 *     "Students & researchers" (under Education), in the main menu and footer quick links.
 *   - The living component library page (/component-library/) on the TMC site; the theme renders
 *     it (page-component-library.php) and keeps it out of search engines.
 *
 * tmc_ensure_editorial_ia() is idempotent. It is called by migration 050 (existing sites) and at the
 * end of seed-site-structure.php (fresh installs, where the menus only exist after seeding).
 * New pages are English-first; Hindi versions follow when TMC provides the content.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Audience pages: slug => definition. Copy is deliberately factual: it links to what exists and
 * says plainly which information TMC still has to provide.
 */
function tmc_audience_pages() {
	$name = (string) get_option( 'blogname' );
	$url  = fn( $path ) => esc_url( home_url( $path ) );
	$link = fn( $path, $label ) => sprintf( '<a href="%s">%s</a>', $url( $path ), esc_html( $label ) );
	$p    = fn( $html, $class = '' ) => $class
		? sprintf( '<!-- wp:paragraph {"className":"%1$s"} --><p class="%1$s">%2$s</p><!-- /wp:paragraph -->', esc_attr( $class ), $html )
		: '<!-- wp:paragraph --><p>' . $html . '</p><!-- /wp:paragraph -->';
	$h2   = fn( $text ) => '<!-- wp:heading --><h2 class="wp-block-heading">' . esc_html( $text ) . '</h2><!-- /wp:heading -->';
	$list = function ( array $items ) {
		$out = '<!-- wp:list --><ul class="wp-block-list">';
		foreach ( $items as $item ) {
			$out .= '<!-- wp:list-item --><li>' . $item . '</li><!-- /wp:list-item -->';
		}
		return $out . '</ul><!-- /wp:list -->';
	};

	return array(
		'for-referring-doctors'    => array(
			'title'   => 'For referring doctors',
			'parent'  => 'patient-care',
			'order'   => 20,
			'content' => $p( esc_html( "Information for doctors and hospitals who refer patients to $name." ), 'tmc-lead' )
				. $h2( 'Referring a patient' )
				. $p( 'The referral procedure, the documents and reports to send with a patient, and contact details for referring clinicians will be published on this page by Tata Memorial Centre.', 'callout' )
				. $h2( 'Useful information' )
				. $list(
					array(
						$link( '/departments/', 'Departments' ) . ' — specialities and the services each department provides',
						$link( '/doctors/', 'Find a doctor' ) . ' — consultants by department',
						$link( '/patient-care/opd-schedule/', 'OPD schedule' ) . ' — outpatient days and timings',
						$link( '/patient-care/appointments/', 'Appointments' ) . ' — how patients book a consultation',
						$link( '/contact-us/', 'Contact us' ) . ' — address and telephone numbers',
					)
				),
		),
		'students-and-researchers' => array(
			'title'   => 'Students & researchers',
			'parent'  => 'education',
			'order'   => 21,
			'content' => $p( esc_html( "Courses, training and research at $name, in one place." ), 'tmc-lead' )
				. $h2( 'Study and training' )
				. $list(
					array(
						$link( '/education/courses/', 'Courses' ) . ' — programmes offered, eligibility and duration',
						$link( '/education/admissions/', 'Admissions' ) . ' — notices, schedules and how to apply',
						$link( '/education/results/', 'Results' ) . ' — examination and selection results',
					)
				)
				. $h2( 'Research' )
				. $list(
					array(
						$link( '/research/clinical-trials/', 'Clinical trials' ) . ' — trials open for enrolment',
						$link( '/research/publications/', 'Publications' ) . ' — research by our clinicians and scientists',
					)
				)
				. $h2( 'Opportunities' )
				. $list( array( $link( '/careers/', 'Careers' ) . ' — current openings, including academic and research positions' ) )
				. $p( 'Details of fellowships, observerships and research collaboration will be published on this page by Tata Memorial Centre.', 'callout' ),
		),
	);
}

/** Menu ID for a location and language (Polylang keeps one menu per language). */
function tmc_ia_menu_id( $location, $lang = 'en' ) {
	if ( function_exists( 'PLL' ) && PLL() && isset( PLL()->options ) ) {
		$menus = PLL()->options->get( 'nav_menus' );
		$id    = (int) ( $menus[ get_stylesheet() ][ $location ][ $lang ] ?? 0 );
		if ( $id && wp_get_nav_menu_object( $id ) ) {
			return $id;
		}
	}
	$id = (int) ( get_nav_menu_locations()[ $location ] ?? 0 );
	return ( $id && wp_get_nav_menu_object( $id ) ) ? $id : 0;
}

/**
 * Add a page to a menu once, optionally below the item that links to $parent_page_id.
 *
 * @return int Menu item ID, 0 when the parent item is missing, or the existing item's ID.
 */
function tmc_ia_add_menu_item( $menu_id, $page_id, $parent_page_id = 0 ) {
	$items     = (array) wp_get_nav_menu_items( $menu_id, array( 'post_status' => 'publish,draft' ) );
	$parent_id = 0;
	foreach ( $items as $item ) {
		if ( 'page' === $item->object && (int) $item->object_id === (int) $page_id ) {
			return (int) $item->ID;
		}
		if ( $parent_page_id && 'page' === $item->object && (int) $item->object_id === (int) $parent_page_id && 0 === (int) $item->menu_item_parent ) {
			$parent_id = (int) $item->ID;
		}
	}
	if ( $parent_page_id && ! $parent_id ) {
		return 0;
	}
	$id = wp_update_nav_menu_item(
		$menu_id,
		0,
		array(
			'menu-item-object-id' => (int) $page_id,
			'menu-item-object'    => 'page',
			'menu-item-type'      => 'post_type',
			'menu-item-status'    => 'publish',
			'menu-item-parent-id' => $parent_id,
		)
	);
	return is_wp_error( $id ) ? 0 : (int) $id;
}

/** Create a published English page if its path does not exist yet. */
function tmc_ia_ensure_page( $slug, $title, $content, $order = 0 ) {
	$existing = get_page_by_path( $slug );
	if ( $existing ) {
		return (int) $existing->ID;
	}
	$id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_name'    => $slug,
			'post_title'   => $title,
			'post_content' => wp_slash( $content ),
			'menu_order'   => $order,
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	if ( function_exists( 'pll_set_post_language' ) ) {
		pll_set_post_language( $id, 'en' );
	}
	return (int) $id;
}

/** The component library page on the TMC site (rendered by the theme). */
function tmc_ensure_component_library_page() {
	return tmc_ia_ensure_page(
		'component-library',
		'Component library',
		'<!-- wp:paragraph --><p>The living reference of the TMC design system: design tokens and every component used on the TMC websites, with the code names developers use. It is kept out of search engines.</p><!-- /wp:paragraph -->',
		99
	);
}

/**
 * Pages and menu items for the audience entry points (+ the component library on the TMC site).
 *
 * @return array<string,int>|WP_Error slug => page ID
 */
function tmc_ensure_editorial_ia() {
	$pages = array();
	foreach ( tmc_audience_pages() as $slug => $page ) {
		$id = tmc_ia_ensure_page( $slug, $page['title'], $page['content'], $page['order'] );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$pages[ $slug ] = $id;
	}

	// Menus exist only after seed-site-structure.php; on a fresh install it calls this again.
	$primary = tmc_ia_menu_id( 'primary' );
	$quick   = tmc_ia_menu_id( 'footer-quick' );
	foreach ( tmc_audience_pages() as $slug => $page ) {
		$parent = get_page_by_path( $page['parent'] );
		if ( $primary && $parent ) {
			tmc_ia_add_menu_item( $primary, $pages[ $slug ], $parent->ID );
		}
		if ( $quick ) {
			tmc_ia_add_menu_item( $quick, $pages[ $slug ] );
		}
	}

	if ( is_main_site() ) {
		$library = tmc_ensure_component_library_page();
		if ( is_wp_error( $library ) ) {
			return $library;
		}
		$pages['component-library'] = $library;
	}
	return $pages;
}
