<?php
/**
 * Front ends for TMC applications (tender §4.4, R-4.4-1, R-4.12-3). Presentation only: every call
 * goes through the application gateway (mu-plugins/tmc-core/apps-gateway.php); nothing that a
 * visitor submits is stored by the website.
 *
 *   tmc/app-appointment  request an OPD appointment (department → date → free times → details)
 *   tmc/app-results      examination / selection result lookup (roll number + date of birth)
 *   tmc/app-form         any online form, rendered from the schema the TMC backend provides
 *   tmc/app-donate       donation: amount + donor details → payment gateway → return page that
 *                        verifies the payment status with the backend
 *
 * Accessible forms: labels, hints, "(optional)" markers, an error summary linked to each field,
 * inline errors tied with aria-describedby, results announced in a live region. They work without
 * JavaScript (the form posts to the same page and is processed server-side); assets/js/features/apps.js
 * upgrades them to in-page requests to /wp-json/tmc/v1/apps/<service>/<action>.
 *
 * In demo environments a DEMO payment gateway page is served at /?tmc_demo_checkout=<order>.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', 'tmc_register_app_blocks' );
function tmc_register_app_blocks() {
	$service = array( 'service' => array( 'type' => 'string', 'default' => '' ) );
	register_block_type( 'tmc/app-appointment', array( 'render_callback' => 'tmc_render_app_appointment', 'attributes' => $service ) );
	register_block_type( 'tmc/app-results', array( 'render_callback' => 'tmc_render_app_results', 'attributes' => $service ) );
	register_block_type( 'tmc/app-donate', array( 'render_callback' => 'tmc_render_app_donate', 'attributes' => $service ) );
	register_block_type(
		'tmc/app-form',
		array(
			'render_callback' => 'tmc_render_app_form',
			'attributes'      => $service + array(
				'form'    => array( 'type' => 'string', 'default' => 'feedback' ),
				'heading' => array( 'type' => 'string', 'default' => '' ),
			),
		)
	);
}

add_action( 'enqueue_block_editor_assets', 'tmc_app_editor_assets' );
function tmc_app_editor_assets() {
	wp_enqueue_script(
		'tmc-apps-editor',
		get_template_directory_uri() . '/assets/js/apps-editor.js',
		array( 'wp-blocks', 'wp-element', 'wp-server-side-render', 'wp-block-editor', 'wp-components' ),
		tmc_asset_version( 'assets/js/apps-editor.js' ),
		true
	);
}

// Messages for assets/js/features/apps.js (the handle comes from the file name, see inc/assets.php).
add_action( 'wp_enqueue_scripts', 'tmc_app_script_strings', 20 );
function tmc_app_script_strings() {
	wp_localize_script(
		'tmc-apps',
		'tmcApps',
		array(
			'problem'  => __( 'There is a problem', 'tmc' ),
			'error'    => __( 'Error:', 'tmc' ),
			'sending'  => __( 'Sending…', 'tmc' ),
			'network'  => __( 'We could not reach the website. Check your connection and try again.', 'tmc' ),
			'noTimes'  => __( 'No times are available on this date. Please choose another date.', 'tmc' ),
			/* translators: %d: number of available appointment times */
			'times'    => __( '%d times are available. Choose a time below.', 'tmc' ),
			'redirect' => __( 'Taking you to the payment gateway…', 'tmc' ),
		)
	);
}

/* ================================================================ helpers */

/** [ service name, service ] of an enabled service of this type, or null. */
function tmc_app_context( $type, array $attributes ) {
	if ( ! function_exists( 'tmc_apps_service' ) ) {
		return null;
	}
	$name    = ! empty( $attributes['service'] ) ? sanitize_key( $attributes['service'] ) : tmc_apps_service_of_type( $type );
	$service = $name ? tmc_apps_service( $name ) : null;
	return ( $service && $service['enabled'] && $type === $service['type'] ) ? array( $name, $service ) : null;
}

function tmc_app_instance_id( $kind ) {
	static $counts = array();
	$counts[ $kind ] = ( $counts[ $kind ] ?? 0 ) + 1;
	return 'tmc-app-' . $kind . '-' . $counts[ $kind ];
}

/** The no-JavaScript submission for this block instance, if this request carries one. */
function tmc_app_posted( $instance ) {
	$posted = $GLOBALS['tmc_app_posted'] ?? null;
	return ( is_array( $posted ) && $posted['instance'] === $instance ) ? $posted : null;
}

function tmc_app_value( array $values, $name ) {
	return isset( $values[ $name ] ) && is_scalar( $values[ $name ] ) ? (string) $values[ $name ] : '';
}

/**
 * One form field: label, optional hint, error slot, control. Types: text, email, tel, date, number,
 * url, textarea, select, radio, checkbox.
 */
