<?php
/**
 * Structured data (tender §4.10): one JSON-LD @graph per page, printed in <head>.
 *
 *   every page   Organisation — TMC: GovernmentOrganization + MedicalOrganization (parent: Department
 *                of Atomic Energy); unit sites: Hospital (parent: TMC) — with name, url, logo, address;
 *                WebSite with a SearchAction (site search in the page's language)
 *   most pages   WebPage / CollectionPage and a BreadcrumbList built from the same trail as the
 *                visible breadcrumbs (tmc_breadcrumb_trail() in the theme), so the two always match
 *   events       Event (tmc_event)
 *   careers      JobPosting (tmc_job), validThrough = last date to apply
 *   doctors      Physician (tmc_doctor)
 *
 * Item-level nodes (Event, JobPosting, Physician) are not produced for sample/demonstration items:
 * structured data must describe real things. Values are JSON-encoded with <, >, & escaped, so
 * content can never close the <script> element.
 */

defined( 'ABSPATH' ) || exit;

/** Plain text for JSON-LD (entities decoded, tags removed). */
function tmc_schema_text( $value ) {
	return trim( html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
}

/** "Y-m-d H:i:s" in site time → ISO 8601 with offset ("2026-10-05T15:00:00+05:30"). */
function tmc_schema_iso( $mysql ) {
	if ( ! $mysql ) {
		return '';
	}
	$date = date_create_immutable( (string) $mysql, wp_timezone() );
	return $date ? $date->format( 'c' ) : '';
}

/** Remove empty values so nodes carry only what is known. */
function tmc_schema_clean( array $node ) {
	$is_list = array_is_list( $node );
	foreach ( $node as $key => $value ) {
		if ( is_array( $value ) ) {
			$value        = tmc_schema_clean( $value );
			$node[ $key ] = $value;
		}
		if ( null === $value || '' === $value || array() === $value ) {
			unset( $node[ $key ] );
		}
	}
	return $is_list ? array_values( $node ) : $node; // lists stay JSON arrays
}

function tmc_schema_org_id( $blog_id = 0 ) {
	return get_home_url( $blog_id ? $blog_id : get_current_blog_id(), '/' ) . '#organization';
}

/**
 * Postal address from the site's contact address (Appearance → Customize → TMC contact details).
 * "Dr. Ernest Borges Marg, Parel\nMumbai – 400 012, Maharashtra, India" becomes street, locality,
 * postal code, region and country.
 */
function tmc_schema_address() {
	$raw = (string) get_theme_mod( 'tmc_address' );
	if ( '' === trim( $raw ) ) {
		return array();
	}
	$parts   = array_values( array_filter( array_map( 'trim', preg_split( '/[\r\n,]+/u', $raw ) ), 'strlen' ) );
	$address = array( '@type' => 'PostalAddress', 'addressCountry' => 'IN' );
	if ( $parts && preg_match( '/^india$/i', end( $parts ) ) ) {
		array_pop( $parts );
	}
	foreach ( $parts as $i => $part ) {
		if ( preg_match( '/(?<!\d)(\d{3})\s?(\d{3})(?!\d)/u', $part, $m ) ) {
			$address['postalCode'] = $m[1] . $m[2];
			$parts[ $i ]           = trim( preg_replace( '/[\s–—-]*(?<!\d)\d{3}\s?\d{3}(?!\d)[\s–—-]*/u', ' ', $part ) );
		}
	}
	$parts = array_values( array_filter( $parts, 'strlen' ) );
	if ( count( $parts ) >= 2 ) {
		$address['addressRegion']   = array_pop( $parts );
		$address['addressLocality'] = array_pop( $parts );
	} elseif ( $parts ) {
		$address['addressLocality'] = array_pop( $parts );
	}
	if ( $parts ) {
		$address['streetAddress'] = implode( ', ', $parts );
	}
	return (array) apply_filters( 'tmc_schema_address', $address );
}

function tmc_schema_logo_url() {
	$url = has_site_icon() ? get_site_icon_url( 512 ) : '';
	if ( ! $url && file_exists( get_theme_file_path( 'assets/img/logo-mark.svg' ) ) ) {
		$url = get_theme_file_uri( 'assets/img/logo-mark.svg' );
	}
	return (string) apply_filters( 'tmc_schema_logo', $url );
}

/** Organisation of the current site. */
function tmc_schema_organization() {
	$main    = is_main_site();
	$home    = home_url( '/' );
	$main_id = get_main_site_id();
	$same_as = array();
	foreach ( array( 'tmc_facebook', 'tmc_x', 'tmc_youtube', 'tmc_instagram', 'tmc_linkedin' ) as $mod ) {
		$url = esc_url_raw( (string) get_theme_mod( $mod ) );
		if ( $url ) {
			$same_as[] = $url;
		}
	}
	$logo = tmc_schema_logo_url();
	$node = array(
		'@type'         => $main ? array( 'GovernmentOrganization', 'MedicalOrganization' ) : 'Hospital',
		'@id'           => tmc_schema_org_id(),
		'name'          => tmc_schema_text( get_option( 'blogname' ) ),
		'alternateName' => tmc_schema_text( get_option( 'tmc_name_hi' ) ),
		'url'           => $home,
		'logo'          => $logo ? array( '@type' => 'ImageObject', 'url' => $logo ) : '',
		'address'       => tmc_schema_address(),
		'telephone'     => sanitize_text_field( (string) get_theme_mod( 'tmc_phone' ) ),
		'email'         => sanitize_email( (string) get_theme_mod( 'tmc_email' ) ),
		'sameAs'        => $same_as,
	);
	if ( $main ) {
		$node['parentOrganization'] = array(
			'@type' => 'GovernmentOrganization',
			'name'  => 'Department of Atomic Energy, Government of India',
			'url'   => 'https://dae.gov.in/',
		);
	} else {
		$node['parentOrganization'] = array(
			'@type' => array( 'GovernmentOrganization', 'MedicalOrganization' ),
			'@id'   => tmc_schema_org_id( $main_id ),
			'name'  => tmc_schema_text( get_blog_option( $main_id, 'blogname' ) ),
			'url'   => get_home_url( $main_id, '/' ),
		);
	}
	return tmc_schema_clean( (array) apply_filters( 'tmc_schema_organization', $node ) );
}

/** A short reference to the organisation (for organizer, hiringOrganization, …). */
function tmc_schema_org_ref() {
	$org = tmc_schema_organization();
	return tmc_schema_clean(
		array(
			'@type' => $org['@type'],
			'@id'   => $org['@id'],
			'name'  => $org['name'],
			'url'   => $org['url'],
			'logo'  => $org['logo'] ?? '',
		)
	);
}

function tmc_schema_lang() {
	$locale = get_locale();
	if ( is_singular() && function_exists( 'pll_get_post_language' ) ) {
		$post_locale = pll_get_post_language( get_queried_object_id(), 'locale' );
		$locale      = $post_locale ? $post_locale : $locale;
	}
	return str_replace( '_', '-', $locale );
}

function tmc_schema_website() {
	$home   = home_url( '/' );
	$search = trailingslashit( function_exists( 'pll_home_url' ) ? pll_home_url() : $home );
	return array(
		'@type'           => 'WebSite',
		'@id'             => $home . '#website',
		'url'             => $home,
		'name'            => tmc_schema_text( get_option( 'blogname' ) ),
		'inLanguage'      => tmc_schema_lang(),
		'publisher'       => array( '@id' => tmc_schema_org_id() ),
		'potentialAction' => array(
			'@type'       => 'SearchAction',
			'target'      => array(
				'@type'       => 'EntryPoint',
				'urlTemplate' => $search . '?s={search_term_string}',
			),
			'query-input' => 'required name=search_term_string',
		),
	);
}

/** BreadcrumbList matching the visible breadcrumbs, or null when the page shows none. */
function tmc_schema_breadcrumbs( $canonical ) {
	if ( ! function_exists( 'tmc_breadcrumb_trail' ) ) {
		return null;
	}
	$trail = tmc_breadcrumb_trail();
	if ( ! $trail ) {
		return null;
	}
	$items = array();
	foreach ( array_values( $trail ) as $i => list( $label, $url ) ) {
		$items[] = tmc_schema_clean(
			array(
				'@type'    => 'ListItem',
				'position' => $i + 1,
				'name'     => tmc_schema_text( $label ),
				'item'     => $url ? $url : $canonical,
			)
		);
	}
	return array(
		'@type'           => 'BreadcrumbList',
		'@id'             => $canonical . '#breadcrumb',
		'itemListElement' => $items,
	);
}

function tmc_schema_webpage( array $seo, $has_breadcrumb ) {
	$canonical = $seo['canonical'];
	$type      = 'WebPage';
	if ( is_post_type_archive() || is_category() || is_tag() || is_tax() || ( is_home() && ! is_front_page() ) ) {
		$type = 'CollectionPage';
	}
	$node = array(
		'@type'       => $type,
		'@id'         => $canonical . '#webpage',
		'url'         => $canonical,
		'name'        => tmc_schema_text( $seo['title'] ),
		'description' => $seo['description'],
		'isPartOf'    => array( '@id' => home_url( '/' ) . '#website' ),
		'inLanguage'  => tmc_schema_lang(),
		'breadcrumb'  => $has_breadcrumb ? array( '@id' => $canonical . '#breadcrumb' ) : '',
	);
	if ( is_front_page() ) {
		$node['about'] = array( '@id' => tmc_schema_org_id() );
	}
	if ( is_singular() ) {
		$node['datePublished'] = get_post_time( 'c', true, get_queried_object_id() );
		$node['dateModified']  = get_post_modified_time( 'c', true, get_queried_object_id() );
		if ( ! empty( $seo['image']['url'] ) ) {
			$node['primaryImageOfPage'] = array( '@type' => 'ImageObject', 'url' => $seo['image']['url'] );
		}
	}
	return tmc_schema_clean( $node );
}

/** Limited HTML for JobPosting descriptions (search engines accept basic formatting). */
function tmc_schema_html( WP_Post $post ) {
	if ( '' !== $post->post_password ) {
		return tmc_schema_text( $post->post_title ); // protected content never enters structured data
	}
	$allowed = array(
		'p'      => array(),
		'br'     => array(),
		'ul'     => array(),
		'ol'     => array(),
		'li'     => array(),
		'strong' => array(),
		'em'     => array(),
		'b'      => array(),
		'i'      => array(),
		'h2'     => array(),
		'h3'     => array(),
		'h4'     => array(),
	);
	$html = trim( wp_kses( do_blocks( $post->post_content ), $allowed ) );
	$html = trim( preg_replace( '/\s+/u', ' ', $html ) );
	return '' !== wp_strip_all_tags( $html ) ? $html : tmc_schema_text( $post->post_excerpt ? $post->post_excerpt : $post->post_title );
}

function tmc_schema_event( WP_Post $post, array $seo ) {
	$start = tmc_schema_iso( tmc_field( $post->ID, 'tmc_start_at' ) );
	if ( ! $start ) {
		return null;
	}
	$org   = tmc_schema_org_ref();
	$venue = tmc_schema_text( tmc_field( $post->ID, 'tmc_venue' ) );
	return tmc_schema_clean(
		array(
			'@type'               => 'Event',
			'@id'                 => get_permalink( $post ) . '#event',
			'name'                => tmc_schema_text( get_the_title( $post ) ),
			'url'                 => get_permalink( $post ),
			'description'         => $seo['description'],
			'startDate'           => $start,
			'endDate'             => tmc_schema_iso( tmc_field( $post->ID, 'tmc_end_at' ) ),
			'eventStatus'         => 'https://schema.org/EventScheduled',
			'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
			'location'            => array(
				'@type'   => 'Place',
				'name'    => $venue ? $venue : $org['name'],
				'address' => tmc_schema_address(),
			),
			'organizer'           => $org,
			'image'               => $seo['image']['url'] ?? '',
			'inLanguage'          => tmc_schema_lang(),
			'mainEntityOfPage'    => array( '@id' => $seo['canonical'] . '#webpage' ),
		)
	);
}

function tmc_schema_job( WP_Post $post, array $seo ) {
	$org       = tmc_schema_org_ref();
	$ref       = tmc_schema_text( tmc_field( $post->ID, 'tmc_ref_no' ) );
	$vacancies = (int) tmc_field( $post->ID, 'tmc_vacancies' );
	return tmc_schema_clean(
		array(
			'@type'             => 'JobPosting',
			'@id'               => get_permalink( $post ) . '#job',
			'title'             => tmc_schema_text( get_the_title( $post ) ),
			'description'       => tmc_schema_html( $post ),
			'url'               => get_permalink( $post ),
			'datePosted'        => get_post_time( 'c', true, $post ),
			'validThrough'      => tmc_schema_iso( tmc_field( $post->ID, 'tmc_closing_at' ) ),
			'hiringOrganization' => $org,
			'jobLocation'       => array(
				'@type'   => 'Place',
				'address' => tmc_schema_address(),
			),
			'identifier'        => $ref ? array( '@type' => 'PropertyValue', 'name' => $org['name'], 'value' => $ref ) : '',
			'totalJobOpenings'  => $vacancies > 0 ? $vacancies : '',
			'inLanguage'        => tmc_schema_lang(),
			'mainEntityOfPage'  => array( '@id' => $seo['canonical'] . '#webpage' ),
		)
	);
}

function tmc_schema_physician( WP_Post $post, array $seo ) {
	$designation = tmc_schema_text( tmc_field( $post->ID, 'tmc_designation' ) );
	if ( '' === $designation ) {
		return null;
	}
	$qualifications = tmc_schema_text( tmc_field( $post->ID, 'tmc_qualifications' ) );
	$departments    = array();
	foreach ( (array) tmc_field( $post->ID, 'tmc_department_ids' ) as $department_id ) {
		if ( 'publish' === get_post_status( $department_id ) ) {
			$departments[] = tmc_schema_text( get_the_title( $department_id ) );
		}
	}
	$thumbnail = get_the_post_thumbnail_url( $post, 'medium' );
	return tmc_schema_clean(
		array(
			'@type'               => 'Physician',
			'@id'                 => get_permalink( $post ) . '#physician',
			'name'                => tmc_schema_text( get_the_title( $post ) ),
			'url'                 => get_permalink( $post ),
			'description'         => implode( ', ', array_filter( array( $designation, $qualifications, implode( ', ', $departments ) ) ) ),
			'knowsAbout'          => tmc_schema_text( tmc_field( $post->ID, 'tmc_specialisation' ) ),
			'image'               => $thumbnail ? $thumbnail : '',
			'address'             => tmc_schema_address(),
			'hospitalAffiliation' => tmc_schema_org_ref(),
			'mainEntityOfPage'    => array( '@id' => $seo['canonical'] . '#webpage' ),
		)
	);
}

/** All nodes for the current request. */
function tmc_schema_graph() {
	$graph = array( tmc_schema_organization(), tmc_schema_website() );
	if ( is_404() || is_search() ) {
		return $graph;
	}
	$seo = tmc_seo_context();
	if ( ! $seo['canonical'] ) {
		return $graph;
	}
	$breadcrumbs = tmc_schema_breadcrumbs( $seo['canonical'] );
	$graph[]     = tmc_schema_webpage( $seo, (bool) $breadcrumbs );
	if ( $breadcrumbs ) {
		$graph[] = $breadcrumbs;
	}

	if ( is_singular( array( 'tmc_event', 'tmc_job', 'tmc_doctor' ) ) ) {
		$post = get_queried_object();
		if ( ! tmc_seo_is_sample( $post->ID ) ) {
			$builders = array(
				'tmc_event'  => 'tmc_schema_event',
				'tmc_job'    => 'tmc_schema_job',
				'tmc_doctor' => 'tmc_schema_physician',
			);
			$node     = call_user_func( $builders[ $post->post_type ], $post, $seo );
			if ( $node ) {
				$graph[] = $node;
			}
		}
	}
	return array_values( (array) apply_filters( 'tmc_schema_graph', $graph ) );
}

/** JSON for a <script type="application/ld+json"> element; <, > and & are \u-escaped. */
function tmc_schema_json( array $graph ) {
	return (string) wp_json_encode(
		array(
			'@context' => 'https://schema.org',
			'@graph'   => array_values( $graph ),
		),
		JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
	);
}

add_action( 'wp_head', 'tmc_schema_print', 30 );
function tmc_schema_print() {
	echo '<script type="application/ld+json">' . tmc_schema_json( tmc_schema_graph() ) . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON with HEX_TAG/HEX_AMP
}
