---
status: complete
priority: p3
issue_id: "035"
tags: [code-review, security, privacy, database]
dependencies: []
---

# `wp_orbit_phone_verification` accumulates plaintext phone rows with no GC

## Problem Statement

`Orbit_Phone_Verify::send_code()` writes a row per attempt; `verify_code()` only deletes rows on successful verification. Failed attempts (wrong code, abandoned flow, expired code) leave plaintext phone numbers in the database indefinitely. There's no scheduled cleanup.

Pre-existing — surfaced by PR #5 because the new UI invites more failed attempts.

## Proposed Solution

Add a daily cleanup hook (similar to `HOOK_CLEANUP` in `Orbit_Notifier` for the notification log) that deletes verification rows where `expires_at < NOW() - INTERVAL 7 DAY`:

```php
public static function cleanup_expired() {
    global $wpdb;
    $table = $wpdb->prefix . ORBIT_TABLE_PHONE_VERIFICATION;
    $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( 7 * DAY_IN_SECONDS ) );
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE expires_at < %s", $cutoff ) );
}
```

Wire to ActionScheduler in `Orbit_Notifier::schedule_recurring_jobs()`.

## Acceptance Criteria

- [ ] Daily cron deletes expired verification rows older than 7 days
- [ ] Test: insert old rows, run cron, confirm deletion
