<?php
/**
 * Redirect manager — admin screen: Tools → Redirects (R-4.10-5).
 *
 * List, search, add, edit, delete, CSV import/export and per-site settings. Rules and the functions
 * they use are in redirects.php.
 */

defined( 'ABSPATH' ) || exit;

/* ================================================================ admin screen: Tools → Redirects */

add_action(
	'admin_menu',
	function () {
		add_management_page( 'Redirects', 'Redirects', 'manage_options', 'tmc-redirects', 'tmc_redirects_page' );
	}
);

function tmc_redirects_admin_url( array $args = array() ) {
	return add_query_arg( $args + array( 'page' => 'tmc-redirects' ), admin_url( 'tools.php' ) );
}

/** Messages survive the redirect after a POST (per user, short-lived). */
function tmc_redirects_notice( $type, $message ) {
	$key              = 'tmc_redirects_notices_' . get_current_user_id();
	$notices          = (array) get_transient( $key );
	$notices[]        = array( $type, $message );
	set_transient( $key, array_filter( $notices ), 5 * MINUTE_IN_SECONDS );
}

function tmc_redirects_print_notices() {
	$key     = 'tmc_redirects_notices_' . get_current_user_id();
	$notices = array_filter( (array) get_transient( $key ) );
	delete_transient( $key );
	foreach ( $notices as list( $type, $message ) ) {
		printf(
			'<div class="notice notice-%s" role="%s"><p>%s</p></div>',
			esc_attr( $type ),
			'error' === $type ? 'alert' : 'status',
			wp_kses( $message, array( 'br' => array(), 'strong' => array(), 'code' => array() ) )
		);
	}
}

function tmc_redirects_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'You are not allowed to manage redirects.', 403 );
	}
	tmc_redirects_install();
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view parameters
	$view   = sanitize_key( $_GET['view'] ?? '' );
	$edit   = $view === 'edit' ? tmc_redirect_get( absint( $_GET['id'] ?? 0 ) ) : null;
	$search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
	$filter = absint( $_GET['status'] ?? 0 );
	$paged  = max( 1, absint( $_GET['paged'] ?? 1 ) );
	// phpcs:enable

	echo '<div class="wrap tmc-redirects"><h1 class="wp-heading-inline">Redirects</h1>';
	printf( ' <a class="page-title-action" href="%s">Add redirect</a>', esc_url( tmc_redirects_admin_url( array( 'view' => 'add' ) ) . '#tmc-redirect-form' ) );
	printf( ' <a class="page-title-action" href="%s">Export CSV</a>', esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=tmc_redirects_export' ), 'tmc_redirects_export' ) ) );
	echo '<hr class="wp-header-end">';
	tmc_redirects_print_notices();
	echo '<p>Send visitors and search engines from old or removed addresses to the right page. Rules apply only to addresses that do not exist on this website, so they can never hide a live page. <strong>301</strong> = moved permanently, <strong>302</strong> = moved temporarily, <strong>410</strong> = removed on purpose.</p>';

	if ( 'add' === $view || $edit ) {
		tmc_redirects_form( $edit );
	}
	tmc_redirects_list( $search, $filter, $paged );
	tmc_redirects_import_form();
	tmc_redirects_settings_form();
	echo '</div>';
}

