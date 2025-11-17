# Implementing Scheduled Notifications with ntfy.sh Support

## Overview of What Exists

The Scoped Notify plugin already has the foundation needed:

1. **Multi-channel architecture** - Can route notifications to different channels
2. **Extensible filter hooks** - Can add custom channel handlers
3. **Queue-based system** - Stores notifications until processing
4. **Scheduling logic** - Supports immediate, daily, and weekly delivery
5. **WordPress admin integration** - Has UI and preference management

## Implementation Points for ntfy.sh

### 1. Add ntfy.sh Channel Handler

**Location:** `/home/user/scoped-notify/src/` (new file or extend existing)

**Required Filter Hook:**
```php
add_filter('scoped_notify_send_notification_channel_ntfy', function($result, $users, $trigger_obj, $item) {
    // $result = [[], [], $users] (initial empty arrays for succeeded/failed)
    // Return: [succeeded_users, failed_users, not_processed_users]
});
```

**Key Code Points to Review:**
- `/home/user/scoped-notify/src/Notification_Processor.php` (lines 181-305)
  - `send_notification_via_custom_channel()` method shows expected return format
  - `process_single_notification()` shows how channel is selected

**Implementation Checklist:**
- [ ] Create ntfy client (HTTP or existing library)
- [ ] Build ntfy topic URL from user/blog/preference
- [ ] Format notification title/body from trigger object
- [ ] Handle HTTP errors and retries
- [ ] Map WordPress users to ntfy topics
- [ ] Return success/failure arrays

### 2. Store ntfy.sh Preferences

**Database Extensions Needed:**

Add ntfy topic/subscription preferences. Options:

**Option A: Extend existing `scoped_notify_user_blog_schedules`**
- Add column: `notification_channel` (default 'mail')
- Add column: `ntfy_topic` (nullable)

**Option B: New table `scoped_notify_user_ntfy_config`**
- user_id (PK)
- blog_id (PK)
- ntfy_topic
- ntfy_enabled (boolean)

**File to Modify:** `/home/user/scoped-notify/config/database-tables.php`

Example schema update:
```php
array(
    'name' => 'scoped_notify_user_ntfy_config',
    'columns' => array(
        'user_id'    => 'bigint(20) NOT NULL',
        'blog_id'    => 'bigint(20) NOT NULL',
        'ntfy_topic' => 'varchar(255) NOT NULL',
        'created_at' => 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_at' => 'datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
    ),
    'create' => 'CREATE TABLE {name} (
        {columns_create},
        PRIMARY KEY (`user_id`, `blog_id`),
        KEY `user_id` (`user_id`),
        KEY `blog_id` (`blog_id`)
    )',
),
```

### 3. Add WordPress Admin UI for ntfy Configuration

**Files to Modify/Create:**

**1. REST Endpoint Extension** (`/home/user/scoped-notify/src/Rest_Api.php`)

Add new REST route for ntfy configuration:
```php
// POST /wp-json/scoped-notify/v1/ntfy-config
register_rest_route(
    self::NAMESPACE,
    '/ntfy-config',
    array(
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => [__CLASS__, 'save_ntfy_config'],
        'permission_callback' => fn() => is_user_logged_in(),
    )
);

public static function save_ntfy_config(WP_REST_Request $request): WP_REST_Response {
    // Save ntfy topic for user
    // Return success/error response
}
```

**2. UI Component** (`/home/user/scoped-notify/src/Notification_Ui.php`)

Add new method:
```php
public function add_ntfy_settings_item(array $settings_items) {
    // Add ntfy topic input field to blog settings
    // Include toggle between mail and ntfy channels
    
    $ntfy_settings = array(
        'id'   => 'scoped-notify-ntfy-config',
        'html' => fn($d) => $this->get_ntfy_selector($d['blog_id']),
    );
    array_splice($settings_items, 2, 0, array($ntfy_settings));
    return $settings_items;
}

private function get_ntfy_selector(int $blog_id): string {
    // Return HTML with:
    // - Toggle to enable/disable ntfy
    // - Input field for ntfy topic
    // - Channel selector (mail vs ntfy)
}
```

**3. JavaScript Handler** (`/home/user/scoped-notify/js/scoped-notify.js`)

