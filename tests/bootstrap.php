<?php
/**
 * PHPUnit bootstrap — loads the Composer autoloader and initialises WP_Mock.
 *
 * These are unit tests, not WordPress integration tests: no WordPress is loaded and no
 * database exists. Every WP function a class under test calls must be declared through
 * WP_Mock, which is the point — an untested call shows up as an error rather than
 * silently working against a real install.
 *
 * Run with: composer test
 *
 * @package AJR\StarterCore
 */

declare( strict_types=1 );

$autoload = dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! is_readable( $autoload ) ) {
	fwrite( STDERR, "Run `composer install` before the test suite.\n" );
	exit( 1 );
}

require_once $autoload;

WP_Mock::bootstrap();

require_once __DIR__ . '/Unit/wp-class-stubs.php';
