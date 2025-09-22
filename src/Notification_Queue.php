<?php
/**
 * Handles queueing and processing of notifications.
 *
 * @package Scoped_Notify
 */

declare(strict_types=1);

namespace Scoped_Notify;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

use Scoped_Notify\Notification_Scheduler;

/**
 * Manages the notification queue (individual notifications).
 */
class Notification_Queue {
	use Static_Logger_Trait;

	/**
	 * Default schedule type if none is found for a user.
	 * @var string
	 */
	const DEFAULT_SCHEDULE_TYPE = 'immediate';

	/**
	 * Database table name for individual notifications.
	 * @var string
	 */
	private $notifications_table_name; // Renamed from table_name

	/**
	 * Notification Resolver instance.
	 * @var Notification_Resolver
	 */
	private $resolver;

	/**
	 * WordPress database object.
	 * @var \wpdb
	 */
	private $wpdb;

	/**
	 * Notification Scheduler instance.
	 * This is used to determine the schedule for each user.
	 * @var Notification_Scheduler
	 */
	private $scheduler; // Add scheduler property

	/**
	 * Constructor.
	 *
	 * @param Notification_Resolver  $resolver  The notification resolver instance.
	 * @param Notification_Scheduler $scheduler The notification scheduler instance.
	 * @param \wpdb                  $wpdb      WordPress database instance.
	 */
	public function __construct( Notification_Resolver $resolver, Notification_Scheduler $scheduler, \wpdb $wpdb ) {
		$this->resolver  = $resolver;
		$this->scheduler = $scheduler; // Store scheduler instance
		$this->wpdb      = $wpdb;
		// TODO: Get table name from a central config/registry if possible
		$this->notifications_table_name = SCOPED_NOTIFY_TABLE_QUEUE; // Use new table name
	}

