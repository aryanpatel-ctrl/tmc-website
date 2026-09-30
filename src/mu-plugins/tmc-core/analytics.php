<?php
/**
 * Web analytics and search-console verification (tender §4.10: "TMC-approved web analytics and
 * search console, dashboards accessible to TMC").
 *
 * Network Admin → Settings → Analytics & Search (Super Admins only):
 *   - provider for the whole network:
 *       none    nothing is added to pages (default);
 *       matomo  self-hosted Matomo (recommended: data stays on TMC / India-resident servers).
 *               Always cookieless (disableCookies) and honours "Do Not Track", so no consent banner
 *               is needed; a no-JavaScript image fallback is included;
 *       ga4     Google Analytics 4. Loads a Google script and sends data outside India — offered only
 *               because the tender leaves the tool to TMC. Runs in consent mode "denied" (no cookies,
 *               no advertising signals).
 *   - per site: Matomo site ID, GA4 measurement ID, Google and Bing site-verification tokens and a
 *     default social-sharing image (used by seo.php).
 *
 * Output happens only when the values needed are configured. Logged-in editors are not tracked
 * (their previews would distort the figures). The privacy policy page gets a short "Website
 * analytics" section describing whichever provider is active. Every change is audit-logged.
 *
 * The Matomo address can also come from the environment (TMC_MATOMO_URL), which then takes
 * precedence over the stored value, so each environment (UAT, production) can point at its own
 * analytics server without a database change.
 */

defined( 'ABSPATH' ) || exit;

const TMC_ANALYTICS_OPTION    = 'tmc_analytics';
const TMC_ANALYTICS_PROVIDERS = array(
	'none'   => 'None',
	'matomo' => 'Matomo (self-hosted, cookieless) — recommended',
	'ga4'    => 'Google Analytics 4',
);

/** Per-site settings: key => [ label, help ]. */
function tmc_analytics_site_fields() {
	return array(
		'matomo_site_id' => array( 'Matomo site ID', 'Number shown in Matomo → Administration → Websites.' ),
		'ga4_id'         => array( 'GA4 measurement ID', 'Format G-XXXXXXXXXX.' ),
		'google_verify'  => array( 'Google Search Console token', 'The content value of the google-site-verification meta tag.' ),
		'bing_verify'    => array( 'Bing Webmaster token', 'The content value of the msvalidate.01 meta tag.' ),
		'social_image'   => array( 'Default social image URL', 'Used when a page has no social image or featured image (1200 × 630 px recommended).' ),
	);
}

/** Network settings with defaults filled in. */
function tmc_analytics_settings() {
	$stored = get_site_option( TMC_ANALYTICS_OPTION, array() );
	$stored = is_array( $stored ) ? $stored : array();
	return array(
		'provider'   => isset( TMC_ANALYTICS_PROVIDERS[ $stored['provider'] ?? '' ] ) ? $stored['provider'] : 'none',
		'matomo_url' => (string) ( $stored['matomo_url'] ?? '' ),
		'sites'      => is_array( $stored['sites'] ?? null ) ? $stored['sites'] : array(),
	);
}

/** Matomo base URL (with trailing slash): environment first, then the network setting. */
function tmc_analytics_matomo_url() {
	$env = getenv( 'TMC_MATOMO_URL' );
	$url = tmc_analytics_sanitize_base_url( $env ? $env : tmc_analytics_settings()['matomo_url'] );
	return $url;
}

/** http(s) base URL without query/fragment, ending in "/", or ''. HTTPS is required in production. */
function tmc_analytics_sanitize_base_url( $url ) {
	$url   = trim( (string) $url );
	$parts = $url ? wp_parse_url( $url ) : false;
	if ( ! $parts || empty( $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ?? '' ), array( 'http', 'https' ), true ) ) {
		return '';
	}
	if ( 'http' === strtolower( $parts['scheme'] ) && 'production' === wp_get_environment_type() ) {
		return '';
	}
	$base = strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' ) . ( $parts['path'] ?? '/' );
	return esc_url_raw( trailingslashit( $base ) );
}

