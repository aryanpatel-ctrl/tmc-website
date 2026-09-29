<?php
/**
 * Site footer: contact, quick links, TMC network, Government of India links, policies, last updated.
 */
?>
</main>

<footer class="site-footer">
	<div class="container footer-grid">
		<section class="footer-col" aria-labelledby="footer-contact">
			<h2 class="footer-heading" id="footer-contact"><?php esc_html_e( 'Contact us', 'tmc' ); ?></h2>
			<p class="footer-org"><?php echo esc_html( tmc_site_name() ); ?></p>
			<?php tmc_contact_details(); ?>
			<?php tmc_social_links(); ?>
		</section>

		<nav class="footer-col" aria-labelledby="footer-quick">
			<h2 class="footer-heading" id="footer-quick"><?php esc_html_e( 'Quick links', 'tmc' ); ?></h2>
			<?php tmc_simple_menu( 'footer-quick', 'footer-links' ); ?>
		</nav>

		<nav class="footer-col" aria-labelledby="footer-network">
			<h2 class="footer-heading" id="footer-network"><?php esc_html_e( 'TMC network', 'tmc' ); ?></h2>
			<ul class="footer-links">
				<?php foreach ( tmc_network_sites() as $tmc_site ) : ?>
					<li><a href="<?php echo esc_url( $tmc_site['url'] ); ?>"<?php echo $tmc_site['current'] ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $tmc_site['name'] ); ?></a></li>
				<?php endforeach; ?>
			</ul>
		</nav>

		<nav class="footer-col" aria-labelledby="footer-important">
			<h2 class="footer-heading" id="footer-important"><?php esc_html_e( 'Important links', 'tmc' ); ?></h2>
			<ul class="footer-links">
				<li><?php echo tmc_external_link( 'https://www.india.gov.in/', __( 'National Portal of India', 'tmc' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></li>
				<li><?php echo tmc_external_link( 'https://dae.gov.in/', __( 'Department of Atomic Energy', 'tmc' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></li>
				<li><?php echo tmc_external_link( 'https://www.mygov.in/', 'MyGov' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></li>
				<li><?php echo tmc_external_link( 'https://www.digitalindia.gov.in/', __( 'Digital India', 'tmc' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?></li>
			</ul>
		</nav>
	</div>

	<div class="footer-bottom">
		<div class="container">
			<nav aria-label="<?php esc_attr_e( 'Website policies', 'tmc' ); ?>">
				<?php tmc_simple_menu( 'footer-policies', 'policy-links' ); ?>
			</nav>
			<div class="footer-meta">
				<p>
					<?php
					/* translators: 1: year, 2: organisation name */
					echo esc_html( sprintf( __( '© %1$s %2$s. All rights reserved.', 'tmc' ), wp_date( 'Y' ), tmc_site_name( get_main_site_id() ) ) );
					?>
					<?php
					/* translators: %s: organisation name */
					echo esc_html( sprintf( __( 'Website content is owned, updated and managed by %s.', 'tmc' ), tmc_site_name( get_main_site_id() ) ) );
					?>
				</p>
				<?php $tmc_updated = tmc_last_updated(); ?>
				<?php if ( $tmc_updated ) : ?>
					<p class="last-updated">
						<?php
						/* translators: %s: date */
						echo esc_html( sprintf( __( 'Last updated: %s', 'tmc' ), $tmc_updated ) );
						?>
					</p>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<a class="back-to-top" href="#main"><span aria-hidden="true">↑</span> <?php esc_html_e( 'Back to top', 'tmc' ); ?></a>
</footer>

<?php wp_footer(); ?>
</body>
</html>
