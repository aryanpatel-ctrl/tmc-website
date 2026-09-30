<?php
/**
 * Title: Home – Quick access tiles
 * Slug: tmc/home-quick-links
 * Categories: tmc-home
 * Description: Eight icon tiles linking to the most used services.
 */
echo serialize_blocks( // phpcs:ignore WordPress.Security.EscapeOutput
	array(
		tmc_section_quick(
			'Quick links',
			array(
				array( 'calendar', 'Appointments', home_url( '/patient-care/appointments/' ) ),
				array( 'guide', 'Patient guide', home_url( '/patient-care/patient-guide/' ) ),
				array( 'department', 'Departments', home_url( '/departments/' ) ),
				array( 'research', 'Research', home_url( '/research/' ) ),
				array( 'education', 'Education', home_url( '/education/' ) ),
				array( 'careers', 'Careers', home_url( '/careers/' ) ),
				array( 'tender', 'Tenders', home_url( '/tenders/' ) ),
				array( 'donate', 'Donate', home_url( '/donate/' ) ),
			)
		),
	)
);
