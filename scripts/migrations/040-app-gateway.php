<?php
/**
 * 040 — TMC application gateway, application front ends, location map (W4).
 *
 *   1. Demo environments only (TMC_DEMO=1): register the four DEMO mock backend services in the network
 *      registry, once — if the registry has never been saved. Production starts with an empty registry;
 *      TMC registers its approved endpoints (docs/integration/gateway.md).
 *   2. Place the Appointment, Results, Online form, Donate and Location map blocks on the existing
 *      Appointments, Results, Feedback, Donate and Contact Us pages (EN + HI) and set the site's
 *      city-level map position — see tmc_w4_seed_site() in the theme (inc/apps-pages.php).
 *
 * Fresh installs run this before the pages exist; seed-site-structure.php then places the blocks.
 */

if ( ! function_exists( 'tmc_w4_seed_site' ) || ! function_exists( 'tmc_apps_demo_registry' ) ) {
	WP_CLI::warning( 'migration 040 needs the TMC theme and tmc-core to be active on ' . home_url() );
	return false;
}

if ( tmc_is_demo() && null === get_site_option( TMC_APPS_OPTION, null ) ) {
	update_site_option( TMC_APPS_OPTION, tmc_apps_demo_registry() );
	tmc_audit(
		'app_registry_changed',
		array(
			'object_type'  => 'network_option',
			'object_title' => TMC_APPS_OPTION,
			'details'      => array_fill_keys( array_keys( tmc_apps_demo_registry() ), 'added (DEMO mock backend)' ),
		)
	);
	WP_CLI::log( '    application registry: DEMO mock services registered' );
}

foreach ( tmc_w4_seed_site() as $line ) {
	WP_CLI::log( "    $line" );
}
return true;
