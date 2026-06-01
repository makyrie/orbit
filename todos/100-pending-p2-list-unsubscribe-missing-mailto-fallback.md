---
status: pending
priority: p2
issue_id: "100"
tags: [code-review, rfc-8058, deliverability, PR-24]
dependencies: []
---

# List-Unsubscribe header missing mailto: fallback — Yahoo deliverability hit

## Problem Statement

`Orbit_Notifier::build_email_headers()` emits:

```
List-Unsubscribe: <https://perihelion.social/unsubscribe?token=...>
List-Unsubscribe-Post: List-Unsubscribe=One-Click
```

RFC 8058 §3 specifies the `List-Unsubscribe` header SHOULD contain BOTH an HTTPS URL AND a `mailto:` URI, so mail clients that don't trust the HTTPS one-click endpoint can fall back to email. Gmail's 2026 bulk-sender enforcement accepts either, but Yahoo's enforcement looks for the mailto.

Current shape is valid per RFC 8058 but suboptimal for Yahoo deliverability.

## Proposed Solutions

**Option A — Add mailto: fallback (recommended):**

```
List-Unsubscribe: <https://perihelion.social/unsubscribe/?token=TOKEN>, <mailto:unsubscribe+TOKEN@perihelion.social>
```

The mailto requires:
1. A mail handler that parses incoming `unsubscribe+*@perihelion.social` to extract the token.
2. The same `perform_unsubscribe()` codepath but triggered from the mail handler.

For v1.6.0, ship the mailto address but document that the mail-side handler is v1.1 work — Yahoo accepts the header even if the mailbox isn't yet handling. Most bulk senders use a no-op mailto initially.

**Option B — Defer to v1.1** (with the mail-handler implementation). Acceptable but loses Yahoo deliverability headroom in the dormant-period email volume.

Recommend **Option A** with documented partial implementation.

## Acceptance Criteria

- [ ] `List-Unsubscribe` header includes both the https URL and a mailto URI.
- [ ] Mailto address format documented (`unsubscribe+{token}@perihelion.social` or similar).
- [ ] README or DNS docs note that the mailbox/handler is v1.1 work.

## Work Log

- 2026-06-01: Identified during code review of PR #24 by call-chain-verifier.

## Resources

- PR #24: https://github.com/makyrie/orbit/pull/24
- `includes/class-orbit-notifier.php:831-840`
- [RFC 8058 §3](https://datatracker.ietf.org/doc/html/rfc8058#section-3)
