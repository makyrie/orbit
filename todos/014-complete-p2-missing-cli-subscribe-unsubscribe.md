---
status: pending
priority: p2
issue_id: "014"
tags: [code-review, cli, agent-native]
dependencies: []
---

# Missing CLI Commands: subscribe, unsubscribe, verify-phone

## Problem Statement

Core user actions have no CLI equivalent, breaking agent-native parity:
- No `wp orbit subscription create` (subscribe on behalf of user)
- No `wp orbit subscription unsubscribe` (subscriber-initiated opt-out)
- No `wp orbit subscriber verify-phone` (admin phone verification)
- No `GET /activities/{id}` REST endpoint (single activity fetch)

## Findings

- **Agent-native reviewer:** 3 CLI commands and 1 REST endpoint missing from capability map.

## Proposed Solutions

Add the missing commands to `cli/class-orbit-cli-subscription.php` and `cli/class-orbit-cli-subscriber.php`. Add `GET` method to the `/activities/{id}` REST route.

## Acceptance Criteria

- [ ] `wp orbit subscription create --user_id=X --profile_id=Y` works
- [ ] `wp orbit subscription unsubscribe <id>` works
- [ ] `GET /activities/{id}` returns single activity
- [ ] All mutation commands output affected record as JSON
