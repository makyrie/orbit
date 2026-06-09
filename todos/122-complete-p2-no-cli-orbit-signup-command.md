---
status: complete
priority: p2
issue_id: "122"
tags: [code-review, PR-26, agent-native, cli]
dependencies: []
---

# No wp orbit signup command — agents and ops cannot create posters end-to-end

## Problem Statement

`POST /orbit/v1/signup` is the only programmatic way to create a poster account that captures consent. There is no equivalent `wp orbit signup` CLI command. As a result:

- An agent cannot create a poster account the way a human can at /sign-up/.
- Seed scripts, demo content, and support flows all need to either drive the REST endpoint or skip the consent path and use `wp user create` (which produces an undefended account).
- Integration tests have no convenient way to create a poster fixture.

## Findings

- `cli/` does not contain a `class-orbit-cli-signup.php`.
- `orbit.php` registers no `orbit signup` subcommand.
- `includes/class-orbit-rest-signup.php` is the only path that captures display_name + email + phone + consent and stamps the ledger.
- Surfaced by agent-native-reviewer (finding #3) during multi-agent review.

## Proposed Solutions

**Option A — New `class-orbit-cli-signup.php` (recommended).** Add:

```
wp orbit signup create --display_name=<name> --email=<email> [--phone=<phone>] --consent_email [--consent_sms]
```

Same validation, same consent stamping, same ledger writes as the REST handler. Use the same downstream provisioning function (eventually `Orbit_User_Provisioning::create_user_with_consent` from todo 130).

Effort: low — mirrors the existing CLI files.

**Option B — Make `wp orbit subscription create` polymorphic.** Reuse the subscription command and have it create a user when `--user_id` is omitted. Saves a file, but conflates two distinct flows (subscribe an existing user vs sign up a brand-new poster) — confusing.

## Recommended Action

Option A. Keep the CLI verb-mapping aligned to the REST endpoint vocabulary (`signup` ↔ `/signup`).

## Technical Details

- Register the CLI class alongside the existing CLI registrations in `orbit.php`.
- Surface the same WP_Error codes the REST handler returns so scripts can branch on them.
- `cta_snapshot` should come from the same source as REST + CLI subscription create (see todo 121).
- Provenance: `ip_address = 'cli'`, `user_agent = 'wp orbit signup create'`.
- Auto-confirm flag: `--skip-email` to suppress `wp_send_new_user_notifications` for fixture creation in tests.

## Acceptance Criteria

- [ ] `wp orbit signup create` exists with display_name / email / phone / consent_email / consent_sms flags.
- [ ] Returns the same WP_Error codes as the REST endpoint for the same failure modes.
- [ ] Consent ledger rows match REST rows in structure and content.
- [ ] `--skip-email` flag suppresses welcome email for fixture use.
- [ ] Smoke test: `wp orbit signup create --display_name="Test Poster" --email=test@example.com --consent_email` produces a working poster account with one ledger row.

## Work Log

- 2026-06-08: Surfaced during PR #26 multi-agent code review.
- 2026-06-09: Shipped `cli/class-orbit-cli-signup.php` with the `create`
  subcommand at parity with `POST /orbit/v1/signup`. Validation, multisite
  attach, transaction envelope, and consent stamping all mirror the REST
  handler. Welcome email is opt-in via `--send-welcome-email` so seed
  scripts don't spray real inboxes (the REST handler always defers to
  ActionScheduler). CLI consent rows are stamped with `source=cli` and
  `user_agent='wp orbit signup create'`. Registered in `orbit.php`
  alongside the other CLI commands. Bundled with todo 123 in PR #26
  review wave C; tests in `tests/OrbitCliSignupTest.php`.

## Resources

- PR #26: https://github.com/makyrie/orbit/pull/26
- `cli/` (new `class-orbit-cli-signup.php`)
- `orbit.php` (CLI registration)
- Related: todo 121 (subscription parity), todo 130 (provisioning service)
