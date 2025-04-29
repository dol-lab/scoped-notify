<?php

/**
 * WP-CLI commands for Scoped Notify.
 *
 * @package Scoped_Notify
 */

namespace Scoped_Notify;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

// Ensure WP_CLI is running
// Note: WP_CLI constant is defined by WP-CLI itself.
if ( ! defined( '\WP_CLI' ) || ! \WP_CLI ) { // Check global constant
	return;
}

// No 'use' statements needed as we use fully qualified names

/**
 * Manages Scoped Notify notifications via WP-CLI.
 */
class CLI_Command extends \WP_CLI_Command {
	// Use fully qualified name

	/**
	 * Test resolving recipients for a given post and queues notifications.
	 *
	 * Resolves recipients based on current rules and queues individual notifications
	 * for each recipient based on their schedule.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : The ID of the post to trigger notifications for.
	 *
	 * [--channel=<channel>]
	 * : The notification channel to use.
	 * ---
	 * default: mail
	 * ---
	 *
	 * [--reason=<reason>]
	 * : The reason for the notification (e.g., 'new_post', 'manual_trigger').
	 * ---
	 * default: manual_trigger
	 * ---
	 *
	 * [--dry-run]
	 * : If set, will only list recipients without queueing.
	 *
	 * ## EXAMPLES
	 *
	 *     # Resolve recipients for post 123, mail channel, reason 'manual_trigger' (dry run)
	 *     wp scoped-notify resolve_post 123 --dry-run
	 *
	 *     # Queue notifications for post 456, push channel, reason 'new_post'
	 *     wp scoped-notify resolve_post 456 --channel=push --reason=new_post
	 *
	 * @when after_wp_load
	 */
	public function resolve_post( $args, $assoc_args ) {
		global $wpdb; // Make sure $wpdb is available
		list($post_id) = $args;
		$channel       = $assoc_args['channel'] ?? 'mail';
		$reason        = $assoc_args['reason'] ?? 'manual_trigger'; // Default reason for manual CLI trigger
		$dry_run       = \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false ); // Use FQN

		$post = \get_post( (int) $post_id ); // Use global function

		if ( ! $post ) {
			\WP_CLI::error( "Post with ID {$post_id} not found." ); // Use FQN
			return;
		}

		// Determine the correct blog ID for the post
		$target_blog_id   = null;
		$original_blog_id = null;
		$switched_blog    = false;
		if ( \is_multisite() ) { // Use global function
			$original_blog_id = \get_current_blog_id(); // Use global function
			$post_blog_id     = $post->BLOG_ID ?? null;
			if ( ! $post_blog_id ) {
				$post_blog_id = \get_blog_id_from_url( \get_home_url(), $original_blog_id ); // Use global functions
			}

			if ( $post_blog_id ) {
				 $target_blog_id = $post_blog_id;
				if ( $target_blog_id !== $original_blog_id ) {
					\switch_to_blog( $target_blog_id ); // Use global function
					$switched_blog = true;
					\WP_CLI::log( 'Switched to blog ID: ' . $target_blog_id ); // Use FQN
				}
			} else {
				\WP_CLI::warning( "Could not determine blog ID for post {$post_id}. Using current blog context ({$original_blog_id})." ); // Use FQN
				$target_blog_id = $original_blog_id; // Fallback to current blog
			}
		} else {
			$target_blog_id = \get_current_blog_id(); // Single site, use current blog ID
		}

		\WP_CLI::log( "Resolving recipients for Post ID: {$post_id} (Type: {$post->post_type}), Blog ID: {$target_blog_id}, Channel: {$channel}, Reason: {$reason}" ); // Use FQN

