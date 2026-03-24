---
status: pending
priority: p2
issue_id: "010"
tags: [code-review, architecture, data-integrity]
dependencies: []
---

# No Database Upgrade Mechanism

## Problem Statement

`orbit_db_version` is stored on activation but never checked. When the plugin is updated, schema changes via `dbDelta()` will not be applied unless the user manually deactivates and reactivates.

**Affected files:**
- `includes/class-orbit-activator.php:159` — stores version
- `orbit.php` — no `plugins_loaded` version check

## Proposed Solutions

Add a `plugins_loaded` hook:
```php
add_action( 'plugins_loaded', function() {
    if ( get_option( 'orbit_db_version' ) !== ORBIT_VERSION ) {
        Orbit_Activator::create_tables();
    }
});
```

## Acceptance Criteria

- [ ] Schema changes are applied automatically on plugin update
- [ ] `orbit_db_version` is updated after successful migration
