# Scoped Notify - Implementation Summary & Roadmap

## Executive Summary

**Scoped Notify** is a mature, well-architected WordPress plugin for managing granular notification preferences in multisite networks. It's ready for extending with new channels like ntfy.sh.

### Key Statistics
- **Total Code**: 3,688 lines of PHP (src/)
- **Database Tables**: 7 custom tables
- **Plugin Hooks**: 15+ WordPress actions/filters
- **Classes**: 13 core classes + utilities
- **Architecture**: MVC-like separation of concerns

---

## Architecture Strengths

### 1. **Multi-Channel Design** ✅
The plugin was built with extensibility in mind:
- Triggers support multiple notification channels per event
- Custom channel handlers via WordPress filters
- Easy to add new channels (email, ntfy.sh, push, SMS, etc.)

### 2. **Queue-Based Processing** ✅
- Decouples notification triggering from sending
- Allows scheduling (immediate, daily, weekly)
- Graceful handling of failures
- Batch processing efficiency

### 3. **Hierarchical Preferences** ✅
Most-specific-wins rule allows fine-grained control:
- Network-wide settings
- Blog-level overrides
- Category/term-level control
- Per-post comment settings

### 4. **Scalability Features** ✅
- User chunking for large email batches (configurable)
- Cron-based async processing
- Indexed database queries
- Time-zone aware scheduling

### 5. **WordPress Integration** ✅
- Native REST API endpoints
- WP-CLI commands for management
- Proper capability checking
- Theme integration hooks
- Multisite support

---

## Current Implementation Status

### Fully Implemented ✅
- Mail channel notifications
- User preference management (hierarchical)
- Queue system (immediate, daily, weekly)
- Post & comment triggers
- Blog membership resolution
- REST API for settings management
- Frontend UI components
- Logging & error handling
- Data cleanup on deletion

### In Progress / Partial ⚠️
- Mentions system (@mentions)
- Admin override capabilities
- Global notification toggle UI
- Unsubscribe mechanisms

### Not Yet Implemented ❌
- ntfy.sh channel
- Push notifications
- SMS notifications
- Custom scheduling times (configurable hours)
- Admin dashboard widget

---

## Immediate Implementation: ntfy.sh Channel

### Why ntfy.sh Fits Well
1. **Minimal changes** - Leverages existing channel architecture
2. **No new DB schema required** - Can use existing queue structure
3. **Simple integration** - Just HTTP POST requests
4. **User-friendly** - Works with existing preference UI

### Implementation Checklist

#### Phase 1: Core Integration (2-3 hours)
- [ ] Create `Ntfy_Channel.php` class
- [ ] Register filter hook `scoped_notify_send_notification_channel_ntfy`
- [ ] Test basic ntfy.sh API calls
- [ ] Handle ntfy.sh response codes

#### Phase 2: Admin UI (3-4 hours)
- [ ] Create `Ntfy_Admin_Settings.php` 
- [ ] Add REST endpoint for saving ntfy topics
- [ ] Update `Notification_Ui.php` with ntfy UI component
- [ ] JavaScript handler for topic input

#### Phase 3: Database (1-2 hours)
- [ ] Create `scoped_notify_user_ntfy_config` table
- [ ] Update `database-tables.php` schema
- [ ] Add migration logic

#### Phase 4: User Preferences (2-3 hours)
- [ ] Extend `User_Preferences.php` for channel support
- [ ] Store channel selection per blog/user
- [ ] Fallback logic (ntfy→mail if topic missing)

#### Phase 5: Testing & Refinement (2-3 hours)
- [ ] Unit tests for ntfy channel
- [ ] Integration tests with queue
- [ ] Admin UI testing
- [ ] Error handling edge cases

**Total Estimated Time: 10-15 hours**

---

## Step-by-Step Implementation Guide

### Step 1: Create ntfy.sh Integration Class

**File**: `/src/Ntfy_Channel.php`

This class will handle:
- Building ntfy.sh request payloads
- Sending HTTP POST to ntfy.sh
- Error handling & retries
- Returning success/failure arrays

Reference implementation in `/NTFYSH_IMPLEMENTATION_GUIDE.md`

### Step 2: Update Database Schema

**File**: `/config/database-tables.php`

Add new table for ntfy configuration:
```php
[
    'name' => 'scoped_notify_user_ntfy_config',
    'columns' => [
        'user_id' => '...',
        'blog_id' => '...',
        'ntfy_topic' => 'varchar(255)',
        'created_at' => '...',
        'updated_at' => '...',
    ],
    // ... primary key, indexes, etc
]
```

### Step 3: Extend User Preferences

**File**: `/src/User_Preferences.php`

Add methods to retrieve ntfy topics:
- `get_ntfy_topic(user_id, blog_id): ?string`
- `set_ntfy_topic(user_id, blog_id, topic): bool`
- `delete_ntfy_topic(user_id, blog_id): bool`

### Step 4: Enhance Queue Processing

**File**: `/src/Notification_Queue.php` (lines 177-195)

Store channel preference when queueing:
```php
$user_channel = get_user_preferred_channel($user_id, $blog_id);
$notification_data['channel'] = $user_channel;

if ('ntfy' === $user_channel) {
    $notification_data['ntfy_topic'] = 
        get_ntfy_topic($user_id, $blog_id);
}
```

### Step 5: Add Admin UI

**File**: `/src/Notification_Ui.php`

Add method to render ntfy topic input:
```php
public function add_ntfy_settings_item(array $settings_items) {
    // Add HTML selector for ntfy topic
    // Include enable/disable toggle
    // Show current configuration
}
```

### Step 6: Register REST Endpoints

**File**: `/src/Rest_Api.php`

Add new route:
```php
register_rest_route(
    self::NAMESPACE,
    '/ntfy-config',
    [
        'methods' => WP_REST_Server::CREATABLE,
        'callback' => [__CLASS__, 'save_ntfy_config'],
        'permission_callback' => fn() => is_user_logged_in(),
    ]
);
```

### Step 7: Register ntfy Channel Handler

**File**: `/scoped-notify.php`

In `plugins_loaded` hook:
```php
add_filter('scoped_notify_send_notification_channel_ntfy',
    function($result, $users, $trigger_obj, $item) {
        // Use Ntfy_Channel class to send
    }, 10, 4
);
```

### Step 8: Update Frontend JavaScript

**File**: `/js/scoped-notify.js`

Extend `initializeScopedNotify()` to handle ntfy topic input changes.

---

## Key Files to Understand Before Starting

### Essential Reading Order:

1. **`/CODEBASE_OVERVIEW.md`** - This file (comprehensive architecture)
2. **`/NTFYSH_IMPLEMENTATION_GUIDE.md`** - Detailed ntfy.sh steps
3. **`/scoped-notify.php`** (lines 104-146) - Plugin initialization
4. **`/src/Notification_Queue.php`** (lines 83-246) - Queue management
5. **`/src/Notification_Processor.php`** (lines 181-305) - Channel routing
6. **`/src/Notification_Ui.php`** (lines 34-130) - UI components
7. **`/src/Rest_Api.php`** - REST endpoint pattern

### Code Patterns to Follow:

1. **Logging**: Use `Static_Logger_Trait` for all logging
2. **Database**: Use `$wpdb` prepared statements, constants for table names
3. **Errors**: Return consistent error format in REST responses
4. **Filters**: Follow existing filter signatures
5. **Naming**: Use `scoped_notify_` prefix for all hooks

---

## Database Schema Changes Needed

### New Table: `scoped_notify_user_ntfy_config`
```sql
CREATE TABLE scoped_notify_user_ntfy_config (
    user_id BIGINT(20) NOT NULL,
    blog_id BIGINT(20) NOT NULL,
    ntfy_topic VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, blog_id),
    KEY user_id (user_id),
    KEY blog_id (blog_id)
);
```

### Existing Table: `scoped_notify_user_blog_schedules`
**Consider adding** (optional, for channel preference):
- `notification_channel` VARCHAR(50) - "mail" or "ntfy"

### Existing Table: `scoped_notify_queue`
**Already has**:
- `schedule_type` column ✅
- `scheduled_send_time` column ✅

---

## Testing Strategy

### Unit Tests

Create `/tests/test-ntfy-channel.php`:
```php
class Test_Ntfy_Channel extends WP_UnitTestCase {
    // Test ntfy.sh API calls
    // Test error handling
    // Test topic validation
    // Test response parsing
}
```

### Integration Tests

Create `/tests/test-ntfy-integration.php`:
```php
class Test_Ntfy_Integration extends WP_UnitTestCase {
    // Test queue→ntfy flow
    // Test channel selection logic
    // Test preference fallback
}
```

### Manual Testing

1. Set ntfy topic in user profile
2. Create blog post
3. Verify notification queued with correct channel
4. Verify notification sent to ntfy.sh
5. Check ntfy.sh receives message
6. Test error handling (ntfy.sh down, invalid topic, etc.)

