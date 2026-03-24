---
status: pending
priority: p3
issue_id: "021"
tags: [code-review, quality, testing]
dependencies: []
---

# No Test Infrastructure

## Problem Statement

The plugin has no tests directory, no `phpunit.xml.dist`, and no test files. For a plugin with 6,400 lines of code handling user data, financial-like operations (subscriptions), and external integrations (Twilio), test coverage is essential.

## Proposed Solutions

Scaffold test infrastructure using `wp-testing` skill. Priority test targets:
1. `Orbit_Token` — action token generation and validation
2. `Orbit_Subscription` — lifecycle state transitions
3. `Orbit_Response` — upsert idempotency
4. `Orbit_Notifier` — tier routing and SMS cap logic

## Acceptance Criteria

- [ ] `phpunit.xml.dist` exists
- [ ] At least 1 test per core data class
