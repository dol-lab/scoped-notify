<?php

declare(strict_types=1);

namespace Scoped_Notify\Logger;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * Routes log messages to DB and error_log with independent thresholds.
 */
class Router extends AbstractLogger {
	private AbstractLogger $db_logger;
	private AbstractLogger $error_logger;
	private string $db_threshold;
	private string $error_threshold;

	/**
	 * @param AbstractLogger $db_logger     Logger writing to DB.
	 * @param AbstractLogger $error_logger  Logger writing to PHP error_log.
	 * @param string         $db_threshold  Minimum level for DB logging.
	 * @param string         $error_threshold Minimum level for error_log.
	 */
	public function __construct( AbstractLogger $db_logger, AbstractLogger $error_logger, string $db_threshold, string $error_threshold ) {
		$this->db_logger       = $db_logger;
		$this->error_logger    = $error_logger;
		$this->db_threshold    = $this->normalize_level( $db_threshold );
		$this->error_threshold = $this->normalize_level( $error_threshold );

		// Sync underlying loggers if they support set_log_level.
		if ( method_exists( $this->db_logger, 'set_log_level' ) ) {
			$this->db_logger->set_log_level( $this->db_threshold );
		}
		if ( method_exists( $this->error_logger, 'set_log_level' ) ) {
			$this->error_logger->set_log_level( $this->error_threshold );
		}
	}

	/**
	 * Logs with an arbitrary level.
	 *
	 * @param mixed  $level   The log level. See Psr\Log\LogLevel constants.
	 * @param string $message The message to log.
	 * @param array  $context The context data.
	 */
	public function log( $level, $message, array $context = array() ): void {
		$level = $this->normalize_level( (string) $level );

		if ( $this->should_log( $level, $this->db_threshold ) ) {
			$this->db_logger->log( $level, $message, $context );
		}

		if ( $this->should_log( $level, $this->error_threshold ) ) {
			$this->error_logger->log( $level, $message, $context );
		}
	}

	/**
	 * @param string $level      Current log level.
	 * @param string $threshold  Minimum threshold.
	 *
	 * @return bool
	 */
	private function should_log( string $level, string $threshold ): bool {
		$priority = $this->priority_map();
		return ( $priority[ $level ] ?? 0 ) >= ( $priority[ $threshold ] ?? $priority[ LogLevel::ERROR ] );
	}

	/**
	 * Normalize a level string to a valid PSR-3 level.
	 *
	 * @param string $level Level string.
	 * @return string
	 */
	private function normalize_level( string $level ): string {
		$level = strtolower( $level );
		return array_key_exists( $level, $this->priority_map() ) ? $level : LogLevel::ERROR;
	}

	/**
	 * @return array<string,int>
	 */
	private function priority_map(): array {
		return array(
			LogLevel::DEBUG     => 1,
			LogLevel::INFO      => 2,
			LogLevel::NOTICE    => 3,
			LogLevel::WARNING   => 4,
			LogLevel::ERROR     => 5,
			LogLevel::CRITICAL  => 6,
			LogLevel::ALERT     => 7,
			LogLevel::EMERGENCY => 8,
		);
	}
}
