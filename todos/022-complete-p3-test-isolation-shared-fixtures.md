---
status: pending
priority: p3
issue_id: "022"
tags: [code-review, testing]
dependencies: []
---

# Test Isolation: Shared Fixtures Without Cleanup

## Problem Statement

OrbitSubscriptionTest and OrbitResponseTest share user/profile/subscription fixtures across test methods via `wpSetUpBeforeClass`. Custom table rows persist between tests because `WP_UnitTestCase` only rolls back core WP tables. Tests depend on execution order.

## Proposed Solutions

Add `tear_down()` to each test class that truncates the relevant custom tables. Also move `Orbit_Activator::create_tables()` to `tests/bootstrap.php` so it runs once.

## Acceptance Criteria

- [ ] Each test class has `tear_down()` that cleans custom table state
- [ ] `create_tables()` called once in bootstrap, not per-class
- [ ] Tests pass in any order
