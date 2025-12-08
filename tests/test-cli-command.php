<?php
/**
 * Test CLI_Command
 *
 * @package Scoped_Notify
 */

namespace {
	// Define WP_CLI constant if not already defined.
	if ( ! defined( 'WP_CLI' ) ) {
		define( 'WP_CLI', true );
	}
}

// Mock WP_CLI\Utils function if not exists
namespace WP_CLI\Utils {
	if ( ! function_exists( 'WP_CLI\Utils\get_flag_value' ) ) {
		function get_flag_value( $args, $key, $default = null ) {
			return isset( $args[ $key ] ) ? $args[ $key ] : $default;
		}
	}
}

namespace Scoped_Notify {

	use WP_UnitTestCase;
	use Scoped_Notify\CLI_Command;

	class Test_CLI_Command extends WP_UnitTestCase {

		private $cli;
		private $wpdb;

		public function setUp(): void {
			parent::setUp();
			global $wpdb;
			$this->wpdb = $wpdb;

			// Ensure CLI_Command is loaded
			if ( ! class_exists( 'Scoped_Notify\CLI_Command' ) ) {
				require_once dirname( __DIR__ ) . '/src/CLI_Command.php';
			}

			$this->cli = new CLI_Command();

			// Clean DB
			$this->wpdb->query( 'DELETE FROM ' . SCOPED_NOTIFY_TABLE_QUEUE );
			$this->wpdb->query( 'DELETE FROM ' . SCOPED_NOTIFY_TABLE_TRIGGERS );
		}

		public function test_resolve_post_success() {
			// 1. Setup Data
			$trigger_id = 10;
			$this->wpdb->insert(
				SCOPED_NOTIFY_TABLE_TRIGGERS,
				array(
					'trigger_id'  => $trigger_id,
					'trigger_key' => 'post-post',
					'channel'     => 'mail',
				)
			);

			$post_id = $this->factory()->post->create(
				array(
					'post_type'   => 'post',
					'post_status' => 'publish',
				)
			);
			$user_id = $this->factory()->user->create();

			// Add user to blog so they are a potential recipient (default rules)
			add_user_to_blog( get_current_blog_id(), $user_id, 'subscriber' );

			// Clear queue populated by save_post hook
			$this->wpdb->query( 'TRUNCATE TABLE ' . SCOPED_NOTIFY_TABLE_QUEUE );

			// 2. Run Command
			$this->cli->resolve_post( array( $post_id ), array() );

			// 3. Assert Queue
			$queued = $this->wpdb->get_results( 'SELECT * FROM ' . SCOPED_NOTIFY_TABLE_QUEUE );
			$this->assertNotEmpty( $queued, 'Queue should contain items after resolve_post.' );
			$this->assertEquals( $post_id, $queued[0]->object_id );
			// We expect 1 item because we have 1 valid recipient (plus maybe admin?)
		}

		public function test_resolve_post_dry_run() {
			// 1. Setup Data
			$this->wpdb->insert(
				SCOPED_NOTIFY_TABLE_TRIGGERS,
				array(
					'trigger_key' => 'post-post',
					'channel'     => 'mail',
				)
			);

			$post_id = $this->factory()->post->create( array( 'post_type' => 'post' ) );
			$user_id = $this->factory()->user->create();
			add_user_to_blog( get_current_blog_id(), $user_id, 'subscriber' );

			// Clear queue populated by save_post hook
			$this->wpdb->query( 'TRUNCATE TABLE ' . SCOPED_NOTIFY_TABLE_QUEUE );

			// 2. Run Command with --dry-run
			$this->cli->resolve_post( array( $post_id ), array( 'dry-run' => true ) );

			// 3. Assert Queue Empty
			$queued_count = $this->wpdb->get_var( 'SELECT COUNT(*) FROM ' . SCOPED_NOTIFY_TABLE_QUEUE );
			$this->assertEquals( 0, $queued_count, 'Queue should be empty on dry run.' );
		}

		public function test_process_queue() {
			// 1. Setup Trigger (Required for foreign key constraint)
			$trigger_id = 1;
			$this->wpdb->insert(
				SCOPED_NOTIFY_TABLE_TRIGGERS,
				array(
					'trigger_id'  => $trigger_id,
					'trigger_key' => 'post-post',
					'channel'     => 'mail',
				)
			);

			// Create a valid object to avoid processing errors
			$post_id = $this->factory()->post->create();

			// Clear queue populated by save_post hook
			$this->wpdb->query( 'TRUNCATE TABLE ' . SCOPED_NOTIFY_TABLE_QUEUE );

			// 2. Insert Queue Item manually
			$this->wpdb->insert(
				SCOPED_NOTIFY_TABLE_QUEUE,
				array(
					'user_id'     => 1,
					'trigger_id'  => $trigger_id,
					'blog_id'     => get_current_blog_id(),
					'object_type' => 'post',
					'object_id'   => $post_id,
					'reason'      => 'test',
					'status'      => 'pending',
					'created_at'  => current_time( 'mysql' ),
					'meta'        => '{}',
				)
			);

			// 3. Run Command
			$this->cli->process_queue( array(), array( 'limit' => 5 ) );

			// 4. Assert Output or DB State
			// The item should be processed. Status changes to 'sent' or 'failed'.
			$item = $this->wpdb->get_row( 'SELECT * FROM ' . SCOPED_NOTIFY_TABLE_QUEUE . ' WHERE trigger_id = 1', ARRAY_A );
			$this->assertNotEquals( 'pending', $item['status'], 'Queue item status should have changed from pending.' );
		}

		public function test_process_queue_with_time_limit() {
			// 1. Setup Trigger
			$trigger_id = 1;
			$this->wpdb->insert(
				SCOPED_NOTIFY_TABLE_TRIGGERS,
				array(
					'trigger_id'  => $trigger_id,
					'trigger_key' => 'post-post',
					'channel'     => 'mail',
				)
			);

			$post_id = $this->factory()->post->create();
			$this->wpdb->query( 'TRUNCATE TABLE ' . SCOPED_NOTIFY_TABLE_QUEUE );

			// 2. Insert Queue Item
			$this->wpdb->insert(
				SCOPED_NOTIFY_TABLE_QUEUE,
				array(
					'user_id'     => 1,
					'trigger_id'  => $trigger_id,
					'blog_id'     => get_current_blog_id(),
					'object_type' => 'post',
					'object_id'   => $post_id,
					'reason'      => 'test',
					'status'      => 'pending',
					'created_at'  => current_time( 'mysql' ),
					'meta'        => '{}',
				)
			);

			// 3. Run Command with time-limit
			// Just verify it runs without error and processes the item
			$this->cli->process_queue( array(), array( 'limit' => 5, 'time-limit' => 10 ) );

			$item = $this->wpdb->get_row( 'SELECT * FROM ' . SCOPED_NOTIFY_TABLE_QUEUE . ' WHERE trigger_id = 1', ARRAY_A );
			$this->assertNotEquals( 'pending', $item['status'], 'Queue item should be processed with time limit set.' );
		}
	}
}
