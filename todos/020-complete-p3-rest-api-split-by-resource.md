---
status: pending
priority: p3
issue_id: "020"
tags: [code-review, architecture]
dependencies: []
---

# REST API Single 1,217-Line Class Should Split by Resource

## Problem Statement

`class-orbit-rest-api.php` handles 17+ endpoints across 4 resource domains (subscriptions, activities, profiles, notifications) plus system status and phone verification. This violates SRP and will degrade maintainability.

## Proposed Solutions

Split into resource-based controllers: `Orbit_REST_Subscription`, `Orbit_REST_Activity`, `Orbit_REST_Profile`, `Orbit_REST_Notification`.

## Acceptance Criteria

- [ ] Each controller file is < 300 lines
- [ ] All existing endpoints continue to work
