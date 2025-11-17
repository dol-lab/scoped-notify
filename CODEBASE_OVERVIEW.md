# Scoped Notify - Comprehensive Codebase Overview

## 1. Architecture & File Organization

### Directory Structure
```
scoped-notify/
├── scoped-notify.php              # Main plugin entry point
├── config/
│   └── database-tables.php        # Database schema definitions
├── src/                           # Core plugin classes (3,688 LOC)
│   ├── Notification_Queue.php     # (558 LOC) Queues notifications
│   ├── Notification_Processor.php # (678 LOC) Processes queue & sends
│   ├── Notification_Resolver.php  # (696 LOC) Determines recipients
│   ├── Notification_Scheduler.php # (221 LOC) Handles delivery timing
│   ├── Notification_Hooks.php     # (243 LOC) WordPress hooks
│   ├── Notification_Ui.php        # (251 LOC) Admin UI components
│   ├── Rest_Api.php               # (139 LOC) REST endpoints
│   ├── User_Preferences.php       # (410 LOC) User preference management
│   ├── CLI_Command.php            # (362 LOC) WP-CLI commands
│   ├── Scope.php                  # Enum: Network, Blog, Post
│   ├── Notification_Preference.php # Enum: Posts_Only, Posts_And_Comments, No_Notifications
│   ├── Static_Logger_Trait.php    # Logging utility
│   └── Logger/
│       └── Error_Log.php
├── js/
│   └── scoped-notify.js           # Frontend notification UI handler
├── css/
│   └── scoped-notify.css
├── tests/                         # PHPUnit tests
└── composer.json                  # PHP dependencies

### Key Dependencies
- dol-lab/custom-table-manager (v1.*) - Database schema management
- psr/log (v3.0) - Logging interface
- phpunit/phpunit (for testing)
- wp-cli/wp-cli (WP-CLI integration)
```

---

## 2. Notification Systems Currently Implemented

### 2.1 Multi-Channel Architecture
Currently supports:
- **Mail Channel** ✅ Fully implemented
- **Custom Channels** - Extensible via WordPress filters

### 2.2 Notification Triggers
Database table: `scoped_notify_triggers`

**Default Triggers:**
- `post-post` - When a post is published
- `comment-post` - When a comment is posted
- Future: `post-page`, `comment-page`, etc. (dynamic per post type)

```php
// Trigger structure:
trigger_id (PK)
trigger_key (e.g., "post-post", "comment-post") 
channel (default: "mail")
```

### 2.3 Notification Queue System
Database table: `scoped_notify_queue`

**Queue Flow:**
1. Event triggered (post published, comment added)
2. Recipients resolved via `Notification_Resolver`
3. Individual queue entries created for each user
4. Cron job processes queue every 5 minutes
5. Notifications sent via configured channel

**Queue Record Structure:**
```php
queue_id (PK)
user_id, blog_id, object_id, object_type (post/comment)
trigger_id, reason (e.g., "new_post", "new_comment")
status ("pending", "processing", "sent", "failed")
schedule_type ("immediate", "daily", "weekly")
scheduled_send_time (NULL for immediate, datetime for scheduled)
meta (JSON metadata)
created_at, sent_at
```

### 2.4 Notification Recipients Resolution

**Process:**
1. Get all blog members (users with capabilities)
2. Apply preference hierarchy (most specific wins):
   - Post-level settings (`_settings_post_comments`)
   - Term-level settings (`_settings_terms`)
   - Blog-level settings (`_settings_blogs`)
   - Network-level settings (`_settings_user_profiles`)
3. Filter by user preferences
4. Return final recipient list

**Preference Hierarchy:**
```
Post Settings > Term Settings > Blog Settings > Network Settings > Default (true)
```

### 2.5 Scheduling Mechanisms

#### Current Implementation:
```php
// Database: scoped_notify_user_blog_schedules
user_id, blog_id, schedule_type, channel

// Schedule Types:
- "immediate" → NULL send time (immediate dispatch)
- "daily"     → Next day at 08:00 (configurable)
- "weekly"    → Next Monday at 09:00 (configurable)

// Calculate send time:
- Daily: If 08:00 has passed today → schedule for tomorrow 08:00
- Weekly: If Monday before 09:00 → schedule for today, else next week
```

