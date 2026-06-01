---
status: pending
priority: p2
issue_id: "097"
tags: [code-review, cli, agent-native, consent-ledger, PR-24]
dependencies: []
---

# No CLI surface for consent ledger inspection or hash-chain verification

## Problem Statement

PR #24 ships ~1,800 lines of compliance-critical infrastructure but no CLI commands for the new consent-ledger domain. Two specific gaps:

1. **No `wp orbit consent log --user_id=<id>`** — operators can only inspect the ledger by writing SQL against `wp_orbit_consent_ledger`. The plan explicitly named this as the only operational tool required for v1.

2. **No `wp orbit consent verify --user_id=<id> --channel=<email|sms>`** — `Orbit_Consent::verify_chain()` is callable only from PHP. A security responder dealing with a tamper-detection incident has to drop into `wp shell` — unacceptable MTTR for the primitive whose entire purpose is tamper detection.

Without these surfaces, the audit trail engineered in this PR cannot be inspected or verified by anyone who isn't holding a PHP REPL.

## Proposed Solutions

**Add `cli/class-orbit-cli-consent.php`** with subcommands:

```bash
# History for a user, optionally filtered by channel.
wp orbit consent log --user_id=<id> [--channel=email|sms] [--format=json|table|csv]

# Chain integrity check. Exit code 0 = intact, 1 = tampered.
wp orbit consent verify --user_id=<id> --channel=<email|sms>

# Show latest state (one row).
wp orbit consent state --user_id=<id> --channel=<email|sms>
```

Wrap `Orbit_Consent::latest_state()` and `Orbit_Consent::verify_chain()`. Register in `orbit.php` alongside the other CLI commands.

The `verify` exit code makes it composable into CI / ops scripts (`wp orbit consent verify ... || alert`).

## Acceptance Criteria

- [ ] `cli/class-orbit-cli-consent.php` exists with the three subcommands.
- [ ] Registered via `WP_CLI::add_command('orbit consent', 'Orbit_CLI_Consent')` in `orbit.php`.
- [ ] `verify` exits 0 on intact chain, 1 on tampered, with the first-broken-row ID printed.
- [ ] `log` supports `--format=json|table|csv`.
- [ ] Help text via standard PHPDoc command annotations.

## Work Log

- 2026-06-01: Identified during code review of PR #24 by agent-native-reviewer.

## Resources

- PR #24: https://github.com/makyrie/orbit/pull/24
- `includes/class-orbit-consent.php`
- Existing CLI patterns: `cli/class-orbit-cli-notification.php`, `cli/class-orbit-cli-subscription.php`
