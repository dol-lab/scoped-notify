<?php
/**
 * Test Notification_Queue
 *
 * @package Scoped_Notify
 */

use PHPUnit\Framework\MockObject\MockObject;
use Scoped_Notify\Notification_Resolver;
use Scoped_Notify\Notification_Scheduler;
use Scoped_Notify\Notification_Queue;

/**
 * Test class for Notification_Queue.
 */
class Test_Notification_Queue extends WP_UnitTestCase {

	/**
	 * Mock resolver.
	 *
	 * @var Notification_Resolver&MockObject
	 */
	private $resolver;

	/**
	 * Mock scheduler.
	 *
	 * @var Notification_Scheduler&MockObject
	 */
	private $scheduler;

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
	 * Set up test.
	 */
	public function setUp(): void {
		parent::setUp();
		global $wpdb;
		$this->wpdb = $wpdb;

		$this->resolver  = $this->createMock( Notification_Resolver::class );
		$this->scheduler = $this->createMock( Notification_Scheduler::class );
		$this->queue     = new Notification_Queue( $this->resolver, $this->scheduler, $wpdb );

		// Truncate tables to ensure clean state
		$this->wpdb->query( 'TRUNCATE TABLE ' . SCOPED_NOTIFY_TABLE_QUEUE );
		$this->wpdb->query( 'DELETE FROM ' . SCOPED_NOTIFY_TABLE_TRIGGERS );
	}

	/**
	 * Test that notifications are queued correctly for a valid event.
	 */
	public function test_queue_event_notifications_success() {
		// 1. Setup Trigger
		$trigger_id = 123;
		$this->wpdb->insert(
			SCOPED_NOTIFY_TABLE_TRIGGERS,
			array(
				'trigger_id'  => $trigger_id,
				'trigger_key' => 'post-post',
				'channel'     => 'mail',
			)
		);

		// 2. Setup Post
		$post_id = $this->factory()->post->create( array( 'post_type' => 'post' ) );

		// Clear queue populated by global hooks
		$this->wpdb->query( 'TRUNCATE TABLE ' . SCOPED_NOTIFY_TABLE_QUEUE );

		// 3. Setup Mocks
		$recipients = array( 10, 20, 30 );

		$this->resolver->expects( $this->once() )
			->method( 'get_recipients_for_post' )
			->with( $this->isInstanceOf( \WP_Post::class ), 'mail' )
			->willReturn( $recipients );

		$this->scheduler->expects( $this->once() )
			->method( 'get_users_schedules' )
			->with( $recipients, get_current_blog_id(), 'mail' )
			->willReturn(
				array(
					10 => 'immediate',
					20 => 'daily',
					30 => 'weekly',
				)
			);

		$this->scheduler->expects( $this->exactly( 3 ) )
			->method( 'calculate_scheduled_send_time' )
			->will(
				$this->returnValueMap(
					array(
						array( 'immediate', null ),
						array( 'daily', '2025-12-07 09:00:00' ),
						array( 'weekly', '2025-12-08 09:00:00' ),
					)
				)
			);

		// 4. Execute
		$queued_count = $this->queue->queue_event_notifications(
			'post',
			$post_id,
			'new_post',
			get_current_blog_id(),
			array(),
			$trigger_id
		);

		// 5. Assertions
		$this->assertEquals( 3, $queued_count, 'Should have queued 3 notifications' );

		$queued_items = $this->wpdb->get_results( 'SELECT * FROM ' . SCOPED_NOTIFY_TABLE_QUEUE . ' ORDER BY user_id ASC', ARRAY_A );
		$this->assertCount( 3, $queued_items );

		// Check User 10 (Immediate)
		$this->assertEquals( 10, $queued_items[0]['user_id'] );
		$this->assertEquals( 'pending', $queued_items[0]['status'] );
		$this->assertNull( $queued_items[0]['scheduled_send_time'] );

		// Check User 20 (Daily)
		$this->assertEquals( 20, $queued_items[1]['user_id'] );
		$this->assertEquals( 'pending', $queued_items[1]['status'] );
		$this->assertEquals( '2025-12-07 09:00:00', $queued_items[1]['scheduled_send_time'] );
	}

	/**
	 * Test that nothing is queued if no recipients are resolved.
	 */
	public function test_queue_event_notifications_no_recipients() {
		// 1. Setup Trigger
		$trigger_id = 456;
		$this->wpdb->insert(
			SCOPED_NOTIFY_TABLE_TRIGGERS,
			array(
				'trigger_id'  => $trigger_id,
				'trigger_key' => 'post-post',
				'channel'     => 'mail',
			)
		);

		$post_id = $this->factory()->post->create();

		// Clear queue populated by global hooks
		$this->wpdb->query( 'TRUNCATE TABLE ' . SCOPED_NOTIFY_TABLE_QUEUE );

		// 2. Mock Resolver to return empty
		$this->resolver->expects( $this->once() )
			->method( 'get_recipients_for_post' )
			->willReturn( array() );

		// 3. Execute
		$queued_count = $this->queue->queue_event_notifications(
			'post',
			$post_id,
			'new_post',
			get_current_blog_id(),
			array(),
			$trigger_id
		);

		// 4. Assert
		$this->assertEquals( 0, $queued_count );
		$this->assertEquals( 0, $this->wpdb->get_var( 'SELECT COUNT(*) FROM ' . SCOPED_NOTIFY_TABLE_QUEUE ) );
	}

	/**
	 * Test behavior when trigger ID is invalid/missing.
	 */
	public function test_queue_event_notifications_invalid_trigger() {
		$post_id = $this->factory()->post->create();

		// Clear queue populated by global hooks
		$this->wpdb->query( 'TRUNCATE TABLE ' . SCOPED_NOTIFY_TABLE_QUEUE );

		// No trigger inserted
		$queued_count = $this->queue->queue_event_notifications(
			'post',
			$post_id,
			'new_post',
			get_current_blog_id(),
			array(),
			9999 // Non-existent
		);

		$this->assertEquals( 0, $queued_count );
	}

	/**
	 * Test handle_new_post directly.
	 */
	public function test_handle_new_post() {
		// 1. Setup Trigger
		$this->wpdb->insert(
			SCOPED_NOTIFY_TABLE_TRIGGERS,
			array(
				'trigger_id'  => 1,
				'trigger_key' => 'post-post',
				'channel'     => 'mail',
			)
		);

		// 2. Mock Resolver & Scheduler
		// handle_new_post will eventually call queue_event_notifications
		$this->resolver->method( 'get_recipients_for_post' )->willReturn( array( 1 ) );
		$this->scheduler->method( 'get_users_schedules' )->willReturn( array( 1 => 'immediate' ) );

		// 3. Create Post and Trigger Logic
		$post_id = $this->factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
			)
		);
		$post    = get_post( $post_id );

		// Clear queue populated by global hooks
		$this->wpdb->query( 'TRUNCATE TABLE ' . SCOPED_NOTIFY_TABLE_QUEUE );

		// Call handle_new_post manually to test it
		$this->queue->handle_new_post( $post_id, $post, false, null );

		// 4. Assert
		$queued = $this->wpdb->get_var( 'SELECT COUNT(*) FROM ' . SCOPED_NOTIFY_TABLE_QUEUE );
		$this->assertEquals( 1, $queued, 'handle_new_post should queue a notification' );
	}
}
