<?php
/**
 * Handles processing of notifications from the queue.
 *
 * @package Scoped_Notify
 */

// TODO strict_types clashes with apply_filter (~line 226)
// declare(strict_types=1);

namespace Scoped_Notify;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Processes notifications stored in the database queue.
 */
class Notification_Processor {
	use Static_Logger_Trait;

	const CHUNK_SIZE_FALLBACK = 300;

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
	 * @param \wpdb  $wpdb      WordPress database instance.
	 * @param string $table_name The name of the notifications DB table.
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
	 * @param int $limit      Maximum number of notifications to process in one run.
	 * @param int $time_limit Optional. Maximum execution time in seconds. Default 0 (auto-detect).
	 * @return int Number of notifications successfully processed (or attempted).
	 */
	public function process_queue( int $limit = 80, int $time_limit = 0 ): int {
		$logger = self::logger();

		$start_time = $this->get_current_time();

		if ( $time_limit > 0 ) {
			$calc_time_limit = $time_limit;
		} else {
			$max_execution_time = $this->get_max_execution_time();
			// If max_execution_time is 0 (unlimited) or very large, set a reasonable default limit for this batch
			if ( 0 === $max_execution_time || $max_execution_time > 300 ) {
				$max_execution_time = 300;
			}
			// Stop processing if we've used 80% of the time limit
			$calc_time_limit = (int) ( $max_execution_time * 0.8 );
		}

		$processed_count = 0;
		$now_utc         = gmdate( 'Y-m-d H:i:s' ); // Get current UTC time in MySQL format

		// Get pending notifications that are due
		// first get list of pending "objects"
		// Status is 'pending' AND (scheduled_send_time is NULL OR scheduled_send_time <= NOW())
		$items = $this->wpdb->get_results(
			$this->wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
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

		foreach ( $items as $row ) {
			$item = Notification_Item::from_db_row( $row );

			// Check for timeout before processing the next item
			if ( ( $this->get_current_time() - $start_time ) > $calc_time_limit ) {
				$logger->info( 'Time limit reached. Stopping queue processing.', array( 'processed_count' => $processed_count ) );
				break;
			}

			// Generate a unique lock key for this item
			$lock_key = 'sn_item_' . md5( "{$item->blog_id}_{$item->object_id}_{$item->object_type}_{$item->trigger_id}" );

			// Try to acquire lock
			if ( ! $this->acquire_lock( $lock_key ) ) {
				$logger->debug( "Could not acquire lock for {$item->format()}. Skipping." );
				continue;
			}

			try {
				$logger->debug( "Acquired lock for {$item->format()}. Processing..." );

				// Chunk users to avoid memory issues and allow timeout checks
				$chunk_size = absint( \get_site_option( SCOPED_NOTIFY_MAIL_CHUNK_SIZE, self::CHUNK_SIZE_FALLBACK ) );
				if ( $chunk_size < 1 ) {
					$chunk_size = self::CHUNK_SIZE_FALLBACK;
				}

				// Process users in batches until no more pending users are found or timeout
				while ( true ) {
					// Check for timeout before processing chunk
					if ( ( $this->get_current_time() - $start_time ) > $calc_time_limit ) {
						$logger->info( 'Time limit reached during user processing. Stopping.', array( 'processed_count' => $processed_count ) );
						break; // Break the while loop, finally block will release lock
					}

					// Fetch next batch of pending users
					// We fetch only up to chunk_size
					$user_list = $this->wpdb->get_results(
						$this->wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
							"SELECT user_id
							FROM {$this->notifications_table_name}
							WHERE
								blog_id = %d
								AND object_id = %d
								AND object_type = %s
								AND trigger_id = %d
								AND status = %s
							LIMIT %d
							",
							$item->blog_id,
							$item->object_id,
							$item->object_type,
							$item->trigger_id,
							'pending',
							$chunk_size
						)
					);

					if ( empty( $user_list ) ) {
						break; // No more pending users
					}

					$chunk_user_ids = array_column( $user_list, 'user_id' );

					// Mark THIS chunk as processing.
					// This is critical: We only mark the users we are about to send to.
					// If we crash after this, only these users are stuck.
					$marked = $this->bulk_update_notification_status( $item, $chunk_user_ids, 'processing', false );
					if ( ! $marked ) {
						$logger->warning( 'Failed to mark chunk of ' . count( $chunk_user_ids ) . ' users as processing. Skipping chunk.' );
						// If we can't mark them, we shouldn't send.
						// To avoid infinite loop, we must break or ensure we don't pick them up again?
						// If update failed, they are likely still pending (or DB error).
						// If we break, we stop processing this item. Safer.
						break;
					}

					$users = get_users(
						array(
							'include' => $chunk_user_ids,
							'fields'  => array( 'ID', 'user_email', 'display_name' ), // only what you need
							'blog_id' => 0, // get users across all blogs
						)
					);
					$logger->debug(
						'Fetched users from get_users',
						array(
							'fetched_users_ids'       => array_column( $users, 'ID' ),
							'original_chunk_user_ids' => $chunk_user_ids,
						)
					);

					// Identify users that were in the chunk but not found in the DB (deleted users)
					$fetched_user_ids = array_map( fn( $u ) => (int) $u->ID, $users );
					$missing_user_ids = array_diff( $chunk_user_ids, $fetched_user_ids );
					$logger->debug( 'Calculated missing user IDs', array( 'missing_user_ids' => $missing_user_ids ) );

					if ( ! empty( $missing_user_ids ) ) {
						$updated_rows = $this->bulk_update_notification_status( $item, $missing_user_ids, 'orphaned', false );
						$logger->info(
							'Marked users as orphaned (source user deleted)',
							array(
								'user_ids'      => $missing_user_ids,
								'rows_affected' => $updated_rows,
							)
						);
					}

					// Only continue processing with users that actually exist
					if ( empty( $users ) ) {
						$logger->debug( 'No valid users found in chunk after filtering missing ones. Skipping processing for this chunk.' );
						continue;
					}

					list($users_succeeded, $users_failed, $users_orphaned) = $this->process_single_notification( $item, $users ); // Process the single notification

					// Update status based on processing result
					if ( ! empty( $users_succeeded ) ) {
						$succeeded_ids = array_map( fn( $u ) => (int) $u->ID, $users_succeeded );
						$this->bulk_update_notification_status( $item, $succeeded_ids, 'sent', true );
						$processed_count += count( $users_succeeded );
					}

					if ( ! empty( $users_failed ) ) {
						$failed_ids = array_map( fn( $u ) => (int) $u->ID, $users_failed );
						$this->bulk_update_notification_status( $item, $failed_ids, 'failed', false );
					}

					if ( ! empty( $users_orphaned ) ) {
						$orphaned_ids = array_map( fn( $u ) => (int) $u->ID, $users_orphaned );
						$this->bulk_update_notification_status( $item, $orphaned_ids, 'orphaned', false );
					}

					if ( count( $users_failed ) > 0 ) {
						$logger->error( 'sending of notifications failed for some users', array( 'users_failed' => $users_failed ) );
					}
				}
			} finally {
				$this->release_lock( $lock_key );
			}

			// Optional: Add retry logic for failed items later
		}

		$logger->info( "Finished processing batch. Successfully processed {$processed_count} notifications." );

		if ( $processed_count > 0 ) {
			$stats = \get_site_option(
				'scoped_notify_total_sent_count',
				array(
					'count' => 0,
					'since' => \time(),
				)
			);

			// Migration: If it's a simple integer, convert to array
			if ( \is_numeric( $stats ) ) {
				$stats = array(
					'count' => (int) $stats,
					'since' => \time(),
				);
			}

			$stats['count'] += $processed_count;
			\update_site_option( 'scoped_notify_total_sent_count', $stats );
		}

		return $processed_count;
	}

