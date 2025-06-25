<?php
/**
 * Handles processing of notifications from the queue.
 *
 * @package Scoped_Notify
 */

declare(strict_types=1);

namespace Scoped_Notify;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Processes notifications stored in the database queue.
 */
class Notification_Processor {
	use Static_Logger_Trait;

	const CHUNK_SIZE_FALLBACK = 400;

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
	 * Processes pending notifications from the SCOPED_NOTIFY_TABLE_QUEUE table.
	 *
	 * Selects notifications that are pending and due to be sent (either immediate or scheduled time is past).
	 * Notifications are grouped by content, and a single email is sent for each set of contents, with all recipients
	 * in bcc to avoid leaking of mailadresses
	 *
	 * @param int $limit Maximum number of notifications to process in one run.
	 * @return int Number of notifications successfully processed (or attempted).
	 */
	public function process_queue( int $limit = 10 ): int {
		$logger = self::logger();

		$processed_count = 0;
		$now_utc         = gmdate( 'Y-m-d H:i:s' ); // Get current UTC time in MySQL format

		// Get pending notifications that are due
		// first get list of pending "objects"
		// Status is 'pending' AND (scheduled_send_time is NULL OR scheduled_send_time <= NOW())
		$items = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT blog_id, object_id, object_type, trigger_id, reason, schedule_type
				 FROM {$this->notifications_table_name}
                 WHERE status = %s
                 AND (scheduled_send_time IS NULL OR scheduled_send_time <= %s)
				 group by blog_id, object_id, object_type, trigger_id, reason, schedule_type
                 ORDER BY min(created_at) ASC
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

			// now fetch users
			$users = $this->wpdb->get_results(
				$this->wpdb->prepare(
					"SELECT queue_id,user_id
					FROM {$this->notifications_table_name}
					WHERE
						blog_id = %d
						AND object_id = %d
						AND object_type = %s
						AND trigger_id = %d
						AND status = %s
					",
					$item->blog_id,
					$item->object_id,
					$item->object_type,
					$item->trigger_id,
					'pending'
				)
			);

			$logger->debug( "Processing notification queue item {$this->format_item($item)}" );

			// Mark as processing to reduce race conditions if run concurrently
			$updated = $this->update_notification_status( absint($item->blog_id), absint($item->object_id), $item->object_type, absint($item->trigger_id), null, 'processing', false ); // Mark as processing, don't update sent_at yet
			if ( ! $updated ) {
				$logger->warning( "Failed to mark notification queue item {$this->format_item($item)} as 'processing'. Skipping." );
				continue;
			}

			$chunk_size=absint(\get_site_option(SCOPED_NOTIFY_MAIL_CHUNK_SIZE));
			// use default-value if chunk_size equals 0
			if ($chunk_size < 1) { $chunk_size = CHUNK_SIZE_FALLBACK; }

			$user_chunks=array_chunk($users,$chunk_size);

			// process each chunk and put the succeeded and failed users in the respective result arrays
			$users_succeeded = array();
			$users_failed = array();
			foreach ($user_chunks as $user_chunk) {
				list($success,$fail) = $this->process_single_notification( $item, $user_chunk ); // Process the single notification
				$users_succeeded = array_merge($users_succeeded, $success);
				$users_failed = array_merge($users_failed, $fail);
			}

			// Update status based on processing result
			foreach($users_succeeded as $user) {
				$this->update_notification_status( absint($item->blog_id), absint($item->object_id), $item->object_type, absint($item->trigger_id), absint($user->queue_id), "sent", true );
				++$processed_count;
			}
			foreach($users_failed as $user) {
				$this->update_notification_status( absint($item->blog_id), absint($item->object_id), $item->object_type, absint($item->trigger_id), absint($user->queue_id), "failed", false );
			}

			if (count($users_failed) > 0) {
				$logger->error("sending of notifications failed for some users",array("users_failed" => $users_failed));
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

		$channel        = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT channel FROM ".SCOPED_NOTIFY_TABLE_TRIGGERS." WHERE trigger_id = %d",
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
	 * Processes a single notification item from the SCOPED_NOTIFY_TABLE_QUEUE table.
	 *
	 * @param \stdClass $item The notification item data from the database.
	 * @param Arrays $users The list of users to whom the notification is sent
	 * @return [$users with successes,$users which failed]
	 */
	private function process_single_notification( \stdClass $item, Array $users): Array {
		$logger = self::logger();

		// Switch to the correct blog context if in multisite
		$original_blog_id = null;
		$switched_blog    = false;
		if ( \is_multisite() ) {
			$original_blog_id = \get_current_blog_id();
			if ( $item->blog_id && $item->blog_id !== $original_blog_id ) {
				\switch_to_blog( $item->blog_id );
				$switched_blog = true;
				$logger->debug( "Switched to blog ID {$item->blog_id} for processing notification queue item {$this->format_item($item)}" );
			}
		}

		$users_succeeded=array();
		$users_failed=array();

		try {
			// Get Object
			// Note: get_object handles blog switching internally
			$object = $this->get_object( $item->object_type, (int) $item->object_id, (int) $item->blog_id );
			if ( ! $object ) {
				// Object might have been deleted. Decide how to handle this.
				// Option 1: Mark as failed. Option 2: Mark as 'sent'/'completed' (nothing to send).
				// Let's mark as failed for now, as the notification context is lost.
				throw new \Exception( "Triggering object {$item->object_type}:{$item->object_id} not found on blog {$item->blog_id}." );
			}

			// Get Channel via Trigger ID
			$channel = $this->get_channel_for_trigger( (int) $item->trigger_id );
			if ( ! $channel ) {
				// Error already logged in get_channel_for_trigger
				// Mark as failed because we don't know the channel
				throw new \Exception( "Channel could not be determined for trigger_id {$item->trigger_id}." );
			}
			$logger->debug( "Determined channel '{$channel}' for notification queue item {$this->format_item($item)} via trigger_id {$item->trigger_id}." );

			// Send Notification via the specified channel
			$sent = false;
			if ( $channel === 'mail' ) {
				// Get User-emails
				$user_emails=array();
				foreach ($users as $user) {
					$user_data=\get_userdata( $user->user_id);
					if ( ! $user_data) {
						$logger->error("no userdata for user with id {$user->user_id} found - skipping");
						$users_failed[]=$user;
					}
					else {
						$user_emails[]=$user_data->user_email;
					}
				}

				$sent = $this->send_mail_notification( $user_emails, $object, $item );
				if ($sent) {
					$users_succeeded = array_merge($users_succeeded, $users);
				}
				else {
					$users_failed = array_merge($users_failed, $users);
				}
			} elseif ( $channel === 'push' ) {
				// Placeholder for push notification logic
				$logger->warning( "Push notification channel not yet implemented for notification queue item {$this->format_item($item)}" );
				$users_failed = array_merge($users_failed, $users);
			} else {
				// Allow filtering for custom channels
				[$succeeded,$failed] = \apply_filters( 'scoped_notify_send_notification_channel_' . $channel, false, $users, $object, $item );
				$users_succeeded = array_merge($users_succeeded, $succeeded);
				$users_failed = array_merge($users_failed, $failed);
			}

			// Restore blog context if switched (must happen before returning)
			if ( $switched_blog ) {
				\restore_current_blog();
				$logger->debug( "Restored original blog context (Blog ID: {$original_blog_id}) after processing notification queue item {$this->format_item($item)}" );
			}

			$logger->debug( "Sent notification queue item {$this->format_item($item)} via channel '{$channel}' to user {$user->ID}." );
			return [ $users_succeeded, $users_failed ];
		} catch ( \Exception $e ) {
			$logger->error(
				"Error processing notification queue item" . $e->getMessage(),
				array(
					'item'      => $item,
					'exception' => $e,
				)
			);
			// Restore blog context if switched, even on error
			if ( $switched_blog ) {
				\restore_current_blog();
				$logger->debug( "Restored original blog context (Blog ID: {$original_blog_id}) after error on notification queue item {$this->format_item($item)}" );
			}
			return [ array(), $users ];
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
	private function get_object( string $object_type, int $object_id, int $blog_id ): mixed {
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
	 * if no queue_id is given, update all entries for given item (blog_id,object_id,object_type,trigger_id)
	 * if queue_id is given, update only a single item, but still the item values have to be passed
	 *
	 * @param int	 $blog_id			mandatory
	 * @param int	 $object_id			mandatory
	 * @param int	 $object_type		mandatory
	 * @param int	 $trigger_id		mandatory
	 * @param ?int	 $queue_id       The ID of the notification queue item or null - if given, update only this single item
	 * @param string $status         The new status ('processing', 'sent', 'failed').
	 * @param bool   $update_sent_at Whether to update the sent_at timestamp.
	 * @return bool 				True on success, false on failure.
	 */
	// $item->blog_id, $item->object_id, $item->object_type, $item->trigger_id,
	private function update_notification_status( int $blog_id, int $object_id, string $object_type, int $trigger_id, ?int $queue_id, string $status, bool $update_sent_at ): bool {
		$logger = self::logger();

		$data   = array( 'status' => $status );
		$format = array( '%s' );

		$wheredata = array( 'blog_id' => $blog_id,
						'object_id'	=> $object_id,
						'object_type'	=> $object_type,
						'trigger_id'	=> $trigger_id,
					);
		$whereformat = array( '%d','%d','%s','%d' );
		if (! is_null($queue_id)) {
			$wheredata["queue_id"]=$queue_id;
			$whereformat[] = "%d";
		}

		if ( $update_sent_at ) {
			$data['sent_at'] = \current_time( 'mysql', true ); // Use UTC time
			$format[]        = '%s';
		}

		$result = $this->wpdb->update(
			$this->notifications_table_name,
			$data,
			$wheredata,
			$format, // format for data
			$whereformat
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
	 * @param Array                   $user_emails   The recipient user object.
	 * @param \WP_Post|\WP_Comment|mixed $object The triggering object.
	 * @param \stdClass                  $item   The notification item from SCOPED_NOTIFY_TABLE_QUEUE.
	 * @return bool True if sending was successful, false otherwise.
	 */
	private function send_mail_notification( Array $user_emails, $object, \stdClass $item ): bool {
		$logger = self::logger();

		$logger->info(
			'--- Preparing Mail Notification ---',
			array(
				'user_emails'            => $user_emails,
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
			$message = $this->format_mail_message( $object, $item ); // format methods now handle blog switching if needed
			$headers = array( 'Content-Type: text/html; charset=UTF-8' );
			$headers[]='Bcc: '.implode(',',$user_emails);


			// Use the filter method for HTML emails
			$set_html_content_type = fn() => 'text/html'; // PHP 7.4+ arrow function
			add_filter( 'wp_mail_content_type', $set_html_content_type );

			// todo default recipient needs constant
			$sent = \wp_mail( "noreply@thkoeln.de", $subject, $message, $headers );

			\remove_filter( 'wp_mail_content_type', $set_html_content_type );
		} finally {
			// Restore blog context if switched
			if ( $switched_blog ) {
				\restore_current_blog();
			}
		}

		if ( ! $sent ) {
			$logger->error(
				"wp_mail failed",
				array(
					'mails'                 => $user_emails,
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
	private function format_mail_subject( mixed $object, \stdClass $item ): string {
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
						/* translators:  %1$s blog name, "%2$s post title */
						$subject = \sprintf( \__( '[%1$s] New Post Published: %2$s', 'scoped-notify' ), $blog_name, $object->post_title );
						break;
					default: // Fallback for other post reasons
						/* translators:  %1$s blog name, "%2$s post title */
						$subject = \sprintf( \__( '[%1$s] Notification regarding Post: %2$s', 'scoped-notify' ), $blog_name, $object->post_title );
				}
			} elseif ( $object instanceof \WP_Comment ) {
				$post       = \get_post( $object->comment_post_ID );
				$post_title = $post ? $post->post_title : \__( 'a post', 'scoped-notify' );
				switch ( $item->reason ) {
					case 'new_comment':
						/* translators:  %1$s blog name, "%2$s post title */
						$subject = \sprintf( \__( '[%1$s] New Comment on: %2$s', 'scoped-notify' ), $blog_name, $post_title );
						break;
					case 'mention': // Example for future use
						/* translators:  %1$s blog name, "%2$s post title */
						$subject = \sprintf( \__( '[%1$s] You were mentioned in a comment on: %2$s', 'scoped-notify' ), $blog_name, $post_title );
						break;
					default: // Fallback for other comment reasons
						/* translators:  %1$s blog name, "%2$s post title */
						$subject = \sprintf( \__( '[%1$s] Notification regarding Comment on: %2$s', 'scoped-notify' ), $blog_name, $post_title );
				}
			} else {
				// Generic fallback
				/* translators:  %1$s blog name, "%2$s reason */
				$subject = \sprintf( \__( '[%1$s] New Notification (%2$s)', 'scoped-notify' ), $blog_name, $item->reason );
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
	 * @param \WP_Post|\WP_Comment|mixed $object The triggering object.
	 * @param \stdClass                  $item   The notification item.
	 * @return string The formatted HTML message body.
	 */
	private function format_mail_message( mixed $object, \stdClass $item ): string {
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
			$message      = '<p>' . \__( 'Hello,', 'scoped-notify' ) . '</p>';
			$object_link  = null;
			$object_title = '';
			$blog_name    = \get_bloginfo( 'name' );

			if ( $object instanceof \WP_Post ) {
				$object_link  = \get_permalink( $object->ID );
				$object_title = $object->post_title;
				switch ( $item->reason ) {
					case 'new_post':
						$message .= '<p>' . \sprintf(
							/* translators:  %1$s object title, "%2$s blog name */
							\__( 'A new post, "%1$s", has been published on %2$s.', 'scoped-notify' ),
							\esc_html( $object_title ),
							$blog_name
						) . '</p>';
						break;
					default:
						$message .= '<p>' . \sprintf(
							/* translators:  %1$s object title, "%2$s blog name */
							\__( 'There is a notification regarding the post "%1$s" on %2$s.', 'scoped-notify' ),
							\esc_html( $object_title ),
							$blog_name
						) . '</p>';
				}
			} elseif ( $object instanceof \WP_Comment ) {
				$object_link    = \get_comment_link( $object );
				$post           = \get_post( $object->comment_post_ID );
				$post_title     = $post ? $post->post_title : \__( 'a post', 'scoped-notify' );
				$commenter_name = $object->comment_author;

				switch ( $item->reason ) {
					case 'new_comment':
						$message .= '<p>' . \sprintf(
							/* translators:  %1$s commenter name, "%2$s post title */
							\__( '%1$s left a new comment on the post "%2$s":', 'scoped-notify' ),
							\esc_html( $commenter_name ),
							\esc_html( $post_title )
						) . '</p>';
						$message .= '<blockquote>' . \wpautop( \esc_html( $object->comment_content ) ) . '</blockquote>';
						break;
					case 'mention':
						$message .= '<p>' . \sprintf(
							/* translators:  %1$s commenter name, "%2$s post title */
							\__( 'You were mentioned by %1$s in a comment on the post "%2$s":', 'scoped-notify' ),
							\esc_html( $commenter_name ),
							\esc_html( $post_title )
						) . '</p>';
						$message .= '<blockquote>' . \wpautop( \esc_html( $object->comment_content ) ) . '</blockquote>';
						break;
					default:
						$message .= '<p>' . \sprintf(
							/* translators:  %1$s commenter name, "%2$s post title */
							\__( 'There is a notification regarding a comment by %1$s on the post "%2$s".', 'scoped-notify' ),
							\esc_html( $commenter_name ),
							\esc_html( $post_title )
						) . '</p>';
				}
			} else {
				// Generic fallback
				$message .= '<p>' . \sprintf(
					/* translators: %s reason */
					\__( 'A notification was triggered with reason: %s', 'scoped-notify' ),
					\esc_html( $item->reason )
				) . '</p>';
			}

			// Add link if available
			if ( $object_link ) {
				$link_text = ( $object instanceof \WP_Comment ) ? \__( 'View Comment', 'scoped-notify' ) : \__( 'View Post', 'scoped-notify' );
				$message  .= '<p><a href="' . \esc_url( $object_link ) . '">' . $link_text . '</a></p>';
			}

			// TODO: Add link to manage notification preferences

			$message .= '<p>---</p>';
			// Add footer? e.g., site name
			/* translators: %s blog name */
			$message .= '<p><small>' . \sprintf( \__( 'This email was sent from %s.', 'scoped-notify' ), $blog_name ) . '</small></p>';
		} finally {
			// Restore blog context if switched
			if ( $switched_blog ) {
				\restore_current_blog();
			}
		}

		// Allow filtering of the message body
		return \apply_filters( 'scoped_notify_mail_message', $message, $object, $item );
	}

	/**
	 * Formats the items data for logging messages
	 *
	 * @param \stdClass                  $item   The notification item.
	 * @return string The formatted HTML message body.
	 */
	private function format_item( \stdClass $item ): string {
		return sprintf("blog_id %d, object_id %d, object_type %s, trigger_id %d",$item->blog_id,$item->object_id,$item->object_type,$item->trigger_id);
	}

}