function tmc_app_field( $form_id, array $f, $value = '', $error = '' ) {
	$f       += array( 'required' => false, 'hint' => '', 'options' => array(), 'autocomplete' => '', 'attrs' => array() );
	$id       = $form_id . '-' . $f['name'];
	$describe = array();
	$hint     = '';
	if ( '' !== $f['hint'] ) {
		$hint       = sprintf( '<p class="tmc-app-hint" id="%s-hint">%s</p>', esc_attr( $id ), esc_html( $f['hint'] ) );
		$describe[] = $id . '-hint';
	}
	if ( '' !== $error ) {
		$describe[] = $id . '-error';
	}
	$error_html = sprintf(
		'<p class="tmc-app-field-error" id="%s-error"%s>%s</p>',
		esc_attr( $id ),
		'' === $error ? ' hidden' : '',
		'' === $error ? '' : '<span class="screen-reader-text">' . esc_html__( 'Error:', 'tmc' ) . ' </span>' . esc_html( $error )
	);
	$described = $describe ? ' aria-describedby="' . esc_attr( implode( ' ', $describe ) ) . '"' : '';
	$optional  = $f['required'] ? '' : ' <span class="tmc-app-optional">' . esc_html__( '(optional)', 'tmc' ) . '</span>';
	$attrs     = sprintf( ' name="%s"%s%s', esc_attr( $f['name'] ), $f['required'] ? ' required' : '', '' !== $error ? ' aria-invalid="true"' : '' );
	foreach ( $f['attrs'] as $attr => $attr_value ) {
		$attrs .= sprintf( ' %s="%s"', esc_attr( $attr ), esc_attr( $attr_value ) );
	}
	if ( '' !== $f['autocomplete'] ) {
		$attrs .= ' autocomplete="' . esc_attr( $f['autocomplete'] ) . '"';
	}
	$class = 'tmc-app-field tmc-app-field-' . $f['type'] . ( '' !== $error ? ' has-error' : '' );

	if ( 'radio' === $f['type'] ) {
		$choices = '';
		$i       = 0;
		foreach ( $f['options'] as $option_value => $option_label ) {
			$option_id = 0 === $i ? $id : $id . '-' . $i;
			$choices  .= sprintf(
				'<div class="tmc-app-choice"><input type="radio" id="%s" value="%s"%s%s><label for="%s">%s</label></div>',
				esc_attr( $option_id ),
				esc_attr( $option_value ),
				checked( $value, (string) $option_value, false ),
				$attrs,
				esc_attr( $option_id ),
				esc_html( $option_label )
			);
			++$i;
		}
		return sprintf( '<div class="%s" data-field="%s"><fieldset class="tmc-app-fieldset"%s><legend>%s%s</legend>%s%s<div class="tmc-app-choices">%s</div></fieldset></div>', esc_attr( $class ), esc_attr( $f['name'] ), $described, esc_html( $f['label'] ), $optional, $hint, $error_html, $choices );
	}

	if ( 'checkbox' === $f['type'] ) {
		return sprintf(
			'<div class="%s" data-field="%s">%s<div class="tmc-app-choice"><input type="checkbox" id="%s" value="1"%s%s%s><label for="%s">%s%s</label></div>%s</div>',
			esc_attr( $class ),
			esc_attr( $f['name'] ),
			$error_html,
			esc_attr( $id ),
			checked( in_array( strtolower( $value ), array( '1', 'on', 'true', 'yes' ), true ), true, false ),
			$attrs,
			$described,
			esc_attr( $id ),
			esc_html( $f['label'] ),
			$optional,
			$hint
		);
	}

	if ( 'textarea' === $f['type'] ) {
		$control = sprintf( '<textarea id="%s" rows="5"%s%s>%s</textarea>', esc_attr( $id ), $attrs, $described, esc_textarea( $value ) );
	} elseif ( 'select' === $f['type'] ) {
		$options = '<option value="">' . esc_html__( 'Select…', 'tmc' ) . '</option>';
		foreach ( $f['options'] as $option_value => $option_label ) {
			$options .= sprintf( '<option value="%s"%s>%s</option>', esc_attr( $option_value ), selected( $value, (string) $option_value, false ), esc_html( $option_label ) );
		}
		$control = sprintf( '<select id="%s"%s%s>%s</select>', esc_attr( $id ), $attrs, $described, $options );
	} else {
		$control = sprintf( '<input type="%s" id="%s" value="%s"%s%s>', esc_attr( $f['type'] ), esc_attr( $id ), esc_attr( $value ), $attrs, $described );
	}
	return sprintf( '<div class="%s" data-field="%s"><label for="%s">%s%s</label>%s%s%s</div>', esc_attr( $class ), esc_attr( $f['name'] ), esc_attr( $id ), esc_html( $f['label'] ), $optional, $hint, $error_html, $control );
}

/** Error summary (GOV.UK pattern): each message links to its field. Rendered hidden when empty (JS fills it). */
function tmc_app_error_summary( $form_id, array $errors ) {
	$items = '';
	foreach ( $errors as $field => $message ) {
		$items .= sprintf( '<li><a href="#%s-%s">%s</a></li>', esc_attr( $form_id ), esc_attr( $field ), esc_html( $message ) );
	}
	return sprintf(
		'<div class="tmc-app-errors" id="%1$s-errors" tabindex="-1" aria-labelledby="%1$s-errors-title"%2$s><h3 class="tmc-app-errors-title" id="%1$s-errors-title">%3$s</h3><ul>%4$s</ul></div>',
		esc_attr( $form_id ),
		$errors ? ' autofocus' : ' hidden',
		esc_html__( 'There is a problem', 'tmc' ),
		$items
	);
}

function tmc_app_message( $message, $kind = 'error' ) {
	return sprintf( '<div class="tmc-app-message is-%s"><p>%s</p></div>', esc_attr( $kind ), esc_html( $message ) );
}