	/**
	 * Acquires a named lock in MySQL.
	 *
	 * @param string $lock_name The name of the lock.
	 * @param int    $timeout   Timeout in seconds.
	 * @return bool True if lock acquired, false otherwise.
	 */
	private function acquire_lock( string $lock_name, int $timeout = 0 ): bool {
		$query  = $this->wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, $timeout );
		$result = $this->wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return '1' === (string) $result;
	}

	/**
	 * Releases a named lock in MySQL.
	 *
	 * @param string $lock_name The name of the lock.
	 * @return bool True if lock released, false otherwise.
	 */
	private function release_lock( string $lock_name ): bool {
		$query  = $this->wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name );
		$result = $this->wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return '1' === (string) $result;
	}


	/**
	 * Retrieves the channel associated with a trigger ID.
	 *
	 * @param int $trigger_id The trigger ID.
	 * @return string|null The channel name or null if not found.
	 */
	private function get_channel_for_trigger( int $trigger_id ): ?string {
		$logger = self::logger();

		$channel = $this->wpdb->get_var(
			$this->wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				'SELECT channel FROM ' . SCOPED_NOTIFY_TABLE_TRIGGERS . ' WHERE trigger_id = %d',
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
	 * Processes a single notification item, routing it to the correct channel handler.
	 *
	 * @param Notification_Item $item  The notification item data from the database.
	 * @param \WP_User[]        $users Array of users to whom the notification is sent.
	 * @return array An array containing three arrays: users succeeded, users failed, and users orphaned.
	 * @throws \Exception When the triggering object is not found or channel cannot be determined.
	 */
	public function process_single_notification( Notification_Item $item, array $users ): array {
		$logger = self::logger();

		// Switch to the correct blog context if in multisite
		$original_blog_id = \get_current_blog_id();
		$switched_blog    = \is_multisite() && $item->blog_id && $item->blog_id !== $original_blog_id;

		if ( $switched_blog ) {
			\switch_to_blog( $item->blog_id );
			$logger->debug( "Switched to blog ID {$item->blog_id} for processing notification queue item {$item->format()}" );
		}

		$users_orphaned  = array();
		$users_succeeded = array();
		$users_failed    = array();

		try {
			// Note: get_object handles blog switching internally
			$object = Notification_Item::get_related_object( $item->object_type, (int) $item->object_id, (int) $item->blog_id );
			if ( ! $object ) {
				// Object might have been deleted.
				$logger->info( "Triggering object {$item->object_type}:{$item->object_id} not found. Marking users as orphaned." );
				return array( array(), array(), $users );
			}

			$channel = $this->get_channel_for_trigger( (int) $item->trigger_id );
			if ( ! $channel ) {
				// Error already logged in get_channel_for_trigger
				// Mark as failed because we don't know the channel
				throw new \Exception( "Channel could not be determined for trigger_id {$item->trigger_id}." );
			}
			$logger->debug( "Determined channel '{$channel}' for notification item {$item->format()} via trigger_id {$item->trigger_id}." );

			if ( 'mail' === $channel ) {
				list($users_succeeded, $users_failed) = $this->send_notification_via_mail_channel( $users, $object, $item );
			} elseif ( \has_filter( 'scoped_notify_send_notification_channel_' . $channel ) ) {
				list($users_succeeded, $users_failed) = $this->send_notification_via_custom_channel( $channel, $users, $object, $item );
			} else {
				$logger->warning( "Notification channel '{$channel}' not implemented for item {$item->format()}" );
				$users_succeeded = array();
				$users_failed    = $users;
			}

			$logger->debug( "Sent notification item {$item->format()} via channel '{$channel}'." );
			return array( $users_succeeded, $users_failed, $users_orphaned );
		} catch ( \Exception $e ) {
			$logger->error(
				'Error processing notification queue item: ' . $e->getMessage(),
				array(
					'item'      => $item,
					'exception' => $e,
				)
			);
			return array( array(), $users, array() ); // On failure, all users are considered failed.
		} finally {
			if ( $switched_blog ) {
				\restore_current_blog();
				$logger->debug( "Restored original blog context (Blog ID: {$original_blog_id}) after processing item {$item->format()}" );
			}
		}
	}

	/**
	 * Handles sending notifications through the 'mail' channel, with chunking.
	 *
	 * @param \WP_User[]        $users       Array of user objects.
	 * @param mixed             $trigger_obj The triggering object (e.g., \WP_Post).
	 * @param Notification_Item $item        The notification item.
	 * @return \WP_User[][] An array containing two arrays: users succeeded and users failed.
	 */
	private function send_notification_via_mail_channel( array $users, $trigger_obj, Notification_Item $item ): array {
		$logger          = self::logger();
		$users_succeeded = array();
		$users_failed    = array();

		$chunk_size = absint( \get_site_option( SCOPED_NOTIFY_MAIL_CHUNK_SIZE, self::CHUNK_SIZE_FALLBACK ) );
		if ( $chunk_size < 1 ) {
			$chunk_size = self::CHUNK_SIZE_FALLBACK;
		}

		$user_chunks = array_chunk( $users, $chunk_size );

		foreach ( $user_chunks as $user_chunk ) {
			$sent = false;

			try {
				$sent = apply_filters( 'scoped_notify_third_party_send_mail_notification', false, $user_chunk, $trigger_obj, $item );
			} catch ( \Throwable $e ) {
				$logger->error(
					'Third-party mail notification hook threw an exception.',
					array(
						'exception' => $e,
						'user_ids'  => array_map( fn( $user ) => (int) $user->ID, $user_chunk ),
						'item'      => $item->format(),
					)
				);
				$sent = false;
			}

			if ( ! $sent ) {
				$user_emails = array_map( fn( $user ) => $user->user_email, $user_chunk );

				try {
					$sent = $this->send_mail_notification( $user_emails, $trigger_obj, $item );
				} catch ( \Throwable $e ) {
					$logger->error(
						'send_mail_notification threw an exception.',
						array(
							'exception' => $e,
							'user_ids'  => array_map( fn( $user ) => (int) $user->ID, $user_chunk ),
							'item'      => $item->format(),
						)
					);
					$sent = false;
				}
			}

			if ( $sent ) {
				$users_succeeded = array_merge( $users_succeeded, $user_chunk );
			} else {
				$users_failed = array_merge( $users_failed, $user_chunk );
			}
		}

		return array( $users_succeeded, $users_failed );
	}

	/**
	 * Handles sending notifications through a custom channel via WordPress filters.
	 *
	 * @param string            $channel     The name of the custom channel.
	 * @param \WP_User[]        $users       Array of user objects.
	 * @param mixed             $trigger_obj The triggering object (e.g., \WP_Post).
	 * @param Notification_Item $item        The notification item.
	 * @return \WP_User[][] An array containing two arrays: users succeeded and users failed.
	 */
	private function send_notification_via_custom_channel( string $channel, array $users, $trigger_obj, Notification_Item $item ): array {
		// The filter is expected to return: [ $succeeded_users, $failed_users, $not_processed_users ]
		list( $succeeded, $failed, $not_processed ) = \apply_filters(
			'scoped_notify_send_notification_channel_' . $channel,
			array( array(), array(), $users ), // Default return value
			$users,
			$trigger_obj,
			$item
		);

		$users_succeeded = $succeeded;
		$users_failed    = array_merge( $failed, $not_processed );

		return array( $users_succeeded, $users_failed );
	}



	/**
	 * Updates the status of a notification item for multiple users efficiently.
	 *
	 * @param Notification_Item $item           The notification item.
	 * @param array             $user_ids       Array of user IDs to update.
	 * @param string            $status         The new status ('processing', 'sent', 'failed').
	 * @param bool              $update_sent_at Whether to update the sent_at timestamp.
	 * @return int Number of rows affected.
	 */
	private function bulk_update_notification_status( Notification_Item $item, array $user_ids, string $status, bool $update_sent_at ): int {
		$logger = self::logger();

		if ( empty( $user_ids ) ) {
			return 0;
		}

		$set_clauses = array();
		$values      = array();

		$set_clauses[] = 'status = %s';
		$values[]      = $status;

		if ( $update_sent_at ) {
			$set_clauses[] = 'sent_at = %s';
			$values[]      = \current_time( 'mysql', true ); // Use UTC time
		}

		$user_ids_placeholder = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );

		$sql  = "UPDATE {$this->notifications_table_name} SET " . implode( ', ', $set_clauses );
		$sql .= " WHERE blog_id = %d AND object_id = %d AND object_type = %s AND trigger_id = %d AND user_id IN ($user_ids_placeholder)";

		// Combine all values for prepare: SET values, WHERE values, IN values
		$prepare_args = array_merge(
			$values,
			array( $item->blog_id, $item->object_id, $item->object_type, $item->trigger_id ),
			$user_ids
		);

		$sql = $this->wpdb->prepare( $sql, $prepare_args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$result = $this->wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( false === $result ) {
			$logger->error(
				"Failed to bulk update status to '{$status}' for {$item->format()}.",
				array(
					'error' => $this->wpdb->last_error,
					'query' => $this->wpdb->last_query,
				)
			);
			return 0;
		}
		$logger->debug( "Bulk update status to '{$status}' affected {$result} rows." );

		return (int) $result;
	}

	/**
	 * Updates the status of a notification item and optionally the sent_at timestamp.
	 * If no user_id is given, update all entries for given item (blog_id, object_id, object_type, trigger_id).
	 * If user_id is given, update only the item entry for this user.
	 *
	 * @param Notification_Item $item           The notification item.
	 * @param int|null          $user_id        The user ID - if given, update only the item entry for this user.
	 * @param string            $status         The new status ('processing', 'sent', 'failed').
	 * @param bool              $update_sent_at Whether to update the sent_at timestamp.
	 * @return bool True on success, false on failure.
	 */
	private function update_notification_status( Notification_Item $item, ?int $user_id, string $status, bool $update_sent_at ): bool {
		$logger = self::logger();

		$data   = array( 'status' => $status );
		$format = array( '%s' );

		$where_data   = array(
			'blog_id'     => $item->blog_id,
			'object_id'   => $item->object_id,
			'object_type' => $item->object_type,
			'trigger_id'  => $item->trigger_id,
		);
		$where_format = array( '%d', '%d', '%s', '%d' );
		if ( ! is_null( $user_id ) ) {
			$where_data['user_id'] = $user_id;
			$where_format[]        = '%d';
		}

		if ( $update_sent_at ) {
			$data['sent_at'] = \current_time( 'mysql', true ); // Use UTC time
			$format[]        = '%s';
		}

		$result = $this->wpdb->update(
			$this->notifications_table_name,
			$data,
			$where_data,
			$format, // format for data
			$where_format
		);

		if ( false === $result ) {
			$logger->error(
				"Failed to update status to '{$status}' for {$item->format()}, user_id {$user_id}.",
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
	 * Reverts 'processing' status back to 'pending' for a specific item.
	 * Used when a timeout occurs.
	 *
	 * @param Notification_Item $item The notification item.
	 */
	private function revert_processing_to_pending( Notification_Item $item ): void {
		$logger = self::logger();

		$sql = "UPDATE {$this->notifications_table_name}
				SET status = 'pending'
				WHERE blog_id = %d
				AND object_id = %d
				AND object_type = %s
				AND trigger_id = %d
				AND status = 'processing'";

		$query = $this->wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$sql,
			$item->blog_id,
			$item->object_id,
			$item->object_type,
			$item->trigger_id
		);

		$result = $this->wpdb->query( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( false === $result ) {
			$logger->error( "Failed to revert processing users to pending for {$item->format()}" );
		} else {
			$logger->info( "Reverted {$result} users from processing to pending for {$item->format()}" );
		}
	}

	/**
	 * Cleans up old sent notifications from the queue.
	 *
	 * @param int $retention_period Retention period in seconds.
	 * @return int Number of deleted rows.
	 */
	public function cleanup_old_notifications( int $retention_period ): int {
		$logger = self::logger();

		$cutoff_time = gmdate( 'Y-m-d H:i:s', time() - $retention_period );

		$sql = "DELETE FROM {$this->notifications_table_name}
				WHERE status = 'sent'
				AND sent_at <= %s";

		$query  = $this->wpdb->prepare( $sql, $cutoff_time ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$result = $this->wpdb->query( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( false === $result ) {
			$logger->error(
				'Failed to cleanup old notifications.',
				array(
					'error' => $this->wpdb->last_error,
					'query' => $this->wpdb->last_query,
				)
			);
			return 0;
		}

		if ( $result > 0 ) {
			$logger->info( "Cleaned up {$result} old sent notifications (older than {$cutoff_time})." );
		}

		return (int) $result;
	}

	/**
	 * Cleans up old failed notifications from the queue.
	 *
	 * @param int $retention_period Retention period in seconds.
	 * @return int Number of deleted rows.
	 */
	public function cleanup_failed_notifications( int $retention_period ): int {
		$logger = self::logger();

		$cutoff_time = gmdate( 'Y-m-d H:i:s', time() - $retention_period );

		$sql = "DELETE FROM {$this->notifications_table_name}
				WHERE status = 'failed'
				AND created_at <= %s";

		$query  = $this->wpdb->prepare( $sql, $cutoff_time ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$result = $this->wpdb->query( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( false === $result ) {
			$logger->error(
				'Failed to cleanup old failed notifications.',
				array(
					'error' => $this->wpdb->last_error,
					'query' => $this->wpdb->last_query,
				)
			);
			return 0;
		}

		if ( $result > 0 ) {
			$logger->info( "Cleaned up {$result} old failed notifications (older than {$cutoff_time})." );
		}

		return (int) $result;
	}

	/**
	 * Resets notifications that have been stuck in specified states for too long.
	 *
	 * @param int   $seconds_threshold The number of seconds after which an item is considered stuck. Default 1800 (30 minutes).
	 * @param array $statuses          The statuses to reset. Default array('processing').
	 * @return int The number of rows affected (reset to pending or sent).
	 */
	public function reset_stuck_items( int $seconds_threshold = 1800, array $statuses = array( 'processing' ) ): int {
		$logger = self::logger();

		if ( empty( $statuses ) ) {
			return 0;
		}

		// Calculate the cutoff time
		$cutoff_time = gmdate( 'Y-m-d H:i:s', time() - $seconds_threshold );
		$total_rows  = 0;

		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		// 1. Mark as 'sent' if sent_at exists (assume success)
		$sql_sent = "UPDATE {$this->notifications_table_name}
			 SET status = 'sent'
			 WHERE status IN ($placeholders)
			 AND sent_at IS NOT NULL
			 AND COALESCE(scheduled_send_time, created_at) <= %s";

		$args_sent   = $statuses;
		$args_sent[] = $cutoff_time;

		$query_sent = $this->wpdb->prepare( $sql_sent, $args_sent ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$res_sent   = $this->wpdb->query( $query_sent ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( false !== $res_sent && $res_sent > 0 ) {
			$logger->info( "Marked {$res_sent} stuck items as sent (had sent_at)." );
			$total_rows += $res_sent;
		}

		// 2. Reset to 'pending' if sent_at is NULL (retry)
		$sql_pending = "UPDATE {$this->notifications_table_name}
			 SET status = 'pending'
			 WHERE status IN ($placeholders)
			 AND sent_at IS NULL
			 AND COALESCE(scheduled_send_time, created_at) <= %s";

		$args_pending   = $statuses;
		$args_pending[] = $cutoff_time;

		$query_pending = $this->wpdb->prepare( $sql_pending, $args_pending ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$res_pending   = $this->wpdb->query( $query_pending ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( false !== $res_pending && $res_pending > 0 ) {
			$logger->info( "Reset {$res_pending} stuck items to pending (no sent_at)." );
			$total_rows += $res_pending;
		}

		return $total_rows;
	}

	/**
	 * Resets failed notifications back to pending status and increments fail_count.
	 *
	 * @return int The number of rows affected.
	 */
	public function move_failed_to_pending(): int {
		$logger = self::logger();

		// Get all failed items
		$failed_items = $this->wpdb->get_results(
			$this->wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				"SELECT queue_id, meta FROM {$this->notifications_table_name} WHERE status = %s",
				'failed'
			)
		);

		if ( empty( $failed_items ) ) {
			return 0;
		}

		$count = 0;
		foreach ( $failed_items as $item ) {
			$meta = null;
			if ( ! is_null( $item->meta ) ) {
				$meta = json_decode( $item->meta, true );
			}
			if ( ! is_array( $meta ) ) {
				$meta = array();
			}

			if ( ! isset( $meta['fail_count'] ) ) {
				$meta['fail_count'] = 0;
			}
			++$meta['fail_count'];

			$updated = $this->wpdb->update(
				$this->notifications_table_name,
				array(
					'status' => 'pending',
					'meta'   => wp_json_encode( $meta ),
				),
				array( 'queue_id' => $item->queue_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);

			if ( false !== $updated ) {
				++$count;
			}
		}

		if ( $count > 0 ) {
			$logger->info( "Reset {$count} failed items to pending with incremented fail_count." );
		}

		return $count;
	}

	private function get_author_email( $post_or_comment, $type ) {
		$email = '';
		if ( 'post' === $type ) {
			$user = get_userdata( $post_or_comment->post_author );
			if ( $user ) {
				$email = $user->user_email;
			}
		} elseif ( 'comment' === $type ) {
			$email = $post_or_comment->comment_author_email;
		}
		return $email;
	}

	private function remove_author_from_bcc( $bcc, $author_email ) {
		$bcc_array = explode( ', ', $bcc );
		foreach ( $bcc_array as $key => $value ) {
			if ( $value === $author_email ) {
				unset( $bcc_array[ $key ] );
			}
		}
		$bcc = implode( ', ', $bcc_array );
		return $bcc;
	}

	public function get_to_email() {
		/** @todo: make this a plugin-standalone value. */
		return get_site_option( 'spaces_mail_from', 'noreply@example.com' );
	}

	public function get_reply_to() {
		$reply_to = $this->get_to_email();
		if ( ! empty( $reply_to ) ) {
			return "Reply-To: (no-reply) <{$reply_to}>";
		} else {
			return '';
		}
	}

	/**
	 * Sends mail notification to users.
	 *
	 * @param array                $user_emails     Array of user email addresses.
	 * @param \WP_Post|\WP_Comment $post_or_comment_obj The triggering object.
	 * @param Notification_Item    $n_item            The notification item.
	 * @return bool True on success, false on failure.
	 */
	private function send_mail_notification( array $user_emails, $post_or_comment_obj, Notification_Item $n_item ): bool {
		$logger = self::logger();

		$logger->info(
			'--- Preparing Mail Notification ---',
			array(
				'user_emails' => $user_emails,
			)
		);

		// Ensure we are on the correct blog for formatting content (permalinks, bloginfo etc.)
		$original_blog_id = null;
		$switched_blog    = false;
		if ( \is_multisite() ) {
			$original_blog_id = \get_current_blog_id();
			if ( $n_item->blog_id && $n_item->blog_id !== $original_blog_id ) {
				\switch_to_blog( $n_item->blog_id );
				$switched_blog = true;
			}
		}

		$sent = false;
		try {
			/**
			 * Filter to customize the email body for post notifications. Allows overriding the default formatting.
			 * @param string                $body             The email body. Default empty.
			 * @param \WP_Post|\WP_Comment  $post_or_comment_obj  The triggering object.
			 * @param Notification_Item     $n_item             The notification item.
			 */
			$body = apply_filters( 'scoped_notify_email_body', '', $post_or_comment_obj, $n_item );
			if ( empty( $body ) ) {
				$body = $this->format_mail_message( $post_or_comment_obj, $n_item );
			}

			$default_email_data = array();
			if ( 'post' === $n_item->object_type && $post_or_comment_obj instanceof \WP_Post ) {
				$default_email_data['post_title']         = $post_or_comment_obj->post_title;
				$default_email_data['post_id']            = $post_or_comment_obj->ID;
				$author_user                              = \get_userdata( $post_or_comment_obj->post_author );
				$default_email_data['author_displayname'] = $author_user ? $author_user->display_name : \__( 'Unknown', 'scoped-notify' );
			} elseif ( 'comment' === $n_item->object_type && $post_or_comment_obj instanceof \WP_Comment ) {
				$related_post                             = \get_post( $post_or_comment_obj->comment_post_ID );
				$default_email_data['post_title']         = $related_post ? $related_post->post_title : \__( 'Unknown Post', 'scoped-notify' );
				$default_email_data['post_id']            = $post_or_comment_obj->comment_post_ID;
				$default_email_data['author_displayname'] = $post_or_comment_obj->comment_author;
			}

			$email_data = apply_filters( 'scoped_notify_prepare_email_data', $default_email_data, $post_or_comment_obj, $n_item->object_type );

			$author_email = $this->get_author_email( $post_or_comment_obj, $n_item->object_type );

			$blog_name = wp_specialchars_decode( get_blog_details()->blogname );
			$blog_id   = get_current_blog_id();

			// Sanitize host for the ID (remove http/https/slashes)
			$network_host = wp_parse_url( network_site_url() )['host'];
			$clean_host   = str_replace( array( 'http://', 'https://', '/' ), '', $network_host );

			if ( 'post' === $n_item->object_type ) {
				// The Subject Anchor. Comments must usually match this (with "Re:") to thread reliably.
				$subject = "[$blog_name] {$email_data['post_title']}";
			} elseif ( 'comment' === $n_item->object_type ) {
				$subject = "Re: [$blog_name] {$email_data['post_title']}";
			}

			$root_conversation_id = "<post-{$email_data['post_id']}-{$blog_id}@{$clean_host}>";

			$current_message_id = '';
			$reply_headers      = '';

			if ( 'post' === $n_item->object_type ) {
				$current_message_id = $root_conversation_id; // The post is the root.
				$reply_headers      = ''; // Posts don't reply to anything
			} elseif ( 'comment' === $n_item->object_type ) {
				// Comments need a unique ID
				$current_message_id = "<comment-{$post_or_comment_obj->comment_ID}-{$email_data['post_id']}-{$blog_id}@{$clean_host}>";

				// Point everything back to the Root Post ID to group by Post ID
				// In-Reply-To: The message we are directly responding to (The Post)
				// References:  The list of parents (The Post)
				$reply_headers = $root_conversation_id;
			}

			$bcc = $this->remove_author_from_bcc( implode( ',', $user_emails ), $author_email );

			$headers = array(
				'from'         => "From: {$email_data['author_displayname']} <{$author_email}>",
				'content-type' => 'Content-Type: text/html; charset=UTF-8',
				'reply-to'     => $this->get_reply_to(),
				'bcc'          => "Bcc: $bcc",
			);

			// Hook into PHPMailer
			// We pass the IDs calculated above into the closure
			$threading_hook = function ( $phpmailer ) use ( $current_message_id, $reply_headers ) {
				// Force the ID for this email (Must include < > brackets)
				$phpmailer->MessageID = $current_message_id; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

				// If it's a comment, add the glue headers
				if ( ! empty( $reply_headers ) ) {
					$phpmailer->addCustomHeader( 'In-Reply-To', $reply_headers );
					$phpmailer->addCustomHeader( 'References', $reply_headers );
				}
			};

			add_action( 'phpmailer_init', $threading_hook );

			$sent = \wp_mail( $this->get_to_email(), $subject, $body, $headers );

			remove_action( 'phpmailer_init', $threading_hook );
		} catch ( \Exception $e ) {
			$logger->error(
				'Exception during wp_mail: ' . $e->getMessage(),
				array(
					'exception' => $e,
				)
			);
		} finally {
			// Restore blog context if switched
			if ( $switched_blog ) {
				\restore_current_blog();
			}
		}

		if ( ! $sent ) {
			/*
			We get this with more detail from wp_mail_failed
			$logger->error('wp_mail failed',array());
			*/
			return false;
		}
		return true;
	}

	/**
	 * Formats the email subject line based on the object and notification item.
	 * Handles blog switching.
	 *
	 * @param \WP_Post|\WP_Comment|mixed $trigger_obj The triggering object.
	 * @param Notification_Item          $item        The notification item.
	 * @return string The formatted subject line.
	 */
	private function format_mail_subject( mixed $trigger_obj, Notification_Item $item ): string {
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
			$blog_name = html_entity_decode( \get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' );

			if ( $trigger_obj instanceof \WP_Post ) {
				switch ( $item->reason ) {
					case 'new_post':
						/* translators:  %1$s blog name, "%2$s post title */
						$subject = \sprintf( \__( '[%1$s] New Post Published: %2$s', 'scoped-notify' ), $blog_name, $trigger_obj->post_title );
						break;
					default: // Fallback for other post reasons
						/* translators:  %1$s blog name, "%2$s post title */
						$subject = \sprintf( \__( '[%1$s] Notification regarding Post: %2$s', 'scoped-notify' ), $blog_name, $trigger_obj->post_title );
				}
			} elseif ( $trigger_obj instanceof \WP_Comment ) {
				$post       = \get_post( $trigger_obj->comment_post_ID );
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
		return \apply_filters( 'scoped_notify_mail_subject', $subject, $trigger_obj, $item );
	}

	/**
	 * Formats the email message body (HTML) based on the user, object, and notification item.
	 * Handles blog switching.
	 *
	 * @param \WP_Post|\WP_Comment|mixed $trigger_obj The triggering object.
	 * @param Notification_Item          $item        The notification item.
	 * @return string The formatted HTML message body.
	 */
	private function format_mail_message( mixed $trigger_obj, Notification_Item $item ): string {
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

			if ( $trigger_obj instanceof \WP_Post ) {
				$object_link  = \get_permalink( $trigger_obj->ID );
				$object_title = $trigger_obj->post_title;
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
			} elseif ( $trigger_obj instanceof \WP_Comment ) {
				$object_link    = \get_comment_link( $trigger_obj );
				$post           = \get_post( $trigger_obj->comment_post_ID );
				$post_title     = $post ? $post->post_title : \__( 'a post', 'scoped-notify' );
				$commenter_name = $trigger_obj->comment_author;

				switch ( $item->reason ) {
					case 'new_comment':
						$message .= '<p>' . \sprintf(
							/* translators:  %1$s commenter name, "%2$s post title */
							\__( '%1$s left a new comment on the post "%2$s":', 'scoped-notify' ),
							\esc_html( $commenter_name ),
							\esc_html( $post_title )
						) . '</p>';
						$message .= '<blockquote>' . \wpautop( $trigger_obj->comment_content ) . '</blockquote>';
						break;
					case 'mention':
						$message .= '<p>' . \sprintf(
							/* translators:  %1$s commenter name, "%2$s post title */
							\__( 'You were mentioned by %1$s in a comment on the post "%2$s":', 'scoped-notify' ),
							\esc_html( $commenter_name ),
							\esc_html( $post_title )
						) . '</p>';
						$message .= '<blockquote>' . \wpautop( $trigger_obj->comment_content ) . '</blockquote>';
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
				$link_text = ( $trigger_obj instanceof \WP_Comment ) ? \__( 'View Comment', 'scoped-notify' ) : \__( 'View Post', 'scoped-notify' );
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
		return \apply_filters( 'scoped_notify_mail_message', $message, $trigger_obj, $item );
	}

	/**
	 * Get the current time.
	 *
	 * @return int Current timestamp.
	 */
	protected function get_current_time(): int {
		return time();
	}

	/**
	 * Get the max execution time.
	 *
	 * @return int Max execution time in seconds.
	 */
	protected function get_max_execution_time(): int {
		return (int) ini_get( 'max_execution_time' );
	}
}
