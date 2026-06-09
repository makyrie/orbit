---
status: complete
priority: p2
issue_id: "125"
tags: [code-review, PR-26, ux, javascript]
dependencies: []
---

# Subscribe JS always reloads, ignoring server message and redirect_url

## Problem Statement

`orbit-forms.js` for the subscribe form always does `window.location.reload()` on success, even when the server returns a `message` and a `redirect_url`. The signup branch (lines 288-297) correctly honours `result.redirect_url`. The subscribe branch (lines 267-300) does not.

Concrete UX bug: an approved subscription reloads the same page that hosted the subscribe form. The reloaded page now sees the user as already-subscribed and renders "You are already subscribed" — which to a user who just successfully completed the form looks like a duplicate-detection error message. The actual success message from the server is lost.

## Findings

- `assets/js/orbit-forms.js:267-300` — subscribe branch is gated on `willRedirect` but resolves to `window.location.reload()`.
- `assets/js/orbit-forms.js:288-297` — signup branch correctly uses `result.redirect_url`.
- `includes/class-orbit-rest-subscription.php` — response does not currently include `redirect_url`.
- Surfaced by call-chain-verifier (finding #3) during multi-agent review.

## Proposed Solutions

**Option A — Mirror the signup pattern (recommended).**

1. Add `redirect_url` to the subscribe response: `home_url( '/dashboard/' )` for new accounts, the profile permalink for existing logged-in subscribers.
2. Update the subscribe JS branch to honour `redirect_url` (same shape as signup) and fall back to reload only when no `redirect_url` is provided.
3. Surface `message` in a transient success state before redirecting (use the same success-flash pattern signup uses).

Effort: low (~15 LOC across PHP + JS).

**Option B — Just redirect, no message.** Simpler but loses the chance to show the user a friendly confirmation before the page changes.

## Recommended Action

Option A. The signup branch already demonstrates the correct shape; subscribe should match.

## Technical Details

- `redirect_url` for a *new* subscriber: dashboard, since they have no profile yet.
- `redirect_url` for an *existing* logged-in user subscribing to an additional profile: that profile's permalink, since they're already authenticated.
- `redirect_url` should be sanitized with `esc_url_raw` server-side and `URL` parsed client-side to reject cross-origin destinations.
- The success message can flash for ~600ms before redirect using the same approach signup uses; see related todo 077 about success-message flash for the existing pattern.

## Acceptance Criteria

- [ ] Subscribe response includes a `redirect_url` field.
- [ ] JS branch honours `redirect_url`, falling back to reload only when absent.
- [ ] Server `message` is shown briefly before the redirect, matching signup behaviour.
- [ ] New-account subscribers land on `/dashboard/`; existing logged-in subscribers land on the profile permalink.
- [ ] Cross-origin `redirect_url` values are rejected client-side.
- [ ] Manual test: subscribe with a new email, observe redirect to dashboard with success message visible.

## Work Log

- 2026-06-08: Surfaced during PR #26 multi-agent code review.
- 2026-06-09: Server side: added a `redirect_url` field to the
  `/orbit/v1/subscribe` 201 response. New-account branch returns
  `home_url('/dashboard/')`; the existing-logged-in branch returns the
  profile permalink (`home_url('/@' . $profile->slug . '/')`, matching
  the rewrite in `Orbit_Routes::add_rewrite_rules()`). Value is
  sanitized with `esc_url_raw`. Client side: collapsed the signup and
  subscribe branches in `assets/js/orbit-forms.js` (around 280-310)
  into a single shared block that honours `result.redirect_url`, parses
  it with the `URL` constructor, and rejects cross-origin destinations
  before navigating. Falls back to `window.location.reload()` when no
  `redirect_url` is present (or fails to parse). PHPUnit coverage in
  `OrbitRestSubscriptionTest`: `test_new_account_response_includes_
  dashboard_redirect_url` and `test_existing_logged_in_response_redirects
  _to_profile_permalink`. (The 600ms success-message flash before
  redirect is tracked separately in todo 077.)

## Resources

- PR #26: https://github.com/makyrie/orbit/pull/26
- `includes/class-orbit-rest-subscription.php`
- `assets/js/orbit-forms.js:267-300`
- Related: todo 077 (success message flash before redirect)
