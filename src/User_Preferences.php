<?php
/**
 * @package Scoped_Notify
 *
 * TODO
 * @phpcs:disable Squiz.Commenting.ClassComment.Missing,Squiz.Commenting.FunctionComment.Missing
 */

declare(strict_types=1);

namespace Scoped_Notify;

class User_Preferences {
	use Static_Logger_Trait;

	const CHANNEL = 'mail';

	/**
	 * get network preferences == user preferences
	 * @param int $user_id
	 * @return Notification_Preference  preference
	 */
	public static function get_network_preference( int $user_id ): Notification_Preference {
		$pref = self::get(
			Scope::Network,
			$user_id,
		);

		if ( null === $pref ) {
			return SCOPED_NOTIFY_DEFAULT_NOTIFICATION_STATE ? Notification_Preference::Posts_And_Comments : Notification_Preference::No_Notifications;
		} else {
			return $pref;
		}
	}

	/**
	 * get blog preferences
	 * @param int $user_id
	 * @param int $blog_id
	 * @return Notification_Preference|null  preference or null if none is set
	 */
	public static function get_blog_preference( int $user_id, int $blog_id ): Notification_Preference|null {
		$pref = self::get(
			Scope::Blog,
			$user_id,
			array(
				'blog_id' => $blog_id,
			),
		);

		return $pref;
	}

	/**
	 * get post preferences
	 * @param int $user_id
	 * @param int $blog_id
	 * @param int $post_id
	 * @return Notification_Preference|null  preference or null if none is set
	 */
	public static function get_post_preference( int $user_id, int $blog_id, int $post_id ): Notification_Preference|null {

		$pref = self::get(
			Scope::Post,
			$user_id,
			array(
				'blog_id' => $blog_id,
				'post_id' => $post_id,
			),
		);

		return $pref;
	}

