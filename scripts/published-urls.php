<?php
/**
 * Print the published URL inventory of the current site as JSON (tmc_published_urls(), in
 * mu-plugins/tmc-core/quality.php). The link crawler compares it with what it can reach by
 * following links, to find orphaned pages (tender §4.11: "no orphaned pages").
 *
 *   docker compose run --rm -T wpcli --url=<site> eval-file - < scripts/published-urls.php > published-<site>.json
 */

if ( ! function_exists( 'tmc_published_urls' ) ) {
	WP_CLI::error( 'tmc-core (quality.php) is not loaded on ' . home_url() );
}

echo wp_json_encode(
	array(
		'site'      => home_url( '/' ),
		'generated' => gmdate( 'c' ),
		'urls'      => tmc_published_urls(),
	),
	JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
) . "\n";
