---
status: complete
priority: p1
issue_id: "089"
tags: [code-review, tests, rfc-8058, unsubscribe, PR-24]
dependencies: []
---

# No tests for handle_one_click_unsubscribe / handle_unsubscribe_post / perform_unsubscribe

## Problem Statement

PR #24 ships the entire RFC 8058 one-click unsubscribe flow — `handle_one_click_unsubscribe`, `is_one_click_unsubscribe_post`, the `List-Unsubscribe=One-Click` POST body detection, the `List-Unsubscribe-Post` header fallback, the legacy raw-secret fallback in `resolve_unsubscribe_subscription`, the rate-limit guard, the consent ledger write, and the idempotent-replay short-circuit in `perform_unsubscribe`.

There is no `OrbitRoutes*Test.php` and no test in any existing file exercises these methods. The 97-test suite reports green, but a regression here breaks every Gmail/Apple Mail "Unsubscribe" button — a CAN-SPAM and Gmail bulk-sender compliance surface.

The HMAC unsubscribe token has tests in `OrbitTokenUnsubscribeTest`. The route that consumes those tokens has none.

## Findings

- `includes/class-orbit-routes.php:294-541` — entirely untested.
- `tests/` — no `OrbitRoutesUnsubscribeTest.php` or equivalent.

## Proposed Solutions

**Add `tests/OrbitRoutesUnsubscribeTest.php` covering:**

| Scenario | Expected |
|---|---|
| POST with `List-Unsubscribe=One-Click` body and valid HMAC token | 200, body 'unsubscribed', subscription unsubscribed, consent row appended |
| POST one-click with invalid HMAC token | 400, body 'invalid_token' |
| POST one-click with no token | 400, body 'invalid_token' |
| POST one-click 31 times from same IP within a minute | 31st returns 429 'rate_limited' |
| POST one-click when subscription already unsubscribed | 200, no duplicate ledger row (per todo 086, check subscription status) |
| GET with valid HMAC token | renders confirmation form with nonce |
| POST two-step form with valid nonce + token | 200, subscription unsubscribed, consent row appended |
| POST two-step form with invalid nonce | renders "Security check failed" |
| Legacy raw subscription_secret as token resolves correctly | subscription unsubscribed |
| HMAC token with wrong subscription_secret | resolver returns null |
| Expired HMAC token (>1 year) | resolver returns null |

The test class should extend `WP_UnitTestCase`. Use `$_SERVER['REQUEST_METHOD']` injection or refactor to call the handlers directly with crafted request state. Mock `Orbit_Client_IP::get()` (or set `$_SERVER['REMOTE_ADDR']`) to control rate-limit scoping.

## Recommended Action

(Filled during triage.)

## Technical Details

- Affected file: `tests/OrbitRoutesUnsubscribeTest.php` (NEW)
- The handlers `exit` after writing the response — tests need to either mock `exit` (via Process Isolation) or extract the response-writing into a callable that returns instead of exits.

## Acceptance Criteria

- [ ] New test file with all 11 scenarios above.
- [ ] All tests pass.
- [ ] Coverage includes the legacy raw-secret fallback path (no current test).

## Work Log

- 2026-06-01: Identified during code review of PR #24 by wp-test-reviewer.

## Resources

- PR #24: https://github.com/makyrie/orbit/pull/24
- `includes/class-orbit-routes.php:294-541`
