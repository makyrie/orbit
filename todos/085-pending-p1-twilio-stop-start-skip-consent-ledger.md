---
status: pending
priority: p1
issue_id: "085"
tags: [code-review, tcpa, consent-ledger, sms, PR-24]
dependencies: []
---

# Twilio STOP/START handlers don't write consent ledger rows — TCPA SMS audit trail is empty

## Problem Statement

The email one-click unsubscribe path (`Orbit_Routes::perform_unsubscribe`) correctly writes a consent ledger row: `Orbit_Consent::record( $user_id, 'email', 'opt_out', ... )`. The matching SMS STOP/START handlers in `Orbit_Twilio::handle_incoming()` do NOT write equivalent rows for the `sms` channel.

The data model expects ledger entries for both channels — `Orbit_Consent::CHANNELS` is `array('email','sms')`. The hash chain for `(user_id, 'sms')` will be perpetually empty for every user who has ever STOP-opted-out, even though their `orbit_sms_opted_out` user_meta is set.

TCPA defense consequence: when Twilio (or a court) asks "show me when this user opted out of SMS," the audit response is "we don't have a record." The user_meta value is mutable and ephemeral; the ledger is the immutable evidence.

This is the single biggest gap in the Phase 1 consent-ledger integration. Found by both `pattern-recognition-specialist` and `call-chain-verifier`.

## Findings

- `includes/class-orbit-twilio.php:164-172` — STOP handler updates user_meta, does NOT call `Orbit_Consent::record()`.
- `includes/class-orbit-twilio.php:175-180` — START handler clears user_meta, does NOT call `Orbit_Consent::record()`.
- `includes/class-orbit-routes.php:531-538` — email path correctly writes ledger row.

## Proposed Solutions

**Option A — Add ledger writes (recommended):**

In `handle_incoming()`:
- After `update_user_meta( $user_id, 'orbit_sms_opted_out', 1 )` on STOP, call:
  ```php
  Orbit_Consent::record(
      $user_id,
      'sms',
      'opt_out',
      array( 'source' => 'sms_stop', 'cta_snapshot' => 'inbound SMS keyword: STOP' )
  );
  ```
- After `delete_user_meta( ..., 'orbit_sms_opted_out' )` on START, call:
  ```php
  Orbit_Consent::record(
      $user_id,
      'sms',
      're_opt_in',
      array( 'source' => 'sms_start', 'cta_snapshot' => 'inbound SMS keyword: START' )
  );
  ```

Discard the WP_Error return value for now (the consent write is best-effort; the operational STOP/START handling already succeeded). Or — better — log it via `error_log()` so failures are observable.

**Option B — Wire via the new `orbit_notification_sent` action's listener pattern** in v1.1.

Recommend **Option A** for v1.6.0 — the gap is in this PR's charter; deferring would mean PR #24 ships TCPA-incomplete.

## Recommended Action

(Filled during triage.)

## Technical Details

- Affected file: `includes/class-orbit-twilio.php`
- The `$user_id` is already in scope in both branches.
- The HELP path does NOT need a ledger write (HELP is informational, not consent-changing).
- IP/UA capture: inbound webhook POST is from Twilio's IPs, not the user — pass empty `ip` / `user_agent` overrides so the ledger doesn't capture Twilio's IPs.

## Acceptance Criteria

- [ ] STOP keyword writes `(user_id, 'sms', 'opt_out', source='sms_stop')` ledger row.
- [ ] START keyword writes `(user_id, 'sms', 're_opt_in', source='sms_start')` ledger row.
- [ ] `Orbit_Consent::verify_chain( $user_id, 'sms' )` returns non-empty for users who have STOP'd.
- [ ] Test added: simulate STOP webhook, assert ledger row exists with correct shape.
- [ ] Test added: simulate START webhook, assert ledger row exists with correct shape.

## Work Log

- 2026-06-01: Identified during code review of PR #24 by pattern-recognition-specialist and call-chain-verifier.

## Resources

- PR #24: https://github.com/makyrie/orbit/pull/24
- `includes/class-orbit-twilio.php:164-180`
- `includes/class-orbit-routes.php:516-541` (email opt_out pattern to mirror)
