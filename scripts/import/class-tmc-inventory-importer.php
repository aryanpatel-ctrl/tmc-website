<?php
/**
 * Content inventory importer (tender §4.11 content migration) — the library behind
 * scripts/import/import-inventory.php. Documentation: docs/migration/importer.md.
 *
 * Reads a CSV content inventory (one row per page / item per language) and, for the rows of the
 * current site:
 *   - creates or updates the content idempotently (a row is recognised again by its old URL, or by
 *     type + language + path), mapped to its content type, page template and parent page;
 *   - fills the structured fields of the TMC content types ("field:tmc_closing_at" columns), the SEO
 *     fields and categories, and attaches document files to the media library (once per file);
 *   - links English and Hindi versions that share a translation_key;
 *   - creates a 301 redirect from every old URL (and old document URL) to the new address, and
 *     rewrites links between migrated pages to their new addresses;
 *   - never overwrites a page an editor has changed since the last import, or a page with real
 *     content that the importer did not create (seeded placeholder pages are filled), unless forced;
 *   - in dry-run mode (the default) changes nothing and reports what would happen;
 *   - returns a per-row result that the CLI writes as a CSV report for the migration sign-off.
 */

if ( class_exists( 'TMC_Inventory_Importer' ) ) {
	return;
}

class TMC_Inventory_Importer {

	const REQUIRED       = array( 'site', 'language', 'type', 'title' );
	const COLUMNS        = array( 'site', 'language', 'old_url', 'type', 'template', 'parent_path', 'slug', 'title', 'date', 'status', 'excerpt', 'content_html_file', 'content_html', 'documents', 'categories', 'translation_key', 'order', 'seo_title', 'seo_description', 'seo_noindex', 'sample' );
	const REPORT_COLUMNS = array( 'row', 'site', 'language', 'old_url', 'type', 'title', 'action', 'post_id', 'new_url', 'redirect', 'translation', 'messages' );
	const STATUSES       = array( 'publish', 'draft', 'pending', 'private' );
	const PLACEHOLDER    = '<p class="callout">'; // seeded placeholder pages (seed-site-structure.php) carry this callout

	/** @var string */
	private $csv;
	/** @var string */
	private $base_dir;
	/** @var bool */
	private $dry_run = true;
	/** @var bool */
	private $force = false;
	/** @var int */
	private $author = 0;
	/** @var string */
	private $site_key;
	/** @var string */
	private $default_lang = 'en';
	/** @var string[] */
	private $header = array();
	/** @var array<int,array<string,string>> */
	private $rows = array();
	/** @var array<int,array> */
	private $results = array();
	/** @var array<string,bool> page paths that exist or will exist ("lang|path") */
	private $planned = array();
	/** @var array<int,int> row => post ID created/updated in this run */
	private $touched = array();
	/** @var array<string,array<string,int|string>> translation_key => [ lang => post ID or row marker ] */
	private $groups = array();
	/** @var array<string,string> old match key => new site-relative URL */
	private $url_map = array();
	/** @var string[] hosts of old URLs (links to them are rewritten) */
	private $old_hosts = array();
	/** @var array<string,int> */
	private $counts = array();
	/** @var string[] problems with the file as a whole (e.g. unknown columns) */
	private $warnings = array();
	/** @var array<string,int> "type|lang|path" => row that claimed the address in this run */
	private $claimed = array();

	/**
	 * @param array $options csv (path, required), mode (dry-run|apply), base (directory of content and
	 *                       document files; default: the CSV's directory), author (login), force (bool).
	 */
	public function __construct( array $options ) {
		$this->csv      = (string) ( $options['csv'] ?? '' );
		$this->dry_run  = 'apply' !== strtolower( (string) ( $options['mode'] ?? 'dry-run' ) );
		$this->force    = in_array( strtolower( (string) ( $options['force'] ?? '' ) ), array( '1', 'yes', 'true' ), true );
		$base           = (string) ( $options['base'] ?? '' );
		$this->base_dir = rtrim( '' !== $base ? $base : dirname( $this->csv ), '/' );
		$login          = (string) ( $options['author'] ?? getenv( 'WP_ADMIN_USER' ) );
		$user           = $login ? get_user_by( 'login', $login ) : false;
		$this->author   = $user ? (int) $user->ID : 0;
		$this->site_key = self::site_key();
		if ( function_exists( 'pll_default_language' ) && pll_default_language() ) {
			$this->default_lang = pll_default_language();
		}
	}

	/** Short name of the current site as used in the inventory's "site" column ("tmc", "tmh", …). */
	public static function site_key() {
		if ( is_main_site() ) {
			return 'tmc';
		}
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		return (string) strstr( $host, '.', true );
	}

	public function is_dry_run() {
		return $this->dry_run;
	}

	/**
	 * Run the import.
	 *
	 * @return array<int,array>|WP_Error Report rows (see REPORT_COLUMNS).
	 */
	public function run() {
		if ( ! function_exists( 'tmc_redirect_save' ) ) {
			return new WP_Error( 'tmc_import', 'The tmc-core redirect module is not loaded.' );
		}
		$read = $this->read_csv();
		if ( is_wp_error( $read ) ) {
			return $read;
		}
		$this->load_previous_imports();

		// Parents before children: pages are processed by depth of their parent path.
		$lines = array_keys( $this->rows );
		usort( $lines, fn( $a, $b ) => $this->depth( $this->rows[ $a ] ) <=> $this->depth( $this->rows[ $b ] ) ?: $a <=> $b );
		foreach ( $lines as $line ) {
			$this->results[ $line ] = $this->process_row( $line, $this->rows[ $line ] );
		}
		$this->link_translations();
		$this->rewrite_links();
		if ( ! $this->dry_run ) {
			foreach ( array_unique( $this->touched ) as $post_id ) {
				update_post_meta( $post_id, '_tmc_import_at', time() );
			}
		}
		ksort( $this->results );
		foreach ( $this->results as $result ) {
			$this->counts[ $result['action'] ] = ( $this->counts[ $result['action'] ] ?? 0 ) + 1;
		}
		if ( function_exists( 'tmc_audit' ) ) {
			tmc_audit(
				$this->dry_run ? 'content_import_dry_run' : 'content_imported',
				array(
					'object_type'  => 'import',
					'object_title' => basename( $this->csv ),
					'details'      => array( 'sha256' => hash_file( 'sha256', $this->csv ), 'rows' => count( $this->rows ), 'results' => $this->counts ),
				)
			);
		}
		return array_values( $this->results );
	}

	/** Problems with the inventory as a whole (unknown columns). */
	public function warnings() {
		return $this->warnings;
	}

	/** Number of rows per action ("created", "would create", "error", …). */
	public function counts() {
		return $this->counts;
	}

	/** Write the report as CSV (formula-looking cells neutralised). */
	public function write_report( $path ) {
		$out = fopen( $path, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $out ) {
			return false;
		}
		$safe = static fn( $v ) => preg_match( '/^[=+\-@\t\r]/', (string) $v ) ? "'" . $v : $v;
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- UTF-8 BOM so spreadsheet programs show Hindi correctly
		fputcsv( $out, self::REPORT_COLUMNS, ',', '"', '' );
		foreach ( $this->results as $row ) {
			$cells = array();
			foreach ( self::REPORT_COLUMNS as $column ) {
				$value   = $row[ $column ] ?? '';
				$cells[] = $safe( is_array( $value ) ? implode( ' | ', $value ) : (string) $value );
			}
			fputcsv( $out, $cells, ',', '"', '' );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return true;
	}

	/* ================================================================ reading */

	private function read_csv() {
		if ( '' === $this->csv || ! is_readable( $this->csv ) ) {
			return new WP_Error( 'tmc_import', 'Inventory file not found or not readable: ' . $this->csv );
		}
		$handle = fopen( $this->csv, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$header = fgetcsv( $handle, 0, ',', '"', '' );
		if ( ! $header ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return new WP_Error( 'tmc_import', 'The inventory is empty.' );
		}
		$this->header = array_map( fn( $h ) => strtolower( trim( preg_replace( '/^\xEF\xBB\xBF/', '', (string) $h ) ) ), $header );
		$missing      = array_diff( self::REQUIRED, $this->header );
		if ( $missing ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return new WP_Error( 'tmc_import', 'Required columns missing: ' . implode( ', ', $missing ) );
		}
		$unknown = array_filter( $this->header, fn( $column ) => '' !== $column && ! in_array( $column, self::COLUMNS, true ) && ! str_starts_with( $column, 'field:' ) );
		if ( $unknown ) {
			$this->warnings[] = 'Unknown columns ignored (check the spelling): ' . implode( ', ', $unknown );
		}
		$row_number = 1;
		while ( ( $cells = fgetcsv( $handle, 0, ',', '"', '' ) ) !== false ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
			++$row_number;
			if ( array( null ) === $cells || '' === trim( implode( '', $cells ) ) ) {
				continue;
			}
			$row = array();
			foreach ( $this->header as $i => $column ) {
				if ( '' !== $column ) {
					$row[ $column ] = trim( (string) ( $cells[ $i ] ?? '' ) );
				}
			}
			$this->rows[ $row_number ] = $row;
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return true;
	}

	private function has_column( $column ) {
		return in_array( $column, $this->header, true );
	}

	private function depth( array $row ) {
		if ( 'page' !== strtolower( $row['type'] ?? '' ) ) {
			return 0;
		}
		$parent = trim( (string) ( $row['parent_path'] ?? '' ), '/' );
		return '' === $parent ? 1 : 2 + substr_count( $parent, '/' );
	}

	/** Old URL → new URL of earlier import runs, so links across batches are rewritten too. */
	private function load_previous_imports() {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT m.post_id, m.meta_value FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = '_tmc_import_old_url' AND p.post_status NOT IN ('trash','auto-draft')" );
		foreach ( $rows as $row ) {
			$key = $this->old_key( $row->meta_value );
			if ( $key ) {
				$this->url_map[ $key ] = $this->relative_url( (int) $row->post_id );
			}
		}
	}

	/* ================================================================ one row */

	private function process_row( $line, array $row ) {
		$lang   = strtolower( $row['language'] ?? '' );
		$type   = strtolower( $row['type'] ?? '' );
		$result = array(
			'row'         => $line,
			'site'        => $row['site'] ?? '',
			'language'    => $lang,
			'old_url'     => $row['old_url'] ?? '',
			'type'        => $type,
			'title'       => $row['title'] ?? '',
			'action'      => '',
			'post_id'     => '',
			'new_url'     => '',
			'redirect'    => '',
			'translation' => '',
			'messages'    => array(),
		);
		$fail   = function ( $message ) use ( &$result ) {
			$result['action']     = 'error';
			$result['messages'][] = $message;
			return $result;
		};

		$site = strtolower( $row['site'] ?? '' );
		if ( $site !== $this->site_key && $site !== strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
			$result['action'] = 'skipped (other site)';
			return $result;
		}

		// ---- validation
		$languages = function_exists( 'pll_languages_list' ) ? (array) pll_languages_list() : array( 'en' );
		if ( ! in_array( $lang, $languages, true ) ) {
			return $fail( sprintf( 'Unknown language "%s" (available: %s).', $lang, implode( ', ', $languages ) ) );
		}
		$types = array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) );
		if ( ! in_array( $type, $types, true ) ) {
			return $fail( sprintf( 'Unknown content type "%s" (available: %s).', $type, implode( ', ', $types ) ) );
		}
		$title = sanitize_text_field( $row['title'] ?? '' );
		if ( '' === $title ) {
			return $fail( 'Title is empty.' );
		}
		$status = strtolower( $row['status'] ?? '' );
		$status = '' === $status ? 'publish' : $status;
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return $fail( sprintf( 'Unknown status "%s" (publish, draft, pending or private).', $status ) );
		}
		$date = '';
		if ( '' !== ( $row['date'] ?? '' ) ) {
			$date = self::parse_date( $row['date'] );
			if ( ! $date ) {
				return $fail( sprintf( 'Date "%s" not understood (use YYYY-MM-DD, YYYY-MM-DD HH:MM or DD/MM/YYYY).', $row['date'] ) );
			}
		}
		$slug_raw = $row['slug'] ?? '';
		$slug     = sanitize_title( '' !== $slug_raw ? $slug_raw : $title );
		if ( '' === $slug ) {
			return $fail( 'Slug is empty.' );
		}
		if ( '' !== $slug_raw && $slug !== $slug_raw ) {
			$result['messages'][] = sprintf( 'Slug "%s" normalised to "%s".', $slug_raw, $slug );
		}
		if ( preg_match( '/%[0-9a-f]{2}/i', $slug ) ) {
			$result['messages'][] = 'The slug is not in Latin letters; give a transliterated slug for readable URLs.';
		}

		$object       = get_post_type_object( $type );
		$hierarchical = $object && $object->hierarchical;
		$parent_path  = trim( (string) ( $row['parent_path'] ?? '' ), '/' );
		$parent_id    = 0;
		if ( '' !== $parent_path ) {
			if ( ! $hierarchical ) {
				$result['messages'][] = 'parent_path ignored: this content type has no parent pages.';
				$parent_path          = '';
			} else {
				$parent    = $this->find_by_path( $type, $lang, $parent_path );
				$parent_id = $parent ? $parent->ID : 0;
				if ( ! $parent && ! isset( $this->planned[ "$lang|$parent_path" ] ) ) {
					return $fail( sprintf( 'Parent page "/%s/" not found in language "%s" (add the parent to the inventory or create it first).', $parent_path, $lang ) );
				}
			}
		}
		$path = trim( ( '' !== $parent_path ? $parent_path . '/' : '' ) . $slug, '/' );
		if ( isset( $this->claimed[ "$type|$lang|$path" ] ) ) {
			return $fail( sprintf( 'Same address as row %d (/%s/ in "%s"); give one of them another slug or parent.', $this->claimed[ "$type|$lang|$path" ], $path, $lang ) );
		}
		$this->claimed[ "$type|$lang|$path" ] = $line;

		$template = $this->resolve_template( $type, $row['template'] ?? '', $result['messages'] );

		$content = $this->content_html( $row, $result['messages'] );
		if ( is_wp_error( $content ) ) {
			return $fail( $content->get_error_message() );
		}
		$documents = $this->parse_documents( $row['documents'] ?? '' );
		if ( is_wp_error( $documents ) ) {
			return $fail( $documents->get_error_message() );
		}
		$fields = $this->collect_fields( $type, $lang, $row, $result['messages'] );
		if ( is_wp_error( $fields ) ) {
			return $fail( $fields->get_error_message() );
		}
		$old_key = '' !== $result['old_url'] ? $this->old_key( $result['old_url'] ) : '';
		if ( '' !== $result['old_url'] && ! $old_key ) {
			return $fail( sprintf( 'old_url "%s" is not a valid address.', $result['old_url'] ) );
		}

		// ---- existing content?
		$import_key = sha1( implode( '|', array( $this->site_key, $lang, $old_key ? 'url:' . $old_key : "path:$type:$path" ) ) );
		$existing   = $this->find_by_import_key( $import_key );
		$adopted    = '';
		if ( ! $existing ) {
			$existing = $this->find_by_path( $type, $lang, $path );
			if ( $existing ) {
				$placeholder = false !== strpos( $existing->post_content, self::PLACEHOLDER ) || '' === trim( $existing->post_content );
				$ours        = '' !== (string) get_post_meta( $existing->ID, '_tmc_import_key', true ); // imported earlier (e.g. from a row whose old URL was corrected)
				if ( ! $placeholder && ! $ours && ! $this->force ) {
					$result['action']     = 'skipped (exists)';
					$result['post_id']    = $existing->ID;
					$result['new_url']    = $this->relative_url( $existing->ID );
					$result['messages'][] = 'Content already exists at this address and was not created by the importer; use force=1 to overwrite it.';
					$this->register_planned( $type, $lang, $path );
					return $result;
				}
				$adopted = $ours ? 'matches the item imported earlier at this address' : ( $placeholder ? 'fills the placeholder page at this address' : 'overwrites the existing page at this address (force=1)' );
			}
		}

		$hash = sha1(
			wp_json_encode(
				array(
					$row,
					sha1( $content ),
					array_map( fn( $doc ) => $doc['sha1'], $documents ),
					$parent_id,
				)
			)
		);
		if ( $existing && ! $this->force ) {
			if ( get_post_meta( $existing->ID, '_tmc_import_hash', true ) === $hash ) {
				$result['action']  = 'unchanged';
				$result['post_id'] = $existing->ID;
				$result['new_url'] = $this->relative_url( $existing->ID );
				$this->after_row( $line, $row, $result, $existing->ID, $type, $lang, $path, $old_key, $documents );
				return $result;
			}
			$imported_at = (int) get_post_meta( $existing->ID, '_tmc_import_at', true );
			if ( $imported_at && strtotime( $existing->post_modified_gmt . ' UTC' ) > $imported_at + 5 ) {
				$result['action']     = 'skipped (edited in CMS)';
				$result['post_id']    = $existing->ID;
				$result['new_url']    = $this->relative_url( $existing->ID );
				$result['messages'][] = 'An editor changed this item after the last import; use force=1 to overwrite the changes.';
				$this->register_planned( $type, $lang, $path );
				return $result;
			}
		}

		// ---- dry run: report only
		if ( $this->dry_run ) {
			$result['action']  = $existing ? 'would update' : 'would create';
			$result['post_id'] = $existing ? $existing->ID : '';
			$result['new_url'] = $this->predict_url( $type, $lang, $path );
			if ( $adopted ) {
				$result['messages'][] = 'Would update: ' . $adopted . '.';
			}
			foreach ( $documents as $doc ) {
				$result['messages'][] = ( $this->find_attachment( $doc['sha1'] ) ? 'Document already in media library: ' : 'Would add document: ' ) . basename( $doc['file'] );
			}
			$this->register_planned( $type, $lang, $path );
			$this->after_row( $line, $row, $result, 0, $type, $lang, $path, $old_key, $documents );
			return $result;
		}

		// ---- documents first (their URLs go into the content)
		$attachment_ids = array();
		foreach ( $documents as $doc ) {
			$attachment = $this->import_document( $doc );
			if ( is_wp_error( $attachment ) ) {
				return $fail( $attachment->get_error_message() );
			}
			$attachment_ids[] = $attachment;
		}
		$has_document_field = isset( tmc_field_schema()[ $type ]['tmc_documents'] );
		if ( $attachment_ids && ! $has_document_field ) {
			$content .= $this->documents_block( $attachment_ids );
		}

		// ---- create / update
		$postarr = array(
			'post_type'    => $type,
			'post_title'   => $title,
			'post_name'    => $slug,
			'post_content' => $content,
			'post_status'  => $status,
			'post_parent'  => $parent_id,
		);
		// Optional columns change the item only when they are part of the inventory.
		if ( $this->has_column( 'excerpt' ) ) {
			$postarr['post_excerpt'] = sanitize_textarea_field( $row['excerpt'] );
		}
		if ( $this->has_column( 'order' ) && '' !== $row['order'] ) {
			$postarr['menu_order'] = (int) $row['order'];
		}
		if ( $template ) {
			$postarr['page_template'] = $template;
		}
		if ( $date ) {
			$postarr['post_date'] = $date;
			$postarr['edit_date'] = true;
		}
		if ( $existing ) {
			$postarr['ID'] = $existing->ID;
			$post_id       = wp_update_post( wp_slash( $postarr ), true );
		} else {
			$postarr['post_author'] = $this->author;
			$post_id                = wp_insert_post( wp_slash( $postarr ), true );
		}
		if ( is_wp_error( $post_id ) ) {
			return $fail( 'Could not save: ' . $post_id->get_error_message() );
		}
		if ( function_exists( 'pll_set_post_language' ) ) {
			pll_set_post_language( $post_id, $lang );
		}
		$this->save_fields( $post_id, $type, $fields );
		if ( $has_document_field && $this->has_column( 'documents' ) ) {
			$attachment_ids ? update_post_meta( $post_id, '_tmc_documents', $attachment_ids ) : delete_post_meta( $post_id, '_tmc_documents' );
		}
		$this->save_seo( $post_id, $row );
		if ( 'post' === $type && $this->has_column( 'categories' ) ) {
			$this->save_categories( $post_id, $lang, $row['categories'] ?? '', $result['messages'] );
		}
		if ( $this->has_column( 'sample' ) ) {
			// Demonstration rows: excluded from sitemaps / structured data, removable in one go.
			in_array( strtolower( $row['sample'] ), array( '1', 'yes', 'true' ), true ) ? update_post_meta( $post_id, '_tmc_sample', 1 ) : delete_post_meta( $post_id, '_tmc_sample' );
		}
		update_post_meta( $post_id, '_tmc_import_key', $import_key );
		update_post_meta( $post_id, '_tmc_import_hash', $hash );
		update_post_meta( $post_id, '_tmc_import_source', basename( $this->csv ) . ':' . $line );
		'' !== $result['old_url'] ? update_post_meta( $post_id, '_tmc_import_old_url', $result['old_url'] ) : delete_post_meta( $post_id, '_tmc_import_old_url' );

		$this->touched[ $line ] = $post_id;
		$result['action']       = $existing ? 'updated' : 'created';
		$result['post_id']      = $post_id;
		$result['new_url']      = $this->relative_url( $post_id );
		if ( $adopted ) {
			$result['messages'][] = 'Updated: ' . $adopted . '.';
		}
		if ( 'publish' !== $status ) {
			$result['messages'][] = sprintf( 'Saved as %s: the new address works once the item is published.', $status );
		}
		$this->register_planned( $type, $lang, $path );
		$this->after_row( $line, $row, $result, $post_id, $type, $lang, $path, $old_key, $documents, $attachment_ids );
		return $result;
	}

