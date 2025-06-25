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

	public function getLabel(): string {
        return match($this)
		{
            self::Posts_Only			=> esc_html__( 'For Posts', 'scoped-notify' ),
            self::Posts_And_Comments	=> esc_html__( 'For Posts and Comments', 'scoped-notify' ),
            self::No_Notifications		=> esc_html__( 'No Notifications', 'scoped-notify' ),
        };
    }
}
