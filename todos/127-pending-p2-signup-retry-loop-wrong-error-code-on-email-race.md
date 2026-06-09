---
status: pending
priority: p2
issue_id: "127"
tags: [code-review, PR-26, error-handling, race-conditions]
dependencies: []
---

# Signup retry loop maps email-race failures to generic 500 instead of 409

## Problem Statement

The signup handler's retry loop watches for `wp_insert_user` errors with code `existing_user_login` and retries with a suffixed username. Any *other* WP_Error code throws via the transaction catch and emerges as a generic 500 with code `signup_failed`.

The realistic failure that escapes this path is `existing_user_email`: a concurrent signup grabbed the same email between our upstream email-existence check (line 137) and the actual `wp_insert_user` call. The user submitting the form sees a 500 "we couldn't create your account" — but the truthful response is 409 "this email already exists, please log in" with a `login_url`. The race-loser deserves the same UX as the steady-state duplicate.

## Findings

- `includes/class-orbit-rest-signup.php:171-256` — retry loop branches only on `existing_user_login`; all other codes fall through to the catch.
- `includes/class-orbit-rest-signup.php:137` — upstream email existence check, vulnerable to race window with insert.
- Surfaced by call-chain-verifier (finding #8) during multi-agent review.

## Proposed Solutions

**Option A — Map known codes in the catch (recommended).** When the catch fires, inspect the underlying WP_Error code (requires todo 116 — the carrier exception). Known mappings:

- `existing_user_email` → 409 with code `login_required`, same `login_url` shape as the steady-state branch.
- `existing_user_login` after exhausted retries → 500 with a more specific code (we genuinely could not generate a free username).
- Anything else → existing generic 500.

Effort: low once todo 116 lands.

**Option B — Extend the retry loop.** Add `existing_user_email` as another retriable code… but there's nothing to retry: the user supplied the email. So this is wrong shape.

## Recommended Action

Option A. Coupled with todo 116, this becomes a small switch statement in the catch block.

## Technical Details

- Compose this with todo 116's `Orbit_RolledBack_Exception` — the catch already has access to the inner WP_Error via `$e->wp_error`.
- The `login_url` should match what the upstream 409 path produces (see todo 074 for the existing login-URL shape).
- The metric / log should distinguish steady-state duplicates (caught at line 137) from race-loser duplicates (caught here) so we can quantify how often the race actually fires.

## Acceptance Criteria

- [ ] `existing_user_email` raised by `wp_insert_user` is mapped to 409 `login_required` with a `login_url`, matching the steady-state path.
- [ ] Exhausted-retry `existing_user_login` produces a specific code, not the generic `signup_failed`.
- [ ] Other failure codes continue to produce the generic 500.
- [ ] PHPUnit test simulates the race (force `wp_insert_user` to return `existing_user_email`) and asserts 409 + `login_url`.
- [ ] Server log distinguishes race-loser from steady-state duplicates.

## Work Log

- 2026-06-08: Surfaced during PR #26 multi-agent code review.

## Resources

- PR #26: https://github.com/makyrie/orbit/pull/26
- `includes/class-orbit-rest-signup.php:171-256, 137`
- Related: todo 116 (carrier exception), todo 074 (login URL shape)
