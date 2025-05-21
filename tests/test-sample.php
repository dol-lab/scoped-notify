<?php
/**
 * Class SampleTest
 *
 * @package Spaces_Core
 */

$path = '../vendor/wordpress/wordpress/tests/phpunit/includes/testcase.php';
if ( file_exists( $path ) ) {
	/**
	 * This path is not available for ci (which has it's own copy of WordPress tests...).
	 */
	require_once $path;
}

/**
 * Sample test case.
 */
class SampleTest extends PHPUnit\Framework\TestCase {

	/**
	 * A single example test.
	 */
	public function test_sample() {
		// Replace this with some actual testing code.
		$this->assertTrue( true );

	}

}
