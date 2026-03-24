---
status: pending
priority: p3
issue_id: "023"
tags: [code-review, security, php]
dependencies: []
---

# Missing sanitize_callback on sms_daily_cap and date_flexible REST Args

## Problem Statement

`sms_daily_cap` in class-orbit-rest-subscription.php:121 and `date_flexible` in class-orbit-rest-activity.php:114,141 have no `sanitize_callback`. Mitigated downstream but missing at REST boundary per WPCS.

## Proposed Solutions

Add sanitize callbacks. `date_flexible` gets `'sanitize_callback' => 'rest_sanitize_boolean'`. `sms_daily_cap` needs a null-aware callback since null means "unlimited".

## Acceptance Criteria

- [ ] Both args have sanitize_callbacks
- [ ] `sms_daily_cap = null` still works (unlimited)
