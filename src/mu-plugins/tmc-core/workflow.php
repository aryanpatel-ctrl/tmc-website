<?php
/**
 * Editorial workflow (tender §4.6): Content Editors submit, Reviewer / Publishers approve.
 *
 * WordPress already provides the "Pending Review" status and hides the Publish button from
 * users without publish rights. On top of that this adds:
 *   - "Awaiting your review" dashboard queue + admin-bar counter for reviewers
 *   - "My submissions" dashboard panel for content editors
 *   - a review note from reviewer to author, shown when content is returned for changes
 *   - email notifications on submit / return / approval
 */

defined( 'ABSPATH' ) || exit;

const TMC_META_REVIEW_NOTE = '_tmc_review_note';
const TMC_META_RETURNED    = '_tmc_returned';

function tmc_workflow_post_types() {
	$types = get_post_types( array( 'public' => true, 'show_ui' => true ) );
	unset( $types['attachment'] );
	return array_values( $types );
}

function tmc_user_can_review( $user_id = 0 ) {
	$user_id = $user_id ? $user_id : get_current_user_id();
	return user_can( $user_id, 'publish_posts' ) || user_can( $user_id, 'publish_pages' );
}

function tmc_pending_count() {
	$count = 0;
	foreach ( tmc_workflow_post_types() as $type ) {
		$count += (int) wp_count_posts( $type )->pending;
	}
	return $count;
}

function tmc_post_language_name( $post_id ) {
	return function_exists( 'pll_get_post_language' ) ? (string) pll_get_post_language( $post_id, 'name' ) : '';
}

/* ---------------------------------------------------------------- status changes */

add_action( 'transition_post_status', 'tmc_workflow_on_transition', 10, 3 );
function tmc_workflow_on_transition( $new_status, $old_status, $post ) {
	if ( $new_status === $old_status || ! in_array( $post->post_type, tmc_workflow_post_types(), true ) ) {
		return;
	}
	$actor = get_current_user_id();

	if ( 'pending' === $new_status ) {
		delete_post_meta( $post->ID, TMC_META_RETURNED );
		tmc_workflow_mail_reviewers( $post );
	} elseif ( 'pending' === $old_status && 'draft' === $new_status && $actor && (int) $post->post_author !== $actor ) {
		update_post_meta( $post->ID, TMC_META_RETURNED, array( 'by' => $actor, 'at' => time() ) );
		tmc_workflow_mail_author( $post, 'returned for changes' );
	} elseif ( 'pending' === $old_status && in_array( $new_status, array( 'publish', 'future' ), true ) ) {
		delete_post_meta( $post->ID, TMC_META_RETURNED );
		tmc_workflow_mail_author( $post, 'publish' === $new_status ? 'approved and published' : 'approved and scheduled' );
	}
}

function tmc_workflow_mail_reviewers( WP_Post $post ) {
	$emails = array();
	foreach ( get_users( array( 'capability__in' => array( 'publish_posts', 'publish_pages' ), 'fields' => array( 'ID', 'user_email' ) ) ) as $reviewer ) {
		if ( (int) $reviewer->ID !== (int) $post->post_author ) {
			$emails[] = $reviewer->user_email;
		}
	}
	if ( ! $emails ) {
		return;
	}
	$author = get_the_author_meta( 'display_name', $post->post_author );
	wp_mail(
		$emails,
		sprintf( '[%s] Review requested: %s', get_bloginfo( 'name' ), $post->post_title ),
		sprintf(
			"%s has submitted \"%s\" for review.\n\nReview it here:\n%s\n",
			$author,
			$post->post_title,
			admin_url( 'post.php?post=' . $post->ID . '&action=edit' )
		)
	);
}

