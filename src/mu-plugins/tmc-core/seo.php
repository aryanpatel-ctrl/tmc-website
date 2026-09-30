<?php
/**
 * Search engine optimisation (tender §4.10): editor-managed metadata, social sharing tags,
 * canonical URLs, crawl directives and XML sitemaps.
 *
 *   - "Search and social sharing" box on every public content type: SEO title, meta description
 *     (with length guidance), hide from search engines (noindex), canonical override, social image.
 *     Empty fields fall back to sensible defaults (title, excerpt, content, featured image).
 *   - <head>: one <title> (theme "title-tag" support), meta description, canonical, Open Graph and
 *     Twitter card tags — each printed exactly once. Structured data lives in seo-schema.php.
 *   - Crawl directives follow the environment, never a checkbox someone may forget:
 *       production (WP_ENVIRONMENT_TYPE=production, "Search engine visibility" on) → robots.txt allows
 *         crawling and lists the sitemap;
 *       anything else (local, CI, UAT) → robots.txt "Disallow: /", X-Robots-Tag: noindex and a
 *         robots meta noindex on every page.
 *   - Core XML sitemaps (/wp-sitemap.xml) include the TMC content types, and leave out sample
 *     (_tmc_sample) and noindex items, and the user sitemap (it would disclose login names).
 */

defined( 'ABSPATH' ) || exit;

const TMC_SEO_TITLE_MAX = 60;  // guidance only: longer titles are cut off in search results
const TMC_SEO_DESC_MIN  = 70;
const TMC_SEO_DESC_MAX  = 160;

/** Public content types that get the SEO fields (pages, posts, tenders, events, …). */
function tmc_seo_post_types() {
	$types = get_post_types( array( 'public' => true, 'show_ui' => true ) );
	unset( $types['attachment'] );
	return array_values( $types );
}

/** SEO fields, stored as protected post meta ("_" + key). */
function tmc_seo_fields() {
	return array(
		'tmc_seo_title'       => array( 'type' => 'string', 'sanitize' => 'sanitize_text_field' ),
		'tmc_seo_description' => array( 'type' => 'string', 'sanitize' => 'tmc_seo_clean_text' ),
		'tmc_seo_noindex'     => array( 'type' => 'string', 'sanitize' => 'tmc_seo_sanitize_flag' ),
		'tmc_seo_canonical'   => array( 'type' => 'string', 'sanitize' => 'tmc_seo_sanitize_url' ),
		'tmc_seo_image'       => array( 'type' => 'integer', 'sanitize' => 'absint' ),
	);
}

function tmc_seo_clean_text( $value ) {
	return trim( preg_replace( '/\s+/u', ' ', sanitize_textarea_field( (string) $value ) ) );
}

function tmc_seo_sanitize_flag( $value ) {
	return $value ? '1' : '';
}

/** Absolute http(s) URL or ''. */
function tmc_seo_sanitize_url( $value ) {
	$value = esc_url_raw( trim( (string) $value ), array( 'http', 'https' ) );
	return ( $value && wp_parse_url( $value, PHP_URL_HOST ) ) ? $value : '';
}

add_action( 'init', 'tmc_seo_register_meta', 20 );
function tmc_seo_register_meta() {
	foreach ( tmc_seo_post_types() as $type ) {
		foreach ( tmc_seo_fields() as $key => $field ) {
			register_post_meta(
				$type,
				'_' . $key,
				array(
					'single'            => true,
					'type'              => $field['type'],
					'show_in_rest'      => true,
					'sanitize_callback' => $field['sanitize'],
					'auth_callback'     => fn( $allowed, $meta_key, $post_id ) => current_user_can( 'edit_post', $post_id ),
				)
			);
		}
	}
}

/** SEO field value (key without the leading underscore). */
function tmc_seo_field( $post_id, $key ) {
	return get_post_meta( $post_id, '_' . $key, true );
}

/* ================================================================ environment */

/** True only on the production environment (WP_ENVIRONMENT_TYPE=production). */
function tmc_seo_is_production() {
	return (bool) apply_filters( 'tmc_seo_is_production', 'production' === wp_get_environment_type() );
}

/** May search engines index this site? Production and "Search engine visibility" on. */
function tmc_seo_site_indexable() {
	return tmc_seo_is_production() && (bool) get_option( 'blog_public' );
}

/** Is this item demonstration content (to be removed before go-live)? */
function tmc_seo_is_sample( $post_id ) {
	return (bool) get_post_meta( $post_id, '_tmc_sample', true );
}

/* ================================================================ editor: meta box */

add_action( 'add_meta_boxes', 'tmc_seo_register_box', 20, 2 );
function tmc_seo_register_box( $post_type, $post ) {
	if ( in_array( $post_type, tmc_seo_post_types(), true ) ) {
		add_meta_box( 'tmc_seo', 'Search and social sharing', 'tmc_seo_render_box', $post_type, 'normal', 'low' );
	}
}

function tmc_seo_render_box( WP_Post $post ) {
	wp_nonce_field( 'tmc_seo', 'tmc_seo_nonce' );
	$title       = (string) tmc_seo_field( $post->ID, 'tmc_seo_title' );
	$description = (string) tmc_seo_field( $post->ID, 'tmc_seo_description' );
	$canonical   = (string) tmc_seo_field( $post->ID, 'tmc_seo_canonical' );
	$noindex     = (bool) tmc_seo_field( $post->ID, 'tmc_seo_noindex' );
	$image       = (int) tmc_seo_field( $post->ID, 'tmc_seo_image' );
	$preview     = $image ? wp_get_attachment_image_url( $image, 'medium' ) : '';

	echo '<div class="tmc-seo">';
	echo '<p>How this page appears in search results and when it is shared on social media. Leave a field empty to use the default shown.</p>';

	printf(
		'<p><label for="tmc-seo-title"><strong>SEO title</strong></label><br><input type="text" id="tmc-seo-title" name="tmc_seo[title]" value="%s" class="widefat" maxlength="200" placeholder="%s" aria-describedby="tmc-seo-title-help tmc-seo-title-count" data-tmc-count="%d"><br><span class="description" id="tmc-seo-title-help">Default: the page title. The site name is added automatically. Keep it under %d characters.</span> <span class="tmc-seo-count" id="tmc-seo-title-count" aria-live="polite">%s</span></p>',
		esc_attr( $title ),
		esc_attr( $post->post_title ),
		(int) TMC_SEO_TITLE_MAX,
		(int) TMC_SEO_TITLE_MAX,
		esc_html( tmc_seo_count_text( $title, 0, TMC_SEO_TITLE_MAX ) )
	);

	printf(
		'<p><label for="tmc-seo-description"><strong>Meta description</strong></label><br><textarea id="tmc-seo-description" name="tmc_seo[description]" rows="3" class="widefat" maxlength="400" aria-describedby="tmc-seo-description-help tmc-seo-description-count" data-tmc-min="%d" data-tmc-count="%d">%s</textarea><br><span class="description" id="tmc-seo-description-help">A one- or two-sentence summary shown under the title in search results. Recommended length: %d–%d characters. Default: the excerpt, or the start of the content.</span> <span class="tmc-seo-count" id="tmc-seo-description-count" aria-live="polite">%s</span></p>',
		(int) TMC_SEO_DESC_MIN,
		(int) TMC_SEO_DESC_MAX,
		esc_textarea( $description ),
		(int) TMC_SEO_DESC_MIN,
		(int) TMC_SEO_DESC_MAX,
		esc_html( tmc_seo_count_text( $description, TMC_SEO_DESC_MIN, TMC_SEO_DESC_MAX ) )
	);

	echo '<fieldset class="tmc-seo-image"><legend><strong>Social sharing image</strong></legend>';
	printf( '<input type="hidden" id="tmc-seo-image" name="tmc_seo[image]" value="%s">', esc_attr( $image ? (string) $image : '' ) );
	printf(
		'<p class="tmc-seo-image-preview">%s</p>',
		$preview ? '<img src="' . esc_url( $preview ) . '" alt="" style="max-width:240px;height:auto">' : '<em>No image selected.</em>'
	);
	echo '<p><button type="button" class="button tmc-seo-image-choose" aria-controls="tmc-seo-image">Choose image</button> ';
	printf( '<button type="button" class="button-link tmc-seo-image-remove"%s>Remove image</button></p>', $image ? '' : ' hidden' );
	echo '<p class="description">Shown when the page is shared (at least 1200 × 630 pixels works best). Default: the featured image, then the site default image.</p></fieldset>';

	printf(
		'<p><label for="tmc-seo-canonical"><strong>Canonical URL</strong> (advanced)</label><br><input type="url" id="tmc-seo-canonical" name="tmc_seo[canonical]" value="%s" class="widefat" placeholder="%s" aria-describedby="tmc-seo-canonical-help"><br><span class="description" id="tmc-seo-canonical-help">Only when the same content is published at another address that search engines should treat as the original. Leave empty in almost all cases.</span></p>',
		esc_attr( $canonical ),
		esc_attr( 'publish' === $post->post_status ? get_permalink( $post ) : '' )
	);

	printf(
		'<p><input type="checkbox" id="tmc-seo-noindex" name="tmc_seo[noindex]" value="1"%s> <label for="tmc-seo-noindex"><strong>Hide from search engines</strong> (noindex)</label><br><span class="description">The page stays on the website but is not shown in search results and is left out of the XML sitemap.</span></p>',
		checked( $noindex, true, false )
	);
	echo '</div>';
}

/** "42 characters — good" style guidance (also produced live by admin/seo.js). */
function tmc_seo_count_text( $text, $min, $max ) {
	$length = mb_strlen( (string) $text );
	if ( 0 === $length ) {
		return 'Empty: the default is used.';
	}
	if ( $length > $max ) {
		return sprintf( '%d characters: too long, may be cut off (maximum %d).', $length, $max );
	}
	if ( $min && $length < $min ) {
		return sprintf( '%d characters: rather short (at least %d recommended).', $length, $min );
	}
	return sprintf( '%d characters: good length.', $length );
}

add_action( 'save_post', 'tmc_seo_save', 10, 2 );
function tmc_seo_save( $post_id, $post ) {
	if ( ! isset( $_POST['tmc_seo_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['tmc_seo_nonce'] ), 'tmc_seo' ) ) {
		return;
	}
	if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	if ( ! in_array( $post->post_type, tmc_seo_post_types(), true ) ) {
		return;
	}
	$input = isset( $_POST['tmc_seo'] ) && is_array( $_POST['tmc_seo'] ) ? wp_unslash( $_POST['tmc_seo'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	tmc_seo_update(
		$post_id,
		array(
			'title'       => $input['title'] ?? '',
			'description' => $input['description'] ?? '',
			'canonical'   => $input['canonical'] ?? '',
			'noindex'     => ! empty( $input['noindex'] ),
			'image'       => $input['image'] ?? 0,
		)
	);
}

/**
 * Save the SEO fields of a post (also used by the content importer and tests).
 * Changes that affect whether/where a page is found (noindex, canonical) are audit-logged.
 *
 * @param array $values title, description, canonical, noindex (bool), image (attachment ID).
 */
function tmc_seo_update( $post_id, array $values ) {
	$before = array(
		'noindex'   => (string) tmc_seo_field( $post_id, 'tmc_seo_noindex' ),
		'canonical' => (string) tmc_seo_field( $post_id, 'tmc_seo_canonical' ),
	);
	$image  = absint( $values['image'] ?? 0 );
	if ( $image && ! wp_attachment_is_image( $image ) ) {
		$image = 0;
	}
	$clean = array(
		'tmc_seo_title'       => sanitize_text_field( (string) ( $values['title'] ?? '' ) ),
		'tmc_seo_description' => tmc_seo_clean_text( $values['description'] ?? '' ),
		'tmc_seo_canonical'   => tmc_seo_sanitize_url( $values['canonical'] ?? '' ),
		'tmc_seo_noindex'     => tmc_seo_sanitize_flag( ! empty( $values['noindex'] ) ),
		'tmc_seo_image'       => $image ? $image : '',
	);
	foreach ( $clean as $key => $value ) {
		'' !== $value ? update_post_meta( $post_id, '_' . $key, $value ) : delete_post_meta( $post_id, '_' . $key );
	}
	$after = array( 'noindex' => $clean['tmc_seo_noindex'], 'canonical' => $clean['tmc_seo_canonical'] );
	if ( $after !== $before && function_exists( 'tmc_audit' ) ) {
		tmc_audit(
			'seo_visibility_changed',
			array(
				'object_type'  => get_post_type( $post_id ),
				'object_id'    => $post_id,
				'object_title' => get_the_title( $post_id ),
				'details'      => array( 'from' => $before, 'to' => $after ),
			)
		);
	}
}

add_action( 'admin_enqueue_scripts', 'tmc_seo_admin_assets' );
function tmc_seo_admin_assets( $hook ) {
	if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
		return;
	}
	$screen = get_current_screen();
	if ( ! $screen || ! in_array( $screen->post_type, tmc_seo_post_types(), true ) ) {
		return;
	}
	wp_enqueue_media();
	wp_enqueue_script( 'tmc-seo', plugins_url( 'admin/seo.js', __FILE__ ), array( 'jquery' ), TMC_CORE_VERSION, true );
	wp_add_inline_style( 'wp-admin', '.tmc-seo-count{font-style:italic}.tmc-seo fieldset{margin:1em 0}' );
}

/* ================================================================ values for the current request */

/** Site default social image (Network Admin → Analytics & Search: per site), or the site icon. */
function tmc_seo_default_image() {
	$url = function_exists( 'tmc_analytics_site_value' ) ? tmc_analytics_site_value( 'social_image' ) : '';
	if ( ! $url && has_site_icon() ) {
		$url = get_site_icon_url( 512 );
	}
	return $url ? array( 'url' => $url, 'width' => 0, 'height' => 0, 'alt' => '' ) : array();
}

/** Social image of a post: SEO image → featured image → site default. */
function tmc_seo_image( $post_id = 0 ) {
	$ids = $post_id ? array( (int) tmc_seo_field( $post_id, 'tmc_seo_image' ), (int) get_post_thumbnail_id( $post_id ) ) : array();
	foreach ( array_filter( $ids ) as $id ) {
		$src = wp_get_attachment_image_src( $id, 'large' );
		if ( $src ) {
			return array(
				'url'    => $src[0],
				'width'  => (int) $src[1],
				'height' => (int) $src[2],
				'alt'    => trim( wp_strip_all_tags( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) ),
			);
		}
	}
	return tmc_seo_default_image();
}

/** Plain text, entities decoded, whitespace collapsed, cut on a word boundary. */
function tmc_seo_plain( $html, $max = TMC_SEO_DESC_MAX ) {
	// A space at block boundaries, so "<h2>Title</h2><p>Text" does not become "TitleText".
	$html = preg_replace( '~<(/?)(p|div|h[1-6]|li|ul|ol|br|tr|td|th|table|section|article|header|footer|figure|figcaption|blockquote|dt|dd)\b~i', ' <$1$2', strip_shortcodes( (string) $html ) );
	$text = html_entity_decode( wp_strip_all_tags( (string) $html, true ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$text = trim( preg_replace( '/\s+/u', ' ', $text ) );
	if ( mb_strlen( $text ) <= $max ) {
		return $text;
	}
	$cut   = mb_substr( $text, 0, $max - 1 );
	$space = mb_strrpos( $cut, ' ' );
	return rtrim( ( $space && $space > $max / 2 ) ? mb_substr( $cut, 0, $space ) : $cut, " ,;:.-–" ) . '…';
}

/**
 * Default meta description of a post: excerpt, else the start of the content. Never for a
 * password-protected post — its text must not leak into meta, social or structured data (as core
 * also hides the excerpt of a protected post). An editor-entered SEO description still applies.
 */
function tmc_seo_post_description( WP_Post $post ) {
	if ( '' !== $post->post_password ) {
		return '';
	}
	if ( '' !== trim( $post->post_excerpt ) ) {
		return tmc_seo_plain( $post->post_excerpt );
	}
	return tmc_seo_plain( excerpt_remove_blocks( $post->post_content ) );
}

/**
 * Default description of the home page: its excerpt, the site tagline (Settings → General), or
 * "<site name>: <theme tagline>". The home page is built from sections, so its text makes a poor summary.
 */
function tmc_seo_front_description( $post = null ) {
	if ( $post && '' !== trim( $post->post_excerpt ) ) {
		return tmc_seo_plain( $post->post_excerpt );
	}
	$tagline = tmc_seo_plain( get_bloginfo( 'description' ) );
	if ( '' !== $tagline ) {
		return $tagline;
	}
	$name = function_exists( 'tmc_site_name' ) ? tmc_site_name() : get_bloginfo( 'name' );
	$name = html_entity_decode( (string) $name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	return function_exists( 'tmc_site_tagline' ) ? tmc_seo_plain( $name . ': ' . tmc_site_tagline() . '.' ) : $name;
}

/** Default description of the content-type listings. */
function tmc_seo_archive_description( $post_type ) {
	$site         = get_bloginfo( 'name' );
	$descriptions = array(
		/* translators: %s: site name */
		'tmc_tender'     => __( 'Current tenders, expressions of interest and corrigenda of %s, with closing dates and documents.', 'tmc' ),
		/* translators: %s: site name */
		'tmc_job'        => __( 'Current job openings and recruitment notices of %s, with last dates and application details.', 'tmc' ),
		/* translators: %s: site name */
		'tmc_event'      => __( 'Upcoming events, conferences and programmes at %s.', 'tmc' ),
		/* translators: %s: site name */
		'tmc_department' => __( 'Clinical and support departments of %s.', 'tmc' ),
		/* translators: %s: site name */
		'tmc_doctor'     => __( 'Find a doctor at %s by name or department.', 'tmc' ),
	);
	if ( isset( $descriptions[ $post_type ] ) ) {
		return sprintf( $descriptions[ $post_type ], $site );
	}
	$object = get_post_type_object( $post_type );
	return $object ? tmc_seo_plain( $object->description ) : '';
}

/** Canonical URL of the current request ('' where none applies: search, 404). */
function tmc_seo_canonical() {
	if ( is_404() || is_search() ) {
		return '';
	}
	if ( is_singular() ) {
		$post_id  = get_queried_object_id();
		$override = (string) tmc_seo_field( $post_id, 'tmc_seo_canonical' );
		if ( $override ) {
			return $override;
		}
		$url = wp_get_canonical_url( $post_id );
		return $url ? $url : '';
	}
	$url  = '';
	$view = '';
	if ( is_post_type_archive() ) {
		$type = get_query_var( 'post_type' );
		$url  = get_post_type_archive_link( is_array( $type ) ? reset( $type ) : $type );
		$view = function_exists( 'tmc_view' ) ? tmc_view() : '';
		$view = in_array( $view, array( 'archive', 'past', 'calendar' ), true ) ? $view : ''; // listing views are separate pages
	} elseif ( is_category() || is_tag() || is_tax() ) {
		$link = get_term_link( get_queried_object() );
		$url  = is_wp_error( $link ) ? '' : $link;
	} elseif ( is_home() && ! is_front_page() ) {
		$url = get_permalink( (int) get_option( 'page_for_posts' ) );
	} elseif ( is_front_page() ) {
		$url = function_exists( 'pll_home_url' ) ? pll_home_url() : home_url( '/' );
	}
	$paged = (int) get_query_var( 'paged' );
	if ( $url && $paged > 1 ) {
		$url = false === strpos( $url, '?' ) ? trailingslashit( $url ) . user_trailingslashit( 'page/' . $paged, 'paged' ) : add_query_arg( 'paged', $paged, $url );
	}
	if ( $url && $view ) {
		$url = add_query_arg( 'view', $view, $url );
	}
	return $url ? $url : '';
}

/**
 * Everything the <head> tags need for the current request.
 *
 * @return array{title:string,description:string,canonical:string,image:array,og_type:string,locale:string,noindex:bool,post_id:int}
 */
function tmc_seo_context() {
	$context = array(
		'title'       => wp_get_document_title(),
		'description' => '',
		'canonical'   => tmc_seo_canonical(),
		'image'       => array(),
		'og_type'     => 'website',
		'locale'      => get_locale(),
		'noindex'     => false,
		'post_id'     => 0,
	);

	if ( is_singular() ) {
		$post                   = get_queried_object();
		$context['post_id']     = $post->ID;
		$custom                 = (string) tmc_seo_field( $post->ID, 'tmc_seo_description' );
		$context['description'] = '' !== $custom ? $custom : ( is_front_page() ? tmc_seo_front_description( $post ) : tmc_seo_post_description( $post ) );
		$context['image']       = tmc_seo_image( $post->ID );
		$context['og_type']     = 'post' === $post->post_type ? 'article' : 'website'; // news and notices are articles
		$context['noindex']     = (bool) tmc_seo_field( $post->ID, 'tmc_seo_noindex' ) || tmc_seo_is_sample( $post->ID );
		if ( function_exists( 'pll_get_post_language' ) ) {
			$locale            = pll_get_post_language( $post->ID, 'locale' );
			$context['locale'] = $locale ? $locale : $context['locale'];
		}
	} elseif ( is_post_type_archive() ) {
		$type                   = get_query_var( 'post_type' );
		$context['description'] = tmc_seo_archive_description( is_array( $type ) ? reset( $type ) : $type );
		$context['image']       = tmc_seo_default_image();
	} elseif ( is_category() || is_tag() || is_tax() ) {
		$term                   = get_queried_object();
		$context['description'] = $term && '' !== trim( (string) $term->description )
			? tmc_seo_plain( $term->description )
			/* translators: 1: category name, 2: site name */
			: sprintf( __( '%1$s from %2$s.', 'tmc' ), $term ? $term->name : '', get_bloginfo( 'name' ) );
		$context['image']       = tmc_seo_default_image();
	} elseif ( is_front_page() || is_home() ) {
		$context['description'] = tmc_seo_front_description();
		$context['image']       = tmc_seo_default_image();
	} elseif ( is_search() || is_404() ) {
		$context['noindex'] = true;
	}
	return (array) apply_filters( 'tmc_seo_context', $context );
}

/* ================================================================ <head> output */

// One canonical per page, from tmc_seo_canonical() (core only covers singular pages).
remove_action( 'wp_head', 'rel_canonical' );

/** SEO title replaces the page part of the document title; the site name is kept. */
add_filter( 'document_title_parts', 'tmc_seo_title_parts', 20 );
function tmc_seo_title_parts( $parts ) {
	if ( ! is_singular() ) {
		return $parts;
	}
	$custom = trim( (string) tmc_seo_field( get_queried_object_id(), 'tmc_seo_title' ) );
	if ( '' === $custom ) {
		return $parts;
	}
	$site = get_bloginfo( 'name', 'display' );
	return false !== mb_stripos( $custom, $site ) ? array( 'title' => $custom ) : array( 'title' => $custom, 'site' => $site );
}

add_filter( 'wp_robots', 'tmc_seo_robots' );
function tmc_seo_robots( array $robots ) {
	if ( ! tmc_seo_site_indexable() ) {
		$robots['noindex']  = true;
		$robots['nofollow'] = true;
		unset( $robots['index'], $robots['follow'], $robots['max-image-preview'] );
		return $robots;
	}
	$noindex = is_404() || is_search() || ( is_singular() && ( tmc_seo_field( get_queried_object_id(), 'tmc_seo_noindex' ) || tmc_seo_is_sample( get_queried_object_id() ) ) );
	if ( $noindex ) {
		$robots['noindex'] = true;
		$robots['follow']  = true;
		unset( $robots['index'], $robots['max-image-preview'] );
	}
	return $robots;
}

add_action( 'wp_head', 'tmc_seo_head', 2 );
function tmc_seo_head() {
	echo tmc_seo_head_html( tmc_seo_context() ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in tmc_seo_head_html()
}

/** Meta description, canonical, Open Graph and Twitter tags (each at most once). */
function tmc_seo_head_html( array $c ) {
	$meta  = static fn( $attr, $key, $value ) => '' === (string) $value ? '' : sprintf( '<meta %s="%s" content="%s">' . "\n", $attr, esc_attr( $key ), esc_attr( $value ) );
	$title = html_entity_decode( (string) $c['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$html  = "<!-- TMC SEO -->\n";
	$html .= $meta( 'name', 'description', $c['description'] );
	if ( $c['canonical'] ) {
		$html .= sprintf( '<link rel="canonical" href="%s">' . "\n", esc_url( $c['canonical'] ) );
	}

	$html .= $meta( 'property', 'og:type', $c['og_type'] );
	$html .= $meta( 'property', 'og:site_name', html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	$html .= $meta( 'property', 'og:title', $title );
	$html .= $meta( 'property', 'og:description', $c['description'] );
	$html .= $c['canonical'] ? $meta( 'property', 'og:url', esc_url_raw( $c['canonical'] ) ) : '';
	$html .= $meta( 'property', 'og:locale', $c['locale'] );
	if ( $c['post_id'] && function_exists( 'pll_get_post_translations' ) && function_exists( 'pll_get_post_language' ) ) {
		foreach ( pll_get_post_translations( $c['post_id'] ) as $translation_id ) {
			$locale = (int) $translation_id !== (int) $c['post_id'] && 'publish' === get_post_status( $translation_id ) ? pll_get_post_language( $translation_id, 'locale' ) : '';
			$html  .= $locale && $locale !== $c['locale'] ? $meta( 'property', 'og:locale:alternate', $locale ) : '';
		}
	}
	if ( 'article' === $c['og_type'] && $c['post_id'] ) {
		$html .= $meta( 'property', 'article:published_time', get_post_time( 'c', true, $c['post_id'] ) );
		$html .= $meta( 'property', 'article:modified_time', get_post_modified_time( 'c', true, $c['post_id'] ) );
	}
	$image = $c['image'];
	if ( ! empty( $image['url'] ) ) {
		$html .= $meta( 'property', 'og:image', esc_url_raw( $image['url'] ) );
		$html .= ! empty( $image['width'] ) ? $meta( 'property', 'og:image:width', (int) $image['width'] ) . $meta( 'property', 'og:image:height', (int) $image['height'] ) : '';
		$html .= $meta( 'property', 'og:image:alt', $image['alt'] ?? '' );
	}

	$html .= $meta( 'name', 'twitter:card', ! empty( $image['url'] ) ? 'summary_large_image' : 'summary' );
	$handle = tmc_seo_twitter_handle();
	$html  .= $handle ? $meta( 'name', 'twitter:site', '@' . $handle ) : '';
	$html  .= $meta( 'name', 'twitter:title', $title );
	$html  .= $meta( 'name', 'twitter:description', $c['description'] );
	if ( ! empty( $image['url'] ) ) {
		$html .= $meta( 'name', 'twitter:image', esc_url_raw( $image['url'] ) );
		$html .= $meta( 'name', 'twitter:image:alt', $image['alt'] ?? '' );
	}
	return $html;
}

/** X (Twitter) handle from the site's social link setting (Appearance → Customize), if any. */
function tmc_seo_twitter_handle() {
	$url = (string) get_theme_mod( 'tmc_x' );
	if ( $url && preg_match( '~^https?://(?:www\.)?(?:twitter|x)\.com/@?([A-Za-z0-9_]{1,15})/?$~', $url, $m ) ) {
		return $m[1];
	}
	return '';
}

/* ================================================================ crawl directives */

add_filter( 'robots_txt', 'tmc_seo_robots_txt', 100, 2 );
function tmc_seo_robots_txt( $output = '', $public = true ) {
	if ( ! tmc_seo_site_indexable() ) {
		return "# Non-production environment (" . wp_get_environment_type() . "): not for search engines.\nUser-agent: *\nDisallow: /\n";
	}
	$lines = array(
		'User-agent: *',
		'Disallow: /wp-admin/',
		'Allow: /wp-admin/admin-ajax.php',
		'Disallow: /*?s=',
		'Disallow: /*&s=',
		'',
		'Sitemap: ' . home_url( '/wp-sitemap.xml' ),
	);
	return implode( "\n", $lines ) . "\n";
}

/**
 * X-Robots-Tag on every page WordPress serves outside production. Static files (uploaded PDFs) are
 * served by Apache directly; robots.txt "Disallow: /" keeps crawlers away from those.
 */
add_filter( 'wp_headers', 'tmc_seo_http_headers' );
function tmc_seo_http_headers( $headers ) {
	if ( ! tmc_seo_site_indexable() ) {
		$headers['X-Robots-Tag'] = 'noindex, nofollow';
	}
	return $headers;
}

/* ================================================================ XML sitemaps */

// Available on every environment so it can be tested before go-live; robots rules above keep
// non-production sitemaps out of search engines.
add_filter( 'wp_sitemaps_enabled', '__return_true' );

// The user sitemap lists author login names (a VAPT finding) and author archives carry no content.
add_filter(
	'wp_sitemaps_add_provider',
	function ( $provider, $name ) {
		return 'users' === $name ? false : $provider;
	},
	5,
	2
);

add_filter( 'wp_sitemaps_posts_query_args', 'tmc_seo_sitemap_query_args', 10, 2 );
function tmc_seo_sitemap_query_args( $args, $post_type = '' ) {
	$exclude = array(
		'relation' => 'AND',
		array( 'key' => '_tmc_sample', 'compare' => 'NOT EXISTS' ),
		array( 'key' => '_tmc_seo_noindex', 'compare' => 'NOT EXISTS' ),
	);
	$args['meta_query'] = empty( $args['meta_query'] ) ? $exclude : array( 'relation' => 'AND', $args['meta_query'], $exclude );
	return $args;
}

/** Last-modified date for each URL in the sitemap (helps crawlers pick up changes). */
add_filter(
	'wp_sitemaps_posts_entry',
	function ( $entry, $post ) {
		$entry['lastmod'] = get_post_modified_time( 'c', true, $post );
		return $entry;
	},
	10,
	2
);
