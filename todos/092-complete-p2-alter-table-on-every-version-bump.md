---
status: complete
priority: p2
issue_id: "092"
tags: [code-review, migrations, performance, PR-24]
dependencies: []
---

# ALTER TABLE notification_log runs on every version bump — production lock storm on busy installs

## Problem Statement

`includes/class-orbit-activator.php:216` unconditionally runs:

```php
$wpdb->query( "ALTER TABLE {$table_notif_log} MODIFY COLUMN status varchar(32) NOT NULL DEFAULT 'queued'" );
```

This fires on every `create_tables()` invocation, which is every time `orbit_maybe_upgrade()` sees a version mismatch. The comment claims "idempotent on already-VARCHAR(32) columns" — true semantically, but in MySQL 5.7 / MariaDB < 10.3 the MODIFY rewrites the entire table with an exclusive metadata lock. For a notification_log with millions of rows on a successful product, every plugin upgrade triggers a multi-minute lock during which `log_notification()` INSERTs block.

Worse: `update_option('orbit_db_version', ORBIT_VERSION)` runs even if the ALTER fails (return value unchecked), so a killed request leaves a partially-migrated schema with the version bumped — subsequent upgrades skip the redo.

Flagged by data-integrity-guardian, data-migration-expert, performance-oracle, wp-php-reviewer, schema-drift-detector (5 independent findings).

## Proposed Solutions

**Option A — Gate on version_compare (recommended):**

```php
$installed = get_option( 'orbit_db_version' );
if ( ! $installed || version_compare( $installed, '1.6.0', '<' ) ) {
    $result = $wpdb->query( "ALTER TABLE {$table_notif_log} MODIFY COLUMN status varchar(32) NOT NULL DEFAULT 'queued'" );
    if ( false === $result ) {
        error_log( 'Orbit: status-column ALTER failed; aborting version bump.' );
        return; // Don't update orbit_db_version.
    }
}
```

The check requires `create_tables()` to know `$installed_version`, so either pass it as a parameter or have `orbit_maybe_upgrade()` orchestrate.

**Option B — INFORMATION_SCHEMA check:** read the current column type first; only ALTER if it's still enum. More portable, less brittle to version-numbering mistakes.

Recommend **Option A** combined with the error-check on ALTER return — composition is correct, version-gated and atomic.

## Acceptance Criteria

- [ ] ALTER runs only when crossing 1.5.x → 1.6.0 boundary.
- [ ] ALTER failure is logged and aborts the version bump.
- [ ] Subsequent version bumps skip the no-op ALTER entirely.
- [ ] Test added: simulate upgrade from 1.5.0 → 1.6.0 → 1.6.1, assert ALTER fires exactly once.

## Work Log

- 2026-06-01: Identified during code review of PR #24 by 5 reviewers (data-integrity, data-migration, performance, wp-php, schema-drift).

## Resources

- PR #24: https://github.com/makyrie/orbit/pull/24
- `includes/class-orbit-activator.php:211-220`
