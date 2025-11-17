<?php
/**
 * Database table definitions configuration for Scoped Notify.
 *
 * @package Scoped_Notify
 */
return array(
	array(
		/**
		 * Defines trigger for notifications and the channel used to send them.
		 * E.g. A notification could be triggered by:
		 *  - a post of post-type 'post' (trigger_key = 'post-post') and sent via the 'mail' channel.
		 *  - by a comment on a post (trigger_key = 'comment-post') and sent via the 'push' channel.
		 */
		'name'    => SCOPED_NOTIFY_TABLE_TRIGGERS,
		'columns' => array(
			'trigger_id'  => 'bigint(20) unsigned NOT NULL AUTO_INCREMENT',
			'trigger_key' => 'varchar(50) NOT NULL', // e.g. post-post, post-${post_type}, comment-${post_type}.
			'channel'     => "varchar(50) NOT NULL DEFAULT 'mail'",
		),
		'create'  => 'CREATE TABLE {name} (
            {columns_create},
            PRIMARY KEY (`trigger_id`),
            UNIQUE KEY `trigger_channel` (`trigger_key`, `channel`)
        )',
	),
	array(
		/**
		 * Holds notifications waiting to be processed and sent.
		 * Includes details like recipient, context, trigger, reason, and schedule.
		 * @todo: if a post is published in a blog with 3 subscribers, 3 entries are created in this table.
		 *        We might want to have 2 tables for this for platform-notifications/activity.
		 */
		'name'    => SCOPED_NOTIFY_TABLE_QUEUE,
		'columns' => array(
			'queue_id'            => 'bigint(20) unsigned NOT NULL AUTO_INCREMENT',
			'user_id'             => 'bigint(20) NOT NULL',
			'blog_id'             => 'bigint(20) NOT NULL',
			'object_id'           => 'bigint(20) NOT NULL',
			'object_type'         => 'varchar(20) NOT NULL', // 'post', 'comment'
			'trigger_id'          => 'bigint(20) unsigned NOT NULL',
			'reason'              => 'varchar(50) NOT NULL', // 'subscribed', 'mentioned', 'author' etc.
			'schedule_type'       => 'varchar(20) NOT NULL', // 'immediate', 'daily', 'weekly'
			'scheduled_send_time' => 'datetime NULL DEFAULT NULL',
			'status'              => "varchar(20) NOT NULL DEFAULT 'pending'", // 'pending', 'sending', 'sent', 'failed'
			'meta'                => 'TEXT NULL DEFAULT NULL', // Added for storing JSON metadata
			'created_at'          => 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP',
			'sent_at'             => 'datetime NULL DEFAULT NULL',
		),
		'create'  => 'CREATE TABLE {name} (
            {columns_create},
            PRIMARY KEY (`queue_id`),
            KEY `user_status_schedule` (`user_id`, `status`, `scheduled_send_time`),
            KEY `status_schedule` (`status`, `scheduled_send_time`),
            KEY `object_info` (`object_type`, `object_id`),
            FOREIGN KEY (`trigger_id`) REFERENCES `' . SCOPED_NOTIFY_TABLE_TRIGGERS . '` (`trigger_id`) ON DELETE CASCADE
        )',
	),
	array(
		/**
		 * Stores the user's preferred notification delivery schedule (e.g., immediate, daily, weekly) per blog and channel.
		 * Defaults are set in code if no entry exists.
		 */
		'name'    => SCOPED_NOTIFY_TABLE_USER_BLOG_SCHEDULES,
		'columns' => array(
			'user_id'       => 'bigint(20) NOT NULL',
			'blog_id'       => 'bigint(20) NOT NULL',
			'schedule_type' => 'varchar(20) NOT NULL', // 'immediate', 'daily', 'weekly'. wip.
			'channel'       => "varchar(50) NOT NULL DEFAULT 'mail'", // Explicitly 'mail', 'push', etc.
		),
		'create'  => 'CREATE TABLE {name} (
            {columns_create},
            PRIMARY KEY (`user_id`, `blog_id`, `channel`),
            KEY `user_id` (`user_id`),
            KEY `blog_id` (`blog_id`)
        )',
	),
	/**
	 * ######################################################################
	 * The following tables are used to store user notification preferences.
	 * ######################################################################
	 */
	array(
		/**
		 * Stores user notification preferences that apply network-wide (across all blogs).
		 * Set in the user's profile.
		 */
		'name'    => SCOPED_NOTIFY_TABLE_SETTINGS_USER_PROFILES,
		'columns' => array(
			'user_id'    => 'bigint(20) NOT NULL',
			'trigger_id' => 'bigint(20) unsigned NOT NULL',
			'mute'       => 'tinyint(1) NOT NULL DEFAULT 1', // 1 = muted, 0 = unmuted (explicitly)
		),
		'create'  => 'CREATE TABLE {name} (
            {columns_create},
            PRIMARY KEY (`user_id`, `trigger_id`),
            KEY `user_id` (`user_id`),
            FOREIGN KEY (`trigger_id`) REFERENCES `' . SCOPED_NOTIFY_TABLE_TRIGGERS . '` (`trigger_id`) ON DELETE CASCADE
        )',
		'updates' => array(
			'0.3.0' => array(
				// Rename table to plural form.
				'ALTER TABLE scoped_notify_settings_user_profile RENAME TO ' . SCOPED_NOTIFY_TABLE_SETTINGS_USER_PROFILES,

			),
		),
	),
	array(
		/**
		 * Stores user notification preferences specific to individual blogs.
		 * Overrides network-wide settings for that blog.
		 */
		'name'    => SCOPED_NOTIFY_TABLE_SETTINGS_BLOGS,
		'columns' => array(
			'blog_id'    => 'bigint(20) NOT NULL',
			'user_id'    => 'bigint(20) NOT NULL',
			'trigger_id' => 'bigint(20) unsigned NOT NULL',
			'mute'       => 'tinyint(1) NOT NULL DEFAULT 1', // 1 = muted, 0 = unmuted (explicitly)
		),
		'create'  => 'CREATE TABLE {name} (
            {columns_create},
            PRIMARY KEY (`blog_id`, `user_id`, `trigger_id`),
            KEY `user_id` (`user_id`),
            FOREIGN KEY (`trigger_id`) REFERENCES `' . SCOPED_NOTIFY_TABLE_TRIGGERS . '` (`trigger_id`) ON DELETE CASCADE
        )',
	),
	array(
		/**
		 * Stores user notification preferences specific to taxonomy terms within a blog.
		 * Overrides blog and network settings for that term.
		 */
		'name'    => SCOPED_NOTIFY_TABLE_SETTINGS_TERMS,
		'columns' => array(
			'blog_id'    => 'bigint(20) NOT NULL',
			'user_id'    => 'bigint(20) NOT NULL',
			'term_id'    => 'bigint(20) NOT NULL', // Assumes term_id across network is unique with blog_id context, standard WP term_id
			'trigger_id' => 'bigint(20) unsigned NOT NULL',
			'mute'       => 'tinyint(1) NOT NULL DEFAULT 1', // 1 = muted, 0 = unmuted (explicitly)
		),
		'create'  => 'CREATE TABLE {name} (
            {columns_create},
            PRIMARY KEY (`blog_id`, `user_id`, `term_id`, `trigger_id`),
            KEY `user_id` (`user_id`),
            KEY `term_lookup` (`blog_id`, `term_id`, `trigger_id`),
            FOREIGN KEY (`trigger_id`) REFERENCES `' . SCOPED_NOTIFY_TABLE_TRIGGERS . '` (`trigger_id`) ON DELETE CASCADE
        )',
	),
	array(
		/**
		 * Stores user notification preferences specifically for comments on a particular post.
		 * Overrides term, blog, and network settings for comments on that post.
		 */
		'name'    => SCOPED_NOTIFY_TABLE_SETTINGS_POST_COMMENTS,
		'columns' => array(
			'blog_id'    => 'bigint(20) NOT NULL',
			'user_id'    => 'bigint(20) NOT NULL',
			'post_id'    => 'bigint(20) NOT NULL',
			'trigger_id' => 'bigint(20) unsigned NOT NULL', // Should only be comment triggers
			'mute'       => 'tinyint(1) NOT NULL DEFAULT 1', // 1 = muted, 0 = unmuted (explicitly)
		),
		'create'  => 'CREATE TABLE {name} (
            {columns_create},
            PRIMARY KEY (`blog_id`, `post_id`, `user_id`, `trigger_id`),
            KEY `user_id` (`user_id`),
            KEY `post_lookup` (`blog_id`, `post_id`, `trigger_id`),
            FOREIGN KEY (`trigger_id`) REFERENCES `' . SCOPED_NOTIFY_TABLE_TRIGGERS . '` (`trigger_id`) ON DELETE CASCADE
        )',
	),
	array(
		/**
		 * Stores ntfy.sh configuration for users per blog.
		 * Holds the ntfy.sh topic that users subscribe to for receiving notifications.
		 */
		'name'    => 'scoped_notify_user_ntfy_config',
		'columns' => array(
			'user_id'    => 'bigint(20) NOT NULL',
			'blog_id'    => 'bigint(20) NOT NULL',
			'ntfy_topic' => 'varchar(255) NOT NULL',
			'enabled'    => 'tinyint(1) NOT NULL DEFAULT 1',
			'created_at' => 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP',
			'updated_at' => 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
		),
		'create'  => 'CREATE TABLE {name} (
            {columns_create},
            PRIMARY KEY (`user_id`, `blog_id`),
            KEY `user_id` (`user_id`),
            KEY `blog_id` (`blog_id`)
        )',
	),
);
