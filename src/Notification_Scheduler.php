<?php
/**
 * Handles notification scheduling logic.
 *
 * @package Scoped_Notify
 */

namespace Scoped_Notify;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

use Psr\Log\LoggerInterface;
use DateTime;
use DateTimeZone;

/**
 * Manages user notification schedules and calculates send times.
 */
class Notification_Scheduler {
	use Static_Logger_Trait;

	/**
	 * Default schedule type if none is found for a user.
	 * @var string
	 */
	const DEFAULT_SCHEDULE_TYPE = 'immediate';

	/**
	 * WordPress database object.
	 * @var \wpdb
	 */
	private $wpdb;

	/**
	 * Constructor.
	 *
	 * @param \wpdb           $wpdb   WordPress database instance.
	 */
	public function __construct( \wpdb $wpdb ) {
		$this->wpdb   = $wpdb;
	}

	/**
	 * Retrieves the notification schedule type for a user on a specific blog and channel.
	 * Handles blog switching.
	 *
	 * @param int    $user_id User ID.
	 * @param int    $blog_id Blog ID.
	 * @param string $channel Notification channel (e.g., 'mail').
	 * @return string Schedule type ('immediate', 'daily', 'weekly') or default.
	 */
	public function get_user_schedule( int $user_id, int $blog_id, string $channel ): string {
		$logger = self::logger();

		// Ensure we are on the correct blog to query user meta if needed later,
		// although the schedule table itself includes blog_id.
		$original_blog_id = null;
		$switched_blog    = false;
		if ( \is_multisite() ) {
			$original_blog_id = \get_current_blog_id();
			if ( $blog_id && $blog_id !== $original_blog_id ) {
				\switch_to_blog( $blog_id );
				$switched_blog = true;
			}
		}

		$schedule = self::DEFAULT_SCHEDULE_TYPE; // Default value
		try {
			// TODO: Get table name from config/central place
			$schedule_table = 'sn_user_blog_schedules';

			$db_schedule = $this->wpdb->get_var(
				$this->wpdb->prepare(
					"SELECT schedule_type FROM {$schedule_table} WHERE user_id = %d AND blog_id = %d AND channel = %s",
					$user_id,
					$blog_id,
					$channel
				)
			);

			if ( ! empty( $db_schedule ) ) {
				// TODO: Validate schedule type against allowed values?
				$schedule = $db_schedule;
				$logger->debug(
					"Found schedule '{$schedule}' for user {$user_id}, blog {$blog_id}, channel '{$channel}'.",
					array(
						'user_id'  => $user_id,
						'blog_id'  => $blog_id,
						'channel'  => $channel,
						'schedule' => $schedule,
					)
				);
			} else {
				$logger->debug(
					"No specific schedule found for user {$user_id}, blog {$blog_id}, channel '{$channel}'. Using default.",
					array(
						'user_id' => $user_id,
						'blog_id' => $blog_id,
						'channel' => $channel,
					)
				);
			}
		} finally {
			// Restore blog context if switched
			if ( $switched_blog ) {
				\restore_current_blog();
			}
		}

		return $schedule;
	}

