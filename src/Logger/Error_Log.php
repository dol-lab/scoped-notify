<?php
namespace Scoped_Notify\Logger;

use Psr\Log\LogLevel;
use Psr\Log\AbstractLogger;

/**
 * Logger that logs messages to the PHP error log, or to whoever listens to `scoped_notify_log`.
 *
 * Only logs messages with a level of ERROR or higher by default.
 */
class Error_Log extends AbstractLogger {

	/**
	 * The minimum log level to record.
	 *
	 * @var string
	 */
	private $log_level_threshold = LogLevel::ERROR;

	/**
	 * Sets the minimum log level.
	 *
	 * @param 'debug'|'info' | 'notice' | 'warning' | 'error' | 'critical' | 'alert' | 'emergency' $level The minimum log level.
	 * @return void
	 */
	public function set_log_level( string $level ): void {
		// Define valid log levels to ensure the provided level is correct.
		$valid_levels = array(
			LogLevel::DEBUG,
			LogLevel::INFO,
			LogLevel::NOTICE,
			LogLevel::WARNING,
			LogLevel::ERROR,
			LogLevel::CRITICAL,
			LogLevel::ALERT,
			LogLevel::EMERGENCY,
		);

		if ( in_array( $level, $valid_levels, true ) ) {
			$this->log_level_threshold = $level;
		} else {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( 'Invalid log level provided to set_log_level: %s', $level ) );
		}
	}

	/**
	 * Logs with an arbitrary level.
	 *
	 * @param mixed  $level    The log level. See Psr\Log\LogLevel constants.
	 * @param string $message  The message to log.
	 * @param array  $context  The context data.
	 *
	 * @return void
	 */
	public function log( $level, $message, array $context = array() ): void {
		$level_priority = array(
			LogLevel::DEBUG     => 1,
			LogLevel::INFO      => 2,
			LogLevel::NOTICE    => 3,
			LogLevel::WARNING   => 4,
			LogLevel::ERROR     => 5,
			LogLevel::CRITICAL  => 6,
			LogLevel::ALERT     => 7,
			LogLevel::EMERGENCY => 8,
		);

		$current_level_priority = $level_priority[ $level ] ?? 0;
		$log_threshold_priority = $level_priority[ $this->log_level_threshold ] ?? $level_priority[ LogLevel::ERROR ];

		if ( $current_level_priority >= $log_threshold_priority ) {
			$line = trim( $message . ' ' . ( ! empty( $context ) ? \wp_json_encode( $context ) : '' ) );
			if ( \has_action( 'scoped_notify_log' ) ) {
				/**
				 * Fires for every log line that would go to the PHP error log, so a host with its own logger can take it.
				 *
				 * @param string $level   PSR-3 level, e.g. 'warning' or 'error'.
				 * @param string $message The message, context appended as JSON.
				 * @param string $source  Always 'scoped-notify'.
				 */
				\do_action( 'scoped_notify_log', $level, $line, 'scoped-notify' );
				return;
			}
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( strtoupper( $level ) . ': ' . $line );
		}
	}
}
