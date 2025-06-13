<?php
/**
 * @package Scoped_Notify
 */

declare(strict_types=1);

namespace Scoped_Notify;

enum Scope: string {
	case Network = 'network';
	case Blog    = 'blog';
	case Post    = 'post';
}
