---
status: complete
priority: p3
issue_id: "068"
tags: [code-review, consistency, php]
dependencies: [060]
---

# Centralize Manage status labels in `Orbit_Activity::get_status_labels()`

## Problem Statement

In `includes/class-orbit-shortcodes.php:586-591`, the Manage table's `array( 'cancelled' => __( 'Cancelled' ), 'past' => __( 'Past' ) )` map is defined inline. This pattern is inconsistent with the existing colocation pattern used by `Orbit_Activity::get_tier_labels()` and `Orbit_Activity::get_tier_descriptions()`.

## Proposed Solution

Add an `Orbit_Activity::get_status_labels()` method that returns the status label map. Consider including `'active'`, `'cancelled'`, and `'past'` exhaustively so the method can also serve as the source of truth for the status fallback discussed in todo 060.

Update `includes/class-orbit-shortcodes.php:586-591` (and any other inline status-label site) to consume the centralized method.

## Acceptance Criteria

- [ ] `Orbit_Activity::get_status_labels()` exists and returns the canonical status label map.
- [ ] The Manage table consumes the centralized labels rather than an inline array.
- [ ] Any other inline duplicates of the same label set are routed through the new method.
- [ ] Translations remain marked with `__()` and behavior is unchanged.
