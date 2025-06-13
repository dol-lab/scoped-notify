<?php
/**
 * @package Scoped_Notify
 */

declare(strict_types=1);

namespace Scoped_Notify;

enum Notification_Preference: string {
	case Posts_Only         = 'posts-only';
	case Posts_And_Comments = 'posts-and-comments';
	case No_Notifications   = 'no-notifications';
}