/** Sanitise one per-site value; returns '' when invalid. */
function tmc_analytics_sanitize_site_value( $key, $value ) {
	$value = trim( (string) $value );
	switch ( $key ) {
		case 'matomo_site_id':
			return ctype_digit( $value ) && (int) $value > 0 ? (string) (int) $value : '';
		case 'ga4_id':
			$value = strtoupper( $value );
			return preg_match( '/^G-[A-Z0-9]{4,20}$/', $value ) ? $value : '';
		case 'google_verify':
		case 'bing_verify':
			// People often paste the whole meta tag: keep only the token.
			if ( preg_match( '/content\s*=\s*["\']([^"\']+)["\']/i', $value, $m ) ) {
				$value = $m[1];
			}
			return preg_match( '/^[A-Za-z0-9_\-]{8,100}$/', $value ) ? $value : '';
		case 'social_image':
			$url = esc_url_raw( $value, array( 'http', 'https' ) );
			return ( $url && wp_parse_url( $url, PHP_URL_HOST ) ) ? $url : '';
	}
	return '';
}

/** A per-site value for the current (or given) site. */
function tmc_analytics_site_value( $key, $blog_id = 0 ) {
	$blog_id = $blog_id ? (int) $blog_id : get_current_blog_id();
	$sites   = tmc_analytics_settings()['sites'];
	return (string) ( $sites[ $blog_id ][ $key ] ?? '' );
}

/**
 * What the current site will output, or null when analytics is off / incomplete.
 *
 * @return array{provider:string,url?:string,site_id?:string,ga4_id?:string}|null
 */
function tmc_analytics_active() {
	$provider = tmc_analytics_settings()['provider'];
	if ( 'matomo' === $provider ) {
		$url = tmc_analytics_matomo_url();
		$id  = tmc_analytics_site_value( 'matomo_site_id' );
		return ( $url && $id ) ? array( 'provider' => 'matomo', 'url' => $url, 'site_id' => $id ) : null;
	}
	if ( 'ga4' === $provider ) {
		$id = tmc_analytics_site_value( 'ga4_id' );
		return $id ? array( 'provider' => 'ga4', 'ga4_id' => $id ) : null;
	}
	return null;
}

/** Should this front-end request be measured? */
function tmc_analytics_should_track() {
	if ( is_admin() || is_preview() || is_feed() || is_robots() || is_customize_preview() || wp_is_json_request() ) {
		return false;
	}
	if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
		return false;
	}
	return (bool) apply_filters( 'tmc_analytics_should_track', true );
}

/* ================================================================ front end */

add_action( 'wp_head', 'tmc_analytics_verification_tags', 3 );
function tmc_analytics_verification_tags() {
	// Search engines verify the home page; there is no need to repeat the tags on every page.
	if ( ! is_front_page() ) {
		return;
	}
	$tags = array(
		'google-site-verification' => tmc_analytics_site_value( 'google_verify' ),
		'msvalidate.01'            => tmc_analytics_site_value( 'bing_verify' ),
	);
	foreach ( array_filter( $tags ) as $name => $token ) {
		printf( '<meta name="%s" content="%s">' . "\n", esc_attr( $name ), esc_attr( $token ) );
	}
}

