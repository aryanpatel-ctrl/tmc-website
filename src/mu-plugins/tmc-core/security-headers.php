<?php
/**
 * HTTP security headers and security.txt (tender §4.8; RTM R-4.8-6: OWASP A05, R-4.8-7).
 *
 * Headers that are the same for every response (X-Content-Type-Options, X-Frame-Options,
 * Referrer-Policy, Permissions-Policy, Cross-Origin-Opener-Policy, Cross-Origin-Resource-Policy)
 * are set by Apache for static files too: wordpress/apache-tmc.conf. This module adds what depends
 * on the request:
 *
 *   Content-Security-Policy  Public pages: scripts only from this site or carrying the per-request
 *                            nonce (every script WordPress prints goes through wp_*_script_tag(), which
 *                            receives the nonce below); no eval, no plugins/objects, no framing by
 *                            other sites, forms only to this site. Styles allow inline because core
 *                            block supports and global styles print inline style attributes.
 *                            Login and admin screens: a baseline policy (no objects, no <base>, no
 *                            framing by other sites) because WordPress core still prints inline
 *                            scripts and uses underscore templates (eval) there.
 *                            Extend with the "tmc_csp_directives" filter (e.g. a map tile server).
 *   Strict-Transport-Security  whenever the request is HTTPS (TMC_HSTS_MAX_AGE, default one year;
 *                            TMC_HSTS_INCLUDE_SUBDOMAINS=1 only once every tmc.gov.in host has HTTPS).
 *
 * NOTE for full-page caching: the nonce changes on every request, so a page cache must store and
 * replay the Content-Security-Policy header together with the HTML (or bypass the cache).
 *
 * /.well-known/security.txt (RFC 9116) tells researchers where to report vulnerabilities.
 * TMC_SECURITY_CONTACT (comma-separated mailto:/https: URIs) must be confirmed by TMC before
 * go-live; until then the file carries a clearly marked placeholder.
 */

defined( 'ABSPATH' ) || exit;

/** One random nonce per request. */
function tmc_csp_nonce() {
	static $nonce = null;
	if ( null === $nonce ) {
		$nonce = rtrim( strtr( base64_encode( random_bytes( 18 ) ), '+/', '-_' ), '=' );
	}
	return $nonce;
}

function tmc_csp_add_nonce( $attributes ) {
	if ( is_array( $attributes ) && ! isset( $attributes['nonce'] ) ) {
		$attributes['nonce'] = tmc_csp_nonce();
	}
	return $attributes;
}
add_filter( 'wp_script_attributes', 'tmc_csp_add_nonce' );
add_filter( 'wp_inline_script_attributes', 'tmc_csp_add_nonce' );

/** Hosts of this network ("tmc.example" and "*.tmc.example") for images, fonts and media shared between sites. */
function tmc_csp_network_sources() {
	$domain = defined( 'DOMAIN_CURRENT_SITE' ) ? DOMAIN_CURRENT_SITE : (string) wp_parse_url( network_home_url(), PHP_URL_HOST );
	$domain = preg_replace( '/[^a-z0-9.\-:]/i', '', (string) $domain );
	return '' === $domain ? array() : array( $domain, '*.' . $domain );
}

/**
 * Directives for a context.
 *
 * @param string $context 'front' | 'login' | 'admin'.
 * @param bool   $https   Whether the request is HTTPS.
 * @return array<string,string[]>
 */
function tmc_csp_directives( $context, $https ) {
	$network = tmc_csp_network_sources();
	if ( 'front' === $context ) {
		$directives = array(
			'default-src'     => array( "'self'" ),
			'script-src'      => array( "'self'", "'nonce-" . tmc_csp_nonce() . "'" ),
			'style-src'       => array_merge( array( "'self'", "'unsafe-inline'" ), $network ),
			'img-src'         => array_merge( array( "'self'", 'data:' ), $network ),
			'font-src'        => array_merge( array( "'self'", 'data:' ), $network ),
			'media-src'       => array_merge( array( "'self'" ), $network ),
			'connect-src'     => array( "'self'" ),
			'frame-src'       => array( "'self'" ),
			'worker-src'      => array( "'self'" ),
			'manifest-src'    => array( "'self'" ),
			'object-src'      => array( "'none'" ),
			'base-uri'        => array( "'self'" ),
			'form-action'     => array( "'self'" ),
			'frame-ancestors' => array( "'self'" ),
		);
	} else {
		$directives = array(
			'object-src'      => array( "'none'" ),
			'base-uri'        => array( "'self'" ),
			'frame-ancestors' => array( "'self'" ),
		);
	}
	if ( $https ) {
		$directives['upgrade-insecure-requests'] = array();
	}
	return (array) apply_filters( 'tmc_csp_directives', $directives, $context );
}

