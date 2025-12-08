<?php
/**
 * Test handling of missing source objects (users, posts, etc.).
 *
 * @package Scoped_Notify
 */

class Scoped_Notify_Missing_Source_Test extends WP_UnitTestCase {

	/**
	 * Processor instance.
	 *
	 * @var \Scoped_Notify\Notification_Processor
	 */
	private $processor;

	public function setUp(): void {
		parent::setUp();
		global $wpdb;
		$table = defined('SCOPED_NOTIFY_TABLE_QUEUE') ? SCOPED_NOTIFY_TABLE_QUEUE : 'scoped_notify_queue';
		$this->processor = new \Scoped_Notify\Notification_Processor( $wpdb, $table );

		// Ensure tables are clean
		$wpdb->query( 'TRUNCATE TABLE ' . $table );
	}

	public function tearDown(): void {
		parent::tearDown();
		delete_site_option( SCOPED_NOTIFY_MAIL_CHUNK_SIZE );
		remove_all_filters( 'scoped_notify_third_party_send_mail_notification' );
	}

	public function test_missing_users_marked_as_orphaned() {
		global $wpdb;
		$table = defined('SCOPED_NOTIFY_TABLE_QUEUE') ? SCOPED_NOTIFY_TABLE_QUEUE : 'scoped_notify_queue';

		// 1. Create Users
		$user_ids = $this->factory()->user->create_many( 3 );
		$kept_user_id = $user_ids[0];
		$deleted_user_ids = [ $user_ids[1], $user_ids[2] ];

		$post_id = $this->factory()->post->create();

		// Clear any auto-queued items from creation hooks
		$wpdb->query( 'TRUNCATE TABLE ' . $table );

		$blog_id = get_current_blog_id();
		$trigger_id = 1;

		// 2. Delete users FIRST.
		// The plugin's hooks will run, but since there are no queue items yet, nothing is cleaned up.
		foreach ( $deleted_user_ids as $uid ) {
			if ( is_multisite() ) {
				wpmu_delete_user( $uid );
			} else {
				wp_delete_user( $uid );
			}
			// Ensure gone from DB just in case
			$wpdb->delete( $wpdb->users, array( 'ID' => $uid ) );
		}
		wp_cache_flush();

		// 3. Insert items into queue.
		// Now we have queue items pointing to non-existent users (orphaned state).
		foreach ( $user_ids as $uid ) {
			$wpdb->insert(
				$table,
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

		// 4. Process
		add_filter( 'scoped_notify_third_party_send_mail_notification', '__return_true' );

		// This should process 1 valid user and find 2 orphaned ones
		$processed = $this->processor->process_queue( 10 );



		// 5. Assertions
		$sent_count     = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $table . " WHERE status = 'sent'" );
		$orphaned_count = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $table . " WHERE status = 'orphaned'" );

		$this->assertEquals( 1, $processed, 'Should have successfully processed 1 valid user.' );
		$this->assertEquals( 1, $sent_count, 'One user should be sent.' );
		$this->assertEquals( 2, $orphaned_count, 'Two users should be marked as orphaned.' );
	}

	public function test_missing_object_marked_as_orphaned() {
		global $wpdb;
		$table = defined('SCOPED_NOTIFY_TABLE_QUEUE') ? SCOPED_NOTIFY_TABLE_QUEUE : 'scoped_notify_queue';

		// 1. Create Users
		$user_ids = $this->factory()->user->create_many( 2 );

		// 2. Create Post and Delete it FIRST.
		$post_id = $this->factory()->post->create();
		$deleted_post_id = $post_id;
		wp_delete_post( $deleted_post_id, true );

		// Clear any auto-queued items from creation hooks
		$wpdb->query( 'TRUNCATE TABLE ' . $table );

		$blog_id    = get_current_blog_id();
		$trigger_id = 1;

		// 3. Insert items pointing to deleted post
		foreach ( $user_ids as $uid ) {
			$wpdb->insert(
				$table,
				array(
					'blog_id'       => $blog_id,
					'user_id'       => $uid,
					'object_id'     => $deleted_post_id,
					'object_type'   => 'post',
					'trigger_id'    => $trigger_id,
					'status'        => 'pending',
					'created_at'    => current_time( 'mysql' ),
					'reason'        => 'new_post',
					'schedule_type' => 'immediate',
				)
			);
		}

		// 4. Process
		$processed = $this->processor->process_queue( 10 );

		// 5. Assertions
		$this->assertEquals( 0, $processed, 'Should have processed 0 users successfully.' );

		$orphaned_count = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $table . " WHERE status = 'orphaned'" );

		$this->assertEquals( 2, $orphaned_count, 'Users for missing object should be orphaned.' );
	}
}