function tmc_workflow_mail_author( WP_Post $post, $outcome ) {
	$author = get_userdata( $post->post_author );
	if ( ! $author || (int) $author->ID === get_current_user_id() ) {
		return;
	}
	wp_mail(
		$author->user_email,
		sprintf( '[%s] "%s" was %s', get_bloginfo( 'name' ), $post->post_title, $outcome ),
		sprintf(
			"Your submission \"%s\" was %s by %s.\n\nOpen it to see any review note:\n%s\n",
			$post->post_title,
			$outcome,
			wp_get_current_user()->display_name,
			admin_url( 'post.php?post=' . $post->ID . '&action=edit' )
		)
	);
}

/* ---------------------------------------------------------------- review note */

add_action( 'add_meta_boxes', 'tmc_review_note_register', 10, 2 );
function tmc_review_note_register( $post_type, $post ) {
	if ( in_array( $post_type, tmc_workflow_post_types(), true ) ) {
		add_meta_box( 'tmc_review_note', 'Review note', 'tmc_review_note_box', $post_type, 'side', 'high' );
	}
}

function tmc_review_note_box( WP_Post $post ) {
	$note     = (string) get_post_meta( $post->ID, TMC_META_REVIEW_NOTE, true );
	$returned = get_post_meta( $post->ID, TMC_META_RETURNED, true );

	if ( $returned ) {
		printf(
			'<p style="padding:6px 8px;background:#fcf0f1;border-left:3px solid #d63638;margin-top:0">Returned for changes by <strong>%s</strong>, %s ago.</p>',
			esc_html( get_the_author_meta( 'display_name', $returned['by'] ) ),
			esc_html( human_time_diff( $returned['at'] ) )
		);
	}

	if ( tmc_user_can_review() ) {
		wp_nonce_field( 'tmc_review_note', 'tmc_review_note_nonce' );
		printf(
			'<textarea name="tmc_review_note" rows="4" style="width:100%%" placeholder="What should the author change? Save, then switch the status back to Draft to return it.">%s</textarea>',
			esc_textarea( $note )
		);
	} elseif ( '' !== $note ) {
		echo '<p style="white-space:pre-wrap;margin:0">' . esc_html( $note ) . '</p>';
	} else {
		echo '<p style="margin:0;color:#646970">No review notes.</p>';
	}
}

