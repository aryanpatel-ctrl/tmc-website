<?php
/**
 * TMC application gateway — response allow-lists (R-4.12-1).
 *
 * Backend responses are rebuilt from an explicit allow-list of fields, so upstream URLs, host names,
 * status texts and error bodies never reach the visitor. Payment redirects go only to the hosts the
 * registry allows for that service.
 */

defined( 'ABSPATH' ) || exit;

/* ================================================================ response allow-lists */

function tmc_apps_text( $value, $max = 200 ) {
	return is_scalar( $value ) ? mb_substr( sanitize_text_field( (string) $value ), 0, $max ) : '';
}

function tmc_apps_map_departments( array $body ) {
	if ( ! isset( $body['departments'] ) || ! is_array( $body['departments'] ) ) {
		return null;
	}
	$list = array();
	foreach ( array_slice( $body['departments'], 0, 200 ) as $item ) {
		$code = is_array( $item ) ? (string) ( $item['code'] ?? '' ) : '';
		$name = is_array( $item ) ? tmc_apps_text( $item['name'] ?? '', 100 ) : '';
		if ( preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $code ) && '' !== $name ) {
			$list[] = array( 'code' => $code, 'name' => $name );
		}
	}
	return array( 'departments' => $list );
}

function tmc_apps_map_slots( array $body ) {
	if ( ! isset( $body['slots'] ) || ! is_array( $body['slots'] ) ) {
		return null;
	}
	$slots = array();
	foreach ( array_slice( $body['slots'], 0, 200 ) as $slot ) {
		if ( is_array( $slot ) && preg_match( '/^[A-Za-z0-9_-]{1,64}$/', (string) ( $slot['id'] ?? '' ) ) && preg_match( '/^\d{2}:\d{2}$/', (string) ( $slot['time'] ?? '' ) ) ) {
			$slots[] = array( 'id' => (string) $slot['id'], 'time' => (string) $slot['time'], 'available' => ! empty( $slot['available'] ) );
		}
	}
	return array( 'slots' => $slots );
}

function tmc_apps_map_reference( array $body ) {
	$reference = (string) ( $body['reference'] ?? '' );
	return preg_match( '/^[A-Za-z0-9\/_-]{1,40}$/', $reference ) ? array( 'reference' => $reference ) : null;
}

function tmc_apps_map_result( array $body ) {
	$data = array(
		'roll_number'    => tmc_apps_text( $body['roll_number'] ?? '', 20 ),
		'candidate_name' => tmc_apps_text( $body['candidate_name'] ?? '', 100 ),
		'examination'    => tmc_apps_text( $body['examination'] ?? '', 200 ),
		'result'         => tmc_apps_text( $body['result'] ?? '', 100 ),
		'published_on'   => preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $body['published_on'] ?? '' ) ) ? (string) $body['published_on'] : '',
	);
	return '' === $data['result'] ? null : $data;
}

