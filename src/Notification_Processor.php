<?php
/**
 * Handles processing of notifications from the queue.
 *
 * @package Scoped_Notify
 */

namespace Scoped_Notify;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Processes notifications stored in the database queue.
 */
class Notification_Processor {
	use Static_Logger_Trait;

	/**
	 * Database table name for individual notifications.
	 * @var string
	 */
	private $notifications_table_name;

	/**
	 * WordPress database object.
	 * @var \wpdb
	 */
	private $wpdb;

	/**
	 * Constructor.
	 *
	 * @param \wpdb           $wpdb      WordPress database instance.
	 * @param string          $table_name The name of the notifications DB table.
	 */
	public function __construct( \wpdb $wpdb, string $table_name ) {
		$this->wpdb                     = $wpdb;
		$this->notifications_table_name = $table_name;
	}

	/**
	 * Processes pending notifications from the `sn_queue` table.
	 *
	 * Selects notifications that are pending and due to be sent (either immediate or scheduled time is past).
	 * @todo: we have 1k users and want to send notifications now. do this with chunked bcc-lists for the same post.
	 *
	 * @param int $limit Maximum number of notifications to process in one run.
	 * @return int Number of notifications successfully processed (or attempted).
	 */
	public function process_queue( int $limit = 10 ): int {
		$logger = self::logger();

		$processed_count = 0;
		$now_utc         = gmdate( 'Y-m-d H:i:s' ); // Get current UTC time in MySQL format

		// Get pending notifications that are due
		// Status is 'pending' AND (scheduled_send_time is NULL OR scheduled_send_time <= NOW())
		$items = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->notifications_table_name}
                 WHERE status = %s
                 AND (scheduled_send_time IS NULL OR scheduled_send_time <= %s)
                 ORDER BY created_at ASC
                 LIMIT %d",
				'pending',
				$now_utc,
				$limit
			)
		);

		if ( empty( $items ) ) {
			$logger->debug( 'No pending notifications due for processing.', array( 'time_utc' => $now_utc ) );
			return 0;
		}

		$logger->info( "Processing up to {$limit} due notifications. Found " . \count( $items ) . ' pending and due.', array( 'time_utc' => $now_utc ) );

		foreach ( $items as $item ) {
			// Ensure the item queue_id is a valid integer before proceeding
			if ( ! isset( $item->queue_id ) || ! filter_var( $item->queue_id, FILTER_VALIDATE_INT ) || $item->queue_id <= 0 ) {
				$logger->error( 'Invalid or missing notification queue_id found in queue item. Skipping.', array( 'item_data' => $item ) );
				continue;
			}
			// Cast to int after validation
			$queue_id = (int) $item->queue_id;

			$logger->debug( "Processing notification queue_id: {$queue_id} for user {$item->user_id}" );

			// Mark as processing to prevent race conditions if run concurrently
			$updated = $this->update_notification_status( $queue_id, 'processing', false ); // Mark as processing, don't update sent_at yet
			if ( ! $updated ) {
				$logger->warning( "Failed to mark notification queue_id: {$queue_id} as 'processing'. Skipping." );
				continue;
			}

			$success = $this->process_single_notification( $item ); // Process the single notification

			// Update status based on processing result
			$final_status = $success ? 'sent' : 'failed'; // Use 'sent' instead of 'completed'
			$this->update_notification_status( $queue_id, $final_status, $success ); // Update status and sent_at if successful

			if ( $success ) {
				++$processed_count;
			}
			// Optional: Add retry logic for failed items later
		}

		$logger->info( "Finished processing batch. Successfully processed {$processed_count} notifications." );
		return $processed_count;
	}

	/**
	 * Retrieves the channel associated with a trigger ID.
	 *
	 * @param int $trigger_id The trigger ID.
	 * @return string|null The channel name or null if not found.
	 */
	private function get_channel_for_trigger( int $trigger_id ): ?string {
		$logger = self::logger();

		// Assuming 'sn_triggers' is the correct table name.
		// You might want to pass this table name via the constructor like the notifications table.
		$triggers_table = 'sn_triggers'; // TODO: Consider making this configurable
		$channel        = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT channel FROM {$triggers_table} WHERE trigger_id = %d",
				$trigger_id
			)
		);

		if ( null === $channel ) {
			$logger->error( "Could not find channel for trigger_id {$trigger_id}." );
			return null;
		}

		return (string) $channel;
	}


	/**
	 * Processes a single notification item from the `sn_queue` table.
	 *
	 * @param \stdClass $item The notification item data from the database.
	 * @return bool True on success, false on failure.
	 */
	private function process_single_notification( \stdClass $item ): bool {
		$logger = self::logger();

		// Switch to the correct blog context if in multisite
		$original_blog_id = null;
		$switched_blog    = false;
		if ( \is_multisite() ) {
			$original_blog_id = \get_current_blog_id();
			if ( $item->blog_id && $item->blog_id !== $original_blog_id ) {
				\switch_to_blog( $item->blog_id );
				$switched_blog = true;
				// Use queue_id for logging consistency
				$logger->debug( "Switched to blog ID {$item->blog_id} for processing notification queue_id {$item->queue_id}." );
			}
		}

		try {
			// 1. Get User
			$user = \get_userdata( $item->user_id );
			if ( ! $user ) {
				throw new \Exception( "User ID {$item->user_id} not found." );
			}

			// 2. Get Object
			// Note: get_object handles blog switching internally
			$object = $this->get_object( $item->object_type, $item->object_id, $item->blog_id );
			if ( ! $object ) {
				// Object might have been deleted. Decide how to handle this.
				// Option 1: Mark as failed. Option 2: Mark as 'sent'/'completed' (nothing to send).
				// Let's mark as failed for now, as the notification context is lost.
				throw new \Exception( "Triggering object {$item->object_type}:{$item->object_id} not found on blog {$item->blog_id}." );
			}

			// 3. Get Channel via Trigger ID
			$channel = $this->get_channel_for_trigger( $item->trigger_id );
			if ( ! $channel ) {
				// Error already logged in get_channel_for_trigger
				// Mark as failed because we don't know the channel
				throw new \Exception( "Channel could not be determined for trigger_id {$item->trigger_id}." );
			}
			$logger->debug( "Determined channel '{$channel}' for notification queue_id {$item->queue_id} via trigger_id {$item->trigger_id}." );

			// 4. Send Notification via the specified channel
			$sent = false;
			if ( $channel === 'mail' ) {
				$sent = $this->send_mail_notification( $user, $object, $item );
			} elseif ( $channel === 'push' ) {
				// Placeholder for push notification logic
				// Use queue_id for logging consistency
				$logger->warning( "Push notification channel not yet implemented for notification queue_id {$item->queue_id}." );
				$sent = false; // Mark as failed until implemented
			} else {
				// Allow filtering for custom channels
				$sent = \apply_filters( 'scoped_notify_send_notification_channel_' . $channel, false, $user, $object, $item );
				if ( $sent === false ) {
					// Use queue_id for logging consistency
					$logger->warning( "Unsupported or unhandled notification channel '{$channel}' for notification queue_id {$item->queue_id}." );
				}
			}

			// Restore blog context if switched (must happen before returning)
			if ( $switched_blog ) {
				\restore_current_blog();
				// Use queue_id for logging consistency
				$logger->debug( "Restored original blog context (Blog ID: {$original_blog_id}) after processing notification queue_id {$item->queue_id}." );
			}

			if ( ! $sent ) {
				// Use queue_id for logging consistency
				$logger->error( "Failed to send notification queue_id {$item->queue_id} via channel '{$channel}' to user {$user->ID}." );
				return false;
			}

			// Use queue_id for logging consistency
			$logger->debug( "Successfully sent notification queue_id {$item->queue_id} via channel '{$channel}' to user {$user->ID}." );
			return true;
		} catch ( \Exception $e ) {
			// Use queue_id for logging consistency
			$logger->error(
				"Error processing notification queue_id {$item->queue_id}: " . $e->getMessage(),
				array(
					'item'      => $item,
					'exception' => $e,
				)
			);
			// Restore blog context if switched, even on error
			if ( $switched_blog ) {
				\restore_current_blog();
				// Use queue_id for logging consistency
				$logger->debug( "Restored original blog context (Blog ID: {$original_blog_id}) after error on notification queue_id {$item->queue_id}." );
			}
			return false;
		}
	}

	/**
	 * Retrieves the object associated with a notification item.
	 * Handles blog switching.
	 *
	 * @param string $object_type Object type ('post', 'comment', etc.).
	 * @param int    $object_id   Object ID.
	 * @param int    $blog_id     Blog ID where the object resides.
	 * @return \WP_Post|\WP_Comment|mixed|null The object, or null if not found or type unsupported.
	 */
	private function get_object( string $object_type, int $object_id, int $blog_id ) {
		$logger = self::logger();

		// Ensure we are on the correct blog to fetch the object
		$original_blog_id = null;
		$switched_blog    = false;
		if ( \is_multisite() ) {
			$original_blog_id = \get_current_blog_id();
			if ( $blog_id && $blog_id !== $original_blog_id ) {
				\switch_to_blog( $blog_id );
				$switched_blog = true;
			}
		}

		$object = null;
		try {
			if ( $object_type === 'post' ) {
				$object = \get_post( $object_id );
			} elseif ( $object_type === 'comment' ) {
				$object = \get_comment( $object_id );
			} else {
				// Allow extension for other object types
				$object = \apply_filters( 'scoped_notify_get_notification_object', null, $object_type, $object_id, $blog_id );
			}
		} finally {
			// Restore blog context if switched
			if ( $switched_blog ) {
				\restore_current_blog();
			}
		}

		if ( ! $object ) {
			$logger->warning(
				'Could not retrieve object.',
				array(
					'object_type' => $object_type,
					'object_id'   => $object_id,
					'blog_id'     => $blog_id,
				)
			);
		}

		return $object;
	}

	/**
	 * Updates the status of a notification item and optionally the sent_at timestamp.
	 *
	 * @param int    $queue_id       The ID of the notification queue item.
	 * @param string $status         The new status ('processing', 'sent', 'failed').
	 * @param bool   $update_sent_at Whether to update the sent_at timestamp.
	 * @return bool True on success, false on failure.
	 */
	private function update_notification_status( int $queue_id, string $status, bool $update_sent_at ): bool {
		$logger = self::logger();

		$data   = array( 'status' => $status );
		$format = array( '%s' );

		if ( $update_sent_at ) {
			$data['sent_at'] = \current_time( 'mysql', true ); // Use UTC time
			$format[]        = '%s';
		}

		$result = $this->wpdb->update(
			$this->notifications_table_name,
			$data,
			array( 'queue_id' => $queue_id ), // Use queue_id in WHERE clause
			$format, // format for data
			array( '%d' )   // format for where
		);

		if ( $result === false ) {
			$logger->error(
				"Failed to update status to '{$status}' for notification queue_id {$queue_id}.",
				array(
					'error' => $this->wpdb->last_error,
					'query' => $this->wpdb->last_query,
				)
			);
			return false;
		}
		return true;
	}

	/**
	 * Formats and sends an email notification for a single notification item.
	 * Handles blog switching for content generation.
	 *
	 * @param \WP_User                   $user   The recipient user object.
	 * @param \WP_Post|\WP_Comment|mixed $object The triggering object.
	 * @param \stdClass                  $item   The notification item from `sn_queue`.
	 * @return bool True if sending was successful, false otherwise.
	 */
	private function send_mail_notification( \WP_User $user, $object, \stdClass $item ): bool {
		$logger = self::logger();

		$logger->info(
			'--- Preparing Mail Notification ---',
			array(
				'notification_queue_id' => $item->queue_id, // Use queue_id for logging consistency
				'user_id'               => $user->ID,
				'user_email'            => $user->user_email,
			)
		);

		// Ensure we are on the correct blog for formatting content (permalinks, bloginfo etc.)
		$original_blog_id = null;
		$switched_blog    = false;
		if ( \is_multisite() ) {
			$original_blog_id = \get_current_blog_id();
			if ( $item->blog_id && $item->blog_id !== $original_blog_id ) {
				\switch_to_blog( $item->blog_id );
				$switched_blog = true;
			}
		}

		$sent = false;
		try {
			// Pass $item which contains object_type, object_id, reason etc.
			$subject = $this->format_mail_subject( $object, $item ); // format methods now handle blog switching if needed
			$message = $this->format_mail_message( $user, $object, $item ); // format methods now handle blog switching if needed
			$headers = array( 'Content-Type: text/html; charset=UTF-8' );

			// Use the filter method for HTML emails
			$set_html_content_type = fn() => 'text/html'; // PHP 7.4+ arrow function
			add_filter( 'wp_mail_content_type', $set_html_content_type );

			$sent = \wp_mail( $user->user_email, $subject, $message, $headers );

			\remove_filter( 'wp_mail_content_type', $set_html_content_type );
		} finally {
			// Restore blog context if switched
			if ( $switched_blog ) {
				\restore_current_blog();
			}
		}

		if ( ! $sent ) {
			$logger->error(
				"wp_mail failed for user {$user->ID}",
				array(
					'email'                 => $user->user_email,
					'notification_queue_id' => $item->queue_id, // Use queue_id for logging consistency
				)
			);
			return false;
		}
		return true;
	}

	/**
	 * Formats the email subject line based on the object and notification item.
	 * Handles blog switching.
	 *
	 * @param \WP_Post|\WP_Comment|mixed $object The triggering object.
	 * @param \stdClass                  $item   The notification item.
	 * @return string The formatted subject line.
	 */
	private function format_mail_subject( $object, \stdClass $item ): string {
		$logger = self::logger();

		// Ensure we are on the correct blog for get_bloginfo.
		$original_blog_id = null;
		$switched_blog    = false;
		if ( \is_multisite() ) {
			$original_blog_id = \get_current_blog_id();
			if ( $item->blog_id && $item->blog_id !== $original_blog_id ) {
				\switch_to_blog( $item->blog_id );
				$switched_blog = true;
			}
		}

		$subject = '';
		try {
			// prevent html entities showing up in the mail subject like [Everybody&#039;s home]
			$blog_name = html_entity_decode(\get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8');

			if ( $object instanceof \WP_Post ) {
				switch ( $item->reason ) {
					case 'new_post':
						$subject = \sprintf( \__( '[%1$s] New Post Published: %2$s', 'snotify' ), $blog_name, $object->post_title );
						break;
					default: // Fallback for other post reasons
						$subject = \sprintf( \__( '[%1$s] Notification regarding Post: %2$s', 'snotify' ), $blog_name, $object->post_title );
				}
			} elseif ( $object instanceof \WP_Comment ) {
				$post       = \get_post( $object->comment_post_ID );
				$post_title = $post ? $post->post_title : \__( 'a post', 'snotify' );
				switch ( $item->reason ) {
					case 'new_comment':
						$subject = \sprintf( \__( '[%1$s] New Comment on: %2$s', 'snotify' ), $blog_name, $post_title );
						break;
					case 'mention': // Example for future use
						$subject = \sprintf( \__( '[%1$s] You were mentioned in a comment on: %2$s', 'snotify' ), $blog_name, $post_title );
						break;
					default: // Fallback for other comment reasons
						$subject = \sprintf( \__( '[%1$s] Notification regarding Comment on: %2$s', 'snotify' ), $blog_name, $post_title );
				}
			} else {
				// Generic fallback
				$subject = \sprintf( \__( '[%1$s] New Notification (%2$s)', 'snotify' ), $blog_name, $item->reason );
			}
		} finally {
			// Restore blog context if switched
			if ( $switched_blog ) {
				\restore_current_blog();
			}
		}

		// Allow filtering of the subject
		return \apply_filters( 'scoped_notify_mail_subject', $subject, $object, $item );
	}

	/**
	 * Formats the email message body (HTML) based on the user, object, and notification item.
	 * Handles blog switching.
	 *
	 * @param \WP_User                   $user   The recipient user object.
	 * @param \WP_Post|\WP_Comment|mixed $object The triggering object.
	 * @param \stdClass                  $item   The notification item.
	 * @return string The formatted HTML message body.
	 */
	private function format_mail_message( \WP_User $user, $object, \stdClass $item ): string {
		$logger = self::logger();

		// Ensure we are on the correct blog for permalinks, get_bloginfo etc.
		$original_blog_id = null;
		$switched_blog    = false;
		if ( \is_multisite() ) {
			$original_blog_id = \get_current_blog_id();
			if ( $item->blog_id && $item->blog_id !== $original_blog_id ) {
				\switch_to_blog( $item->blog_id );
				$switched_blog = true;
			}
		}

		$message = '';
		try {
			$message      = '<p>' . \sprintf( \__( 'Hello %s,', 'snotify' ), $user->display_name ) . '</p>';
			$object_link  = null;
			$object_title = '';
			$blog_name    = \get_bloginfo( 'name' );

			if ( $object instanceof \WP_Post ) {
				$object_link  = \get_permalink( $object->ID );
				$object_title = $object->post_title;
				switch ( $item->reason ) {
					case 'new_post':
						$message .= '<p>' . \sprintf(
							\__( 'A new post, "%1$s", has been published on %2$s.', 'snotify' ),
							\esc_html( $object_title ),
							$blog_name
						) . '</p>';
						break;
					default:
						$message .= '<p>' . \sprintf(
							\__( 'There is a notification regarding the post "%1$s" on %2$s.', 'snotify' ),
							\esc_html( $object_title ),
							$blog_name
						) . '</p>';
				}
			} elseif ( $object instanceof \WP_Comment ) {
				$object_link    = \get_comment_link( $object );
				$post           = \get_post( $object->comment_post_ID );
				$post_title     = $post ? $post->post_title : \__( 'a post', 'snotify' );
				$commenter_name = $object->comment_author;

				switch ( $item->reason ) {
					case 'new_comment':
						$message .= '<p>' . \sprintf(
							\__( '%1$s left a new comment on the post "%2$s":', 'snotify' ),
							\esc_html( $commenter_name ),
							\esc_html( $post_title )
						) . '</p>';
						$message .= '<blockquote>' . \wpautop( \esc_html( $object->comment_content ) ) . '</blockquote>';
						break;
					case 'mention':
						$message .= '<p>' . \sprintf(
							\__( 'You were mentioned by %1$s in a comment on the post "%2$s":', 'snotify' ),
							\esc_html( $commenter_name ),
							\esc_html( $post_title )
						) . '</p>';
						$message .= '<blockquote>' . \wpautop( \esc_html( $object->comment_content ) ) . '</blockquote>';
						break;
					default:
						$message .= '<p>' . \sprintf(
							\__( 'There is a notification regarding a comment by %1$s on the post "%2$s".', 'snotify' ),
							\esc_html( $commenter_name ),
							\esc_html( $post_title )
						) . '</p>';
				}
			} else {
				// Generic fallback
				$message .= '<p>' . \sprintf(
					\__( 'A notification was triggered with reason: %s', 'snotify' ),
					\esc_html( $item->reason )
				) . '</p>';
			}

			// Add link if available
			if ( $object_link ) {
				$link_text = ( $object instanceof \WP_Comment ) ? \__( 'View Comment', 'snotify' ) : \__( 'View Post', 'snotify' );
				$message  .= '<p><a href="' . \esc_url( $object_link ) . '">' . $link_text . '</a></p>';
			}

			// TODO: Add link to manage notification preferences

			$message .= '<p>---</p>';
			// Add footer? e.g., site name
			$message .= '<p><small>' . \sprintf( \__( 'This email was sent from %s.', 'snotify' ), $blog_name ) . '</small></p>';
		} finally {
			// Restore blog context if switched
			if ( $switched_blog ) {
				\restore_current_blog();
			}
		}

		// Allow filtering of the message body
		return \apply_filters( 'scoped_notify_mail_message', $message, $user, $object, $item );
	}
}
