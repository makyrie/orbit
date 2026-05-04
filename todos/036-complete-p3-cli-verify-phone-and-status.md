---
status: complete
priority: p3
issue_id: "036"
tags: [code-review, agent-native, cli]
dependencies: ["030"]
---

# Add CLI `wp orbit notification verify-phone` and document overwrite-on-resend

## Problem Statement

PR #5 adds a UI and reuses the existing REST endpoint, but there's no CLI parallel. Agent flows on this project default to WP-CLI (per the global Local-by-Flywheel pattern). Today the only CLI path to register a phone is `wp user meta update <id> orbit_phone +1...` which bypasses E.164 validation, rate limiting, and the SMS round trip.

Also: `Orbit_Phone_Verify::send_code()` overwrites `orbit_phone` and resets `orbit_phone_verified` when called with a new number — the "change phone number" path is implicit, not documented.

## Proposed Solution

Add to `cli/class-orbit-cli-notification.php`:

```
wp orbit notification verify-phone <user_id> --phone=+15551234567
wp orbit notification verify-phone <user_id> --code=123456
```

Both delegate to existing `Orbit_Phone_Verify::send_code()` / `verify_code()` — no business logic in CLI.

Add docblock notes to `Orbit_Phone_Verify::send_code()` and `Orbit_REST_Notification::handle_verify_phone()` documenting the overwrite-on-resend semantics.

## Acceptance Criteria

- [ ] `wp orbit notification verify-phone` accepts both `--phone` and `--code`
- [ ] Behavior matches REST endpoint exactly (same error codes)
- [ ] Docblock clarifies overwrite-on-resend