	/**
	 * Calculates the scheduled send time based on the schedule type.
	 * Uses WordPress timezone settings.
	 *
	 * @param string $schedule_type Schedule type ('immediate', 'daily', 'weekly').
	 * @return string|null MySQL datetime string (UTC) for scheduled send, or null for immediate.
	 */
	public function calculate_scheduled_send_time( string $schedule_type ): ?string {
		$logger = self::logger();

		if ( $schedule_type === 'immediate' ) {
			return null; // Send immediately
		}

		// Get WordPress timezone setting
		$wp_timezone_string = \get_option( 'timezone_string' );
		$wp_timezone        = $wp_timezone_string ? new DateTimeZone( $wp_timezone_string ) : null;
		if ( ! $wp_timezone ) {
			// Fallback to UTC offset if timezone string is not set
			$gmt_offset = (float) \get_option( 'gmt_offset' );
			// Ensure offset format is correct for DateTimeZone (e.g., +02:00 or -05:30)
			$offset_prefix  = $gmt_offset < 0 ? '-' : '+';
			$offset_hours   = floor( abs( $gmt_offset ) );
			$offset_minutes = ( abs( $gmt_offset ) - $offset_hours ) * 60;
			$offset_string  = sprintf( '%s%02d:%02d', $offset_prefix, $offset_hours, $offset_minutes );
			try {
				$wp_timezone = new DateTimeZone( $offset_string );
			} catch ( \Exception $e ) {
				$logger->error( "Invalid timezone offset calculated: {$offset_string}. Falling back to UTC.", array( 'gmt_offset' => $gmt_offset ) );
				$wp_timezone = new DateTimeZone( 'UTC' ); // Fallback to UTC
			}
		}

		// Current time in WordPress timezone
		$now = new DateTime( 'now', $wp_timezone );

		// TODO: Make digest times configurable (e.g., via WP options or constants)
		$daily_time  = '08:00:00'; // e.g., 8 AM in WP timezone
		$weekly_day  = 'Monday'; // e.g., Every Monday in WP timezone
		$weekly_time = '09:00:00'; // e.g., 9 AM in WP timezone

		$scheduled_time_local = null;

		try {
			if ( $schedule_type === 'daily' ) {
				$scheduled_time_local = new DateTime( $now->format( 'Y-m-d' ) . ' ' . $daily_time, $wp_timezone );
				// If the target time for today has already passed, schedule for tomorrow
				if ( $scheduled_time_local <= $now ) { // Use <= to handle exact match case
					$scheduled_time_local->modify( '+1 day' );
				}
			} elseif ( $schedule_type === 'weekly' ) {
				// Calculate the next occurrence of the target day and time
				$scheduled_time_local = new DateTime( $now->format( 'Y-m-d' ) . ' ' . $weekly_time, $wp_timezone );
				$scheduled_time_local->modify( 'next ' . $weekly_day ); // Move to the next target day

				// Check if today *is* the target day but the time has already passed
				$today_target_time = new DateTime( $now->format( 'Y-m-d' ) . ' ' . $weekly_time, $wp_timezone );
				if ( $now->format( 'l' ) === $weekly_day && $now >= $today_target_time ) {
					// It's the correct day, but time is past, so schedule for next week's target day
					$scheduled_time_local->modify( '+1 week' );
				}
				// If 'next Monday' calculation resulted in a time earlier today (e.g., it's Monday morning before 9 AM),
				// it should be correct. If it resulted in a past date (shouldn't happen with 'next'), add a week.
				if ( $scheduled_time_local <= $now ) {
					// This might happen if 'next Monday' is today but time is past, handled above, but as safeguard:
					$logger->debug(
						'Weekly schedule calculation resulted in past/present time, adjusting to next week.',
						array(
							'now'        => $now->format( DateTime::ATOM ),
							'calculated' => $scheduled_time_local->format( DateTime::ATOM ),
						)
					);
					$scheduled_time_local->modify( '+1 week' );
				}
			} else {
				$logger->warning( "Unsupported schedule type '{$schedule_type}' encountered during send time calculation." );
				return null; // Treat unsupported as immediate for now
			}

			// Convert the calculated local time to UTC for storage
			$scheduled_time_utc = clone $scheduled_time_local;
			$scheduled_time_utc->setTimezone( new DateTimeZone( 'UTC' ) );
			$mysql_utc_time = $scheduled_time_utc->format( 'Y-m-d H:i:s' );

			$logger->debug(
				'Calculated scheduled send time.',
				array(
					'schedule_type'         => $schedule_type,
					'wp_timezone'           => $wp_timezone->getName(),
					'now_local_time'        => $now->format( 'Y-m-d H:i:s P' ),
					'calculated_local_time' => $scheduled_time_local->format( 'Y-m-d H:i:s P' ),
					'calculated_utc_time'   => $mysql_utc_time,
				)
			);

			return $mysql_utc_time;
		} catch ( \Exception $e ) {
			$logger->error(
				'Error calculating scheduled send time: ' . $e->getMessage(),
				array(
					'schedule_type' => $schedule_type,
					'wp_timezone'   => $wp_timezone->getName(),
					'exception'     => $e,
				)
			);
			return null; // Fallback to immediate on error
		}
	}
}