function tmc_csp_header_value( array $directives ) {
	$parts = array();
	foreach ( $directives as $name => $sources ) {
		$name = preg_replace( '/[^a-z\-]/', '', strtolower( (string) $name ) );
		if ( '' === $name ) {
			continue;
		}
		$sources = array_filter( array_map( static fn( $s ) => preg_replace( '/[;,\r\n]/', '', (string) $s ), (array) $sources ) );
		$parts[] = trim( $name . ' ' . implode( ' ', array_unique( $sources ) ) );
	}
	return implode( '; ', $parts );
}

/**
 * All request-dependent security headers for a context.
 *
 * @return array<string,string>
 */
function tmc_security_headers( $context, $https ) {
	$headers = array( 'Content-Security-Policy' => tmc_csp_header_value( tmc_csp_directives( $context, $https ) ) );
	if ( $https ) {
		$hsts = 'max-age=' . tmc_env_int( 'TMC_HSTS_MAX_AGE', YEAR_IN_SECONDS, 0, 2 * YEAR_IN_SECONDS );
		if ( tmc_env_flag( 'TMC_HSTS_INCLUDE_SUBDOMAINS', false ) ) {
			$hsts .= '; includeSubDomains';
		}
		$headers['Strict-Transport-Security'] = $hsts;
	}
	return $headers;
}

function tmc_send_security_headers( $context ) {
	if ( headers_sent() || tmc_is_cli() ) {
		return;
	}
	foreach ( tmc_security_headers( $context, is_ssl() ) as $name => $value ) {
		header( $name . ': ' . $value );
	}
}

add_action( 'send_headers', fn() => tmc_send_security_headers( 'front' ) ); // public pages, feeds, sitemaps
add_action( 'login_init', fn() => tmc_send_security_headers( 'login' ) );
add_action( 'admin_init', fn() => tmc_send_security_headers( 'admin' ) );
add_action(
	'rest_api_init',
	function () {
		if ( is_ssl() && ! headers_sent() && ! tmc_is_cli() ) {
			$headers = tmc_security_headers( 'admin', true );
			header( 'Strict-Transport-Security: ' . $headers['Strict-Transport-Security'] );
		}
	}
);

// Pingbacks go through XML-RPC, which Apache blocks; do not advertise it.
add_filter(
	'wp_headers',
	function ( $headers ) {
		unset( $headers['X-Pingback'] );
		return $headers;
	}
);
add_filter( 'xmlrpc_enabled', '__return_false' );

/* ================================================================ security.txt */

/** Contact URIs; placeholder until TMC confirms one (TMC_SECURITY_CONTACT). */
function tmc_security_contacts() {
	$contacts = array();
	foreach ( explode( ',', tmc_env( 'TMC_SECURITY_CONTACT', '' ) ) as $contact ) {
		$contact = trim( $contact );
		if ( preg_match( '#^(mailto:[^\s@]+@[^\s@]+|https://\S+)$#i', $contact ) ) {
			$contacts[] = $contact;
		}
	}
	return $contacts;
}

function tmc_security_txt_body( $canonical, $now = null ) {
	$now      = null === $now ? time() : (int) $now;
	$contacts = tmc_security_contacts();
	$expires  = tmc_env( 'TMC_SECURITY_TXT_EXPIRES', '' );
	$expires  = preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $expires ) ? $expires : gmdate( 'Y-m-d\T00:00:00\Z', $now + 180 * DAY_IN_SECONDS );

	$lines = array( '# Security contact for the Tata Memorial Centre website network (RFC 9116).' );
	if ( ! $contacts ) {
		$lines[]  = '# PLACEHOLDER: the reporting contact is to be confirmed by TMC before go-live (set TMC_SECURITY_CONTACT).';
		$contacts = array( 'mailto:security-contact-to-be-confirmed@example.invalid' );
	}
	foreach ( $contacts as $contact ) {
		$lines[] = 'Contact: ' . $contact;
	}
	$lines[] = 'Expires: ' . $expires;
	$lines[] = 'Preferred-Languages: en, hi';
	$lines[] = 'Canonical: ' . $canonical;
	return implode( "\n", $lines ) . "\n";
}

add_action( 'parse_request', 'tmc_serve_security_txt', 0 );
function tmc_serve_security_txt() {
	$path = tmc_request_path();
	if ( '/security.txt' === $path ) {
		wp_safe_redirect( home_url( '/.well-known/security.txt' ), 301 );
		exit;
	}
	if ( '/.well-known/security.txt' !== $path ) {
		return;
	}
	tmc_send_security_headers( 'front' );
	status_header( 200 );
	header( 'Content-Type: text/plain; charset=utf-8' );
	header( 'Cache-Control: public, max-age=86400' );
	echo tmc_security_txt_body( home_url( '/.well-known/security.txt' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- text/plain built from validated values
	exit;
}
