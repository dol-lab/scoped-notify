<?php
/**
 * @package Scoped_Notify
 */

declare(strict_types=1);

namespace Scoped_Notify;

use Psr\Log\LoggerInterface;

trait Static_Logger_Trait {
	/**
	 * Logger instance
	 * @var LoggerInterface
	 */
	private static LoggerInterface $logger;

	/**
	 * Returns the static logger instance
	 *
	 * @return LoggerInterface  logger instance
	 */
	public static function logger(): LoggerInterface {
		if ( ! isset( self::$logger ) ) {
			self::$logger = Logger::create();
		}

		return self::$logger;
	}
}
