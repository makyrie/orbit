---
status: complete
priority: p2
issue_id: "061"
tags: [code-review, consistency, css]
dependencies: []
---

# `.orbit-profile-owner-bar` naming breaks the `.orbit-notice` family

## Problem Statement

`includes/class-orbit-shortcodes.php` lines 1164-1168 (and the corresponding rule in `assets/css/orbit.css`) introduce a new class `.orbit-profile-owner-bar` for a persistent owner-only banner. Every other persistent banner in the plugin uses the `.orbit-notice…` family or `.orbit-…-badge` suffix; the new "bar" suffix appears nowhere else and breaks the established naming convention.

## Proposed Solution

1. Rename the class to `.orbit-notice orbit-notice-owner` in the markup.
2. Update the corresponding CSS rule in `assets/css/orbit.css` so the styles attach to `.orbit-notice-owner` (composed with the base `.orbit-notice` styles).
3. Optional: factor `.orbit-notice-owner` as a reusable modifier so future "owner-only" affordances can adopt the same pattern.

## Acceptance Criteria

- No remaining occurrences of `.orbit-profile-owner-bar` in PHP or CSS.
- Owner banner renders with `class="orbit-notice orbit-notice-owner"` and inherits base notice styles.
- Visual rendering matches the previous bar (or is intentionally improved).
- Class naming is consistent with the rest of the `.orbit-notice` family.