#### Time Calculation Logic (Notification_Scheduler.php):
- Uses WordPress timezone settings
- Converts to UTC for storage
- Each user can have different schedule per blog/channel

---

## 3. WordPress Plugin Structure

### 3.1 Plugin Activation Hook
**File:** `scoped-notify.php` (lines 179-264)

**On Activation:**
1. Creates custom database tables via `SchemaManager`
2. Adds default triggers: `post-post`, `comment-post`
3. Schedules WordPress cron: `scoped_notify_process_queue` (every 5 minutes)
4. Sets site options for mail configuration

### 3.2 Plugin Hooks & Filters

**WordPress Hooks Registered:**

**Activation/Deactivation:**
```php
register_activation_hook()    → activate_plugin()
register_deactivation_hook()  → deactivate_plugin()
register_uninstall_hook()     → uninstall_plugin()
```

**Plugin Load Hooks:**
```php
plugins_loaded           → in_plugins_loaded() [priority 20]
  └─ Instantiates core classes
  └─ Registers WP-CLI command
  └─ Adds action hooks for posts/comments/deletions

init                     → init() [priority 20]
  └─ Loads translations
  └─ Adds UI filters to theme

wp_enqueue_scripts       → enqueue_scripts()
  └─ Enqueues JS/CSS

rest_api_init            → Rest_Api::register_routes()
  └─ Registers REST endpoint
```

**Content Hooks:**
```php
wp_after_insert_post     → Notification_Queue::handle_new_post()
wp_insert_comment        → Notification_Queue::handle_new_comment()
delete_post              → Notification_Hooks::hook_trash_delete_post()
wp_trash_post            → Notification_Hooks::hook_trash_delete_post()
wpmu_delete_user         → Notification_Hooks::hook_delete_user()
wp_uninitialize_site     → Notification_Hooks::hook_delete_blog()
delete_term              → Notification_Hooks::hook_delete_term()
```

**Cron Hooks:**
```php
cron_schedules           → add_cron_schedules() [custom "every_five_minutes"]
scoped_notify_process_queue → process_notification_queue_cron()
sn_after_handle_new_post → process_notification_queue_cron()
sn_after_handle_new_comment → process_notification_queue_cron()
```

### 3.3 REST API Integration

**Endpoint:** `POST /wp-json/scoped-notify/v1/settings`

**Handler:** `Rest_Api::set_user_preferences()`

**Parameters:**
```json
{
  "scope": "network|blog|post",
  "blogId": 1,           // required for blog/post scopes
  "postId": 1,           // required for post scope
  "value": "preference_value|use-default"
}
```

**Preference Values:**
- `posts-only`
- `posts-and-comments`
- `no-notifications`
- `use-default`
- For posts: `activate-notifications`, `deactivate-notifications`

### 3.4 Constants Defined

```php
SCOPED_NOTIFY_VERSION = '0.3.0'
SCOPED_NOTIFY_CRON_HOOK = 'scoped_notify_process_queue'
SCOPED_NOTIFY_DEFAULT_NOTIFICATION_STATE = true

// Tables
SCOPED_NOTIFY_TABLE_TRIGGERS
SCOPED_NOTIFY_TABLE_QUEUE
SCOPED_NOTIFY_TABLE_USER_BLOG_SCHEDULES
SCOPED_NOTIFY_TABLE_SETTINGS_USER_PROFILES
SCOPED_NOTIFY_TABLE_SETTINGS_BLOGS
SCOPED_NOTIFY_TABLE_SETTINGS_TERMS
SCOPED_NOTIFY_TABLE_SETTINGS_POST_COMMENTS

// Options
SCOPED_NOTIFY_MAIL_CHUNK_SIZE (default 400)
SCOPED_NOTIFY_EMAIL_TO_ADDRESS (for BCC)
```

---

## 4. Database Tables

### 4.1 Triggers Table
```sql
scoped_notify_triggers
├── trigger_id (PK)
├── trigger_key varchar(50) - e.g., "post-post", "comment-page"
└── channel varchar(50) - e.g., "mail"
```

