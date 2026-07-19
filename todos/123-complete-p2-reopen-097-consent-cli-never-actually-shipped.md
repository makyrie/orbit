---
status: complete
priority: p2
issue_id: "123"
tags: [code-review, PR-26, agent-native, cli, audit]
dependencies: []
---

# Reopen todo 097 — consent CLI was marked complete but never shipped

## Problem Statement

Todo `097-complete-p2-no-cli-for-consent-log-and-verify-chain.md` is filed as `status: complete`, but the implementation never landed:

- `cli/class-orbit-cli-consent.php` does not exist.
- `orbit.php:112-118` registers no `orbit consent` subcommand.

Phase 2 of the project (this PR) makes this gap significantly more painful — every signup and subscribe now writes one to two ledger rows. The only audit path today is raw SQL against `wp_orbit_consent_log`, which is unsafe for ops users and impossible for agents.

## Findings

- `todos/097-complete-p2-no-cli-for-consent-log-and-verify-chain.md` — status field says `complete`, but the referenced files do not exist.
- `includes/class-orbit-consent.php` — has the primitives `Orbit_Consent::record()`, `Orbit_Consent::verify_chain()`, and `Orbit_Consent::latest_state()` that the CLI was supposed to wrap.
- Surfaced by agent-native-reviewer (finding #1) during multi-agent review.

## Proposed Solutions

**Option A — Reopen 097 + ship the CLI (recommended).**

1. Rename `097-complete-p2-...` to `097-pending-p2-...` and reset its `status` field.
2. Implement:
   - `wp orbit consent log [--user_id=<id>] [--channel=email|sms] [--limit=<n>]` — list ledger rows with the same columns as the dashboard.
   - `wp orbit consent verify [--user_id=<id>]` — wrap `Orbit_Consent::verify_chain()` and print PASS / FAIL with the first broken-link index.
   - `wp orbit consent state --user_id=<id>` — wrap `Orbit_Consent::latest_state()` and print the current effective opt-in state per channel.

Effort: low — pure CLI wrappers around existing primitives.

**Option B — Reopen 097 and leave the implementation for a follow-up PR.** Honest about the state but doesn't fix the audit gap.

## Recommended Action

Option A. The primitives already exist and the wrappers are thin. Document in the body of the reopened 097 that the previous `complete` marker was an error.

## Technical Details

- Output should default to a tab-separated table; support `--format=json` and `--format=csv` for scripted use.
- `verify` should exit non-zero on broken chain so it can be used in monitoring scripts.
- `log` should accept `--since` / `--until` ISO timestamps for slicing.
- Document an audit recipe in the AGENTS.md (or a `docs/runbooks/audit-consent.md`) so a reviewer can reproduce a TCR-defensible export.

## Acceptance Criteria

- [ ] 097 renamed back to `097-pending-p2-...` with an explanatory note in the body about the false-complete.
- [ ] `cli/class-orbit-cli-consent.php` exists and registers three subcommands.
- [ ] `wp orbit consent verify` exits non-zero on broken chain.
- [ ] Each subcommand supports `--format=json` for scripted use.
- [ ] PHPUnit / wp-cli smoke test exercises log + verify + state against a seeded ledger.

## Work Log

- 2026-06-08: Surfaced during PR #26 multi-agent code review.
- 2026-06-09: Shipped `cli/class-orbit-cli-consent.php` with three
  subcommands: `log` (filterable ledger row dump), `verify` (walks
  `Orbit_Consent::verify_chain()` per user/channel; exits non-zero on
  any broken chain so it composes into monitoring scripts), and `state`
  (wraps `Orbit_Consent::latest_state()` for both channels). Registered
  in `orbit.php`. Resolved option (a) on todo 097: left its status
  `complete`, added a "superseded by 123" header note plus a dated
  Work Log entry attributing the actual fix to PR #26 review wave C.
  Tests in `tests/OrbitCliConsentTest.php` cover verify PASS / FAIL,
  state per-channel, and log filter dispatch.

## Resources

- PR #26: https://github.com/makyrie/orbit/pull/26
- `todos/097-complete-p2-no-cli-for-consent-log-and-verify-chain.md` (rename)
- `cli/` (new `class-orbit-cli-consent.php`)
- `includes/class-orbit-consent.php` (existing primitives)
