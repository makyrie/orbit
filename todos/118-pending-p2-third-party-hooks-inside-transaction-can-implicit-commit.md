---
status: pending
priority: p2
issue_id: "118"
tags: [code-review, PR-26, transactions, wp-php]
dependencies: []
---

# Third-party hooks inside provisioning transaction can trigger InnoDB implicit COMMIT

## Problem Statement

The signup and subscribe transactions wrap `wp_insert_user` / `wp_create_user`, which fire `user_register`, `wpmu_new_user` (multisite), and `wpmu_activate_user`. Any third-party plugin hooking these that issues a non-DML statement — `CREATE TABLE`, `ALTER TABLE`, `DROP`, `CREATE TEMPORARY TABLE`, `REPLACE`, `RENAME`, `TRUNCATE` — triggers InnoDB's [implicit commit](https://dev.mysql.com/doc/refman/8.0/en/implicit-commit.html). The active transaction silently commits, our subsequent `ROLLBACK` becomes a no-op, and atomicity is destroyed.

The PR plan documents the assumption but does not enforce it. A user-installed analytics, audit-log, or newsletter plugin hooking `user_register` to lazily create its own table on first use is enough to break the invariant.

## Findings

- `includes/class-orbit-rest-subscription.php` and `includes/class-orbit-rest-signup.php` both wrap `wp_insert_user` / `wp_create_user` inside `START TRANSACTION` / `COMMIT` / `ROLLBACK`.
- WordPress fires `user_register` (and on multisite `wpmu_new_user`) synchronously inside `wp_insert_user`, which is inside our transaction.
- No documentation in `AGENTS.md` or `CLAUDE.md` flags the load-bearing "no DDL in `user_register`" assumption.
- Surfaced by wp-php-reviewer and architecture-strategist (finding #11) during multi-agent review.

## Proposed Solutions

**Option A — Document + tripwire test (recommended).**

1. Add a one-paragraph callout in `AGENTS.md` documenting the assumption: "Orbit provisioning runs `wp_insert_user` inside a transaction. Any hook on `user_register` / `wpmu_new_user` that issues DDL will trigger an InnoDB implicit COMMIT and destroy atomicity. Hooks added here must be DML-only."
2. Add an integration test that hooks `user_register` with a sentinel `INSERT` into a known table, forces the transaction to roll back (e.g. by making the next step fail), and asserts the sentinel row is absent. If the implicit-commit case ever lands, the test fails loudly.

Effort: low. Risk: low.

**Option B — Defer hooks via a queue.** Trap `user_register` callbacks ourselves, store the payload, run our DML, COMMIT, then dispatch the deferred hooks. Eliminates the risk but is invasive (re-implementing core's filter dispatch) and surprising to other plugins.

## Recommended Action

Option A. Documentation + a tripwire test is cheap and matches how this kind of invariant is normally guarded in the WordPress ecosystem. Option B is too invasive for the level of risk.

## Technical Details

- The tripwire test wires `add_action( 'user_register', $sentinel_callback )`, where the callback writes to a test table.
- Force rollback by raising an exception from a later step (e.g. consent stamping configured to fail).
- Assert the sentinel row is absent after the request returns the error.
- Keep the test in an `@group transactions` group so it can be run targeted in CI.

## Acceptance Criteria

- [ ] `AGENTS.md` documents the load-bearing assumption about `user_register` / `wpmu_new_user` hooks.
- [ ] Integration test exists in `tests/` that proves the rollback path actually rolls back hook-side DML.
- [ ] Test fails (visibly) when run against a configuration where the hook issues DDL — demonstrating the tripwire fires.
- [ ] Documentation cross-references todo 130 (provisioning service) so the assumption travels with the code.

## Work Log

- 2026-06-08: Surfaced during PR #26 multi-agent code review.

## Resources

- PR #26: https://github.com/makyrie/orbit/pull/26
- `includes/class-orbit-rest-subscription.php`
- `includes/class-orbit-rest-signup.php`
- `AGENTS.md`
- MySQL implicit-commit docs: https://dev.mysql.com/doc/refman/8.0/en/implicit-commit.html