/** Success panel: heading, key facts, note. $rows values are escaped HTML. */
function tmc_app_success( $title, array $rows, $note = '', $kind = 'success' ) {
	return sprintf(
		'<div class="tmc-app-message is-%s"><h3 class="tmc-app-message-title">%s</h3>%s%s</div>',
		esc_attr( $kind ),
		esc_html( $title ),
		tmc_details_list( $rows ),
		'' !== $note ? '<p>' . esc_html( $note ) . '</p>' : ''
	);
}

function tmc_app_demo_notice( array $service, $extra = '' ) {
	if ( ! $service['demo'] ) {
		return '';
	}
	return sprintf(
		'<p class="tmc-app-demo"><strong>%s</strong> %s%s</p>',
		esc_html__( 'Demo backend.', 'tmc' ),
		esc_html__( 'This form is connected to a demonstration service with made-up data. Do not enter real personal or medical information.', 'tmc' ),
		'' !== $extra ? ' ' . esc_html( $extra ) : ''
	);
}

function tmc_app_unavailable( $id, $title, $kind ) {
	return sprintf(
		'<section class="tmc-app tmc-app-%1$s is-unavailable" aria-labelledby="%2$s-title"><h2 class="tmc-app-title" id="%2$s-title">%3$s</h2><p class="callout">%4$s</p></section>',
		esc_attr( $kind ),
		esc_attr( $id ),
		esc_html( $title ),
		esc_html__( 'This online service is not available at present. Please use the contact details on the Contact Us page.', 'tmc' )
	);
}

/**
 * Wrap a form in the common section: heading, demo notice, intro, live result region, error summary,
 * hidden routing fields, the fields and the privacy note.
 */
function tmc_app_section( array $a ) {
	$a += array( 'intro' => '', 'result' => '', 'errors' => array(), 'data' => array(), 'hidden' => array(), 'demo_extra' => '', 'privacy' => '' );
	$data = '';
	foreach ( $a['data'] as $key => $value ) {
		$data .= sprintf( ' data-%s="%s"', esc_attr( $key ), esc_attr( $value ) );
	}
	$hidden = array(
		'tmc_app'          => $a['kind'],
		'tmc_app_service'  => $a['service'],
		'tmc_app_instance' => $a['id'],
		'_tmc_token'       => tmc_apps_token(),
	) + $a['hidden'];
	$hidden_html = '';
	foreach ( $hidden as $name => $value ) {
		$hidden_html .= sprintf( '<input type="hidden" name="%s" value="%s">', esc_attr( $name ), esc_attr( $value ) );
	}
	return sprintf(
		'<section class="tmc-app tmc-app-%1$s" aria-labelledby="%2$s-title">
			<h2 class="tmc-app-title" id="%2$s-title">%3$s</h2>%4$s%5$s
			<div class="tmc-app-result" id="%2$s-result" role="status" aria-live="polite" tabindex="-1">%6$s</div>
			<form class="tmc-app-form" id="%2$s" method="post" novalidate data-tmc-app="%1$s"%7$s>%8$s%9$s%10$s</form>
			<p class="tmc-app-privacy">%11$s</p>
		</section>',
		esc_attr( $a['kind'] ),
		esc_attr( $a['id'] ),
		esc_html( $a['title'] ),
		tmc_app_demo_notice( $a['svc'], $a['demo_extra'] ),
		'' !== $a['intro'] ? '<p class="tmc-app-intro">' . esc_html( $a['intro'] ) . '</p>' : '',
		$a['result'],
		$data . sprintf( ' data-endpoint="%s"', esc_url( rest_url( 'tmc/v1/apps/' . $a['service'] . '/' ) ) ),
		tmc_app_error_summary( $a['id'], $a['errors'] ),
		$hidden_html,
		$a['fields'],
		esc_html( '' !== $a['privacy'] ? $a['privacy'] : __( 'Your details are sent securely to the Tata Memorial Centre system that handles this service. This website does not store them.', 'tmc' ) )
	);
}

function tmc_app_submit( $label, $step = 'submit', $class = 'button' ) {
	return sprintf( '<p class="tmc-app-actions"><button type="submit" class="%s" name="tmc_step" value="%s">%s</button></p>', esc_attr( $class ), esc_attr( $step ), esc_html( $label ) );
}

/** Result region content for a no-JavaScript submission. */
function tmc_app_posted_result_html( $type, $action, $posted ) {
	if ( ! $posted ) {
		return '';
	}
	$result = $posted['result'];
	if ( ! $result['ok'] ) {
		return $result['errors'] ? '' : tmc_app_message( $result['message'] );
	}
	return tmc_app_result_html( '', $type, $action, $result );
}

/* ================================================================ result presentation */

