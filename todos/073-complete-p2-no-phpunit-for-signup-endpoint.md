---
status: complete
priority: p2
issue_id: "073"
tags: [code-review, tests, rest-api, PR-23]
dependencies: []
---

# No PHPUnit coverage for the new /signup REST endpoint

## Problem Statement

PR #23 adds `Orbit_REST_Signup` — a public, anonymous endpoint that creates WordPress user accounts and auto-logs them in. It has zero PHPUnit coverage. The project's test suite stays at 53/53 because the new code isn't exercised.

For a security-sensitive endpoint (anonymous user creation + auto-login), regression coverage is especially valuable: a future tweak to the honeypot, role assignment, or rate-limiter could silently break the signup path or open an enumeration / abuse vector.

## Findings

- `includes/class-orbit-rest-signup.php` — new file, no test sibling.
- `tests/` — has phpunit.xml.dist + a tests/ directory with existing test files; the harness exists.
- Manual end-to-end was done twice in the browser. That's not the same as automated regression coverage.

## Proposed Solutions

**Option A — Add `tests/test-orbit-rest-signup.php` covering:**

| Scenario | Expected |
|---|---|
| Happy path: valid name + email, honeypot clean, timestamp ≥ 1.5s old | 201, user created, role = subscriber, auth cookie set, redirect_url = /edit-profile/ |
| Honeypot filled | 400 with `orbit_spam_detected` |
| Form submitted too fast (init_ms ≈ now) | 400 with `orbit_spam_detected` |
| Form stale (init_ms > 24h ago) | 400 with `orbit_form_expired` |
| Rate limit exceeded (6th attempt from same IP) | 429 with `rate_limited` |
| Email already exists | 409 with `login_required` + login_url in error_data |
| Invalid email format | 400 with `invalid_email` |
| Empty display_name | 400 with `invalid_name` |
| Display name with non-Latin chars that sanitize to empty | username falls back to `orbit-user{rand}` |
| Already logged in | 200 with `already_signed_in` + redirect_url |

**Option B — Tests deferred but tracked.**

If the team's velocity bar is to ship now and write tests in a separate follow-up, accept that and create a tracking issue. Don't conflate "ship without tests" with "tests don't matter."

Recommend **Option A**, in this PR or a fast-follow.

## Recommended Action

(Filled during triage.)

## Technical Details

- Test class would extend `WP_UnitTestCase` and use `$this->factory->user` for fixtures.
- For rate-limit tests, the existing `Orbit_Rate_Limiter` uses transients — tests need to clear those between cases.
- Auth-cookie verification in PHPUnit: WP test harness fakes cookies, so assert on `is_user_logged_in()` after the handler call.

## Acceptance Criteria

- [ ] New test file `tests/test-orbit-rest-signup.php` exists.
- [ ] All scenarios in the table above are covered.
- [ ] Full suite still green (currently 53/53 → 63+/63+ depending on coverage scope).

## Work Log

- 2026-05-14: Identified during code review of PR #23.

## Resources

- PR #23: https://github.com/makyrie/orbit/pull/23
- `includes/class-orbit-rest-signup.php`
- Existing test for subscribe endpoint (if any) as a reference shape: `tests/test-orbit-rest-subscription.php`
