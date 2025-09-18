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
	 * @var Notification_Queue   * TODO check if this is needed, seems not to be
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

	public function hook_delete_user( $user_id ) {
		global $wpdb;
		$logger = Logger::create();

		$data = array( "user_id" => $user_id );

		$tables = array(
			SCOPED_NOTIFY_TABLE_USER_BLOG_SCHEDULES,
			SCOPED_NOTIFY_TABLE_QUEUE,
			SCOPED_NOTIFY_TABLE_SETTINGS_USER_PROFILES,
			SCOPED_NOTIFY_TABLE_SETTINGS_BLOGS,
			SCOPED_NOTIFY_TABLE_SETTINGS_TERMS,
			SCOPED_NOTIFY_TABLE_SETTINGS_POST_COMMENTS
		);

		foreach ($tables as $table) {
			$result = $wpdb->delete( $table, $data, '%d' );

			if ( false === $result ) {
				$logger->error(
					"Failed to delete user entries for user {$user_id} in table {$table}",
					array(
						'user_id'    => $user_id,
						'table_name' => $table,
						'db_error'   => $wpdb->last_error,
					)
				);
			} elseif ( $result > 0 ) {
				$logger->debug(
					"Deleted user entries for user {$user_id} in table {$table}",
					array(
						'user_id'    	=> $user_id,
						'table_name' 	=> $table,
						'rows_affected' => $result,
					)
				);
			}

		}

	}

	public function hook_delete_blog( $blog ) {
		global $wpdb;
		$logger = Logger::create();

		$site = get_site( $blog );
		if ( empty( $site ) ) {
			return new WP_Error( 'site_empty_id', __( 'Site ID must not be empty.' ) );
		}
		$blog_id = $site->id;

		$data = array( "blog_id" => $blog_id );

		$tables = array(
			SCOPED_NOTIFY_TABLE_USER_BLOG_SCHEDULES,
			SCOPED_NOTIFY_TABLE_QUEUE,
			SCOPED_NOTIFY_TABLE_SETTINGS_BLOGS,
			SCOPED_NOTIFY_TABLE_SETTINGS_TERMS,
			SCOPED_NOTIFY_TABLE_SETTINGS_POST_COMMENTS
		);

		foreach ($tables as $table) {
			$result = $wpdb->delete( $table, $data, '%d' );

			if ( false === $result ) {
				$logger->error(
					"Failed to delete blog entries for blog {$blog_id} in table {$table}",
					array(
						'blog_id'    => $blog_id,
						'table_name' => $table,
						'db_error'   => $wpdb->last_error,
					)
				);
			} elseif ( $result > 0 ) {
				$logger->debug(
					"Deleted blog entries for blog {$blog_id} in table {$table}",
					array(
						'blog_id'    	=> $blog_id,
						'table_name' 	=> $table,
						'rows_affected' => $result,
					)
				);
			}

		}
	}

	public function hook_delete_term( $term_id ) {
		global $wpdb;
		$logger = Logger::create();

		$blog_id = get_current_blog_id();

		$data = array(
			'term_id' => $term_id,
			'blog_id' => $blog_id
		);

		$tables = array(
			SCOPED_NOTIFY_TABLE_SETTINGS_TERMS,
		);

		foreach ($tables as $table) {
			$result = $wpdb->delete( $table, $data, array( '%d', '%d' ) );

			if ( false === $result ) {
				$logger->error(
					"Failed to delete term entries for term {$term_id} in blog {$blog_id} in table {$table}",
					array(
						'term_id' 	 => $term_id,
						'blog_id'    => $blog_id,
						'table_name' => $table,
						'db_error'   => $wpdb->last_error,
					)
				);
			} elseif ( $result > 0 ) {
				$logger->debug(
					"Deleted blog entries for term {$term_id} in blog {$blog_id} in table {$table}",
					array(
						'term_id' 	 	=> $term_id,
						'blog_id'    	=> $blog_id,
						'table_name' 	=> $table,
						'rows_affected' => $result,
					)
				);
			}

		}
	}
}
