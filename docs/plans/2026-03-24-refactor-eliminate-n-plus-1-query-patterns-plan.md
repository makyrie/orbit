---
title: "refactor: Eliminate N+1 query patterns across dashboard, REST API, privacy, and digest"
type: refactor
status: completed
date: 2026-03-24
---

# Eliminate N+1 Query Patterns

## Overview

Multiple code paths fetch data inside loops — one query per subscription, one per activity, one per response — producing 600+ queries for a typical dashboard page load. This refactor adds batch-loading methods to the data layer and rewires all callers to use them, targeting < 20 queries per page regardless of subscription count.

## Problem Statement / Motivation

A user subscribed to 10 profiles viewing 50 activities triggers:
- 10 activity-list queries (one per profile)
- 50 profile lookups (one per activity card)
- 100 response counts (2 per activity card — going + maybe)
- **= 160 queries** for the dashboard alone

At 100 subscriptions this exceeds 1,500 queries. The REST API `GET /activities` and digest compilation have the same pattern. The privacy `resolve_responses()` method adds 3 queries per response in "names" mode.

## Proposed Solution

Add 4 new batch-loading methods to the data layer, add `cache_users()` calls before user-rendering loops, and add static request-level caching to `get_or_create_preferences()`. Then rewire all 7 caller sites to use batch patterns.

## Technical Approach

### Phase 1: Data Layer — Batch Methods

Add batch methods to the existing CRUD classes. No schema changes needed — existing indexes already support `WHERE ... IN (...)` queries.

**Files:**

- `includes/class-orbit-activity.php`
- `includes/class-orbit-profile.php`
- `includes/class-orbit-subscription.php`
- `includes/class-orbit-response.php`
- `includes/class-orbit-notifier.php`

**Tasks:**

- [x] `Orbit_Activity::list_by_profile_ids()` — new method

```php
// includes/class-orbit-activity.php
public static function list_by_profile_ids( $profile_ids, $args = array() ) {
    global $wpdb;

    if ( empty( $profile_ids ) ) {
        return array();
    }

    $args = wp_parse_args( $args, array(
        'status'   => 'active',
        'tier'     => null,
        'per_page' => 100,
        'page'     => 1,
        'orderby'  => 'created_at',
        'order'    => 'DESC',
    ) );

    $table  = $wpdb->prefix . ORBIT_TABLE_ACTIVITIES;
    $where  = array();
    $values = array();

    // profile_id IN (...).
    $placeholders = implode( ',', array_fill( 0, count( $profile_ids ), '%d' ) );
    $where[]      = "profile_id IN ({$placeholders})";
    $values       = array_merge( $values, array_map( 'absint', $profile_ids ) );

    if ( $args['status'] && in_array( $args['status'], self::VALID_STATUSES, true ) ) {
        $where[]  = 'status = %s';
        $values[] = $args['status'];
    }

    if ( $args['tier'] && in_array( absint( $args['tier'] ), self::VALID_TIERS, true ) ) {
        $where[]  = 'tier = %d';
        $values[] = absint( $args['tier'] );
    }

    $allowed_orderby = array( 'created_at', 'date_time', 'tier', 'id' );
    $orderby = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
    $order   = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';
    $offset  = max( 0, ( absint( $args['page'] ) - 1 ) * absint( $args['per_page'] ) );

    $where_clause = implode( ' AND ', $where );

    $sql      = "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
    $values[] = absint( $args['per_page'] );
    $values[] = $offset;

    return $wpdb->get_results( $wpdb->prepare( $sql, ...$values ) );
}
```

Uses existing `profile_id` KEY index on `orbit_activities`.

- [x] `Orbit_Profile::get_by_ids()` — new method

```php
// includes/class-orbit-profile.php
public static function get_by_ids( $ids ) {
    global $wpdb;

    if ( empty( $ids ) ) {
        return array();
    }

    $table        = $wpdb->prefix . ORBIT_TABLE_PROFILES;
    $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
    $values       = array_map( 'absint', $ids );

    $results = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$table} WHERE id IN ({$placeholders})",
            ...$values
        )
    );

    // Key by ID for O(1) lookup.
    $keyed = array();
    foreach ( $results as $row ) {
        $keyed[ (int) $row->id ] = $row;
    }

    return $keyed;
}
```

Uses PRIMARY KEY.

- [x] `Orbit_Subscription::get_by_ids()` — new method

```php
// includes/class-orbit-subscription.php
public static function get_by_ids( $ids ) {
    global $wpdb;

    if ( empty( $ids ) ) {
        return array();
    }

    $table        = $wpdb->prefix . ORBIT_TABLE_SUBSCRIPTIONS;
    $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
    $values       = array_map( 'absint', $ids );

    $results = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$table} WHERE id IN ({$placeholders})",
            ...$values
        )
    );

    $keyed = array();
    foreach ( $results as $row ) {
        $keyed[ (int) $row->id ] = $row;
    }

    return $keyed;
}
```

Uses PRIMARY KEY.

- [x] `Orbit_Response::count_by_activity_ids()` — new method

```php
// includes/class-orbit-response.php
public static function count_by_activity_ids( $activity_ids ) {
    global $wpdb;

    if ( empty( $activity_ids ) ) {
        return array();
    }

    $table        = $wpdb->prefix . ORBIT_TABLE_RESPONSES;
    $placeholders = implode( ',', array_fill( 0, count( $activity_ids ), '%d' ) );
    $values       = array_map( 'absint', $activity_ids );

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT activity_id, response, COUNT(*) AS cnt
             FROM {$table}
             WHERE activity_id IN ({$placeholders})
             GROUP BY activity_id, response",
            ...$values
        )
    );

    // Structure: [ activity_id => [ 'going' => N, 'maybe' => N, 'total' => N ] ]
    $counts = array();
    foreach ( $rows as $row ) {
        $aid = (int) $row->activity_id;
        if ( ! isset( $counts[ $aid ] ) ) {
            $counts[ $aid ] = array( 'going' => 0, 'maybe' => 0, 'total' => 0 );
        }
        $counts[ $aid ][ $row->response ] = (int) $row->cnt;
        $counts[ $aid ]['total']          += (int) $row->cnt;
    }

    return $counts;
}
```

Uses leading column of `activity_subscription` UNIQUE index.

- [x] `Orbit_Notifier::get_or_create_preferences()` — add static cache

```php
// includes/class-orbit-notifier.php — add to get_or_create_preferences()
private static $preferences_cache = array();

public static function get_or_create_preferences( $user_id ) {
    if ( isset( self::$preferences_cache[ $user_id ] ) ) {
        return self::$preferences_cache[ $user_id ];
    }

    // ... existing logic ...

    self::$preferences_cache[ $user_id ] = $prefs; // or the newly created row
    return self::$preferences_cache[ $user_id ];
}
```

### Phase 2: Callers — Dashboard & Manage Shortcodes

Rewire the two highest-traffic shortcodes to use batch methods.

**File:** `includes/class-orbit-shortcodes.php`

**Tasks:**

- [x] `dashboard()` — Replace per-profile activity loop with single `list_by_profile_ids()` call

```php
// Collect profile IDs from subscriptions + own profile.
$profile_ids = array_map( function ( $s ) { return (int) $s->profile_id; }, $subscriptions );
if ( $own_profile ) {
    $profile_ids[] = (int) $own_profile->id;
}
$profile_ids = array_unique( $profile_ids );

// Single query for all activities.
$activities = Orbit_Activity::list_by_profile_ids( $profile_ids, array(
    'status'   => 'active',
    'per_page' => 50,
    'order'    => 'DESC',
) );
```

- [x] `dashboard()` — Pre-fetch profiles and response counts before render loop

