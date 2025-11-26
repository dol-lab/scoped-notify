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
