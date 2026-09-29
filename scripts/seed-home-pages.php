<?php
/**
 * Give the current site a static Home page in English and Hindi (linked translations) and a
 * Hindi site title. Safe to re-run.
 *
 *   docker compose run --rm -T wpcli --url=<site> eval-file - < scripts/seed-home-pages.php
 */

$hindi_names = array(
	''           => 'टाटा मेमोरियल केंद्र',
	'tmh'        => 'टाटा मेमोरियल अस्पताल, मुंबई',
	'hbchrcv'    => 'होमी भाभा कैंसर अस्पताल एवं अनुसंधान केंद्र, विशाखापत्तनम',
	'mpmmcc'     => 'महामना पंडित मदन मोहन मालवीय कैंसर केंद्र एवं होमी भाभा कैंसर अस्पताल, वाराणसी',
	'hbchrcmzp'  => 'होमी भाभा कैंसर अस्पताल एवं अनुसंधान केंद्र, मुज़फ़्फ़रपुर',
	'hbchpunjab' => 'होमी भाभा कैंसर अस्पताल, न्यू चंडीगढ़',
);

$host = wp_parse_url( home_url(), PHP_URL_HOST );
$slug = DOMAIN_CURRENT_SITE === $host ? '' : strstr( $host, '.', true );
if ( ! isset( $hindi_names[ $slug ] ) ) {
	WP_CLI::error( "Unknown site $host" );
}
$name_en = get_bloginfo( 'name' );
$name_hi = $hindi_names[ $slug ];

$find_or_create = function ( $post_name, $title, $content, $lang ) {
	$existing = get_page_by_path( $post_name, OBJECT, 'page' );
	if ( $existing ) {
		return $existing->ID;
	}
	$id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_name'    => $post_name,
			'post_title'   => $title,
			'post_content' => $content,
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		WP_CLI::error( $id->get_error_message() );
	}
	pll_set_post_language( $id, $lang );
	return $id;
};

$home_en = $find_or_create(
	'home',
	'Home',
	'<!-- wp:paragraph --><p>Welcome to the official website of ' . esc_html( $name_en ) . '.</p><!-- /wp:paragraph -->',
	'en'
);
$home_hi = $find_or_create(
	'home-hi',
	'मुखपृष्ठ',
	'<!-- wp:paragraph --><p>' . esc_html( $name_hi ) . ' की आधिकारिक वेबसाइट में आपका स्वागत है।</p><!-- /wp:paragraph -->',
	'hi'
);
pll_save_post_translations( array( 'en' => $home_en, 'hi' => $home_hi ) );

update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', $home_en ); // Polylang serves the Hindi translation on /hi/

// Hindi site title via Polylang string translations.
$language = PLL()->model->get_language( 'hi' );
$mo       = new PLL_MO();
$mo->import_from_db( $language );
$mo->add_entry( $mo->make_entry( $name_en, $name_hi ) );
$mo->export_to_db( $language );

PLL()->model->clean_languages_cache();
flush_rewrite_rules( false );
WP_CLI::success( "$host: Home #$home_en (en) ↔ #$home_hi (hi) — $name_hi" );
