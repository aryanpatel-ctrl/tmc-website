<?php
/**
 * Analytics and search-console settings (tender §4.10): validation, nothing output until
 * configured, cookieless Matomo with a no-JavaScript fallback, GA4 in consent mode "denied",
 * verification tags on the home page only, no tracking of logged-in editors, privacy-policy
 * section, audit trail. Restores the original network setting afterwards.
 *
 *   docker compose run --rm -T wpcli --url=tmh.<base> eval-file - < scripts/tests/analytics-test.php
 */

global $wpdb, $wp_query, $wp_the_query, $post;
$pass = 0;
$fail = 0;
$t    = function ( $label, $ok ) use ( &$pass, &$fail ) {
	WP_CLI::log( ( $ok ? '  PASS  ' : '  FAIL  ' ) . $label );
	$ok ? $pass++ : $fail++;
};
$visit = function ( array $query_vars ) {
	global $wp_query, $wp_the_query, $post;
	$wp_the_query = new WP_Query( $query_vars + array( 'lang' => '' ) );
	$wp_query     = $wp_the_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
	$post         = $wp_query->post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
};
$capture = function ( $callback ) {
	ob_start();
	$callback();
	return (string) ob_get_clean();
};
$blog        = get_current_blog_id();
$original    = get_site_option( TMC_ANALYTICS_OPTION, null );
$first_audit = (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id),0) FROM ' . tmc_audit_table() );
$front       = (int) get_option( 'page_on_front' );
$inner       = get_page_by_path( 'about-us' );
wp_set_current_user( 0 );

if ( getenv( 'TMC_MATOMO_URL' ) ) {
	WP_CLI::warning( 'TMC_MATOMO_URL is set in this environment; Matomo address checks use it.' );
}

WP_CLI::log( '— Off until configured' );
tmc_analytics_update( array( 'provider' => 'none' ) );
$visit( array( 'page_id' => $front ) );
$t( 'provider "none": no tracker code', '' === tmc_analytics_head_html() && '' === $capture( 'tmc_analytics_noscript' ) );
$t( 'provider "none": no privacy-policy section', '' === tmc_analytics_privacy_text() );
tmc_analytics_update( array( 'provider' => 'matomo', 'matomo_url' => 'https://analytics.example.gov.in/matomo' ) );
$t( 'Matomo chosen but no site ID for this site: still nothing', null === tmc_analytics_active() && '' === tmc_analytics_head_html() );

WP_CLI::log( '— Validation' );
$rejected = array();
tmc_analytics_update(
	array(
		'provider'   => 'matomo',
		'matomo_url' => 'ftp://bad.example',
		'sites'      => array(
			$blog => array(
				'matomo_site_id' => 'abc',
				'ga4_id'         => 'UA-12345-1',
				'google_verify'  => '<meta name="google-site-verification" content="AbCdEf1234567890_-xyz" />',
				'bing_verify'    => 'bad token!',
				'social_image'   => 'javascript:alert(1)',
			),
			999999 => array( 'matomo_site_id' => '5' ),
		),
	),
	$rejected
);
$saved = tmc_analytics_settings();
$t( 'invalid Matomo address, site ID, GA4 ID, Bing token and image URL rejected (' . count( $rejected ) . ')', 5 === count( $rejected ) && '' === $saved['matomo_url'] && ! isset( $saved['sites'][ $blog ]['matomo_site_id'], $saved['sites'][ $blog ]['ga4_id'], $saved['sites'][ $blog ]['bing_verify'], $saved['sites'][ $blog ]['social_image'] ) );
$t( 'Google token extracted from a pasted meta tag', 'AbCdEf1234567890_-xyz' === tmc_analytics_site_value( 'google_verify' ) );
$t( 'unknown site IDs ignored', ! isset( $saved['sites'][999999] ) );

