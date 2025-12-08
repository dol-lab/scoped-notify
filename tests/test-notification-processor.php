<?php
/**
 * Test Notification_Processor logic, specifically chunking and timeouts.
 *
 * @package Scoped_Notify
 */

/**
 * Testable subclass to mock time functions.
 */
class Testable_Notification_Processor extends \Scoped_Notify\Notification_Processor {
	/**
	 * Mock current time.
	 * @var int
	 */
	public $mock_current_time = 0;

	/**
	 * Mock max execution time.
	 * @var int
	 */
	public $mock_max_execution_time = 300;

	protected function get_current_time(): int {
		return $this->mock_current_time ?: time();
	}

	protected function get_max_execution_time(): int {
		return $this->mock_max_execution_time;
	}
}

class Scoped_Notify_Notification_Processor_Test extends WP_UnitTestCase {

	/**
	 * Processor instance.
	 *
	 * @var Testable_Notification_Processor
	 */
	private $processor;

	public function setUp(): void {
		parent::setUp();
		global $wpdb;
		$this->processor = new Testable_Notification_Processor( $wpdb, SCOPED_NOTIFY_TABLE_QUEUE );

		// Ensure tables are clean
		$wpdb->query( 'TRUNCATE TABLE ' . SCOPED_NOTIFY_TABLE_QUEUE );
	}

	public function tearDown(): void {
		parent::tearDown();
		delete_site_option( SCOPED_NOTIFY_MAIL_CHUNK_SIZE );
		remove_all_filters( 'scoped_notify_third_party_send_mail_notification' );
	}

	public function test_user_chunking_logic() {
		global $wpdb;

		// 1. Create Users
		$user_ids = $this->factory()->user->create_many( 5 );

		// 2. Create Notification Item
		$post_id = $this->factory()->post->create();

		// Clear queue polluted by the hook on post creation
		$wpdb->query( 'TRUNCATE TABLE ' . SCOPED_NOTIFY_TABLE_QUEUE );

		$blog_id    = get_current_blog_id();
		$trigger_id = 1;

		// Insert users into queue
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

		// 3. Set Chunk Size to 2
		update_site_option( SCOPED_NOTIFY_MAIL_CHUNK_SIZE, 2 );

		// 4. Mock Sending to count chunks
		$chunk_counts = 0;
		add_filter(
			'scoped_notify_third_party_send_mail_notification',
			function ( $sent, $chunk ) use ( &$chunk_counts ) {
				$chunk_counts++;
				return true; // Mark as sent so we don't actually mail
			},
			10,
			2
		);

		// 5. Process
		$this->processor->mock_current_time = time(); // Set a baseline time
		$processed = $this->processor->process_queue( 10 );

		// 6. Assertions
		// 5 users, chunk size 2 => 3 chunks (2, 2, 1)
		$this->assertEquals( 3, $chunk_counts, 'Should have processed 3 chunks.' );
		$this->assertEquals( 5, $processed, 'Should have processed 5 users.' );

		// Verify all users are 'sent'
		$pending_count = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . SCOPED_NOTIFY_TABLE_QUEUE . " WHERE status = 'pending'" );
		$sent_count    = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . SCOPED_NOTIFY_TABLE_QUEUE . " WHERE status = 'sent'" );

		$this->assertEquals( 0, $pending_count );
		$this->assertEquals( 5, $sent_count );
	}

	public function test_timeout_handling() {
		global $wpdb;

		// 1. Create Users
		$user_ids = $this->factory()->user->create_many( 3 );

		// 2. Create Notification Item
		$post_id = $this->factory()->post->create();

		// Clear queue polluted by the hook on post creation
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

		// 3. Set Chunk Size to 1
		update_site_option( SCOPED_NOTIFY_MAIL_CHUNK_SIZE, 1 );

		// 4. Setup Mock Time
		$start_time = 1000000;
		$this->processor->mock_current_time = $start_time;
		// Set max execution time to 10 seconds.
		// The processor calculates limit as 80% of max = 8 seconds.
		$this->processor->mock_max_execution_time = 10;

		// Hook to advance time on each send
		add_filter(
			'scoped_notify_third_party_send_mail_notification',
			function ( $sent, $chunk ) {
				// Advance time by 9 seconds.
				// 1st chunk: Time becomes 1000009. Elapsed = 9s.
				// Limit is 8s.
				// Check is done at START of loop (time - start > limit).
				// First chunk processes fine.
				// Loop continues.
				// Next check: (1000009 - 1000000) = 9 > 8. Break.
				$this->processor->mock_current_time += 9;
				return true;
			},
			10,
			2
		);

		// 5. Process
		$processed = $this->processor->process_queue( 10 );

		// 6. Assertions
		// Should have processed only 1 user (the first chunk)
		$this->assertEquals( 1, $processed, 'Should have stopped after first chunk due to timeout.' );

		// Verify statuses
		$sent_count       = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . SCOPED_NOTIFY_TABLE_QUEUE . " WHERE status = 'sent'" );
		$pending_count    = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . SCOPED_NOTIFY_TABLE_QUEUE . " WHERE status = 'pending'" );
		$processing_count = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . SCOPED_NOTIFY_TABLE_QUEUE . " WHERE status = 'processing'" );

		$this->assertEquals( 1, $sent_count, 'First user should be sent.' );
		$this->assertEquals( 2, $pending_count, 'Remaining users should be pending.' );
		$this->assertEquals( 0, $processing_count, 'No users should be left in processing.' );
	}
}