<?php
/**
 * Title: Home – TMC network
 * Slug: tmc/home-network
 * Categories: tmc-home
 * Description: Cards for every website in the TMC ecosystem (updates automatically).
 */
echo serialize_blocks( array( tmc_section_network( 'Our network', 'Tata Memorial Centre serves patients across India through a hub-and-spoke network of hospitals and research centres.' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput
