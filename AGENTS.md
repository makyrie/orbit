# Orbit - Agent Instructions

## Overview

Orbit is the WordPress plugin behind **Perihelion**, a person-centric social
activity service. A poster publishes activities at one of three commitment
tiers; people subscribe to that poster and receive email, digest, or optional
SMS notifications, then respond “going” or “maybe” without needing a separate
app. The product deliberately treats no response as a private, socially
costless decline. “Orbit” remains the internal code, database, REST, shortcode,
and WP-CLI namespace; “Perihelion” is the public-facing brand.

The plugin is the application layer, not just a WordPress content extension. It
owns user roles and capabilities, custom-table persistence, provisioning,
privacy and consent records, REST endpoints, virtual routes, shortcode-rendered
screens, notification scheduling/delivery, Twilio integration, and operational
WP-CLI commands. A companion block theme supplies the site shell and templates,
while this repository also contains the frontend CSS and JavaScript used by the
plugin's forms and app screens.

### Product model
- WordPress users may be subscribers, posters, or both. Posters have one
  `orbit_profiles` record; `orbit_subscriptions` relates subscriber users to
  poster profiles.
- Activities belong to profiles and use tiers 1–3 (“Just an idea”, “I'll go if
  you will”, and “I'm going — join me”). Responses belong to a subscription and
  are limited to `going` or `maybe`.
- Notification preferences are account-wide. Action Scheduler handles activity
  dispatch, immediate delivery, daily digests, lifecycle updates, cleanup, and
  deferred new-user email. Twilio provides phone verification and SMS webhooks.
- Privacy is restrictive by default. Subscription secrets and scoped HMAC
  action tokens support no-login RSVP/unsubscribe flows. Consent is recorded in
  an append-only, hash-chained ledger with policy snapshots and retention rules.

### Architecture and source map
- `orbit.php` is the bootstrap and composition root: constants, class loading,
  activation/upgrades, hooks, asset loading, and WP-CLI registration.
- `includes/class-orbit-{profile,activity,subscription,response}.php` contain
  the core domain CRUD. `class-orbit-user-provisioning.php` centralizes account
  creation shared by signup and subscribe flows.
- `includes/class-orbit-rest-*.php` expose the `orbit/v1` REST API. Public
  signup, subscribe, RSVP-token, unsubscribe, and Twilio webhook operations sit
  beside capability-checked subscriber, poster, and admin operations.
- `includes/class-orbit-routes.php` implements virtual public pages such as
  `/@{slug}`, `/@{slug}/subscribe`, `/activity/{id}`, and `/unsubscribe`.
  `class-orbit-shortcodes.php` renders those pages plus the dashboard, settings,
  subscription management, poster management, profile, and signup screens.
- `includes/class-orbit-notifier.php`, `class-orbit-twilio.php`, and
  `class-orbit-phone-verify.php` own asynchronous notification routing and SMS.
  `class-orbit-consent.php`, `class-orbit-compliance-ui.php`, and
  `class-orbit-privacy.php` own compliance evidence, policy UI, and deletion.
- `includes/class-orbit-activator.php` creates eight InnoDB tables and the
  shortcode-backed application/compliance pages. The consent ledger is
  network-scoped on multisite; the other tables are site-scoped.
- `cli/` mirrors the main resources for administration and diagnostics.
  `tests/` is a WordPress PHPUnit integration suite; run it using the settings
  in `phpunit.xml.dist`. Run `composer policy-diff` whenever policy copy changes.

### Website and content work
Website strategy and source material live in `docs/`. Start with
`content-architecture.md`, `creative-direction.md`, `design-system.md`, and
`brand-brief.md`; `marketing-plan.md`, `gtm-playbook.md`, and
`website-engagement.md` cover launch and engagement. Canonical legal prose lives
in `docs/compliance/` and is duplicated in `Orbit_Activator`; those copies must
remain byte-equivalent, and policy edits require an `ORBIT_VERSION` bump because
the consent ledger records that version. User-facing application copy is also
embedded in shortcode, compliance, messaging-copy, REST, email, SMS, and
JavaScript code, so content changes should search all of those surfaces rather
than assuming the theme is the sole owner.

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

## Local Testing
Orbit is a WordPress plugin. The directory is located at `wp-content/plugins/orbit/` within a Local Sites WordPress installation.

- Admin panel: https://orbit.local/wp-admin/
- Front end: https://orbit.local/
- Username: ai
- Password: d@(fUHQrUufY5*XE(w05DviD