	/**
	 * this checks for a post if notifications should be sent or not, and checks the full set of preferences
	 * post, term, blog, user_settings
	 * @param int $user_id
	 * @param int $blog_id
	 * @param int $post_id
	 * @return bool
	 * @throws Exception
	 */
	public static function get_post_toggle_state( int $user_id, int $blog_id, int $post_id ): bool {
		global $wpdb;

		$logger = self::logger();

		// Note: the sql code is quite similar to Notification_Resolver, might be worth to refactor in the future to extract common code

		$post = \get_post( $post_id );
		if ( ! $post ) {
			$logger->error(
				'Could not find post {post_id}.',
				array( 'post_id' => $post_id )
			);
			throw new \Exception( 'Could not find post' );
		}

		$post_type = $post->post_type;
		// Comment trigger key includes the PARENT post type
		$trigger_key = 'comment-' . $post_type;

		$term_ids             = \wp_get_post_terms( $post->ID, \get_object_taxonomies( $post_type ), array( 'fields' => 'ids' ) );
		$term_ids_placeholder = ! empty( $term_ids ) ? implode( ',', array_fill( 0, count( $term_ids ), '%d' ) ) : 'NULL';

		// Prepare arguments for the query
		$query_args = array_merge(
			array( SCOPED_NOTIFY_TABLE_TRIGGERS, $trigger_key ),
			array( SCOPED_NOTIFY_TABLE_SETTINGS_POST_COMMENTS, $blog_id, $post->ID ), // for post settings
			array( SCOPED_NOTIFY_TABLE_SETTINGS_TERMS, $blog_id ),              // for term settings
			! empty( $term_ids ) ? $term_ids : array(), // for term settings term_id IN (...)
			array( SCOPED_NOTIFY_TABLE_SETTINGS_BLOGS, $blog_id ),              // for blog settings
			array( SCOPED_NOTIFY_TABLE_SETTINGS_USER_PROFILES ),                 // network settings
			array( $user_id )
		);

		// Query logic similar to posts, but includes the post-specific setting as highest priority.
		// Table names are hardcoded as per config/database-tables.php
		$sql = "
            SELECT
                COALESCE(
                    post_comment.mute, -- Specificity 1: Post setting for comments
                    term.mute,  	  -- Specificity 2: Term: if any is unmute => unmute, if all are mute => mute
                    blog.mute,         -- Specificity 3: Blog setting
                    network.mute       -- Specificity 4: Network setting
                    -- Specificity 5 (Default) handled in PHP
                ) as final_mute_state
            FROM
                {$wpdb->users} u

			JOIN %i t on t.trigger_key = %s

			-- post settings
            LEFT JOIN %i post_comment ON post_comment.user_id = u.ID
                AND post_comment.blog_id = %d
                AND post_comment.post_id = %d
                AND post_comment.trigger_id = t.trigger_id

			-- term settings
            LEFT JOIN ( -- Find if ANY relevant term setting is UNMUTE (0) then unmute, if all are mute (1), then mute
                SELECT user_id, trigger_id, case when MIN(mute) = 0 then 0 when min(mute) = 1 then 1 else null end as mute
                FROM %i
                WHERE blog_id = %d
                " . ( ! empty( $term_ids ) ? "AND term_id IN ({$term_ids_placeholder})" : 'AND 1=0' ) . '
                GROUP BY user_id, trigger_id
            ) term ON term.user_id = u.ID and term.trigger_id = t.trigger_id

			-- blog settings
            LEFT JOIN %i blog ON blog.user_id = u.ID
                AND blog.blog_id = %d
                AND blog.trigger_id = t.trigger_id

			-- user settings
            LEFT JOIN %i network ON network.user_id = u.ID
                AND network.trigger_id = t.trigger_id

            WHERE
                u.ID = %d
        ';

		$prepared_sql = $wpdb->prepare( $sql, $query_args );
		$results      = $wpdb->get_results( $prepared_sql );

		// Check for DB errors before proceeding
		if ( $results === null ) {
			$logger->error(
				'Database error occurred while fetching notification settings for comment.',
				array(
					'query'      => $wpdb->last_query,
					'error'      => $wpdb->last_error,
					'comment_id' => $comment->comment_ID,
					'post_id'    => $post->ID,
					'blog_id'    => $blog_id,
				)
			);
			throw new \Exception( 'Database error occurred while fetching notification settings for comment.' );
		}

		if ( count( $results ) === 0 ) {
			// no entry found => global default
			return SCOPED_NOTIFY_DEFAULT_NOTIFICATION_STATE;
		}

		// we invert the database wording "mute=1" to toggle state off=false here
		if ( '1' === $results[0]->final_mute_state ) {
			return false;
		} elseif ( '0' === $results[0]->final_mute_state ) {
			return true;
		} else {
			return SCOPED_NOTIFY_DEFAULT_NOTIFICATION_STATE;
		}
	}