add_filter( 'tmc_apps_result_html', 'tmc_app_result_html', 10, 4 );
function tmc_app_result_html( $html, $type, $action, $result ) {
	$data = $result['data'];
	switch ( $type . '/' . $action ) {
		case 'appointments/request':
			return tmc_app_success(
				__( 'Appointment request sent', 'tmc' ),
				array( __( 'Reference number', 'tmc' ) => '<strong>' . esc_html( $data['reference'] ) . '</strong>' ),
				__( 'Please keep this reference number for any follow-up with the hospital.', 'tmc' )
			);
		case 'results/lookup':
			return tmc_app_success(
				__( 'Result', 'tmc' ),
				array(
					__( 'Roll number', 'tmc' )  => esc_html( $data['roll_number'] ),
					__( 'Name', 'tmc' )         => esc_html( $data['candidate_name'] ),
					__( 'Examination', 'tmc' )  => esc_html( $data['examination'] ),
					__( 'Result', 'tmc' )       => '<strong>' . esc_html( $data['result'] ) . '</strong>',
					__( 'Published on', 'tmc' ) => $data['published_on'] ? tmc_time_tag( $data['published_on'] . ' 00:00:00', false ) : '',
				),
				'',
				'info'
			);
		case 'forms/submit':
			return tmc_app_success(
				__( 'Form submitted', 'tmc' ),
				array( __( 'Reference number', 'tmc' ) => '<strong>' . esc_html( $data['reference'] ) . '</strong>' ),
				__( 'Thank you. Please keep this reference number.', 'tmc' )
			);
	}
	return $html;
}

/* ================================================================ appointment */

function tmc_render_app_appointment( $attributes ) {
	$id    = tmc_app_instance_id( 'appointment' );
	$title = __( 'Request an appointment', 'tmc' );
	$ctx   = tmc_app_context( 'appointments', $attributes );
	if ( ! $ctx ) {
		return tmc_app_unavailable( $id, $title, 'appointment' );
	}
	list( $service, $svc ) = $ctx;
	$departments           = tmc_apps_call( $service, 'departments', array(), array( 'internal' => true, 'channel' => 'server' ) );
	if ( ! $departments['ok'] || ! $departments['data']['departments'] ) {
		return tmc_app_unavailable( $id, $title, 'appointment' );
	}

	$posted = tmc_app_posted( $id );
	$result = $posted['result'] ?? null;
	$step   = $posted['step'] ?? '';
	$done   = $result && $result['ok'] && 'submit' === $step;
	$values = ( $posted && ! $done ) ? $posted['input'] : array();
	$errors = ( $result && ! $result['ok'] ) ? $result['errors'] : array();
	$slots  = ( $result && $result['ok'] && 'slots' === $step ) ? $result['data']['slots'] : null;
	$rules  = tmc_apps_catalogue()['appointments']['actions']['request']['fields'];
	$field  = fn( array $f ) => tmc_app_field( $id, $f + array( 'label' => $rules[ $f['name'] ]['label'], 'required' => ! empty( $rules[ $f['name'] ]['required'] ) ), tmc_app_value( $values, $f['name'] ), $errors[ $f['name'] ] ?? '' );

	$fields  = '<h3 class="tmc-app-subtitle">' . esc_html__( '1. Department and date', 'tmc' ) . '</h3>';
	$fields .= $field( array( 'name' => 'department', 'type' => 'select', 'options' => wp_list_pluck( $departments['data']['departments'], 'name', 'code' ) ) );
	$fields .= $field(
		array(
			'name'  => 'date',
			'type'  => 'date',
			'hint'  => __( 'You can ask for a date from tomorrow up to 90 days ahead.', 'tmc' ),
			'attrs' => array( 'min' => wp_date( 'Y-m-d', time() + DAY_IN_SECONDS ), 'max' => wp_date( 'Y-m-d', time() + 90 * DAY_IN_SECONDS ) ),
		)
	);
	$fields .= tmc_app_submit( __( 'Show available times', 'tmc' ), 'slots', 'button is-outline' );
	$fields .= '<h3 class="tmc-app-subtitle">' . esc_html__( '2. Time', 'tmc' ) . '</h3>';
	$fields .= tmc_app_slots_field( $id, $rules['slot']['label'], $slots, tmc_app_value( $values, 'slot' ), $errors['slot'] ?? '' );
	$fields .= '<h3 class="tmc-app-subtitle">' . esc_html__( '3. Patient details', 'tmc' ) . '</h3>';
	$fields .= $field( array( 'name' => 'patient_type', 'type' => 'radio', 'options' => $rules['patient_type']['options'] ) );
	$fields .= $field( array( 'name' => 'registration_no', 'type' => 'text', 'hint' => __( 'Only if you are already registered at this hospital.', 'tmc' ), 'attrs' => array( 'maxlength' => 20, 'spellcheck' => 'false' ) ) );
	$fields .= $field( array( 'name' => 'patient_name', 'type' => 'text', 'autocomplete' => 'name', 'attrs' => array( 'maxlength' => 100 ) ) );
	$fields .= $field( array( 'name' => 'mobile', 'type' => 'tel', 'autocomplete' => 'tel-national', 'hint' => __( 'A 10-digit Indian mobile number.', 'tmc' ), 'attrs' => array( 'inputmode' => 'numeric', 'maxlength' => 15 ) ) );
	$fields .= $field( array( 'name' => 'email', 'type' => 'email', 'autocomplete' => 'email', 'attrs' => array( 'maxlength' => 254, 'spellcheck' => 'false' ) ) );
	$fields .= $field( array( 'name' => 'consent', 'type' => 'checkbox', 'label' => __( 'I confirm these details are correct and agree that they are sent to the hospital appointment system to process this request.', 'tmc' ) ) );
	$fields .= tmc_app_submit( __( 'Request appointment', 'tmc' ) );

	return tmc_app_section(
		array(
			'kind'    => 'appointment',
			'id'      => $id,
			'title'   => $title,
			'svc'     => $svc,
			'service' => $service,
			'intro'   => __( 'Ask for an outpatient (OPD) appointment. This is a request: the appointment is final only when the hospital confirms it.', 'tmc' ),
			'result'  => tmc_app_posted_result_html( 'appointments', 'slots' === $step ? 'slots' : 'request', $posted ),
			'errors'  => $errors,
			'fields'  => $fields,
			'data'    => array( 'action' => 'request', 'slots-action' => 'slots' ),
			'privacy' => __( 'Your details are sent securely to the hospital appointment system. This website does not store them.', 'tmc' ),
		)
	);
}

