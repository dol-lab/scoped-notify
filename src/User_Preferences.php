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

	/** @var array Per request: get_user_settings() by "user_id:blog_id". */
	private static array $user_settings = array();

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
	 * Whether the user gets mails about comments on a post: the most specific setting wins,
	 * post, then the post's terms (any unmute wins), then blog, then network, else the default.
	 * @param int $user_id
	 * @param int $blog_id
	 * @param int $post_id
	 * @return bool
	 * @throws Exception
	 */
	public static function get_post_toggle_state( int $user_id, int $blog_id, int $post_id ): bool {
		$post = \get_post( $post_id );
		if ( ! $post ) {
			self::logger()->error(
				'Could not find post {post_id}.',
				array( 'post_id' => $post_id )
			);
			throw new \Exception( 'Could not find post' );
		}

		// Comment trigger key includes the PARENT post type
		$trigger_key = 'comment-' . $post->post_type;
		$settings    = self::get_user_settings( $user_id, $blog_id );

		$mute = $settings['post'][ $trigger_key ][ $post->ID ] ?? null;
		if ( null === $mute && ! empty( $settings['term'][ $trigger_key ] ) ) {
			$term_mutes = array_intersect_key( $settings['term'][ $trigger_key ], array_flip( self::get_post_term_ids( $post ) ) );
			$mute       = $term_mutes ? min( $term_mutes ) : null;
		}
		$mute ??= $settings['blog'][ $trigger_key ][0] ?? $settings['network'][ $trigger_key ][0] ?? null;

		// The database stores "mute", the toggle shows "on".
		return null === $mute ? SCOPED_NOTIFY_DEFAULT_NOTIFICATION_STATE : 0 === $mute;
	}

	/**
	 * The user's mail settings in a blog, loaded once per request: a page asks for each post card.
	 * @param int $user_id
	 * @param int $blog_id
	 * @return array<string, array<string, array<int, int>>>  mute by scope, trigger key and object id (post, term, 0 for blog and network)
	 */
	private static function get_user_settings( int $user_id, int $blog_id ): array {
		global $wpdb;

		$key = "{$user_id}:{$blog_id}";
		if ( isset( self::$user_settings[ $key ] ) ) {
			return self::$user_settings[ $key ];
		}

		$sql = "
			SELECT 'post' AS scope, s.post_id AS object_id, t.trigger_key, s.mute
			FROM %i s JOIN %i t ON t.trigger_id = s.trigger_id AND t.channel = %s
			WHERE s.user_id = %d AND s.blog_id = %d
			UNION ALL
			SELECT 'term', s.term_id, t.trigger_key, s.mute
			FROM %i s JOIN %i t ON t.trigger_id = s.trigger_id AND t.channel = %s
			WHERE s.user_id = %d AND s.blog_id = %d
			UNION ALL
			SELECT 'blog', 0, t.trigger_key, s.mute
			FROM %i s JOIN %i t ON t.trigger_id = s.trigger_id AND t.channel = %s
			WHERE s.user_id = %d AND s.blog_id = %d
			UNION ALL
			SELECT 'network', 0, t.trigger_key, s.mute
			FROM %i s JOIN %i t ON t.trigger_id = s.trigger_id AND t.channel = %s
			WHERE s.user_id = %d
		";

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				$sql,
				SCOPED_NOTIFY_TABLE_SETTINGS_POST_COMMENTS,
				SCOPED_NOTIFY_TABLE_TRIGGERS,
				self::CHANNEL,
				$user_id,
				$blog_id,
				SCOPED_NOTIFY_TABLE_SETTINGS_TERMS,
				SCOPED_NOTIFY_TABLE_TRIGGERS,
				self::CHANNEL,
				$user_id,
				$blog_id,
				SCOPED_NOTIFY_TABLE_SETTINGS_BLOGS,
				SCOPED_NOTIFY_TABLE_TRIGGERS,
				self::CHANNEL,
				$user_id,
				$blog_id,
				SCOPED_NOTIFY_TABLE_SETTINGS_USER_PROFILES,
				SCOPED_NOTIFY_TABLE_TRIGGERS,
				self::CHANNEL,
				$user_id,
			)
		);

		if ( '' !== $wpdb->last_error ) {
			self::logger()->error(
				'Could not read the notification settings of user {user_id} in blog {blog_id}: {error}',
				array(
					'user_id' => $user_id,
					'blog_id' => $blog_id,
					'error'   => $wpdb->last_error,
				)
			);
		}

		$settings = array();
		foreach ( $rows ?? array() as $row ) {
			$settings[ $row->scope ][ $row->trigger_key ][ (int) $row->object_id ] = (int) $row->mute;
		}

		self::$user_settings[ $key ] = $settings;
		return $settings;
	}

	/**
	 * @param \WP_Post $post
	 * @return int[]
	 */
	private static function get_post_term_ids( \WP_Post $post ): array {
		// get_the_terms() reads the term cache a post query primed; wp_get_post_terms() always queries.
		$term_ids = array();
		foreach ( \get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$terms = \get_the_terms( $post, $taxonomy );
			if ( is_array( $terms ) ) {
				array_push( $term_ids, ...\wp_list_pluck( $terms, 'term_id' ) );
			}
		}
		return $term_ids;
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

		self::$user_settings = array();

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

		self::$user_settings = array();

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
