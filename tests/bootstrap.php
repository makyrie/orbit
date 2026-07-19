<?php
/**
 * PHPUnit bootstrap file.
 *
 * @package Orbit
 */

// Composer autoloader.
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Define test-environment salt used by Orbit_Consent::record() to hash IPs.
// Real installs set this in wp-config.php; tests use a fixed value so
// hash chains are reproducible across runs.
defined( 'ORBIT_CONSENT_IP_SALT' ) || define( 'ORBIT_CONSENT_IP_SALT', 'orbit_test_salt_do_not_use_in_production' );

// Load WordPress test environment.
$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

// Check for wp-phpunit as a Composer dependency.
if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	$_tests_dir = dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find WordPress test library. Set WP_TESTS_DIR or install wp-phpunit/wp-phpunit.\n";
	exit( 1 );
}

// Give access to tests_add_filter() function.
require_once $_tests_dir . '/includes/functions.php';

/**
 * Manually load the plugin being tested.
 *
 * Also pull ActionScheduler into the test runtime. AS ships as a
 * WordPress plugin and its bootstrap is gated behind `plugins_loaded`,
 * so it must be required after WP has loaded but before `plugins_loaded`
 * fires — `muplugins_loaded` is the right seam. Without this, the
 * `as_*()` family of functions never get declared and tests covering
 * todo 119 (deferred new-user-notification dispatch) fall through to
 * the synchronous fallback path.
 */
tests_add_filter(
	'muplugins_loaded',
	function () {
		$action_scheduler = dirname( __DIR__ ) . '/vendor/woocommerce/action-scheduler/action-scheduler.php';
		if ( file_exists( $action_scheduler ) ) {
			require_once $action_scheduler;
		}
		require dirname( __DIR__ ) . '/orbit.php';
	}
);

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';

// Create plugin tables once for the entire test suite.
Orbit_Activator::create_tables();
