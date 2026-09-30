<?php
/**
 * Text extraction from uploaded documents, so site search finds words inside them (tender §4.12).
 *
 * When a document is uploaded its text is extracted once and stored:
 *   {prefix}tmc_document_text  one row per attachment: plain UTF-8 text (capped at TMC_DOC_TEXT_MAX
 *                              characters), method, time. A table rather than post meta, so large texts
 *                              are never loaded into the meta cache whenever an attachment is used.
 *   _tmc_doc_text_method       attachment meta: pdftotext | basic | docx | xlsx | pptx | odf | text | none | failed | missing
 *   _tmc_doc_text_chars        attachment meta: number of characters extracted
 *
 * PDFs: pdftotext (poppler-utils, installed in the WordPress image) is used when it is available.
 * Otherwise (e.g. WP-CLI containers) a small built-in reader handles PDFs with simple text encoding
 * and records the method "basic"; Network Admin → Media & documents re-extracts those with pdftotext.
 * Scanned PDFs have no text layer: pdftotext returns nothing and the method stays "pdftotext" with
 * empty text (they need OCR before upload). Office Open XML / OpenDocument text is read from the
 * zip package. pdftotext runs without a shell (argument array), with a time limit and output cap.
 */

defined( 'ABSPATH' ) || exit;

const TMC_DOC_TEXT_MAX       = 300000;   // characters kept per document
const TMC_DOC_FILE_MAX       = 67108864; // 64 MB: larger files are not read
const TMC_DOC_EXTRACT_SECS   = 60;
const TMC_DOC_PDF_MAX_PAGES  = 500;
const TMC_DOC_TEXT_DB_VERSION = 1;

function tmc_document_text_table() {
	global $wpdb;
	return $wpdb->prefix . 'tmc_document_text';
}

/**
 * False while WordPress or a new site is being installed (no core tables yet): TMC tables, options
 * and terms are created on the first normal load instead.
 */
function tmc_site_installed() {
	return ! wp_installing() && is_blog_installed();
}

/** Create/upgrade the extracted-text table of the current site. */
function tmc_document_text_install() {
	if ( (int) get_option( 'tmc_doc_text_db_version' ) === TMC_DOC_TEXT_DB_VERSION || ! tmc_site_installed() ) {
		return;
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$table = tmc_document_text_table();
	dbDelta(
		"CREATE TABLE {$table} (
			post_id bigint(20) unsigned NOT NULL,
			method varchar(20) NOT NULL DEFAULT '',
			extracted_at datetime NOT NULL,
			content mediumtext NOT NULL,
			PRIMARY KEY  (post_id)
		) {$wpdb->get_charset_collate()};"
	);
	update_option( 'tmc_doc_text_db_version', TMC_DOC_TEXT_DB_VERSION );
}
add_action( 'init', 'tmc_document_text_install', 1 );

/** Extracted text of a document ('' if none). */
function tmc_document_get_text( $attachment_id ) {
	global $wpdb;
	if ( (int) get_option( 'tmc_doc_text_db_version' ) !== TMC_DOC_TEXT_DB_VERSION ) {
		return '';
	}
	$table = tmc_document_text_table();
	return (string) $wpdb->get_var( $wpdb->prepare( "SELECT content FROM {$table} WHERE post_id = %d", $attachment_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

add_action(
	'delete_attachment',
	function ( $attachment_id ) {
		global $wpdb;
		if ( (int) get_option( 'tmc_doc_text_db_version' ) === TMC_DOC_TEXT_DB_VERSION ) {
			$wpdb->delete( tmc_document_text_table(), array( 'post_id' => (int) $attachment_id ), array( '%d' ) );
		}
	}
);

/** File extension → MIME type of the files treated as documents (not images or media). */
function tmc_document_mime_types() {
	return array(
		'pdf'  => 'application/pdf',
		'doc'  => 'application/msword',
		'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'xls'  => 'application/vnd.ms-excel',
		'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'ppt'  => 'application/vnd.ms-powerpoint',
		'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
		'odt'  => 'application/vnd.oasis.opendocument.text',
		'ods'  => 'application/vnd.oasis.opendocument.spreadsheet',
		'odp'  => 'application/vnd.oasis.opendocument.presentation',
		'rtf'  => 'application/rtf',
		'txt'  => 'text/plain',
		'csv'  => 'text/csv',
	);
}

/** Whether an attachment (ID or post) is a document. */
function tmc_is_document( $attachment ) {
	$post = get_post( $attachment );
	return $post && 'attachment' === $post->post_type && in_array( $post->post_mime_type, tmc_document_mime_types(), true );
}

/* ---------------------------------------------------------------- extraction */

add_action( 'add_attachment', 'tmc_document_on_upload', 5 );
function tmc_document_on_upload( $attachment_id ) {
	if ( tmc_is_document( $attachment_id ) ) {
		tmc_document_extract( $attachment_id );
	}
}

/**
 * Extract and store the text of one document. Does not re-index it (callers that run outside
 * the upload flow call tmc_search_index_post() afterwards).
 *
 * @return string The method used (see file header), or "missing" when the file is not readable.
 */
function tmc_document_extract( $attachment_id ) {
	$file = get_attached_file( $attachment_id );
	if ( ! $file || ! is_readable( $file ) ) {
		update_post_meta( $attachment_id, '_tmc_doc_text_method', 'missing' );
		return 'missing';
	}
	tmc_document_text_install();
	if ( (int) get_option( 'tmc_doc_text_db_version' ) !== TMC_DOC_TEXT_DB_VERSION ) {
		return 'none'; // site not installed yet; migration 010 / "Extract text now" catch up
	}
	list( $text, $method ) = tmc_document_text_from_file( $file, (string) get_post_mime_type( $attachment_id ) );
	global $wpdb;
	$wpdb->replace(
		tmc_document_text_table(),
		array(
			'post_id'      => (int) $attachment_id,
			'method'       => $method,
			'extracted_at' => current_time( 'mysql', true ),
			'content'      => $text,
		),
		array( '%d', '%s', '%s', '%s' )
	);
	update_post_meta( $attachment_id, '_tmc_doc_text_method', $method );
	update_post_meta( $attachment_id, '_tmc_doc_text_chars', mb_strlen( $text ) );
	return $method;
}

/**
 * @return array{0:string,1:string} [ normalised text, method ]
 */
function tmc_document_text_from_file( $file, $mime ) {
	$size = @filesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	if ( ! $size || $size > TMC_DOC_FILE_MAX ) {
		return array( '', 'none' );
	}
	$types = array_flip( tmc_document_mime_types() );
	$ext   = $types[ $mime ] ?? strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );

	switch ( $ext ) {
		case 'pdf':
			$text = tmc_pdftotext( $file );
			if ( null !== $text ) {
				return array( tmc_document_normalise_text( $text ), 'pdftotext' );
			}
			$text = tmc_document_normalise_text( tmc_pdf_text_basic( $file ) );
			if ( '' !== $text ) {
				return array( $text, 'basic' );
			}
			// "failed": pdftotext could not read it either (damaged or encrypted) — not retried.
			return array( '', tmc_pdftotext_binary() ? 'failed' : 'none' );
		case 'docx':
			return tmc_document_zip_text( $file, '#^word/(document|header\d*|footer\d*|footnotes)\.xml$#', 'docx' );
		case 'xlsx':
			return tmc_document_zip_text( $file, '#^xl/sharedStrings\.xml$#', 'xlsx' );
		case 'pptx':
			return tmc_document_zip_text( $file, '#^ppt/slides/slide\d+\.xml$#', 'pptx' );
		case 'odt':
		case 'ods':
		case 'odp':
			return tmc_document_zip_text( $file, '#^content\.xml$#', 'odf' );
		case 'txt':
		case 'csv':
			$text = (string) file_get_contents( $file, false, null, 0, TMC_DOC_TEXT_MAX * 4 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return array( tmc_document_normalise_text( $text ), 'text' );
	}
	return array( '', 'none' ); // .doc / .xls / .ppt / .rtf: title, caption and description are still searchable
}

/** Valid UTF-8, no control characters, single spaces, capped length. */
function tmc_document_normalise_text( $text ) {
	$text = (string) $text;
	if ( '' === $text ) {
		return '';
	}
	if ( ! mb_check_encoding( $text, 'UTF-8' ) ) {
		$text = mb_convert_encoding( $text, 'UTF-8', 'UTF-8' ); // replaces invalid sequences
	}
	$text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', ' ', $text );
	$text = preg_replace( '/[ \t\r\n\x{00A0}\x{200B}]+/u', ' ', (string) $text );
	return mb_substr( trim( (string) $text ), 0, TMC_DOC_TEXT_MAX );
}

/* ---------------------------------------------------------------- pdftotext */

/** Path of an executable pdftotext, or '' (override with the TMC_PDFTOTEXT environment variable). */
function tmc_pdftotext_binary() {
	if ( ! function_exists( 'proc_open' ) ) {
		return '';
	}
	$candidates = array_filter( array( (string) getenv( 'TMC_PDFTOTEXT' ), '/usr/bin/pdftotext', '/usr/local/bin/pdftotext' ) );
	foreach ( $candidates as $binary ) {
		if ( @is_file( $binary ) && @is_executable( $binary ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- open_basedir
			return $binary;
		}
	}
	return '';
}

/**
 * Run pdftotext. No shell is involved: the command is an argument array.
 *
 * @return string|null Text ('' for a PDF without a text layer), or null if pdftotext is unavailable or failed.
 */
function tmc_pdftotext( $file ) {
	$binary = tmc_pdftotext_binary();
	if ( ! $binary ) {
		return null;
	}
	$command = array( $binary, '-q', '-enc', 'UTF-8', '-nopgbrk', '-l', (string) TMC_DOC_PDF_MAX_PAGES, $file, '-' );
	$pipes   = array();
	$process = @proc_open( $command, array( 0 => array( 'file', '/dev/null', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'file', '/dev/null', 'w' ) ), $pipes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	if ( ! is_resource( $process ) ) {
		return null;
	}
	stream_set_blocking( $pipes[1], false );
	$output    = '';
	$deadline  = microtime( true ) + TMC_DOC_EXTRACT_SECS;
	$max_bytes = TMC_DOC_TEXT_MAX * 4;
	$stopped   = false;
	while ( ! feof( $pipes[1] ) ) {
		$left = $deadline - microtime( true );
		if ( $left <= 0 || strlen( $output ) > $max_bytes ) {
			$stopped = true;
			break;
		}
		$read   = array( $pipes[1] );
		$write  = null;
		$except = null;
		$ready  = @stream_select( $read, $write, $except, (int) $left, 200000 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $ready ) {
			break;
		}
		if ( $ready > 0 ) {
			$chunk = fread( $pipes[1], 65536 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( false === $chunk ) {
				break;
			}
			$output .= $chunk;
		}
	}
	fclose( $pipes[1] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( $stopped ) {
		proc_terminate( $process );
	}
	$status = proc_close( $process );
	if ( '' !== $output ) {
		return $output; // complete, or truncated at the size cap
	}
	return ( 0 === $status && ! $stopped ) ? '' : null;
}

/* ---------------------------------------------------------------- built-in PDF reader */

/**
 * Text of PDFs whose fonts use simple (single-byte or UTF-16) string encoding — e.g. generated
 * notices and reports. Handles uncompressed and FlateDecode content streams; glyph-ID (CID) strings
 * that cannot be decoded without the font's CMap are skipped rather than stored as garbage.
 */
function tmc_pdf_text_basic( $file ) {
	$data = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( false === strpos( substr( $data, 0, 1024 ), '%PDF' ) ) {
		return '';
	}
	$text   = '';
	$offset = 0;
	while ( preg_match( '/\bstream\r?\n/', $data, $match, PREG_OFFSET_CAPTURE, $offset ) ) {
		$keyword = (int) $match[0][1];
		$start   = $keyword + strlen( $match[0][0] );
		$end     = strpos( $data, 'endstream', $start );
		if ( false === $end ) {
			break;
		}
		$offset = $end + 9;

		// The stream dictionary is the text between the object header and the "stream" keyword.
		$window = substr( $data, max( 0, $keyword - 4096 ), min( 4096, $keyword ) );
		$header = strrpos( $window, 'obj' );
		$dict   = false === $header ? $window : substr( $window, $header );
		if ( preg_match( '#/(Subtype\s*/Image|FontFile|Length1|Type\s*/XRef|Type\s*/ObjStm|Type\s*/Metadata|Type\s*/EmbeddedFile)#', $dict ) ) {
			continue;
		}
		$raw = rtrim( substr( $data, $start, $end - $start ), "\r\n" );
		if ( preg_match( '#/(DCTDecode|JPXDecode|JBIG2Decode|CCITTFaxDecode|LZWDecode|ASCII85Decode|ASCIIHexDecode|RunLengthDecode|Crypt)#', $dict ) ) {
			continue;
		}
		if ( preg_match( '#/(FlateDecode|Fl)\b#', $dict ) ) {
			$decoded = @gzuncompress( $raw, 16 * MB_IN_BYTES ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( false === $decoded ) {
				$decoded = @gzinflate( substr( $raw, 2 ), 16 * MB_IN_BYTES ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
			$raw = (string) $decoded;
		}
		if ( '' === $raw || ! preg_match( '/\bBT\b/', $raw ) ) {
			continue; // not a page content stream with text
		}
		$text .= tmc_pdf_content_text( $raw ) . "\n";
		if ( strlen( $text ) > TMC_DOC_TEXT_MAX * 4 ) {
			break;
		}
	}
	return $text;
}

/** Text shown by the text operators (Tj, TJ, ', ") of one content stream. */
function tmc_pdf_content_text( $stream ) {
	$length   = strlen( $stream );
	$i        = 0;
	$out      = '';
	$pending  = '';
	$in_array = false;
	$delims   = " \t\r\n\f\0/[]()<>{}%";
	while ( $i < $length ) {
		$char = $stream[ $i ];
		if ( '%' === $char ) {
			$i += strcspn( $stream, "\r\n", $i );
			continue;
		}
		if ( '(' === $char ) {
			$pending .= tmc_pdf_decode_string( tmc_pdf_read_literal( $stream, $i ) );
			continue;
		}
		if ( '<' === $char ) {
			if ( '<' === ( $stream[ $i + 1 ] ?? '' ) ) {
				$i += 2;
				continue;
			}
			$close = strpos( $stream, '>', $i );
			if ( false === $close ) {
				break;
			}
			$hex = preg_replace( '/[^0-9A-Fa-f]/', '', substr( $stream, $i + 1, $close - $i - 1 ) );
			if ( strlen( $hex ) % 2 ) {
				$hex .= '0';
			}
			$pending .= tmc_pdf_decode_string( (string) hex2bin( $hex ) );
			$i        = $close + 1;
			continue;
		}
		if ( '[' === $char || ']' === $char ) {
			$in_array = '[' === $char;
			++$i;
			continue;
		}
		if ( false !== strpos( " \t\r\n\f\0>{}", $char ) ) {
			++$i;
			continue;
		}
		if ( '-' === $char || '+' === $char || '.' === $char || ctype_digit( $char ) ) {
			$n = strspn( $stream, '+-.0123456789', $i );
			if ( $in_array && (float) substr( $stream, $i, $n ) < -180 ) {
				$pending .= ' '; // a large kerning gap inside TJ is a word space
			}
			$i += max( 1, $n );
			continue;
		}
		if ( '/' === $char ) {
			++$i;
			$i += strcspn( $stream, $delims, $i );
			continue;
		}
		$n = strcspn( $stream, $delims, $i );
		if ( 0 === $n ) {
			++$i;
			continue;
		}
		$operator = substr( $stream, $i, $n );
		$i       += $n;
		switch ( $operator ) {
			case 'Tj':
			case 'TJ':
				$out .= $pending;
				break;
			case "'":
			case '"':
				$out .= "\n" . $pending;
				break;
			case 'T*':
			case 'Td':
			case 'TD':
			case 'Tm':
			case 'ET':
				$out .= "\n";
				break;
			case 'ID': // inline image data: skip to EI
				$end = strpos( $stream, 'EI', $i );
				$i   = false === $end ? $length : $end + 2;
				break;
		}
		$pending = '';
	}
	return $out;
}

/** Read a PDF literal string starting at "(" and advance $i past its closing ")". */
function tmc_pdf_read_literal( $stream, &$i ) {
	$length = strlen( $stream );
	$depth  = 0;
	$out    = '';
	$escape = array( 'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\f", '(' => '(', ')' => ')', '\\' => '\\' );
	++$i;
	while ( $i < $length ) {
		$char = $stream[ $i ];
		if ( '\\' === $char ) {
			$next = $stream[ $i + 1 ] ?? '';
			if ( isset( $escape[ $next ] ) ) {
				$out .= $escape[ $next ];
				$i   += 2;
				continue;
			}
			$octal = '';
			$j     = $i + 1;
			while ( strlen( $octal ) < 3 && isset( $stream[ $j ] ) && $stream[ $j ] >= '0' && $stream[ $j ] <= '7' ) {
				$octal .= $stream[ $j ];
				++$j;
			}
			if ( '' !== $octal ) {
				$out .= chr( octdec( $octal ) & 0xFF );
				$i    = $j;
				continue;
			}
			if ( "\r" === $next || "\n" === $next ) { // line continuation
				$i += ( "\r" === $next && "\n" === ( $stream[ $i + 2 ] ?? '' ) ) ? 3 : 2;
				continue;
			}
			++$i;
			continue;
		}
		if ( '(' === $char ) {
			++$depth;
		} elseif ( ')' === $char ) {
			if ( 0 === $depth ) {
				++$i;
				return $out;
			}
			--$depth;
		}
		$out .= $char;
		++$i;
	}
	return $out;
}

/** PDF string bytes → UTF-8: UTF-16BE (with BOM) or single-byte WinAnsi. Glyph-ID strings → ''. */
function tmc_pdf_decode_string( $bytes ) {
	if ( '' === $bytes ) {
		return '';
	}
	if ( 0 === strncmp( $bytes, "\xFE\xFF", 2 ) ) {
		return (string) mb_convert_encoding( substr( $bytes, 2 ), 'UTF-8', 'UTF-16BE' );
	}
	$printable = preg_match_all( '/[\x20-\x7E\x80-\xFF\t\r\n]/', $bytes );
	if ( $printable < 0.85 * strlen( $bytes ) ) {
		return '';
	}
	return (string) mb_convert_encoding( $bytes, 'UTF-8', 'Windows-1252' );
}

/* ---------------------------------------------------------------- office documents */

/**
 * Text of the XML parts of an Office Open XML / OpenDocument package whose names match $pattern.
 *
 * @return array{0:string,1:string}
 */
function tmc_document_zip_text( $file, $pattern, $method ) {
	if ( ! class_exists( 'ZipArchive' ) ) {
		return array( '', 'none' );
	}
	$zip = new ZipArchive();
	if ( true !== $zip->open( $file, ZipArchive::RDONLY ) ) {
		return array( '', 'none' );
	}
	$names = array();
	for ( $index = 0; $index < $zip->numFiles; $index++ ) {
		$stat = $zip->statIndex( $index );
		if ( $stat && preg_match( $pattern, $stat['name'] ) && $stat['size'] < 32 * MB_IN_BYTES ) { // zip-bomb guard
			$names[] = $stat['name'];
		}
	}
	natsort( $names );
	$text = '';
	foreach ( $names as $name ) {
		$xml = (string) $zip->getFromName( $name );
		// Paragraph, cell and tab boundaries become spaces so words do not run together.
		$xml   = preg_replace( '#<(/w:p|w:tab|w:br|/a:p|/text:p|/text:h|text:tab|text:s|/si|/c|/table:table-cell)\b[^>]*>#', ' ', $xml );
		$text .= html_entity_decode( wp_strip_all_tags( (string) $xml ), ENT_QUOTES | ENT_XML1, 'UTF-8' ) . ' ';
		if ( strlen( $text ) > TMC_DOC_TEXT_MAX * 4 ) {
			break;
		}
	}
	$zip->close();
	$text = tmc_document_normalise_text( $text );
	return array( $text, '' === $text ? 'none' : $method );
}
