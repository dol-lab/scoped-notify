<?php
/**
 * Test stats logic.
 *
 * @package Scoped_Notify
 */

use Scoped_Notify\Notification_Processor;

class Scoped_Notify_Stats_Test extends WP_UnitTestCase {

	private $processor;

	public function setUp(): void {
		parent::setUp();
		global $wpdb;
		$this->processor = new Notification_Processor( $wpdb, SCOPED_NOTIFY_TABLE_QUEUE );
		$wpdb->query( 'TRUNCATE TABLE ' . SCOPED_NOTIFY_TABLE_QUEUE );
		delete_site_option( 'scoped_notify_total_sent_count' );
	}

	public function tearDown(): void {
		parent::tearDown();
		remove_filter( 'scoped_notify_third_party_send_mail_notification', '__return_true' );
	}

	public function test_stats_structure_and_increment() {
		global $wpdb;

		// 1. Create Users
		$user_ids = $this->factory()->user->create_many( 3 );
		$post_id = $this->factory()->post->create();

		// Clear queue
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

		// Mock sending to avoid actual mail
		add_filter( 'scoped_notify_third_party_send_mail_notification', '__return_true' );

		// Process
		$this->processor->process_queue( 10 );

		// Check option
		$stats = get_site_option( 'scoped_notify_total_sent_count' );
		$this->assertIsArray( $stats, 'Stats should be an array.' );
		$this->assertArrayHasKey( 'count', $stats );
		$this->assertArrayHasKey( 'since', $stats );
		$this->assertEquals( 3, $stats['count'], 'Total sent count should be 3.' );
		$this->assertIsInt( $stats['since'] );
	}

	public function test_stats_migration() {
		global $wpdb;

		// Seed old integer data
		update_site_option( 'scoped_notify_total_sent_count', 100 );

		// Create 1 user/notification
		$user_id = $this->factory()->user->create();
		$post_id = $this->factory()->post->create();
		$wpdb->query( 'TRUNCATE TABLE ' . SCOPED_NOTIFY_TABLE_QUEUE ); // clear hooks

		$wpdb->insert(
			SCOPED_NOTIFY_TABLE_QUEUE,
			array(
				'blog_id'       => get_current_blog_id(),
				'user_id'       => $user_id,
				'object_id'     => $post_id,
				'object_type'   => 'post',
				'trigger_id'    => 1,
				'status'        => 'pending',
				'created_at'    => current_time( 'mysql' ),
				'reason'        => 'new_post',
				'schedule_type' => 'immediate',
			)
		);

		add_filter( 'scoped_notify_third_party_send_mail_notification', '__return_true' );

		$this->processor->process_queue( 10 );

		$stats = get_site_option( 'scoped_notify_total_sent_count' );
		$this->assertIsArray( $stats, 'Stats should be converted to array.' );
		$this->assertEquals( 101, $stats['count'], 'Count should increment from old value.' );
		$this->assertArrayHasKey( 'since', $stats );
	}
}