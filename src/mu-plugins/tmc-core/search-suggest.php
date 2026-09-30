<?php
/**
 * Search suggestions: GET /wp-json/tmc/v1/suggest?q=<text>&lang=<en|hi>
 *
 * Public, read-only. Answers from the search index (search-index.php), which holds published
 * content only, and returns nothing but title, URL and content type — no IDs, authors or text.
 *   - at least 2 and at most 100 characters (400 otherwise)
 *   - rate limited per client IP (default 120 requests per minute; filter tmc_suggest_rate_limit
 *     or env TMC_SUGGEST_RATE_LIMIT), 429 with Retry-After when exceeded
 *   - cached in the object cache until the index changes; anonymous responses may be cached by
 *     browsers and proxies for 5 minutes
 */

defined( 'ABSPATH' ) || exit;

const TMC_SUGGEST_WINDOW = 60; // seconds

add_action( 'rest_api_init', 'tmc_suggest_register_route' );
function tmc_suggest_register_route() {
	register_rest_route(
		'tmc/v1',
		'/suggest',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'tmc_suggest_endpoint',
			'permission_callback' => '__return_true', // public: returns only published titles
			'args'                => array(
				'q'    => array(
					'description' => 'Text typed so far (2–100 characters).',
					'type'        => 'string',
					'required'    => true,
				),
				'lang' => array(
					'description' => 'Language code, e.g. en or hi. Defaults to the site default language.',
					'type'        => 'string',
					'default'     => '',
				),
			),
		)
	);
}

function tmc_suggest_endpoint( WP_REST_Request $request ) {
	$rate        = tmc_rate_limit( 'suggest', tmc_suggest_rate_limit(), TMC_SUGGEST_WINDOW ); // shared limiter: rate-limit.php
	$retry_after = $rate['over'] ? $rate['retry_after'] : 0;
	if ( $retry_after ) {
		$response = rest_convert_error_to_response( new WP_Error( 'tmc_rate_limited', 'Too many requests. Please try again shortly.', array( 'status' => 429 ) ) );
		$response->header( 'Retry-After', (string) $retry_after );
		return $response;
	}

	$query = tmc_search_normalise_query( (string) $request->get_param( 'q' ) );
	if ( mb_strlen( $query ) < 2 ) {
		return new WP_Error( 'tmc_query_too_short', 'Type at least 2 characters.', array( 'status' => 400 ) );
	}
	if ( mb_strlen( trim( (string) $request->get_param( 'q' ) ) ) > TMC_SEARCH_MAX_QUERY ) {
		return new WP_Error( 'tmc_query_too_long', 'The search text is too long.', array( 'status' => 400 ) );
	}

	$lang = sanitize_key( (string) $request->get_param( 'lang' ) );
	if ( function_exists( 'pll_languages_list' ) ) {
		$lang = in_array( $lang, (array) pll_languages_list(), true ) ? $lang : (string) pll_default_language();
	} else {
		$lang = '';
	}

	$key   = 'suggest:' . md5( mb_strtolower( $query ) . '|' . $lang ) . ':' . wp_cache_get_last_changed( 'tmc_search' );
	$items = wp_cache_get( $key, 'tmc_search' );
	$hit   = is_array( $items );
	if ( ! $hit ) {
		$items = array();
		foreach ( tmc_search_suggest( $query, $lang, 8 ) as $row ) {
			$url = 'attachment' === $row['post_type'] ? wp_get_attachment_url( $row['post_id'] ) : get_permalink( $row['post_id'] );
			if ( ! $url ) {
				continue;
			}
			$items[] = array(
				'title' => $row['title'],
				'url'   => $url,
				'type'  => tmc_search_type_label( $row['post_type'] ),
			);
		}
		wp_cache_set( $key, $items, 'tmc_search', 10 * MINUTE_IN_SECONDS );
	}

	$response = rest_ensure_response(
		array(
			'query' => $query,
			'count' => count( $items ),
			'items' => $items,
		)
	);
	$response->header( 'X-TMC-Cache', $hit ? 'hit' : 'miss' );
	if ( ! is_user_logged_in() ) {
		$response->header( 'Cache-Control', 'public, max-age=300' );
	}
	return $response;
}

function tmc_suggest_rate_limit() {
	$env = (int) getenv( 'TMC_SUGGEST_RATE_LIMIT' );
	return max( 1, (int) apply_filters( 'tmc_suggest_rate_limit', $env > 0 ? $env : 120 ) );
}

