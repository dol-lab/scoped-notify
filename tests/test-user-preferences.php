<?php
/**
 * Test User_Preferences::get_post_toggle_state()
 *
 * @package Scoped_Notify
 */

use Scoped_Notify\Notification_Preference as Pref;
use Scoped_Notify\Scope;
use Scoped_Notify\User_Preferences;

/**
 * A post's comment switch: the most specific setting wins.
 */
class Test_User_Preferences extends WP_UnitTestCase {

	/**
	 * Other tests replace the triggers, and a later TRUNCATE commits that.
	 */
	public function setUp(): void {
		global $wpdb;
		parent::setUp();
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO %i (trigger_key, channel) VALUES ('post-post', %s), ('comment-post', %s)", SCOPED_NOTIFY_TABLE_TRIGGERS, User_Preferences::CHANNEL, User_Preferences::CHANNEL ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Post, then blog, then network, else the default; set() and remove() take effect within the request.
	 */
	public function test_most_specific_scope_wins() {
		$user_id = self::factory()->user->create();
		$blog_id = get_current_blog_id();
		$post_id = self::factory()->post->create();
		$user    = array( 'user_id' => $user_id );
		$blog    = $user + array( 'blog_id' => $blog_id );
		$post    = $blog + array( 'post_id' => $post_id );
		$state   = fn() => User_Preferences::get_post_toggle_state( $user_id, $blog_id, $post_id );

		$this->assertSame( SCOPED_NOTIFY_DEFAULT_NOTIFICATION_STATE, $state() );

		User_Preferences::set( Scope::Network, $user, Pref::Posts_Only );
		$this->assertFalse( $state() );

		User_Preferences::set( Scope::Blog, $blog, Pref::Posts_And_Comments );
		$this->assertTrue( $state() );

		User_Preferences::set( Scope::Post, $post, Pref::No_Notifications );
		$this->assertFalse( $state() );

		User_Preferences::remove( Scope::Post, $post );
		$this->assertTrue( $state() );
	}

	/**
	 * Term settings beat the blog's; of a post's terms, any unmuted one wins.
	 */
	public function test_terms_any_unmute_wins() {
		global $wpdb;

		$user_id  = self::factory()->user->create();
		$blog_id  = get_current_blog_id();
		$muted    = self::factory()->category->create();
		$unmuted  = self::factory()->category->create();
		$one_term = self::factory()->post->create( array( 'post_category' => array( $muted ) ) );
		$both     = self::factory()->post->create( array( 'post_category' => array( $muted, $unmuted ) ) );
		$trigger  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT trigger_id FROM %i WHERE trigger_key = %s AND channel = %s', SCOPED_NOTIFY_TABLE_TRIGGERS, 'comment-post', User_Preferences::CHANNEL ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$mutes = array(
			$muted   => 1,
			$unmuted => 0,
		);
		foreach ( $mutes as $term_id => $mute ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				SCOPED_NOTIFY_TABLE_SETTINGS_TERMS,
				array(
					'blog_id'    => $blog_id,
					'user_id'    => $user_id,
					'term_id'    => $term_id,
					'trigger_id' => $trigger,
					'mute'       => $mute,
				)
			);
		}
		$blog = array(
			'user_id' => $user_id,
			'blog_id' => $blog_id,
		);
		User_Preferences::set( Scope::Blog, $blog, Pref::Posts_And_Comments );

		$this->assertFalse( User_Preferences::get_post_toggle_state( $user_id, $blog_id, $one_term ) );
		$this->assertTrue( User_Preferences::get_post_toggle_state( $user_id, $blog_id, $both ) );
	}
}
