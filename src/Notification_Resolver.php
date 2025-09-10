<?php
/**
 * Resolves who should receive notifications based on context.
 *
 * @package Scoped_Notify
 */

declare(strict_types=1);

namespace Scoped_Notify;

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

// Use fully qualified names for WP classes
use WP_Post;
use WP_Comment;

/**
 * Determines notification recipients.
 */
class Notification_Resolver {
	use Static_Logger_Trait;

	/**
	 * WordPress database object.
	 *
	 * @var \wpdb
	 */
	private $wpdb;

	/**
	 * Constructor.
	 *
	 * @param \wpdb $wpdb   WordPress database object.
	 */
	public function __construct( \wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	/**
	 * Get the user IDs who should receive a notification for a specific post publication.
	 *
	 * @param WP_Post $post    The post object.
	 * @param string  $channel The notification channel (default 'mail').
	 * @return array List of user IDs. Returns empty array on error (e.g., invalid post type).
	 */
	public function get_recipients_for_post( WP_Post $post, string $channel = 'mail' ): array {
		$logger = self::logger();

		$blog_id     = \get_current_blog_id(); // Assuming the action runs within the blog context.
		$post_type   = $post->post_type;
		$trigger_key = 'post-' . $post_type;
		$trigger_id  = $this->get_trigger_id( $trigger_key, $channel );

		if ( ! $trigger_id ) {
			$logger->error(
				"Could not resolve trigger ID for post type '{post_type}' on blog {blog_id}.",
				array(
					'post_type' => $post_type,
					'blog_id'   => $blog_id,
				)
			);
			return array(); // Cannot proceed without a trigger ID
		}

		$potential_recipient_ids = $this->get_blog_member_ids( $blog_id );
		if ( empty( $potential_recipient_ids ) ) {
			$logger->info( 'no user associated with blog with id ' . $blog_id );
			return array(); // No users associated with this blog.
		}

		$object_taxonomies    = \get_object_taxonomies( $post_type );
		$term_ids             = \wp_get_post_terms( $post->ID, $object_taxonomies, array( 'fields' => 'ids' ) );
		$term_ids_placeholder = ! empty( $term_ids ) ? implode( ',', array_fill( 0, count( $term_ids ), '%d' ) ) : 'NULL';

		$user_ids_placeholder = implode( ',', array_fill( 0, count( $potential_recipient_ids ), '%d' ) );

		// Prepare arguments for the query
		$query_args = array_merge(
			array( SCOPED_NOTIFY_TABLE_SETTINGS_TERMS, $blog_id, $trigger_id ),             // for term settings
			! empty( $term_ids ) ? $term_ids : array(), // for term settings term_id IN (...)
			array( SCOPED_NOTIFY_TABLE_SETTINGS_BLOGS, $blog_id, $trigger_id ),             // for blog settings
			array( SCOPED_NOTIFY_TABLE_SETTINGS_USER_PROFILES, $trigger_id ),                        // for network settings
			$potential_recipient_ids                    // final where statement user_id IN (...)
		);

		// This query fetches all relevant settings for all potential users in one go.
		// It uses LEFT JOINs so we always get a row per potential user, even if they have no specific settings.
		// COALESCE is used to determine the final mute state based on specificity rules.
		// Table names are hardcoded as per config/database-tables.php
		$sql = "
            SELECT
                u.ID as user_id,
                COALESCE(
                    term.mute,         -- Specificity 2: Term: if any is unmute => unmute, if all are mute => mute
                    blog.mute,         -- Specificity 3: Blog setting
                    network.mute       -- Specificity 4: Network setting
                    -- Specificity 5 (Default) handled in PHP
                ) as final_mute_state

            FROM
                {$this->wpdb->users} u

			-- term settings
            LEFT JOIN ( -- Find if ANY relevant term setting is UNMUTE (0) then unmute, if all are mute (1), then mute
                SELECT user_id, case when MIN(mute) = 0 then 0 when min(mute) = 1 then 1 else null end as mute
                FROM %i
                WHERE blog_id = %d
                AND trigger_id = %d
                " . ( ! empty( $term_ids ) ? "AND term_id IN ({$term_ids_placeholder})" : ' AND 1=0 ' ) . "
                GROUP BY user_id
            ) term ON term.user_id = u.ID

			-- blog settings
            LEFT JOIN %i blog ON blog.user_id = u.ID
                AND blog.blog_id = %d
                AND blog.trigger_id = %d

			-- user settings
            LEFT JOIN %i network ON network.user_id = u.ID
                AND network.trigger_id = %d

            WHERE
                u.ID IN ({$user_ids_placeholder})
        ";

		// Prepare the SQL statement
		$prepared_sql = $this->wpdb->prepare( $sql, $query_args );

		// Check if prepare failed (returned empty string or false)

		if ( empty( $prepared_sql ) ) {
			$logger->error(
				'wpdb::prepare failed to prepare the SQL query for get_recipients_for_post.',
				array(
					'last_error' => $this->wpdb->last_error,
					'sql'        => $sql, // Log the raw SQL for debugging
					'args'       => $query_args, // Log the args passed to prepare
					'post_id'    => $post->ID,
					'blog_id'    => $blog_id,
					'trigger_id' => $trigger_id,
				)
			);
			return array();
		}

		$results = $this->wpdb->get_results( $prepared_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// Optional: Keep the error log for debugging during development if needed
		// error_log( '' . __FILE__ . ' on line ' . __LINE__ . "\n" . print_r( compact( 'prepared_sql', 'sql', 'prepare_args' ), true ) );

		// Check for DB errors before proceeding
		if ( null === $results ) {
			$logger->error(
				'Database error occurred while fetching notification settings for post.',
				array(
					'query'      => $this->wpdb->last_query,
					'error'      => $this->wpdb->last_error,
					'post_id'    => $post->ID,
					'blog_id'    => $blog_id,
					'trigger_id' => $trigger_id,
				)
			);
			return array(); // Return empty on DB error
		}

		$recipient_ids = array();
		foreach ( $results as $result ) {
			$user_id          = (int) $result->user_id;
			$final_mute_state = $result->final_mute_state; // Can be NULL, '0', or '1'

			// skip post author
			if ( $user_id === (int) $post->post_author ) {
				continue;
			}

			$is_muted = false; // Default based on rules (Specificity 5)
			if ( null === $final_mute_state ) {
				// No specific setting found at any level, use default
				$is_muted = ! SCOPED_NOTIFY_DEFAULT_NOTIFICATION_STATE;
			} else {
				// Explicit setting found
				$is_muted = (bool) $final_mute_state; // '0' -> false (unmuted), '1' -> true (muted)
			}

			if ( ! $is_muted ) {
				$recipient_ids[] = $user_id;
			}
		}

		$recipient_ids_filtered = apply_filters( 'scoped_notify_filter_recipients', $recipient_ids, $post );

		// Add mentioned users - they override mute settings.
		$mentioned_user_ids  = $this->get_mentioned_user_ids( $post->post_content );
		$final_recipient_ids = array_unique( array_merge( $recipient_ids_filtered, $mentioned_user_ids ) );

		return $final_recipient_ids;
	}

	/**
	 * Get the user IDs who should receive a notification for a specific comment.
	 *
	 * @param WP_Comment $comment The comment object.
	 * @param string     $channel The notification channel (default 'mail').
	 * @return array List of user IDs. Returns empty array on error.
	 */
	public function get_recipients_for_comment( WP_Comment $comment, string $channel = 'mail' ): array {
		$logger = self::logger();

		$blog_id = \get_current_blog_id(); // Assuming the action runs within the blog context.
		$post    = \get_post( $comment->comment_post_ID );
		if ( ! $post ) {
			$logger->error(
				'Could not find parent post for comment ID {comment_id}.',
				array( 'comment_id' => $comment->comment_ID )
			);
			return array();
		}

		$logger->debug( 'Fetching recipients for blog ' . $blog_id . ' and post ' . $comment->comment_post_ID );

		$post_type = $post->post_type;
		// Comment trigger key includes the PARENT post type
		$trigger_key = 'comment-' . $post_type;
		$trigger_id  = $this->get_trigger_id( $trigger_key, $channel );

		if ( ! $trigger_id ) {
			$logger->error(
				"Could not resolve trigger ID for comment on post type '{post_type}' (Post ID {post_id}) on blog {blog_id}.",
				array(
					'post_type' => $post_type,
					'post_id'   => $post->ID,
					'blog_id'   => $blog_id,
				)
			);
			return array(); // Cannot proceed without a trigger ID
		}

		$potential_recipient_ids = $this->get_blog_member_ids( $blog_id );
		if ( empty( $potential_recipient_ids ) ) {
			$logger->error( 'No user associated with blog with id ' . $blog_id );
			return array(); // No users associated with this blog.
		}

		$term_ids             = \wp_get_post_terms( $post->ID, \get_object_taxonomies( $post_type ), array( 'fields' => 'ids' ) );
		$term_ids_placeholder = ! empty( $term_ids ) ? implode( ',', array_fill( 0, count( $term_ids ), '%d' ) ) : 'NULL';

		$user_ids_placeholder = implode( ',', array_fill( 0, count( $potential_recipient_ids ), '%d' ) );

		// Prepare arguments for the query
		$query_args = array_merge(
			array( SCOPED_NOTIFY_TABLE_SETTINGS_POST_COMMENTS, $blog_id, $post->ID, $trigger_id ),  // for post settings
			array( SCOPED_NOTIFY_TABLE_SETTINGS_TERMS, $blog_id, $trigger_id ), // for term settings
			! empty( $term_ids ) ? $term_ids : array(),                                 // for term settings term_id IN (...)
			array( SCOPED_NOTIFY_TABLE_SETTINGS_BLOGS, $blog_id, $trigger_id ),  // for blog settings
			array( SCOPED_NOTIFY_TABLE_SETTINGS_USER_PROFILES, $trigger_id ),    // for network settings
			$potential_recipient_ids                    // final where statement user_id IN (...)
		);

		// Query logic similar to posts, but includes the post-specific setting as highest priority.
		// Table names are hardcoded as per config/database-tables.php
		$sql = "
            SELECT
                u.ID as user_id,
                COALESCE(
                    post_comment.mute, -- Specificity 1: Post setting for comments
                    term.mute,         -- Specificity 2: Term: if any is unmute => unmute, if all are mute => mute
                    blog.mute,         -- Specificity 3: Blog setting
                    network.mute       -- Specificity 4: Network setting
                    -- Specificity 5 (Default) handled in PHP
                ) as final_mute_state
            FROM
                {$this->wpdb->users} u

			-- post settings
            LEFT JOIN %i post_comment ON post_comment.user_id = u.ID
                AND post_comment.blog_id = %d
                AND post_comment.post_id = %d
                AND post_comment.trigger_id = %d

			-- term settings
            LEFT JOIN ( -- Find if ANY relevant term setting is UNMUTE (0) then unmute, if all are mute (1), then mute
                SELECT user_id, case when MIN(mute) = 0 then 0 when min(mute) = 1 then 1 else null end as mute
                FROM %i
                WHERE blog_id = %d
                AND trigger_id = %d
                " . ( ! empty( $term_ids ) ? "AND term_id IN ({$term_ids_placeholder})" : 'AND 1=0' ) . "
                GROUP BY user_id
            ) term ON term.user_id = u.ID

			-- blog settings
            LEFT JOIN %i blog ON blog.user_id = u.ID
                AND blog.blog_id = %d
                AND blog.trigger_id = %d

			-- user settings
            LEFT JOIN %i network ON network.user_id = u.ID
                AND network.trigger_id = %d

            WHERE
                u.ID IN ({$user_ids_placeholder})
        ";

		$prepared_sql = $this->wpdb->prepare( $sql, $query_args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$results      = $this->wpdb->get_results( $prepared_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// Check for DB errors before proceeding
		if ( $results === null ) {
			$logger->error(
				'Database error occurred while fetching notification settings for comment.',
				array(
					'query'      => $this->wpdb->last_query,
					'error'      => $this->wpdb->last_error,
					'comment_id' => $comment->comment_ID,
					'post_id'    => $post->ID,
					'blog_id'    => $blog_id,
					'trigger_id' => $trigger_id,
				)
			);
			return array(); // Return empty on DB error
		}

		$recipient_ids = array();
		foreach ( $results as $result ) {
			$user_id          = (int) $result->user_id;
			$final_mute_state = $result->final_mute_state; // Can be NULL, '0', or '1'

			// skip comment author
			if ( $user_id === (int) $comment->user_id ) {
				continue;
			}

			$is_muted = false; // Default based on rules (Specificity 5)
			if ( null === $final_mute_state ) {
				// No specific setting found at any level, use default
				$is_muted = ! SCOPED_NOTIFY_DEFAULT_NOTIFICATION_STATE;
			} else {
				// Explicit setting found
				$is_muted = (bool) $final_mute_state; // '0' -> false (unmuted), '1' -> true (muted)
			}

			if ( ! $is_muted ) {
				$recipient_ids[] = $user_id;
			}
		}

		$recipient_ids_filtered = apply_filters( 'scoped_notify_filter_recipients', $recipient_ids, $comment );

		// Add mentioned users - they override mute settings.
		$mentioned_user_ids  = $this->get_mentioned_user_ids( $comment->comment_content );
		$final_recipient_ids = array_unique( array_merge( $recipient_ids_filtered, $mentioned_user_ids ) );

		// TODO: Add logic for conversation participants? (e.g., author of parent post, other commenters)
		// This might require separate checks or adding reasons to the queue.

		return $final_recipient_ids;
	}

	/**
	 * Finds more specific notification settings that override a more general one being set.
	 *
	 * @param Scope    $scope The scope that was just set (e.g., Network, Blog).
	 * @param int      $user_id The ID of the user whose preferences are being checked.
	 * @param int|null $blog_id The ID of the blog, required if the scope is Blog.
	 * @return array A list of opposing, more specific settings, including their type, name, link, and current value.
	 */
	public function get_opposing_more_specific( Scope $scope, int $user_id, ?int $blog_id = null ): array {
		if ( Scope::Post === $scope ) {
			return array();
		}

		$opposing_settings = array();

		// Determine the preference for the current scope to find opposing settings in child scopes.
		$parent_preference = match ( $scope ) {
			Scope::Network => User_Preferences::get_network_preference( $user_id ),
			Scope::Blog    => User_Preferences::get_blog_preference( $user_id, $blog_id ),
			default        => null,
		};

		// If the preference for the current scope is not set, it inherits from the parent.
		// We need to resolve this to find the actual parent preference.
		if ( null === $parent_preference ) {
			if ( Scope::Blog === $scope ) {
				// A blog's parent is the network.
				$parent_preference = User_Preferences::get_network_preference( $user_id );
			} else {
				// Network scope is top-level; if null, it uses the hardcoded default.
				$parent_preference = SCOPED_NOTIFY_DEFAULT_NOTIFICATION_STATE ? Notification_Preference::Posts_And_Comments : Notification_Preference::No_Notifications;
			}
		}

		// A preference of No_Notifications means notifications are off. Anything else means they are on (for at least posts).
		// We use the 'post-post' trigger's mute state as the indicator.
		$parent_post_post_mute_state = ( $parent_preference === Notification_Preference::No_Notifications ) ? 1 : 0;
		$opposing_mute_value         = 1 - $parent_post_post_mute_state;

		// Get the trigger_id for the 'post-post' trigger, which we'll use to find opposing settings.
		$post_post_trigger_id = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT trigger_id FROM %i WHERE trigger_key = %s AND channel = %s', SCOPED_NOTIFY_TABLE_TRIGGERS, Trigger_Key::Post_Post->value, 'mail' ) );

		if ( ! $post_post_trigger_id ) {
			self::logger()->error( 'Could not find trigger_id for post-post.' );
			return array();
		}

		// #####################################################################
		// Step 1: Gather all raw setting entries from the database that are opposing.
		// #####################################################################

		$blog_settings = array();
		if ( Scope::Network === $scope ) {
			$blog_settings = $this->wpdb->get_results(
				$this->wpdb->prepare(
					'SELECT DISTINCT blog_id FROM %i WHERE user_id = %d AND trigger_id = %d AND mute = %d',
					SCOPED_NOTIFY_TABLE_SETTINGS_BLOGS,
					$user_id,
					$post_post_trigger_id,
					$opposing_mute_value
				)
			);
		}

		$term_table = SCOPED_NOTIFY_TABLE_SETTINGS_TERMS;
		$term_sql   = 'SELECT DISTINCT blog_id, term_id FROM %i WHERE user_id = %d AND trigger_id = %d AND mute = %d';
		$term_args  = array( $term_table, $user_id, $post_post_trigger_id, $opposing_mute_value );
		if ( Scope::Blog === $scope ) {
			$term_sql   .= ' AND blog_id = %d';
			$term_args[] = $blog_id;
		}
		$term_settings = $this->wpdb->get_results( $this->wpdb->prepare( $term_sql, $term_args ) );

		$post_table = SCOPED_NOTIFY_TABLE_SETTINGS_POST_COMMENTS;
		$post_sql   = 'SELECT DISTINCT blog_id, post_id FROM %i WHERE user_id = %d AND trigger_id = %d AND mute = %d';
		$post_args  = array( $post_table, $user_id, $post_post_trigger_id, $opposing_mute_value );
		if ( Scope::Blog === $scope ) {
			$post_sql   .= ' AND blog_id = %d';
			$post_args[] = $blog_id;
		}
		$post_settings = $this->wpdb->get_results( $this->wpdb->prepare( $post_sql, $post_args ) );

		// #####################################################################
		// Step 2: Group IDs by blog for efficient bulk fetching.
		// #####################################################################

		$posts_by_blog = array();
		foreach ( $post_settings as $setting ) {
			$posts_by_blog[ (int) $setting->blog_id ][] = (int) $setting->post_id;
		}

		$terms_by_blog = array();
		foreach ( $term_settings as $setting ) {
			$terms_by_blog[ (int) $setting->blog_id ][] = (int) $setting->term_id;
		}

		// #####################################################################
		// Step 3: Bulk fetch details (titles, names, etc.) for each blog.
		// #####################################################################

		$post_details = array();
		foreach ( $posts_by_blog as $b_id => $post_ids ) {
			$post_table_name      = $this->wpdb->get_blog_prefix( $b_id ) . 'posts';
			$post_ids_placeholder = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
			$post_results         = $this->wpdb->get_results(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$this->wpdb->prepare( "SELECT ID, post_title FROM {$post_table_name} WHERE ID IN ($post_ids_placeholder)", $post_ids )
			);
			foreach ( $post_results as $post ) {
				$post_details[ $b_id ][ $post->ID ] = $post->post_title;
			}
		}

		$term_details = array();
		foreach ( $terms_by_blog as $b_id => $term_ids ) {
			$term_table_name      = $this->wpdb->get_blog_prefix( $b_id ) . 'terms';
			$term_tax_table_name  = $this->wpdb->get_blog_prefix( $b_id ) . 'term_taxonomy';
			$term_ids_placeholder = implode( ',', array_fill( 0, count( $term_ids ), '%d' ) );
			$term_results         = $this->wpdb->get_results(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$this->wpdb->prepare( "SELECT t.term_id, t.name, tt.taxonomy FROM {$term_table_name} t JOIN {$term_tax_table_name} tt ON t.term_id = tt.term_id WHERE t.term_id IN ($term_ids_placeholder)", $term_ids )
			);
			foreach ( $term_results as $term ) {
				$term_details[ $b_id ][ $term->term_id ] = array(
					'name'     => $term->name,
					'taxonomy' => $term->taxonomy,
				);
			}
		}

		// #####################################################################
		// Step 4: Assemble the final response using the pre-fetched data.
		// #####################################################################

		foreach ( $blog_settings as $setting ) {
			$b_id = (int) $setting->blog_id;
			$pref = User_Preferences::get_blog_preference( $user_id, $b_id );
			if ( null !== $pref ) {
				$blog_details = get_blog_details( $b_id );
				if ( $blog_details ) {
					$opposing_settings[] = array(
						'type'    => __( 'Blog', 'scoped-notify' ),
						'name'    => $blog_details->blogname,
						'link'    => get_home_url( $b_id ),
						'setting' => $pref->get_label(),
					);
				}
			}
		}

		foreach ( $term_settings as $setting ) {
			$b_id = (int) $setting->blog_id;
			$t_id = (int) $setting->term_id;
			$pref = $this->get_term_preference_helper( $user_id, $b_id, $t_id );
			if ( null !== $pref && isset( $term_details[ $b_id ][ $t_id ] ) ) {
				$detail              = $term_details[ $b_id ][ $t_id ];
				$opposing_settings[] = array(
					'type'    => __( 'Term', 'scoped-notify' ),
					'name'    => $detail['name'],
					'link'    => get_term_link( $t_id, $detail['taxonomy'] ),
					'setting' => $pref->get_label(),
				);
			}
		}

		foreach ( $post_settings as $setting ) {
			$b_id = (int) $setting->blog_id;
			$p_id = (int) $setting->post_id;
			$pref = User_Preferences::get_post_preference( $user_id, $b_id, $p_id );
			if ( null !== $pref && isset( $post_details[ $b_id ][ $p_id ] ) ) {
				$opposing_settings[] = array(
					'type'    => __( 'Post', 'scoped-notify' ),
					'name'    => $post_details[ $b_id ][ $p_id ],
					'link'    => get_blog_permalink( $b_id, $p_id ),
					'setting' => $pref->get_label(),
				);
			}
		}

		return $opposing_settings;
	}

	/**
	 * Extracts mentioned user IDs from content.
	 * Looks for @username patterns.
	 *
	 * @param string $content The content to scan (post_content or comment_content).
	 * @return array List of user IDs mentioned.
	 */
	public function get_mentioned_user_ids( string $content ): array {
		$mentioned_ids = array();
		// Simple regex for @username - adjust if needed for allowed characters
		if ( \preg_match_all( '/@([a-zA-Z0-9_-]+)/', $content, $matches ) ) {
			$logins = array_unique( $matches[1] );
			foreach ( $logins as $login ) {
				$user = \get_user_by( 'login', $login );
				if ( $user ) {
					$mentioned_ids[] = $user->ID;
				} else {
					// Maybe try by nicename as fallback?
					$user = \get_user_by( 'slug', $login );
					if ( $user ) {
						$mentioned_ids[] = $user->ID;
					}
				}
			}
		}
		return array_map( 'intval', array_unique( $mentioned_ids ) );
	}

	/**
	 * Get the trigger ID for a given key and channel.
	 *
	 * @param string $trigger_key The trigger key (e.g., 'post-post').
	 * @param string $channel     The notification channel.
	 * @return int|null Trigger ID or null if not found.
	 */
	public function get_trigger_id( string $trigger_key, string $channel ): ?int {
		$logger = self::logger();

		$sql = $this->wpdb->prepare(
			'SELECT trigger_id FROM ' . SCOPED_NOTIFY_TABLE_TRIGGERS . ' WHERE trigger_key = %s AND channel = %s',
			$trigger_key,
			$channel
		);

		$trigger_id = $this->wpdb->get_var( $sql );

		if ( null === $trigger_id ) {
			$logger->warning(
				"Could not find trigger ID for key '{trigger_key}' and channel '{channel}' in table '{table_name}'.",
				array(
					'trigger_key' => $trigger_key,
					'channel'     => $channel,
					'table_name'  => SCOPED_NOTIFY_TABLE_TRIGGERS,
				)
			);
			return null;
		}

		return (int) $trigger_id;
	}

	/**
	 * Helper to reconstruct a Notification_Preference for a term, as User_Preferences lacks this.
	 *
	 * @param int $user_id
	 * @param int $blog_id
	 * @param int $term_id
	 * @return Notification_Preference|null
	 */
	private function get_term_preference_helper( int $user_id, int $blog_id, int $term_id ): ?Notification_Preference {
		$table = SCOPED_NOTIFY_TABLE_SETTINGS_TERMS;
		$sql   = $this->wpdb->prepare(
			'SELECT t.trigger_key, s.mute
 			FROM %i t
 			LEFT JOIN %i s ON s.trigger_id = t.trigger_id AND s.user_id = %d AND s.blog_id = %d AND s.term_id = %d
 			WHERE t.channel = %s',
			SCOPED_NOTIFY_TABLE_TRIGGERS,
			$table,
			$user_id,
			$blog_id,
			$term_id,
			'mail'
		);

		$rows = $this->wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( empty( $rows ) ) {
			return null;
		}

		$muted = array();
		foreach ( $rows as $row ) {
			if ( null !== $row->trigger_key ) {
				$muted[ $row->trigger_key ] = $row->mute;
			}
		}

		$muted_post_post    = $muted[ Trigger_Key::Post_Post->value ] ?? null;
		$muted_comment_post = $muted[ Trigger_Key::Comment_Post->value ] ?? null;

		// If either setting is missing, we can't determine the preference.
		if ( null === $muted_post_post || null === $muted_comment_post ) {
			return null;
		}

		return match ( true ) {
			'0' === $muted_post_post && '0' === $muted_comment_post => Notification_Preference::Posts_And_Comments,
			'0' === $muted_post_post && '1' === $muted_comment_post => Notification_Preference::Posts_Only,
			'1' === $muted_post_post && '1' === $muted_comment_post => Notification_Preference::No_Notifications,
			default => null,
		};
	}

	/**
	 * Placeholder: Get user IDs associated with a specific blog.
	 * Needs implementation, likely using get_users() with blog_id filter.
	 *
	 * @param int $blog_id The blog ID.
	 * @return integer[] List of user IDs. Returns empty array if no users found or on error.
	 */
	public function get_blog_member_ids( int $blog_id ): array {
		$logger = self::logger();

		if ( ! \function_exists( 'get_users' ) ) {
			$logger->error( 'get_users() function not available.' );
			return array();
		}

		// Check if the blog exists and is not archived, deleted, or spammed.
		$blog_details = \get_blog_details( $blog_id );
		if ( ! $blog_details || $blog_details->archived || $blog_details->deleted || $blog_details->spam ) {
			$logger->warning(
				'Blog ID {blog_id} is invalid or not accessible.',
				array( 'blog_id' => $blog_id )
			);
			return array();
		}

		$user_ids = \get_users(
			array(
				'blog_id' => $blog_id,
				'fields'  => 'ID', // Only retrieve user IDs
			)
		);

		if ( \is_wp_error( $user_ids ) ) {
			/** @var \WP_Error $error_object */ // PHPDoc hint for linters
			$error_object = $user_ids;
			$logger->error(
				'Error retrieving users for blog {blog_id}: {error_message}',
				array(
					'blog_id'       => $blog_id,
					'error_message' => $error_object->get_error_message(),
				)
			);
			return array();
		}

		// Ensure it's always an array of integers
		return array_map( 'intval', $user_ids );
	}
} // End class Notification_Resolver
