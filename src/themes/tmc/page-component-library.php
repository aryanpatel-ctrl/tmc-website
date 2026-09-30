<?php
/**
 * Living component library (/component-library/ on the TMC site). See inc/component-library.php.
 */

get_header();

while ( have_posts() ) :
	the_post();
	get_template_part(
		'template-parts/page-header',
		null,
		array(
			'title' => get_the_title(),
			'intro' => __( 'Design tokens and every component of the TMC websites, rendered live with the names developers use.', 'tmc' ),
		)
	);
	?>
	<div class="container page-body is-wide component-library">
		<div class="entry-content cl-intro">
			<?php the_content(); ?>
			<p class="callout">
				<?php
				printf(
					/* translators: 1: theme version, 2: date */
					esc_html__( 'Theme version %1$s, generated %2$s. Provisional tokens: TMC\'s Annexure A design tokens replace the values in theme.json (colours, type scale, spacing) and main.css; every component below and on all six websites follows automatically.', 'tmc' ),
					esc_html( TMC_THEME_VERSION ),
					esc_html( tmc_date( time() ) )
				);
				?>
			</p>
		</div>
		<?php echo tmc_component_library_html(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped while building ?>
	</div>
	<?php
endwhile;

get_footer();
