<?php
/**
 * Scoped Notify Plugin
 *
 * @package Scoped_Notify
 */

namespace Scoped_Notify;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Class Notification_Hooks
 *
 * Handles WordPress hooks for triggering notifications or other actions.
 */
class Notification_Hooks {

	/**
	 * @var Notification_Queue
	 */
	protected $queue_manager;

	/**
	 * Notification_Hooks constructor.
	 *
	 * @param Notification_Queue $queue_manager The queue manager.
	 */
	public function __construct( Notification_Queue $queue_manager ) {
		$this->queue_manager = $queue_manager;
	}

	/**
	 * Deletes notification settings for a post when it is deleted or trashed.
	 *
	 * This function is hooked to 'delete_post' and 'wp_trash_post'.
	 *
	 * @param int $post_id The ID of the post being deleted or trashed.
	 */
	public function hook_trash_delete_post( $post_id ) {
		global $wpdb;
		$blog_id = get_current_blog_id();

		$result = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			SCOPED_NOTIFY_TABLE_SETTINGS_POST_COMMENTS,
			array(
				'post_id' => $post_id,
				'blog_id' => $blog_id,
			),
			array( '%d', '%d' )
		);

		if ( false === $result ) {
			$logger = Logger::create();
			$logger->error(
				"Failed to delete post comment settings for post_id: {$post_id} in blog_id: {$blog_id}",
				array(
					'post_id'    => $post_id,
					'blog_id'    => $blog_id,
					'table_name' => SCOPED_NOTIFY_TABLE_SETTINGS_POST_COMMENTS,
					'db_error'   => $wpdb->last_error,
				)
			);
		} elseif ( $result > 0 ) {
			$logger = Logger::create();
			$logger->debug(
				"Deleted post comment settings for post_id: {$post_id} in blog_id: {$blog_id}",
				array(
					'post_id'       => $post_id,
					'blog_id'       => $blog_id,
					'table_name'    => SCOPED_NOTIFY_TABLE_SETTINGS_POST_COMMENTS,
					'rows_affected' => $result,
				)
			);
		}

		$result = $wpdb->delete(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			SCOPED_NOTIFY_TABLE_QUEUE,
			array(
				'object_id'   => $post_id,
				'object_type' => 'post',
				'blog_id'     => $blog_id,
			),
			array( '%d', '%s', '%d' )
		);

		if ( false === $result ) {
			$logger = Logger::create();
			$logger->error(
				"Failed to delete post from queue for post_id: {$post_id} in blog_id: {$blog_id}",
				array(
					'post_id'    => $post_id,
					'blog_id'    => $blog_id,
					'table_name' => SCOPED_NOTIFY_TABLE_QUEUE,
					'db_error'   => $wpdb->last_error,
				)
			);
		} elseif ( $result > 0 ) {
			$logger = Logger::create();
			$logger->debug(
				"Deleted post from queue for post_id: {$post_id} in blog_id: {$blog_id}",
				array(
					'post_id'       => $post_id,
					'blog_id'       => $blog_id,
					'table_name'    => SCOPED_NOTIFY_TABLE_QUEUE,
					'rows_affected' => $result,
				)
			);
		}
	}
}
