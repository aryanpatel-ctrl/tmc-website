<?php
/**
 * TMC application gateway — input validation (R-4.12-2).
 *
 * Visitor input is validated and sanitised against the action's schema (or a backend-provided form
 * schema); unknown fields are dropped and messages are our own.
 */

defined( 'ABSPATH' ) || exit;

/* ================================================================ input validation */

/**
 * Validate input against field rules. Unknown input keys are ignored (never forwarded).
 * Expects unslashed input.
 *
 * @return array{0:array,1:array} [ clean values, field => error message ]
 */
function tmc_apps_validate( array $rules, array $input ) {
	$values = array();
	$errors = array();
	foreach ( $rules as $name => $rule ) {
		$rule += array( 'required' => false, 'label' => $name, 'type' => 'text' );
		$raw   = $input[ $name ] ?? null;
		if ( is_bool( $raw ) ) {
			$raw = $raw ? '1' : '';
		}
		if ( null !== $raw && ! is_scalar( $raw ) ) {
			$errors[ $name ] = tmc_apps_invalid_message( $rule );
			continue;
		}
		$raw   = trim( (string) $raw );
		$empty = '' === $raw || ( in_array( $rule['type'], array( 'consent', 'bool' ), true ) && ! in_array( strtolower( $raw ), array( '1', 'true', 'on', 'yes' ), true ) );
		if ( $empty ) {
			if ( 'bool' === $rule['type'] ) {
				$values[ $name ] = false;
			} elseif ( $rule['required'] ) {
				$errors[ $name ] = tmc_apps_required_message( $rule );
			}
			continue;
		}
		$checked = tmc_apps_check_value( $raw, $rule );
		if ( is_array( $checked ) ) {
			$values[ $name ] = $checked[0];
		} else {
			$errors[ $name ] = $checked;
		}
	}
	return array( $values, $errors );
}

function tmc_apps_required_message( array $rule ) {
	switch ( empty( $rule['choice'] ) ? $rule['type'] : 'enum' ) {
		case 'consent':
			return __( 'Tick the box to confirm that you agree.', 'tmc' );
		case 'enum':
			/* translators: %s: field label */
			return sprintf( __( 'Select an option for “%s”.', 'tmc' ), $rule['label'] );
		default:
			/* translators: %s: field label */
			return sprintf( __( 'Enter “%s”.', 'tmc' ), $rule['label'] );
	}
}

function tmc_apps_invalid_message( array $rule ) {
	/* translators: %s: field label */
	return sprintf( __( 'Enter a valid value for “%s”.', 'tmc' ), $rule['label'] ?? '' );
}

/**
 * Check one non-empty value.
 *
 * @return array|string [ clean value ] or an error message.
 */