/** Free appointment times as a radio group; the fieldset is the target of error links. */
function tmc_app_slots_field( $form_id, $label, $slots, $selected, $error ) {
	$id = $form_id . '-slot';
	if ( null === $slots ) {
		$inner = '<p class="tmc-app-hint">' . esc_html__( 'Choose a department and a date, then select “Show available times”.', 'tmc' ) . '</p>';
	} else {
		$free  = array_values( array_filter( $slots, fn( $slot ) => $slot['available'] ) );
		$inner = $free ? '' : '<p>' . esc_html__( 'No times are available on this date. Please choose another date.', 'tmc' ) . '</p>';
		foreach ( $free as $i => $slot ) {
			$inner .= sprintf(
				'<div class="tmc-app-choice"><input type="radio" name="slot" id="%1$s-%2$d" value="%3$s"%4$s required><label for="%1$s-%2$d">%5$s</label></div>',
				esc_attr( $id ),
				(int) $i,
				esc_attr( $slot['id'] ),
				checked( $selected, $slot['id'], false ),
				esc_html( $slot['time'] )
			);
		}
	}
	return sprintf(
		'<div class="tmc-app-field tmc-app-field-radio%1$s" data-field="slot"><fieldset class="tmc-app-fieldset" id="%2$s" tabindex="-1"%3$s><legend>%4$s</legend><p class="tmc-app-field-error" id="%2$s-error"%5$s>%6$s</p><div class="tmc-app-choices tmc-app-slots">%7$s</div></fieldset></div>',
		'' !== $error ? ' has-error' : '',
		esc_attr( $id ),
		'' !== $error ? ' aria-describedby="' . esc_attr( $id ) . '-error"' : '',
		esc_html( $label ),
		'' === $error ? ' hidden' : '',
		'' === $error ? '' : '<span class="screen-reader-text">' . esc_html__( 'Error:', 'tmc' ) . ' </span>' . esc_html( $error ),
		$inner
	);
}

/* ================================================================ results */

function tmc_render_app_results( $attributes ) {
	$id    = tmc_app_instance_id( 'results' );
	$title = __( 'Check your result', 'tmc' );
	$ctx   = tmc_app_context( 'results', $attributes );
	if ( ! $ctx ) {
		return tmc_app_unavailable( $id, $title, 'results' );
	}
	list( $service, $svc ) = $ctx;
	$posted                = tmc_app_posted( $id );
	$result                = $posted['result'] ?? null;
	$values                = $posted ? $posted['input'] : array();
	$errors                = ( $result && ! $result['ok'] ) ? $result['errors'] : array();
	$rules                 = tmc_apps_catalogue()['results']['actions']['lookup']['fields'];

	$fields  = tmc_app_field( $id, array( 'name' => 'roll_number', 'label' => $rules['roll_number']['label'], 'type' => 'text', 'required' => true, 'autocomplete' => 'off', 'attrs' => array( 'maxlength' => 20, 'spellcheck' => 'false', 'autocapitalize' => 'characters' ) ), tmc_app_value( $values, 'roll_number' ), $errors['roll_number'] ?? '' );
	$fields .= tmc_app_field( $id, array( 'name' => 'date_of_birth', 'label' => $rules['date_of_birth']['label'], 'type' => 'date', 'required' => true, 'autocomplete' => 'bday', 'attrs' => array( 'max' => wp_date( 'Y-m-d', time() - DAY_IN_SECONDS ) ) ), tmc_app_value( $values, 'date_of_birth' ), $errors['date_of_birth'] ?? '' );
	$fields .= tmc_app_submit( __( 'View result', 'tmc' ) );

	return tmc_app_section(
		array(
			'kind'       => 'results',
			'id'         => $id,
			'title'      => $title,
			'svc'        => $svc,
			'service'    => $service,
			'intro'      => __( 'Enter the roll number from your admit card and your date of birth.', 'tmc' ),
			'demo_extra' => __( 'To try it, use roll number DEMO1001 with date of birth 15/01/2000.', 'tmc' ),
			'result'     => tmc_app_posted_result_html( 'results', 'lookup', $posted ),
			'errors'     => $errors,
			'fields'     => $fields,
			'data'       => array( 'action' => 'lookup' ),
			'privacy'    => __( 'Your details are sent securely to the examination results system only to find your result. This website does not store them.', 'tmc' ),
		)
	);
}

/* ================================================================ online form (backend schema) */