	/**
	 * get get preferences for a given scope
	 * @param Scope $scope
	 * @param int $user_id
	 * @param int $constraints  constraints effectively are database field / expected value pairs which entries are to be returned
	 * @return Notification_Preference|null  preference or null if none is set
	 */
	private static function get( Scope $scope, int $user_id, array $constraints = null ): Notification_Preference|null {
		global $wpdb;

		$logger = self::logger();

		$table = self::get_table_name( $scope );
		if ( null === $table ) {
			$logger->error( "could not get table name for scope {$scope->value}" );
			return null;
		}

		// get preference
		$where = '';
		$args  = array(
			SCOPED_NOTIFY_TABLE_TRIGGERS,
			$table,
			$user_id,
			self::CHANNEL,
		);

		if ( ! empty( $constraints ) ) {
			$where = 'and ' . implode( ' and ', array_map( fn(): string => '%i = %d', $constraints ) );

			array_push(
				$args,
				...array_merge(
					...array_map( null, array_keys( $constraints ), $constraints )
				),
			);
		}

		$sql = <<<EOT
			select t.trigger_key, s.mute
			from %i t
			left join %i s
				on
					s.trigger_id = t.trigger_id
					and s.user_id = %d
			where
				channel = %s
				$where
			EOT;

		// $logger->debug( "sql statement: {$sql}", array( 'args' => $args ) );

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) );

		if ( count( $rows ) === 0 ) {
			// no settings found - this can happen if no special notifications are currently set on the given scope
			return null;
		}

		$rows  = $rows ?? array();
		$muted = array_merge(
			...array_map(
				fn( $row ) => array( $row->trigger_key => $row->mute ),
				$rows,
			)
		);

		$muted_post_post    = $muted[ Trigger_Key::Post_Post->value ] ?? null;
		$muted_comment_post = $muted[ Trigger_Key::Comment_Post->value ] ?? null;

		if ( null === $muted_post_post || null === $muted_comment_post ) {
			return null;
		}

		// map database state to preference
		$pref = match ( true ) {
			! $muted_post_post && ! $muted_comment_post => Notification_Preference::Posts_And_Comments,
			! $muted_post_post && $muted_comment_post   => Notification_Preference::Posts_Only,
			$muted_post_post && $muted_comment_post     => Notification_Preference::No_Notifications,

			// invalid database state
			default => null
		};

		return $pref;
	}

	/**
	 * set preferences for a given scope
	 * @param Scope $scope
	 * @param array $fields  which entry should be updated
	 * @param Notification_Preference $pref  preference to be set
	 * @return bool   true if successful
	 */
	public static function set( Scope $scope, array $fields, Notification_Preference $pref ): bool {
		global $wpdb;

		$logger = self::logger();

		$table = self::get_table_name( $scope );
		if ( null === $table ) {
			$logger->error( "Could not get table name for scope {$scope->value}" );
			return false;
		}

		if ( Notification_Preference::Posts_And_Comments === $pref ) {
			$prefs = array(
				Trigger_Key::Post_Post->value    => 0,
				Trigger_Key::Comment_Post->value => 0,
			);
		} elseif ( Notification_Preference::Posts_Only === $pref ) {
			$prefs = array(
				Trigger_Key::Post_Post->value    => 0,
				Trigger_Key::Comment_Post->value => 1,
			);
		} elseif ( Notification_Preference::No_Notifications === $pref ) {
			$prefs = array(
				Trigger_Key::Post_Post->value    => 1,
				Trigger_Key::Comment_Post->value => 1,
			);
		} else {
			return false;
		}

		$trigger_ids = self::get_trigger_ids();

		foreach ( $prefs as $key => $value ) {
			$data = array(
				...$fields,
				'trigger_id' => $trigger_ids[ $key ],
				'mute'       => $value,
			);

			$logger->debug( 'Insert or update preference data', $data );

			// insert or update preference
			$count = $wpdb->replace( $table, $data, '%d' );

			if ( false === $count ) {
				return false;
			}

			$logger->debug( "Updated or inserted {$count} rows" );
		}

		return true;
	}

	/**
	 * remove preferences for a given scope
	 * @param Scope $scope
	 * @param array $fields  which entry should be updated
	 * @return bool   true if successful
	 */
	public static function remove( Scope $scope, array $fields ): bool {
		global $wpdb;

		$logger = self::logger();

		$table = self::get_table_name( $scope );
		if ( null === $table ) {
			$logger->error( "Could not get table name for scope {$scope->value}" );
			return false;
		}

		$data = array(
			...$fields,
		);

		$logger->debug( 'Try to remove preference data', $data );

		// delete preference
		$count = $wpdb->delete( $table, $data, '%d' );

		$logger->debug( "Deleted {$count} rows" );

		return true;
	}

	/**
	 * return tablename for given scope
	 * @param Scope $scope
	 * @return string|null   tablename of empty of unknown scope was given
	 */
	private static function get_table_name( Scope $scope ): string|null {
		return match ( $scope ) {
			Scope::Network => SCOPED_NOTIFY_TABLE_SETTINGS_USER_PROFILES,
			Scope::Blog    => SCOPED_NOTIFY_TABLE_SETTINGS_BLOGS,
			Scope::Post    => SCOPED_NOTIFY_TABLE_SETTINGS_POST_COMMENTS,
			default        => null,
		};
	}

	/**
	 * return applicable trigger-ids
	 * @return array
	 */
	private static function get_trigger_ids(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'select trigger_id, trigger_key from %i where channel = %s',
				SCOPED_NOTIFY_TABLE_TRIGGERS,
				self::CHANNEL,
			)
		);

		return array_merge(
			...array_map(
				fn( $row ) => array( $row->trigger_key => $row->trigger_id ),
				$rows,
			)
		);
	}
}
