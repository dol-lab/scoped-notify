<?php
/**
 * Represents a notification queue item.
 *
 * @package Scoped_Notify
 */

namespace Scoped_Notify;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Data class representing a single notification item from the queue.
 */
class Notification_Item {
	use Static_Logger_Trait;

	/**
	 * Blog ID where the notification originates.
	 *
	 * @var int
	 */
	public int $blog_id;

	/**
	 * Object ID (e.g., post ID, comment ID).
	 *
	 * @var int
	 */
	public int $object_id;

	/**
	 * Object type (e.g., 'post', 'comment').
	 *
	 * @var string
	 */
	public string $object_type;

	/**
	 * Trigger ID from the triggers table.
	 *
	 * @var int
	 */
	public int $trigger_id;

	/**
	 * Reason for the notification (e.g., 'new_post', 'new_comment').
	 *
	 * @var string
	 */
	public string $reason;

	/**
	 * Schedule type for the notification.
	 *
	 * @var string|null
	 */
	public ?string $schedule_type;

	/**
	 * Constructor.
	 *
	 * @param int         $blog_id       Blog ID.
	 * @param int         $object_id     Object ID.
	 * @param string      $object_type   Object type.
	 * @param int         $trigger_id    Trigger ID.
	 * @param string      $reason        Notification reason.
	 * @param string|null $schedule_type Schedule type.
	 */
	public function __construct(
		int $blog_id,
		int $object_id,
		string $object_type,
		int $trigger_id,
		string $reason,
		?string $schedule_type = null
	) {
		$this->blog_id       = $blog_id;
		$this->object_id     = $object_id;
		$this->object_type   = $object_type;
		$this->trigger_id    = $trigger_id;
		$this->reason        = $reason;
		$this->schedule_type = $schedule_type;
	}

	/**
	 * Creates a Notification_Item from a database row (stdClass).
	 *
	 * @param \stdClass $row Database row object.
	 * @return self
	 */
	public static function from_db_row( \stdClass $row ): self {
		return new self(
			(int) $row->blog_id,
			(int) $row->object_id,
			(string) $row->object_type,
			(int) $row->trigger_id,
			(string) $row->reason,
			$row->schedule_type ?? null
		);
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
	public static function get_related_object( string $object_type, int $object_id, int $blog_id ): mixed {
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

	/**
	 * Formats the item data for logging messages.
	 *
	 * @return string Formatted string representation.
	 */
	public function format(): string {
		return sprintf(
			'blog_id %d, object_id %d, object_type %s, trigger_id %d',
			$this->blog_id,
			$this->object_id,
			$this->object_type,
			$this->trigger_id
		);
	}
}
