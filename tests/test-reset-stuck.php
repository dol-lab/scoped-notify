<?php
/**
 * Tests for resetting stuck items in Notification_Processor.
 *
 * @package Scoped_Notify
 */

namespace Scoped_Notify\Tests;

use Scoped_Notify\Notification_Processor;

/**
 * Test_Reset_Stuck
 */
class Test_Reset_Stuck extends \WP_UnitTestCase {

	/**
	 * @var Notification_Processor
	 */
	protected $processor;

	/**
	 * @var \wpdb
	 */
	protected $wpdb;

	public function set_up() {
		parent::set_up();
		global $wpdb;
		$this->wpdb = $wpdb;
		$this->processor = new Notification_Processor( $wpdb, SCOPED_NOTIFY_TABLE_QUEUE );
		
		// Ensure tables are created (usually done by bootstrap/install, but just in case)
		// For unit tests, we often assume the schema matches production.
		// If 'updated_at' column is missing in production, it should be missing here too
		// if the test setup uses the same CREATE TABLE logic.
	}

	public function test_reset_stuck_items_with_created_at() {
		// Insert a stuck item: processing, created 2 hours ago, no schedule
		$this->wpdb->insert(
			SCOPED_NOTIFY_TABLE_QUEUE,
			array(
				'user_id'       => 1,
				'blog_id'       => 1,
				'object_id'     => 1,
				'object_type'   => 'post',
				'trigger_id'    => 1,
				'reason'        => 'test',
				'schedule_type' => 'immediate',
				'status'        => 'processing',
				'created_at'    => gmdate( 'Y-m-d H:i:s', time() - 7200 ), // 2 hours ago
				'sent_at'       => null,
			)
		);
		$id = $this->wpdb->insert_id;

		// Verify it is processing
		$status = $this->wpdb->get_var( $this->wpdb->prepare( "SELECT status FROM " . SCOPED_NOTIFY_TABLE_QUEUE . " WHERE queue_id = %d", $id ) );
		$this->assertEquals( 'processing', $status );

		// Reset items stuck for more than 30 mins (1800s)
		$count = $this->processor->reset_stuck_items( 1800 );

		// Verify result
		$this->assertEquals( 1, $count, 'Should reset 1 item' );
		$new_status = $this->wpdb->get_var( $this->wpdb->prepare( "SELECT status FROM " . SCOPED_NOTIFY_TABLE_QUEUE . " WHERE queue_id = %d", $id ) );
		$this->assertEquals( 'pending', $new_status );
	}

	public function test_reset_stuck_items_respects_schedule() {
		// Item created 2 hours ago, but scheduled for 10 mins ago.
		// If cutoff is 30 mins, this should NOT be reset (since it became valid only 10 mins ago).
		// Wait, if it's processing, it means it started processing AFTER schedule time.
		// So if schedule time was 10 mins ago, it could only have started processing 10 mins ago.
		// So it is NOT stuck (stuck > 30 mins).
		
		$this->wpdb->insert(
			SCOPED_NOTIFY_TABLE_QUEUE,
			array(
				'user_id'             => 1,
				'blog_id'             => 1,
				'object_id'           => 1,
				'object_type'         => 'post',
				'trigger_id'          => 1,
				'reason'              => 'test',
				'schedule_type'       => 'daily',
				'status'              => 'processing',
				'created_at'          => gmdate( 'Y-m-d H:i:s', time() - 7200 ), // 2 hours ago
				'scheduled_send_time' => gmdate( 'Y-m-d H:i:s', time() - 600 ), // 10 mins ago
				'sent_at'             => null,
			)
		);
		$id_not_stuck = $this->wpdb->insert_id;

		// Item scheduled 2 hours ago. Processing. Stuck.
		$this->wpdb->insert(
			SCOPED_NOTIFY_TABLE_QUEUE,
			array(
				'user_id'             => 1,
				'blog_id'             => 1,
				'object_id'           => 1,
				'object_type'         => 'post',
				'trigger_id'          => 1,
				'reason'              => 'test',
				'schedule_type'       => 'daily',
				'status'              => 'processing',
				'created_at'          => gmdate( 'Y-m-d H:i:s', time() - 10000 ),
				'scheduled_send_time' => gmdate( 'Y-m-d H:i:s', time() - 7200 ), // 2 hours ago
				'sent_at'             => null,
			)
		);
		$id_stuck = $this->wpdb->insert_id;

		$count = $this->processor->reset_stuck_items( 1800 ); // 30 mins

		$this->assertEquals( 1, $count );
		
		$status_not_stuck = $this->wpdb->get_var( $this->wpdb->prepare( "SELECT status FROM " . SCOPED_NOTIFY_TABLE_QUEUE . " WHERE queue_id = %d", $id_not_stuck ) );
		$this->assertEquals( 'processing', $status_not_stuck, 'Recent scheduled item should remain processing' );

		$status_stuck = $this->wpdb->get_var( $this->wpdb->prepare( "SELECT status FROM " . SCOPED_NOTIFY_TABLE_QUEUE . " WHERE queue_id = %d", $id_stuck ) );
		$this->assertEquals( 'pending', $status_stuck, 'Old scheduled item should be reset' );
	}

	public function test_reset_stuck_items_with_sent_at() {
		// Insert a stuck item: processing, created 2 hours ago, has sent_at
		$this->wpdb->insert(
			SCOPED_NOTIFY_TABLE_QUEUE,
			array(
				'user_id'       => 1,
				'blog_id'       => 1,
				'object_id'     => 1,
				'object_type'   => 'post',
				'trigger_id'    => 1,
				'reason'        => 'test',
				'schedule_type' => 'immediate',
				'status'        => 'processing',
				'created_at'    => gmdate( 'Y-m-d H:i:s', time() - 7200 ), // 2 hours ago
				'sent_at'       => gmdate( 'Y-m-d H:i:s', time() - 3600 ), // 1 hour ago
			)
		);
		$id = $this->wpdb->insert_id;

		// Verify it is processing
		$status = $this->wpdb->get_var( $this->wpdb->prepare( "SELECT status FROM " . SCOPED_NOTIFY_TABLE_QUEUE . " WHERE queue_id = %d", $id ) );
		$this->assertEquals( 'processing', $status );

		// Reset items stuck for more than 30 mins (1800s)
		$count = $this->processor->reset_stuck_items( 1800 );

		// Verify result: Should be marked as 'sent'
		$this->assertEquals( 1, $count, 'Should update 1 item' );
		$new_status = $this->wpdb->get_var( $this->wpdb->prepare( "SELECT status FROM " . SCOPED_NOTIFY_TABLE_QUEUE . " WHERE queue_id = %d", $id ) );
		$this->assertEquals( 'sent', $new_status );
	}
}