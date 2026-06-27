<?php
/**
 * Database logger writing scoped-notify entries to a custom table.
 *
 * @package Scoped_Notify
 */

declare(strict_types=1);

namespace Scoped_Notify\Logger;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

class Db extends AbstractLogger {
	/**
	 * The minimum log level to record.
	 *
	 * @var string
	 */
	private $log_level_threshold = LogLevel::ERROR;

	/**
	 * Fallback PSR logger that writes to PHP error_log.
	 *
	 * @var Error_Log
	 */
	private Error_Log $fallback;

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
			global $wpdb;

			if ( isset( $wpdb ) ) {
				$table_name = SCOPED_NOTIFY_TABLE_LOGS;
				$encoded    = ! empty( $context ) ? \wp_json_encode( $context ) : null;

				$inserted = $wpdb->insert(
					$table_name,
					array(
						'level'   => $level,
						'message' => $message,
						'data'    => $encoded,
					),
					array( '%s', '%s', '%s' )
				);

				if ( false !== $inserted ) {
					$this->trim_table( $wpdb, $table_name, 1000 );
				}
			}
		}

		// Always log to the PHP error log as before.
		$this->fallback->log( $level, $message, $context );
	}

	/**
	 * Trim table rows to the newest $limit entries.
	 *
	 * @param \wpdb  $wpdb  WPDB instance.
	 * @param string $table Fully-qualified table name.
	 * @param int    $limit Maximum number of rows to keep.
	 *
	 * @return void
	 */
	private function trim_table( \wpdb $wpdb, string $table, int $limit ): void {
		if ( $limit <= 0 ) {
			return;
		}

		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( $count <= $limit ) {
			return;
		}

		$offset = $count - $limit;
		$ids    = $wpdb->get_col(
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT id FROM {$table} ORDER BY id ASC LIMIT %d",
				$offset
			)
		);

		if ( empty( $ids ) ) {
			return;
		}

		$ids     = array_map( 'intval', $ids );
		$id_list = implode( ',', $ids );
		$wpdb->query( "DELETE FROM {$table} WHERE id IN ({$id_list})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}
}
