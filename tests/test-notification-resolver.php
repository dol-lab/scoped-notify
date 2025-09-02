<?php
/**
 * Test Notification_Resolver
 *
 * @package Scoped_Notify
 */

use PHPUnit\Framework\MockObject\MockObject;
/**
 * Test class for Notification_Resolver.
 */
class Test_Notification_Resolver extends WP_UnitTestCase {

	/**
	 * Resolver instance.
	 *
	 * @var \Scoped_Notify\Notification_Resolver&MockObject
	 */
	private $resolver;

	/**
	 * Set up test.
	 */
	public function setUp(): void {
		global $wpdb;
		$this->resolver = $this->getMockBuilder( \Scoped_Notify\Notification_Resolver::class )
			->setConstructorArgs( array( $wpdb ) )
			->onlyMethods( array( 'get_trigger_id', 'get_blog_member_ids', 'get_mentioned_user_ids' ) )
			->getMock();
	}

	/**
	 * Test default behavior: user with no settings receives notification if default is on.
	 */
	public function test_default_behavior() {

		$post_id = $this->factory()->post->create( array( 'post_author' => 2 ) ); // already sends an email.
		$post    = get_post( $post_id );

		$this->resolver->method( 'get_trigger_id' )->willReturn( 1 ); // 1 is post-post by default.
		$this->resolver->method( 'get_blog_member_ids' )->willReturn( array( 1 ) ); // can only be an existing user.
		$this->resolver->method( 'get_mentioned_user_ids' )->willReturn( array( 3 ) );

		$recipients = $this->resolver->get_recipients_for_post( $post );

		$this->assertContains( 1, $recipients ); // user 1 (from get_blog_member_ids)
		$this->assertContains( 3, $recipients ); // user 3 (from get_mentioned_user_ids)
	}

	/**
	 * Test mute hierarchy: user muted at network level should not receive notification.
	 */
	public function test_mute_network_level() {
		$post_id = $this->factory()->post->create( array( 'post_author' => 1 ) );
		$post    = get_post( $post_id );

		$this->resolver->method( 'get_trigger_id' )->willReturn( 1 );
		$this->resolver->method( 'get_blog_member_ids' )->willReturn( array( 3 ) );
		$this->resolver->method( 'get_mentioned_user_ids' )->willReturn( array() );

		global $wpdb;
		$wpdb->insert(
			'scoped_notify_settings_user_profile',
			array(
				'user_id'    => 3,
				'trigger_id' => 1,
				'mute'       => 1,
			)
		);

		$recipients = $this->resolver->get_recipients_for_post( $post );

		$this->assertNotContains( 3, $recipients );
	}

	/**
	 * Test author exclusion: author should not be in recipients.
	 */
	public function test_author_exclusion() {
		$user_id = $this->factory()->user->create();
		$post_id = $this->factory()->post->create( array( 'post_author' => $user_id ) );
		$post    = get_post( $post_id );

		$this->resolver->method( 'get_trigger_id' )->willReturn( 1 );
		$this->resolver->method( 'get_blog_member_ids' )->willReturn( array( $user_id ) );
		$this->resolver->method( 'get_mentioned_user_ids' )->willReturn( array() );

		$recipients = $this->resolver->get_recipients_for_post( $post );

		$this->assertNotContains( $user_id, $recipients );
	}

	/**
	 * Test mentions: mentioned user is always added.
	 */
	public function test_mentions_override() {
		$post_id = $this->factory()->post->create( array( 'post_author' => 1 ) );
		$post    = get_post( $post_id );

		$this->resolver->method( 'get_trigger_id' )->willReturn( 1 );
		$this->resolver->method( 'get_blog_member_ids' )->willReturn( array( 3 ) );
		$this->resolver->method( 'get_mentioned_user_ids' )->willReturn( array( 3 ) );

		global $wpdb;
		$wpdb->insert(
			'scoped_notify_settings_user_profile',
			array(
				'user_id'    => 3,
				'trigger_id' => 1,
				'mute'       => 1,
			)
		);

		$recipients = $this->resolver->get_recipients_for_post( $post );

		$this->assertContains( 3, $recipients );
	}

	/**
	 * Test empty state: no users.
	 */
	public function test_empty_state() {
		$post_id = $this->factory()->post->create( array( 'post_author' => 1 ) );
		$post    = get_post( $post_id );

		$this->resolver->method( 'get_trigger_id' )->willReturn( 1 );
		$this->resolver->method( 'get_blog_member_ids' )->willReturn( array() ); // no users
		$this->resolver->method( 'get_mentioned_user_ids' )->willReturn( array() );

		$recipients = $this->resolver->get_recipients_for_post( $post );

		$this->assertEmpty( $recipients );
	}
}
