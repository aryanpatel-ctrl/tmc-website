<?php
/**
 * Network Admin → Settings → TMC applications: the endpoint registry of the application gateway
 * (see apps-gateway.php and docs/integration/gateway.md).
 *
 * Super Admins register each TMC-approved backend: service name, type, internal base URL, allowed
 * actions, timeout, rate limit and (payments) allowed redirect hosts. API keys are NOT entered here —
 * they are read from the environment variable shown for each service. Every change is audit-logged.
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'network_admin_menu',
	function () {
		add_submenu_page( 'settings.php', 'TMC applications', 'TMC applications', 'manage_network_options', 'tmc-apps', 'tmc_apps_admin_page' );
	}
);

add_action( 'admin_post_tmc_apps_registry', 'tmc_apps_admin_save' );

function tmc_apps_admin_url( array $args = array() ) {
	return add_query_arg( $args, network_admin_url( 'settings.php?page=tmc-apps' ) );
}

/** Turn the posted form into registry entries; returns [ registry, errors ]. */
function tmc_apps_admin_parse( array $posted ) {
	$registry = array();
	$errors   = array();
	foreach ( $posted as $key => $raw ) {
		if ( ! is_array( $raw ) || ! empty( $raw['delete'] ) ) {
			continue;
		}
		$name = '__new' === $key ? trim( (string) ( $raw['name'] ?? '' ) ) : (string) $key;
		if ( '__new' === $key && '' === $name ) {
			continue; // "add a service" left empty
		}
		$raw['redirect_hosts'] = preg_split( '/[\s,]+/', (string) ( $raw['redirect_hosts'] ?? '' ), -1, PREG_SPLIT_NO_EMPTY );
		$service               = tmc_apps_normalize_service( $name, $raw );
		if ( is_wp_error( $service ) ) {
			$errors[] = $service->get_error_message();
		} elseif ( '__new' === $key && isset( $posted[ $name ] ) ) {
			$errors[] = "$name: a service with this name already exists.";
		} else {
			$registry[ $name ] = $service;
		}
	}
	return array( $registry, $errors );
}

