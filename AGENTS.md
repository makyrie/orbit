# Orbit - Agent Instructions

## Overview

Orbit is the WordPress plugin behind **Perihelion**, a person-centric social
activity service. Posters publish activities at one of three commitment tiers;
people subscribe to a poster, receive email, digest, or optional SMS
notifications, and respond “going” or “maybe” without a separate app. “Orbit”
is the internal code, database, REST, shortcode, and WP-CLI namespace;
“Perihelion” is the public-facing brand.

The plugin is the application layer. It owns roles and capabilities, eight
custom tables, account provisioning, REST endpoints, virtual routes,
shortcode-rendered screens, notification scheduling/delivery, Twilio
integration, privacy, and consent evidence. The separate Perihelion block theme
provides the site shell and templates; this repository contains the frontend
CSS and JavaScript used by plugin forms and app screens.

### Source map

- `orbit.php` is the bootstrap and composition root.
- `includes/class-orbit-{profile,activity,subscription,response}.php` contain
  domain CRUD; `class-orbit-user-provisioning.php` centralizes account creation.
- `includes/class-orbit-rest-*.php` expose the `orbit/v1` API.
- `includes/class-orbit-routes.php` owns virtual public pages; shortcodes render
  those pages and the authenticated application screens.
- `includes/class-orbit-notifier.php`, `class-orbit-twilio.php`, and
  `class-orbit-phone-verify.php` own asynchronous notification delivery.
- `includes/class-orbit-consent.php`, `class-orbit-compliance-ui.php`, and
  `class-orbit-privacy.php` own compliance evidence, policy UI, and deletion.
- `includes/class-orbit-activator.php` creates the schema and required pages.
  The consent ledger is network-scoped on multisite; other tables are per-site.
- `cli/` contains operational commands; `tests/` is a WordPress PHPUnit
  integration suite.

### Documentation map

- `README.md` is the current operator and developer guide.
- `docs/README.md` classifies current guidance, strategy, historical plans, and
  dated audit artifacts.
- Website direction lives in `docs/content-architecture.md`,
  `creative-direction.md`, `design-system.md`, and `brand-brief.md`.
- Canonical legal prose lives in `docs/compliance/` and is duplicated in
  `Orbit_Activator`. Keep the copies byte-equivalent with `composer policy-diff`
  and bump `ORBIT_VERSION` whenever policy prose changes.

## Development Guidelines

- Treat `README.md` and current code as authoritative for implemented behavior;
  `docs/plans/`, `docs/refs/orbit-v1-spec.md`, and punch lists preserve earlier
  decisions and observations and may describe superseded states.
- User-facing copy is spread across shortcodes, compliance UI, REST responses,
  email/SMS builders, JavaScript, and the companion theme. Search all relevant
  surfaces before changing terminology.
- Run focused PHPUnit coverage for behavioral changes and `composer
  policy-diff` for any compliance-copy change.

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