---

## Security Checklist

- [ ] Validate ntfy topics (alphanumeric + hyphens only)
- [ ] Sanitize all REST request inputs
- [ ] Check user capabilities before saving preferences
- [ ] Verify nonce on REST endpoints
- [ ] Escape all HTML output
- [ ] Use HTTPS for ntfy.sh calls
- [ ] Validate HTTP response codes
- [ ] Rate limit ntfy.sh requests to prevent abuse
- [ ] Don't expose ntfy.sh API in error messages
- [ ] Log sensitive errors server-side only

---

## Performance Optimization Tips

### Database
- Index on `(user_id, blog_id)` in ntfy config table ✅
- Reuse queries where possible
- Consider caching ntfy topic lookups

### API Calls
- Batch ntfy.sh notifications if building custom endpoint
- Use WordPress HTTP API with built-in timeouts
- Implement exponential backoff for retries
- Consider rate limiting if many notifications

### Queue Processing
- Process ntfy separately from mail (different batch size OK)
- Don't wait for ntfy.sh response in queue processing
- Log failures for later investigation

---

## Future Enhancements (Post-MVP)

### Phase 2 Features
- [ ] Custom ntfy.sh server support
- [ ] Notification priority levels
- [ ] Multiple ntfy topics per user
- [ ] ntfy.sh action buttons
- [ ] Admin dashboard widget
- [ ] Notification history/archive

### Phase 3 Features
- [ ] Push notifications (Firebase, OneSignal)
- [ ] SMS integration
- [ ] Slack/Teams integration
- [ ] Webhook support
- [ ] Custom template system

---

## Troubleshooting Guide

### Common Issues

**Queue not processing:**
- Check WordPress cron: `wp cron test`
- Verify `scoped_notify_process_queue` hook active
- Check database permissions
- Review logs: `wp scoped-notify log tail`

**Notifications not queuing:**
- Verify user is blog member
- Check notification preferences not muted
- Check trigger exists: `wp db query "SELECT * FROM scoped_notify_triggers"`
- Review logs for recipient resolution

**ntfy.sh not receiving:**
- Verify topic is valid (alphanumeric + hyphens)
- Check ntfy.sh is reachable (not blocked by firewall)
- Verify HTTP status from ntfy.sh (should be 20x)
- Check logs for API errors
- Test manually: `curl -d "test" https://ntfy.sh/my-topic`

---

## Version Roadmap

### v0.3.0 (Current)
- Mail notifications ✅
- Scheduling (immediate/daily/weekly) ✅
- User preferences hierarchy ✅
- REST API ✅

### v0.4.0 (Planned)
- ntfy.sh channel (this implementation)
- Channel selection UI
- Multiple notification method support

### v0.5.0+ (Future)
- Push notifications
- Admin dashboard
- Advanced scheduling
- Notification templates

---

## Quick Reference: File Locations

| Feature | Main File | Lines | Purpose |
|---------|-----------|-------|---------|
| Plugin Init | scoped-notify.php | 104-146 | Class instantiation |
| Queueing | src/Notification_Queue.php | 83-246 | Add notifications to queue |
| Processing | src/Notification_Processor.php | 57-305 | Send notifications |
| Recipients | src/Notification_Resolver.php | 48-200 | Determine who gets notified |
| Scheduling | src/Notification_Scheduler.php | 54-220 | Calculate send times |
| Preferences | src/User_Preferences.php | 1-410 | Manage user settings |
| REST API | src/Rest_Api.php | 43-139 | API endpoints |
| Admin UI | src/Notification_Ui.php | 34-251 | Settings interface |
| Database | config/database-tables.php | 1-169 | Schema definitions |

---

## Next Steps

1. **Read** `/NTFYSH_IMPLEMENTATION_GUIDE.md` for detailed steps
2. **Review** core files listed above
3. **Create** `src/Ntfy_Channel.php` as starting point
4. **Extend** database schema
5. **Add** UI components
6. **Test** thoroughly
7. **Deploy** and monitor

---

## Support & Questions

Key people/resources:
- Plugin repo: `/home/user/scoped-notify/`
- Composer: Check dependencies with `composer show`
- Tests: Run with `composer test`
- Code style: Follows WordPress PHP standards

---

**Document Generated**: 2025-11-17  
**Codebase Version**: 0.3.0  
**Total Assessment Time**: ~3 hours comprehensive review