Extend existing event listeners:
```javascript
// Add to initializeScopedNotify():
const ntfyInputs = document.querySelectorAll('.js-scoped-notify-ntfy-topic');
ntfyInputs.forEach((input) => {
    input.addEventListener('change', async (event) => {
        const topic = event.target.value;
        const blogId = event.target.dataset.blogId;
        
        // Save ntfy topic
        await fetch(ScopedNotify.rest.ntfyConfigEndpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': ScopedNotify.rest.nonce,
            },
            body: JSON.stringify({
                blog_id: blogId,
                ntfy_topic: topic,
            }),
        });
    });
});
```

### 4. Modify Queue to Store Channel Preference

**File:** `/home/user/scoped-notify/src/Notification_Queue.php` (lines 177-195)

Current code stores notification with default channel from trigger. Modify to:

```php
// In queue_event_notifications() or insert_notification():

// Get user's preferred channel for this blog
$user_channel = get_user_notification_channel($user_id, $blog_id);

// Get ntfy topic if using ntfy channel
$ntfy_topic = null;
if ($user_channel === 'ntfy') {
    $ntfy_topic = get_user_ntfy_topic($user_id, $blog_id);
}

$notification_data = array(
    // ... existing fields ...
    'channel'    => $user_channel,  // 'mail' or 'ntfy'
    'ntfy_topic' => $ntfy_topic,    // Store topic in meta or separate field
);
```

### 5. Update Trigger Table to Support Multiple Channels

**File:** `/home/user/scoped-notify/config/database-tables.php`

Current triggers are configured per channel. Verify structure supports:
- One trigger (e.g., post-post)
- Multiple channels (mail AND ntfy)

Current design: `UNIQUE KEY trigger_channel (trigger_key, channel)`

This is correct - supports multiple channels per trigger.

### 6. Notification Processor Update

**File:** `/home/user/scoped-notify/src/Notification_Processor.php` (lines 200-220)

Current code:
```php
$channel = $this->get_channel_for_trigger($item->trigger_id);

if ('mail' === $channel) {
    list($users_succeeded, $users_failed) = 
        $this->send_notification_via_mail_channel(...);
} elseif (has_filter('scoped_notify_send_notification_channel_' . $channel)) {
    list($users_succeeded, $users_failed) = 
        $this->send_notification_via_custom_channel($channel, ...);
}
```

**Modification needed:** 
- Queue table needs `channel` field (already exists)
- Queue table needs `ntfy_topic` field (optional, store in meta or separate)
- Processor reads channel from queue record instead of trigger

### 7. Create ntfy.sh Integration Module

**New File:** `/home/user/scoped-notify/src/Ntfy_Channel.php`

```php
<?php
namespace Scoped_Notify;

class Ntfy_Channel {
    use Static_Logger_Trait;
    
    private string $base_url;
    
    public function __construct(string $base_url = 'https://ntfy.sh') {
        $this->base_url = $base_url;
    }
    
    /**
     * Send notification via ntfy.sh
     */
    public function send(
        array $users,
        $trigger_obj,
        stdClass $item,
        string $ntfy_topic
    ): array {
        $logger = self::logger();
        
        // Build notification payload
        $subject = $this->format_subject($trigger_obj, $item);
        $message = $this->format_message($trigger_obj, $item);
        
        // Send to ntfy.sh
        $succeeded = [];
        $failed = [];
        
        try {
            $response = wp_remote_post(
                "{$this->base_url}/{$ntfy_topic}",
                array(
                    'method'  => 'POST',
                    'headers' => array(
                        'Content-Type' => 'application/json',
                    ),
                    'body'    => wp_json_encode(array(
                        'title'    => $subject,
                        'message'  => $message,
                        'tags'     => ['notify'],
                    )),
                    'timeout' => 10,
                )
            );
            
            if (is_wp_error($response)) {
                $failed = $users;
                $logger->error('ntfy.sh request failed: ' . 
                    $response->get_error_message());
            } else {
                $http_code = wp_remote_retrieve_response_code($response);
                if ($http_code >= 200 && $http_code < 300) {
                    $succeeded = $users;
                } else {
                    $failed = $users;
                }
            }
        } catch (Exception $e) {
            $failed = $users;
            $logger->error('ntfy.sh exception: ' . $e->getMessage());
        }
        
        return [$succeeded, $failed];
    }
    
    private function format_subject($trigger_obj, $item): string {
        // Similar to Notification_Processor::format_mail_subject
    }
    
    private function format_message($trigger_obj, $item): string {
        // Similar to Notification_Processor::format_mail_message
        // But text-only, not HTML
    }
}
```

