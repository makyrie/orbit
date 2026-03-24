---
status: pending
priority: p2
issue_id: "009"
tags: [code-review, performance, php]
dependencies: []
---

# N+1 Query Patterns in Dashboard, REST API, Privacy, and Digest

## Problem Statement

Multiple code paths fetch data in loops instead of batch queries. A user subscribed to 10 profiles with 20 activities each produces 600+ queries for a single dashboard page load.

## Findings

**Locations (from Performance Oracle):**
1. `class-orbit-shortcodes.php:59-69` — Dashboard fetches activities per subscription in a loop
2. `class-orbit-rest-api.php:732-744` — `get_activities()` same loop pattern
3. `class-orbit-privacy.php:65-87` — `resolve_responses()` calls `get()` per response in names mode
4. `class-orbit-notifier.php:302-391` — `send_digest()` calls `get()` per item for profiles and subscriptions
5. `class-orbit-shortcodes.php:142-143` — Dashboard calls `count_by_activity()` per activity (2x)
6. `class-orbit-shortcodes.php:286` — Manage view calls `count_by_activity()` per activity
7. `class-orbit-shortcodes.php:512-513` — Subscribers shortcode calls `get_userdata()` per subscriber

## Proposed Solutions

1. Add `Orbit_Activity::list_by_profile_ids($ids)` using `WHERE profile_id IN (...)`
2. Batch-load profiles with `WHERE id IN (...)`
3. Batch response counts with `GROUP BY activity_id, response`
4. Use `cache_users($user_ids)` before subscriber rendering loops
5. Add static request-level caching to `get_or_create_preferences()`

## Acceptance Criteria

- [ ] Dashboard page load uses < 20 queries regardless of subscription count
- [ ] REST `GET /activities` uses a single query for all profiles
- [ ] Privacy resolution batch-loads subscriptions and users
