<?php
/**
 * Title: Home – Key figures
 * Slug: tmc/home-stats
 * Categories: tmc-home
 * Description: Four headline numbers with labels.
 */
echo serialize_blocks( array( tmc_section_stats( 'Key figures', array( array( '0', 'Label' ), array( '0', 'Label' ), array( '0', 'Label' ), array( '0', 'Label' ) ) ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput
