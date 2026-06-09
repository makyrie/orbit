# Orbit - Agent Instructions

## Overview

<!-- Describe the plugin's purpose and architecture here. -->

## Development Guidelines

<!-- Add project-specific development instructions here. -->

## Transactional Boundaries

Both `Orbit_REST_Subscription::handle_subscribe()` and
`Orbit_REST_Signup::handle_signup()` wrap account provisioning in an InnoDB
transaction (`START TRANSACTION` / `COMMIT` / `ROLLBACK`). Inside the
transaction they call `wp_insert_user` / `wp_create_user`, which fire the
WordPress `user_register` action (and `wpmu_new_user` on multisite)
**synchronously, while the transaction is still open**.

This invariant is load-bearing: any hook on `user_register` or
`wpmu_new_user` that issues a non-DML statement —
`CREATE TABLE`, `CREATE TEMPORARY TABLE`, `ALTER`, `DROP`, `TRUNCATE`,
`RENAME`, `REPLACE` (in some configurations) — will trigger
MySQL's [implicit commit](https://dev.mysql.com/doc/refman/8.0/en/implicit-commit.html).
At that point the active transaction silently commits, our subsequent
`ROLLBACK` becomes a no-op, and atomicity is destroyed: a partial
provisioning failure can leave a half-created user with no consent rows,
or stranded notifier preferences with no user. `$wpdb->query()` does not
validate the DML/DDL split — it submits whatever SQL it's handed.

Rules for code in this repo and for plugins that integrate with Orbit:

- **Do not register `user_register` / `wpmu_new_user` callbacks that
  issue DDL.** DML (`INSERT`, `UPDATE`, `DELETE`, `SELECT`) is safe.
- If a hook genuinely needs to provision a table on first use, do it
  lazily on a non-provisioning code path (e.g. activation, admin_init,
  or a `shutdown` hook that runs after the REST response).
- The tripwire test in `tests/OrbitTransactionSafetyCanaryTest.php`
  registers a sentinel `user_register` callback that writes to a known
  table and then forces the provisioning transaction to roll back. If
  the sentinel row survives, the test fails with a pointer back to this
  section — that is the signal that an implicit COMMIT happened
  somewhere in the chain.
- Related: todo 130 (extract a provisioning service) — if/when that
  lands, this invariant travels with the service.
