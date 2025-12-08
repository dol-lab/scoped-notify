<?php
/**
 * Test retry_failed_items logic.
 *
 * @package Scoped_Notify
 */

class Scoped_Notify_Retry_Failed_Test extends WP_UnitTestCase {

	/**
	 * Processor instance.
	 *
	 * @var \Scoped_Notify\Notification_Processor
	 */
	private $processor;

	public function setUp(): void {
		parent::setUp();
		global $wpdb;
		$this->processor = new \Scoped_Notify\Notification_Processor( $wpdb, SCOPED_NOTIFY_TABLE_QUEUE );

		// Ensure tables are clean
		$wpdb->query( 'TRUNCATE TABLE ' . SCOPED_NOTIFY_TABLE_QUEUE );
	}

	public function test_retry_failed_increments_count() {
		global $wpdb;

		// 1. Create a user
		$user_id = $this->factory()->user->create();

		// 2. Insert a failed item
		$blog_id    = get_current_blog_id();
		$trigger_id = 1; // Assuming trigger ID 1 exists, or insert it.
		$object_id  = 999; // Dummy object ID

		$wpdb->insert(
			SCOPED_NOTIFY_TABLE_QUEUE,
			array(
				'blog_id'       => $blog_id,
				'user_id'       => $user_id,
				'object_id'     => $object_id,
				'object_type'   => 'post',
				'trigger_id'    => $trigger_id,
				'status'        => 'failed', // Start as failed
				'created_at'    => current_time( 'mysql' ),
				'reason'        => 'new_post',
				'schedule_type' => 'immediate',
				'meta'          => null,
			)
		);
		$queue_id = $wpdb->insert_id;

		// 3. Retry Failed Items
		$count = $this->processor->move_failed_to_pending();

		// 4. Assertions - Round 1
		$this->assertEquals( 1, $count, 'Should have reset 1 item.' );

		$item = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . SCOPED_NOTIFY_TABLE_QUEUE . " WHERE queue_id = %d", $queue_id ) );
		$this->assertEquals( 'pending', $item->status, 'Status should be pending.' );

		$meta = json_decode( $item->meta, true );
		$this->assertIsArray( $meta, 'Meta should be an array.' );
		$this->assertEquals( 1, $meta['fail_count'], 'fail_count should be 1.' );


		// 5. Fail it again manually to test increment
		$wpdb->update(
			SCOPED_NOTIFY_TABLE_QUEUE,
			array( 'status' => 'failed' ),
			array( 'queue_id' => $queue_id )
		);

		// 6. Retry again
		$count = $this->processor->move_failed_to_pending();

		// 7. Assertions - Round 2
		$this->assertEquals( 1, $count, 'Should have reset 1 item.' );

		$item = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . SCOPED_NOTIFY_TABLE_QUEUE . " WHERE queue_id = %d", $queue_id ) );
		$this->assertEquals( 'pending', $item->status, 'Status should be pending.' );

		$meta = json_decode( $item->meta, true );
		$this->assertEquals( 2, $meta['fail_count'], 'fail_count should be 2.' );
	}
}
