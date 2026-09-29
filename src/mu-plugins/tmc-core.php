<?php
/**
 * Plugin Name: TMC Core
 * Description: TMC website ecosystem — editorial roles, review workflow and tamper-evident audit log.
 * Version:     0.1.0
 * Author:      TMC Website Project
 * License:     GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

define( 'TMC_CORE_VERSION', '0.1.0' );

foreach ( array( 'roles', 'workflow', 'audit-log' ) as $tmc_module ) {
	require __DIR__ . "/tmc-core/{$tmc_module}.php";
}
unset( $tmc_module );
