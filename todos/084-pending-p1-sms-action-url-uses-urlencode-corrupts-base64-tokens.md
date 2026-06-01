---
status: pending
priority: p1
issue_id: "084"
tags: [code-review, bug, sms, tokens, PR-24]
dependencies: []
---

# SMS action URL uses urlencode() instead of rawurlencode() — corrupts base64 tokens containing '+'

## Problem Statement

`Orbit_Notifier::send_immediate_sms()` constructs the action URL with `urlencode( $token )` (line 276 in current file, called at SMS send). But the email path at `:323` and the digest path at `:486` use `rawurlencode( $token )`.

The token format from `Orbit_Token::generate_action_token()` is `{subscription_id}.{base64(expiry)}:{hmac_hex}`. Base64 can contain `+` characters. PHP's `urlencode()` encodes `+` as literal `+` (per HTML form encoding), while `rawurlencode()` encodes it as `%2B` (per RFC 3986).

When the SMS recipient taps the link, the browser submits the path to WordPress. The `+` decodes back to a space character on the receiving end (because that's what `urldecode()` does). The token shape becomes `{id}.{base64-with-spaces}:{hmac}`. `validate_action_token()` rebuilds the HMAC over the corrupted string → validation fails → the user gets "Invalid or expired link."

This silently breaks SMS-delivered RSVP links whenever the base64-encoded expiry happens to contain `+`. Probability is non-trivial: base64 contains `+` ~1/64 of the time per character on average.

## Findings

- `includes/class-orbit-notifier.php:276` — `urlencode( $token )` in `send_immediate_sms`.
- `includes/class-orbit-notifier.php:323` — `rawurlencode( $token )` in `send_immediate_email`.
- `includes/class-orbit-notifier.php:486` — `rawurlencode( $token )` in `send_digest`.

## Proposed Solutions

**Option A — Fix the encoding (recommended):**

Change line 276 to `rawurlencode( $token )`. One-character fix. Three callsites should be consistent — they all interpolate the same token shape into the same URL path/query.

**Option B — Switch tokens to URL-safe base64** (`base64url`): replaces `+` with `-` and `/` with `_`. More work (updates `generate_action_token`, `generate_unsubscribe_token`, `extract_subscription_id` parsing) and risks invalidating tokens already in flight.

Recommend **Option A**. Single-line fix; matches the other call sites.

## Recommended Action

(Filled during triage.)

## Technical Details

- Affected file: `includes/class-orbit-notifier.php`
- The matching unsubscribe code path uses `rawurlencode()` consistently — no other inconsistency.

## Acceptance Criteria

- [ ] `send_immediate_sms()` uses `rawurlencode()`.
- [ ] Regression test: generate an action token with `+` in the base64 portion, build the SMS URL, decode it, verify `validate_action_token` accepts it.

## Work Log

- 2026-06-01: Identified during code review of PR #24 by wp-php-reviewer.

## Resources

- PR #24: https://github.com/makyrie/orbit/pull/24
- `includes/class-orbit-notifier.php:276, 323, 486`
