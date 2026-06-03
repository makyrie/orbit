---
status: complete
priority: p1
issue_id: "082"
tags: [code-review, privacy, gdpr, lifecycle, PR-24]
dependencies: []
---

# uninstall.php doesn't drop wp_orbit_consent_ledger — PII table orphaned after plugin removal

## Problem Statement

`uninstall.php` iterates a hardcoded list of 7 per-site tables and runs `DROP TABLE IF EXISTS {$wpdb->prefix}{$table}`. PR #24 adds an 8th table, `wp_orbit_consent_ledger` (network-scoped — uses `$wpdb->base_prefix`), but `uninstall.php` is unchanged.

Two compounding problems:

1. The new ledger is not in the `$tables` array — `wp uninstall orbit` leaves it behind.
2. Even if added, the existing loop uses `$wpdb->prefix` (per-site), not `$wpdb->base_prefix`. The ledger requires `base_prefix`, so a simple "add it to the array" fix would still drop the wrong (non-existent) table on multisite.

The orphaned table retains `ip_hash`, `user_agent`, `cta_snapshot`, and `user_id` linkage — PII under GDPR even after the plugin is gone. A site admin who uninstalls Perihelion has a reasonable expectation that all plugin data is removed.

## Findings

- `uninstall.php:15-27` — hardcoded 7-table list, single-prefix DROP loop.
- `includes/class-orbit-activator.php:180` — ledger created at `base_prefix`.
- `includes/class-orbit-consent.php:65` — same base_prefix accessor.

## Proposed Solutions

**Option A — Add the ledger with a special-case branch (minimum viable):**

```php
$base_prefix_tables = array( 'orbit_consent_ledger' );

foreach ( $base_prefix_tables as $table ) {
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->base_prefix}{$table}" );
}
```

**Option B — Extract a shared `Orbit_Activator::table_names()` helper** that returns `[ table_name => prefix_type ]` and use it from BOTH `create_tables()` and `uninstall.php`. Single source of truth — prevents the next ledger-style table from missing the same uninstall step.

**Option C — Keep `cleanup_user_data()`-style soft-delete semantics** (don't drop the ledger; just redact). Defensible for TCPA evidence preservation across reinstalls. Requires explicit admin documentation since `wp uninstall` usually means "remove everything."

Recommend **Option A** for v1.6.0 (5-minute fix), with **Option B** filed as a follow-up so the next net-new table doesn't repeat this miss.

## Recommended Action

(Filled during triage.)

## Technical Details

- Affected file: `uninstall.php`
- Multisite consideration: on a network deactivation/uninstall, the ledger is removed once for the whole network (correct — it's a network table).

## Acceptance Criteria

- [ ] `wp uninstall orbit` removes `wp_orbit_consent_ledger` (base_prefix).
- [ ] No leftover orphan tables on a multisite uninstall.
- [ ] Docblock at `class-orbit-activator.php:26` updated to "8 custom tables" while we're here (schema-drift #2).

## Work Log

- 2026-06-01: Identified during code review of PR #24 by schema-drift-detector.

## Resources

- PR #24: https://github.com/makyrie/orbit/pull/24
- `uninstall.php:15-27`
- `includes/class-orbit-activator.php:26, 180`