function tmc_render_app_form( $attributes ) {
	$id    = tmc_app_instance_id( 'form' );
	$form  = sanitize_key( (string) ( $attributes['form'] ?? '' ) );
	$title = '' !== (string) ( $attributes['heading'] ?? '' ) ? (string) $attributes['heading'] : __( 'Online form', 'tmc' );
	$ctx   = tmc_app_context( 'forms', $attributes );
	$schema = ( $ctx && '' !== $form ) ? tmc_apps_form_schema( $ctx[0], $form ) : null;
	if ( ! $schema ) {
		return tmc_app_unavailable( $id, $title, 'form' );
	}
	list( $service, $svc ) = $ctx;
	if ( '' === (string) ( $attributes['heading'] ?? '' ) && '' !== $schema['title'] ) {
		$title = $schema['title'];
	}
	$posted = tmc_app_posted( $id );
	$result = $posted['result'] ?? null;
	$done   = $result && $result['ok'];
	$values = ( $posted && ! $done ) ? $posted['input'] : array();
	$errors = ( $result && ! $result['ok'] ) ? $result['errors'] : array();

	$fields = '';
	foreach ( $schema['fields'] as $f ) {
		$attrs = array();
		if ( in_array( $f['type'], array( 'text', 'email', 'tel', 'url', 'textarea' ), true ) ) {
			$attrs['maxlength'] = $f['max_length'];
		}
		if ( 'number' === $f['type'] ) {
			$attrs += array( 'min' => $f['min'], 'max' => $f['max'], 'inputmode' => 'numeric' );
		}
		$fields .= tmc_app_field(
			$id,
			array(
				'name'         => $f['name'],
				'label'        => $f['label'],
				'type'         => $f['type'],
				'required'     => $f['required'],
				'hint'         => $f['help'],
				'options'      => wp_list_pluck( $f['options'], 'label', 'value' ),
				'autocomplete' => $f['autocomplete'],
				'attrs'        => $attrs,
			),
			tmc_app_value( $values, $f['name'] ),
			$errors[ $f['name'] ] ?? ''
		);
	}
	$fields .= tmc_app_submit( __( 'Submit', 'tmc' ) );

	return tmc_app_section(
		array(
			'kind'    => 'form',
			'id'      => $id,
			'title'   => $title,
			'svc'     => $svc,
			'service' => $service,
			'intro'   => $schema['description'],
			'result'  => tmc_app_posted_result_html( 'forms', 'submit', $posted ),
			'errors'  => $errors,
			'fields'  => $fields,
			'hidden'  => array( 'form' => $form ),
			'data'    => array( 'action' => 'submit' ),
		)
	);
}

/* ================================================================ donation */

function tmc_render_app_donate( $attributes ) {
	$id    = tmc_app_instance_id( 'donate' );
	$title = __( 'Donate online', 'tmc' );
	$ctx   = tmc_app_context( 'payments', $attributes );
	if ( ! $ctx ) {
		return tmc_app_unavailable( $id, $title, 'donate' );
	}
	list( $service, $svc ) = $ctx;
	$posted                = tmc_app_posted( $id );
	$result                = $posted['result'] ?? null;
	$values                = $posted ? $posted['input'] : array();
	$errors                = ( $result && ! $result['ok'] ) ? $result['errors'] : array();
	$rules                 = tmc_apps_catalogue()['payments']['actions']['initiate']['fields'];
	$field                 = fn( array $f ) => tmc_app_field( $id, $f + array( 'label' => $rules[ $f['name'] ]['label'], 'required' => ! empty( $rules[ $f['name'] ]['required'] ) ), tmc_app_value( $values, $f['name'] ), $errors[ $f['name'] ] ?? '' );

	$fields  = $field( array( 'name' => 'amount', 'type' => 'text', 'hint' => __( 'In whole rupees, for example 1000.', 'tmc' ), 'attrs' => array( 'inputmode' => 'numeric', 'maxlength' => 9, 'pattern' => '[0-9,]*' ) ) );
	$fields .= '<h3 class="tmc-app-subtitle">' . esc_html__( 'Your details', 'tmc' ) . '</h3>';
	$fields .= $field( array( 'name' => 'donor_name', 'type' => 'text', 'autocomplete' => 'name', 'attrs' => array( 'maxlength' => 100 ) ) );
	$fields .= $field( array( 'name' => 'email', 'type' => 'email', 'autocomplete' => 'email', 'hint' => __( 'Your receipt is sent to this address.', 'tmc' ), 'attrs' => array( 'maxlength' => 254, 'spellcheck' => 'false' ) ) );
	$fields .= $field( array( 'name' => 'mobile', 'type' => 'tel', 'autocomplete' => 'tel-national', 'attrs' => array( 'inputmode' => 'numeric', 'maxlength' => 15 ) ) );
	$fields .= $field( array( 'name' => 'pan', 'type' => 'text', 'hint' => __( 'Give your PAN if you need it printed on your receipt, for example ABCDE1234F.', 'tmc' ), 'attrs' => array( 'maxlength' => 10, 'spellcheck' => 'false', 'autocapitalize' => 'characters' ) ) );
	$fields .= $field( array( 'name' => 'address', 'type' => 'textarea', 'autocomplete' => 'street-address', 'attrs' => array( 'maxlength' => 300 ) ) );
	$fields .= $field( array( 'name' => 'consent', 'type' => 'checkbox', 'label' => __( 'I agree that these details are sent to the Tata Memorial Centre payment system to process my donation and issue a receipt.', 'tmc' ) ) );
	$fields .= tmc_app_submit( __( 'Continue to payment', 'tmc' ) );

	// Back from the payment gateway: ask the backend for the real status (never trust the URL).
	$status_html = '';
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only status check
	if ( 'return' === ( $_GET['tmc_donation'] ?? '' ) && isset( $_GET['order'] ) ) {
		$status      = tmc_apps_call( $service, 'status', array( 'order_id' => sanitize_text_field( wp_unslash( $_GET['order'] ) ) ), array( 'channel' => 'form' ) );
		$status_html = tmc_app_donation_status_html( $status );
	}
	// phpcs:enable

	return tmc_app_section(
		array(
			'kind'    => 'donate',
			'id'      => $id,
			'title'   => $title,
			'svc'     => $svc,
			'service' => $service,
			'intro'   => __( 'Enter the amount and your details. You will then pay securely on the payment gateway and come back to this page, where the payment is confirmed.', 'tmc' ),
			'result'  => $status_html . ( $posted && ! $result['ok'] && ! $errors ? tmc_app_message( $result['message'] ) : '' ),
			'errors'  => $errors,
			'fields'  => $fields,
			'data'    => array( 'action' => 'initiate', 'page' => (string) get_queried_object_id() ),
			'privacy' => __( 'Your details are sent securely to the Tata Memorial Centre payment system. This website does not store them or any card or bank details.', 'tmc' ),
		)
	);
}

