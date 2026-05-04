---
status: pending
priority: p2
issue_id: "030"
tags: [code-review, agent-native, rest, cli]
dependencies: []
---

# No primitive for an agent to read phone verification state

## Problem Statement

The new UI in PR #5 derives 4 render states (initial / pending / verified / unavailable) from a combination of `orbit_phone` user_meta, `orbit_phone_verified` user_meta, and three `defined()` checks. There's no Orbit-specific REST or CLI way to ask "what state is this user in?" — agents must read raw user_meta and re-implement the state machine.

`POST /orbit/v1/verify-phone` is the only verify-phone endpoint and it's POST-only. `wp orbit status` exposes `twilio_configured` but nothing per-user.

## Proposed Solution

Add either or both:

**REST**: `GET /orbit/v1/verify-phone` returning:
```json
{
  "phone": "+15551234567",
  "verified": true,
  "state": "verified",
  "twilio_configured": true,
  "pending_code_expires_at": null
}
```
`state` enum: `no_phone | pending | verified | unavailable`.

**CLI**: `wp orbit notification phone-status <user_id> --format=json` — same payload.

This also gives the UI's `render_phone_verification()` one place to consolidate the inline state branching.

## Acceptance Criteria

- [ ] One inspection primitive (REST or CLI, ideally both)
- [ ] Returns the same 4 states the UI uses
- [ ] UI refactored to consume the primitive (eliminates duplicated state logic)
