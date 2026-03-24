---
status: pending
priority: p2
issue_id: "008"
tags: [code-review, performance]
dependencies: []
---

# Synchronous Notification Dispatch Blocks REST Response

## Problem Statement

`Orbit_Notifier::dispatch_for_activity()` runs synchronously in the `create_activity` REST handler. It iterates all approved subscribers (up to 9,999), calling `get_or_create_preferences()` for each. For profiles with hundreds of subscribers, this causes request timeouts.

**Affected files:**
- `includes/class-orbit-rest-api.php:794`
- `includes/class-orbit-notifier.php:66-117`

## Proposed Solutions

Wrap the entire dispatch in a single ActionScheduler async action so the REST response returns immediately.

## Acceptance Criteria

- [ ] Activity creation returns 201 within 1-2 seconds regardless of subscriber count
- [ ] Notification dispatch runs asynchronously via ActionScheduler
