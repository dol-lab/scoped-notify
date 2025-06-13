<?php
/**
 * @package Scoped_Notify
 */

declare(strict_types=1);

namespace Scoped_Notify;

enum Trigger_Key: string {
	case Post_Post    = 'post-post';
	case Comment_Post = 'comment-post';
}
