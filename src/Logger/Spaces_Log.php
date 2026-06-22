<?php
/**
 * Logger that forwards messages to spaces-core's spaces_log().
 *
 * @package Scoped_Notify
 */

declare(strict_types=1);

namespace Scoped_Notify\Logger;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * Logger that delegates to spaces-core's spaces_log() so scoped-notify output is
 * unified with the surrounding Spaces logging when that plugin is active.
 *
 * Falls back to the plain PHP error_log logger when spaces_log() is unavailable,
 * keeping scoped-notify usable as a standalone plugin.
 */
class Spaces_Log extends AbstractLogger {

	/**
	 * The minimum log level to record.
	 *
	 * @var string
	 */
	private string $log_level_threshold = LogLevel::ERROR;

	/**
	 * Fallback PSR logger used when spaces_log() is not available.
	 *
	 * @var Error_Log
	 */
	private Error_Log $fallback;

	/**
	 * Sets up the error_log fallback used when spaces_log() is unavailable.
	 */
	public function __construct() {
		$this->fallback = new Error_Log();
	}

	/**
	 * Sets the minimum log level.
	 *
	 * @param 'debug'|'info'|'notice'|'warning'|'error'|'critical'|'alert'|'emergency' $level The minimum log level.
	 * @return void
	 */
	public function set_log_level( string $level ): void {
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
			$this->fallback->set_log_level( $level );
		} else {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf( 'Invalid log level provided to set_log_level: %s', $level ) );
		}
	}

	/**
	 * Logs with an arbitrary level.
	 *
	 * @param mixed  $level   The log level. See Psr\Log\LogLevel constants.
	 * @param string $message The message to log.
	 * @param array  $context The context data.
	 *
	 * @return void
	 */
	public function log( $level, $message, array $context = array() ): void {
		// When spaces-core is absent, defer entirely to the error_log fallback
		// (which applies its own threshold).
		if ( ! function_exists( '\spaces_log' ) ) {
			$this->fallback->log( $level, $message, $context );
			return;
		}

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

		if ( $current_level_priority < $log_threshold_priority ) {
			return;
		}

		// Tag the caller so unified Spaces logs are attributable to this plugin.
		if ( ! isset( $context['_source'] ) ) {
			$context['_source'] = 'scoped-notify';
		}

		\spaces_log( (string) $level, (string) $message, $context );
	}
}
