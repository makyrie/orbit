---
status: pending
priority: p1
issue_id: "001"
tags: [code-review, security, php, data-integrity]
dependencies: []
---

# SMS Daily Cap Null Stored as Zero — Blocks All SMS for New Subscribers

## Problem Statement

When creating default notification preferences, `sms_daily_cap => null` is passed with format `'%s'`. `$wpdb->prepare()` converts null with `%s` to empty string `''`, which MySQL casts to `0` for a `smallint` column. This means every new subscriber gets `sms_daily_cap = 0`, and `is_sms_cap_reached()` returns true immediately (since `0 >= 0`), **blocking all SMS notifications for every new subscriber**.

This silently breaks the core notification system.

## Findings

- **Call-chain verifier (X-001):** Confirmed `null` with `%s` produces `0` in MySQL, making `is_sms_cap_reached()` always true.
- **Schema reviewer (#9):** Confirmed column is `smallint unsigned DEFAULT NULL` — null means unlimited, 0 means zero cap.
- **PHP reviewer (#13):** Confirmed the format mismatch in `update_preferences()` has the same issue.

**Affected files:**
- `includes/class-orbit-notifier.php:459-471` — `get_or_create_preferences()` INSERT
- `includes/class-orbit-notifier.php:505-506` — `update_preferences()` UPDATE

## Proposed Solutions

### Option A: Remove sms_daily_cap from INSERT, let column default to NULL
- **Pros:** Simplest fix, relies on database default
- **Cons:** Less explicit about intent
- **Effort:** Small
- **Risk:** Low

### Option B: Build INSERT data conditionally, omitting null fields
- **Pros:** Handles null properly for any field
- **Cons:** More code
- **Effort:** Small
- **Risk:** Low

## Recommended Action

Option A.

## Acceptance Criteria

- [ ] New subscribers have `sms_daily_cap = NULL` in database (not 0)
- [ ] `is_sms_cap_reached()` returns false for users with null cap
- [ ] `update_preferences()` can set cap back to null (unlimited)
