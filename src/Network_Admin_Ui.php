<?php
/**
 * Network Admin UI for Scoped Notify.
 *
 * @package Scoped_Notify
 */

declare(strict_types=1);

namespace Scoped_Notify;

defined( 'ABSPATH' ) || exit;

/**
 * Handles the Network Admin dashboard page.
 */
class Network_Admin_Ui {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'network_admin_menu', array( $this, 'add_network_admin_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_init', array( $this, 'process_queue_action' ) );
		add_action( 'admin_init', array( $this, 'reset_stuck_items_action' ) );
		add_action( 'admin_init', array( $this, 'save_settings_action' ) );
	}

	/**
	 * Handles the manual queue processing action.
	 */
	public function process_queue_action() {
		if ( isset( $_POST['scoped_notify_process_queue'] ) && check_admin_referer( 'scoped_notify_process_queue_action', 'scoped_notify_nonce' ) ) {
			global $wpdb;
			$processor = new Notification_Processor( $wpdb, SCOPED_NOTIFY_TABLE_QUEUE );
			try {
				$processed = $processor->process_queue( 50, 20 ); // Process up to 50 items manually, max 20 seconds
				add_action(
					'network_admin_notices',
					function () use ( $processed ) {
						$msg = sprintf( esc_html__( 'Processed %d notifications.', 'scoped-notify' ), $processed );
						echo "<div class='notice notice-success is-dismissible'><p>$msg</p></div>";
					}
				);
			} catch ( \Exception $e ) {
				add_action(
					'network_admin_notices',
					function () use ( $e ) {
						$msg = sprintf( esc_html__( 'Error processing queue: %s', 'scoped-notify' ), $e->getMessage() );
						echo "<div class='notice notice-error is-dismissible'><p>$msg</p></div>";
					}
				);
			}
		}
	}

	/**
	 * Handles the reset stuck items action.
	 */
	public function reset_stuck_items_action() {
		if ( isset( $_POST['scoped_notify_reset_stuck'] ) && check_admin_referer( 'scoped_notify_reset_stuck_action', 'scoped_notify_nonce_stuck' ) ) {
			global $wpdb;
			$processor = new Notification_Processor( $wpdb, SCOPED_NOTIFY_TABLE_QUEUE );
			try {
				$reset_count = $processor->reset_stuck_items( 1800, array( 'processing' ) ); // 30 minutes
				add_action(
					'network_admin_notices',
					function () use ( $reset_count ) {
						$msg = sprintf( esc_html__( 'Reset %d stuck notifications to pending.', 'scoped-notify' ), $reset_count );
						echo "<div class='notice notice-success is-dismissible'><p>$msg</p></div>";
					}
				);
			} catch ( \Exception $e ) {
				add_action(
					'network_admin_notices',
					function () use ( $e ) {
						$msg = sprintf( esc_html__( 'Error resetting stuck items: %s', 'scoped-notify' ), $e->getMessage() );
						echo "<div class='notice notice-error is-dismissible'><p>$msg</p></div>";
					}
				);
			}
		}
	}

	/**
	 * Handles saving of network settings.
	 */
	public function save_settings_action() {
		if ( isset( $_POST['scoped_notify_save_settings'] ) && check_admin_referer( 'scoped_notify_save_settings_action', 'scoped_notify_settings_nonce' ) ) {
			if ( isset( $_POST['scoped_notify_mail_chunk_size'] ) ) {
				$chunk_size = absint( $_POST['scoped_notify_mail_chunk_size'] );
				if ( $chunk_size > 0 ) {
					update_site_option( SCOPED_NOTIFY_MAIL_CHUNK_SIZE, $chunk_size );
					add_action(
						'network_admin_notices',
						function () {
							echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'scoped-notify' ) . '</p></div>';
						}
					);
				}
			}
		}
	}

	/**
	 * Enqueues admin CSS and JavaScript.
	 */
	public function enqueue_admin_assets() {
		wp_enqueue_style(
			'scoped-notify-admin-style',
			plugin_dir_url( __DIR__ ) . 'css/scoped-notify-admin.css',
			array(),
			SCOPED_NOTIFY_VERSION
		);
	}

	/**
	 * Adds the menu page.
	 */
	public function add_network_admin_page() {
		add_menu_page(
			__( 'Scoped Notify', 'scoped-notify' ),
			__( 'Scoped Notify', 'scoped-notify' ),
			'manage_network_options',
			'scoped-notify',
			array( $this, 'render_admin_page' ),
			'dashicons-email-alt'
		);
	}

	/**
	 * Renders the admin page content.
	 */
	public function render_admin_page() {
		global $wpdb;

		// Get all defined tables from constants
		$tables = array(
			'Triggers'                => SCOPED_NOTIFY_TABLE_TRIGGERS,
			'Queue'                   => SCOPED_NOTIFY_TABLE_QUEUE,
			'User Blog Schedules'     => SCOPED_NOTIFY_TABLE_USER_BLOG_SCHEDULES,
			'Settings: Profiles'      => SCOPED_NOTIFY_TABLE_SETTINGS_USER_PROFILES,
			'Settings: Blogs'         => SCOPED_NOTIFY_TABLE_SETTINGS_BLOGS,
			'Settings: Terms'         => SCOPED_NOTIFY_TABLE_SETTINGS_TERMS,
			'Settings: Post Comments' => SCOPED_NOTIFY_TABLE_SETTINGS_POST_COMMENTS,
		);

		$descriptions = array(
			SCOPED_NOTIFY_TABLE_TRIGGERS               => __( 'Defines triggers for notifications and the channel used to send them.', 'scoped-notify' ),
			SCOPED_NOTIFY_TABLE_QUEUE                  => __( 'Holds notifications waiting to be processed and sent. Includes details like recipient, context, trigger, reason, and schedule.', 'scoped-notify' ),
			SCOPED_NOTIFY_TABLE_USER_BLOG_SCHEDULES    => __( 'Stores the user\'s preferred notification delivery schedule (e.g., immediate, daily, weekly) per blog and channel.', 'scoped-notify' ),
			SCOPED_NOTIFY_TABLE_SETTINGS_USER_PROFILES => __( 'Stores user notification preferences that apply network-wide (across all blogs).', 'scoped-notify' ),
			SCOPED_NOTIFY_TABLE_SETTINGS_BLOGS         => __( 'Stores user notification preferences specific to individual blogs.', 'scoped-notify' ),
			SCOPED_NOTIFY_TABLE_SETTINGS_TERMS         => __( 'Stores user notification preferences specific to taxonomy terms within a blog.', 'scoped-notify' ),
			SCOPED_NOTIFY_TABLE_SETTINGS_POST_COMMENTS => __( 'Stores user notification preferences specifically for comments on a particular post.', 'scoped-notify' ),
		);

		$title       = esc_html__( 'Scoped Notify Tables Overview', 'scoped-notify' );
		$btn_process = esc_attr__( 'Process Pending Now', 'scoped-notify' );
		$btn_reset   = esc_attr__( 'Reset Stuck Items (> 30m)', 'scoped-notify' );
		$desc        = esc_html__( 'Click on a table name to expand and see more details.', 'scoped-notify' );

		echo "<div class='wrap'><h1>$title</h1>";

		$stats = \get_site_option(
			'scoped_notify_total_sent_count',
			array(
				'count' => 0,
				'since' => \time(),
			)
		);
		if ( \is_numeric( $stats ) ) {
			$stats = array(
				'count' => (int) $stats,
				'since' => \time(),
			);
		}
		$count = $stats['count'];
		$since = \gmdate( 'Y-m-d H:i:s', $stats['since'] ) . ' GMT';

		echo "<div class='card' style='max-width: 100%; margin-top: 10px; margin-bottom: 20px;'>
			<h2 class='title'>" . esc_html__( 'System Statistics', 'scoped-notify' ) . '</h2>
			<p>' . sprintf(
				wp_kses(
					/* translators: %d: number of notifications */
					__( 'Total Notifications Sent: <strong>%d</strong>', 'scoped-notify' ),
					array( 'strong' => array() )
				),
				$count
			) . '</p>
			<p>' . sprintf( esc_html__( 'Since: %s', 'scoped-notify' ), $since ) . '</p>
		</div>';

		// Process Queue Button
		echo "<div style='display: flex; gap: 10px; margin-bottom: 20px;'>
			<form method='post' action=''>";
		wp_nonce_field( 'scoped_notify_process_queue_action', 'scoped_notify_nonce' );
		echo "<input type='submit' name='scoped_notify_process_queue' class='button button-primary' value='$btn_process'></form>
			<form method='post' action=''>";
		wp_nonce_field( 'scoped_notify_reset_stuck_action', 'scoped_notify_nonce_stuck' );
		echo "<input type='submit' name='scoped_notify_reset_stuck' class='button button-secondary' value='$btn_reset'></form>
		</div>";

		// Settings Section.
		$chunk_size   = (int) get_site_option( SCOPED_NOTIFY_MAIL_CHUNK_SIZE, 400 );
		$lbl_settings = esc_html__( 'Global Settings', 'scoped-notify' );
		$lbl_chunk    = esc_html__( 'Mail Chunk Size', 'scoped-notify' );
		$desc_chunk   = esc_html__( 'Number of recipients per email (BCC).', 'scoped-notify' );
		$btn_save     = esc_attr__( 'Save Settings', 'scoped-notify' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		printf(
			'<div class="card" style="max-width: 100%%; margin-bottom: 20px;">
			<h2 class="title">%s</h2>
			<form method="post" action="">
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="scoped_notify_mail_chunk_size">%s</label></th>
							<td>
								<input name="scoped_notify_mail_chunk_size" type="number" step="1" min="1" id="scoped_notify_mail_chunk_size" value="%d" class="regular-text">
								<p class="description">%s</p>
							</td>
						</tr>
					</tbody>
				</table>',
			esc_html( $lbl_settings ),
			esc_html( $lbl_chunk ),
			(int) $chunk_size,
			esc_html( $desc_chunk )
		);
		wp_nonce_field( 'scoped_notify_save_settings_action', 'scoped_notify_settings_nonce' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		printf(
			'<p class="submit"><input type="submit" name="scoped_notify_save_settings" id="submit" class="button button-primary" value="%s"></p>
			</form>
		</div>',
			esc_attr( $btn_save )
		);

		echo "<p>$desc</p>";

		foreach ( $tables as $label => $table_name ) {
			// Check if table exists
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name;

			// Check for open state from URL
			$is_open   = isset( $_GET[ 'paged_' . $table_name ] );
			$open_attr = $is_open ? ' open' : '';

			echo "<details class='scoped-notify-table-details'$open_attr><summary>";

			if ( ! $table_exists ) {
				echo esc_html( "$label ($table_name) - " . __( 'Missing', 'scoped-notify' ) );
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$count = $wpdb->get_var( "SELECT COUNT(*) FROM `$table_name`" );
				echo esc_html( "$label ($table_name) - $count rows" );
			}
			echo "</summary><div class='scoped-notify-table-content'>";

			if ( ! $table_exists ) {
				$msg = esc_html__( 'Table Missing', 'scoped-notify' );
				echo "<p><span style='color:red;'>$msg</span></p></div></details>";
				continue;
			}

			if ( isset( $descriptions[ $table_name ] ) ) {
				echo "<p class='scoped-notify-description'>" . esc_html( $descriptions[ $table_name ] ) . '</p>';
			}

			// Get table size and other info from information_schema
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$table_info = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT table_rows, data_length, index_length, create_time, update_time FROM information_schema.TABLES WHERE table_schema = %s AND table_name = %s',
					$wpdb->dbname,
					$table_name
				)
			);

			$data_size  = '';
			$index_size = '';
			if ( $table_info ) {
				$data_size  = size_format( $table_info->data_length );
				$index_size = size_format( $table_info->index_length );
			}

			$th_stat = esc_html__( 'Statistic', 'scoped-notify' );
			$th_val  = esc_html__( 'Value', 'scoped-notify' );
			echo "<details><summary>$th_stat</summary>";
			echo "<table class='widefat fixed striped'><thead><tr><th>$th_stat</th><th>$th_val</th></tr></thead><tbody>";

			$lbl_rows    = esc_html__( 'Total Rows', 'scoped-notify' );
			$lbl_data    = esc_html__( 'Data Size', 'scoped-notify' );
			$lbl_index   = esc_html__( 'Index Size', 'scoped-notify' );
			$lbl_created = esc_html__( 'Created At', 'scoped-notify' );
			$lbl_updated = esc_html__( 'Last Updated', 'scoped-notify' );

			echo "<tr><td>$lbl_rows</td><td>" . esc_html( (string) $count ) . "</td></tr>
				<tr><td>$lbl_data</td><td>" . esc_html( $data_size ) . "</td></tr>
				<tr><td>$lbl_index</td><td>" . esc_html( $index_size ) . '</td></tr>';

			if ( $table_info && $table_info->create_time ) {
				echo "<tr><td>$lbl_created</td><td>" . esc_html( (string) $table_info->create_time ) . '</td></tr>';
			}
			if ( $table_info && $table_info->update_time ) {
				echo "<tr><td>$lbl_updated</td><td>" . esc_html( (string) $table_info->update_time ) . '</td></tr>';
			}

			if ( SCOPED_NOTIFY_TABLE_QUEUE === $table_name ) {
				echo "<tr><td colspan='2'><strong>" . esc_html__( 'Queue Specific Stats', 'scoped-notify' ) . '</strong></td></tr>';

				// Get status breakdown for queue
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$statuses = $wpdb->get_results( "SELECT status, COUNT(*) as cnt FROM `$table_name` GROUP BY status" );
				if ( ! empty( $statuses ) ) {
					foreach ( $statuses as $st ) {
						echo '<tr><td>' . esc_html( "Status: $st->status" ) . '</td><td>' . esc_html( (string) $st->cnt ) . '</td></tr>';
					}
				}

				// Get schedule type breakdown for queue
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$schedule_types = $wpdb->get_results( "SELECT schedule_type, COUNT(*) as cnt FROM `$table_name` GROUP BY schedule_type" );
				if ( ! empty( $schedule_types ) ) {
					foreach ( $schedule_types as $st ) {
						echo '<tr><td>' . esc_html( "Schedule Type: $st->schedule_type" ) . '</td><td>' . esc_html( (string) $st->cnt ) . '</td></tr>';
					}
				}

				// Oldest pending item
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$oldest_pending = $wpdb->get_var( "SELECT MIN(created_at) FROM `$table_name` WHERE status = 'pending'" );
				$lbl_oldest     = esc_html__( 'Oldest Pending Item', 'scoped-notify' );
				$val_oldest     = $oldest_pending ? esc_html( (string) $oldest_pending ) : esc_html__( 'N/A', 'scoped-notify' );
				echo "<tr><td>$lbl_oldest</td><td>$val_oldest</td></tr>";
			}

			echo '</tbody></table></details>';

			// Determine Primary Key for ID and Sorting, and check for status column
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$columns_info   = $wpdb->get_results( "SHOW COLUMNS FROM `$table_name`" );
			$pk_column      = '';
			$has_created_at = false;
			$has_status     = false;
			$columns_map    = array();

			foreach ( $columns_info as $col ) {
				// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$columns_map[] = $col->Field;
				if ( 'PRI' === $col->Key ) {
					$pk_column = $col->Field; // Grab first PK
				}
				if ( 'created_at' === $col->Field ) {
					$has_created_at = true;
				}
				if ( 'status' === $col->Field ) {
					$has_status = true;
				}
			}
			// Fallback if composite key or no explicit PK returned easily
			if ( empty( $pk_column ) && ! empty( $columns_info ) ) {
				$pk_column = $columns_info[0]->Field;
			}

			// --- Filtering ---
			$where_clauses = array();
			$where_args    = array();

			$filter_status_key = 'filter_status_' . $table_name;
			$search_key        = 'search_' . $table_name;

			$current_status_filter = isset( $_GET[ $filter_status_key ] ) ? sanitize_text_field( $_GET[ $filter_status_key ] ) : '';
			$current_search        = isset( $_GET[ $search_key ] ) ? sanitize_text_field( $_GET[ $search_key ] ) : '';

			if ( $has_status && ! empty( $current_status_filter ) ) {
				$where_clauses[] = 'status = %s';
				$where_args[]    = $current_status_filter;
			}

			if ( ! empty( $current_search ) ) {
				$search_clauses = array();
				foreach ( $columns_map as $col_field ) {
					// Basic search across all columns
					$search_clauses[] = "`$col_field` LIKE %s";
					$where_args[]     = '%' . $wpdb->esc_like( $current_search ) . '%';
				}
				if ( ! empty( $search_clauses ) ) {
					$where_clauses[] = '(' . implode( ' OR ', $search_clauses ) . ')';
				}
			}

			$where_sql = '';
			if ( ! empty( $where_clauses ) ) {
				$where_sql = 'WHERE ' . implode( ' AND ', $where_clauses );
			}

			// --- Calculate Filtered Count (moved up) ---
			$count_sql = "SELECT COUNT(*) FROM `$table_name` $where_sql";
			if ( ! empty( $where_args ) ) {
				$count_sql = $wpdb->prepare( $count_sql, $where_args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
			$filtered_count = $wpdb->get_var( $count_sql );

			echo '<h3>' . esc_html__( 'Table Data', 'scoped-notify' ) . '</h3>';

			// Filter Form
			echo "<form method='get' style='margin-bottom: 15px; background: #f9f9f9; padding: 10px; border: 1px solid #ddd; display: flex; align-items: center; gap: 10px;'>
				<input type='hidden' name='page' value='scoped-notify'>
				<input type='hidden' name='paged_" . esc_attr( $table_name ) . "' value='1'>";

			if ( $has_status ) {
				echo "<select name='" . esc_attr( $filter_status_key ) . "'>
					<option value=''>" . esc_html__( 'All Statuses', 'scoped-notify' ) . '</option>';
				foreach ( array( 'pending', 'processing', 'sent', 'failed', 'orphaned' ) as $opt ) {
					$selected = selected( $current_status_filter, $opt, false );
					echo "<option value='" . esc_attr( $opt ) . "' $selected>" . esc_html( ucfirst( $opt ) ) . '</option>';
				}
				echo '</select>';
			}

			$ph_search  = esc_attr__( 'Search...', 'scoped-notify' );
			$btn_filter = esc_attr__( 'Filter', 'scoped-notify' );
			echo "<input type='text' name='" . esc_attr( $search_key ) . "' value='" . esc_attr( $current_search ) . "' placeholder='$ph_search'>
				<input type='submit' class='button' value='$btn_filter'>";

			if ( ! empty( $current_status_filter ) || ! empty( $current_search ) ) {
				echo "<span style='margin-left: 10px;'>" . sprintf( esc_html__( 'Found %d rows.', 'scoped-notify' ), $filtered_count ) . '</span>';
				$clear_url = remove_query_arg( array( $filter_status_key, $search_key ) );
				echo " <a href='" . esc_url( $clear_url ) . "' class='button'>" . esc_html__( 'Clear', 'scoped-notify' ) . '</a>';
			}
			echo '</form>';

			// --- Pagination and Data Display ---

			$per_page = 50;
			$paged    = isset( $_GET[ 'paged_' . $table_name ] ) ? absint( $_GET[ 'paged_' . $table_name ] ) : 1;
			if ( $paged < 1 ) {
				$paged = 1;
			}
			$offset      = ( $paged - 1 ) * $per_page;
			$total_pages = ceil( $filtered_count / $per_page ); // Use filtered count for pagination

			$order_by = '';
			if ( $has_created_at ) {
				$order_by = 'ORDER BY created_at DESC';
			} elseif ( $pk_column ) {
				$order_by = "ORDER BY `$pk_column` DESC";
			}

			// Fetch actual data rows
			$data_sql = "SELECT * FROM `$table_name` $where_sql $order_by LIMIT $per_page OFFSET $offset";
			if ( ! empty( $where_args ) ) {
				$data_sql = $wpdb->prepare( $data_sql, $where_args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( $data_sql, ARRAY_A );

			if ( ! empty( $rows ) ) {
				$render_pagination = function () use ( $paged, $total_pages, $table_name ) {
					if ( $total_pages <= 1 ) {
						return;
					}
					echo "<div class='scoped-notify-pagination'>";
					$base_url  = remove_query_arg( 'paged_' . $table_name );
					$lbl_first = esc_html__( 'First', 'scoped-notify' );
					$lbl_prev  = esc_html__( 'Prev', 'scoped-notify' );
					$lbl_next  = esc_html__( 'Next', 'scoped-notify' );
					$lbl_last  = esc_html__( 'Last', 'scoped-notify' );

					if ( $paged > 1 ) {
						echo "<a href='" . esc_url( add_query_arg( 'paged_' . $table_name, 1, $base_url ) ) . "'>&laquo; $lbl_first</a>";
						echo "<a href='" . esc_url( add_query_arg( 'paged_' . $table_name, $paged - 1, $base_url ) ) . "'>&lsaquo; $lbl_prev</a>";
					}

					echo "<span class='current'>" . sprintf( esc_html__( 'Page %1$d of %2$d', 'scoped-notify' ), $paged, $total_pages ) . '</span>';

					if ( $paged < $total_pages ) {
						echo "<a href='" . esc_url( add_query_arg( 'paged_' . $table_name, $paged + 1, $base_url ) ) . "'>$lbl_next &rsaquo;</a>";
						echo "<a href='" . esc_url( add_query_arg( 'paged_' . $table_name, $total_pages, $base_url ) ) . "'>$lbl_last &raquo;</a>";
					}
					echo '</div>';
				};

				$render_pagination();

				echo "<div class='scoped-notify-data-table-container' style='overflow-x:auto;'>
					<table class='widefat fixed striped'>
					<thead><tr>";
				foreach ( array_keys( $rows[0] ) as $header ) {
					echo '<th class="scoped-notify-header" data-full-text="' . esc_attr( $header ) . '">' . esc_html( $header ) . '</th>';
				}
				echo '</tr></thead><tbody>';

				foreach ( $rows as $row ) {
					$row_id = '';
					if ( $pk_column && isset( $row[ $pk_column ] ) ) {
						$row_id = esc_attr( "$table_name-row-" . $row[ $pk_column ] );
					} elseif ( isset( $row['queue_id'] ) ) {
						$row_id = esc_attr( "$table_name-row-" . $row['queue_id'] );
					}

					echo "<tr id='$row_id'>";
					foreach ( $row as $val ) {
						$maybe_attr = empty( $val ) ? '' : "data-full-text='" . esc_attr( (string) $val ) . "'";
						echo "<td class='scoped-notify-cell' $maybe_attr>" . esc_html( (string) $val ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					}
					echo '</tr>';
				}
				echo '</tbody></table></div>';

				$render_pagination();
			} else {
				echo '<p>' . esc_html__( 'No data found.', 'scoped-notify' ) . '</p>';
			}

			echo '</div></details>';
		}
		echo '</div>';
	}
}
