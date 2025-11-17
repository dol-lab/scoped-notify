# Scoped Notify - Documentation Index

## Overview

This directory contains comprehensive documentation about the Scoped Notify WordPress plugin's architecture, current implementation, and step-by-step guides for implementing new features like ntfy.sh support.

---

## Documentation Files

### 1. **IMPLEMENTATION_SUMMARY.md** (This is the starting point!)
**Purpose**: Executive overview and quick-start guide  
**Best for**: Getting oriented, understanding the project scope  
**Key sections**:
- Architecture strengths
- Current implementation status
- ntfy.sh implementation checklist
- Quick reference file locations
- Troubleshooting guide

**Read time**: 15-20 minutes

---

### 2. **CODEBASE_OVERVIEW.md** (Most comprehensive)
**Purpose**: Complete technical documentation of all systems  
**Best for**: Developers implementing features or maintaining code  
**Key sections**:
- Directory structure & dependencies
- Notification systems (triggers, queue, processing)
- WordPress plugin structure & hooks
- Database schema (7 tables with relationships)
- Main entry point & initialization flow
- Event handling flows (posts, comments, cron)
- Class instantiation graph
- Extensibility points & filters

**Read time**: 45-60 minutes

---

### 3. **NTFYSH_IMPLEMENTATION_GUIDE.md** (Implementation focused)
**Purpose**: Step-by-step guide for adding ntfy.sh channel support  
**Best for**: Developers building the ntfy.sh integration  
**Key sections**:
- Overview of what exists (foundation)
- 8 implementation points with code examples
- Admin settings UI requirements
- Database changes
- Security considerations
- Performance optimizations
- Testing strategy
- Files to create/modify with checkboxes

**Read time**: 30-40 minutes

---

### 4. **Original Documentation** (Existing files)

#### readme.md
- Plugin description & features
- Rules and limits for notification system
- Plugin UI structure
- FAQ section

#### usecases.md
- Default behavior workflows
- Use cases 1-5 (muting, selective notifications, etc.)
- Proposed UI designs
- Database design considerations

---

## How to Use These Documents

### Scenario: "I'm new to this project"

1. Start with **IMPLEMENTATION_SUMMARY.md**
   - Get the big picture
   - Understand what's already built
   - Learn the architecture strengths

2. Review **quick-reference table** in IMPLEMENTATION_SUMMARY.md
   - See key files and their purposes

3. Skim **CODEBASE_OVERVIEW.md** sections 1-3
   - Understand file organization
   - See notification systems overview
   - Learn WordPress plugin structure

---

### Scenario: "I need to implement ntfy.sh support"

1. Read **IMPLEMENTATION_SUMMARY.md** sections:
   - Architecture Strengths
   - Immediate Implementation: ntfy.sh Channel
   - Step-by-Step Implementation Guide

2. Study **NTFYSH_IMPLEMENTATION_GUIDE.md** in detail
   - Follow the 8 implementation points
   - Reference code examples
   - Review admin UI requirements

3. Use **CODEBASE_OVERVIEW.md** as reference
   - Section 6: Extensibility Points (especially 6.1)
   - Section 4: Database Tables
   - Section 5.3: Class Instantiation Graph

4. Review actual code files mentioned:
   - `/src/Notification_Processor.php` (lines 181-305) - Channel routing pattern
   - `/src/Rest_Api.php` - REST endpoint pattern
   - `/src/Notification_Ui.php` - UI component pattern

---

### Scenario: "I need to understand how notifications work"

1. Read **CODEBASE_OVERVIEW.md** section 2
   - Notification systems overview
   - Queue system explanation
   - Recipient resolution process
   - Scheduling mechanisms

2. Study **CODEBASE_OVERVIEW.md** section 5.2
   - Event handling flows (detailed diagrams)
   - Post publication flow
   - Comment publication flow
   - Cron processing flow

3. Review related code:
   - `/src/Notification_Queue.php` - Queueing logic
   - `/src/Notification_Processor.php` - Processing logic
   - `/src/Notification_Resolver.php` - Recipient resolution

---

### Scenario: "I need to add a custom notification channel"

