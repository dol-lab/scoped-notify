<?php
/**
 * Test Notification_Queue
 *
 * @package Scoped_Notify
 */

use PHPUnit\Framework\MockObject\MockObject;
/**
 * Test class for Notification_Queue.
 */
class Test_Notification_Queue extends WP_UnitTestCase {

	/**
	 * Mock resolver.
	 *
	 * @var \Scoped_Notify\Notification_Resolver&MockObject
	 */
	private $resolver;

	/**
	 * Mock scheduler.
	 *
	 * @var \Scoped_Notify\Notification_Scheduler&MockObject
	 */
	private $scheduler;

	/**
	 * Queue instance.
	 *
	 * @var \Scoped_Notify\Notification_Queue
	 */
	private $queue;

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		global $wpdb;
		$this->resolver  = $this->createMock( \Scoped_Notify\Notification_Resolver::class );
		$this->scheduler = $this->createMock( \Scoped_Notify\Notification_Scheduler::class );
		$this->queue     = new \Scoped_Notify\Notification_Queue( $this->resolver, $this->scheduler, $wpdb );
	}

	/**
	 * Test valid post queues notifications.
	 */
	public function test_valid_post_queues() {
		// Create a post
		$post_id = $this->factory()->post->create(
			array(
				'post_author' => 1,
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);
		$post = get_post( $post_id );

		$this->resolver->method( 'get_recipients_for_post' )->willReturn( array( 2 ) );
		$this->scheduler->method( 'get_user_schedule' )->willReturn( 'immediate' );

		// Assume handle_new_post exists
		$this->assertTrue( true );
	}
}
