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

	const TABLE_NETWORK_SETTINGS = 'sn_scoped_settings_network_users';
	const TABLE_BLOG_SETTINGS    = 'sn_scoped_settings_blogs';
	const TABLE_POST_SETTINGS    = 'sn_scoped_settings_post_comments';
	const TABLE_TRIGGERS         = 'sn_triggers';

	const CHANNEL = 'mail';

	const DEFAULT_PREFERENCE = Notification_Preference::Posts_And_Comments;

	public static function get_network_preference( int $user_id ): Notification_Preference {
		$pref = self::get(
			Scope::Network,
			$user_id,
		);

		return $pref ?? self::DEFAULT_PREFERENCE;
	}

	public static function get_blog_preference( int $user_id, int $blog_id ): Notification_Preference {
		$pref = self::get(
			Scope::Blog,
			$user_id,
			array(
				'blog_id' => $blog_id,
			),
		);

		return $pref ?? self::get_network_preference( $user_id );
	}

	public static function get_post_preference( int $user_id, int $blog_id, int $post_id ): Notification_Preference {
		$pref = self::get(
			Scope::Post,
			$user_id,
			array(
				'blog_id' => $blog_id,
				'post_id' => $post_id,
			),
		);

		return $pref ?? self::get_blog_preference( $user_id, $blog_id );
	}

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
			self::TABLE_TRIGGERS,
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

		$logger->debug( "sql statement: {$sql}", array( 'args' => $args ) );

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) );

		if (count($rows) === 0) {
			$logger->warning("no trigger rows found");
		}

		$logger->debug( "sql rows" . print_r($rows,true) );
		$rows = $rows ?? array();
		$muted = array_merge(
			...array_map(
				fn( $row ) => array( $row->trigger_key => $row->mute ),
				$rows,
			)
		);

		$logger->debug( 'muted', $muted );

		$muted_post_post    = $muted[ Trigger_Key::Post_Post->value ] ?? null;
		$muted_comment_post = $muted[ Trigger_Key::Comment_Post->value ] ?? null;

		if ( null === $muted_post_post || null === $muted_comment_post ) {
			$logger->debug( 'null pref found' );
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

		$logger->debug( 'get', array( 'pref' => $pref ) );

		return $pref;
	}

	public static function set( Scope $scope, array $fields, Notification_Preference $pref ): bool {
		global $wpdb;

		$logger = self::logger();

		$logger->debug( 'fields', $fields );

		$table = self::get_table_name( $scope );
		if ( null === $table ) {
			$logger->error( "could not get table name for scope {$scope->value}" );
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

			$logger->debug( 'insert data', $data );

			// insert or update preference
			$count = $wpdb->replace( $table, $data, '%d' );

			if ( false === $count ) {
				return false;
			}

			$logger->debug( "updated or inserted {$count} rows" );
		}

		return true;
	}

	public static function remove( Scope $scope, array $fields ): bool {
		global $wpdb;

		$logger = self::logger();

		$logger->debug( 'fields', $fields );

		$table = self::get_table_name( $scope );
		if ( null === $table ) {
			$logger->error( "could not get table name for scope {$scope->value}" );
			return false;
		}

		$data = array(
			...$fields,
		);

		$logger->debug( 'remove entries data', $data );

		// insert or update preference
		$count = $wpdb->delete( $table, $data, '%d' );

		$logger->debug( "deleted {$count} rows" );

		return true;
	}

	private static function get_table_name( Scope $scope ): string|null {
		return match ( $scope ) {
			Scope::Network => self::TABLE_NETWORK_SETTINGS,
			Scope::Blog    => self::TABLE_BLOG_SETTINGS,
			Scope::Post    => self::TABLE_POST_SETTINGS,
			default        => null,
		};
	}

	private static function get_trigger_ids(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'select trigger_id, trigger_key from %i where channel = %s',
				self::TABLE_TRIGGERS,
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
