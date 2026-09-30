<?php
/**
 * Editor UI for the structured fields in tmc_field_schema(): one "Details" meta box per type.
 * Plain PHP meta boxes (they work in the block editor), native date-time inputs, and the
 * WordPress media library for documents (admin/fields.js).
 */

defined( 'ABSPATH' ) || exit;

add_action( 'add_meta_boxes', 'tmc_fields_register_box', 10, 2 );
function tmc_fields_register_box( $post_type, $post ) {
	$fields = tmc_field_schema()[ $post_type ] ?? array();
	if ( $fields ) {
		$title = 'post' === $post_type ? 'Expiry' : 'Details';
		add_meta_box( 'tmc_fields', $title, 'tmc_fields_render_box', $post_type, 'post' === $post_type ? 'side' : 'normal', 'high' );
	}
}

function tmc_fields_render_box( WP_Post $post ) {
	wp_nonce_field( 'tmc_fields', 'tmc_fields_nonce' );
	echo '<div class="tmc-fields">';
	foreach ( tmc_field_schema()[ $post->post_type ] as $key => $field ) {
		$id    = 'tmc-field-' . $key;
		$value = tmc_field( $post->ID, $key );
		$label = esc_html( $field['label'] ) . ( ! empty( $field['required'] ) ? ' <span class="required" aria-hidden="true">*</span><span class="screen-reader-text">(required)</span>' : '' );
		echo '<p class="tmc-field tmc-field-' . esc_attr( $field['type'] ) . '">';

		switch ( $field['type'] ) {
			case 'datetime':
				printf( '<label for="%s">%s</label><br><input type="datetime-local" id="%1$s" name="tmc_fields[%s]" value="%s">', esc_attr( $id ), $label, esc_attr( $key ), esc_attr( $value ? substr( str_replace( ' ', 'T', $value ), 0, 16 ) : '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
				break;

			case 'select':
				printf( '<label for="%s">%s</label><br><select id="%1$s" name="tmc_fields[%s]">', esc_attr( $id ), $label, esc_attr( $key ) ); // phpcs:ignore WordPress.Security.EscapeOutput
				foreach ( $field['options'] as $option => $option_label ) {
					printf( '<option value="%s"%s>%s</option>', esc_attr( $option ), selected( $value, $option, false ), esc_html( $option_label ) );
				}
				echo '</select>';
				break;

			case 'documents':
				$ids = array_filter( array_map( 'intval', (array) $value ) );
				printf( '<span class="tmc-field-label">%s</span>', $label ); // phpcs:ignore WordPress.Security.EscapeOutput
				echo '<ul class="tmc-documents" data-for="' . esc_attr( $id ) . '">';
				foreach ( $ids as $attachment_id ) {
					printf(
						'<li data-id="%d">%s <button type="button" class="button-link tmc-doc-remove">Remove<span class="screen-reader-text"> %s</span></button></li>',
						(int) $attachment_id,
						esc_html( get_the_title( $attachment_id ) ),
						esc_html( get_the_title( $attachment_id ) )
					);
				}
				echo '</ul>';
				printf( '<input type="hidden" id="%s" name="tmc_fields[%s]" value="%s">', esc_attr( $id ), esc_attr( $key ), esc_attr( implode( ',', $ids ) ) );
				printf( '<button type="button" class="button tmc-doc-add" data-target="%s">Add documents</button>', esc_attr( $id ) );
				break;

			case 'departments':
				$lang        = function_exists( 'pll_get_post_language' ) ? pll_get_post_language( $post->ID ) : '';
				$departments = get_posts(
					array(
						'post_type'      => 'tmc_department',
						'posts_per_page' => 100,
						'orderby'        => 'title',
						'order'          => 'ASC',
						'post_status'    => array( 'publish', 'draft', 'pending' ),
						'lang'           => $lang ? $lang : '',
					)
				);
				echo '<fieldset><legend>' . $label . '</legend>'; // phpcs:ignore WordPress.Security.EscapeOutput
				if ( ! $departments ) {
					echo '<em>No departments yet.</em>';
				}
				foreach ( $departments as $department ) {
					printf(
						'<label style="display:block"><input type="checkbox" name="tmc_fields[%s][]" value="%d"%s> %s</label>',
						esc_attr( $key ),
						(int) $department->ID,
						checked( in_array( $department->ID, (array) $value, true ), true, false ),
						esc_html( $department->post_title )
					);
				}
				echo '<input type="hidden" name="tmc_fields[' . esc_attr( $key ) . '][]" value="0"></fieldset>';
				break;

			default:
				$input_type = array( 'url' => 'url', 'email' => 'email', 'number' => 'number' )[ $field['type'] ] ?? 'text';
				printf( '<label for="%s">%s</label><br><input type="%s" id="%1$s" name="tmc_fields[%s]" value="%s" class="widefat">', esc_attr( $id ), $label, esc_attr( $input_type ), esc_attr( $key ), esc_attr( $value ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		}

		if ( ! empty( $field['help'] ) ) {
			echo '<br><span class="description">' . esc_html( $field['help'] ) . '</span>';
		}
		echo '</p>';
	}
	echo '</div>';
}

add_action( 'save_post', 'tmc_fields_save', 10, 2 );
function tmc_fields_save( $post_id, $post ) {
	if ( ! isset( $_POST['tmc_fields_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['tmc_fields_nonce'] ), 'tmc_fields' ) ) {
		return;
	}
	if ( wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	$input = isset( $_POST['tmc_fields'] ) && is_array( $_POST['tmc_fields'] ) ? wp_unslash( $_POST['tmc_fields'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	foreach ( tmc_field_schema()[ $post->post_type ] ?? array() as $key => $field ) {
		$meta_key = '_' . $key;
		$raw      = $input[ $key ] ?? '';
		switch ( $field['type'] ) {
			case 'documents':
				$ids = array_values( array_filter( array_map( 'absint', explode( ',', (string) $raw ) ), fn( $id ) => 'attachment' === get_post_type( $id ) ) );
				$ids ? update_post_meta( $post_id, $meta_key, $ids ) : delete_post_meta( $post_id, $meta_key );
				break;
			case 'departments':
				delete_post_meta( $post_id, $meta_key );
				foreach ( array_unique( array_filter( array_map( 'absint', (array) $raw ) ) ) as $department_id ) {
					if ( 'tmc_department' === get_post_type( $department_id ) ) {
						add_post_meta( $post_id, $meta_key, $department_id );
					}
				}
				break;
			case 'select':
				$value = isset( $field['options'][ $raw ] ) ? $raw : '';
				'' !== $value ? update_post_meta( $post_id, $meta_key, $value ) : delete_post_meta( $post_id, $meta_key );
				break;
			default:
				$value = trim( (string) $raw );
				'' !== $value ? update_post_meta( $post_id, $meta_key, $value ) : delete_post_meta( $post_id, $meta_key ); // sanitised by register_post_meta
		}
	}
}

/** Warn (in the editor) when required details are missing, so incomplete items are not approved. */
add_action( 'admin_notices', 'tmc_fields_missing_notice' );
function tmc_fields_missing_notice() {
	$screen = get_current_screen();
	if ( ! $screen || 'post' !== $screen->base || empty( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}
	$post_id = absint( $_GET['post'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$missing = array();
	foreach ( tmc_field_schema()[ get_post_type( $post_id ) ] ?? array() as $key => $field ) {
		if ( ! empty( $field['required'] ) && ! tmc_field( $post_id, $key ) ) {
			$missing[] = $field['label'];
		}
	}
	if ( $missing ) {
		printf( '<div class="notice notice-warning"><p><strong>Details missing:</strong> %s.</p></div>', esc_html( implode( ', ', $missing ) ) );
	}
}

add_action( 'admin_enqueue_scripts', 'tmc_fields_assets' );
function tmc_fields_assets( $hook ) {
	if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
		return;
	}
	wp_enqueue_media();
	wp_enqueue_script( 'tmc-fields', plugins_url( 'admin/fields.js', __FILE__ ), array( 'jquery' ), TMC_CORE_VERSION, true );
	wp_add_inline_style( 'wp-admin', '.tmc-fields .required{color:#d63638}.tmc-documents{margin:.5em 0;list-style:disc;padding-left:1.5em}.tmc-field-label{font-weight:600}' );
}
