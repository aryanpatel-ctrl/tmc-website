<?php
/**
 * GIGW accessibility bar. "Skip to main content" is the first focusable element on every page.
 * Text size and contrast are remembered per visitor (assets/js/main.js).
 */

$tmc_hi            = 'hi' === tmc_current_lang();
$tmc_screen_reader = get_page_by_path( $tmc_hi ? 'screen-reader-sahayata' : 'screen-reader-access' );
$tmc_sitemap       = get_page_by_path( $tmc_hi ? 'sitemap-hi' : 'sitemap' );
?>
<div class="a11y-bar">
	<div class="container a11y-row">
		<ul class="a11y-links">
			<li><a class="skip-link" href="#main"><?php esc_html_e( 'Skip to main content', 'tmc' ); ?></a></li>
			<?php if ( $tmc_screen_reader ) : ?>
				<li class="hide-sm"><a href="<?php echo esc_url( get_permalink( $tmc_screen_reader ) ); ?>"><?php esc_html_e( 'Screen Reader Access', 'tmc' ); ?></a></li>
			<?php endif; ?>
			<?php if ( $tmc_sitemap ) : ?>
				<li class="hide-sm"><a href="<?php echo esc_url( get_permalink( $tmc_sitemap ) ); ?>"><?php esc_html_e( 'Sitemap', 'tmc' ); ?></a></li>
			<?php endif; ?>
		</ul>

		<ul class="a11y-tools">
			<li>
				<div class="tool-group" role="group" aria-label="<?php esc_attr_e( 'Text size', 'tmc' ); ?>">
					<button type="button" data-tmc-font="90" aria-pressed="false" title="<?php esc_attr_e( 'Decrease text size', 'tmc' ); ?>"><span aria-hidden="true">A-</span><span class="screen-reader-text"><?php esc_html_e( 'Decrease text size', 'tmc' ); ?></span></button>
					<button type="button" data-tmc-font="100" aria-pressed="true" title="<?php esc_attr_e( 'Normal text size', 'tmc' ); ?>"><span aria-hidden="true">A</span><span class="screen-reader-text"><?php esc_html_e( 'Normal text size', 'tmc' ); ?></span></button>
					<button type="button" data-tmc-font="115" aria-pressed="false" title="<?php esc_attr_e( 'Increase text size', 'tmc' ); ?>"><span aria-hidden="true">A+</span><span class="screen-reader-text"><?php esc_html_e( 'Increase text size', 'tmc' ); ?></span></button>
					<button type="button" data-tmc-font="130" aria-pressed="false" title="<?php esc_attr_e( 'Largest text size', 'tmc' ); ?>"><span aria-hidden="true">A++</span><span class="screen-reader-text"><?php esc_html_e( 'Largest text size', 'tmc' ); ?></span></button>
				</div>
			</li>
			<li>
				<div class="tool-group" role="group" aria-label="<?php esc_attr_e( 'Contrast', 'tmc' ); ?>">
					<button type="button" class="contrast-normal" data-tmc-contrast="normal" aria-pressed="true" title="<?php esc_attr_e( 'Standard contrast', 'tmc' ); ?>"><span aria-hidden="true">A</span><span class="screen-reader-text"><?php esc_html_e( 'Standard contrast', 'tmc' ); ?></span></button>
					<button type="button" class="contrast-high" data-tmc-contrast="high" aria-pressed="false" title="<?php esc_attr_e( 'High contrast', 'tmc' ); ?>"><span aria-hidden="true">A</span><span class="screen-reader-text"><?php esc_html_e( 'High contrast', 'tmc' ); ?></span></button>
				</div>
			</li>
			<li class="lang-item">
				<span class="screen-reader-text"><?php esc_html_e( 'Language', 'tmc' ); ?>:</span>
				<?php tmc_language_switcher(); ?>
			</li>
		</ul>
	</div>
</div>
