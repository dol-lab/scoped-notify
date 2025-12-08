<?php
/**
 * Plugin Name:       Scoped Notify
 * Version:           0.3
 * Author:            DolLab & V3
 * Description:       A flexible notification system for multisite WordPress installations, allowing users to customize notifications based on their preferences and site context.
 * License:           GPL v2 or later
 * Text Domain:       scoped-notify
 * Domain Path:       /languages
 * Network:           True
 * Namespace:         Scoped_Notify
 *
 * @package Scoped_Notify
 */

namespace Scoped_Notify;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

// Define constants
define( 'SCOPED_NOTIFY_VERSION', '0.3.0' ); // Incremented version to trigger DB update
define( 'SCOPED_NOTIFY_PLUGIN_FILE', __FILE__ );
define( 'SCOPED_NOTIFY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SCOPED_NOTIFY_DB_VERSION_OPTION', 'scoped_notify_db_version' );
define( 'SCOPED_NOTIFY_CRON_HOOK', 'scoped_notify_process_queue' ); // Define cron hook name.

// Names for site_options
define( 'SCOPED_NOTIFY_MAIL_CHUNK_SIZE', 'scoped_notify_mail_chunk_size' ); // how many email adresses to use in bcc:
define( 'SCOPED_NOTIFY_EMAIL_TO_ADDRESS', 'scoped_notify_email_to_address' ); // the to-adress for the mails

// The global default if no special notification settings exist on any level
define( 'SCOPED_NOTIFY_DEFAULT_NOTIFICATION_STATE', true ); // the default notification

// Define table names as constants
define( 'SCOPED_NOTIFY_TABLE_TRIGGERS', 'scoped_notify_triggers' );
define( 'SCOPED_NOTIFY_TABLE_USER_BLOG_SCHEDULES', 'scoped_notify_user_blog_schedules' );
define( 'SCOPED_NOTIFY_TABLE_QUEUE', 'scoped_notify_queue' );
define( 'SCOPED_NOTIFY_TABLE_SETTINGS_USER_PROFILES', 'scoped_notify_settings_user_profiles' );
define( 'SCOPED_NOTIFY_TABLE_SETTINGS_BLOGS', 'scoped_notify_settings_blogs' );
define( 'SCOPED_NOTIFY_TABLE_SETTINGS_TERMS', 'scoped_notify_settings_terms' );
define( 'SCOPED_NOTIFY_TABLE_SETTINGS_POST_COMMENTS', 'scoped_notify_settings_post_comments' );

// This meta is used so a post can set this to "no" if no notification should be sent out when the post is written.
define( 'SCOPED_NOTIFY_META_NOTIFY_OTHERS', 'scoped_notify_notify_others' );

// Default retention period for sent notifications (30 days).
define( 'SCOPED_NOTIFY_RETENTION_PERIOD', 30 * 24 * 60 * 60 );

