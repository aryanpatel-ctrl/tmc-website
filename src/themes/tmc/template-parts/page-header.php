<?php
/**
 * Page title band with breadcrumbs. Pass ['title' => ..., 'intro' => ...] via get_template_part args.
 */

$tmc_title = $args['title'] ?? '';
$tmc_intro = $args['intro'] ?? '';
?>
<div class="page-header">
	<div class="container">
		<?php tmc_breadcrumbs(); ?>
		<h1 class="page-title"><?php echo wp_kses_post( $tmc_title ); ?></h1>
		<?php if ( $tmc_intro ) : ?>
			<p class="page-intro"><?php echo esc_html( $tmc_intro ); ?></p>
		<?php endif; ?>
	</div>
</div>
