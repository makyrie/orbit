---
status: pending
priority: p2
issue_id: "017"
tags: [code-review, performance, database]
dependencies: []
---

# Missing Database Indexes for Common Query Patterns

## Problem Statement

Several queries lack supporting indexes:
1. `orbit_phone_verification` — rate-limit query filters on `phone + created_at` but only `user_id` is indexed
2. `orbit_notification_log` — cleanup query filters on `created_at` alone, but the existing composite index starts with `user_id`
3. `orbit_notification_log` — digest query filters on `user_id + method + status` but index covers `user_id + method + created_at`

## Proposed Solutions

Add to `class-orbit-activator.php`:
```sql
-- orbit_phone_verification
KEY phone_created (phone, created_at)

-- orbit_notification_log
KEY created_at (created_at)
```

## Acceptance Criteria

- [ ] Rate-limit and cleanup queries use indexes (EXPLAIN shows index usage)