	/** Translation group, URL map and redirects for a processed row. */
	private function after_row( $line, array $row, array &$result, $post_id, $type, $lang, $path, $old_key, array $documents, array $attachment_ids = array() ) {
		$key = trim( (string) ( $row['translation_key'] ?? '' ) );
		if ( '' !== $key ) {
			$this->groups[ $key ][ $lang ] = $post_id ? (int) $post_id : 'row:' . $line;
		}
		$new_url = $result['new_url'];
		if ( $old_key ) {
			$this->url_map[ $old_key ] = $new_url;
			$result['redirect']        = $this->redirect( $result['old_url'], $new_url, $result['messages'] );
		}
		// Old document addresses → the files' new addresses.
		foreach ( $documents as $i => $doc ) {
			if ( '' === $doc['old_url'] ) {
				continue;
			}
			$doc_url = isset( $attachment_ids[ $i ] ) ? wp_make_link_relative( (string) wp_get_attachment_url( $attachment_ids[ $i ] ) ) : '';
			if ( ! $doc_url && ( $existing = $this->find_attachment( $doc['sha1'] ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition
				$doc_url = wp_make_link_relative( (string) wp_get_attachment_url( $existing ) );
			}
			$doc_key = $this->old_key( $doc['old_url'] );
			if ( ! $doc_key ) {
				$result['messages'][] = sprintf( 'Document old URL "%s" is not valid.', $doc['old_url'] );
				continue;
			}
			if ( $doc_url ) {
				$this->url_map[ $doc_key ] = $doc_url;
			}
			$outcome              = $doc_url ? $this->redirect( $doc['old_url'], $doc_url, $result['messages'] ) : 'would create';
			$result['messages'][] = sprintf( 'Document redirect %s: %s', $doc['old_url'], $outcome );
		}
	}

	/* ================================================================ redirects */

	/** Create/update the 301 old → new; returns the outcome for the report. */
	private function redirect( $old_url, $new_url, array &$messages ) {
		$old = tmc_redirect_split( $old_url );
		if ( is_wp_error( $old ) ) {
			return 'error: ' . $old->get_error_message();
		}
		$old_key = tmc_redirect_key( $old['path'], $old['query'] );
		if ( '' === $new_url || '(assigned on import)' === $new_url ) {
			return $this->dry_run ? 'would create' : 'error: new address unknown';
		}
		if ( tmc_redirect_target_key( $new_url ) === $old_key ) {
			return 'not needed (same address)';
		}
		global $wpdb;
		$table    = tmc_redirects_table();
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE source_key = %s", sha1( $old_key ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $existing && 301 === (int) $existing->status && $existing->target === $new_url ) {
			return 'exists';
		}
		if ( $this->dry_run ) {
			return $existing ? 'would update' : 'would create';
		}
		$saved = tmc_redirect_save(
			array(
				'source' => $old_url,
				'target' => $new_url,
				'status' => 301,
				'note'   => 'Content migration: ' . basename( $this->csv ),
			),
			$existing ? (int) $existing->id : 0,
			false
		);
		if ( is_wp_error( $saved ) ) {
			return 'error: ' . $saved->get_error_message();
		}
		foreach ( $saved['warnings'] as $warning ) {
			$messages[] = $warning;
		}
		return $existing ? 'updated' : 'created';
	}

	/** Match key of an old URL ('' if invalid); remembers its host for link rewriting. */
	private function old_key( $url ) {
		$parts = tmc_redirect_split( $url );
		if ( is_wp_error( $parts ) ) {
			return '';
		}
		$host = wp_parse_url( trim( (string) $url ), PHP_URL_HOST );
		if ( $host ) {
			$this->old_hosts[ strtolower( $host ) ] = true;
		}
		return tmc_redirect_key( $parts['path'], $parts['query'] );
	}

	/* ================================================================ translations and links */

	private function link_translations() {
		foreach ( $this->groups as $key => $members ) {
			$lines = array();
			foreach ( $this->results as $line => $result ) {
				if ( trim( (string) ( $this->rows[ $line ]['translation_key'] ?? '' ) ) === $key && 'error' !== $result['action'] && ! str_starts_with( $result['action'], 'skipped (other' ) ) {
					$lines[] = $line;
				}
			}
			if ( count( $members ) < 2 ) {
				foreach ( $lines as $line ) {
					$this->results[ $line ]['translation'] = 'no other language in the inventory';
				}
				continue;
			}
			$label = implode( ' + ', array_keys( $members ) );
			if ( $this->dry_run || ! function_exists( 'pll_save_post_translations' ) ) {
				foreach ( $lines as $line ) {
					$this->results[ $line ]['translation'] = $this->dry_run ? "would link ($label)" : 'not linked (Polylang inactive)';
				}
				continue;
			}
			$ids      = array_map( 'intval', $members );
			$existing = function_exists( 'pll_get_post_translations' ) ? (array) pll_get_post_translations( reset( $ids ) ) : array();
			pll_save_post_translations( array_merge( $existing, $ids ) );
			foreach ( $lines as $line ) {
				$this->results[ $line ]['translation'] = "linked ($label)";
			}
		}
	}

	/** Links in migrated content that point at old URLs are changed to the new addresses. */
	private function rewrite_links() {
		if ( ! $this->url_map ) {
			return;
		}
		foreach ( $this->results as $line => $result ) {
			$post_id = $this->touched[ $line ] ?? 0;
			if ( ! $post_id && ( ! $this->dry_run || ! in_array( $result['action'], array( 'would create', 'would update' ), true ) ) ) {
				continue;
			}
			$ignored = array();
			$html    = $post_id ? (string) get_post_field( 'post_content', $post_id ) : $this->content_html( $this->rows[ $line ], $ignored );
			if ( is_wp_error( $html ) ) {
				continue;
			}
			$count = 0;
			$new   = $this->replace_links( $html, $count );
			if ( ! $count ) {
				continue;
			}
			if ( $post_id ) {
				wp_update_post( wp_slash( array( 'ID' => $post_id, 'post_content' => $new ) ) );
			}
			$this->results[ $line ]['messages'][] = sprintf( '%d link(s) to old addresses %s.', $count, $post_id ? 'updated' : 'would be updated' );
		}
	}

	/** Replace href/src values that match an old URL. */
	public function replace_links( $html, &$count = 0 ) {
		$site_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		return preg_replace_callback(
			'/\b(href|src)=(["\'])(.*?)\2/i',
			function ( $m ) use ( &$count, $site_host ) {
				$url = html_entity_decode( $m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				if ( preg_match( '~^https?://~i', $url ) ) {
					$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
					if ( $host !== $site_host && ! isset( $this->old_hosts[ $host ] ) ) {
						return $m[0];
					}
				} elseif ( '' === $url || '/' !== $url[0] || str_starts_with( $url, '//' ) ) {
					return $m[0];
				}
				$fragment = '';
				if ( false !== strpos( $url, '#' ) ) {
					list( $url, $fragment ) = explode( '#', $url, 2 );
					$fragment               = '#' . $fragment;
				}
				$parts = tmc_redirect_split( $url );
				if ( is_wp_error( $parts ) ) {
					return $m[0];
				}
				$key = tmc_redirect_key( $parts['path'], $parts['query'] );
				if ( ! isset( $this->url_map[ $key ] ) || '(assigned on import)' === $this->url_map[ $key ] ) {
					return $m[0];
				}
				++$count;
				return $m[1] . '=' . $m[2] . esc_attr( $this->url_map[ $key ] . $fragment ) . $m[2];
			},
			(string) $html
		);
	}

	/* ================================================================ helpers */

	/** "Y-m-d H:i:s" (site time) from YYYY-MM-DD[ HH:MM[:SS]] or DD/MM/YYYY[ HH:MM], or ''. */
	public static function parse_date( $value ) {
		$value   = trim( str_replace( 'T', ' ', (string) $value ) );
		$formats = array( 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d', 'd/m/Y H:i', 'd/m/Y', 'd-m-Y' );
		foreach ( $formats as $format ) {
			$date   = DateTimeImmutable::createFromFormat( '!' . $format, $value, wp_timezone() );
			$errors = DateTimeImmutable::getLastErrors();
			if ( $date && ( ! $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) ) {
				return $date->format( 'Y-m-d H:i:s' );
			}
		}
		return '';
	}

	private function find_by_import_key( $key ) {
		global $wpdb;
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE m.meta_key = '_tmc_import_key' AND m.meta_value = %s AND p.post_status NOT IN ('trash','auto-draft') ORDER BY p.ID ASC LIMIT 1", $key ) );
		return $id ? get_post( $id ) : null;
	}

	/** Item of a type at a path ("parent/child" for pages, the slug otherwise) in a language. */
	private function find_by_path( $type, $lang, $path ) {
		global $wpdb;
		$segments = explode( '/', trim( $path, '/' ) );
		$slug     = sanitize_title( (string) end( $segments ) );
		// All candidates with this slug; the language and full path decide.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_name = %s AND post_status NOT IN ('trash','auto-draft','inherit') ORDER BY ID ASC", $type, $slug ) );
		foreach ( $ids as $id ) {
			$post = get_post( (int) $id );
			if ( function_exists( 'pll_get_post_language' ) && pll_get_post_language( $post->ID ) !== $lang ) {
				continue;
			}
			$full = array( $post->post_name );
			$up   = $post;
			while ( $up->post_parent && ( $up = get_post( $up->post_parent ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition
				array_unshift( $full, $up->post_name );
			}
			if ( implode( '/', $full ) === implode( '/', array_map( 'sanitize_title', $segments ) ) ) {
				return $post;
			}
		}
		return null;
	}

	private function register_planned( $type, $lang, $path ) {
		if ( 'page' === $type || ( get_post_type_object( $type ) && get_post_type_object( $type )->hierarchical ) ) {
			$this->planned[ "$lang|$path" ] = true;
		}
	}

	/** Site-relative address of a post; a predicted pretty URL while it is not yet published. */
	private function relative_url( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}
		if ( 'publish' === $post->post_status ) {
			return wp_make_link_relative( (string) get_permalink( $post ) );
		}
		$path = array( $post->post_name );
		$up   = $post;
		while ( $up->post_parent && ( $up = get_post( $up->post_parent ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition
			array_unshift( $path, $up->post_name );
		}
		$lang = function_exists( 'pll_get_post_language' ) ? (string) pll_get_post_language( $post->ID ) : $this->default_lang;
		return $this->predict_url( $post->post_type, $lang, implode( '/', $path ) );
	}

	/** Pretty URL the item will have (permalinks "/%postname%/", Polylang language directories). */
	private function predict_url( $type, $lang, $path ) {
		$prefix = ( $lang && $lang !== $this->default_lang ) ? '/' . $lang : '';
		if ( 'page' === $type ) {
			return $prefix . '/' . $path . '/';
		}
		if ( 'post' === $type ) {
			return '/%postname%/' === get_option( 'permalink_structure' ) ? $prefix . '/' . basename( $path ) . '/' : '(assigned on import)';
		}
		$object = get_post_type_object( $type );
		$base   = ( $object && ! empty( $object->rewrite['slug'] ) ) ? $object->rewrite['slug'] : $type;
		return $prefix . '/' . $base . '/' . basename( $path ) . '/';
	}

	/** Page template file for a template name or file ('' = default). */
	private function resolve_template( $type, $name, array &$messages ) {
		$name = trim( (string) $name );
		if ( '' === $name || 'default' === strtolower( $name ) ) {
			return '';
		}
		$templates = (array) apply_filters( 'tmc_import_templates', wp_get_theme()->get_page_templates( null, $type ), $type );
		if ( isset( $templates[ $name ] ) ) {
			return $name;
		}
		$by_name = array_search( strtolower( $name ), array_map( 'strtolower', $templates ), true );
		if ( false !== $by_name ) {
			return (string) $by_name;
		}
		$messages[] = sprintf( 'Template "%s" is not available for %s; the default template is used.', $name, $type );
		return '';
	}

	/** Absolute path of a file named in the inventory; must stay inside the base directory. */
	private function resolve_file( $relative ) {
		$relative = trim( (string) $relative );
		$base     = realpath( $this->base_dir );
		$path     = realpath( '/' === substr( $relative, 0, 1 ) ? $relative : $this->base_dir . '/' . $relative );
		if ( ! $base || ! $path || ! is_file( $path ) ) {
			return new WP_Error( 'tmc_import_file', sprintf( 'File not found: %s', $relative ) );
		}
		if ( ! str_starts_with( $path, $base . DIRECTORY_SEPARATOR ) ) {
			return new WP_Error( 'tmc_import_file', sprintf( 'File outside the import folder: %s', $relative ) );
		}
		return $path;
	}

	/** Page content from content_html_file or content_html, made safe and UTF-8. */
	private function content_html( array $row, &$messages ) {
		$messages = is_array( $messages ) ? $messages : array();
		$html     = '';
		if ( '' !== ( $row['content_html_file'] ?? '' ) ) {
			$file = $this->resolve_file( $row['content_html_file'] );
			if ( is_wp_error( $file ) ) {
				return $file;
			}
			$html = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		} elseif ( '' !== ( $row['content_html'] ?? '' ) ) {
			$html = (string) $row['content_html'];
		}
		$html = preg_replace( '/^\xEF\xBB\xBF/', '', $html );
		if ( '' !== $html && ! mb_check_encoding( $html, 'UTF-8' ) ) {
			$html       = mb_convert_encoding( $html, 'UTF-8', 'Windows-1252' );
			$messages[] = 'Content was not UTF-8; converted from Windows-1252 — check special characters.';
		}
		// Only the body of a full HTML document.
		if ( preg_match( '~<body[^>]*>(.*)</body>~is', $html, $m ) ) {
			$html = $m[1];
		}
		$clean = trim( wp_kses_post( $html ) );
		if ( $clean !== trim( $html ) && preg_match( '~<(script|iframe|object|embed|form|style)\b~i', $html ) ) {
			$messages[] = 'Scripts, frames, forms or styles were removed from the content.';
		}
		return $clean;
	}

	/**
	 * "file.pdf|Title|/old/url.pdf; other.pdf" → list of documents.
	 *
	 * @return array<int,array{file:string,title:string,old_url:string,sha1:string}>|WP_Error
	 */
	private function parse_documents( $value ) {
		$documents = array();
		foreach ( array_filter( array_map( 'trim', explode( ';', (string) $value ) ) ) as $entry ) {
			$parts = array_map( 'trim', explode( '|', $entry ) );
			$file  = $this->resolve_file( $parts[0] );
			if ( is_wp_error( $file ) ) {
				return $file;
			}
			$type = wp_check_filetype( basename( $file ) );
			if ( empty( $type['type'] ) ) {
				return new WP_Error( 'tmc_import_file', sprintf( 'File type not allowed in the media library: %s', basename( $file ) ) );
			}
			$documents[] = array(
				'file'    => $file,
				'title'   => sanitize_text_field( $parts[1] ?? '' ),
				'old_url' => $parts[2] ?? '',
				'sha1'    => sha1_file( $file ),
			);
		}
		return $documents;
	}

	private function find_attachment( $sha1 ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE p.post_type = 'attachment' AND m.meta_key = '_tmc_import_file' AND m.meta_value = %s ORDER BY p.ID ASC LIMIT 1", $sha1 ) );
	}

	/** Add a file to the media library once (recognised again by its SHA-1). */
	private function import_document( array $doc ) {
		$existing = $this->find_attachment( $doc['sha1'] );
		if ( $existing ) {
			return $existing;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$tmp = wp_tempnam( basename( $doc['file'] ) );
		if ( ! $tmp || ! copy( $doc['file'], $tmp ) ) {
			return new WP_Error( 'tmc_import_file', 'Could not copy ' . basename( $doc['file'] ) );
		}
		$title = '' !== $doc['title'] ? $doc['title'] : preg_replace( '/\.[^.]+$/', '', basename( $doc['file'] ) );
		$id    = media_handle_sideload( array( 'name' => basename( $doc['file'] ), 'tmp_name' => $tmp ), 0, null, array( 'post_title' => $title, 'post_author' => $this->author ) );
		if ( is_wp_error( $id ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
			return new WP_Error( 'tmc_import_file', basename( $doc['file'] ) . ': ' . $id->get_error_message() );
		}
		update_post_meta( $id, '_tmc_import_file', $doc['sha1'] );
		return (int) $id;
	}

	/** "Documents" list (with type and size, GIGW) appended to pages and posts. */
	private function documents_block( array $attachment_ids ) {
		$items = '';
		foreach ( $attachment_ids as $id ) {
			$file   = get_attached_file( $id );
			$meta   = array_filter( array( $file ? strtoupper( pathinfo( $file, PATHINFO_EXTENSION ) ) : '', ( $file && file_exists( $file ) ) ? size_format( filesize( $file ), 1 ) : '' ) );
			$items .= sprintf(
				'<!-- wp:list-item --><li><a href="%s">%s</a>%s</li><!-- /wp:list-item -->',
				esc_url( wp_make_link_relative( (string) wp_get_attachment_url( $id ) ) ),
				esc_html( get_the_title( $id ) ),
				$meta ? ' (' . esc_html( implode( ', ', $meta ) ) . ')' : ''
			);
		}
		return "\n" . '<!-- wp:heading --><h2 class="wp-block-heading">' . esc_html__( 'Documents', 'tmc' ) . '</h2><!-- /wp:heading -->'
			. '<!-- wp:list --><ul class="wp-block-list">' . $items . '</ul><!-- /wp:list -->';
	}

	/**
	 * Values of the "field:<key>" columns, validated against tmc_field_schema().
	 *
	 * @return array<string,mixed>|WP_Error key => value ('' deletes; arrays for departments)
	 */
	private function collect_fields( $type, $lang, array $row, array &$messages ) {
		$schema = function_exists( 'tmc_field_schema' ) ? ( tmc_field_schema()[ $type ] ?? array() ) : array();
		$fields = array();
		foreach ( $row as $column => $value ) {
			if ( ! str_starts_with( $column, 'field:' ) ) {
				continue;
			}
			$key = substr( $column, 6 );
			if ( ! isset( $schema[ $key ] ) ) {
				if ( '' !== $value ) {
					$messages[] = sprintf( 'Field "%s" does not exist for %s; ignored.', $key, $type );
				}
				continue;
			}
			$field = $schema[ $key ];
			if ( '' === $value ) {
				$fields[ $key ] = 'departments' === $field['type'] ? array() : '';
				continue;
			}
			switch ( $field['type'] ) {
				case 'datetime':
					$clean = self::parse_date( $value );
					if ( ! $clean ) {
						return new WP_Error( 'tmc_import_field', sprintf( '%s: date "%s" not understood.', $field['label'], $value ) );
					}
					break;
				case 'number':
					$clean = (string) absint( $value );
					break;
				case 'url':
					$clean = esc_url_raw( $value, array( 'http', 'https' ) );
					break;
				case 'email':
					$clean = sanitize_email( $value );
					break;
				case 'select':
					if ( ! isset( $field['options'][ $value ] ) ) {
						return new WP_Error( 'tmc_import_field', sprintf( '%s: "%s" is not one of %s.', $field['label'], $value, implode( ', ', array_keys( $field['options'] ) ) ) );
					}
					$clean = $value;
					break;
				case 'documents':
					$messages[] = sprintf( 'Use the documents column for "%s".', $key );
					continue 2;
				case 'departments':
					$clean = array();
					foreach ( array_filter( array_map( 'trim', explode( ';', $value ) ) ) as $slug ) {
						$department = $this->find_by_path( 'tmc_department', $lang, $slug );
						if ( $department ) {
							$clean[] = $department->ID;
						} else {
							$messages[] = sprintf( 'Department "%s" not found in language "%s".', $slug, $lang );
						}
					}
					break;
				default:
					$clean = sanitize_text_field( $value );
			}
			if ( ! empty( $field['required'] ) && ( '' === $clean || array() === $clean ) ) {
				$messages[] = sprintf( 'Required field "%s" is empty.', $field['label'] );
			}
			$fields[ $key ] = $clean;
		}
		foreach ( $schema as $key => $field ) {
			if ( ! empty( $field['required'] ) && ! $this->has_column( 'field:' . $key ) ) {
				$messages[] = sprintf( 'Required field "%s" is not in the inventory (column field:%s).', $field['label'], $key );
			}
		}
		return $fields;
	}

	private function save_fields( $post_id, $type, array $fields ) {
		$schema = tmc_field_schema()[ $type ] ?? array();
		foreach ( $fields as $key => $value ) {
			if ( 'departments' === ( $schema[ $key ]['type'] ?? '' ) ) {
				delete_post_meta( $post_id, '_' . $key );
				foreach ( array_unique( (array) $value ) as $department_id ) {
					add_post_meta( $post_id, '_' . $key, (int) $department_id );
				}
				continue;
			}
			'' !== $value ? update_post_meta( $post_id, '_' . $key, $value ) : delete_post_meta( $post_id, '_' . $key );
		}
	}

	private function save_seo( $post_id, array $row ) {
		if ( ! function_exists( 'tmc_seo_update' ) || ! array_intersect( array( 'seo_title', 'seo_description', 'seo_noindex' ), $this->header ) ) {
			return;
		}
		tmc_seo_update(
			$post_id,
			array(
				'title'       => $this->has_column( 'seo_title' ) ? $row['seo_title'] : tmc_seo_field( $post_id, 'tmc_seo_title' ),
				'description' => $this->has_column( 'seo_description' ) ? $row['seo_description'] : tmc_seo_field( $post_id, 'tmc_seo_description' ),
				'noindex'     => $this->has_column( 'seo_noindex' ) ? in_array( strtolower( $row['seo_noindex'] ), array( '1', 'yes', 'true' ), true ) : (bool) tmc_seo_field( $post_id, 'tmc_seo_noindex' ),
				'canonical'   => tmc_seo_field( $post_id, 'tmc_seo_canonical' ),
				'image'       => tmc_seo_field( $post_id, 'tmc_seo_image' ),
			)
		);
	}

	private function save_categories( $post_id, $lang, $value, array &$messages ) {
		$ids = array();
		foreach ( array_filter( array_map( 'trim', explode( ';', (string) $value ) ) ) as $slug ) {
			$terms = get_terms( array( 'taxonomy' => 'category', 'slug' => sanitize_title( $slug ), 'hide_empty' => false, 'lang' => '' ) );
			$match = null;
			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				if ( ! function_exists( 'pll_get_term_language' ) || pll_get_term_language( $term->term_id ) === $lang ) {
					$match = $term;
				}
			}
			if ( $match ) {
				$ids[] = (int) $match->term_id;
			} else {
				$messages[] = sprintf( 'Category "%s" not found in language "%s".', $slug, $lang );
			}
		}
		if ( $ids ) {
			wp_set_post_categories( $post_id, $ids );
		}
	}
}
