<?php
/**
 * Tests that a subscribed user receives an email when another user publishes a post.
 *
 * @package Scoped_Notify
 */

class Scoped_Notify_Subscription_Email_Test extends WP_UnitTestCase {

	public function test_subscribed_user_receives_email_on_other_user_post() {
		global $wpdb;

		// Create two users: author and subscriber.
		$author_id     = $this->factory()->user->create(
			array(
				'user_email' => 'author@example.test',
				'user_login' => 'author',
			)
		);
		$subscriber_id = $this->factory()->user->create(
			array(
				'user_email' => 'sub@example.test',
				'user_login' => 'subscriber',
			)
		);

		// Create a published post by the author.
		$post_id = $this->factory()->post->create(
			array(
				'post_author' => $author_id,
				'post_status' => 'publish',
				'post_type'   => 'post',
				'post_title'  => 'Notify me post',
			)
		);

		$post = get_post( $post_id );
		$this->assertNotNull( $post );

		// Build a fake notification item object similar to what the processor expects.
		$item              = new stdClass();
		$item->blog_id     = get_current_blog_id();
		$item->object_id   = $post_id;
		$item->object_type = 'post';
		$item->trigger_id  = 1; // assuming 1 is the ID for "new post" trigger.
		$item->reason      = 'new_post';

		// Prepare users array (WP_User objects)
		$users = array( get_userdata( $subscriber_id ) );

		// Short-circuit actual wp_mail by signalling the processor that the send was handled by a 3rd party.
		add_filter(
			'scoped_notify_third_party_send_mail_notification',
			function ( $sent, $user_chunk, $object ) {
				return true; // pretend mail was sent successfully by third party
			},
			10,
			3
		);

		$processor = new \Scoped_Notify\Notification_Processor( $wpdb, SCOPED_NOTIFY_TABLE_QUEUE );

		// Call the private method process_single_notification via reflection.
		$reflection = new ReflectionClass( $processor );
		$method     = $reflection->getMethod( 'process_single_notification' );
		$method->setAccessible( true );

		/** @var array $result [ $users_succeeded, $users_failed ] */
		$result = $method->invoke( $processor, $item, $users );

		// Remove the test filter so it does not affect other tests.
		remove_all_filters( 'scoped_notify_third_party_send_mail_notification' );

		// Assertions: the subscriber should be in the succeeded list and none failed.
		$this->assertIsArray( $result );
		$this->assertCount( 2, $result );
		$users_succeeded = $result[0];
		$users_failed    = $result[1];

		$this->assertNotEmpty( $users_succeeded, 'No users marked as succeeded' );
		$this->assertEmpty( $users_failed, 'Some users failed in notification processing' );

		// Ensure the succeeded user is the subscriber.
		$this->assertEquals( $subscriber_id, $users_succeeded[0]->ID );
	}
}
