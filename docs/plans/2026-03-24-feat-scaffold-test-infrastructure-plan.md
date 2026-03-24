---
title: "feat: Scaffold PHPUnit test infrastructure with core data layer tests"
type: feat
status: completed
date: 2026-03-24
---

# Scaffold Test Infrastructure

Scaffold PHPUnit with WordPress test framework (`wp-browser` or WP core test suite) and write unit tests for the 4 highest-priority classes: Token, Subscription, Response, and Notifier.

## Acceptance Criteria

- [x] `phpunit.xml.dist` exists and is configured
- [x] `composer.json` has `phpunit` and `wp-phpunit` as dev dependencies
- [x] `tests/bootstrap.php` loads WordPress test framework
- [x] `tests/test-orbit-token.php` — token generation, validation, expiry, subscription ID extraction
- [x] `tests/test-orbit-subscription.php` — subscribe, approve, deny, unsubscribe, re-subscribe lifecycle
- [x] `tests/test-orbit-response.php` — set (upsert), remove, activity validation, cancelled activity rejection
- [x] `tests/test-orbit-notifier.php` — tier routing, SMS cap logic, preferences cache
- [x] All tests pass with `vendor/bin/phpunit`

## Context

The plugin uses custom tables (not CPTs), so tests need the WordPress database. Use the `wp-testing` skill to scaffold the infrastructure, then write tests following WordPress test conventions (`WP_UnitTestCase`).

**Priority test targets (from review findings):**

| Class | What to test | Why |
|-------|-------------|-----|
| `Orbit_Token` | HMAC generation, validation, expiry, `extract_subscription_id()` | Security-critical — tokens gate unauthenticated access |
| `Orbit_Subscription` | Full lifecycle: pending→approved→unsubscribed, re-subscribe reactivation, self-subscription prevention | State machine with re-entry — most likely source of bugs |
| `Orbit_Response` | Upsert idempotency, cancelled activity rejection, profile mismatch rejection | Data integrity — UPSERT semantics need verification |
| `Orbit_Notifier` | `resolve_notification_method()`, `is_sms_cap_reached()`, preferences cache hit | Complex routing logic with SMS cap overflow to digest |

## Sources

- Todo: `todos/021-pending-p3-no-test-infrastructure.md`
- Existing classes: `includes/class-orbit-token.php`, `includes/class-orbit-subscription.php`, `includes/class-orbit-response.php`, `includes/class-orbit-notifier.php`
