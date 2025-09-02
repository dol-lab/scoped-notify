<?php
/**
 * Tests for cron schedule helper in scoped-notify plugin.
 *
 * @package Scoped_Notify
 */

class Scoped_Notify_Cron_Schedules_Test extends WP_UnitTestCase {

	/**
	 * Ensure the custom 'every_five_minutes' schedule is added when missing.
	 */
	public function test_adds_every_five_minutes_when_missing() {
		$input  = array();
		$output = \Scoped_Notify\add_cron_schedules( $input );

		$this->assertArrayHasKey( 'every_five_minutes', $output );
		$this->assertIsArray( $output['every_five_minutes'] );
		$this->assertArrayHasKey( 'interval', $output['every_five_minutes'] );
		$this->assertEquals( 300, $output['every_five_minutes']['interval'] );
		$this->assertArrayHasKey( 'display', $output['every_five_minutes'] );
	}

	/**
	 * Ensure an existing 'every_five_minutes' schedule is preserved (not overwritten).
	 */
	public function test_preserves_existing_every_five_minutes_schedule() {
		$input = array(
			'every_five_minutes' => array(
				'interval' => 123,
				'display'  => 'Custom Display',
			),
		);

		$output = \Scoped_Notify\add_cron_schedules( $input );

		$this->assertArrayHasKey( 'every_five_minutes', $output );
		$this->assertEquals( 123, $output['every_five_minutes']['interval'] );
		$this->assertEquals( 'Custom Display', $output['every_five_minutes']['display'] );
	}
}
