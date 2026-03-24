---
status: pending
priority: p2
issue_id: "016"
tags: [code-review, php, wordpress]
dependencies: ["005"]
---

# Anonymous Closure on delete_user Cannot Be Removed

## Problem Statement

The `delete_user` handler is a 50-line anonymous closure that cannot be removed by other plugins, themes, or tests via `remove_action()`. This violates WordPress extensibility conventions.

**Affected file:** `orbit.php:131-182`

## Proposed Solutions

Extract to a named static method. Natural home: `Orbit_Privacy::cleanup_user_data()` or a dedicated cleanup class. Combine with #005 (incomplete cascade fix).

## Acceptance Criteria

- [ ] `delete_user` handler is a named function or static method
- [ ] Other plugins can `remove_action('delete_user', ...)` if needed
