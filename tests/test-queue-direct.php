<?php
/**
 * Test Notification_Queue::queue_direct() / cancel_pending() (collapse_key seams).
 *
 * @package Scoped_Notify
 */

use Scoped_Notify\Notification_Resolver;
use Scoped_Notify\Notification_Scheduler;
use Scoped_Notify\Notification_Queue;

/**
 * Direct enqueueing for stream-shaped sources (chat etc.).
 */
class Test_Queue_Direct extends WP_UnitTestCase {

	/**
	 * Queue instance.
	 *
	 * @var Notification_Queue
	 */
	private $queue;

	/**
	 * Database connection.
	 *
	 * @var \wpdb
	 */
	private $wpdb;

	/**
	 * A trigger id to hang queue rows on (FK).
	 *
	 * @var int
	 */
	private $trigger_id;

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();
		global $wpdb;
		$this->wpdb = $wpdb;

		$this->queue = new Notification_Queue(
			$this->createMock( Notification_Resolver::class ),
			$this->createMock( Notification_Scheduler::class ),
			$wpdb
		);

		$this->wpdb->query( 'DELETE FROM ' . SCOPED_NOTIFY_TABLE_QUEUE );
		$this->wpdb->query( 'DELETE FROM ' . SCOPED_NOTIFY_TABLE_TRIGGERS );
		$this->wpdb->insert(
			SCOPED_NOTIFY_TABLE_TRIGGERS,
			array(
				'trigger_key' => 'chat-dm',
				'channel'     => 'mail',
			),
			array( '%s', '%s' )
		);
		$this->trigger_id = (int) $this->wpdb->insert_id;
	}

	/**
	 * Queue rows for a user, ordered by queue_id.
	 *
	 * @param int $user_id User id.
	 * @return array
	 */
	private function rows( int $user_id ): array {
		return $this->wpdb->get_results(
			$this->wpdb->prepare( 'SELECT * FROM ' . SCOPED_NOTIFY_TABLE_QUEUE . ' WHERE user_id = %d ORDER BY queue_id', $user_id )
		);
	}

	public function test_queue_direct_inserts_rows_with_all_fields() {
		$send_after = gmdate( 'Y-m-d H:i:s', time() + 300 );

		$count = $this->queue->queue_direct(
			array( 5, 7 ),
			'chat-dm',
			42,
			$this->trigger_id,
			'chat-unread',
			0,
			$send_after,
			'chat:mail:dm:42',
			array( 'room_ref' => 'dm:42' )
		);

		$this->assertSame( 2, $count );

		$row = $this->rows( 5 )[0];
		$this->assertSame( 'chat-dm', $row->object_type );
		$this->assertSame( '42', (string) $row->object_id );
		$this->assertSame( 'chat-unread', $row->reason );
		$this->assertSame( 'chat:mail:dm:42', $row->collapse_key );
		$this->assertSame( 'immediate', $row->schedule_type );
		$this->assertSame( 'pending', $row->status );
		$this->assertSame( $send_after, $row->scheduled_send_time );
		$this->assertSame( array( 'room_ref' => 'dm:42' ), json_decode( $row->meta, true ) );
	}

	public function test_queue_direct_without_collapse_key_or_send_after() {
		$this->queue->queue_direct( array( 5 ), 'system-alert', 1, $this->trigger_id, 'alert', 0 );

		$row = $this->rows( 5 )[0];
		$this->assertNull( $row->collapse_key );
		$this->assertNull( $row->scheduled_send_time, 'No send_after means due immediately.' );

		// Without a collapse key nothing collapses.
		$this->queue->queue_direct( array( 5 ), 'system-alert', 1, $this->trigger_id, 'alert', 0 );
		$this->assertCount( 2, $this->rows( 5 ) );
	}

	public function test_collapse_key_keeps_first_pending_row_per_user() {
		$first = gmdate( 'Y-m-d H:i:s', time() + 100 );
		$later = gmdate( 'Y-m-d H:i:s', time() + 999 );

		$this->assertSame( 1, $this->queue->queue_direct( array( 5 ), 'chat-dm', 42, $this->trigger_id, 'chat-unread', 0, $first, 'chat:mail:dm:42' ) );
		$this->assertSame( 0, $this->queue->queue_direct( array( 5 ), 'chat-dm', 42, $this->trigger_id, 'chat-unread', 0, $later, 'chat:mail:dm:42' ) );

		$rows = $this->rows( 5 );
		$this->assertCount( 1, $rows );
		$this->assertSame( $first, $rows[0]->scheduled_send_time, 'First message wins: the grace window stays anchored at the burst start.' );
	}

	public function test_collapse_key_is_per_user_and_per_key() {
		$this->queue->queue_direct( array( 5 ), 'chat-dm', 42, $this->trigger_id, 'chat-unread', 0, null, 'chat:mail:dm:42' );

		// Another user with the same key inserts.
		$this->assertSame( 1, $this->queue->queue_direct( array( 7 ), 'chat-dm', 42, $this->trigger_id, 'chat-unread', 0, null, 'chat:mail:dm:42' ) );

		// The same user with a different key (e.g. push channel) inserts too.
		$this->assertSame( 1, $this->queue->queue_direct( array( 5 ), 'chat-dm', 42, $this->trigger_id, 'chat-unread', 0, null, 'chat:push:dm:42' ) );
	}

	public function test_collapse_only_applies_to_pending_rows() {
		$this->queue->queue_direct( array( 5 ), 'chat-dm', 42, $this->trigger_id, 'chat-unread', 0, null, 'chat:mail:dm:42' );

		$this->wpdb->update( SCOPED_NOTIFY_TABLE_QUEUE, array( 'status' => 'sent' ), array( 'user_id' => 5 ), array( '%s' ), array( '%d' ) );

		$this->assertSame( 1, $this->queue->queue_direct( array( 5 ), 'chat-dm', 42, $this->trigger_id, 'chat-unread', 0, null, 'chat:mail:dm:42' ) );
		$this->assertCount( 2, $this->rows( 5 ) );
	}

	public function test_cancel_pending_deletes_only_matching_pending_rows() {
		$this->queue->queue_direct( array( 5, 7 ), 'chat-dm', 42, $this->trigger_id, 'chat-unread', 0, null, 'chat:mail:dm:42' );
		$this->queue->queue_direct( array( 5 ), 'chat-dm', 43, $this->trigger_id, 'chat-unread', 0, null, 'chat:mail:dm:43' );

		$this->assertSame( 1, $this->queue->cancel_pending( 5, 'chat:mail:dm:42' ) );

		$this->assertCount( 1, $this->rows( 5 ), 'The other room key stays.' );
		$this->assertCount( 1, $this->rows( 7 ), 'Other users stay.' );

		// Sent rows are never cancelled.
		$this->wpdb->update( SCOPED_NOTIFY_TABLE_QUEUE, array( 'status' => 'sent' ), array( 'user_id' => 7 ), array( '%s' ), array( '%d' ) );
		$this->assertSame( 0, $this->queue->cancel_pending( 7, 'chat:mail:dm:42' ) );
	}

	public function test_queue_direct_with_no_users_is_a_noop() {
		$this->assertSame( 0, $this->queue->queue_direct( array(), 'chat-dm', 42, $this->trigger_id, 'chat-unread', 0 ) );
	}

	public function test_queue_direct_deduplicates_user_ids() {
		$this->assertSame( 1, $this->queue->queue_direct( array( 5, 5, '5' ), 'chat-dm', 42, $this->trigger_id, 'chat-unread', 0 ) );
	}

	public function test_digest_schedule_type_is_stored() {
		$send_after = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
		$this->queue->queue_direct( array( 5 ), 'chat-blog', 3, $this->trigger_id, 'chat-unread', 3, $send_after, 'chat:mail:blog:3', array(), 'daily' );

		$this->assertSame( 'daily', $this->rows( 5 )[0]->schedule_type );
	}
}