function tmc_apps_admin_save() {
	if ( ! current_user_can( 'manage_network_options' ) ) {
		wp_die( 'You are not allowed to change the application registry.', 403 );
	}
	check_admin_referer( 'tmc_apps_registry' );

	$posted                     = isset( $_POST['services'] ) && is_array( $_POST['services'] ) ? wp_unslash( $_POST['services'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated field by field in tmc_apps_normalize_service()
	list( $registry, $errors ) = tmc_apps_admin_parse( $posted );
	if ( $errors ) {
		set_site_transient( 'tmc_apps_admin_errors_' . get_current_user_id(), $errors, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( tmc_apps_admin_url( array( 'error' => 1 ) ) );
		exit;
	}

	$before = tmc_apps_registry();
	update_site_option( TMC_APPS_OPTION, $registry );
	$changes = array();
	foreach ( array_unique( array_merge( array_keys( $before ), array_keys( $registry ) ) ) as $name ) {
		if ( ! isset( $before[ $name ] ) ) {
			$changes[ $name ] = 'added';
		} elseif ( ! isset( $registry[ $name ] ) ) {
			$changes[ $name ] = 'removed';
		} elseif ( $before[ $name ] !== $registry[ $name ] ) {
			$changes[ $name ] = 'changed: ' . implode( ',', array_keys( array_filter( $registry[ $name ], fn( $value, $field ) => $before[ $name ][ $field ] !== $value, ARRAY_FILTER_USE_BOTH ) ) );
		}
	}
	if ( $changes && function_exists( 'tmc_audit' ) ) {
		tmc_audit( 'app_registry_changed', array( 'object_type' => 'network_option', 'object_title' => TMC_APPS_OPTION, 'details' => $changes ) );
	}
	wp_safe_redirect( tmc_apps_admin_url( array( 'updated' => 1 ) ) );
	exit;
}

function tmc_apps_admin_page() {
	if ( ! current_user_can( 'manage_network_options' ) ) {
		return;
	}
	$registry  = tmc_apps_registry();
	$catalogue = tmc_apps_catalogue();
	$errors    = get_site_transient( 'tmc_apps_admin_errors_' . get_current_user_id() );
	delete_site_transient( 'tmc_apps_admin_errors_' . get_current_user_id() );

	echo '<div class="wrap"><h1>TMC applications (integration gateway)</h1>';
	echo '<p>The website reaches TMC application backends only through the services registered here, and only for the actions ticked. Visitors never see these addresses: the gateway calls them from the server and returns only an allow-listed result. Nothing a visitor submits is stored on the website. See <code>docs/integration/gateway.md</code>.</p>';
	echo '<p><strong>API keys are not stored here.</strong> Each service reads its key from the environment variable shown (set it in the server\'s <code>.env</code> and recreate the WordPress container).</p>';
	if ( tmc_is_demo() ) {
		echo '<div class="notice notice-info inline"><p>This is a <strong>demonstration environment</strong> (<code>TMC_DEMO=1</code>). Services marked DEMO use the mock backend <code>tmc-apps-mock</code>, which holds only made-up data.</p></div>';
	}
	if ( isset( $_GET['updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="notice notice-success"><p>Registry saved.</p></div>';
	}
	if ( is_array( $errors ) && $errors ) {
		echo '<div class="notice notice-error"><p><strong>Nothing was saved:</strong></p><ul>';
		foreach ( $errors as $error ) {
			echo '<li>' . esc_html( $error ) . '</li>';
		}
		echo '</ul></div>';
	}

	// Overview.
	echo '<h2>Registered services</h2><table class="widefat striped"><thead><tr><th scope="col">Service</th><th scope="col">Public route</th><th scope="col">Type</th><th scope="col">Status</th><th scope="col">Allowed actions</th><th scope="col">API key</th><th scope="col">Limits</th></tr></thead><tbody>';
	if ( ! $registry ) {
		echo '<tr><td colspan="7">No services registered. Online appointment, results, form and donation blocks show "not available" until TMC registers its endpoints.</td></tr>';
	}
	foreach ( $registry as $name => $service ) {
		$actions = array();
		foreach ( $service['actions'] as $action ) {
			$def       = $catalogue[ $service['type'] ]['actions'][ $action ];
			$actions[] = $def['method'] . ' ' . $action . ( ! empty( $def['internal'] ) ? ' (server only)' : '' );
		}
		printf(
			'<tr><td><strong>%s</strong><br>%s</td><td><code>/wp-json/tmc/v1/apps/%s/&lt;action&gt;</code></td><td>%s</td><td>%s%s</td><td>%s</td><td><code>%s</code><br>%s</td><td>%d req/min per IP<br>timeout %d s</td></tr>',
			esc_html( $name ),
			esc_html( $service['label'] ),
			esc_html( $name ),
			esc_html( $catalogue[ $service['type'] ]['label'] ),
			$service['enabled'] ? 'Enabled' : '<strong>Disabled</strong>',
			$service['demo'] ? ' · DEMO' : '',
			esc_html( implode( ', ', $actions ) ),
			esc_html( $service['key_env'] ),
			'' !== tmc_apps_key( $service ) ? 'present in environment' : '<strong style="color:#b32d2e">missing — service refuses calls</strong>',
			(int) $service['rate_limit'],
			(int) $service['timeout']
		);
	}
	echo '</tbody></table>';

	// Edit form.
	echo '<h2>Edit registry</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	echo '<input type="hidden" name="action" value="tmc_apps_registry">';
	wp_nonce_field( 'tmc_apps_registry' );
	foreach ( $registry as $name => $service ) {
		tmc_apps_admin_fieldset( $name, $service, $catalogue );
	}
	tmc_apps_admin_fieldset(
		'__new',
		array( 'type' => 'forms', 'label' => '', 'base_url' => '', 'actions' => array(), 'timeout' => 8, 'rate_limit' => 20, 'enabled' => true, 'demo' => false, 'key_env' => '', 'redirect_hosts' => array() ),
		$catalogue
	);
	submit_button( 'Save registry' );
	echo '</form></div>';
}

function tmc_apps_admin_fieldset( $name, array $service, array $catalogue ) {
	$is_new = '__new' === $name;
	$field  = fn( $key ) => 'services[' . $name . '][' . $key . ']';
	$id     = fn( $key ) => 'tmc-apps-' . $name . '-' . $key;

	echo '<fieldset style="border:1px solid #c3c4c7;padding:0 1rem 1rem;margin:0 0 1.5rem;background:#fff"><legend style="font-weight:600;padding:0 .5rem">' . esc_html( $is_new ? 'Add a service' : 'Service: ' . $name ) . '</legend><table class="form-table" role="presentation">';
	if ( $is_new ) {
		printf( '<tr><th scope="row"><label for="%1$s">Service name</label></th><td><input type="text" id="%1$s" name="%2$s" pattern="[a-z][a-z0-9_]{1,31}" class="regular-text"><p class="description">Lower-case, used in the public route. Leave empty to add nothing.</p></td></tr>', esc_attr( $id( 'name' ) ), esc_attr( $field( 'name' ) ) );
	}
	printf( '<tr><th scope="row"><label for="%s">Label</label></th><td><input type="text" id="%s" name="%s" value="%s" class="regular-text"></td></tr>', esc_attr( $id( 'label' ) ), esc_attr( $id( 'label' ) ), esc_attr( $field( 'label' ) ), esc_attr( $service['label'] ) );
	printf( '<tr><th scope="row"><label for="%s">Type</label></th><td><select id="%s" name="%s">', esc_attr( $id( 'type' ) ), esc_attr( $id( 'type' ) ), esc_attr( $field( 'type' ) ) );
	foreach ( $catalogue as $type => $spec ) {
		printf( '<option value="%s"%s>%s</option>', esc_attr( $type ), selected( $service['type'], $type, false ), esc_html( $spec['label'] ) );
	}
	echo '</select></td></tr>';
	printf( '<tr><th scope="row"><label for="%s">Internal base URL</label></th><td><input type="url" id="%s" name="%s" value="%s" class="large-text code"><p class="description">TMC-approved endpoint on the integration network, e.g. https://appointments.internal.example/v1. Never shown to visitors.</p></td></tr>', esc_attr( $id( 'base_url' ) ), esc_attr( $id( 'base_url' ) ), esc_attr( $field( 'base_url' ) ), esc_attr( $service['base_url'] ) );

	echo '<tr><th scope="row">Allowed actions</th><td><fieldset><legend class="screen-reader-text">Allowed actions</legend>';
	foreach ( $catalogue as $type => $spec ) {
		foreach ( $spec['actions'] as $action => $def ) {
			printf(
				'<label style="display:block"><input type="checkbox" name="%s[]" value="%s"%s> <code>%s</code> %s <span class="description">(%s%s)</span></label>',
				esc_attr( $field( 'actions' ) ),
				esc_attr( $action ),
				checked( $type === $service['type'] && in_array( $action, $service['actions'], true ), true, false ),
				esc_html( $def['method'] ),
				esc_html( $action ),
				esc_html( $spec['label'] ),
				! empty( $def['demo_only'] ) ? ', demo backends only' : ''
			);
		}
	}
	echo '<p class="description">Only actions of the selected type are kept.</p></fieldset></td></tr>';

	printf( '<tr><th scope="row"><label for="%s">Timeout (seconds)</label></th><td><input type="number" min="1" max="30" id="%s" name="%s" value="%d" class="small-text"></td></tr>', esc_attr( $id( 'timeout' ) ), esc_attr( $id( 'timeout' ) ), esc_attr( $field( 'timeout' ) ), (int) $service['timeout'] );
	printf( '<tr><th scope="row"><label for="%s">Rate limit</label></th><td><input type="number" min="1" max="600" id="%s" name="%s" value="%d" class="small-text"> requests per minute per visitor IP</td></tr>', esc_attr( $id( 'rate_limit' ) ), esc_attr( $id( 'rate_limit' ) ), esc_attr( $field( 'rate_limit' ) ), (int) $service['rate_limit'] );
	printf( '<tr><th scope="row"><label for="%s">API key variable</label></th><td><input type="text" id="%s" name="%s" value="%s" class="regular-text code" placeholder="TMC_APP_&lt;NAME&gt;_KEY"><p class="description">Name of the environment variable that holds the key (empty = TMC_APP_&lt;SERVICE&gt;_KEY). The key itself is never stored in the database.</p></td></tr>', esc_attr( $id( 'key_env' ) ), esc_attr( $id( 'key_env' ) ), esc_attr( $field( 'key_env' ) ), esc_attr( $service['key_env'] ) );
	printf( '<tr><th scope="row"><label for="%s">Allowed redirect hosts</label></th><td><input type="text" id="%s" name="%s" value="%s" class="regular-text code"><p class="description">Payments only: host names of the approved payment gateway page (https), separated by spaces. <code>self</code> = this website (demo gateway page).</p></td></tr>', esc_attr( $id( 'redirect_hosts' ) ), esc_attr( $id( 'redirect_hosts' ) ), esc_attr( $field( 'redirect_hosts' ) ), esc_attr( implode( ' ', $service['redirect_hosts'] ) ) );
	printf( '<tr><th scope="row">Options</th><td><label><input type="checkbox" name="%s" value="1"%s> Enabled</label><br><label><input type="checkbox" name="%s" value="1"%s> DEMO backend (label forms as demo; allow demo-only actions)</label>', esc_attr( $field( 'enabled' ) ), checked( $service['enabled'], true, false ), esc_attr( $field( 'demo' ) ), checked( $service['demo'], true, false ) );
	if ( ! $is_new ) {
		printf( '<br><label><input type="checkbox" name="%s" value="1"> Remove this service</label>', esc_attr( $field( 'delete' ) ) );
	}
	echo '</td></tr></table></fieldset>';
}
