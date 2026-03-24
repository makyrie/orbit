<?php
/**
 * WordPress test configuration.
 *
 * Uses the Local Sites MySQL configuration.
 *
 * @package Orbit
 */

define( 'DB_NAME', 'local' );
define( 'DB_USER', 'root' );
define( 'DB_PASSWORD', 'root' );
define( 'DB_HOST', 'localhost:/Users/sarahlewis/Library/Application Support/Local/run/GxPSrxhJp/mysql/mysqld.sock' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_';

define( 'WP_TESTS_DOMAIN', 'orbit.local' );
define( 'WP_TESTS_EMAIL', 'admin@orbit.local' );
define( 'WP_TESTS_TITLE', 'Orbit Tests' );
define( 'WP_PHP_BINARY', 'php' );

define( 'ABSPATH', dirname( __DIR__, 4 ) . '/' );