### 4.2 Queue Table
```sql
scoped_notify_queue
├── queue_id (PK)
├── user_id, blog_id, object_id (FK)
├── object_type (post/comment)
├── trigger_id (FK to triggers)
├── reason (new_post, new_comment, mention)
├── schedule_type (immediate/daily/weekly)
├── scheduled_send_time (NULL or datetime UTC)
├── status (pending/processing/sent/failed)
├── meta (JSON)
├── created_at, sent_at
└── Indexes: user_status_schedule, status_schedule, object_info
```

### 4.3 User Schedule Table
```sql
scoped_notify_user_blog_schedules
├── user_id (PK)
├── blog_id (PK)
├── schedule_type (PK) - immediate/daily/weekly
└── channel (PK)
```

### 4.4 Settings Tables (Preference Hierarchy)
```sql
scoped_notify_settings_user_profiles
├── user_id (PK)
├── trigger_id (FK, PK)
└── mute (0=unmuted, 1=muted)

scoped_notify_settings_blogs
├── blog_id (PK), user_id (PK), trigger_id (FK, PK)
└── mute

scoped_notify_settings_terms
├── blog_id (PK), user_id (PK), term_id (PK), trigger_id (FK, PK)
└── mute

scoped_notify_settings_post_comments
├── blog_id (PK), post_id (PK), user_id (PK), trigger_id (FK, PK)
└── mute (0/1)
```

---

## 5. Main Entry Point & Initialization Flow

### 5.1 Plugin Loading Sequence

```
scoped-notify.php (main file)
    ↓
[Constants defined]
[Composer autoloader]
    ↓
[Activation/Deactivation hooks registered]
[Cron schedule filter added]
[Custom cron hook registered]
[REST routes registered]
[Post meta registered]
    ↓
plugins_loaded (20)
    └─ in_plugins_loaded()
        ├─ Instantiate Notification_Resolver
        ├─ Instantiate Notification_Scheduler
        ├─ Instantiate Notification_Queue
        ├─ Instantiate Notification_Hooks
        ├─ Register action hooks:
        │  ├─ wp_after_insert_post
        │  ├─ wp_insert_comment
        │  └─ Delete hooks
        └─ Register WP-CLI command (if available)
    ↓
init (20)
    └─ init()
        ├─ Create Notification_Ui
        ├─ Load text domain (translations)
        └─ Add theme filters for UI
    ↓
wp_enqueue_scripts
    └─ enqueue_scripts()
        ├─ Register scoped-notify.css
        ├─ Register scoped-notify.js
        └─ Localize script with REST endpoint/nonce
```

### 5.2 Event Handling

#### Post Publication Flow:
```
wp_after_insert_post
    ↓
Notification_Queue::handle_new_post()
    ├─ Check autosave, post status, revision
    ├─ Check post meta: scoped_notify_notify_others
    ├─ Get matching triggers for post type
    ├─ For each trigger:
    │   └─ queue_event_notifications()
    │       ├─ Resolve recipients via Notification_Resolver
    │       ├─ For each recipient:
    │       │   ├─ Get user schedule (default: immediate)
    │       │   ├─ Calculate send time
    │       │   └─ Insert queue record
    │       └─ Return count queued
    ↓
sn_after_handle_new_post (custom action)
    └─ process_notification_queue_cron()
        └─ Immediately process queue items due now
```

#### Comment Publication Flow:
```
wp_insert_comment
    ↓
Notification_Queue::handle_new_comment()
    ├─ Check comment approval status
    ├─ Determine trigger key from post type
    ├─ Get trigger IDs for trigger key
    ├─ For each trigger:
    │   └─ queue_event_notifications()
    ↓
sn_after_handle_new_comment
    └─ process_notification_queue_cron()
```

#### Cron Processing Flow:
```
every 5 minutes
    ↓
scoped_notify_process_queue (WordPress cron)
    └─ process_notification_queue_cron()
        └─ Notification_Processor::process_queue()
            ├─ Get pending notifications due (status=pending, time<=now)
            ├─ Group by: blog_id, object_id, object_type, trigger_id
            ├─ For each group:
            │   ├─ Fetch all user recipients
            │   ├─ Get object (post/comment)
            │   ├─ Get channel from trigger
            │   ├─ Route to channel handler:
            │   │   └─ send_notification_via_mail_channel()
            │   │       ├─ Chunk users (default 400/chunk)
            │   │       ├─ Build email subject/body
            │   │       ├─ Send via wp_mail() with BCC
            │   │       └─ Update queue status
            │   └─ Update queue: sent/failed
            └─ Return count processed
```

