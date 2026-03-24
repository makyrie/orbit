---
status: pending
priority: p2
issue_id: "012"
tags: [code-review, php, architecture]
dependencies: []
---

# Deactivation Does Not Unschedule ActionScheduler Jobs

## Problem Statement

The plugin schedules recurring ActionScheduler jobs but never unschedules them on deactivation. After deactivation, these jobs accumulate as failed entries.

**Affected file:** `orbit.php:97-99`

## Proposed Solutions

Add `as_unschedule_all_actions()` for all 4 hooks in `orbit_deactivate()`.

## Acceptance Criteria

- [ ] All 4 ActionScheduler hooks are unscheduled on deactivation
- [ ] No orphaned scheduled actions remain after deactivation