	/**
	 * Queues notifications for all relevant users based on a trigger event.
	 *
	 * Resolves recipients, checks their schedules, and inserts individual notification
	 * records into the SCOPED_NOTIFY_TABLE_QUEUE table.
	 *
	 * @param string   $object_type Type of the object triggering the notification.
	 * @param int      $object_id   ID of the object.
	 * @param string   $reason      Reason for the notification.
	 * @param int      $blog_id     The blog ID where the event occurred.
	 * @param array    $meta        Optional additional metadata.
	 * @param int|null $trigger_id  The ID of the trigger definition.
	 * @return int Number of notifications successfully queued.
	 */
	public function queue_event_notifications( string $object_type, int $object_id, string $reason, int $blog_id, array $meta = array(), ?int $trigger_id = null ): int {
		$logger = self::logger();

		if ( null === $trigger_id ) {
			$logger->error(
				'queue_event_notifications called without a trigger_id.',
				array(
					'object_type' => $object_type,
					'object_id'   => $object_id,
					'reason'      => $reason,
					'blog_id'     => $blog_id,
				)
			);
			return 0;
		}

		// Switch to the correct blog context if necessary (for resolving recipients)
		$original_blog_id = null;
		$switched_blog    = false;
		if ( \is_multisite() ) {
			$original_blog_id = \get_current_blog_id();
			if ( $blog_id && $blog_id !== $original_blog_id ) {
				\switch_to_blog( $blog_id );
				$switched_blog = true;
				$logger->debug( "Switched to blog ID {$blog_id} for recipient resolution." );
			}
		}

		$queued_count = 0;
		try {
			// 1. Get Channel from Trigger
			$trigger = $this->wpdb->get_row(
				$this->wpdb->prepare( 'SELECT channel FROM ' . SCOPED_NOTIFY_TABLE_TRIGGERS . ' WHERE trigger_id = %d', $trigger_id ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			);
			if ( ! $trigger || empty( $trigger->channel ) ) {
				throw new \Exception( "Could not find trigger or channel for trigger_id: {$trigger_id}" );
			}
			$channel = $trigger->channel;

			// 2. Get the triggering object (needed for recipient resolution)
			// Note: get_object handles blog switching internally now
			$object = $this->get_object( $object_type, $object_id, $blog_id );
			if ( ! $object ) {
				throw new \Exception( "Could not retrieve object {$object_type}:{$object_id} on blog {$blog_id}." );
			}

			// 3. Resolve Recipients using the Notification_Resolver based on object type
			// Ensure we are still on the correct blog context for the resolver
			$recipients = array();
			if ( $object instanceof \WP_Post ) {
				$recipients = $this->resolver->get_recipients_for_post( $object, $channel );
			} elseif ( $object instanceof \WP_Comment ) {
				$recipients = $this->resolver->get_recipients_for_comment( $object, $channel );
			} else {
				// Allow extension for other object types
				$recipients = \apply_filters( 'scoped_notify_resolve_recipients', array(), $object, $channel, $trigger_id );
				if ( empty( $recipients ) ) {
					$logger->warning(
						'Recipient resolution not handled for object type.',
						array(
							'object_type' => $object_type,
							'object_id'   => $object_id,
							'channel'     => $channel,
						)
					);
				}
			}

			if ( empty( $recipients ) ) {
				$logger->info(
					'No recipients resolved for event.',
					array(
						'object_type' => $object_type,
						'object_id'   => $object_id,
						'reason'      => $reason,
						'blog_id'     => $blog_id,
						'trigger_id'  => $trigger_id,
					)
				);
				// Restore blog context if switched
				if ( $switched_blog ) {
					\restore_current_blog(); }
				return 0;
			}

			$logger->info(
				'Resolved ' . \count( $recipients ) . ' recipients for event.',
				array(
					'object_type' => $object_type,
					'object_id'   => $object_id,
					'reason'      => $reason,
					'blog_id'     => $blog_id,
					'trigger_id'  => $trigger_id,
					'channel'     => $channel,
					'recipients'  => $recipients,
				)
			);

			// 4. Loop through recipients, determine schedule, and insert notification
			foreach ( $recipients as $user_id ) {
				// Use the scheduler to get schedule and calculate send time
				$user_schedule       = $this->scheduler->get_user_schedule( $user_id, $blog_id, $channel );
				$scheduled_send_time = $this->scheduler->calculate_scheduled_send_time( $user_schedule ); // Returns null for immediate

				// Prepare data for insertion
				$notification_data = array(
					'user_id'             => $user_id,
					'trigger_id'          => $trigger_id,
					'blog_id'             => $blog_id,
					'object_type'         => $object_type,
					'object_id'           => $object_id,
					'reason'              => $reason,
					'status'              => 'pending', // Initial status
					'scheduled_send_time' => $scheduled_send_time, // Can be null
					'meta'                => $meta, // Pass the original meta array
					'created_at'          => \current_time( 'mysql', true ),
				);

				$inserted = $this->insert_notification( $notification_data );

				if ( $inserted ) {
					++$queued_count;
				} else {
					// Log context already includes most of the data from notification_data
					$logger->error(
						"Failed to insert notification for user {$user_id}.",
						array(
							'user_id'  => $user_id,
							'channel'  => $channel,
							'schedule' => $user_schedule,
							// 'data' => $notification_data, // Avoid duplicating data already logged by insert_notification
						)
					);
				}
			}
		} catch ( \Exception $e ) {
			$logger->error(
				'Error queuing event notifications: ' . $e->getMessage(),
				array(
					'object_type' => $object_type,
					'object_id'   => $object_id,
					'reason'      => $reason,
					'blog_id'     => $blog_id,
					'trigger_id'  => $trigger_id,
					'exception'   => $e,
				)
			);
		} finally {
			// Restore blog context if switched
			if ( $switched_blog ) {
				\restore_current_blog();
				$logger->debug( "Restored original blog context (Blog ID: {$original_blog_id})." );
			}
		}

		$logger->info(
			"Successfully queued {$queued_count} individual notifications for event.",
			array(
				'object_type'      => $object_type,
				'object_id'        => $object_id,
				'reason'           => $reason,
				'blog_id'          => $blog_id,
				'trigger_id'       => $trigger_id,
				'total_recipients' => count( $recipients ),
			)
		);

		return $queued_count;
	}

	/**
	 * Inserts a single notification record into the database.
	 * Private method called by queue_event_notifications.
	 *
	 * @param array $notification_data Associative array containing notification details.
	 *                                 Expected keys: user_id, trigger_id, blog_id, object_type,
	 *                                 object_id, reason, status, scheduled_send_time, meta, created_at.
	 * @return int|false The ID of the inserted notification, or false on failure.
	 */
	private function insert_notification( array $notification_data ): int|false {
		$logger = self::logger();

		// Ensure required keys exist (optional, for robustness)
		$required_keys = array( 'user_id', 'trigger_id', 'blog_id', 'object_type', 'object_id', 'reason', 'status', 'scheduled_send_time', 'meta', 'created_at' );
		foreach ( $required_keys as $key ) {
			if ( ! array_key_exists( $key, $notification_data ) ) {
				$logger->error( "Missing required key '{$key}' in notification data.", $notification_data );
				return false;
			}
		}

		// Prepare data for wpdb->insert, encoding meta if it's an array
		$data = $notification_data;
		if ( is_array( $data['meta'] ) ) {
			$data['meta'] = \wp_json_encode( $data['meta'] );
		} elseif ( ! is_string( $data['meta'] ) ) {
			// Handle cases where meta might not be an array or string (e.g., null)
			$data['meta'] = \wp_json_encode( array() ); // Default to empty JSON object
		}

		// Define formats based on expected data types
		$format = array(
			'%d', // user_id
			'%d', // trigger_id
			'%d', // blog_id
			'%s', // object_type
			'%d', // object_id
			'%s', // reason
			'%s', // status
			'%s', // scheduled_send_time (use %s for null or string)
			'%s', // meta (always string after potential json_encode)
			'%s', // created_at
		);

		$result = $this->wpdb->insert( $this->notifications_table_name, $data, $format );

		if ( false === $result ) {
			$logger->error(
				'Failed to insert notification.',
				array(
					'data'  => $data, // Log the data prepared for insertion
					'error' => $this->wpdb->last_error,
					'query' => $this->wpdb->last_query,
				)
			);
			return false;
		}

		$notification_id = $this->wpdb->insert_id;
		// Log the original data array for clarity, before meta encoding
		$logger->info( "Notification record created (ID: {$notification_id}).", $notification_data );
		return $notification_id;
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
			if ( 'post' === $object_type ) {
				$object = \get_post( $object_id );
			} elseif ( 'comment' === $object_type ) {
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

	// --- Hook Callbacks ---

	/**
	 * Handles the 'save_post' action hook.
	 * Finds the relevant trigger and queues notifications for recipients.
	 * We want to use save_post and not publish_post, because we want to be able to send notifications for private posts too.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object. Use FQN
	 */
	public function handle_new_post( int $post_id, \WP_Post $post ) {
		$logger = self::logger();

		// Avoid infinite loops if updates trigger saves
		// Use global namespace for WordPress constants
		// this is likely not necessary
		if ( defined( '\DOING_AUTOSAVE' ) && \DOING_AUTOSAVE ) {
			$logger->debug( 'Autosave - no notifications sent' );
			return;
		}

		// @todo: maybe this should be status transition? -> then we have an issue if a users goes back and forth.
		$send_for_post_statuses = apply_filters( 'scoped_notify_send_for_post_status', array( 'publish' ) );
		if ( ! in_array( $post->post_status, $send_for_post_statuses, true ) ) {
			$configured_statuses = implode( ', ', $send_for_post_statuses );
			$logger->debug( "Post status '{$post->post_status}' not in configured statuses ({$configured_statuses}) - no notifications sent" );
			return;
		}

		// Check if this is a revision
		if ( wp_is_post_revision( $post_id ) ) {
			$logger->debug( 'Revision - no notifications sent' );
			return;
		}

		$all_post_triggers = $this->get_post_triggers();
		$selected_triggers = array_filter( $all_post_triggers, fn( $t ) => $t->post_type === $post->post_type );

		if ( empty( $selected_triggers ) ) {
			$configured_types = implode( ', ', array_map( fn( $t ) => $t->post_type, $all_post_triggers ) );
			$logger->debug( "Post type '{$post->post_type}' not in configured types ({$configured_types}) - no notifications sent" );
			return;
		}

		// now check if postmeta SCOPED_NOTIFY_META_NOTIFY_OTHERS is set
		if ( 'no' === get_post_meta( $post_id, SCOPED_NOTIFY_META_NOTIFY_OTHERS, true ) ) {
			$logger->debug( "For post with id {$post_id} notifications are disabled => no notification sent" );
			return;
		}

		// Check if this is the first time publishing (post_date_gmt === post_modified_gmt might be too strict)
		// A common check is to see if the previous status was not 'publish'
		// This requires hooking into 'transition_post_status' instead or checking previous status if available.
		// For 'save_post', we might queue on every update of a published post. Let's assume that's okay for now.
		// If only needed on first publish, hook 'publish_post' or check status transition.

		$blog_id = \get_current_blog_id();

		foreach ( $selected_triggers as $t ) {
			$reason       = 'new_' . $t->post_type; // 'new_post' or 'new_page' etc.
			$queued_count = $this->queue_event_notifications( $t->object_type, $post_id, $reason, $blog_id, array(), (int) $t->trigger_id );
		}
		$logger->info(
			"Finished queuing for '{$reason}' event.",
			array(
				'triggers'     => wp_json_encode( $selected_triggers ),
				'post_id'      => $post_id,
				'total_queued' => count( $selected_triggers ),
			)
		);

		do_action( 'sn_after_handle_new_post' );
	}

	/**
	 * Retrieves all defined triggers from the database (this is never much data).
	 * @phpstan-type TriggerObject object{ trigger_id: int, trigger_key: string, channel: string, object_type: string, post_type: string }
	 *
	 * @return TriggerObject[]
	 */
	public function get_triggers(): array {
		$results = $this->wpdb->get_results( 'SELECT * FROM ' . SCOPED_NOTIFY_TABLE_TRIGGERS ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( $results as &$result ) {
			list( $object_type, $post_type ) = explode( '-', $result->trigger_key );
			$result->object_type             = $object_type; // this is either 'post' or 'comment'.
			$result->post_type               = $post_type; // this is the post-type. so you can also specify, that there is a comment on a 'page'.
		}
		return $results;
	}

	/**
	 * Returns all triggers related to posts (not post-type "post" but any post type).
	 *
	 * @return TriggerObject[]
	 */
	public function get_post_triggers(): array {
		return array_filter( $this->get_triggers(), fn( $t ) => 'post' === $t->object_type );
	}

	/**
	 * Returns all triggers related to comments.
	 *
	 * @return TriggerObject[]
	 */
	public function get_comment_triggers(): array {
		return array_filter( $this->get_triggers(), fn( $t ) => 'comment' === $t->object_type );
	}

	/**
	 * Handles the 'wp_insert_comment' action hook.
	 * Finds the relevant trigger and queues notifications for recipients.
	 *
	 * @todo: use get_comment_triggers, like in handle_new_post.
	 *
	 * @param int         $comment_id The comment ID.
	 * @param \WP_Comment $comment    The comment object. Use FQN
	 */
	public function handle_new_comment( int $comment_id, \WP_Comment $comment ) {
		$logger = self::logger();

		// Only queue for approved comments
		if ( '1' !== $comment->comment_approved ) {
			return;
		}

		// Avoid queueing if comment is by the post author? Maybe configurable.

		$blog_id     = \get_current_blog_id();
		$reason      = 'new_comment';
		$object_type = 'comment';
		// Determine the trigger key based on the comment's post type
		$post = get_post( $comment->comment_post_ID );
		if ( ! $post ) {
			$logger->error(
				"Could not find post (ID: {$comment->comment_post_ID}) for comment {$comment_id}. Cannot determine trigger key.",
				array(
					'comment_id' => $comment_id,
					'blog_id'    => $blog_id,
				)
			);
			return;
		}
		$trigger_key = 'comment-' . $post->post_type; // e.g., 'comment-post', 'comment-page'

		// Find the trigger_id(s) for this trigger_key
		$query       = 'SELECT trigger_id FROM ' . SCOPED_NOTIFY_TABLE_TRIGGERS . ' WHERE trigger_key = %s';
		$trigger_ids = $this->wpdb->get_col( $this->wpdb->prepare( $query, $trigger_key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( empty( $trigger_ids ) ) {
			$logger->debug(
				"No triggers found for key '{$trigger_key}'. No notifications queued.",
				array(
					'comment_id' => $comment_id,
					'blog_id'    => $blog_id,
				)
			);
			// Still check for mentions even if no general comment trigger exists
			// $this->check_for_mentions_and_queue($comment, $blog_id);
			return;
		}

		$total_queued = 0;
		foreach ( $trigger_ids as $trigger_id ) {
			$queued_count  = $this->queue_event_notifications( $object_type, $comment_id, $reason, $blog_id, array(), (int) $trigger_id );
			$total_queued += $queued_count;
		}
		$logger->info(
			"Finished queuing for '{$reason}' event.",
			array(
				'trigger_key'  => $trigger_key,
				'comment_id'   => $comment_id,
				'total_queued' => $total_queued,
			)
		);

		do_action( 'sn_after_handle_new_comment' );

		// TODO: Add logic for 'mention' reason if applicable
		// $this->check_for_mentions_and_queue($comment, $blog_id);
	}
}