function tmc_apps_map_schema( array $body ) {
	$form = $body['form'] ?? null;
	if ( ! is_array( $form ) || ! is_array( $form['fields'] ?? null ) ) {
		return null;
	}
	$allowed  = array( 'text', 'textarea', 'email', 'tel', 'number', 'date', 'select', 'radio', 'checkbox', 'url' );
	$reserved = array( 'form', 'page', 'lang', 'return_url' );
	$fields   = array();
	foreach ( array_slice( $form['fields'], 0, 40 ) as $field ) {
		$name = is_array( $field ) ? (string) ( $field['name'] ?? '' ) : '';
		$type = is_array( $field ) ? (string) ( $field['type'] ?? '' ) : '';
		if ( ! preg_match( '/^[a-z][a-z0-9_]{0,31}$/', $name ) || 0 === strpos( $name, 'tmc_' ) || in_array( $name, $reserved, true ) || ! in_array( $type, $allowed, true ) ) {
			continue;
		}
		$clean = array(
			'name'         => $name,
			'label'        => tmc_apps_text( $field['label'] ?? '', 200 ) ?: $name,
			'type'         => $type,
			'required'     => ! empty( $field['required'] ),
			'help'         => tmc_apps_text( $field['help'] ?? '', 300 ),
			'max_length'   => max( 1, min( 5000, (int) ( $field['max_length'] ?? ( 'textarea' === $type ? 2000 : 200 ) ) ) ),
			'autocomplete' => preg_match( '/^[a-z][a-z -]{0,39}$/', (string) ( $field['autocomplete'] ?? '' ) ) ? (string) $field['autocomplete'] : '',
			'options'      => array(),
			'min'          => (int) ( $field['min'] ?? 0 ),
			'max'          => (int) ( $field['max'] ?? 999999999 ),
		);
		if ( in_array( $type, array( 'select', 'radio' ), true ) ) {
			foreach ( array_slice( (array) ( $field['options'] ?? array() ), 0, 100 ) as $option ) {
				$value = is_array( $option ) ? (string) ( $option['value'] ?? '' ) : '';
				if ( preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $value ) ) {
					$clean['options'][] = array( 'value' => $value, 'label' => tmc_apps_text( $option['label'] ?? $value, 200 ) ?: $value );
				}
			}
			if ( ! $clean['options'] ) {
				continue;
			}
		}
		$fields[ $name ] = $clean;
	}
	if ( ! $fields ) {
		return null;
	}
	return array(
		'id'          => sanitize_key( (string) ( $form['id'] ?? '' ) ),
		'title'       => tmc_apps_text( $form['title'] ?? '', 200 ),
		'description' => tmc_apps_text( $form['description'] ?? '', 500 ),
		'fields'      => array_values( $fields ),
	);
}

/** Is a gateway redirect target allowed for this service? "self" = this website's host. */
function tmc_apps_redirect_allowed( $url, array $service ) {
	$parts = wp_parse_url( (string) $url );
	if ( ! $parts || empty( $parts['host'] ) || ! in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) || isset( $parts['user'] ) ) {
		return false;
	}
	$host = strtolower( $parts['host'] );
	$self = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	foreach ( $service['redirect_hosts'] as $allowed ) {
		if ( 'self' === $allowed ? $host === $self : ( $host === $allowed && 'https' === $parts['scheme'] ) ) {
			return true;
		}
	}
	return false;
}

function tmc_apps_map_initiate( array $body, array $service ) {
	$order = (string) ( $body['order_id'] ?? '' );
	$url   = (string) ( $body['redirect_url'] ?? '' );
	if ( ! preg_match( '/^[A-Za-z0-9_-]{8,64}$/', $order ) || ! tmc_apps_redirect_allowed( $url, $service ) ) {
		return null;
	}
	return array( 'order_id' => $order, 'redirect_url' => esc_url_raw( $url ) );
}

function tmc_apps_map_status( array $body ) {
	$order    = (string) ( $body['order_id'] ?? '' );
	$status   = (string) ( $body['status'] ?? '' );
	$currency = (string) ( $body['currency'] ?? 'INR' );
	if ( ! preg_match( '/^[A-Za-z0-9_-]{8,64}$/', $order ) ) {
		return null;
	}
	$reference = (string) ( $body['transaction_ref'] ?? '' );
	return array(
		'order_id'        => $order,
		'status'          => in_array( $status, array( 'created', 'pending', 'paid', 'failed', 'cancelled' ), true ) ? $status : 'pending',
		'amount'          => max( 0, (int) ( $body['amount'] ?? 0 ) ),
		'currency'        => preg_match( '/^[A-Z]{3}$/', $currency ) ? $currency : 'INR',
		'transaction_ref' => preg_match( '/^[A-Za-z0-9\/_-]{1,40}$/', $reference ) ? $reference : '',
	);
}

/** Demo checkout / simulate: the return URL must point back at this website. */
function tmc_apps_map_checkout( array $body ) {
	$status = tmc_apps_map_status( $body );
	$return = (string) ( $body['return_url'] ?? '' );
	if ( ! $status || strtolower( (string) wp_parse_url( $return, PHP_URL_HOST ) ) !== strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
		return null;
	}
	return $status + array( 'return_url' => esc_url_raw( $return ) );
}
