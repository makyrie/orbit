---
status: complete
priority: p3
issue_id: "064"
tags: [code-review, style, php, phpcs]
dependencies: []
---

# Yoda + alignment cleanup pass

## Problem Statement

Several WPCS style violations remain in `includes/class-orbit-shortcodes.php`:

- `:698` — `$tier_value === $default_tier` should be Yoda: `$default_tier === $tier_value`.
- `:287-292` — over-aligned variable assignments (e.g., `$wp_timezone        = ...`, `$digest_tz_label    = ...`); WPCS prefers single-space.
- Other Yoda spot-checks elsewhere in the same file.

## Proposed Solution

Run a Yoda + alignment cleanup pass on `includes/class-orbit-shortcodes.php`. Once the `wp-phpcs` skill is set up, run PHPCS with the WordPress standard to also catch translator comments, file-level standards, and similar style issues across the plugin.

## Acceptance Criteria

- [ ] `:698` comparison is Yoda-style.
- [ ] `:287-292` assignments use single-space alignment.
- [ ] PHPCS (WordPress standard) reports no Yoda or alignment violations in `includes/class-orbit-shortcodes.php`.
- [ ] Behavior is unchanged after the cleanup.