function tmc_redirects_form( $rule ) {
	$regex_on = tmc_redirects_regex_enabled();
	$status   = $rule ? (int) $rule->status : 301;
	echo '<div class="card" style="max-width:none" id="tmc-redirect-form">';
	printf( '<h2>%s</h2>', $rule ? 'Edit redirect' : 'Add redirect' );
	printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
	wp_nonce_field( 'tmc_redirect_save' );
	echo '<input type="hidden" name="action" value="tmc_redirect_save">';
	printf( '<input type="hidden" name="id" value="%d">', $rule ? (int) $rule->id : 0 );
	echo '<table class="form-table" role="presentation">';
	printf(
		'<tr><th scope="row"><label for="tmc-redirect-source">Old address <span aria-hidden="true">*</span><span class="screen-reader-text">(required)</span></label></th><td><input type="text" id="tmc-redirect-source" name="source" class="large-text code" required value="%s" aria-describedby="tmc-redirect-source-help"><p class="description" id="tmc-redirect-source-help">The path that no longer exists, e.g. <code>/old-section/page.html</code> or <code>/showpage.php?id=12</code>. A full address (https://…) is accepted; only the path is used.</p></td></tr>',
		esc_attr( $rule ? $rule->source : '' )
	);
	if ( $regex_on ) {
		printf(
			'<tr><th scope="row">Match type</th><td><label><input type="checkbox" name="is_regex" value="1"%s aria-describedby="tmc-redirect-regex-help"> The old address is a regular expression</label><p class="description" id="tmc-redirect-regex-help">For advanced use only. Example: <code>^/news/(\d+)$</code> with the target <code>/media/news/?id=$1</code>. Matching is case-insensitive against the lower-case path.</p></td></tr>',
			checked( $rule && $rule->is_regex, true, false )
		);
	}
	printf(
		'<tr><th scope="row"><label for="tmc-redirect-target">New address</label></th><td><input type="text" id="tmc-redirect-target" name="target" class="large-text code" value="%s" aria-describedby="tmc-redirect-target-help"><p class="description" id="tmc-redirect-target-help">A path on this website (<code>/departments/</code>) or a full address of another website (<code>https://…</code>). Not needed for 410.</p></td></tr>',
		esc_attr( $rule ? $rule->target : '' )
	);
	echo '<tr><th scope="row">Type</th><td><fieldset><legend class="screen-reader-text">Redirect type</legend>';
	foreach ( TMC_REDIRECT_STATUSES as $code => $label ) {
		printf( '<label style="display:block;margin:.25em 0"><input type="radio" name="status" value="%d"%s> %s</label>', (int) $code, checked( $status, $code, false ), esc_html( $label ) );
	}
	echo '</fieldset></td></tr>';
	printf(
		'<tr><th scope="row"><label for="tmc-redirect-note">Note</label></th><td><input type="text" id="tmc-redirect-note" name="note" class="large-text" maxlength="255" value="%s"><p class="description">Optional: why this redirect exists (e.g. "migrated from old website").</p></td></tr>',
		esc_attr( $rule ? $rule->note : '' )
	);
	echo '</table>';
	submit_button( $rule ? 'Update redirect' : 'Add redirect', 'primary', 'submit', false );
	printf( ' <a class="button button-secondary" href="%s">Cancel</a>', esc_url( tmc_redirects_admin_url() ) );
	echo '</form></div>';
}

