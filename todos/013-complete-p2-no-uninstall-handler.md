---
status: pending
priority: p2
issue_id: "013"
tags: [code-review, architecture, wordpress]
dependencies: []
---

# No Uninstall Handler — Tables and Data Left Behind

## Problem Statement

No `uninstall.php` or `register_uninstall_hook()` exists. When deleted via WordPress admin, 7 custom tables, `orbit_db_version` option, user meta (`orbit_phone`, `orbit_phone_verified`, `orbit_timezone`, `orbit_sms_opted_out`), and roles/capabilities are left permanently.

## Proposed Solutions

Create `uninstall.php` that drops all 7 tables, deletes options, cleans user meta, and removes roles.

## Acceptance Criteria

- [ ] `uninstall.php` exists and cleans all plugin data
- [ ] All 7 tables dropped on uninstall
- [ ] All Orbit user meta cleaned
- [ ] Roles and capabilities removed
