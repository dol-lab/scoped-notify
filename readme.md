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


## Tests

```bash
# this needs to run in a container where there is mysqladmin installed.
bash ./tests/bin/install-wp-tests.sh scoped_notify_test root devpw 127.0.0.1 latest
bash ./tests/bin/run-query.sh "show tables"
composer test

```

An [example](https://github.com/ProgressPlanner/fewer-tags/blob/develop/.github/workflows/phpunit.yml) for a github-integration (so tests run on push).