add_action( 'save_post', 'tmc_review_note_save', 10, 2 );
function tmc_review_note_save( $post_id, $post ) {
	if ( ! isset( $_POST['tmc_review_note_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['tmc_review_note_nonce'] ), 'tmc_review_note' ) ) {
		return;
	}
	if ( wp_is_post_revision( $post_id ) || ! tmc_user_can_review() ) {
		return;
	}
	$note = sanitize_textarea_field( wp_unslash( $_POST['tmc_review_note'] ?? '' ) );
	if ( '' === $note ) {
		delete_post_meta( $post_id, TMC_META_REVIEW_NOTE );
	} else {
		update_post_meta( $post_id, TMC_META_REVIEW_NOTE, $note );
	}
}

/* ---------------------------------------------------------------- dashboard */

add_action( 'wp_dashboard_setup', 'tmc_workflow_dashboard' );
function tmc_workflow_dashboard() {
	if ( tmc_user_can_review() ) {
		wp_add_dashboard_widget( 'tmc_review_queue', 'Awaiting your review', 'tmc_widget_review_queue' );
		$id = 'tmc_review_queue';
	} elseif ( current_user_can( 'edit_posts' ) ) {
		wp_add_dashboard_widget( 'tmc_my_submissions', 'My submissions', 'tmc_widget_my_submissions' );
		$id = 'tmc_my_submissions';
	} else {
		return;
	}

	// Put the workflow panel first.
	global $wp_meta_boxes;
	$core = $wp_meta_boxes['dashboard']['normal']['core'];
	$wp_meta_boxes['dashboard']['normal']['core'] = array( $id => $core[ $id ] ) + $core; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
}

function tmc_widget_review_queue() {
	$items = get_posts(
		array(
			'post_type'      => tmc_workflow_post_types(),
			'post_status'    => 'pending',
			'posts_per_page' => 20,
			'orderby'        => 'modified',
			'order'          => 'ASC',
			'lang'           => '', // all languages
		)
	);
	if ( ! $items ) {
		echo '<p>Nothing is waiting for review.</p>';
		return;
	}
	echo '<table class="widefat striped"><thead><tr><th>Title</th><th>Type</th><th>Language</th><th>Submitted by</th><th>Waiting</th></tr></thead><tbody>';
	foreach ( $items as $item ) {
		printf(
			'<tr><td><a href="%s"><strong>%s</strong></a><br><a href="%s" target="_blank" rel="noopener">Preview</a></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
			esc_url( admin_url( 'post.php?post=' . $item->ID . '&action=edit' ) ),
			esc_html( $item->post_title ? $item->post_title : '(no title)' ),
			esc_url( get_preview_post_link( $item ) ),
			esc_html( get_post_type_object( $item->post_type )->labels->singular_name ),
			esc_html( tmc_post_language_name( $item->ID ) ),
			esc_html( get_the_author_meta( 'display_name', $item->post_author ) ),
			esc_html( human_time_diff( (int) get_post_modified_time( 'U', true, $item ) ) )
		);
	}
	echo '</tbody></table>';
}

function tmc_widget_my_submissions() {
	$items = get_posts(
		array(
			'post_type'      => tmc_workflow_post_types(),
			'post_status'    => array( 'draft', 'pending', 'future', 'publish' ),
			'author'         => get_current_user_id(),
			'posts_per_page' => 10,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'lang'           => '',
		)
	);
	if ( ! $items ) {
		echo '<p>You have no content yet. Create a page and use <strong>Submit for Review</strong> when it is ready.</p>';
		return;
	}
	$labels = array(
		'draft'   => array( 'Draft', '#646970' ),
		'pending' => array( 'Waiting for review', '#996800' ),
		'future'  => array( 'Approved — scheduled', '#2271b1' ),
		'publish' => array( 'Published', '#008a20' ),
	);
	echo '<table class="widefat striped"><thead><tr><th>Title</th><th>Status</th><th>Updated</th></tr></thead><tbody>';
	foreach ( $items as $item ) {
		list( $label, $color ) = $labels[ $item->post_status ];
		$note                  = '';
		if ( 'draft' === $item->post_status && get_post_meta( $item->ID, TMC_META_RETURNED, true ) ) {
			list( $label, $color ) = array( 'Returned for changes', '#d63638' );
			$note                  = (string) get_post_meta( $item->ID, TMC_META_REVIEW_NOTE, true );
		}
		printf(
			'<tr><td><a href="%s">%s</a>%s</td><td><strong style="color:%s">%s</strong></td><td>%s ago</td></tr>',
			esc_url( admin_url( 'post.php?post=' . $item->ID . '&action=edit' ) ),
			esc_html( $item->post_title ? $item->post_title : '(no title)' ),
			$note ? '<br><em>' . esc_html( $note ) . '</em>' : '',
			esc_attr( $color ),
			esc_html( $label ),
			esc_html( human_time_diff( (int) get_post_modified_time( 'U', true, $item ) ) )
		);
	}
	echo '</tbody></table>';
}

/* ---------------------------------------------------------------- admin bar */

add_action( 'admin_bar_menu', 'tmc_workflow_admin_bar', 80 );
function tmc_workflow_admin_bar( WP_Admin_Bar $bar ) {
	if ( ! is_user_logged_in() || ! tmc_user_can_review() ) {
		return;
	}
	$count = tmc_pending_count();
	$bar->add_node(
		array(
			'id'    => 'tmc-review-queue',
			'title' => '<span class="ab-icon dashicons dashicons-yes-alt" style="top:2px"></span><span class="ab-label">Review queue (' . (int) $count . ')</span>',
			'href'  => admin_url( 'index.php#tmc_review_queue' ),
			'meta'  => array( 'title' => $count ? "$count item(s) waiting for review" : 'Nothing waiting for review' ),
		)
	);
}
