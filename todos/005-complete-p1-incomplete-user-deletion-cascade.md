---
status: pending
priority: p1
issue_id: "005"
tags: [code-review, data-integrity, php]
dependencies: []
---

# Incomplete User Deletion Cascade — Orphaned Data

## Problem Statement

The `delete_user` hook cleans up the deleted user's own data but does NOT clean up data associated with other users that references the deleted user's profile. When a poster is deleted, their activities, other users' subscriptions to that profile, responses to those activities, and notification log entries referencing those activities are all orphaned.

## Findings

- **Schema reviewer (#2):** Confirmed missing cascades for activities, other users' subscriptions, and cross-user responses.
- **Hooks reviewer (#2):** The handler is also an anonymous closure, making it non-removable.

**Missing deletions in `orbit.php:131-182`:**
1. Activities owned by the deleted user's profile
2. Responses from OTHER users to those activities
3. Subscriptions from OTHER users to the deleted profile
4. Notification log entries for those activities

## Proposed Solutions

### Option A: Extend the delete_user handler to cascade through profile's activities
Extract to a named method and add the missing cascades.
- **Effort:** Medium
- **Risk:** Medium — must be careful about deletion order (responses before activities, subscriptions before profile)

## Acceptance Criteria

- [ ] Deleting a poster user removes their profile, activities, all responses to those activities, all subscriptions to that profile, and associated notification log entries
- [ ] Handler is a named function/method, not an anonymous closure
- [ ] No orphaned rows remain after user deletion
