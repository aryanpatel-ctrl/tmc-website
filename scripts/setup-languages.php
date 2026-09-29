<?php
/**
 * Configure Polylang on the current site: English (default) + Hindi (tender §4.13).
 * More languages can be added later from Languages → Languages, with no template changes.
 *
 * Run per site:
 *   docker compose run --rm -T wpcli --url=<site> eval-file - < scripts/setup-languages.php
 */

if ( ! function_exists( 'PLL' ) || ! PLL() || empty( PLL()->model ) ) {
	WP_CLI::error( 'Polylang is not active on ' . home_url() );
}

$model = PLL()->model;

$languages = array(
	// Government of India English uses British spelling (Centre, programme).
	array( 'name' => 'English', 'slug' => 'en', 'locale' => 'en_GB', 'rtl' => false, 'term_group' => 0, 'flag' => 'in' ),
	array( 'name' => 'हिन्दी', 'slug' => 'hi', 'locale' => 'hi_IN', 'rtl' => false, 'term_group' => 1, 'flag' => 'in' ),
);

foreach ( $languages as $language ) {
	if ( $model->get_language( $language['slug'] ) ) {
		WP_CLI::log( "  {$language['slug']}: already configured" );
		continue;
	}
	$result = $model->languages->add( $language );
	if ( is_wp_error( $result ) ) {
		WP_CLI::error( $language['slug'] . ': ' . $result->get_error_message() );
	}
	WP_CLI::log( "  {$language['slug']}: added" );
}

$settings = array(
	'default_lang'  => 'en',
	'force_lang'    => 1,     // language comes from the URL directory: /hi/...
	'hide_default'  => true,  // English URLs have no /en/ prefix
	'rewrite'       => true,  // /hi/ rather than /language/hi/
	'browser'       => false, // visitors choose the language; no auto-redirect
	'redirect_lang' => true,  // Hindi home page is /hi/, not /hi/home-hi/
	'media_support' => false, // one media library shared by both languages
);
foreach ( $settings as $key => $value ) {
	$error = PLL()->options->set( $key, $value );
	if ( $error->has_errors() ) {
		WP_CLI::warning( "$key: " . $error->get_error_message() );
	}
}
PLL()->options->save();

// Anything created before Polylang (e.g. default category) becomes English.
$model->set_language_in_mass();
$model->clean_languages_cache();
flush_rewrite_rules( false );

WP_CLI::success( home_url() . ' → ' . implode( ', ', wp_list_pluck( $model->languages->get_list(), 'name' ) ) );