// Include Composer autoloader if it exists.
if ( file_exists( SCOPED_NOTIFY_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once SCOPED_NOTIFY_PLUGIN_DIR . 'vendor/autoload.php';
} elseif ( ! class_exists( 'Roots\Bedrock\Autoloader' ) ) {
	// Handle missing autoloader if WordPress is not set up as bedrock installation.
	add_action(
		'admin_notices',
		function () {
			$msg = esc_html__( 'Scoped Notify Error: Composer dependencies not found. Please run "composer install" in the plugin directory.', 'scoped-notify' );
			echo "<div class='notice notice-error'><p>$msg</p></div>"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	);
	return;
}

use DolLab\CustomTableManager\SchemaManager;
use DolLab\CustomTableManager\TableOperationException;
use DolLab\CustomTableManager\TableConfigurationException;

register_activation_hook( SCOPED_NOTIFY_PLUGIN_FILE, __NAMESPACE__ . '\activate_plugin' );
register_deactivation_hook( SCOPED_NOTIFY_PLUGIN_FILE, __NAMESPACE__ . '\deactivate_plugin' );
register_uninstall_hook( SCOPED_NOTIFY_PLUGIN_FILE, __NAMESPACE__ . '\uninstall_plugin' );

add_action( 'network_admin_notices', __NAMESPACE__ . '\display_admin_notices' ); // For network activation.
add_action( 'admin_notices', __NAMESPACE__ . '\display_admin_notices' ); // For single site activation (fallback).
add_action( 'plugins_loaded', __NAMESPACE__ . '\check_for_updates' );
add_filter( 'cron_schedules', __NAMESPACE__ . '\add_cron_schedules' );

// Hook the processing function to the scheduled event.
add_action( SCOPED_NOTIFY_CRON_HOOK, __NAMESPACE__ . '\process_notification_queue_cron' );
add_action( 'plugins_loaded', __NAMESPACE__ . '\in_plugins_loaded', 20 ); // Run after update check and cron schedule definition.
add_action( 'init', __NAMESPACE__ . '\init', 20 );
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\enqueue_scripts', 1 );

// Trigger cron job processing after new post/comment handling. This is not async so user might have to wait.
add_action( 'sn_after_handle_new_post', __NAMESPACE__ . '\process_notification_queue_cron' );
add_action( 'sn_after_handle_new_comment', __NAMESPACE__ . '\process_notification_queue_cron' );

// register rest endpoint
add_action( 'rest_api_init', Rest_Api::register_routes( ... ) );

register_meta(
	'post',
	// if this meta is set to yes, a notification is sent to users when a post is done.
		// if this is set to no, no notification is sent on posting (but notifications are still send on comments)
		SCOPED_NOTIFY_META_NOTIFY_OTHERS,
	array(
		'type'         => 'string',
		'single'       => true,
		'default'      => 'unknown',
		'show_in_rest' => true,
	)
);

/**
 * Initialize the plugin. Load classes, add hooks.
 */
function in_plugins_loaded() {
	global $wpdb; // Make sure $wpdb is available.
	$resolver      = new Notification_Resolver( $wpdb );
	$scheduler     = new Notification_Scheduler( $wpdb ); // Instantiate scheduler
	$queue_manager = new Notification_Queue( $resolver, $scheduler, $wpdb ); // Pass scheduler
	$hooks_manager = new Notification_Hooks( $queue_manager );

	// Add hooks for triggering queue additions.
	// note: the handle_new_post function must be called after "save_post", because in save_post the meta-values are not yet set.
	add_action( 'wp_after_insert_post', array( $queue_manager, 'handle_new_post' ), 10, 4 );
	add_action( 'wp_insert_comment', array( $queue_manager, 'handle_new_comment' ), 10, 2 );

	add_action( 'delete_post', array( $hooks_manager, 'hook_trash_delete_post' ), 10, 1 );
	add_action( 'wp_trash_post', array( $hooks_manager, 'hook_trash_delete_post' ), 10, 1 );

	// use wpmu_delete_user for multisites
	add_action( 'wpmu_delete_user', array( $hooks_manager, 'hook_delete_user' ), 10, 1 );

	// use wp_uninitialize_site similar to Activity_Hooks.php
	add_action( 'wp_uninitialize_site', array( $hooks_manager, 'hook_delete_blog' ), 10, 1 );

	add_action( 'delete_term', array( $hooks_manager, 'hook_delete_term' ), 10, 1 );

	// Register WP-CLI command if WP_CLI is defined.
	// Note: WP_CLI constant is defined by WP-CLI itself.
	if ( defined( '\WP_CLI' ) && \WP_CLI ) {
		\WP_CLI::add_command( 'scoped-notify', __NAMESPACE__ . '\CLI_Command' );
	}
}



function init() {
	$ui               = new Notification_Ui(); // Create html for notification
	$network_admin_ui = new Network_Admin_Ui(); // Create network admin UI

	// Load text domain for localization.
	load_plugin_textdomain( 'scoped-notify', false, \dirname( \plugin_basename( SCOPED_NOTIFY_PLUGIN_FILE ) ) . '/languages/' );

	// add blog_settings to defaulttheme sidebar
	add_filter( 'default_space_setting', array( $ui, 'add_blog_settings_item' ) );

	// add comment_settings to dropdown in post card
	add_filter( 'ds_post_dot_menu_data', array( $ui, 'add_comment_settings_item' ), 10, 2 );
}

function enqueue_scripts() {
	$plugin_dir = plugin_dir_url( __DIR__ ) . 'scoped-notify';
	wp_register_style( 'scoped-notify', $plugin_dir . '/css/scoped-notify.css', array(), SCOPED_NOTIFY_VERSION );
	wp_enqueue_style( 'scoped-notify' );

	wp_register_script( 'scoped-notify', $plugin_dir . '/js/scoped-notify.js', array( 'jquery' ), '250902', false );

	wp_localize_script(
		'scoped-notify',
		'ScopedNotify',
		array(
			'rest' => array(
				// The rest_url function relies on the $wp_rewrite global class when pretty permalinks are enabled, which isn't available as early as the plugins_loaded action, but should instead be used with either the init or wp hook.
				'endpoint' => esc_url_raw( rest_url( Rest_Api::NAMESPACE . Rest_Api::ROUTE_SETTINGS ) ),
				'timeout'  => (int) apply_filters( 'scoped_notify_rest_timeout', 3000 ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
			),
			'i18n' => array(
				'profile_notifications_off' => wp_kses_post( __( 'Notifications are now off by default. However, you will <strong>still</strong> receive them for the following exceptions:', 'scoped-notify' ) ),
				'profile_notifications_on'  => wp_kses_post( __( 'Notifications are now on by default. However, you will <strong>not</strong> receive them for the following exceptions:', 'scoped-notify' ) ),
			),
		)
	);

	wp_enqueue_script( 'scoped-notify' );
}

/**
 * Plugin Activation Hook.
 * Installs or verifies database tables and schedules cron.
 */
function activate_plugin() {
	global $wpdb;
	$logger = Logger::create();

	// --- Database Setup ---
	if ( ! class_exists( SchemaManager::class ) ) {
		$logger->critical( 'Scoped Notify Activation Error: Custom Table Manager class not found. Run composer install.' );
		set_transient( 'scoped_notify_admin_error', 'Activation failed: Custom Table Manager library missing. Please run composer install.', 60 );
		return; // Stop activation if critical component missing.
	}

	\add_site_option( SCOPED_NOTIFY_MAIL_CHUNK_SIZE, 300 );
	\add_site_option( SCOPED_NOTIFY_EMAIL_TO_ADDRESS, '' );

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
		$default_triggers = array( 'post-post', 'comment-post' );

		foreach ( $default_triggers as $trigger_key ) {
			// Check if trigger already exists
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT trigger_id FROM `' . SCOPED_NOTIFY_TABLE_TRIGGERS . '` WHERE trigger_key = %s AND channel = %s',
					$trigger_key,
					'mail' // Assuming default channel is 'mail'
				)
			);

			if ( null === $exists ) {
				$inserted = $wpdb->insert(
					SCOPED_NOTIFY_TABLE_TRIGGERS,
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
	$logger = Logger::create();
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

	\delete_site_option( SCOPED_NOTIFY_MAIL_CHUNK_SIZE );
	\delete_site_option( SCOPED_NOTIFY_EMAIL_TO_ADDRESS );
}

/**
 * Display admin notices for activation errors.
 */
function display_admin_notices() {
	if ( $error = \get_transient( 'scoped_notify_admin_error' ) ) {
		echo '<div class="notice notice-error is-dismissible"><p>';
		echo '<strong>' . esc_html__( 'Scoped Notify Error:', 'scoped-notify' ) . '</strong> ' . \esc_html( $error );
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
	$logger               = Logger::create();
	$installed_db_version = \get_site_option( SCOPED_NOTIFY_DB_VERSION_OPTION, '0.0.0' );

	if ( '0.0.0' !== $installed_db_version && \version_compare( $installed_db_version, SCOPED_NOTIFY_VERSION, '<' ) ) {
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
			'display'  => \__( 'Every Five Minutes', 'scoped-notify' ),
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
	$logger = Logger::create();
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
	\delete_site_option( SCOPED_NOTIFY_MAIL_CHUNK_SIZE );
	\delete_site_option( SCOPED_NOTIFY_EMAIL_TO_ADDRESS );
	$logger->info( 'Scoped Notify Uninstall: Options deleted.' );
}

/**
 * Callback function for the WP-Cron event to process the notification queue.
 */
function process_notification_queue_cron() {
	$logger = Logger::create();
	$logger->info( 'Cron job started: ' . SCOPED_NOTIFY_CRON_HOOK );

	// Need to instantiate dependencies here as cron runs in a separate request context.
	global $wpdb; // Make sure $wpdb is available.
	$processor = new Notification_Processor( $wpdb, SCOPED_NOTIFY_TABLE_QUEUE );

	try {
		// Process a limited number of items per run.
		$processed_count = $processor->process_queue( apply_filters( 'scoped_notify_cron_batch_limit', 20 ) ); // Allow filtering batch size.
		$logger->info( "Cron job finished: Processed {$processed_count} queue items." );

		// Cleanup old sent notifications.
		$retention_period = (int) apply_filters( 'scoped_notify_retention_period', SCOPED_NOTIFY_RETENTION_PERIOD );
		if ( $retention_period > 0 ) {
			$processor->cleanup_old_notifications( $retention_period );
		}
		
		// Cleanup failed notifications (keep them for 30 days by default to allow manual retry).
		// We use the same retention period for simplicity, or a separate filter could be added.
		$failed_retention = (int) apply_filters( 'scoped_notify_failed_retention_period', 30 * DAY_IN_SECONDS );
		if ( $failed_retention > 0 ) {
			$processor->cleanup_failed_notifications( $failed_retention );
		}
	} catch ( \Exception $e ) {
		$logger->critical( 'Cron job failed: Unhandled exception during queue processing. ' . $e->getMessage(), array( 'exception' => $e ) );
	}
}