function tmc_app_donation_status_html( array $status ) {
	if ( ! $status['ok'] ) {
		return tmc_app_message( $status['message'] );
	}
	$data   = $status['data'];
	$amount = '₹' . number_format_i18n( $data['amount'] );
	$rows   = array(
		__( 'Amount', 'tmc' )                => esc_html( $amount ),
		__( 'Transaction reference', 'tmc' ) => esc_html( $data['transaction_ref'] ),
		__( 'Order reference', 'tmc' )       => esc_html( $data['order_id'] ),
	);
	switch ( $data['status'] ) {
		case 'paid':
			return tmc_app_success( __( 'Thank you for your donation', 'tmc' ), $rows, __( 'Your payment has been received. Please keep the transaction reference for your records.', 'tmc' ) );
		case 'failed':
			return tmc_app_success( __( 'Payment not completed', 'tmc' ), $rows, __( 'The payment gateway reported that the payment failed. You can try again below. If money was deducted from your account, contact us with the order reference.', 'tmc' ), 'error' );
		case 'cancelled':
			return tmc_app_success( __( 'Payment cancelled', 'tmc' ), $rows, __( 'You cancelled the payment. No donation was made. You can try again below.', 'tmc' ), 'error' );
	}
	return tmc_app_success( __( 'Payment not yet confirmed', 'tmc' ), $rows, __( 'We have not yet received confirmation from the payment gateway. Please reload this page in a few minutes.', 'tmc' ), 'info' );
}

/* ================================================================ no-JavaScript submissions */

add_action( 'template_redirect', 'tmc_app_handle_post', 1 );
function tmc_app_handle_post() {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified with tmc_apps_verify_token() below
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST['tmc_app'], $_POST['tmc_app_service'], $_POST['tmc_app_instance'] ) || ! function_exists( 'tmc_apps_call' ) ) {
		return;
	}
	nocache_headers();
	$kind     = sanitize_key( wp_unslash( $_POST['tmc_app'] ) );
	$service  = sanitize_key( wp_unslash( $_POST['tmc_app_service'] ) );
	$instance = sanitize_html_class( wp_unslash( $_POST['tmc_app_instance'] ) );
	$step     = 'slots' === ( $_POST['tmc_step'] ?? '' ) ? 'slots' : 'submit';
	$input    = array();
	foreach ( wp_unslash( $_POST ) as $key => $value ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated by the gateway
		if ( is_string( $key ) && is_scalar( $value ) && ! preg_match( '/^(tmc_app|tmc_step|_tmc_token|page$)/', $key ) ) {
			$input[ $key ] = (string) $value;
		}
	}
	$token = isset( $_POST['_tmc_token'] ) ? sanitize_text_field( wp_unslash( $_POST['_tmc_token'] ) ) : '';
	// phpcs:enable

	$actions = array(
		'appointment' => 'slots' === $step ? 'slots' : 'request',
		'results'     => 'lookup',
		'form'        => 'submit',
		'donate'      => 'initiate',
	);
	if ( ! isset( $actions[ $kind ] ) ) {
		return;
	}
	if ( ! tmc_apps_same_origin() || ! tmc_apps_verify_token( $token ) ) {
		$result = tmc_apps_result( 403, 'forbidden' );
	} else {
		$svc    = tmc_apps_service( $service );
		$server = $svc ? tmc_apps_server_fields( $svc, $actions[ $kind ], get_queried_object_id() ) : array();
		$result = tmc_apps_call( $service, $actions[ $kind ], $input, array( 'server_fields' => $server, 'channel' => 'form' ) );
		if ( 'donate' === $kind && $result['ok'] ) {
			wp_redirect( $result['data']['redirect_url'], 303 ); // host checked against the service's allow-list
			exit;
		}
	}
	// Kept in memory for this response only; never stored.
	$GLOBALS['tmc_app_posted'] = array(
		'instance' => $instance,
		'step'     => $step,
		'input'    => $input,
		'result'   => $result,
	);
}

