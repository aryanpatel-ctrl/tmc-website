<?php
/**
 * Title: Home – What's new and News
 * Slug: tmc/home-updates
 * Categories: tmc-home
 * Description: Notice board (category "notices") beside the latest news (category "news").
 */
echo serialize_blocks( array( tmc_section_updates( "What's new", 'News & events' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput
