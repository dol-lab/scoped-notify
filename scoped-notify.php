<?php
/**
 * Plugin Name:       Scoped Notify
 * Version:           0.1
 * Author:            DolLab & V3
 * License:           GPL v2 or later
 * Text Domain:       snotify
 * Domain Path:       /languages
 * Network:           True
 * Namespace:         Scoped_Notify
 */

namespace Scoped_Notify;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

// Define constants
define( 'SCOPED_NOTIFY_VERSION', '0.1.1' ); // Incremented version to trigger DB update
define( 'SCOPED_NOTIFY_PLUGIN_FILE', __FILE__ );
define( 'SCOPED_NOTIFY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SCOPED_NOTIFY_DB_VERSION_OPTION', 'scoped_notify_db_version' );
define( 'SCOPED_NOTIFY_CRON_HOOK', 'scoped_notify_process_queue' ); // Define cron hook name.

// Include Composer autoloader if it exists.
if ( file_exists( SCOPED_NOTIFY_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once SCOPED_NOTIFY_PLUGIN_DIR . 'vendor/autoload.php';
} else {
	// Handle missing autoloader.
	add_action(
		'admin_notices',
		function () {
			$msg = esc_html__( 'Scoped Notify Error: Composer dependencies not found. Please run "composer install" in the plugin directory.', 'snotify' );
			echo "<div class='notice notice-error'><p>$msg</p></div>";
		}
	);
	return;
}


// CLI is included conditionally later.
require_once SCOPED_NOTIFY_PLUGIN_DIR . 'includes/class-notification-resolver.php';
require_once SCOPED_NOTIFY_PLUGIN_DIR . 'includes/class-notification-scheduler.php';
require_once SCOPED_NOTIFY_PLUGIN_DIR . 'includes/class-notification-queue.php';
require_once SCOPED_NOTIFY_PLUGIN_DIR . 'includes/class-notification-processor.php';
require_once SCOPED_NOTIFY_PLUGIN_DIR . 'includes/class-logger-error-log.php';

use DolLab\CustomTableManager\SchemaManager;
use DolLab\CustomTableManager\TableOperationException;
use DolLab\CustomTableManager\TableConfigurationException;
use Psr\Log\LoggerInterface;

register_activation_hook( SCOPED_NOTIFY_PLUGIN_FILE, __NAMESPACE__ . '\activate_plugin' );
register_deactivation_hook( SCOPED_NOTIFY_PLUGIN_FILE, __NAMESPACE__ . '\deactivate_plugin' );
register_uninstall_hook( SCOPED_NOTIFY_PLUGIN_FILE, __NAMESPACE__ . '\uninstall_plugin' );

add_action( 'network_admin_notices', __NAMESPACE__ . '\display_admin_notices' ); // For network activation.
add_action( 'admin_notices', __NAMESPACE__ . '\display_admin_notices' ); // For single site activation (fallback).
add_action( 'plugins_loaded', __NAMESPACE__ . '\check_for_updates' );
add_filter( 'cron_schedules', __NAMESPACE__ . '\add_cron_schedules' );

// Hook the processing function to the scheduled event.
add_action( SCOPED_NOTIFY_CRON_HOOK, __NAMESPACE__ . '\process_notification_queue_cron' );
add_action( 'plugins_loaded', __NAMESPACE__ . '\init', 20 ); // Run after update check and cron schedule definition.

// Trigger cron job processing after new post/comment handling. This is not async so user might have to wait.
add_action( 'sn_after_handle_new_post', __NAMESPACE__ . '\process_notification_queue_cron' );
add_action( 'sn_after_handle_new_comment', __NAMESPACE__ . '\process_notification_queue_cron' );


/**
 * Initialize the plugin. Load classes, add hooks.
 */
function init() {
	global $wpdb; // Make sure $wpdb is available.
	$logger        = get_logger();
	$resolver      = new Notification_Resolver( $wpdb, $logger );
	$scheduler     = new Notification_Scheduler( $logger, $wpdb ); // Instantiate scheduler
	$queue_manager = new Notification_Queue( $resolver, $scheduler, $logger, $wpdb ); // Pass scheduler

	// Add hooks for triggering queue additions.
	add_action( 'save_post', array( $queue_manager, 'handle_new_post' ), 10, 2 );
	add_action( 'wp_insert_comment', array( $queue_manager, 'handle_new_comment' ), 10, 2 );

	// Register WP-CLI command if WP_CLI is defined.
	// Note: WP_CLI constant is defined by WP-CLI itself.
	if ( defined( '\WP_CLI' ) && \WP_CLI ) {
		require_once SCOPED_NOTIFY_PLUGIN_DIR . 'includes/class-cli-command.php';
		\WP_CLI::add_command( 'scoped-notify', __NAMESPACE__ . '\CLI_Command' );
	}

	// Load text domain for localization.
	load_plugin_textdomain( 'snotify', false, \dirname( \plugin_basename( SCOPED_NOTIFY_PLUGIN_FILE ) ) . '/languages/' );
}

/**
 * Replace with a real logger.
 */
function get_logger(): LoggerInterface {
	$logger = new Logger_Error_Log();
	// Note: WP_DEBUG constant is defined in wp-config.php.
	$logger->set_log_level( defined( '\WP_DEBUG' ) && \WP_DEBUG ? 'debug' : 'error' ); // Set log level based on WP_DEBUG.
	return $logger;
}

/**
 * Plugin Activation Hook.
 * Installs or verifies database tables and schedules cron.
 */
function activate_plugin() {
	global $wpdb;
	$logger = get_logger();

	// --- Database Setup ---
	if ( ! class_exists( SchemaManager::class ) ) {
		$logger->critical( 'Scoped Notify Activation Error: Custom Table Manager class not found. Run composer install.' );
		set_transient( 'scoped_notify_admin_error', 'Activation failed: Custom Table Manager library missing. Please run composer install.', 60 );
		return; // Stop activation if critical component missing.
	}

	try {
		$config_file = SCOPED_NOTIFY_PLUGIN_DIR . 'config/database-tables.php';
		if ( ! file_exists( $config_file ) ) {
			throw new TableConfigurationException( 'Database configuration file not found at ' . $config_file );
		}

		$table_configs = require $config_file;
		if ( empty( $table_configs ) || ! \is_array( $table_configs ) ) {
			throw new TableConfigurationException( 'Invalid or empty table configurations loaded.' );
		}

		$db_manager = new SchemaManager( $table_configs, $wpdb, $logger );
		$db_manager->install(); // Creates tables if they don't exist.

		// --- Add Default Triggers ---
		$trigger_table_name = 'sn_triggers'; // Direct table name as per config
		$default_triggers   = array( 'post-post', 'comment-post' );

		foreach ( $default_triggers as $trigger_key ) {
			// Check if trigger already exists
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT trigger_id FROM `{$trigger_table_name}` WHERE trigger_key = %s AND channel = %s",
					$trigger_key,
					'mail' // Assuming default channel is 'mail'
				)
			);

			if ( null === $exists ) {
				$inserted = $wpdb->insert(
					$trigger_table_name,
					array(
						'trigger_key' => $trigger_key,
						'channel'     => 'mail', // Default channel
					),
					array( '%s', '%s' )
				);

				if ( false === $inserted ) {
						$logger->error( "Scoped Notify Activation: Failed to insert default trigger '{$trigger_key}'." );
						// Optionally set a transient error here too?
				} else {
					$logger->info( "Scoped Notify Activation: Default trigger '{$trigger_key}' inserted." );
				}
			} else {
				$logger->info( "Scoped Notify Activation: Default trigger '{$trigger_key}' already exists." );
			}
		}

		\update_site_option( SCOPED_NOTIFY_DB_VERSION_OPTION, SCOPED_NOTIFY_VERSION );
		$logger->info( 'Scoped Notify Activation: Database tables installed/verified and default triggers checked/added.' );
	} catch ( TableConfigurationException $e ) {
		$logger->error( 'Scoped Notify Activation Error: Invalid table configuration. ' . $e->getMessage() );
		set_transient( 'scoped_notify_admin_error', 'Activation failed: Invalid database table configuration. Check logs.', 60 );
		return; // Stop activation on DB config error.
	} catch ( TableOperationException $e ) {
		$logger->error( 'Scoped Notify Activation Error: Failed to install/verify tables. ' . $e->getMessage() );
		set_transient( 'scoped_notify_admin_error', 'Activation failed: Could not create database tables. Check logs and DB permissions.', 60 );
		return; // Stop activation on DB operation error.
	} catch ( \Exception $e ) {
		$logger->critical( 'Scoped Notify Activation Error: An unexpected error occurred during DB setup. ' . $e->getMessage() );
		set_transient( 'scoped_notify_admin_error', 'Activation failed: An unexpected error occurred during DB setup. Check logs.', 60 );
		return; // Stop activation on general DB error.
	}

	// --- Cron Scheduling ---
	if ( ! \wp_next_scheduled( SCOPED_NOTIFY_CRON_HOOK ) ) {
		\wp_schedule_event( \time(), 'every_five_minutes', SCOPED_NOTIFY_CRON_HOOK );
		$logger->info( 'Scoped Notify Activation: Notification queue processing cron scheduled.' );
	} else {
		$logger->info( 'Scoped Notify Activation: Notification queue processing cron already scheduled.' );
	}
}

/**
 * Plugin Deactivation Hook.
 * Unschedules the cron job.
 */
function deactivate_plugin() {
	$logger = get_logger();
	$is_dev = in_array( wp_get_environment_type(), array( 'development', 'local' ), true );

	if ( $is_dev ) {
		uninstall_plugin(); // Uninstall if in dev mode.
		$logger->info( 'Scoped Notify Deactivation: Uninstalling plugin in development mode.' );
	}

	$timestamp = \wp_next_scheduled( SCOPED_NOTIFY_CRON_HOOK );
	if ( $timestamp ) {
		\wp_unschedule_event( $timestamp, SCOPED_NOTIFY_CRON_HOOK );
		$logger->info( 'Scoped Notify Deactivation: Notification queue processing cron unscheduled.' );
	} else {
		$logger->info( 'Scoped Notify Deactivation: Notification queue processing cron was not scheduled.' );
	}
}

/**
 * Display admin notices for activation errors.
 */
function display_admin_notices() {
	if ( $error = \get_transient( 'scoped_notify_admin_error' ) ) {
		echo '<div class="notice notice-error is-dismissible"><p>';
		echo '<strong>' . esc_html__( 'Scoped Notify Error:', 'snotify' ) . '</strong> ' . \esc_html( $error );
		echo '</p></div>';
		\delete_transient( 'scoped_notify_admin_error' );
	}
}

/**
 * Check for database updates on plugins_loaded.
 * Compares stored DB version with plugin version.
 */
function check_for_updates() {
	global $wpdb;
	$logger               = get_logger();
	$installed_db_version = \get_site_option( SCOPED_NOTIFY_DB_VERSION_OPTION, '0.0.0' );

	if ( \version_compare( $installed_db_version, SCOPED_NOTIFY_VERSION, '<' ) ) {
		$logger->info( "Scoped Notify DB Update Check: Updating schema from v{$installed_db_version} to v" . SCOPED_NOTIFY_VERSION );

		if ( ! class_exists( SchemaManager::class ) ) {
			$logger->error( 'Scoped Notify Update Check: Custom Table Manager class not found.' );
			return;
		}

		try {
			$config_file = SCOPED_NOTIFY_PLUGIN_DIR . 'config/database-tables.php';
			if ( ! file_exists( $config_file ) ) {
				throw new TableConfigurationException( 'Database configuration file not found during update check.' );
			}
			$table_configs = require $config_file;
			if ( empty( $table_configs ) || ! \is_array( $table_configs ) ) {
				throw new TableConfigurationException( 'Invalid or empty table configurations loaded during update check.' );
			}

			$db_manager = new SchemaManager( $table_configs, $wpdb, $logger );
			$db_manager->update_table_version( $installed_db_version, SCOPED_NOTIFY_VERSION );

			\update_site_option( SCOPED_NOTIFY_DB_VERSION_OPTION, SCOPED_NOTIFY_VERSION );
			$logger->info( 'Scoped Notify Update Check: Database update process completed successfully to v' . SCOPED_NOTIFY_VERSION );
		} catch ( TableConfigurationException $e ) {
			$logger->error( 'Scoped Notify Update Check Error: Invalid table configuration. ' . $e->getMessage() );
			// Add admin notice? Consider transient error display.
		} catch ( TableOperationException $e ) {
			$logger->error( 'Scoped Notify Update Check Error: Failed to apply updates. ' . $e->getMessage() );
			// Add admin notice? Consider transient error display.
		} catch ( \Exception $e ) {
			$logger->critical( 'Scoped Notify Update Check Error: An unexpected error occurred. ' . $e->getMessage() );
			// Add admin notice? Consider transient error display.
		}
	}
}

/**
 * Add custom cron schedules.
 *
 * @param array $schedules Existing cron schedules.
 * @return array Modified schedules.
 */
function add_cron_schedules( $schedules ) {
	if ( ! isset( $schedules['every_five_minutes'] ) ) {
		$schedules['every_five_minutes'] = array(
			'interval' => 300, // 5 * 60 seconds
			'display'  => \__( 'Every Five Minutes', 'snotify' ),
		);
	}
	return $schedules;
}

/**
 * Plugin Uninstall Hook (Optional).
 * Removes tables and options. Consider using deactivation hook for unscheduling cron.
 */
function uninstall_plugin() {
	global $wpdb;
	$logger = get_logger();
	$is_dev = in_array( wp_get_environment_type(), array( 'development', 'local' ), true );
	// Make sure to not loose user-settings on production.
	if ( \get_site_option( 'scoped_notify_preserve_data', true ) && ! $is_dev ) {
		$logger->info( 'Scoped Notify Uninstall: Preserving data as requested.' );
		return;
	}

	if ( ! class_exists( SchemaManager::class ) ) {
		$logger->warning( 'Scoped Notify Uninstall: Custom Table Manager class not found. Tables may not be dropped.' );
	} else {
		try {
			$config_file   = SCOPED_NOTIFY_PLUGIN_DIR . 'config/database-tables.php';
			$table_configs = file_exists( $config_file ) ? require $config_file : array();
			$db_manager    = new SchemaManager( $table_configs, $wpdb, $logger );
			$db_manager->uninstall();
			$logger->info( 'Scoped Notify Uninstall: Table drop process completed (or tables did not exist).' );
		} catch ( TableConfigurationException $e ) {
			$logger->error( 'Scoped Notify Uninstall Error: Invalid table configuration during uninstall. ' . $e->getMessage() );
		} catch ( TableOperationException $e ) {
			$logger->error( 'Scoped Notify Uninstall Error: Failed to drop tables. ' . $e->getMessage() );
		} catch ( \Exception $e ) {
			$logger->critical( 'Scoped Notify Uninstall Error: An unexpected error occurred. ' . $e->getMessage() );
		}
	}

	// Delete options
	\delete_site_option( SCOPED_NOTIFY_DB_VERSION_OPTION );
	$logger->info( 'Scoped Notify Uninstall: Options deleted.' );
}

/**
 * Callback function for the WP-Cron event to process the notification queue.
 * @todo: Get all immediate users for the same post and process them in one go.
 */
function process_notification_queue_cron() {
	$logger = get_logger();
	$logger->info( 'Cron job started: ' . SCOPED_NOTIFY_CRON_HOOK );

	// Need to instantiate dependencies here as cron runs in a separate request context.
	global $wpdb; // Make sure $wpdb is available.
	// TODO: Get table name from config - Using 'sn_queue' as defined in config/database-tables.php
	$notifications_table = 'sn_queue';
	$processor           = new Notification_Processor( $logger, $wpdb, $notifications_table );

	try {
		// Process a limited number of items per run.
		$processed_count = $processor->process_queue( apply_filters( 'scoped_notify_cron_batch_limit', 20 ) ); // Allow filtering batch size.
		$logger->info( "Cron job finished: Processed {$processed_count} queue items." );
	} catch ( \Exception $e ) {
		$logger->critical( 'Cron job failed: Unhandled exception during queue processing. ' . $e->getMessage(), array( 'exception' => $e ) );
	}
}