/** Tracker code for the <head> ('' when nothing is configured). */
function tmc_analytics_head_html() {
	$active = tmc_analytics_active();
	if ( ! $active || ! tmc_analytics_should_track() ) {
		return '';
	}
	$json = static fn( $value ) => wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );

	if ( 'matomo' === $active['provider'] ) {
		$js = sprintf(
			'var _paq=window._paq=window._paq||[];_paq.push(["disableCookies"]);_paq.push(["setDoNotTrack",true]);%s_paq.push(["trackPageView"]);_paq.push(["enableLinkTracking"]);(function(){var u=%s;_paq.push(["setTrackerUrl",u+"matomo.php"]);_paq.push(["setSiteId",%s]);var g=document.createElement("script");g.async=true;g.src=u+"matomo.js";document.head.appendChild(g);})();',
			is_404() ? '_paq.push(["setDocumentTitle","404/URL = "+encodeURIComponent(document.location.pathname+document.location.search)]);' : '',
			$json( $active['url'] ),
			$json( $active['site_id'] )
		);
		return wp_get_inline_script_tag( $js, array( 'id' => 'tmc-analytics' ) );
	}

	// GA4 in consent mode "denied": cookieless pings only, no Google signals or ad personalisation.
	$src = 'https://www.googletagmanager.com/gtag/js?id=' . rawurlencode( $active['ga4_id'] );
	$js  = sprintf(
		'window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag("consent","default",{"ad_storage":"denied","ad_user_data":"denied","ad_personalization":"denied","analytics_storage":"denied"});gtag("js",new Date());gtag("config",%s,{"allow_google_signals":false,"allow_ad_personalization_signals":false});',
		$json( $active['ga4_id'] )
	);
	return wp_get_script_tag( array( 'src' => $src, 'async' => true, 'id' => 'tmc-analytics-ga4' ) ) . wp_get_inline_script_tag( $js, array( 'id' => 'tmc-analytics' ) );
}

add_action( 'wp_head', 'tmc_analytics_head', 50 );
function tmc_analytics_head() {
	echo tmc_analytics_head_html(); // phpcs:ignore WordPress.Security.EscapeOutput -- values JSON-encoded / attributes escaped by core
}

/** Matomo image tracker for visitors without JavaScript (GIGW: works without scripts). */
add_action( 'wp_footer', 'tmc_analytics_noscript', 50 );
function tmc_analytics_noscript() {
	$active = tmc_analytics_active();
	if ( ! $active || 'matomo' !== $active['provider'] || ! tmc_analytics_should_track() ) {
		return;
	}
	$src = add_query_arg(
		array(
			'idsite' => $active['site_id'],
			'rec'    => 1,
		),
		$active['url'] . 'matomo.php'
	);
	printf( '<noscript><p class="screen-reader-text"><img src="%s" alt="" width="1" height="1" style="border:0"></p></noscript>' . "\n", esc_url( $src ) );
}

/* ================================================================ privacy policy */

/** Plain-language description of what the active provider collects ('' when analytics is off). */
function tmc_analytics_privacy_text() {
	$active = tmc_analytics_active();
	if ( ! $active ) {
		return '';
	}
	if ( 'matomo' === $active['provider'] ) {
		return __( 'This website measures how it is used with Matomo, an open-source analytics tool installed on a server controlled by Tata Memorial Centre, so the data is not shared with any analytics company. It does not set cookies and respects the "Do Not Track" setting of your browser. The figures (such as pages viewed, approximate location and type of device) are used only to improve the website.', 'tmc' );
	}
	return __( 'This website measures how it is used with Google Analytics 4, a service of Google LLC. It runs without analytics or advertising cookies, Google signals and advertising personalisation are switched off, and the data is used only to improve the website. Data is processed by Google and may be stored outside India.', 'tmc' );
}

/** Adds a "Website analytics" section to the privacy policy page (and its translations). */
add_filter( 'the_content', 'tmc_analytics_privacy_section', 20 );
function tmc_analytics_privacy_section( $content ) {
	if ( ! is_singular( 'page' ) || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}
	$policy_id = (int) get_option( 'wp_page_for_privacy_policy' );
	$page_id   = get_the_ID();
	$is_policy = $policy_id && ( $page_id === $policy_id || ( function_exists( 'pll_get_post' ) && (int) pll_get_post( $policy_id, (string) pll_get_post_language( $page_id ) ) === $page_id ) );
	$text      = $is_policy ? tmc_analytics_privacy_text() : '';
	if ( '' === $text ) {
		return $content;
	}
	return $content . sprintf( '<h2 id="website-analytics">%s</h2><p>%s</p>', esc_html__( 'Website analytics', 'tmc' ), esc_html( $text ) );
}

