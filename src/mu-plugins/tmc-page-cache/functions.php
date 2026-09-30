<?php
/**
 * TMC full-page cache — shared functions (no WordPress dependency).
 *
 * Used by engine.php (runs from wp-content/advanced-cache.php, before WordPress loads) and by
 * tmc-core/page-cache.php (purging, status, tests). This directory is not auto-loaded as a
 * must-use plugin: WordPress only loads PHP files at the top level of mu-plugins/.
 *
 * Storage is the existing Redis service (tmc-redis, internal network only), in its own database
 * (TMC_PAGE_CACHE_REDIS_DB, default 1) so it never mixes with the object cache in database 0.
 * A tiny RESP client is used instead of the phpredis extension so the same code works in the web
 * container and in the WP-CLI container (which has no phpredis).
 *
 * Keys
 *   tmcpc:gen:network        generation of the whole network
 *   tmcpc:gen:host:<host>    generation of one site host
 *   tmcpc:page:<sha1>        one cached page; sha1( network gen | host gen | scheme://host/path?query )
 *
 * Purging never deletes pages: it writes a new generation, so every page stored under the old one
 * becomes unreachable at once (O(1), no key scans) and expires by TTL / LRU. A generation that is
 * missing (Redis restart, eviction) is re-created with a new random value, never an old one, so
 * stale pages can never become reachable again.
 */

const TMC_PC_PREFIX         = 'tmcpc:';
const TMC_PC_FORMAT         = 1;
const TMC_PC_HEADER         = 'X-TMC-Cache';
const TMC_PC_REASON_HEADER  = 'X-TMC-Cache-Reason';

/** Rules from config.php, with optional overrides from wp-config.php constants. */
function tmc_pc_config() {
	static $config = null;
	if ( null === $config ) {
		$config = require __DIR__ . '/config.php';
		if ( defined( 'TMC_PAGE_CACHE_TTL' ) && (int) TMC_PAGE_CACHE_TTL > 0 ) {
			$config['ttl'] = (int) TMC_PAGE_CACHE_TTL;
		}
	}
	return $config;
}

/** Same normalisation WordPress multisite applies to HTTP_HOST (lower case, :80/:443 removed). */
function tmc_pc_normalize_host( $host ) {
	$host = strtolower( trim( (string) $host ) );
	if ( ':80' === substr( $host, -3 ) ) {
		$host = substr( $host, 0, -3 );
	} elseif ( ':443' === substr( $host, -4 ) ) {
		$host = substr( $host, 0, -4 );
	}
	return preg_match( '/^[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?(:[0-9]{1,5})?$/', $host ) ? $host : '';
}

/**
 * Check a query string against the allow-list.
 *
 * @return array{ok:bool,query:string,ignored:bool}  query = normalised (sorted) cache-relevant part.
 */
function tmc_pc_parse_query( $query, array $config ) {
	$result = array( 'ok' => true, 'query' => '', 'ignored' => false );
	$pairs  = array();
	foreach ( explode( '&', (string) $query ) as $part ) {
		if ( '' === $part ) {
			continue;
		}
		$kv    = explode( '=', $part, 2 );
		$name  = urldecode( $kv[0] );
		$value = isset( $kv[1] ) ? urldecode( $kv[1] ) : '';
		if ( 0 === strpos( $name, 'utm_' ) || in_array( $name, $config['ignore_params'], true ) ) {
			$result['ignored'] = true;
			continue;
		}
		if ( ! isset( $config['query_params'][ $name ] ) || isset( $pairs[ $name ] ) || ! preg_match( $config['query_params'][ $name ], $value ) ) {
			return array( 'ok' => false, 'query' => '', 'ignored' => $result['ignored'] );
		}
		$pairs[ $name ] = $value;
	}
	ksort( $pairs );
	$result['query'] = http_build_query( $pairs, '', '&', PHP_QUERY_RFC3986 );
	return $result;
}

/**
 * Decide from the request alone (before WordPress runs) whether it may be served from / stored in the cache.
 *
 * @param array $server  $_SERVER.
 * @param array $cookies $_COOKIE.
 * @return array{cacheable:bool,reason:string,frontend:bool,host:string,url:string,store:bool}
 */
function tmc_pc_request( array $server, array $cookies ) {
	$config = tmc_pc_config();
	$result = array( 'cacheable' => false, 'reason' => '', 'frontend' => false, 'host' => '', 'url' => '', 'store' => false );
	$no     = static function ( $reason ) use ( &$result ) {
		$result['reason'] = $reason;
		return $result;
	};

	// Only page views through the front controller; wp-login.php, wp-cron.php, admin screens … never.
	if ( 'index.php' !== basename( (string) ( $server['SCRIPT_FILENAME'] ?? '' ) ) ) {
		return $no( 'script' );
	}
	$result['frontend'] = true;

	$method = strtoupper( (string) ( $server['REQUEST_METHOD'] ?? 'GET' ) );
	if ( 'GET' !== $method && 'HEAD' !== $method ) {
		return $no( 'method' );
	}
	$host = tmc_pc_normalize_host( $server['HTTP_HOST'] ?? '' );
	if ( '' === $host ) {
		return $no( 'host' );
	}
	$uri = (string) ( $server['REQUEST_URI'] ?? '/' );
	if ( strlen( $uri ) > 2048 ) {
		return $no( 'uri' );
	}
	$parts = explode( '?', $uri, 2 );
	$path  = $parts[0];
	if ( '' === $path || '/' !== $path[0] || preg_match( $config['bypass_paths'], $path ) ) {
		return $no( 'path' );
	}
	if ( ! empty( $server['HTTP_AUTHORIZATION'] ) || ! empty( $server['PHP_AUTH_USER'] ) || ! empty( $server['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
		return $no( 'auth' );
	}
	foreach ( array_keys( $cookies ) as $name ) {
		if ( preg_match( $config['bypass_cookies'], (string) $name ) ) {
			return $no( 'cookie' );
		}
	}
	$query = tmc_pc_parse_query( $parts[1] ?? '', $config );
	if ( ! $query['ok'] ) {
		return $no( 'query' );
	}

	$https               = ! empty( $server['HTTPS'] ) && 'off' !== strtolower( (string) $server['HTTPS'] );
	$result['cacheable'] = true;
	$result['host']      = $host;
	$result['url']       = ( $https ? 'https' : 'http' ) . '://' . $host . $path . ( '' !== $query['query'] ? '?' . $query['query'] : '' );
	$result['store']     = ! $query['ignored'];
	return $result;
}

/* ------------------------------------------------------------------ Redis (RESP over TCP) */

/**
 * Connection to Redis (lazy, one per request). Returns null when Redis is unreachable — callers fail open.
 *
 * @param bool $reset Close the connection (after a protocol error) so the next call starts clean.
 * @return resource|null
 */
function tmc_pc_redis( $reset = false ) {
	static $conn = null, $failed = false;
	if ( $reset ) {
		if ( $conn ) {
			fclose( $conn );
		}
		$conn   = null;
		$failed = true; // do not retry within the same request
		return null;
	}
	if ( $conn || $failed ) {
		return $conn;
	}
	$host  = defined( 'WP_REDIS_HOST' ) ? WP_REDIS_HOST : 'tmc-valkey';
	$port  = defined( 'WP_REDIS_PORT' ) ? (int) WP_REDIS_PORT : 6379;
	$errno = 0;
	$error = '';
	$conn  = @stream_socket_client( 'tcp://' . $host . ':' . $port, $errno, $error, 0.5 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- unreachable Redis must not print warnings into pages
	if ( ! $conn ) {
		$conn   = null;
		$failed = true;
		return null;
	}
	stream_set_timeout( $conn, 1 );
	$ok = true;
	if ( defined( 'WP_REDIS_PASSWORD' ) && '' !== (string) WP_REDIS_PASSWORD ) {
		$ok = 'OK' === tmc_pc_exec( $conn, array( 'AUTH', (string) WP_REDIS_PASSWORD ) );
	}
	$db = defined( 'TMC_PAGE_CACHE_REDIS_DB' ) ? (int) TMC_PAGE_CACHE_REDIS_DB : 1;
	if ( $ok && $db > 0 ) {
		$ok = 'OK' === tmc_pc_exec( $conn, array( 'SELECT', (string) $db ) );
	}
	if ( ! $ok ) {
		return tmc_pc_redis( true );
	}
	return $conn;
}

/**
 * Run one command. Returns the reply (string|int|array|null), or false on any error.
 * After an I/O failure the connection is dropped (the protocol state is unknown); an error reply
 * from Redis leaves it usable.
 */
function tmc_pc_cmd( array $args ) {
	$conn = tmc_pc_redis();
	if ( ! $conn ) {
		return false;
	}
	$GLOBALS['tmc_pc_io_error'] = false;
	$reply                      = tmc_pc_exec( $conn, $args );
	if ( false === $reply && $GLOBALS['tmc_pc_io_error'] ) {
		tmc_pc_redis( true );
	}
	return $reply;
}

/** Write one command in RESP and read its reply. */
function tmc_pc_exec( $conn, array $args ) {
	$payload = '*' . count( $args ) . "\r\n";
	foreach ( $args as $arg ) {
		$arg      = (string) $arg;
		$payload .= '$' . strlen( $arg ) . "\r\n" . $arg . "\r\n";
	}
	$length = strlen( $payload );
	for ( $written = 0; $written < $length; $written += $n ) {
		$n = fwrite( $conn, substr( $payload, $written ) );
		if ( false === $n || 0 === $n ) {
			$GLOBALS['tmc_pc_io_error'] = true;
			return false;
		}
	}
	return tmc_pc_read( $conn );
}

/** Read one RESP reply. Error replies and I/O failures return false. */
function tmc_pc_read( $conn ) {
	$line = fgets( $conn );
	if ( false === $line || strlen( $line ) < 3 ) {
		$GLOBALS['tmc_pc_io_error'] = true;
		return false;
	}
	$type = $line[0];
	$data = substr( $line, 1, -2 );
	switch ( $type ) {
		case '+':
			return $data;
		case ':':
			return (int) $data;
		case '$':
			$size = (int) $data;
			if ( $size < 0 ) {
				return null;
			}
			$buffer = '';
			while ( strlen( $buffer ) < $size + 2 ) {
				$chunk = fread( $conn, min( 65536, $size + 2 - strlen( $buffer ) ) );
				if ( false === $chunk || '' === $chunk ) {
					$GLOBALS['tmc_pc_io_error'] = true;
					return false;
				}
				$buffer .= $chunk;
			}
			return substr( $buffer, 0, $size );
		case '*':
			$count = (int) $data;
			if ( $count < 0 ) {
				return null;
			}
			$items = array();
			for ( $i = 0; $i < $count; $i++ ) {
				$item = tmc_pc_read( $conn );
				if ( false === $item && ! empty( $GLOBALS['tmc_pc_io_error'] ) ) {
					return false;
				}
				$items[] = $item;
			}
			return $items;
		case '-': // error reply: the connection stays in sync
			return false;
		default:
			$GLOBALS['tmc_pc_io_error'] = true;
			return false;
	}
}

/* ------------------------------------------------------------------ generations and keys */

function tmc_pc_new_generation() {
	return dechex( time() ) . bin2hex( random_bytes( 6 ) );
}

function tmc_pc_generation_keys( $host ) {
	return array( TMC_PC_PREFIX . 'gen:network', TMC_PC_PREFIX . 'gen:host:' . $host );
}

/**
 * Current network + host generations, creating missing ones. Null when Redis is unavailable.
 *
 * @return string[]|null
 */
function tmc_pc_generations( $host ) {
	$keys   = tmc_pc_generation_keys( $host );
	$values = tmc_pc_cmd( array( 'MGET', $keys[0], $keys[1] ) );
	if ( ! is_array( $values ) || 2 !== count( $values ) ) {
		return null;
	}
	foreach ( $values as $i => $value ) {
		if ( is_string( $value ) && '' !== $value ) {
			continue;
		}
		$new = tmc_pc_new_generation();
		if ( 'OK' === tmc_pc_cmd( array( 'SET', $keys[ $i ], $new, 'NX' ) ) ) {
			$values[ $i ] = $new;
		} else {
			$value = tmc_pc_cmd( array( 'GET', $keys[ $i ] ) ); // another request created it first
			if ( ! is_string( $value ) || '' === $value ) {
				return null;
			}
			$values[ $i ] = $value;
		}
	}
	return $values;
}

function tmc_pc_page_key( array $generations, $url ) {
	return TMC_PC_PREFIX . 'page:' . sha1( $generations[0] . '|' . $generations[1] . '|' . $url );
}

/** Purge one site host (all its pages, every language). */
function tmc_pc_purge_host( $host ) {
	$host = tmc_pc_normalize_host( $host );
	if ( '' === $host ) {
		return false;
	}
	return 'OK' === tmc_pc_cmd( array( 'SET', TMC_PC_PREFIX . 'gen:host:' . $host, tmc_pc_new_generation() ) );
}

/** Purge every site of the network. */
function tmc_pc_purge_network() {
	return 'OK' === tmc_pc_cmd( array( 'SET', TMC_PC_PREFIX . 'gen:network', tmc_pc_new_generation() ) );
}

/* ------------------------------------------------------------------ entries */

/** Response headers worth replaying from the cache (everything the application set, minus per-response ones). */
function tmc_pc_storable_headers( array $headers ) {
	$skip = array( 'set-cookie', 'cache-control', 'expires', 'pragma', 'etag', 'last-modified', 'age', 'date', 'content-length', 'content-encoding', 'transfer-encoding', 'connection', 'x-powered-by', 'server', strtolower( TMC_PC_HEADER ), strtolower( TMC_PC_REASON_HEADER ) );
	$keep = array();
	foreach ( $headers as $line ) {
		$name = strtolower( trim( strtok( (string) $line, ':' ) ) );
		if ( '' !== $name && ! in_array( $name, $skip, true ) ) {
			$keep[] = (string) $line;
		}
	}
	return $keep;
}

function tmc_pc_encode( $body, array $headers ) {
	return serialize(
		array(
			'v' => TMC_PC_FORMAT,
			't' => time(),
			'e' => '"' . md5( $body ) . '"',
			'h' => tmc_pc_storable_headers( $headers ),
			'b' => gzdeflate( $body, 3 ),
		)
	);
}

/** @return array{t:int,e:string,h:string[],body:string}|null */
function tmc_pc_decode( $raw ) {
	$entry = @unserialize( (string) $raw, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.PHP.DiscouragedPHPFunctions -- our own data; objects refused
	if ( ! is_array( $entry ) || TMC_PC_FORMAT !== ( $entry['v'] ?? 0 ) || ! isset( $entry['t'], $entry['e'], $entry['h'], $entry['b'] ) || ! is_array( $entry['h'] ) ) {
		return null;
	}
	$body = @gzinflate( (string) $entry['b'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	if ( false === $body ) {
		return null;
	}
	$entry['body'] = $body;
	return $entry;
}

/* ------------------------------------------------------------------ response handling (web requests only) */

/** Label a response; the reason header is left out on production. */
function tmc_pc_label( $state, $reason = '' ) {
	if ( headers_sent() ) {
		return;
	}
	header( TMC_PC_HEADER . ': ' . $state );
	$production = function_exists( 'wp_get_environment_type' ) && 'production' === wp_get_environment_type();
	if ( '' !== $reason && ! $production ) {
		header( TMC_PC_REASON_HEADER . ': ' . $reason );
	} else {
		header_remove( TMC_PC_REASON_HEADER );
	}
}

/** Send a cached page (or 304 Not Modified) and return. */
function tmc_pc_serve( array $entry, $method ) {
	tmc_pc_label( 'HIT' );
	header( 'Age: ' . max( 0, time() - (int) $entry['t'] ) );
	header( 'ETag: ' . $entry['e'] );
	foreach ( $entry['h'] as $line ) {
		header( $line, false );
	}
	$match = array_map( 'trim', explode( ',', (string) ( $_SERVER['HTTP_IF_NONE_MATCH'] ?? '' ) ) );
	if ( in_array( $entry['e'], $match, true ) ) {
		http_response_code( 304 );
		return;
	}
	http_response_code( 200 );
	if ( 'HEAD' !== $method ) {
		echo $entry['body']; // phpcs:ignore WordPress.Security.EscapeOutput -- a complete page rendered (and escaped) by WordPress
	}
}

/**
 * Why the finished response must not be stored ('' = it may be stored).
 *
 * @param string $buffer  The complete page.
 * @param bool   $partial Part of the page was flushed early.
 */
function tmc_pc_response_bypass_reason( $buffer, $partial ) {
	if ( $partial ) {
		return 'flushed';
	}
	if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
		return 'donotcachepage';
	}
	if ( ! empty( $GLOBALS['tmc_page_cache_bypass'] ) ) {
		return (string) $GLOBALS['tmc_page_cache_bypass'];
	}
	if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
		return 'logged-in';
	}
	if ( ! function_exists( 'did_action' ) || ! did_action( 'template_redirect' ) ) {
		return 'not-a-page';
	}
	if ( 200 !== http_response_code() ) {
		return 'status';
	}
	$type = 'text/html';
	foreach ( headers_list() as $line ) {
		$name  = strtolower( trim( strtok( $line, ':' ) ) );
		$value = strtolower( trim( (string) substr( $line, strpos( $line, ':' ) + 1 ) ) );
		if ( 'set-cookie' === $name ) {
			return 'set-cookie';
		}
		if ( 'cache-control' === $name && preg_match( '/no-store|private/', $value ) ) {
			return 'no-store';
		}
		if ( 'content-type' === $name ) {
			$type = $value;
		}
	}
	if ( 0 !== strpos( $type, 'text/html' ) ) {
		return 'content-type';
	}
	if ( strlen( $buffer ) < 256 || false === stripos( $buffer, '</html>' ) ) {
		return 'incomplete';
	}
	return '';
}

/** Output-buffer handler started by engine.php: stores the finished page when every rule allows it. */
function tmc_pc_ob_callback( $buffer, $phase ) {
	static $partial = false;
	if ( ! ( $phase & PHP_OUTPUT_HANDLER_FINAL ) ) {
		if ( $phase & PHP_OUTPUT_HANDLER_FLUSH ) {
			$partial = true; // part of the page has been sent already: never store a fragment
		}
		return $buffer;
	}
	$state  = $GLOBALS['tmc_page_cache'] ?? null;
	$reason = is_array( $state ) ? tmc_pc_response_bypass_reason( $buffer, $partial ) : 'engine';
	if ( '' !== $reason ) {
		tmc_pc_label( 'BYPASS', $reason );
		return $buffer;
	}
	if ( empty( $state['store'] ) ) {
		tmc_pc_label( 'MISS', 'not-stored' );
		return $buffer;
	}
	$config = tmc_pc_config();
	$value  = tmc_pc_encode( $buffer, headers_list() );
	tmc_pc_cmd( array( 'SET', $state['key'], $value, 'EX', (string) (int) $config['ttl'] ) );
	if ( ! headers_sent() ) {
		header( 'ETag: "' . md5( $buffer ) . '"' );
	}
	return $buffer;
}
