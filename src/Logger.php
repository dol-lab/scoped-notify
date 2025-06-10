<?php
/**
 * @package Scoped_Notify
 */

declare(strict_types=1);

namespace Scoped_Notify;

use Psr\Log\LoggerInterface;

/**
 * Returns a logger instance
 */
class Logger {
	/**
	 * Logger instance
	 * @var LoggerInterface
	 */
	private static LoggerInterface $logger;

	/**
	 * Creates a logger instance and returns it
	 *
	 * @return LoggerInterface  logger instance
	 */
	public static function create(): LoggerInterface {
		$logger = self::$logger ?? null;

		// create a new logger if none was stored before
		if ( null === $logger ) {
			$logger = new Logger\Error_Log();

			// set log level based on WP_DEBUG constant defined in wp-config.php
			$logger->set_log_level(
				defined( '\WP_DEBUG' ) && \WP_DEBUG
				? 'debug'
				: 'error'
			);

			// store logger in property
			self::$logger = $logger;
		}

		return $logger;
	}
}
