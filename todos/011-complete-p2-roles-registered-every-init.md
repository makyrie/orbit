---
status: pending
priority: p2
issue_id: "011"
tags: [code-review, performance, php]
dependencies: ["010"]
---

# Roles Re-registered on Every init — DB Writes Per Request

## Problem Statement

`Orbit_Roles::register()` is called on every `init`, firing 9 `$admin_role->add_cap()` calls that write to `wp_options` on every page load. Roles only need to be registered on activation or version upgrade.

**Affected files:**
- `orbit.php:105` — `add_action( 'init', ... )`
- `includes/class-orbit-roles.php:20-62`

## Proposed Solutions

Remove the `init` hook. Gate role registration behind version check (combine with #010 upgrade mechanism).

## Acceptance Criteria

- [ ] `Orbit_Roles::register()` only runs on activation and version upgrade
- [ ] No `wp_options` writes on normal page loads from role registration
