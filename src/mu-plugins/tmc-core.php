<?php
/**
 * Plugin Name: TMC Core
 * Description: TMC website ecosystem — editorial roles, review workflow, tamper-evident audit log, content types and automatic expiry.
 * Version:     0.1.0
 * Author:      TMC Website Project
 * License:     GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

define( 'TMC_CORE_VERSION', '0.1.0' );

// Every module in tmc-core/ is loaded (sorted). Modules only declare functions and hooks, so
// load order does not matter; a new feature is a new file, not an edit to this list.
foreach ( glob( __DIR__ . '/tmc-core/*.php' ) as $tmc_module ) {
	require $tmc_module;
}
unset( $tmc_module );