function tmc_redirects_list( $search, $filter, $paged ) {
	global $wpdb;
	$table    = tmc_redirects_table();
	$per_page = 50;
	$where    = array( '1=1' );
	$args     = array();
	if ( '' !== $search ) {
		$like    = '%' . $wpdb->esc_like( $search ) . '%';
		$where[] = '(source LIKE %s OR target LIKE %s OR note LIKE %s)';
		array_push( $args, $like, $like, $like );
	}
	if ( isset( TMC_REDIRECT_STATUSES[ $filter ] ) ) {
		$where[] = 'status = %d';
		$args[]  = $filter;
	}
	$where = implode( ' AND ', $where );
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders
	$total = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $args ) ) : $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) );
	$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d", array_merge( $args, array( $per_page, ( $paged - 1 ) * $per_page ) ) ) );
	// phpcs:enable

	echo '<h2>Rules</h2>';
	echo '<form method="get" class="tmc-redirects-filter" style="margin:.5em 0 1em"><input type="hidden" name="page" value="tmc-redirects">';
	printf( '<label for="tmc-redirect-search">Search addresses and notes</label> <input type="search" id="tmc-redirect-search" name="s" value="%s"> ', esc_attr( $search ) );
	echo '<label for="tmc-redirect-status">Type</label> <select id="tmc-redirect-status" name="status"><option value="0">All types</option>';
	foreach ( TMC_REDIRECT_STATUSES as $code => $label ) {
		printf( '<option value="%d"%s>%s</option>', (int) $code, selected( $filter, $code, false ), esc_html( $label ) );
	}
	echo '</select> ';
	submit_button( 'Filter', 'secondary', '', false );
	echo '</form>';

	printf( '<p role="status">%s</p>', esc_html( sprintf( 1 === $total ? '%s redirect' : '%s redirects', number_format_i18n( $total ) ) ) );
	echo '<table class="widefat striped"><caption class="screen-reader-text">Redirect rules</caption><thead><tr><th scope="col">Old address</th><th scope="col">New address</th><th scope="col">Type</th><th scope="col">Hits</th><th scope="col">Last hit (IST)</th><th scope="col">Note</th><th scope="col">Actions</th></tr></thead><tbody>';
	if ( ! $rows ) {
		echo '<tr><td colspan="7">No redirects found.</td></tr>';
	}
	foreach ( $rows as $rule ) {
		$flags = array();
		if ( $rule->is_regex ) {
			$flags[] = '<span class="tmc-flag">regex</span>';
		}
		if ( 410 !== (int) $rule->status && tmc_redirect_is_local( $rule->target ) && tmc_redirect_match( tmc_redirect_without_fragment( $rule->target ) ) ) {
			$flags[] = '<span class="tmc-flag tmc-flag-warn">chain: the new address is redirected again</span>';
		}
		printf(
			'<tr><td><code>%s</code> %s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>',
			esc_html( $rule->source ),
			wp_kses( implode( ' ', $flags ), array( 'span' => array( 'class' => true ) ) ),
			410 === (int) $rule->status ? '—' : '<code>' . esc_html( $rule->target ) . '</code>',
			esc_html( TMC_REDIRECT_STATUSES[ (int) $rule->status ] ?? (string) $rule->status ),
			esc_html( number_format_i18n( (int) $rule->hits ) ),
			esc_html( $rule->last_hit ? wp_date( 'd/m/Y H:i', strtotime( $rule->last_hit . ' UTC' ) ) : 'never' ),
			esc_html( $rule->note )
		);
		printf(
			'<a href="%s">Edit<span class="screen-reader-text"> redirect %s</span></a> ',
			esc_url( tmc_redirects_admin_url( array( 'view' => 'edit', 'id' => (int) $rule->id ) ) . '#tmc-redirect-form' ),
			esc_html( $rule->source )
		);
		printf( '<form method="post" action="%s" style="display:inline">', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( 'tmc_redirect_delete_' . (int) $rule->id, '_wpnonce', false );
		printf(
			'<input type="hidden" name="action" value="tmc_redirect_delete"><input type="hidden" name="id" value="%d"><button type="submit" class="button-link button-link-delete">Delete<span class="screen-reader-text"> redirect %s</span></button></form>',
			(int) $rule->id,
			esc_html( $rule->source )
		);
		echo '</td></tr>';
	}
	echo '</tbody></table>';

	$pages = (int) ceil( $total / $per_page );
	if ( $pages > 1 ) {
		echo '<nav class="tablenav" aria-label="Redirect pages"><div class="tablenav-pages">' . wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => $pages ) ) ) . '</div></nav>';
	}
}