function tmc_apps_check_value( $raw, array $rule ) {
	$label = $rule['label'];
	$max   = (int) ( $rule['max'] ?? ( 'textarea' === $rule['type'] ? 2000 : 200 ) );
	/* translators: 1: field label, 2: number of characters */
	$too_long = sprintf( __( '“%1$s” must be %2$d characters or fewer.', 'tmc' ), $label, $max );

	switch ( $rule['type'] ) {
		case 'text':
		case 'name':
		case 'textarea':
			$value = 'textarea' === $rule['type'] ? sanitize_textarea_field( $raw ) : sanitize_text_field( $raw );
			if ( ! empty( $rule['upper'] ) ) {
				$value = strtoupper( $value );
			}
			if ( '' === $value ) {
				return tmc_apps_invalid_message( $rule );
			}
			if ( mb_strlen( $value ) > $max ) {
				return $too_long;
			}
			if ( 'name' === $rule['type'] && ! preg_match( "/^[\p{L}\p{M} .'-]+$/u", $value ) ) {
				/* translators: %s: field label */
				return sprintf( __( '“%s” can contain only letters, spaces, full stops, apostrophes and hyphens.', 'tmc' ), $label );
			}
			if ( ! empty( $rule['pattern'] ) && ! preg_match( $rule['pattern'], $value ) ) {
				return tmc_apps_invalid_message( $rule );
			}
			return array( $value );

		case 'email':
			$value = sanitize_email( $raw );
			if ( strlen( $raw ) > 254 || ! is_email( $value ) ) {
				return __( 'Enter an email address in the correct format, like name@example.com.', 'tmc' );
			}
			return array( $value );

		case 'mobile':
			$digits = preg_replace( '/[\s().-]/', '', $raw );
			$digits = preg_replace( '/^(\+91|0091|0)(?=\d{10}$)/', '', $digits );
			if ( ! preg_match( '/^[6-9]\d{9}$/', $digits ) ) {
				return __( 'Enter a 10-digit mobile number, like 98765 43210.', 'tmc' );
			}
			return array( $digits );

		case 'tel':
			$digits = preg_replace( '/[\s().-]/', '', $raw );
			if ( ! preg_match( '/^\+?\d{6,15}$/', $digits ) ) {
				return __( 'Enter a telephone number using digits only, like 022 2417 7000.', 'tmc' );
			}
			return array( $digits );

		case 'date':
			if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
				/* translators: %s: field label */
				return sprintf( __( '“%s” must be a real date.', 'tmc' ), $label );
			}
			$today = wp_date( 'Y-m-d' );
			if ( ! empty( $rule['past'] ) && ( $raw >= $today || $raw < '1900-01-01' ) ) {
				/* translators: %s: field label */
				return sprintf( __( '“%s” must be in the past.', 'tmc' ), $label );
			}
			if ( isset( $rule['min_days'], $rule['max_days'] ) ) {
				$from = wp_date( 'Y-m-d', time() + (int) $rule['min_days'] * DAY_IN_SECONDS );
				$to   = wp_date( 'Y-m-d', time() + (int) $rule['max_days'] * DAY_IN_SECONDS );
				if ( $raw < $from || $raw > $to ) {
					/* translators: 1: field label, 2: first date, 3: last date */
					return sprintf( __( '“%1$s” must be between %2$s and %3$s.', 'tmc' ), $label, wp_date( 'd/m/Y', strtotime( $from . ' 12:00' ) ), wp_date( 'd/m/Y', strtotime( $to . ' 12:00' ) ) );
				}
			}
			return array( $raw );

		case 'code':
			return preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $raw ) ? array( $raw ) : tmc_apps_invalid_message( $rule );

		case 'token':
			return preg_match( '/^[A-Za-z0-9_-]{8,64}$/', $raw ) ? array( $raw ) : tmc_apps_invalid_message( $rule );

		case 'enum':
			return array_key_exists( $raw, (array) ( $rule['options'] ?? array() ) ) ? array( (string) $raw ) : tmc_apps_invalid_message( $rule );

		case 'consent':
		case 'bool':
			return array( true );

		case 'amount':
		case 'integer':
			$number = str_replace( array( ',', ' ' ), '', $raw );
			$min    = (int) ( $rule['min'] ?? 0 );
			$lim    = (int) ( $rule['max'] ?? PHP_INT_MAX );
			if ( ! preg_match( '/^\d{1,9}$/', $number ) || (int) $number < $min || (int) $number > $lim ) {
				/* translators: 1: field label, 2: minimum, 3: maximum */
				return sprintf( __( '“%1$s” must be a whole number from %2$s to %3$s.', 'tmc' ), $label, number_format_i18n( $min ), number_format_i18n( $lim ) );
			}
			return array( (int) $number );

		case 'url':
			$value = esc_url_raw( $raw, array( 'http', 'https' ) );
			if ( '' === $value || ! wp_parse_url( $value, PHP_URL_HOST ) ) {
				return __( 'Enter a web address starting with http:// or https://.', 'tmc' );
			}
			if ( strlen( $value ) > $max ) {
				return $too_long;
			}
			return array( $value );
	}
	return tmc_apps_invalid_message( $rule );
}

/* ================================================================ backend-provided form schemas */

/** Field rules for a form schema returned by a forms backend (see tmc_apps_map_schema). */
function tmc_apps_schema_rules( array $schema ) {
	$types = array(
		'text'     => 'text',
		'textarea' => 'textarea',
		'email'    => 'email',
		'tel'      => 'tel',
		'number'   => 'integer',
		'date'     => 'date',
		'select'   => 'enum',
		'radio'    => 'enum',
		'url'      => 'url',
	);
	$rules = array();
	foreach ( $schema['fields'] as $field ) {
		$rule = array(
			'type'     => 'checkbox' === $field['type'] ? ( $field['required'] ? 'consent' : 'bool' ) : $types[ $field['type'] ],
			'label'    => $field['label'],
			'required' => $field['required'],
			'max'      => $field['max_length'],
		);
		if ( 'enum' === $rule['type'] ) {
			$rule['options'] = wp_list_pluck( $field['options'], 'label', 'value' );
		}
		if ( 'number' === $field['type'] ) {
			$rule['min'] = $field['min'];
			$rule['max'] = $field['max'];
		}
		$rules[ $field['name'] ] = $rule;
	}
	return $rules;
}

/** The cleaned schema of a backend form, or null when unavailable. Cached briefly (not personal data). */
function tmc_apps_form_schema( $service, $form ) {
	$result = tmc_apps_call( $service, 'schema', array( 'form' => $form ), array( 'internal' => true, 'channel' => 'server' ) );
	return $result['ok'] ? $result['data'] : null;
}
