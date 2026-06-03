---
status: complete
priority: p2
issue_id: "093"
tags: [code-review, performance, dispatcher, PR-24]
dependencies: []
---

# process_dispatch's cache_users() prewarm doesn't help — per-subscriber prefs query still hits DB N times

## Problem Statement

PR #24 added `cache_users($user_ids)` at the top of each dispatcher batch. That prewarms WP user + usermeta caches. But `Orbit_Notifier::get_or_create_preferences()` reads `wp_orbit_notification_preferences` directly — NOT WP usermeta. So each of the 500 subscribers in a batch triggers a separate `SELECT * FROM ..._notification_preferences WHERE user_id = %d`.

Per performance-oracle: at 10k subscribers, that's 10,000 prefs SELECTs the cache_users prewarm doesn't touch. The intended perf benefit of batching is largely defeated for the prefs lookup.

## Proposed Solutions

**Option A — Batch prewarm prefs per batch (recommended):**

Add `Orbit_Notifier::prewarm_preferences( array $user_ids )` that does:

```php
$placeholders = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
$rows = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT * FROM {$table} WHERE user_id IN ({$placeholders})",
        ...$user_ids
    )
);
foreach ( $rows as $row ) {
    self::$preferences_cache[ (int) $row->user_id ] = $row;
}
```

Call it from `process_dispatch()` right after `cache_users( $user_ids )`. Subsequent per-row `get_or_create_preferences()` calls hit the static cache (already implemented at `:484-505`). For users without an existing prefs row, the existing fallthrough to INSERT still works.

**Option B — Move prefs into usermeta.** Schema change; out of scope for v1.

Recommend **Option A**. Drops 10k prefs queries to 1 per batch (~99.8% reduction).

## Acceptance Criteria

- [ ] `prewarm_preferences()` method exists.
- [ ] `process_dispatch` calls it after `cache_users`.
- [ ] Per-subscriber `get_or_create_preferences` hits the static cache, not the DB.
- [ ] Test: fan-out N>1 subscribers, assert DB query count drops accordingly (using `Query Monitor`-style trace or `$wpdb->queries`).

## Work Log

- 2026-06-01: Identified during code review of PR #24 by performance-oracle.

## Resources

- PR #24: https://github.com/makyrie/orbit/pull/24
- `includes/class-orbit-notifier.php:107-156, 629-671`
- `docs/solutions/performance-issues/n-plus-one-batch-query-pattern.md`
