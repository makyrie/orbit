---
title: "Eliminate N+1 queries in WordPress plugins with custom tables"
category: performance-issues
problem_type: performance_issue
severity: high
components: [Orbit_Activity, Orbit_Profile, Orbit_Subscription, Orbit_Response, Orbit_Privacy, Orbit_Shortcodes]
tags: [n-plus-1, wpdb, batch-query, performance, custom-tables, cache-users]
date_discovered: 2026-03-24
date_resolved: 2026-03-24
---

# Eliminate N+1 Queries with Batch Loading

## Problem

A user subscribed to 10 profiles viewing 50 activities triggered 160+ database queries for a single dashboard page load. The pattern: fetch activities per subscription in a loop, then fetch profile and response counts per activity in the render loop.

## The Recipe

### 1. Safe `WHERE IN (...)` Placeholder Construction

WordPress `$wpdb->prepare()` doesn't support array binding. Use this 3-part formula:

```php
$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );  // "%d,%d,%d"
$values       = array_map( 'absint', $ids );                           // sanitize every ID
$results      = $wpdb->get_results(
    $wpdb->prepare( "SELECT * FROM {$table} WHERE id IN ({$placeholders})", ...$values )
);
```

### 2. Keyed Return for O(1) Caller Lookups

Return `array( id => object )` so callers can look up by key instead of scanning:

```php
public static function get_by_ids( $ids ) {
    if ( empty( $ids ) ) { return array(); }
    // ... query with IN clause ...
    $keyed = array();
    foreach ( $results as $row ) {
        $keyed[ (int) $row->id ] = $row;
    }
    return $keyed;
}

// Caller:
$profiles_map = Orbit_Profile::get_by_ids( $profile_ids );
$profile = isset( $profiles_map[ $activity->profile_id ] ) ? $profiles_map[ $activity->profile_id ] : null;
```

### 3. Grouped Counts in One Query

Replace N `COUNT(*)` calls with a single `GROUP BY`:

```php
$rows = $wpdb->get_results( $wpdb->prepare(
    "SELECT activity_id, response, COUNT(*) AS cnt
     FROM {$table} WHERE activity_id IN ({$placeholders})
     GROUP BY activity_id, response",
    ...$values
) );

// Pivot into: [ activity_id => [ 'going' => N, 'maybe' => N, 'total' => N ] ]
```

### 4. `cache_users()` Before User Loops

WordPress core function that batch-loads users + usermeta in 2 queries:

```php
$user_ids = array_map( function ( $s ) { return (int) $s->user_id; }, $subscriptions );
cache_users( $user_ids );  // 2 queries total
// Now every get_userdata() in the loop is a cache hit — 0 queries
```

### 5. Static Request-Level Cache with Invalidation

For data that's queried-or-created (like preferences):

```php
private static $cache = array();

public static function get_or_create( $id ) {
    if ( isset( self::$cache[ $id ] ) ) { return self::$cache[ $id ]; }
    // ... DB query or insert ...
    self::$cache[ $id ] = $result;
    return $result;
}

public static function update( $id, $args ) {
    // ... DB update ...
    unset( self::$cache[ $id ] );  // CRITICAL: invalidate after write
}
```

**The invalidation `unset()` is critical.** Without it, a write followed by a read in the same request returns stale cached data — a bug that was found and fixed in this codebase.

## Results

| Code path | Before | After |
|-----------|--------|-------|
| Dashboard (10 subs, 50 activities) | 160+ queries | ~4 queries |
| REST `GET /activities` | N+1 per profile | 1 query |
| Privacy `resolve_responses` (50 responses) | 150 queries | 2 queries |
| Digest compilation (20 items) | 40+ queries | 3 queries |

## References

- Fix commits: `ca801cb`, `e980879`
- Batch methods: `class-orbit-activity.php:list_by_profile_ids()`, `class-orbit-profile.php:get_by_ids()`, `class-orbit-subscription.php:get_by_ids()`, `class-orbit-response.php:count_by_activity_ids()`
- Plan: `docs/plans/2026-03-24-refactor-eliminate-n-plus-1-query-patterns-plan.md`
