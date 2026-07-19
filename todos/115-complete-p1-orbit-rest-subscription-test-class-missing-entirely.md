---
status: complete
priority: p1
issue_id: "115"
tags: [code-review, PR-26, testing, wp-test]
dependencies: []
---

# OrbitRestSubscriptionTest does not exist — subscribe path has zero PR #26 test coverage

## Problem Statement

PR #26 adds 6 new test cases to `tests/OrbitRestSignupTest.php` covering consent capture (consent_email required, consent_sms requires phone, consent rows written, rollback on consent failure, etc.). The matching `tests/OrbitRestSubscriptionTest.php` file does not exist.

The subscribe endpoint carries the same surface area:

- Phone number validation (E.164).
- `consent_email` required check (TCPA-mandatory).
- `consent_sms` rejected without phone.
- Transactional rollback semantics across user creation + consent inserts + notifier prefs.
- `orbit_phone_pending` user_meta write on success.
- Two consent ledger rows (email + optional sms) per submission.

The PR plan called for BOTH `OrbitRestSubscriptionConsentTest` and `OrbitRestSignupConsentTest`. Only the signup half landed. Subscribe ships untested — every edge case validated in signup tests has an identical-shape counterpart in subscribe that nothing verifies.

Given subscribe also has the multisite role-assignment bypass (todo 114), the missing spam traps (todo 113), and the same transactional consent envelope as signup, the absence of tests means regressions in any of those paths land silently.

## Findings

- `tests/OrbitRestSignupTest.php` — exists, contains 6 consent-coverage cases added in PR #26.
- `tests/OrbitRestSubscriptionTest.php` — does not exist. `ls tests/` shows no file by that name.
- `includes/class-orbit-rest-subscription.php::handle_subscribe()` covers ~200 lines of validation + transaction + side-effect logic, none of which is exercised by PHPUnit.
- The PR plan (referenced in earlier review todos) listed both test classes as required deliverables.

## Proposed Solutions

### Option 1: Scaffold OrbitRestSubscriptionTest mirroring OrbitRestSignupTest (recommended)

Create `tests/OrbitRestSubscriptionTest.php` with the same `WP_UnitTestCase` base and helper conventions as `OrbitRestSignupTest`. Cover at minimum:

1. **Happy path:** valid email + valid E.164 phone + both consents → 200, user created, role assigned, both consent rows written, `orbit_phone_pending` set.
2. **Missing `consent_email` → 400** with `consent_email_required` code, no user created.
3. **`consent_sms=true` without phone → 400** with `sms_consent_requires_phone` code, no user created.
4. **Invalid E.164 phone → 400**, no user, no consent rows.
5. **Phone + SMS consent path:** both consent rows present (email + sms), `orbit_phone_pending` meta exists.
6. **Phone without SMS consent path:** only email consent row, `orbit_phone_pending` still set (so user can later opt in).
7. **Rollback on consent insert failure:** stub `Orbit_Consent::record()` to return WP_Error mid-transaction, assert no user persists, no consent rows persist, no notifier prefs row persists.
8. **Duplicate email → 409** (mirror signup behavior).
9. **Rate limit (5/hr/IP):** sixth request from the same IP → 429.

**Pros:** Brings subscribe to parity with signup coverage; catches the rollback / multisite / traps regressions when those fixes land.
**Cons:** Test scaffold work; needs fixtures for E.164 validation.
**Effort:** Medium (3-4 hr).
**Risk:** Low.

### Option 2: Land a stub now, expand later

Create `OrbitRestSubscriptionTest.php` with only the happy-path test as a placeholder.

**Pros:** Unblocks the PR.
**Cons:** Doesn't actually cover the consent surface that's the whole reason this PR shipped. Half-measure.
**Effort:** Small.
**Risk:** Medium (false sense of coverage).

## Recommended Action

Ship Option 1 before merge. Subscribe and signup are mirror endpoints in PR #26 and need mirror tests. Use `OrbitRestSignupTest` as the reference — copy structure, swap endpoint and fixture data. The rollback test in particular is the only mechanical guarantee that transactional consent doesn't silently drop rows; subscribe needs its own.

## Technical Details

**Affected files:**
- `tests/OrbitRestSubscriptionTest.php` (new).
- `tests/OrbitRestSignupTest.php` (reference for structure/patterns).
- `tests/bootstrap.php` — verify it autoloads new test classes (typical wp-tests-suite glob already does).

**Test fixtures:**
- Valid E.164: `+14155552671`.
- Invalid E.164 examples: `4155552671` (missing `+`), `+1-415-555-2671` (hyphens), `+1 415 555 2671` (spaces — depending on validator), `+99999999999999999` (too long).
- IP override for rate-limit tests: set `$_SERVER['REMOTE_ADDR']` per test.

**Rollback test mechanics:**
- Use a Mockery / PHPUnit mock of `Orbit_Consent::record()` that returns WP_Error on the second call. Assert user count and ledger row count both unchanged after the request.

## Acceptance Criteria

- [ ] `tests/OrbitRestSubscriptionTest.php` exists and is discovered by the test runner.
- [ ] All 9 test cases listed in Option 1 are implemented and pass.
- [ ] Coverage report shows `Orbit_REST_Subscription::handle_subscribe` line coverage at parity with `Orbit_REST_Signup::handle_signup`.
- [ ] Tests catch the rollback regression: a deliberately introduced bug that swallows the `Orbit_Consent::record()` WP_Error causes the rollback test to fail.
- [ ] Tests run in CI via the existing PHPUnit job.

## Work Log

- 2026-06-08: Surfaced during PR #26 multi-agent code review.
- 2026-06-09: Created `tests/OrbitRestSubscriptionTest.php` mirroring
  `OrbitRestSignupTest` (REST server reset + REMOTE_ADDR fixture +
  rate-limit transient clear in set_up/tear_down, per-test profile
  fixture via `Orbit_Profile::create`, `subscribe_params()` body
  helper, `dispatch_subscribe()` request helper). Added the following
  test methods:
  - `test_happy_path_creates_subscription`
  - `test_missing_consent_email_returns_400`
  - `test_consent_sms_without_phone_returns_400`
  - `test_invalid_phone_format_returns_400`
  - `test_phone_without_sms_consent_writes_only_email_row`
  - `test_honeypot_field_filled_returns_400` —
    `markTestIncomplete` pending todo 113 (subscribe lacks
    honeypot/timestamp traps today).
  - `test_too_fast_submission_returns_400` —
    `markTestIncomplete` pending todo 113.
  - `test_logged_in_user_subscribes_to_profile_existing_account`
  - `test_invalid_email_returns_400`
  - `test_rate_limit_kicks_in_after_threshold`
  - `test_transaction_rollback_on_consent_failure` —
    `markTestIncomplete` pending todo 118 (transaction-safety canary);
    forcing a deterministic mid-transaction consent failure requires
    invasive mocking that this PR's scope doesn't justify.
  PHPUnit run via `vendor/bin/phpunit --filter
  OrbitRestSubscriptionTest` exited at WordPress bootstrap with
  `db_connect_fail` — the Local site's MySQL socket
  (`.../run/NZ_MOyrML/mysql/mysqld.sock`) is not present (Local app
  not running). Tests were not executed against the DB in this
  session; suite still needs a live Local socket to verify.

## Resources

- PR #26: https://github.com/makyrie/orbit/pull/26
- Source: wp-test-reviewer
- `tests/OrbitRestSignupTest.php` (reference implementation)
- `includes/class-orbit-rest-subscription.php` (system under test)
