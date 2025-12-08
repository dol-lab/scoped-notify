<?php
/**
 * Test Notification_Processor new features: explicit time limit, retry failed, cleanup failed.
 *
 * @package Scoped_Notify
 */

/**
 * Testable subclass to mock time functions.
 */
class Testable_Notification_Processor_Features extends \Scoped_Notify\Notification_Processor {
	/**
	 * Mock current time.
	 * @var int
	 */
	public $mock_current_time = 0;

	protected function get_current_time(): int {
		return $this->mock_current_time ?: time();
	}
}

class Scoped_Notify_Notification_Processor_Features_Test extends WP_UnitTestCase {

	/**
	 * Processor instance.
	 *
	 * @var Testable_Notification_Processor_Features
	 */
	private $processor;

	public function setUp(): void {
		parent::setUp();
		global $wpdb;
		$this->processor = new Testable_Notification_Processor_Features( $wpdb, SCOPED_NOTIFY_TABLE_QUEUE );

		// Ensure tables are clean
		$wpdb->query( 'TRUNCATE TABLE ' . SCOPED_NOTIFY_TABLE_QUEUE );
	}

	public function tearDown(): void {
		parent::tearDown();
		delete_site_option( SCOPED_NOTIFY_MAIL_CHUNK_SIZE );
		remove_all_filters( 'scoped_notify_third_party_send_mail_notification' );
	}

	public function test_explicit_time_limit() {
		global $wpdb;

		// 1. Create Users
		$user_ids = $this->factory()->user->create_many( 3 );
		$post_id = $this->factory()->post->create();

		$wpdb->query( 'TRUNCATE TABLE ' . SCOPED_NOTIFY_TABLE_QUEUE );

		$blog_id    = get_current_blog_id();
		$trigger_id = 1;

		foreach ( $user_ids as $uid ) {
			$wpdb->insert(
				SCOPED_NOTIFY_TABLE_QUEUE,
				array(
					'blog_id'       => $blog_id,
					'user_id'       => $uid,
					'object_id'     => $post_id,
					'object_type'   => 'post',
					'trigger_id'    => $trigger_id,
					'status'        => 'pending',
					'created_at'    => current_time( 'mysql' ),
					'reason'        => 'new_post',
					'schedule_type' => 'immediate',
				)
			);
		}

		// Chunk size 1 to check timeout between chunks
		update_site_option( SCOPED_NOTIFY_MAIL_CHUNK_SIZE, 1 );

		// Start time
		$start_time = 1000000;
		$this->processor->mock_current_time = $start_time;

		// Hook to advance time: +5 seconds per chunk
		add_filter(
			'scoped_notify_third_party_send_mail_notification',
			function ( $sent, $chunk ) {
				$this->processor->mock_current_time += 5;
				return true;
			},
			10,
			2
		);

		// Call process_queue with explicit limit of 4 seconds.
		// First chunk starts at 0s elapsed.
		// Inside send, time +5s.
		// Next loop check: elapsed 5s > 4s limit. Should break.
		$processed = $this->processor->process_queue( 10, 4 );

		$this->assertEquals( 1, $processed, 'Should stop after first chunk because 5s > 4s limit.' );

		$sent_count = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . SCOPED_NOTIFY_TABLE_QUEUE . " WHERE status = 'sent'" );
		$this->assertEquals( 1, $sent_count );
	}

	public function test_retry_failed_items() {
		global $wpdb;

		$user_ids = $this->factory()->user->create_many( 3 );
		$post_id = $this->factory()->post->create();
		$wpdb->query( 'TRUNCATE TABLE ' . SCOPED_NOTIFY_TABLE_QUEUE );

		$blog_id = get_current_blog_id();

		// Insert 2 failed items and 1 pending item
		$wpdb->insert( SCOPED_NOTIFY_TABLE_QUEUE, [ 'blog_id' => $blog_id, 'user_id' => $user_ids[0], 'object_id' => $post_id, 'object_type' => 'post', 'trigger_id' => 1, 'status' => 'failed', 'created_at' => current_time( 'mysql' ), 'reason' => 'test', 'schedule_type' => 'immediate' ] );
		$wpdb->insert( SCOPED_NOTIFY_TABLE_QUEUE, [ 'blog_id' => $blog_id, 'user_id' => $user_ids[1], 'object_id' => $post_id, 'object_type' => 'post', 'trigger_id' => 1, 'status' => 'failed', 'created_at' => current_time( 'mysql' ), 'reason' => 'test', 'schedule_type' => 'immediate' ] );
		$wpdb->insert( SCOPED_NOTIFY_TABLE_QUEUE, [ 'blog_id' => $blog_id, 'user_id' => $user_ids[2], 'object_id' => $post_id, 'object_type' => 'post', 'trigger_id' => 1, 'status' => 'pending', 'created_at' => current_time( 'mysql' ), 'reason' => 'test', 'schedule_type' => 'immediate' ] );

		$count = $this->processor->move_failed_to_pending();

		$this->assertEquals( 2, $count, 'Should return count of reset items.' );

		$pending_count = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . SCOPED_NOTIFY_TABLE_QUEUE . " WHERE status = 'pending'" );
		$this->assertEquals( 3, $pending_count, 'All failed items should now be pending.' );
	}

	public function test_cleanup_failed_notifications() {
		global $wpdb;

		$user_id = $this->factory()->user->create();
		$post_id = $this->factory()->post->create();
		$wpdb->query( 'TRUNCATE TABLE ' . SCOPED_NOTIFY_TABLE_QUEUE );
		$blog_id = get_current_blog_id();

		// Insert failed item older than retention (e.g., 31 days old)
		$old_date = gmdate( 'Y-m-d H:i:s', time() - ( 31 * DAY_IN_SECONDS ) );
		$wpdb->insert( SCOPED_NOTIFY_TABLE_QUEUE, [
			'blog_id' => $blog_id, 'user_id' => $user_id, 'object_id' => $post_id, 'object_type' => 'post', 'trigger_id' => 1,
			'status' => 'failed', 'created_at' => $old_date, 'reason' => 'test', 'schedule_type' => 'immediate'
		] );

		// Insert failed item newer than retention (e.g., 29 days old)
		$new_date = gmdate( 'Y-m-d H:i:s', time() - ( 29 * DAY_IN_SECONDS ) );
		$wpdb->insert( SCOPED_NOTIFY_TABLE_QUEUE, [
			'blog_id' => $blog_id, 'user_id' => $user_id, 'object_id' => $post_id, 'object_type' => 'post', 'trigger_id' => 1,
			'status' => 'failed', 'created_at' => $new_date, 'reason' => 'test', 'schedule_type' => 'immediate'
		] );

		// Retention 30 days
		$retention = 90 * DAY_IN_SECONDS;
		$deleted = $this->processor->cleanup_failed_notifications( $retention );

		$this->assertEquals( 1, $deleted, 'Should delete 1 old failed item.' );

		$remaining_count = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . SCOPED_NOTIFY_TABLE_QUEUE . " WHERE status = 'failed'" );
		$this->assertEquals( 1, $remaining_count, 'Should leave newer failed item.' );
	}
}