```php
// Batch-load profiles.
$needed_profile_ids = array_unique( array_map( function ( $a ) {
    return (int) $a->profile_id;
}, $activities ) );
$profiles_map = Orbit_Profile::get_by_ids( $needed_profile_ids );

// Batch-load response counts.
$activity_ids = array_map( function ( $a ) { return (int) $a->id; }, $activities );
$response_counts = Orbit_Response::count_by_activity_ids( $activity_ids );

// In render loop:
$profile     = isset( $profiles_map[ (int) $activity->profile_id ] ) ? $profiles_map[ (int) $activity->profile_id ] : null;
$going_count = isset( $response_counts[ $activity->id ]['going'] ) ? $response_counts[ $activity->id ]['going'] : 0;
$maybe_count = isset( $response_counts[ $activity->id ]['maybe'] ) ? $response_counts[ $activity->id ]['maybe'] : 0;
```

- [x] `manage()` — Batch response counts

```php
$activity_ids    = array_map( function ( $a ) { return (int) $a->id; }, $activities );
$response_counts = Orbit_Response::count_by_activity_ids( $activity_ids );

// In render loop:
$response_count = isset( $response_counts[ $activity->id ]['total'] ) ? $response_counts[ $activity->id ]['total'] : 0;
```

- [x] `subscribers()` — Add `cache_users()` before loop

```php
// Pre-populate WP user cache.
$user_ids = array_map( function ( $s ) { return (int) $s->user_id; }, $subscriptions );
cache_users( $user_ids );
```

**Query reduction (dashboard):** 10 + 50 + 100 → 3 queries (activities + profiles + response counts). Plus 1 for subscriptions = **4 total**.

### Phase 3: Callers — REST API

**File:** `includes/class-orbit-rest-api.php`

**Tasks:**

- [x] `get_activities()` — Replace per-profile loop with `list_by_profile_ids()`

```php
// Replace the foreach loop (lines 731-741) with:
$all_activities = Orbit_Activity::list_by_profile_ids(
    $profile_ids,
    array(
        'status'   => $request->get_param( 'status' ) ? $request->get_param( 'status' ) : 'active',
        'tier'     => $request->get_param( 'tier' ),
        'per_page' => $request->get_param( 'per_page' ) ?: 20,
        'page'     => $request->get_param( 'page' ) ?: 1,
    )
);
```

Remove the PHP-side merge, sort, and pagination — the database handles it all.

**Query reduction:** N subscription queries → 1 query.

### Phase 4: Callers — Privacy Resolution

**File:** `includes/class-orbit-privacy.php`

**Tasks:**

- [x] `resolve_responses()` — Batch-load subscriptions and users before loop

```php
// In "names" mode, pre-fetch all subscriptions referenced by responses.
$sub_ids = array_unique( array_map( function ( $r ) {
    return (int) $r->subscription_id;
}, $responses ) );
$subscriptions_map = Orbit_Subscription::get_by_ids( $sub_ids );

// Pre-populate WP user cache.
$user_ids = array();
foreach ( $subscriptions_map as $sub ) {
    $user_ids[] = (int) $sub->user_id;
}
cache_users( $user_ids );

// In the loop, use the map instead of individual queries:
$subscription = isset( $subscriptions_map[ (int) $response->subscription_id ] )
    ? $subscriptions_map[ (int) $response->subscription_id ]
    : null;
```

- [x] `resolve_effective_visibility()` — Accept subscription as parameter instead of fetching it

Change signature to accept the pre-loaded subscription object:

```php
private static function resolve_effective_visibility( $response, $subscription = null ) {
    if ( 'default' !== $response->visibility_override ) {
        return $response->visibility_override;
    }
    if ( $subscription ) {
        return $subscription->visibility_default;
    }
    return 'anonymous';
}
```

**Query reduction:** 3N queries → 2 queries (subscriptions batch + user cache prime).

### Phase 5: Callers — Digest Compilation

**File:** `includes/class-orbit-notifier.php`

**Tasks:**

- [x] `send_digest()` — Batch-load profiles and subscriptions before loops

```php
// After fetching queued items, batch-load profiles.
$profile_ids  = array_unique( array_column( $queued_items, 'profile_id' ) );
$profiles_map = Orbit_Profile::get_by_ids( array_map( 'intval', $profile_ids ) );

// Pre-fetch subscriptions for action token generation.
$subscriptions = Orbit_Subscription::list( array(
    'user_id'  => $user_id,
    'status'   => 'approved',
    'per_page' => 100,
) );
$sub_by_profile = array();
foreach ( $subscriptions as $sub ) {
    $sub_by_profile[ (int) $sub->profile_id ] = $sub;
}

// In loops, use maps:
$profile     = isset( $profiles_map[ (int) $item->profile_id ] ) ? $profiles_map[ (int) $item->profile_id ] : null;
$subscription = isset( $sub_by_profile[ (int) $item->profile_id ] ) ? $sub_by_profile[ (int) $item->profile_id ] : null;
```

- [x] `send_digest()` — Batch update log status

```php
// Replace per-row $wpdb->update() with single bulk update.
$log_ids = array_map( function ( $item ) { return (int) $item->log_id; }, $queued_items );
if ( ! empty( $log_ids ) ) {
    $placeholders = implode( ',', array_fill( 0, count( $log_ids ), '%d' ) );
    $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$log_table} SET status = %s, sent_at = %s WHERE id IN ({$placeholders})",
            $status,
            $now,
            ...$log_ids
        )
    );
}
```

**Query reduction:** 2N profile/subscription queries + N update queries → 3 queries total.

## System-Wide Impact

### Interaction Graph

- All changes are internal optimizations — no new hooks, no API contract changes, no behavior changes.
- `list_by_profile_ids()` uses the same database and indexing as `list()`.
- `cache_users()` is a WordPress core function that pre-populates the WP_User object cache — all subsequent `get_userdata()` calls become cache hits.

### Error Propagation

- No new error paths. Batch methods return empty arrays on empty input.
- If `$wpdb->prepare()` fails on an IN clause (should not happen), it fails the same way the single-row queries would.

### State Lifecycle Risks

- None. All changes are read-only query optimizations except the digest bulk UPDATE, which replaces N identical single-row UPDATEs with one bulk UPDATE — same final state.

### API Surface Parity

- New batch methods are internal to the data layer. No REST API or CLI changes.
- The `get_activities()` REST endpoint will return the same data, just faster.

## Acceptance Criteria

### Functional Requirements

- [x] Dashboard displays the same activities, profiles, and response counts as before
- [x] REST `GET /activities` returns the same data as before
- [x] Privacy `resolve_responses()` produces the same visibility output
- [x] Digest emails contain the same content and action tokens
- [x] Manage view shows the same response counts

### Non-Functional Requirements

- [x] Dashboard page load uses ≤ 10 queries (was 160+ at 10 subscriptions)
- [x] REST `GET /activities` uses ≤ 5 queries (was N+1)
- [x] `resolve_responses()` with 50 responses in "names" mode uses ≤ 5 queries (was 150)
- [x] `send_digest()` with 20 items uses ≤ 5 queries (was 40+)
- [x] `process_dispatch()` preferences lookup uses 1 query per unique user (was 3+)

### Quality Gates

- [x] No PHP warnings or notices
- [x] Empty states (no subscriptions, no activities, no responses) handled correctly
- [x] Single-subscription users still work (IN clause with 1 value)

## Dependencies & Prerequisites

- None. All existing indexes support IN clause queries.
- WordPress `cache_users()` is available since WP 3.0.

## Risk Analysis & Mitigation

| Risk | Impact | Likelihood | Mitigation |
|------|--------|------------|------------|
| IN clause with thousands of IDs | Medium | Low | Dashboard caps at 50 activities; digest processes per-user |
| `cache_users()` memory with many users | Low | Low | Subscribers shortcode already limited to 100 per page |
| Static cache stale within long-running process | Low | Low | Cache is request-scoped; ActionScheduler spawns fresh requests |

## Sources & References

### Internal References

- Todo: `todos/009-pending-p2-n-plus-1-query-patterns.md`
- Database schema: `includes/class-orbit-activator.php`
- Existing IN clause pattern: `includes/class-orbit-privacy.php:169-184` (cleanup cascade)

### External References

- [WordPress `cache_users()` function reference](https://developer.wordpress.org/reference/functions/cache_users/)
- [MySQL IN clause optimization](https://dev.mysql.com/doc/refman/8.0/en/range-optimization.html)
