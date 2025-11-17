<?php
/**
 * Handles sending notifications via ntfy.sh service.
 *
 * @package Scoped_Notify
 */

namespace Scoped_Notify;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Sends notifications to ntfy.sh topics.
 */
class Ntfy_Channel {
	use Static_Logger_Trait;

	/**
	 * Base URL for ntfy.sh service.
	 * @var string
	 */
	private string $base_url;

	/**
	 * WordPress database object.
	 * @var \wpdb
	 */
	private \wpdb $wpdb;

	/**
	 * Constructor.
	 *
	 * @param \wpdb  $wpdb     WordPress database instance.
	 * @param string $base_url Base URL for ntfy.sh (default: https://ntfy.sh).
	 */
	public function __construct( \wpdb $wpdb, string $base_url = 'https://ntfy.sh' ) {
		$this->wpdb     = $wpdb;
		$this->base_url = rtrim( $base_url, '/' );
	}

	/**
	 * Send notification to users via ntfy.sh.
	 *
	 * @param \WP_User[] $users      Array of user objects.
	 * @param mixed      $trigger_obj The triggering object (e.g., \WP_Post).
	 * @param \stdClass  $item       The notification item.
	 * @return array Array containing [succeeded_users, failed_users, not_processed_users].
	 */
	public function send( array $users, $trigger_obj, \stdClass $item ): array {
		$logger = self::logger();

		$succeeded      = array();
		$failed         = array();
		$not_processed  = array();

		// Group users by ntfy topic
		$users_by_topic = $this->group_users_by_topic( $users, (int) $item->blog_id );

		if ( empty( $users_by_topic ) ) {
			$logger->warning( 'No ntfy.sh topics configured for users in notification.', array( 'item' => $item ) );
			return array( array(), array(), $users );
		}

		foreach ( $users_by_topic as $topic => $topic_users ) {
			if ( empty( $topic ) ) {
				$logger->debug( 'Skipping users without ntfy topic configured.' );
				$not_processed = array_merge( $not_processed, $topic_users );
				continue;
			}

			// Send notification to this topic
			$sent = $this->send_to_topic( $topic, $trigger_obj, $item );

			if ( $sent ) {
				$succeeded = array_merge( $succeeded, $topic_users );
				$logger->info( "Notification sent to ntfy.sh topic '{$topic}' for " . count( $topic_users ) . ' user(s).' );
			} else {
				$failed = array_merge( $failed, $topic_users );
				$logger->error( "Failed to send notification to ntfy.sh topic '{$topic}'." );
			}
		}

		return array( $succeeded, $failed, $not_processed );
	}

	/**
	 * Groups users by their configured ntfy.sh topic.
	 *
	 * @param \WP_User[] $users   Array of user objects.
	 * @param int        $blog_id Blog ID.
	 * @return array Associative array of topic => users.
	 */
	private function group_users_by_topic( array $users, int $blog_id ): array {
		$logger = self::logger();
		$grouped = array();

		foreach ( $users as $user ) {
			$topic = $this->get_user_ntfy_topic( (int) $user->ID, $blog_id );

			if ( ! isset( $grouped[ $topic ] ) ) {
				$grouped[ $topic ] = array();
			}

			$grouped[ $topic ][] = $user;
		}

		return $grouped;
	}

