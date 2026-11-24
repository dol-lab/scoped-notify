<?php
/**
 * Test cleanup logic in Notification_Processor.
 *
 * @package Scoped_Notify
 */

use Scoped_Notify\Notification_Processor;

class Scoped_Notify_Cleanup_Test extends WP_UnitTestCase {

	/**
	 * Processor instance.
	 *
	 * @var Notification_Processor
	 */
	private $processor;

	public function setUp(): void {
		parent::setUp();
		global $wpdb;
		$this->processor = new Notification_Processor( $wpdb, SCOPED_NOTIFY_TABLE_QUEUE );

		// Ensure tables are clean
		$wpdb->query( 'TRUNCATE TABLE ' . SCOPED_NOTIFY_TABLE_QUEUE );
	}

	public function test_cleanup_old_notifications() {
		global $wpdb;

		// Retention period of 30 days
		$retention_period = 30 * 24 * 60 * 60;
		$now = time();

		// 1. Insert 'sent' notification OLDER than retention (should be deleted)
		// 31 days ago
		$old_sent_time = gmdate( 'Y-m-d H:i:s', $now - ($retention_period + 86400) );
		$wpdb->insert(
			SCOPED_NOTIFY_TABLE_QUEUE,
			array(
				'blog_id'       => 1,
				'user_id'       => 1,
				'object_id'     => 1,
				'object_type'   => 'post',
				'trigger_id'    => 1,
				'status'        => 'sent',
				'sent_at'       => $old_sent_time,
				'created_at'    => $old_sent_time,
				'reason'        => 'test',
			)
		);
		$id_old_sent = $wpdb->insert_id;

		// 2. Insert 'sent' notification WITHIN retention (should be kept)
		// 29 days ago
		$recent_sent_time = gmdate( 'Y-m-d H:i:s', $now - ($retention_period - 86400) );
		$wpdb->insert(
			SCOPED_NOTIFY_TABLE_QUEUE,
			array(
				'blog_id'       => 1,
				'user_id'       => 2,
				'object_id'     => 1,
				'object_type'   => 'post',
				'trigger_id'    => 1,
				'status'        => 'sent',
				'sent_at'       => $recent_sent_time,
				'created_at'    => $recent_sent_time,
				'reason'        => 'test',
			)
		);
		$id_recent_sent = $wpdb->insert_id;

		// 3. Insert 'pending' notification OLDER than retention (should be kept)
		$wpdb->insert(
			SCOPED_NOTIFY_TABLE_QUEUE,
			array(
				'blog_id'       => 1,
				'user_id'       => 3,
				'object_id'     => 2,
				'object_type'   => 'post',
				'trigger_id'    => 1,
				'status'        => 'pending',
				// Pending items don't have sent_at, or it's NULL
				'created_at'    => $old_sent_time,
				'reason'        => 'test',
			)
		);
		$id_old_pending = $wpdb->insert_id;

		// Run cleanup
		$deleted_count = $this->processor->cleanup_old_notifications( $retention_period );

		// Assertions
		$this->assertEquals( 1, $deleted_count, 'Should delete exactly 1 notification.' );

		// Verify $id_old_sent is gone
		$row_old = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . SCOPED_NOTIFY_TABLE_QUEUE . " WHERE queue_id = %d", $id_old_sent ) );
		$this->assertNull( $row_old, 'Old sent notification should be deleted.' );

		// Verify $id_recent_sent exists
		$row_recent = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . SCOPED_NOTIFY_TABLE_QUEUE . " WHERE queue_id = %d", $id_recent_sent ) );
		$this->assertNotNull( $row_recent, 'Recent sent notification should remain.' );

		// Verify $id_old_pending exists
		$row_pending = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . SCOPED_NOTIFY_TABLE_QUEUE . " WHERE queue_id = %d", $id_old_pending ) );
		$this->assertNotNull( $row_pending, 'Old pending notification should remain.' );
	}

	/**
	 * Batches run until nothing due is left, and leave rows that are not due.
	 */
	public function test_cleanup_deletes_all_due_rows_in_batches() {
		global $wpdb;
		$old    = gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS );
		$recent = gmdate( 'Y-m-d H:i:s' );
		$rows   = array_merge( array_fill( 0, 5, array( 'sent', $old ) ), array( array( 'sent', $recent ), array( 'pending', $old ) ) );
		foreach ( $rows as $i => list( $status, $time ) ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB
				SCOPED_NOTIFY_TABLE_QUEUE,
				array(
					'blog_id'     => 1,
					'user_id'     => $i + 1,
					'object_id'   => 1,
					'object_type' => 'post',
					'trigger_id'  => 1,
					'status'      => $status,
					'sent_at'     => $time,
					'created_at'  => $time,
					'reason'      => 'test',
				)
			);
		}
		$delete = new ReflectionMethod( Notification_Processor::class, 'delete_in_batches' );
		$delete->setAccessible( true );

		$this->assertSame( 5, $delete->invoke( $this->processor, 'sent', 'sent_at', gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ), 2 ) );
		$this->assertSame( array( 'pending', 'sent' ), $wpdb->get_col( 'SELECT status FROM ' . SCOPED_NOTIFY_TABLE_QUEUE . ' ORDER BY status' ) ); // phpcs:ignore WordPress.DB
	}
}
