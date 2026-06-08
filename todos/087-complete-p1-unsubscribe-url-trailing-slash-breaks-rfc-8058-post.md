---
status: complete
priority: p1
issue_id: "087"
tags: [code-review, rfc-8058, deliverability, PR-24]
dependencies: []
---

# Unsubscribe URL in email lacks trailing slash; canonical 301 redirect breaks RFC 8058 one-click POST

## Problem Statement

The email path generates the List-Unsubscribe URL as:

```php
$unsub_url = home_url( '/unsubscribe?token=' . rawurlencode( $unsub_token ) );
```

Note: no trailing slash before the `?`. The rewrite rule (`^unsubscribe/?$`) matches either form, but **the GET-rendered confirmation form** uses `home_url( '/unsubscribe/' )` (with trailing slash) at `class-orbit-routes.php:350`.

WordPress's `redirect_canonical` issues a 301 to add the trailing slash. RFC says 301 should preserve POST method, but in practice many one-click bots — including Gmail's bulk-sender enforcement bot — do NOT follow redirects on POST and treat the 301 as failure.

Effect: List-Unsubscribe-Post one-click may silently fail in production for Gmail-delivered mail. The user doesn't see an error; the unsubscribe just doesn't happen. Gmail's deliverability scoring penalizes the sender for "unsubscribe link not working."

## Findings

- `includes/class-orbit-notifier.php:323` — `send_immediate_email` URL.
- `includes/class-orbit-notifier.php:486` — `send_digest` URL.
- `includes/class-orbit-routes.php:350` — form action with trailing slash.
- `includes/class-orbit-routes.php:196-200` — rewrite rule.

## Proposed Solutions

**Option A — Add trailing slash to the email URL (recommended, 1-line fix):**

```php
$unsub_url = home_url( '/unsubscribe/?token=' . rawurlencode( $unsub_token ) );
```

Apply to both `send_immediate_email` and `send_digest`.

**Option B — Adjust the rewrite to NOT trigger `redirect_canonical` on the unsubscribe route** (more invasive; might affect other routes).

Recommend **Option A**.

## Recommended Action

(Filled during triage.)

## Technical Details

- Affected file: `includes/class-orbit-notifier.php`
- Worth verifying with `curl -X POST` against staging that no 301 fires for the slashed URL.

## Acceptance Criteria

- [ ] Both `send_immediate_email` and `send_digest` emit URLs with trailing slash.
- [ ] Manual test: POST to the URL with `List-Unsubscribe=One-Click` body — returns 200 directly (no 301).
- [ ] Regression test if feasible: assert URL shape in unit test.

## Work Log

- 2026-06-01: Identified during code review of PR #24 by call-chain-verifier.

## Resources

- PR #24: https://github.com/makyrie/orbit/pull/24
- `includes/class-orbit-notifier.php:323, 486`
- [RFC 8058 — One-Click List-Unsubscribe](https://datatracker.ietf.org/doc/html/rfc8058)
