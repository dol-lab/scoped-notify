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
			$default_level = defined( '\WP_DEBUG' ) && \WP_DEBUG ? 'info' : 'error';
			$base_level    = $default_level;

			// Optional global override.
			if ( function_exists( '\Env\env' ) && ! empty( \Env\env( 'SCOPED_NOTIFY_LOG_LEVEL' ) ) ) {
				$base_level = \Env\env( 'SCOPED_NOTIFY_LOG_LEVEL' );
			}

			// Optional per-target overrides.
			$db_level    = 'warning';
			$error_level = $base_level;

			if ( function_exists( '\Env\env' ) && ! empty( \Env\env( 'SCOPED_NOTIFY_LOG_LEVEL_DB' ) ) ) {
				$db_level = \Env\env( 'SCOPED_NOTIFY_LOG_LEVEL_DB' );
			}

			if ( function_exists( '\Env\env' ) && ! empty( \Env\env( 'SCOPED_NOTIFY_LOG_LEVEL_ERROR' ) ) ) {
				$error_level = \Env\env( 'SCOPED_NOTIFY_LOG_LEVEL_ERROR' );
			}

			$logger = new Logger\Router(
				new Logger\Db(),
				new Logger\Error_Log(),
				$db_level,
				$error_level
			);

			self::$logger = $logger; // store logger in property.
		}

		return $logger;
	}
}