		try {
			// Instantiate dependencies (needed within the command context)
			global $wpdb; // Make sure $wpdb is available
			$logger        = get_logger(); // Assuming get_logger() is available globally or in this namespace
			$resolver      = new Notification_Resolver( $wpdb, $logger );
			$scheduler     = new Notification_Scheduler( $logger, $wpdb ); // Instantiate scheduler
			$queue_manager = new Notification_Queue( $resolver, $scheduler, $logger, $wpdb ); // Pass scheduler

			// Resolve recipients (primarily for logging/dry-run info in this command)
			$recipient_ids = $resolver->get_recipients_for_post( $post, $channel );

			if ( empty( $recipient_ids ) ) {
				\WP_CLI::success( "No recipients found based on current settings for Post ID {$post_id}. No item queued." ); // Use FQN
			} else {
				\WP_CLI::log( 'Found ' . \count( $recipient_ids ) . ' potential recipients based on current rules: ' . \implode( ', ', $recipient_ids ) ); // Use FQN, global functions

				if ( $dry_run ) {
					\WP_CLI::success( "[Dry Run] Would queue notifications for Post ID {$post_id}, Channel '{$channel}', Reason '{$reason}'." ); // Use FQN
				} else {
					// Find the trigger_id first
					$trigger_key   = 'post-' . $post->post_type;
					$trigger_table = 'sn_triggers'; // TODO: Centralize table name
					$trigger_id    = $wpdb->get_var(
						$wpdb->prepare(
							"SELECT trigger_id FROM {$trigger_table} WHERE trigger_key = %s AND channel = %s LIMIT 1",
							$trigger_key,
							$channel
						)
					);

					if ( empty( $trigger_id ) ) {
						\WP_CLI::error( "Could not find an active trigger for key '{$trigger_key}' and channel '{$channel}'. No notifications queued." ); // Use FQN
					} else {
						// Queue the event using the new method
						$queued_count = $queue_manager->queue_event_notifications(
							'post',
							$post->ID,
							$reason,
							$target_blog_id, // Pass the determined blog ID
							array(), // Meta
							(int) $trigger_id
						);

						if ( $queued_count > 0 ) {
							\WP_CLI::success( "Successfully queued {$queued_count} notifications for Post ID {$post_id}, Channel '{$channel}', Reason '{$reason}' (Trigger ID: {$trigger_id})." ); // Use FQN
						} elseif ( $queued_count === 0 ) {
							 \WP_CLI::warning( "Event processed, but 0 notifications were queued (likely no recipients found). Post ID {$post_id}, Trigger ID: {$trigger_id}." ); // Use FQN
						} else {
							// queue_event_notifications returns int, so negative shouldn't happen, but check logs if it does.
							 \WP_CLI::error( "Failed to queue notifications for Post ID {$post_id}. Check logs." ); // Use FQN
						}
					}
				}
			}
		} catch ( \Exception $e ) {
			\WP_CLI::error( 'An error occurred: ' . $e->getMessage() ); // Use FQN
		} finally {
			 // Switch back if we switched blogs
			if ( $switched_blog && \function_exists( 'restore_current_blog' ) ) { // Use global function
				\restore_current_blog(); // Use global function
				\WP_CLI::log( "Restored original blog context (Blog ID: {$original_blog_id})." ); // Use FQN
			}
		}
	}

	/**
	 * Test resolving recipients for a given comment and queues a notification trigger.
	 *
	 * Similar to resolve_post, this queues a trigger event. Recipients are resolved
	 * again during queue processing.
	 *
	 * ## OPTIONS
	 *
	 * <comment_id>
	 * : The ID of the comment to trigger notifications for.
	 *
	 * [--channel=<channel>]
	 * : The notification channel to use.
	 * ---
	 * default: mail
	 * ---
	 *
	 * [--reason=<reason>]
	 * : The reason for the notification (e.g., 'new_comment', 'manual_trigger').
	 * ---
	 * default: manual_trigger
	 * ---
	 *
	 * [--dry-run]
	 * : If set, will only list recipients without queueing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp scoped-notify resolve_comment 50 --dry-run
	 *     wp scoped-notify resolve_comment 51 --reason=new_comment
	 *
	 * @when after_wp_load
	 */
	public function resolve_comment( $args, $assoc_args ) {
		list($comment_id) = $args;
		$channel          = $assoc_args['channel'] ?? 'mail';
		$reason           = $assoc_args['reason'] ?? 'manual_trigger'; // Default reason for manual CLI trigger
		$dry_run          = \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false ); // Use FQN

		$comment = \get_comment( (int) $comment_id ); // Use global function

		if ( ! $comment ) {
			\WP_CLI::error( "Comment with ID {$comment_id} not found." ); // Use FQN
			return;
		}

		$post = \get_post( $comment->comment_post_ID ); // Use global function
		if ( ! $post ) {
			 \WP_CLI::error( "Parent post (ID: {$comment->comment_post_ID}) for comment ID {$comment_id} not found." ); // Use FQN
			return;
		}

		 // Determine the correct blog ID for the comment (via its post)
		$target_blog_id   = null;
		$original_blog_id = null;
		$switched_blog    = false;
		if ( \is_multisite() ) { // Use global function
			$original_blog_id = \get_current_blog_id(); // Use global function
			$post_blog_id     = $post->BLOG_ID ?? null;
			if ( ! $post_blog_id ) {
				$post_blog_id = \get_blog_id_from_url( \get_home_url(), $original_blog_id ); // Use global functions
			}

			if ( $post_blog_id ) {
				$target_blog_id = $post_blog_id;
				if ( $target_blog_id !== $original_blog_id ) {
					\switch_to_blog( $target_blog_id ); // Use global function
					$switched_blog = true;
					\WP_CLI::log( 'Switched to blog ID: ' . $target_blog_id ); // Use FQN
				}
			} else {
				\WP_CLI::warning( "Could not determine blog ID for comment {$comment_id} (Post {$post->ID}). Using current blog context ({$original_blog_id})." ); // Use FQN
				$target_blog_id = $original_blog_id; // Fallback to current blog
			}
		} else {
			$target_blog_id = \get_current_blog_id(); // Single site, use current blog ID
		}

		\WP_CLI::log( "Resolving recipients for Comment ID: {$comment_id} (Post ID: {$comment->comment_post_ID}), Blog ID: {$target_blog_id}, Channel: {$channel}, Reason: {$reason}" ); // Use FQN

		try {
			// Instantiate dependencies
			global $wpdb; // Make sure $wpdb is available
			$logger        = get_logger();
			$resolver      = new Notification_Resolver( $wpdb, $logger );
			$scheduler     = new Notification_Scheduler( $logger, $wpdb ); // Instantiate scheduler
			$queue_manager = new Notification_Queue( $resolver, $scheduler, $logger, $wpdb ); // Pass scheduler

			// Resolve recipients (primarily for logging/dry-run info)
			$recipient_ids = $resolver->get_recipients_for_comment( $comment, $channel );

			if ( empty( $recipient_ids ) ) {
				\WP_CLI::success( "No recipients found based on current settings for Comment ID {$comment_id}. No item queued." ); // Use FQN
			} else {
				\WP_CLI::log( 'Found ' . \count( $recipient_ids ) . ' potential recipients based on current rules: ' . \implode( ', ', $recipient_ids ) ); // Use FQN, global functions

				if ( $dry_run ) {
					\WP_CLI::success( "[Dry Run] Would queue notifications for Comment ID {$comment_id}, Channel '{$channel}', Reason '{$reason}'." ); // Use FQN
				} else {
					// Find the trigger_id first
					$trigger_key   = 'comment-' . $post->post_type;
					$trigger_table = 'sn_triggers'; // TODO: Centralize table name
					$trigger_id    = $wpdb->get_var(
						$wpdb->prepare(
							"SELECT trigger_id FROM {$trigger_table} WHERE trigger_key = %s AND channel = %s LIMIT 1",
							$trigger_key,
							$channel
						)
					);

					if ( empty( $trigger_id ) ) {
						 \WP_CLI::error( "Could not find an active trigger for key '{$trigger_key}' and channel '{$channel}'. No notifications queued." ); // Use FQN
					} else {
						// Queue the event using the new method
						$queued_count = $queue_manager->queue_event_notifications(
							'comment',
							$comment->comment_ID,
							$reason,
							$target_blog_id, // Pass the determined blog ID
							array(), // Meta
							(int) $trigger_id
						);

						if ( $queued_count > 0 ) {
							\WP_CLI::success( "Successfully queued {$queued_count} notifications for Comment ID {$comment_id}, Channel '{$channel}', Reason '{$reason}' (Trigger ID: {$trigger_id})." ); // Use FQN
						} elseif ( $queued_count === 0 ) {
							\WP_CLI::warning( "Event processed, but 0 notifications were queued (likely no recipients found). Comment ID {$comment_id}, Trigger ID: {$trigger_id}." ); // Use FQN
						} else {
							\WP_CLI::error( "Failed to queue notifications for Comment ID {$comment_id}. Check logs." ); // Use FQN
						}
					}
				}
			}
		} catch ( \Exception $e ) {
			\WP_CLI::error( 'An error occurred: ' . $e->getMessage() ); // Use FQN
		} finally {
			 // Switch back if we switched blogs
			if ( $switched_blog && \function_exists( 'restore_current_blog' ) ) { // Use global function
				\restore_current_blog(); // Use global function
				\WP_CLI::log( "Restored original blog context (Blog ID: {$original_blog_id})." ); // Use FQN
			}
		}
	}

	/**
	 * Manually process the notification queue.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<limit>]
	 * : Maximum number of queue items to process in this run.
	 * ---
	 * default: 20
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Process up to 20 items from the queue
	 *     wp scoped-notify process_queue
	 *
	 *     # Process up to 100 items from the queue
	 *     wp scoped-notify process_queue --limit=100
	 *
	 * @when after_wp_load
	 */
	public function process_queue( $args, $assoc_args ) {
		$limit = (int) ( $assoc_args['limit'] ?? 20 );
		if ( $limit <= 0 ) {
			\WP_CLI::error( 'Limit must be a positive integer.' );
			return;
		}

		\WP_CLI::log( "Attempting to process up to {$limit} notification queue items..." );

		try {
			// Instantiate dependencies
			global $wpdb; // Make sure $wpdb is available
			$logger = get_logger(); // Use the same logger as the main plugin if possible
			// TODO: Get table name from config
			$notifications_table = 'sn_queue';
			$processor           = new Notification_Processor( $logger, $wpdb, $notifications_table );

			$processed_count = $processor->process_queue( $limit );

			\WP_CLI::success( "Finished processing batch. Successfully processed {$processed_count} items." );
		} catch ( \Exception $e ) {
			\WP_CLI::error( 'An error occurred during queue processing: ' . $e->getMessage() );
		}
	}
}