function tmc_redirects_import_form() {
	echo '<h2 id="tmc-redirects-import">Import from CSV</h2>';
	echo '<p>First line: <code>source,target,status</code> and optionally <code>regex,note</code>. One rule per line; status is 301, 302 or 410. The export above uses the same format. Every line is checked (loops are refused) and the result is shown here.</p>';
	printf( '<form method="post" enctype="multipart/form-data" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
	wp_nonce_field( 'tmc_redirects_import' );
	echo '<input type="hidden" name="action" value="tmc_redirects_import">';
	echo '<p><label for="tmc-redirects-file">CSV file</label><br><input type="file" id="tmc-redirects-file" name="file" accept=".csv,text/csv" required></p>';
	echo '<p><label><input type="checkbox" name="update_existing" value="1"> Replace rules that already exist for the same old address</label></p>';
	submit_button( 'Import redirects', 'secondary', 'submit', false );
	echo '</form>';
}

function tmc_redirects_settings_form() {
	echo '<h2>Settings</h2>';
	$enabled = tmc_redirects_regex_enabled();
	if ( ! current_user_can( 'manage_network_options' ) ) {
		printf( '<p>Regular-expression rules are <strong>%s</strong> on this site. Only a Super Admin can change this.</p>', $enabled ? 'allowed' : 'not allowed' );
		return;
	}
	printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
	wp_nonce_field( 'tmc_redirects_settings' );
	echo '<input type="hidden" name="action" value="tmc_redirects_settings">';
	printf( '<p><label><input type="checkbox" name="regex" value="1"%s> Allow regular-expression rules on this site</label><br><span class="description">Off by default. Patterns are powerful and easy to get wrong; use them only for large, regular URL changes.</span></p>', checked( $enabled, true, false ) );
	submit_button( 'Save settings', 'secondary', 'submit', false );
	echo '</form>';
}

add_action( 'admin_head-tools_page_tmc-redirects', function () {
	echo '<style>.tmc-redirects .tmc-flag{display:inline-block;padding:0 .4em;border:1px solid currentColor;border-radius:3px;font-size:12px}.tmc-redirects .tmc-flag-warn{color:#8a4b00}.tmc-redirects td code{word-break:break-all}.tmc-redirects .card{padding:0 1em 1em;margin-bottom:1.5em}</style>';
} );

/* ---------------------------------------------------------------- handlers */

function tmc_redirects_check( $nonce_action ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'You are not allowed to manage redirects.', 403 );
	}
	check_admin_referer( $nonce_action );
}

add_action( 'admin_post_tmc_redirect_save', 'tmc_redirects_handle_save' );
function tmc_redirects_handle_save() {
	tmc_redirects_check( 'tmc_redirect_save' );
	// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- validated in tmc_redirect_save()
	$id   = absint( $_POST['id'] ?? 0 );
	$data = array(
		'source'   => wp_unslash( $_POST['source'] ?? '' ),
		'target'   => wp_unslash( $_POST['target'] ?? '' ),
		'status'   => absint( $_POST['status'] ?? 301 ),
		'is_regex' => ! empty( $_POST['is_regex'] ),
		'note'     => wp_unslash( $_POST['note'] ?? '' ),
	);
	// phpcs:enable
	$result = tmc_redirect_save( $data, $id );
	if ( is_wp_error( $result ) ) {
		tmc_redirects_notice( 'error', '<strong>Not saved.</strong> ' . esc_html( $result->get_error_message() ) );
		wp_safe_redirect( tmc_redirects_admin_url( $id ? array( 'view' => 'edit', 'id' => $id ) : array( 'view' => 'add' ) ) . '#tmc-redirect-form' );
		exit;
	}
	tmc_redirects_notice( 'success', $result['created'] ? 'Redirect added.' : 'Redirect updated.' );
	foreach ( $result['warnings'] as $warning ) {
		tmc_redirects_notice( 'warning', esc_html( $warning ) );
	}
	wp_safe_redirect( tmc_redirects_admin_url() );
	exit;
}

