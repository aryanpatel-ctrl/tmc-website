<?php
/**
 * Create content a DR drill must find after the restore: one published post and one uploaded file
 * (clearly labelled test data). Prints TMC_CANARY_UPLOAD=<path relative to wp-content/uploads>.
 *
 *   docker compose run --rm -T -e TMC_CANARY_TITLE="DR canary 123" wpcli --url=tmh.<base> \
 *     eval-file /tmc-scripts/dr/canary.php
 */

$title = getenv( 'TMC_CANARY_TITLE' ) ? getenv( 'TMC_CANARY_TITLE' ) : 'DR canary ' . gmdate( 'Y-m-d H:i:s' );
$post  = wp_insert_post(
	array(
		'post_type'    => 'post',
		'post_status'  => 'publish',
		'post_title'   => $title,
		'post_content' => '<p>Test content created by scripts/dr/canary.php to prove that a restore is complete. Safe to delete.</p>',
	),
	true
);
if ( is_wp_error( $post ) ) {
	WP_CLI::error( 'could not create the canary post: ' . $post->get_error_message() );
}
if ( function_exists( 'pll_set_post_language' ) ) {
	pll_set_post_language( $post, 'en' );
}

// A 1×1 transparent PNG: an allowed upload type on every site of the network.
$png    = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
$upload = wp_upload_bits( 'dr-canary-' . strtolower( wp_generate_password( 10, false ) ) . '.png', null, $png );
if ( ! empty( $upload['error'] ) ) {
	WP_CLI::error( 'could not store the canary file: ' . $upload['error'] );
}
wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => $title . ' (file)', 'post_status' => 'inherit' ), $upload['file'], $post );

$relative = ltrim( substr( wp_normalize_path( $upload['file'] ), strlen( wp_normalize_path( WP_CONTENT_DIR . '/uploads' ) ) ), '/' );
WP_CLI::log( 'TMC_CANARY_UPLOAD=' . $relative );
WP_CLI::success( "canary post #$post and file $relative created on " . home_url( '/' ) );