// Suggested policy text for Settings → Privacy (the WordPress privacy policy guide).
add_action(
	'admin_init',
	function () {
		$text = tmc_analytics_privacy_text();
		if ( $text && function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content( 'TMC website analytics', '<p>' . esc_html( $text ) . '</p>' );
		}
	}
);

/* ================================================================ dashboard: links for TMC */

add_action( 'wp_dashboard_setup', 'tmc_analytics_dashboard_widget' );
function tmc_analytics_dashboard_widget() {
	if ( current_user_can( 'manage_options' ) ) {
		wp_add_dashboard_widget( 'tmc_analytics', 'Analytics and search', 'tmc_analytics_dashboard_render' );
	}
}

function tmc_analytics_dashboard_render() {
	$active = tmc_analytics_active();
	$links  = array();
	if ( $active && 'matomo' === $active['provider'] ) {
		$links[] = array( 'Matomo dashboard for this site', add_query_arg( array( 'module' => 'CoreHome', 'action' => 'index', 'idSite' => $active['site_id'], 'period' => 'month', 'date' => 'today' ), $active['url'] . 'index.php' ) );
	} elseif ( $active ) {
		$links[] = array( 'Google Analytics', 'https://analytics.google.com/' );
	}
	if ( tmc_analytics_site_value( 'google_verify' ) ) {
		$links[] = array( 'Google Search Console', 'https://search.google.com/search-console?resource_id=' . rawurlencode( home_url( '/' ) ) );
	}
	if ( tmc_analytics_site_value( 'bing_verify' ) ) {
		$links[] = array( 'Bing Webmaster Tools', 'https://www.bing.com/webmasters/home?siteUrl=' . rawurlencode( home_url( '/' ) ) );
	}
	if ( ! $links ) {
		echo '<p>Web analytics and search-console verification are not configured for this site. A Super Admin can set them up in Network Admin → Settings → Analytics &amp; Search.</p>';
		return;
	}
	echo '<ul>';
	foreach ( $links as list( $label, $url ) ) {
		printf( '<li><a href="%s" target="_blank" rel="noopener noreferrer">%s<span class="screen-reader-text"> (opens in a new tab)</span></a></li>', esc_url( $url ), esc_html( $label ) );
	}
	echo '</ul>';
	printf( '<p>XML sitemap for search consoles: <code>%s</code></p>', esc_html( home_url( '/wp-sitemap.xml' ) ) );
}

/* ================================================================ network admin settings */

add_action(
	'network_admin_menu',
	function () {
		add_submenu_page( 'settings.php', 'Analytics & Search', 'Analytics & Search', 'manage_network_options', 'tmc-analytics', 'tmc_analytics_page' );
	}
);

function tmc_analytics_page() {
	if ( ! current_user_can( 'manage_network_options' ) ) {
		wp_die( 'You are not allowed to manage analytics settings.', 403 );
	}
	$settings = tmc_analytics_settings();
	$env_url  = getenv( 'TMC_MATOMO_URL' );
	$fields   = tmc_analytics_site_fields();

	echo '<div class="wrap"><h1>Analytics &amp; Search</h1>';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only
	if ( isset( $_GET['updated'] ) ) {
		echo '<div class="notice notice-success is-dismissible" role="status"><p>Settings saved.</p></div>';
	}
	$rejected = get_site_transient( 'tmc_analytics_rejected_' . get_current_user_id() );
	if ( $rejected ) {
		delete_site_transient( 'tmc_analytics_rejected_' . get_current_user_id() );
		printf( '<div class="notice notice-warning" role="alert"><p><strong>Some values were not valid and were not saved:</strong> %s</p></div>', esc_html( implode( '; ', (array) $rejected ) ) );
	}

	echo '<p>Web analytics and search-engine verification for all TMC websites. Nothing is added to the websites until a provider and the values for a site are filled in.</p>';
	printf( '<form method="post" action="%s">', esc_url( network_admin_url( 'edit.php?action=tmc_analytics' ) ) );
	wp_nonce_field( 'tmc_analytics' );

	echo '<h2>Analytics provider</h2><fieldset><legend class="screen-reader-text">Analytics provider</legend>';
	foreach ( TMC_ANALYTICS_PROVIDERS as $value => $label ) {
		printf(
			'<p><label><input type="radio" name="provider" value="%s"%s> %s</label></p>',
			esc_attr( $value ),
			checked( $settings['provider'], $value, false ),
			esc_html( $label )
		);
	}
	echo '</fieldset>';
	echo '<p class="description"><strong>Matomo</strong> keeps all data on a server TMC controls (India data residency), runs without cookies and needs no consent banner. <strong>Google Analytics 4</strong> loads a script from Google and data may be stored outside India; it runs in consent mode "denied" (no cookies). Confirm the choice with TMC before enabling it.</p>';

	echo '<table class="form-table" role="presentation"><tr><th scope="row"><label for="tmc-matomo-url">Matomo address</label></th><td>';
	if ( $env_url ) {
		printf( '<input type="url" id="tmc-matomo-url" class="regular-text code" value="%s" readonly aria-describedby="tmc-matomo-url-help"><p class="description" id="tmc-matomo-url-help">Set by the environment variable TMC_MATOMO_URL on this server.</p>', esc_attr( tmc_analytics_matomo_url() ) );
	} else {
		printf( '<input type="url" id="tmc-matomo-url" name="matomo_url" class="regular-text code" value="%s" placeholder="https://analytics.example.gov.in/" aria-describedby="tmc-matomo-url-help"><p class="description" id="tmc-matomo-url-help">Base address of the Matomo installation (HTTPS required in production).</p>', esc_attr( $settings['matomo_url'] ) );
	}
	echo '</td></tr></table>';

	echo '<h2>Per website</h2><p>Leave a field empty to switch that item off for the site.</p>';
	echo '<table class="widefat striped"><caption class="screen-reader-text">Analytics and verification settings per website</caption><thead><tr><th scope="col">Website</th>';
	foreach ( $fields as list( $label ) ) {
		printf( '<th scope="col">%s</th>', esc_html( $label ) );
	}
	echo '</tr></thead><tbody>';
	foreach ( get_sites( array( 'number' => 100, 'deleted' => 0 ) ) as $site ) {
		$blog_id = (int) $site->blog_id;
		$name    = get_blog_option( $blog_id, 'blogname' );
		printf( '<tr><th scope="row">%s<br><code>%s</code></th>', esc_html( $name ), esc_html( $site->domain ) );
		foreach ( $fields as $key => list( $label, $help ) ) {
			$id = 'tmc-' . $key . '-' . $blog_id;
			printf(
				'<td><label class="screen-reader-text" for="%1$s">%2$s — %3$s</label><input type="%4$s" id="%1$s" name="sites[%5$d][%6$s]" value="%7$s" class="%8$s" title="%9$s"%10$s></td>',
				esc_attr( $id ),
				esc_html( $label ),
				esc_html( $name ),
				'social_image' === $key ? 'url' : 'text',
				$blog_id,
				esc_attr( $key ),
				esc_attr( $settings['sites'][ $blog_id ][ $key ] ?? '' ),
				'social_image' === $key ? 'regular-text code' : 'code',
				esc_attr( $help ),
				'matomo_site_id' === $key ? ' inputmode="numeric" size="5"' : ''
			);
		}
		echo '</tr>';
	}
	echo '</tbody></table>';
	echo '<ul class="description" style="list-style:disc;padding-left:1.5em">';
	foreach ( $fields as list( $label, $help ) ) {
		printf( '<li><strong>%s</strong>: %s</li>', esc_html( $label ), esc_html( $help ) );
	}
	echo '</ul>';
	submit_button( 'Save settings' );
	echo '</form></div>';
}