1. Read **CODEBASE_OVERVIEW.md** section 6.1
   - Custom channel architecture
   - Filter hook signature

2. Review **NTFYSH_IMPLEMENTATION_GUIDE.md** section 1
   - Channel handler implementation pattern
   - Code example

3. Study actual implementation:
   - `/src/Notification_Processor.php` (lines 212-219) - How channels are routed
   - `/src/Notification_Processor.php` (lines 248-280) - Mail channel example

---

## Key Concepts Reference

### Notification Flow
```
Event (post/comment) 
  → Queue (store recipients + timing)
  → Cron job (every 5 minutes)
  → Processor (route to channel handler)
  → Send (mail, ntfy.sh, custom, etc.)
  → Update queue status
```

### Preference Hierarchy (Most Specific Wins)
```
Post Settings 
  > Term Settings 
  > Blog Settings 
  > Network Settings 
  > Default (true)
```

### Database Organization
- **Triggers**: What events trigger notifications
- **Queue**: Individual notifications waiting to be sent
- **Schedules**: User's preferred delivery timing per blog
- **Settings**: User's notification preferences at 4 levels

### WordPress Integration Points
- **REST API**: `/wp-json/scoped-notify/v1/settings`
- **Admin UI**: Theme filters for blog & post settings
- **Cron**: Every 5 minutes via WordPress cron
- **WP-CLI**: Commands for management

---

## File Organization

### Main Plugin File
- `scoped-notify.php` (422 LOC) - Entry point, hooks, initialization

### Configuration
- `config/database-tables.php` - Schema definitions

### Source Code (`src/`)
| File | Lines | Purpose |
|------|-------|---------|
| Notification_Queue.php | 558 | Queueing notifications |
| Notification_Processor.php | 678 | Sending notifications |
| Notification_Resolver.php | 696 | Determining recipients |
| Notification_Scheduler.php | 221 | Calculating send times |
| Notification_Hooks.php | 243 | WordPress hook handlers |
| Notification_Ui.php | 251 | Admin UI components |
| User_Preferences.php | 410 | User preference management |
| Rest_Api.php | 139 | REST endpoint handlers |
| CLI_Command.php | 362 | WP-CLI commands |
| *_Trait.php / _Preference.php | Small utilities | Logging, enums |

### Assets
- `js/scoped-notify.js` - Frontend UI handler
- `css/scoped-notify.css` - Styling
- `languages/` - Translations

### Tests
- `tests/` - PHPUnit test suite

---

## Database Tables (7 total)

### Core Tables
1. **scoped_notify_triggers** - Notification triggers (post-post, comment-post, etc.)
2. **scoped_notify_queue** - Pending notifications
3. **scoped_notify_user_blog_schedules** - User scheduling preferences

### Settings Tables (Hierarchical)
4. **scoped_notify_settings_user_profiles** - Network-level preferences
5. **scoped_notify_settings_blogs** - Blog-level preferences
6. **scoped_notify_settings_terms** - Category/term-level preferences
7. **scoped_notify_settings_post_comments** - Post-level comment preferences

---

## Version Information

- **Current Version**: 0.3.0
- **PHP Requirement**: 8.1+
- **WordPress**: Multisite support
- **Dependencies**: custom-table-manager v1.*, PSR-3 logger

---

## Next Steps

1. **Choose your scenario** from above
2. **Read the relevant documents** in recommended order
3. **Review actual code** using file references
4. **Follow implementation guides** if building features
5. **Use documentation** as reference during development

---

## Document Generation

These documents were created through:
- **Codebase analysis**: 3+ hours comprehensive review
- **Pattern identification**: Architecture, hooks, data flow
- **Code reference**: Direct quotes and line numbers
- **Implementation guidance**: Based on existing patterns

---

## Document Maintenance

To keep these docs up to date:
- Update version numbers when plugin version changes
- Add new sections when major features added
- Update file line numbers when significant refactoring occurs
- Add new use cases as they emerge

---

**Last Updated**: 2025-11-17  
**Status**: Complete for v0.3.0  
**Next Review**: After v0.4.0 (ntfy.sh implementation)

