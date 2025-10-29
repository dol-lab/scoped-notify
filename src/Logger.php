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

		// create a new logger if none was stored before.
		if ( null === $logger ) {
			$logger = new Logger\Error_Log();
			// check if \Env\env function exists
			if ( function_exists( '\Env\env' ) ) {
				$level = \Env\env( 'SCOPED_NOTIFY_LOG_LEVEL', ( defined( '\WP_DEBUG' ) && \WP_DEBUG ? 'info' : 'error' ) );
			} else {
				$level = ( defined( '\WP_DEBUG' ) && \WP_DEBUG ? 'info' : 'error' );
			}
			$logger->set_log_level( $level );
			self::$logger = $logger; // store logger in property.
		}

		return $logger;
	}
}
