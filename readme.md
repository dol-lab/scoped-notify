# Scoped Notify

A WordPress plugin designed to provide granular control over notifications for new posts and comments within a WordPress network environment with many users.

## Features

*   **Scoped Notifications:** Allows registered users to fine-tune their notification preferences. Decide whether to receive notifications for posts and comments at different levels:
    *   Entire network (platform-wide)
    *   Specific blogs within the network
    *   Specific terms (categories/tags)
    *   Individual posts (for comment notifications)
*   **Specificity Rules:** The most specific notification setting always takes precedence (e.g., a post-level setting overrides a blog-level setting).
*   **Network-Wide:** Operates at the network level, adding custom tables to manage settings centrally without blog-specific tables.
*   **User-Focused:** Designed for logged-in users to manage their own notifications.

Check [usecases.md](usecases.md)

## Tests

```bash
# this needs to run in a container where mysqladmin is installed. this sets up a complete wordpress.
bash ./tests/bin/install-wp-tests.sh scoped_notify_test root devpw 127.0.0.1 latest
bash ./tests/bin/run-query.sh "show tables"
composer install
composer test
# run a single test
composer run test -- --filter Test_Notification_Queue

```

This plugin manages WordPress notifications:
- For posts and comments in (large) WordPress networks
- only for users with accounts
- It allows "scoped" choices: You can decide if you (don't) want to be notified when somebody writes a post or a comment on different "levels".
	- On the whole platform
	- In a blog
	- For a terms
	- On a post (for comments)
- It adds some tables to the network (there are no blog-specific tables)

## Tables

Check [database-tables.php](./config/database-tables.php)
```php
// stores what (post/comment/...) triggers a notification.
define( 'SCOPED_NOTIFY_TABLE_TRIGGERS', 'scoped_notify_triggers' ); 

// stores schedules (weekly, daily, ...) to decide when notifications are sent. WIP!
define( 'SCOPED_NOTIFY_TABLE_USER_BLOG_SCHEDULES', 'scoped_notify_user_blog_schedules' );

// every triggered notification is sent to the queue before sending.
define( 'SCOPED_NOTIFY_TABLE_QUEUE', 'scoped_notify_queue' );

// all _SETTINGS tables store user-settings (turn notifications on/off for a trigger) for the different "levels": network (profile), blog, term, post.
define( 'SCOPED_NOTIFY_TABLE_SETTINGS_USER_PROFILES', 'scoped_notify_settings_user_profiles' );
define( 'SCOPED_NOTIFY_TABLE_SETTINGS_BLOGS', 'scoped_notify_settings_blogs' );
define( 'SCOPED_NOTIFY_TABLE_SETTINGS_TERMS', 'scoped_notify_settings_terms' );
define( 'SCOPED_NOTIFY_TABLE_SETTINGS_POST_COMMENTS', 'scoped_notify_settings_post_comments' );
```


## Rules/Limits
- ✅ if the ``*_settings_*`` tables are empty, all users receive notifications, if they are a member in a blog. being a member means having any capability in a blog (defined in global table ``wp_usermeta`` by default).
- ✅ **Most specific rule wins**. Examples:
	  - You have disabled notifications for a blog, enabled for a term, disabled for a post. the "disabled for post" wins (and you don't get a notification), because "disabled for post" is most specific.
	  - ``_profiles.mute = 1``,``_blogs.mute = 0`` => you receive notifications for ``event_type_id``, because ``_blogs`` is more specific than ``_users``
- ✅ Checking for a comment-notification requires checking all "parents": ``_posts``, ``_blogs``, ``_terms``, ``_profiles``, checking a post-notification only requires ``_blogs``, ``_terms``, ``_profiles``.
- ✅ Data Retention: Information in ``_settings_post_comments`` and ``_settings_terms`` is not deleted, if ``_settings_blogs`` is toggled (for the blog & user)
- 👷 ``_settings_post_comments`` only allows comment-types.
- ❓ There can be contradicting rules for the same post in ``_settings_terms``. Imagine a post with terms ``group-1`` and ``group-2``. If i muted ``group-1`` i will not get a notification. If i also un-muted ``group-2`` I will get a notification. **Unmute wins**.
- ✅ We only handle ``_triggers.channel = 'mail'`` for now.
- ✅ ``eventype_type_key`` consists of multiple parts. 
	- $object_name: like post or comment (we )
	- $post_type ([docs](https://developer.wordpress.org/themes/basics/post-types/)): like 'post', 'page', 'event' (for comments contains the post type of the parent post)
- 👷 every blog, term and post has 2 explicit states: ``mute = 1``, ``mute = 0`` and one implicit state, where noting set for this entry, we call this "inherit". The user always only sees a simple on-off toggle (depending on the parent value, toggling between an explicit and an implicit value).
  Logic needs to be handled in backend.
- 👷 @mentions 
	- overwrite mute settings? -> yes
	- if you are mentioned in a post -> are you auto-notified for comments? -> no
	- mention-delivery is immediate by default

### The Plugin
- 👷 Removes appropriate entries, if a user (👷), blog (👷), post (✅) or term (👷) is deleted.
- Shows the current state with a toggle at the different levels
  - Blog: Toggle email-notifications for upcoming posts / comment in this blog
  - Post: Toggle email-notifications upcoming comments on this post. (check the queries and decide if we load this only if a user asks for it in a submenu)
- ❓Adds an option to skip sending notifications for a post.
- 👷 Clarifies which actions trigger notifications.
- ❓ Security: Use nonces for any forms submitting changes to settings. Sanitize and validate all inputs. Escape all outputs. Check capabilities before allowing users to change settings or view sensitive data.
- ✅ Internationalization: Use WordPress localization functions (__(), _e(), etc.) for all user-facing strings.
- 👷 Handles errors. Warns an admin if notifications are not sent.
- ❓ Removes old queue entries.


### UI
- 👷 Discuss: Blog-settings: Instead of having two toggle-switches for posts and comments add only three options: 
  notifications for everything (post & comments), only posts, nothing (this leaves comments-only, which does not make sense).
- ✅ You can see on every post, if you receive comment-notifications for it.

## Questions
- [ ] which schedule-options are available?
	- daily. select one:(7, 12, 17, 22)
	- weekly. select one/multiple: (Mo-So)
- [ ] group posts from different blogs in the same email if they are scheduled at the same time => sending needs to be user-specific.
- [ ] scheduled, two approaches
	- when a post happens -> push to a stack
	- when it's time to send -> get everybody
- [ ] Does muting post-post via notification_mute_blogs mute it for all channels?
- [x] Get notifications for an update of a post? - No. We could handle this with triggers...
- [ ] Clarify when notifications are sent: "publish", "update", ...
- [ ] option: notify me in posts, where i'm involved
- [ ] what if a user leaves a space (and there is still a posts scheduled)?
- [ ] what if a post is deleted (while it is still scheduled)?


## Future

- [ ] how does force-(un)notify work? - which channel? maybe not?
	- where do we store that?
- [ ] Ask to be notified: "Do you want to receive email-notifications for this post/conversation"?
  - if a user just published a post and does not receive comment-notifications.
  - if a user just became part of a conversation (by commenting)
- ✅ The post/comment-status which triggers the notification is filter-able and "publish" by default.
- We only handle ``_triggers.channel = 'mail'`` for now. Could be 'push' or 'platform' in the future.
  This would allow you to manage complex scenarios like: Disable push-notifications for a blog, enable push only for event-post-type while enabling mail-notifications for the whole blog.

- Inform user while publishing:
	- ✨ If I don't receive notifications for the current blog and publish a post add a message: "Do you want to be notified, if somebody comments on your post?"
	- ✨ If I don't receive notifications for the current post and a comment add a message: "Do you want to be notified, if somebody comments in this conversation?"
- What if a term (that I am subscribed to) is added to a post after publishing? - Send notifications to users who have not received one?
- ✨ Add an UI to the user-profile to disable all (email-/push-) notifications (for posts, events, comments).
- ✨ We currently send mails when user hits publish. This currently works fine for ~3k users. We might need a queuing-system in the future. 
- ✨Unsubscribe from everything (also explicit unmutes) in Profile, with a warning - similar process, when a user is deleted.
- Bulk Actions: Consider how notifications are handled during bulk post publishing or actions that might trigger many events quickly. Queueing notifications might be necessary.
- Admin UI: Provide network admins with a way to view/override user settings, potentially for debugging or support.
- ✨ global user-settings for disabling @mentions - how does the user who wants to reach a @mentioned user about that? warn?
- network-wide settings for users for schedules? - probably not.
-  ✨ if you write a comment on a post and you are currently not being notified, ask: "do you want to be notified for this conversation?"
- ✨if a user
	- ⬆ disables the network-toggle ask (if exits): do you want to unnotify for the following blogs: "foo", "bar".
	- enables the network toggle ask (if exits): you will not receive notifications for the following blog: "foo", "bar". Do you want to receive?

## FAQ
- **Why is the architecture so complicated? Just use an allowlist.**
  There are two issues with this approach. It becomes big quick, if you imagine 10k users * 4k blogs * 10 terms * 100 posts you get many entries. This also becomes complicated when thinking about new terms: do you auto-subscribe users to new terms and create the entries for that?
- **Why is scheduled sending user-specific?**
  We want to group activity in one email for all posts in different blogs that have the same schedule. These emails look different for different users.
- **Why do we need a queue?**
  Mentions become difficult.
  We can use this for error-handling.
  We could live without, if there wasn't scheduled sending...
  Also the following would be necessary, which is too expensive:
	1. **Identify Users:** The cron job queries `sn_user_blog_schedules` to find users whose schedule (`daily`, `weekly`) is due and for whom `last_sent_at` is either NULL or older than the interval (e.g., `last_sent_at < NOW() - INTERVAL 1 DAY` for daily). You might also check `delivery_time`/`delivery_day` if implemented.
	2. **Gather Content:** For each user/blog/channel identified:
	  - Get their `schedule_type` and `last_sent_at` from `sn_user_blog_schedules`.
	  - Determine the start time for content lookup (e.g., `max(last_sent_at, now() - interval)` where interval is '1 day' or '7 days'). Default to the interval if `last_sent_at` is NULL.
	  - Query relevant `wp_posts` and `wp_comments` tables for that `blog_id` created _after_ the calculated start time.
	3. **Filter Content:** Iterate through the gathered posts and comments:
		- For each item, determine the relevant `trigger_id` (e.g., `post-post`, `comment-post`).
		- Call your `should_user_receive_notification()` function for the user, blog, post, trigger, and terms.
		- Keep only the items for which the function returns `true`.
- What about other post-types? Can be handled via the `_triggers` - table.
- How do we deal with comment on other post-types -> comment knows about it's parent post-type, also via `_triggers`


