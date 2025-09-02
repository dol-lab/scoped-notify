<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package Scoped_Notify
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

// Forward custom PHPUnit Polyfills configuration to PHPUnit bootstrap file.
$_phpunit_polyfills_path = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );
if ( false !== $_phpunit_polyfills_path ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $_phpunit_polyfills_path );
}

if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php, have you run .tests/bin/install-wp-tests.sh ?" . PHP_EOL;
	exit( 1 );
}

// Manually load the plugin's Composer autoloader. THIS IS THE KEY FIX.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Give access to tests_add_filter() function.
require_once "{$_tests_dir}/includes/functions.php";

/**
 * Manually load the plugin being tested.
 */
function _manually_load_plugin() {
	require dirname( __DIR__ ) . '/scoped-notify.php';
}
tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

/**
 * Directly runs the plugin's activation and uninstall routines.
 * This is more reliable in the test environment than using activate_plugin().
 */
function _install_and_activate_plugin() {
	// Ensure a clean state for each test run by calling the uninstall function.
	\Scoped_Notify\uninstall_plugin();

	// Now, directly call your plugin's activation function to set up tables.
	\Scoped_Notify\activate_plugin();
}

// Hook our direct installer function into the test setup process.
tests_add_filter( 'wp_loaded', '_install_and_activate_plugin' );


define( 'WP_TESTS_MULTISITE', true );
define( 'WP_TESTS_SUBDOMAIN_INSTALL', false );

// Start up the WP testing environment.
require "{$_tests_dir}/includes/bootstrap.php";