add_action( 'network_admin_edit_tmc_analytics', 'tmc_analytics_save' );
function tmc_analytics_save() {
	if ( ! current_user_can( 'manage_network_options' ) ) {
		wp_die( 'You are not allowed to manage analytics settings.', 403 );
	}
	check_admin_referer( 'tmc_analytics' );
	// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- sanitised field by field below
	$input = array(
		'provider'   => sanitize_key( wp_unslash( $_POST['provider'] ?? 'none' ) ),
		'matomo_url' => wp_unslash( $_POST['matomo_url'] ?? tmc_analytics_settings()['matomo_url'] ),
		'sites'      => isset( $_POST['sites'] ) && is_array( $_POST['sites'] ) ? wp_unslash( $_POST['sites'] ) : array(),
	);
	// phpcs:enable
	$rejected = array();
	$result   = tmc_analytics_update( $input, $rejected );
	if ( $rejected ) {
		set_site_transient( 'tmc_analytics_rejected_' . get_current_user_id(), $rejected, 5 * MINUTE_IN_SECONDS );
	}
	wp_safe_redirect( add_query_arg( array( 'page' => 'tmc-analytics', 'updated' => $result ? '1' : '0' ), network_admin_url( 'settings.php' ) ) );
	exit;
}

/**
 * Validate and store the network settings (also used by tests). Invalid values are dropped and
 * described in $rejected. Changes are audit-logged (values are identifiers, not secrets).
 *
 * @param array $input    provider, matomo_url, sites[blog_id][field].
 * @param array $rejected Filled with human-readable descriptions of rejected values.
 * @return bool True when saved.
 */
function tmc_analytics_update( array $input, array &$rejected = array() ) {
	$before   = tmc_analytics_settings();
	$provider = isset( TMC_ANALYTICS_PROVIDERS[ $input['provider'] ?? '' ] ) ? $input['provider'] : 'none';
	$url_raw  = trim( (string) ( $input['matomo_url'] ?? '' ) );
	$url      = tmc_analytics_sanitize_base_url( $url_raw );
	if ( '' !== $url_raw && '' === $url ) {
		$rejected[] = 'Matomo address (must be a full http(s) address; HTTPS in production)';
	}
	$sites = array();
	foreach ( (array) ( $input['sites'] ?? array() ) as $blog_id => $values ) {
		$blog_id = absint( $blog_id );
		if ( ! $blog_id || ! get_site( $blog_id ) || ! is_array( $values ) ) {
			continue;
		}
		foreach ( array_keys( tmc_analytics_site_fields() ) as $key ) {
			$raw   = trim( (string) ( $values[ $key ] ?? '' ) );
			$clean = tmc_analytics_sanitize_site_value( $key, $raw );
			if ( '' !== $raw && '' === $clean ) {
				$rejected[] = sprintf( '%s for %s', tmc_analytics_site_fields()[ $key ][0], get_blog_option( $blog_id, 'blogname' ) );
			}
			if ( '' !== $clean ) {
				$sites[ $blog_id ][ $key ] = $clean;
			}
		}
	}
	$after = array(
		'provider'   => $provider,
		'matomo_url' => $url,
		'sites'      => $sites,
	);
	if ( $after === $before ) {
		return true;
	}
	update_site_option( TMC_ANALYTICS_OPTION, $after );
	if ( function_exists( 'tmc_audit' ) ) {
		tmc_audit(
			'analytics_settings_changed',
			array(
				'object_type'  => 'network_option',
				'object_title' => TMC_ANALYTICS_OPTION,
				'details'      => array( 'from' => $before, 'to' => $after ),
			)
		);
	}
	return true;
}