WP_CLI::log( '— Matomo (cookieless)' );
tmc_analytics_update(
	array(
		'provider'   => 'matomo',
		'matomo_url' => 'https://Analytics.Example.gov.in/matomo',
		'sites'      => array(
			$blog => array(
				'matomo_site_id' => '7',
				'google_verify'  => 'AbCdEf1234567890_-xyz',
				'bing_verify'    => '0123456789ABCDEF0123456789ABCDEF',
				'social_image'   => 'https://www.example.gov.in/share.png',
			),
		),
	)
);
$t( 'Matomo address normalised with a trailing slash', getenv( 'TMC_MATOMO_URL' ) || 'https://analytics.example.gov.in/matomo/' === tmc_analytics_matomo_url() );
$visit( array( 'page_id' => $front ) );
$head = tmc_analytics_head_html();
$t( 'tracker code: cookies disabled, Do Not Track honoured, site ID, own server', false !== strpos( $head, '"disableCookies"' ) && false !== strpos( $head, '"setDoNotTrack",true' ) && false !== strpos( $head, '"setSiteId","7"' ) && false !== strpos( $head, 'matomo.js' ) && false !== strpos( $head, 'analytics.example.gov.in' ) );
$t( 'tracker code is a single inline script (no external CDN)', 1 === substr_count( $head, '<script' ) && false === strpos( $head, ' src="' ) );
$noscript = $capture( 'tmc_analytics_noscript' );
$t( 'no-JavaScript image tracker', false !== strpos( $noscript, '<noscript>' ) && false !== strpos( $noscript, 'analytics.example.gov.in/matomo/matomo.php?idsite=7' ) && false !== strpos( $noscript, 'rec=1' ) && false !== strpos( $noscript, 'alt=""' ) );
$verify = $capture( 'tmc_analytics_verification_tags' );
$t( 'home page: Google and Bing verification tags', false !== strpos( $verify, '<meta name="google-site-verification" content="AbCdEf1234567890_-xyz">' ) && false !== strpos( $verify, '<meta name="msvalidate.01" content="0123456789ABCDEF0123456789ABCDEF">' ) );
$t( 'default social image used by the SEO tags', 'https://www.example.gov.in/share.png' === ( tmc_seo_default_image()['url'] ?? '' ) );
if ( $inner ) {
	$visit( array( 'page_id' => $inner->ID ) );
	$t( 'inner page: no verification tags, tracker present', '' === $capture( 'tmc_analytics_verification_tags' ) && '' !== tmc_analytics_head_html() );
}
$admins = get_super_admins();
$admin  = $admins ? get_user_by( 'login', reset( $admins ) ) : null;
if ( $admin ) {
	wp_set_current_user( $admin->ID );
	$t( 'logged-in editors are not tracked', '' === tmc_analytics_head_html() && '' === $capture( 'tmc_analytics_noscript' ) );
	wp_set_current_user( 0 );
}

WP_CLI::log( '— Privacy policy' );
$policy = (int) get_option( 'wp_page_for_privacy_policy' );
if ( $policy ) {
	$visit( array( 'page_id' => $policy ) );
	$wp_query->the_post();
	$content = apply_filters( 'the_content', get_post_field( 'post_content', $policy ) );
	$t( 'privacy policy page gets a "Website analytics" section naming Matomo', false !== strpos( $content, 'id="website-analytics"' ) && false !== strpos( $content, 'Matomo' ) && false !== strpos( $content, 'does not set cookies' ) );
	wp_reset_postdata();
	if ( $inner ) {
		$visit( array( 'page_id' => $inner->ID ) );
		$wp_query->the_post();
		$t( 'other pages are not changed', false === strpos( apply_filters( 'the_content', 'Text' ), 'website-analytics' ) );
		wp_reset_postdata();
	}
} else {
	WP_CLI::log( '  (skipped: no privacy policy page on this site)' );
}

WP_CLI::log( '— Google Analytics 4 (consent mode denied)' );
tmc_analytics_update( array( 'provider' => 'ga4', 'sites' => array( $blog => array( 'ga4_id' => 'g-abc123xyz9' ) ) ) );
$visit( array( 'page_id' => $front ) );
$head = tmc_analytics_head_html();
$t( 'GA4: measurement ID upper-cased and used', false !== strpos( $head, 'googletagmanager.com/gtag/js?id=G-ABC123XYZ9' ) && false !== strpos( $head, '"G-ABC123XYZ9"' ) );
$t( 'GA4: analytics and ad storage denied, signals off', false !== strpos( $head, '"analytics_storage":"denied"' ) && false !== strpos( $head, '"ad_storage":"denied"' ) && false !== strpos( $head, '"allow_google_signals":false' ) );
$t( 'GA4: no Matomo image fallback', '' === $capture( 'tmc_analytics_noscript' ) );
$t( 'GA4: privacy text says data may leave India', false !== strpos( tmc_analytics_privacy_text(), 'outside India' ) );

WP_CLI::log( '— Audit trail and restore' );
$logged = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . tmc_audit_table() . " WHERE id > %d AND action = 'analytics_settings_changed'", $first_audit ) );
$t( "settings changes audit-logged ($logged)", $logged >= 4 );
null === $original ? delete_site_option( TMC_ANALYTICS_OPTION ) : update_site_option( TMC_ANALYTICS_OPTION, $original );
$t( 'original setting restored', ( null === $original ? false : $original ) === get_site_option( TMC_ANALYTICS_OPTION, false ) );
$wp_the_query = new WP_Query();
$wp_query     = $wp_the_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
$t( 'audit chain intact', tmc_audit_verify()['ok'] );

WP_CLI::log( '' );
$fail ? WP_CLI::error( "$fail failed, $pass passed" ) : WP_CLI::success( "all $pass checks passed" );