### 8. Wire up the ntfy Channel

**File:** `scoped-notify.php` or new integration file

Register the ntfy channel handler:
```php
add_filter('scoped_notify_send_notification_channel_ntfy', 
    function($result, $users, $trigger_obj, $item) {
        if (!isset($item->ntfy_topic) || empty($item->ntfy_topic)) {
            return [[], $users, []]; // No topic, fail
        }
        
        $ntfy = new Ntfy_Channel(
            get_option('scoped_notify_ntfy_base_url', 'https://ntfy.sh')
        );
        
        return $ntfy->send($users, $trigger_obj, $item, $item->ntfy_topic);
    }, 10, 4
);
```

## Admin Settings UI Requirements

### WordPress Admin Pages Needed:

1. **User Profile Settings** (`/wp-admin/user-edit.php`)
   - Toggle between mail/ntfy channels
   - Default ntfy topic if provided

2. **Site Settings** (within blog settings)
   - Selector for primary notification channel (mail/ntfy)
   - Topic configuration

3. **Individual Post Settings**
   - Override channel selection
   - Custom notification routing

### Settings Data Structure:

```
User Preferences (hierarchical):
├─ Network Level
│  ├─ Notification Preference (Posts/Comments/None)
│  ├─ Channel (mail/ntfy/both)
│  └─ ntfy Topic (for network-wide notifications)
├─ Blog Level
│  ├─ Notification Preference
│  ├─ Channel Override
│  └─ ntfy Topic
├─ Category/Term Level
│  ├─ Notification Preference
│  └─ Channel
└─ Post Level
   ├─ Comment Notifications
   ├─ Channel
   └─ ntfy Topic
```

## Version Updates Required

Update version in:
- `/home/user/scoped-notify/scoped-notify.php` (line 22)
- Run database migrations via `SchemaManager`

## Testing Points

1. **ntfy.sh Integration:**
   - Can send to ntfy.sh successfully
   - Handles ntfy.sh errors gracefully
   - Topic validation
   - Timeout handling

2. **Admin UI:**
   - Users can set ntfy topics
   - Settings persist in database
   - Channel selection works
   - Proper REST API calls

3. **Queue Processing:**
   - Notifications routed to correct channel
   - ntfy notifications sent separately from email
   - Mixed channels handled properly

4. **Preferences:**
   - Per-blog channel selection
   - Fallback to mail if ntfy topic not set
   - Channel-specific scheduling

## Security Considerations

1. **Sanitize ntfy Topics**
   - Only alphanumeric, hyphens, underscores
   - Max length validation
   - Prevent injection attacks

2. **Access Control**
   - Only logged-in users can set preferences
   - Users can only modify own settings (unless admin)

3. **API Security**
   - REST endpoints require nonce
   - Sanitize all inputs
   - Validate blog_id/post_id ownership

4. **ntfy.sh URL**
   - Make configurable via site option
   - Validate against whitelist of allowed servers
   - Use HTTPS only

## Performance Considerations

1. **Database Indexing**
   - Ensure `channel` indexed in queue table
   - `ntfy_topic` lookup optimized

2. **API Calls**
   - Batch ntfy.sh notifications if possible
   - Use HTTP/2 for better performance
   - Implement exponential backoff for retries

3. **Queue Processing**
   - Process ntfy notifications separately from email
   - Don't block on external API calls

## Files to Create/Modify Summary

### Create New:
- [ ] `/src/Ntfy_Channel.php` - ntfy.sh integration class
- [ ] `/src/Ntfy_Admin_Settings.php` - Admin UI management
- [ ] `/js/ntfy-settings.js` - Frontend ntfy topic handler

### Modify:
- [ ] `scoped-notify.php` - Register ntfy channel filter
- [ ] `config/database-tables.php` - Add ntfy config table
- [ ] `src/Notification_Queue.php` - Store channel/topic
- [ ] `src/Notification_Ui.php` - Add ntfy settings UI
- [ ] `src/Rest_Api.php` - Add ntfy config endpoint
- [ ] `src/Notification_Processor.php` - Handle custom channels

### Database Migrations:
- [ ] Create `scoped_notify_user_ntfy_config` table
- [ ] Add `channel` column to queue (if not present)
- [ ] Add `ntfy_topic` meta field or column to queue