	/**
	 * Retrieves the ntfy.sh topic for a user on a specific blog.
	 *
	 * @param int $user_id User ID.
	 * @param int $blog_id Blog ID.
	 * @return string|null The ntfy topic or null if not configured.
	 */
	private function get_user_ntfy_topic( int $user_id, int $blog_id ): ?string {
		$result = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT ntfy_topic, enabled FROM ' . SCOPED_NOTIFY_TABLE_USER_NTFY_CONFIG . ' WHERE user_id = %d AND blog_id = %d',
				$user_id,
				$blog_id
			)
		);

		if ( ! $result || ! $result->enabled ) {
			return null;
		}

		return $result->ntfy_topic;
	}

	/**
	 * Sends a notification to a specific ntfy.sh topic.
	 *
	 * @param string    $topic       The ntfy.sh topic.
	 * @param mixed     $trigger_obj The triggering object.
	 * @param \stdClass $item        The notification item.
	 * @return bool True on success, false on failure.
	 */
	private function send_to_topic( string $topic, $trigger_obj, \stdClass $item ): bool {
		$logger = self::logger();

		// Validate topic (only alphanumeric, hyphens, underscores)
		if ( ! preg_match( '/^[a-zA-Z0-9_-]+$/', $topic ) ) {
			$logger->error( "Invalid ntfy.sh topic format: {$topic}" );
			return false;
		}

		// Format notification
		$title   = $this->format_notification_title( $trigger_obj, $item );
		$message = $this->format_notification_message( $trigger_obj, $item );
		$url     = $this->get_notification_url( $trigger_obj );

		// Prepare payload
		$payload = array(
			'topic'   => $topic,
			'title'   => $title,
			'message' => $message,
			'tags'    => array( 'notification' ),
		);

		// Add URL if available
		if ( $url ) {
			$payload['click'] = $url;
		}

		// Send to ntfy.sh
		$response = wp_remote_post(
			"{$this->base_url}/{$topic}",
			array(
				'method'  => 'POST',
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) ) {
			$logger->error( 'ntfy.sh request failed: ' . $response->get_error_message() );
			return false;
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		if ( $http_code < 200 || $http_code >= 300 ) {
			$logger->error( "ntfy.sh returned HTTP status {$http_code}" );
			return false;
		}

		return true;
	}

	/**
	 * Formats the notification title based on the trigger object.
	 *
	 * @param mixed     $trigger_obj The triggering object.
	 * @param \stdClass $item        The notification item.
	 * @return string The formatted title.
	 */
	private function format_notification_title( $trigger_obj, \stdClass $item ): string {
		$blog_name = html_entity_decode( \get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' );

		if ( $trigger_obj instanceof \WP_Post ) {
			switch ( $item->reason ) {
				case 'new_post':
					return sprintf( '[%s] New Post: %s', $blog_name, $trigger_obj->post_title );
				default:
					return sprintf( '[%s] Post: %s', $blog_name, $trigger_obj->post_title );
			}
		} elseif ( $trigger_obj instanceof \WP_Comment ) {
			$post       = \get_post( $trigger_obj->comment_post_ID );
			$post_title = $post ? $post->post_title : __( 'a post', 'scoped-notify' );

			switch ( $item->reason ) {
				case 'new_comment':
					return sprintf( '[%s] New Comment on: %s', $blog_name, $post_title );
				case 'mention':
					return sprintf( '[%s] You were mentioned in: %s', $blog_name, $post_title );
				default:
					return sprintf( '[%s] Comment on: %s', $blog_name, $post_title );
			}
		}

		return sprintf( '[%s] Notification', $blog_name );
	}

	/**
	 * Formats the notification message body (text-only).
	 *
	 * @param mixed     $trigger_obj The triggering object.
	 * @param \stdClass $item        The notification item.
	 * @return string The formatted message.
	 */
	private function format_notification_message( $trigger_obj, \stdClass $item ): string {
		if ( $trigger_obj instanceof \WP_Post ) {
			$excerpt = wp_trim_words( strip_tags( $trigger_obj->post_content ), 30 );
			return $excerpt;
		} elseif ( $trigger_obj instanceof \WP_Comment ) {
			$author  = $trigger_obj->comment_author;
			$content = wp_trim_words( strip_tags( $trigger_obj->comment_content ), 30 );
			return sprintf( '%s: %s', $author, $content );
		}

		return sprintf( 'Notification: %s', $item->reason );
	}

	/**
	 * Gets the URL for the notification (post or comment link).
	 *
	 * @param mixed $trigger_obj The triggering object.
	 * @return string|null The URL or null if not available.
	 */
	private function get_notification_url( $trigger_obj ): ?string {
		if ( $trigger_obj instanceof \WP_Post ) {
			return \get_permalink( $trigger_obj->ID );
		} elseif ( $trigger_obj instanceof \WP_Comment ) {
			return \get_comment_link( $trigger_obj );
		}

		return null;
	}

	/**
	 * Saves or updates ntfy.sh configuration for a user on a blog.
	 *
	 * @param int    $user_id    User ID.
	 * @param int    $blog_id    Blog ID.
	 * @param string $ntfy_topic The ntfy.sh topic.
	 * @param bool   $enabled    Whether ntfy notifications are enabled.
	 * @return bool True on success, false on failure.
	 */
	public function save_user_config( int $user_id, int $blog_id, string $ntfy_topic, bool $enabled = true ): bool {
		$logger = self::logger();

		// Validate topic
		if ( ! preg_match( '/^[a-zA-Z0-9_-]+$/', $ntfy_topic ) ) {
			$logger->error( "Invalid ntfy.sh topic format: {$ntfy_topic}" );
			return false;
		}

		// Check if config exists
		$exists = $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(*) FROM ' . SCOPED_NOTIFY_TABLE_USER_NTFY_CONFIG . ' WHERE user_id = %d AND blog_id = %d',
				$user_id,
				$blog_id
			)
		);

		if ( $exists ) {
			// Update existing
			$result = $this->wpdb->update(
				SCOPED_NOTIFY_TABLE_USER_NTFY_CONFIG,
				array(
					'ntfy_topic' => $ntfy_topic,
					'enabled'    => $enabled ? 1 : 0,
				),
				array(
					'user_id' => $user_id,
					'blog_id' => $blog_id,
				),
				array( '%s', '%d' ),
				array( '%d', '%d' )
			);
		} else {
			// Insert new
			$result = $this->wpdb->insert(
				SCOPED_NOTIFY_TABLE_USER_NTFY_CONFIG,
				array(
					'user_id'    => $user_id,
					'blog_id'    => $blog_id,
					'ntfy_topic' => $ntfy_topic,
					'enabled'    => $enabled ? 1 : 0,
				),
				array( '%d', '%d', '%s', '%d' )
			);
		}

		if ( false === $result ) {
			$logger->error( 'Failed to save ntfy.sh config: ' . $this->wpdb->last_error );
			return false;
		}

		return true;
	}

	/**
	 * Deletes ntfy.sh configuration for a user on a blog.
	 *
	 * @param int $user_id User ID.
	 * @param int $blog_id Blog ID.
	 * @return bool True on success, false on failure.
	 */
	public function delete_user_config( int $user_id, int $blog_id ): bool {
		$result = $this->wpdb->delete(
			SCOPED_NOTIFY_TABLE_USER_NTFY_CONFIG,
			array(
				'user_id' => $user_id,
				'blog_id' => $blog_id,
			),
			array( '%d', '%d' )
		);

		return false !== $result;
	}
}