/* ================================================================ DEMO payment gateway page */

/** First enabled DEMO payments service that allows the demo checkout. */
function tmc_app_demo_payment_service() {
	foreach ( tmc_apps_registry() as $name => $service ) {
		if ( 'payments' === $service['type'] && $service['enabled'] && $service['demo'] && in_array( 'checkout', $service['actions'], true ) ) {
			return $name;
		}
	}
	return '';
}

add_action( 'template_redirect', 'tmc_app_demo_checkout', 0 );
function tmc_app_demo_checkout() {
	// phpcs:disable WordPress.Security.NonceVerification -- token verified below for the POST
	if ( ! isset( $_GET['tmc_demo_checkout'] ) || ! function_exists( 'tmc_apps_call' ) ) {
		return;
	}
	$service = tmc_app_demo_payment_service();
	if ( ! $service ) {
		return; // not a demo environment: ignore the parameter
	}
	nocache_headers();
	header( 'X-Robots-Tag: noindex, nofollow' );
	$order = sanitize_text_field( wp_unslash( $_GET['tmc_demo_checkout'] ) );
	$error = '';
	if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
		$token = isset( $_POST['_tmc_token'] ) ? sanitize_text_field( wp_unslash( $_POST['_tmc_token'] ) ) : '';
		if ( ! tmc_apps_same_origin() || ! tmc_apps_verify_token( $token ) ) {
			$error = tmc_apps_messages()['forbidden'];
		} else {
			$outcome = sanitize_key( wp_unslash( $_POST['outcome'] ?? '' ) );
			$result  = tmc_apps_call( $service, 'simulate', array( 'order_id' => $order, 'outcome' => $outcome ), array( 'internal' => true, 'channel' => 'form' ) );
			if ( $result['ok'] ) {
				wp_safe_redirect( add_query_arg( 'order', $result['data']['order_id'], $result['data']['return_url'] ), 303 );
				exit;
			}
			$error = $result['message'];
		}
	}
	// phpcs:enable
	$checkout = tmc_apps_call( $service, 'checkout', array( 'order_id' => $order ), array( 'internal' => true, 'channel' => 'form' ) );

	add_filter( 'pre_get_document_title', fn() => __( 'Demo payment gateway', 'tmc' ) );
	add_filter( 'wp_robots', fn( $robots ) => array( 'noindex' => true, 'nofollow' => true ) + $robots );
	get_header();
	get_template_part( 'template-parts/page-header', null, array( 'title' => __( 'Demo payment gateway', 'tmc' ) ) );
	echo '<div class="container page-body is-narrow"><section class="tmc-app tmc-app-checkout" aria-labelledby="tmc-checkout-title">';
	printf(
		'<p class="tmc-app-demo"><strong>%s</strong> %s</p>',
		esc_html__( 'Demonstration only.', 'tmc' ),
		esc_html__( 'This page stands in for the payment gateway approved by Tata Memorial Centre. No money is taken and no card or bank details are asked for.', 'tmc' )
	);
	echo '<h2 class="tmc-app-title" id="tmc-checkout-title">' . esc_html__( 'Payment details', 'tmc' ) . '</h2>';
	if ( '' !== $error ) {
		echo tmc_app_message( $error ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in tmc_app_message()
	}
	if ( ! $checkout['ok'] ) {
		echo tmc_app_message( $checkout['message'] ); // phpcs:ignore WordPress.Security.EscapeOutput
	} else {
		$data = $checkout['data'];
		echo tmc_details_list( // phpcs:ignore WordPress.Security.EscapeOutput -- values escaped here
			array(
				__( 'Amount', 'tmc' )          => esc_html( '₹' . number_format_i18n( $data['amount'] ) ),
				__( 'Order reference', 'tmc' ) => esc_html( $data['order_id'] ),
			)
		);
		if ( 'created' !== $data['status'] ) {
			printf( '<p><a class="button" href="%s">%s</a></p>', esc_url( add_query_arg( 'order', $data['order_id'], $data['return_url'] ) ), esc_html__( 'Return to the website', 'tmc' ) );
		} else {
			printf( '<form method="post" class="tmc-app-checkout-form"><input type="hidden" name="_tmc_token" value="%s"><fieldset class="tmc-app-fieldset"><legend>%s</legend><p class="tmc-app-actions">', esc_attr( tmc_apps_token() ), esc_html__( 'Choose what the demo gateway should do', 'tmc' ) );
			printf( '<button type="submit" class="button" name="outcome" value="success">%s</button> ', esc_html__( 'Simulate successful payment', 'tmc' ) );
			printf( '<button type="submit" class="button is-outline" name="outcome" value="failure">%s</button> ', esc_html__( 'Simulate failed payment', 'tmc' ) );
			printf( '<button type="submit" class="button is-outline" name="outcome" value="cancel">%s</button>', esc_html__( 'Cancel payment', 'tmc' ) );
			echo '</p></fieldset></form>';
		}
	}
	echo '</section></div>';
	get_footer();
	exit;
}