add_action( 'admin_post_tmc_redirect_delete', 'tmc_redirects_handle_delete' );
function tmc_redirects_handle_delete() {
	$id = absint( $_POST['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified next
	tmc_redirects_check( 'tmc_redirect_delete_' . $id );
	if ( tmc_redirect_delete( $id ) ) {
		tmc_redirects_notice( 'success', 'Redirect deleted.' );
	} else {
		tmc_redirects_notice( 'error', 'The redirect was not found (it may already have been deleted).' );
	}
	wp_safe_redirect( tmc_redirects_admin_url() );
	exit;
}

add_action( 'admin_post_tmc_redirects_import', 'tmc_redirects_handle_import' );
function tmc_redirects_handle_import() {
	tmc_redirects_check( 'tmc_redirects_import' );
	$file = $_FILES['file'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	if ( ! $file || UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
		tmc_redirects_notice( 'error', 'No file was uploaded.' );
	} elseif ( (int) $file['size'] > 5 * MB_IN_BYTES || ! preg_match( '/\.csv$/i', (string) $file['name'] ) ) {
		tmc_redirects_notice( 'error', 'Please upload a .csv file of at most 5 MB.' );
	} else {
		$result = tmc_redirects_import_csv( $file['tmp_name'], ! empty( $_POST['update_existing'] ), sanitize_file_name( $file['name'] ) );
		if ( is_wp_error( $result ) ) {
			tmc_redirects_notice( 'error', esc_html( $result->get_error_message() ) );
		} else {
			tmc_redirects_notice( $result['errors'] ? 'warning' : 'success', esc_html( sprintf( 'Import finished: %d added, %d updated, %d skipped (already present), %d with errors.', $result['created'], $result['updated'], $result['skipped'], count( $result['errors'] ) ) ) );
			$lines = array();
			foreach ( array_slice( $result['errors'], 0, 20, true ) as $line => $message ) {
				$lines[] = esc_html( "Line $line: $message" );
			}
			if ( $lines ) {
				tmc_redirects_notice( 'error', implode( '<br>', $lines ) . ( count( $result['errors'] ) > 20 ? '<br>…' : '' ) );
			}
			$lines = array();
			foreach ( array_slice( $result['warnings'], 0, 10, true ) as $line => $message ) {
				$lines[] = esc_html( "Line $line: $message" );
			}
			if ( $lines ) {
				tmc_redirects_notice( 'warning', implode( '<br>', $lines ) );
			}
		}
	}
	wp_safe_redirect( tmc_redirects_admin_url() . '#tmc-redirects-import' );
	exit;
}

add_action( 'admin_post_tmc_redirects_export', 'tmc_redirects_handle_export' );
function tmc_redirects_handle_export() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'You are not allowed to export redirects.', 403 );
	}
	check_admin_referer( 'tmc_redirects_export' );
	if ( function_exists( 'tmc_audit' ) ) {
		tmc_audit( 'redirects_exported', array( 'object_type' => 'redirect' ) );
	}
	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=redirects-' . sanitize_file_name( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) . '-' . gmdate( 'Ymd-His' ) . '.csv' );
	$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	tmc_redirects_write_csv( $out );
	fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	exit;
}

add_action( 'admin_post_tmc_redirects_settings', 'tmc_redirects_handle_settings' );
function tmc_redirects_handle_settings() {
	if ( ! current_user_can( 'manage_network_options' ) ) {
		wp_die( 'Only a Super Admin can change this setting.', 403 );
	}
	check_admin_referer( 'tmc_redirects_settings' );
	$enabled = ! empty( $_POST['regex'] );
	if ( $enabled !== tmc_redirects_regex_enabled() ) {
		$enabled ? update_option( 'tmc_redirects_regex', 1 ) : delete_option( 'tmc_redirects_regex' );
		tmc_redirects_flush();
		if ( function_exists( 'tmc_audit' ) ) {
			tmc_audit( 'redirects_regex_' . ( $enabled ? 'enabled' : 'disabled' ), array( 'object_type' => 'option', 'object_title' => 'tmc_redirects_regex' ) );
		}
	}
	tmc_redirects_notice( 'success', 'Settings saved.' );
	wp_safe_redirect( tmc_redirects_admin_url() );
	exit;
}
