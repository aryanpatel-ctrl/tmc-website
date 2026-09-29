<?php
/**
 * Title: Home – Hero banner
 * Slug: tmc/home-hero
 * Categories: tmc-home
 * Description: Headline, short introduction and two call-to-action buttons.
 */
echo serialize_blocks( array( tmc_section_hero( 'Tata Memorial Centre', 'Comprehensive, compassionate cancer care for all', 'Introduce the website in one or two sentences.', array( array( 'Patient care', home_url( '/patient-care/' ) ), array( 'Contact us', home_url( '/contact-us/' ), true ) ) ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput
