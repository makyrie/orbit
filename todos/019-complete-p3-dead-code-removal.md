---
status: pending
priority: p3
issue_id: "019"
tags: [code-review, quality, simplicity]
dependencies: []
---

# Dead Code: 3 Unused Methods + 1 Unused Constant + 1 Empty Method

## Problem Statement

- `Orbit_Phone_Verify::reset_on_phone_change()` — never called (9 lines)
- `Orbit_Subscription::set_visibility()` — never called (25 lines)
- `Orbit_Privacy::can_view_location_address()` — never called (21 lines)
- `ORBIT_PLUGIN_URL` constant — defined, never used
- `Orbit_Routes::handle_unsubscribe_route()` — empty method body

## Proposed Solutions

Remove all dead code. ~60 lines eliminated.

## Acceptance Criteria

- [ ] No unreferenced methods or constants remain