### 5.3 Class Instantiation Graph

```
Notification_Resolver (wpdb)
Notification_Scheduler (wpdb)
    ↓ passed to
Notification_Queue (resolver, scheduler, wpdb)
    ↓ passed to
Notification_Hooks (queue_manager)
    ↓
Notification_Processor (wpdb, queue_table_name) [instantiated in cron]
Notification_Ui [instantiated in init]
```

---

## 6. Extensibility Points

### 6.1 Filters for Custom Channels

```php
// To add custom notification channel (e.g., ntfy.sh, push):

// Extend trigger channels:
add_filter( 'cron_schedules', function($schedules) {
    // Add schedule types if needed
});

// Handle sending via custom channel:
add_filter( 'scoped_notify_send_notification_channel_ntfy', 
    function($result, $users, $trigger_obj, $item) {
        // $result = [succeeded_users, failed_users, not_processed_users]
        // Send via custom channel
        return [$succeeded, $failed, $not_processed];
    }, 10, 4
);
```

### 6.2 Other Extension Hooks

```php
// Filter mail subject:
apply_filters('scoped_notify_mail_subject', $subject, $trigger_obj, $item)

// Filter mail body:
apply_filters('scoped_notify_mail_message', $message, $trigger_obj, $item)

// Filter recipients:
apply_filters('scoped_notify_resolve_recipients', $recipient_ids, $object, $channel, $trigger_id)

// Filter custom object types:
apply_filters('scoped_notify_get_notification_object', null, $object_type, $object_id, $blog_id)

// Override mail sending:
apply_filters('scoped_notify_third_party_send_mail_notification', false, $user_chunk, $trigger_obj)

// Customize cron batch size:
apply_filters('scoped_notify_cron_batch_limit', 20)

// Customize post statuses that trigger notifications:
apply_filters('scoped_notify_send_for_post_status', ['publish'])
```

---

## 7. Frontend/UI Components

### 7.1 WordPress Admin UI

**Integration Points:**
- Theme filter `default_space_setting` - Blog notification options
- Theme filter `ds_post_dot_menu_data` - Post comment notification toggle

**UI Components (JavaScript):**
1. Radio groups for blog/network settings
2. Toggle switches for post comment notifications
3. Error/warning callouts for conflicting settings

### 7.2 REST API Response

```json
{
  "success": true,
  "opposing_settings": [
    {
      "name": "Blog Name",
      "type": "blog",
      "link": "/wp-admin/..."
    }
  ]
}
```

---

## 8. Current Limitations & TODOs

### Noted in Code:
- ⚠️ Mail recipient hardcoded to `noreply@thkoeln.de` (line 464 Notification_Processor.php)
- ⚠️ Daily/Weekly times hardcoded: 08:00 daily, Monday 09:00 weekly
- ⚠️ Only `mail` channel fully implemented
- ⚠️ Mentions system marked as WIP (@mentions support)
- ⚠️ Post-level comment notifications not optimized (loads for all posts)
- ⚠️ Doesn't handle post updates retroactively
- ⚠️ No UI for admins to manage user settings
- ⚠️ Limited error recovery for failed notifications

### From README.md:
- 👷 = Work in progress
- ❓ = Decision needed
- ✨ = Planned feature

Key upcoming work:
- Complete mentions system
- Admin override capabilities
- Global user notification toggle
- Unsubscribe mechanisms
- Old queue cleanup

---

## 9. Development Notes

### Dependencies
- PHP 8.1+ (strict types used)
- WordPress (multisite support)
- PSR-3 Logging interface

### Testing
```bash
composer test  # Run PHPUnit tests
```

### Code Quality
- PHPCS standards applied
- Strict type declarations
- Static logging trait for consistency
- Custom table manager for schema versioning

---

