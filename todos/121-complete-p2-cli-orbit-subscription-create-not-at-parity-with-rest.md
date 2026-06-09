---
status: complete
priority: p2
issue_id: "121"
tags: [code-review, PR-26, agent-native, cli, compliance]
dependencies: []
---

# wp orbit subscription create is no longer at parity with the REST endpoint

## Problem Statement

`wp orbit subscription create` accepts only `--user_id --profile_id --connection_note`. The REST endpoint `/orbit/v1/subscribe` now also takes `phone`, `consent_email`, and `consent_sms`, and stamps the consent ledger for each opt-in. CLI-created subscriptions therefore have NO consent rows.

Compliance-relevant divergence: an ops-created subscription cannot be defended in a TCPA / TCR audit because there is no ledger evidence of opt-in. Anything provisioned via CLI is effectively a record without paperwork.

## Findings

- `cli/class-orbit-cli-subscription.php:124-160` — `create` subcommand accepts only the three legacy flags; no consent path.
- `includes/class-orbit-rest-subscription.php` — REST handler stamps `Orbit_Consent::record()` for both `email` and `sms` channels (gated on `consent_sms` requiring `phone`).
- Surfaced by agent-native-reviewer (finding #2) during multi-agent review.

## Proposed Solutions

**Option A — Add the new flags + reuse REST validation (recommended).** Add `--phone`, `--consent_email`, `--consent_sms` to the CLI subcommand. Reject `--consent_sms` without `--phone` with the same WP_Error code the REST handler uses. Route through the same consent-stamping function so the ledger row is byte-identical to a REST-created one.

Effort: low — once todo 130 (`Orbit_User_Provisioning` service) lands, this is a thin CLI wrapper around it.

**Option B — Extract a shared service first, then CLI.** Same outcome but blocked on todo 130. Cleaner long-term but doesn't ship the compliance fix until the refactor lands.

## Recommended Action

Option A *now* — add the flags and wire them through the existing `Orbit_Consent` primitives directly, even if duplicates a few lines from the REST handler. Migrate both call sites to `Orbit_User_Provisioning` when todo 130 lands.

## Technical Details

- The CLI `cta_snapshot` should reflect the same disclosure text the REST endpoint stores — call `Orbit_Shortcodes::compliance_disclosure_text()` (or its post-extraction equivalent from todo 131) directly.
- The CLI `policy_url` should resolve to the canonical privacy page (see todo 117).
- The CLI `ip_address` and `user_agent` should be recorded as `cli` / `wp orbit subscription create` rather than left empty, so the ledger row carries provenance.
- Add validation: reject `--consent_sms` without `--phone`, surface a `WP_CLI::error()` with the same code REST returns.

## Acceptance Criteria

- [ ] `wp orbit subscription create` accepts `--phone`, `--consent_email`, `--consent_sms`.
- [ ] `--consent_sms` without `--phone` errors out with the same code REST returns.
- [ ] Consent ledger rows written by CLI are structurally identical to REST rows (same channel codes, same cta_snapshot, same policy_url).
- [ ] CLI-recorded provenance distinguishes ops-created rows (`ip_address = 'cli'` or similar).
- [ ] PHPUnit / wp-cli test asserts CLI-created subscriptions produce the expected ledger rows.

## Work Log

- 2026-06-08: Surfaced during PR #26 multi-agent code review.
- 2026-06-09: Added `--phone`, `--consent_email`, `--consent_sms` to `wp orbit
  subscription create` (`cli/class-orbit-cli-subscription.php`). E.164
  validation + `consent_sms_without_phone` error mirror the REST handler
  byte-for-byte. CLI-stamped ledger rows carry `source=cli` and
  `user_agent=wp-cli` so ops-initiated provisioning is distinguishable from
  end-user opt-in. Writes wrapped in `START TRANSACTION`/`COMMIT`/`ROLLBACK`
  with notifier-preferences cache eviction on failure, mirroring REST shape.
  Existing positional args + happy-path response untouched (purely additive).
  Added `tests/OrbitCliSubscriptionTest.php` (8 tests, 24 assertions) that
  stubs `WP_CLI` + `WP_CLI_Command` and invokes `Orbit_CLI_Subscription::
  create()` directly with constructed assoc_args; asserts pending-phone
  meta, both consent rows, source/user_agent provenance, and the two
  validation error paths. All 8 pass. Refactor to `Orbit_User_Provisioning`
  (todo 130) is deferred per recommendation.

## Resources

- PR #26: https://github.com/makyrie/orbit/pull/26
- `cli/class-orbit-cli-subscription.php:124-160`
- `includes/class-orbit-rest-subscription.php`
- Related: todo 130 (provisioning service extraction)
