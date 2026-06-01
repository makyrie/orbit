---
status: complete
priority: p3
issue_id: "078"
tags: [code-review, security, privacy, design-call, PR-23]
dependencies: []
---

# Document the email-enumeration tradeoff in the 409 response

## Problem Statement

`Orbit_REST_Signup::handle_signup()` returns a 409 with a clear message when the submitted email belongs to an existing account:

```php
return new WP_Error(
    'login_required',
    __( 'An account with this email already exists. Try logging in instead.', 'orbit' ),
    ...
);
```

This is a deliberate UX choice — telling the user "you already have an account, just log in" is more helpful than the alternative ("we sent you a magic link if there's an account…"). But the response is also a textbook email-enumeration oracle: anyone with a list of candidate emails can determine which are registered users on the site.

The code comment on the preceding line says:

```php
// Email collision → send them to login. Don't leak whether the
// email exists with an explicit "yes, that's a user" — but give
// a useful login_url so the JS can redirect there.
```

The comment claims we *don't* leak, but the message and the 409 status code together do. This is an internal inconsistency that future maintainers may interpret as a defect rather than a design call.

## Findings

- `includes/class-orbit-rest-signup.php:106-117` — comment says one thing, code does another.
- For Perihelion's product (invite-driven, low-stakes social-activity sharing), enumeration risk is low. This is almost certainly the right tradeoff for the audience.

## Proposed Solutions

**Option A — Update the comment to be honest about the tradeoff:**

```php
// Email collision → tell them clearly and offer a login link. This
// is a deliberate enumeration tradeoff: UX wins over the marginal
// privacy benefit of an ambiguous response, given the product is
// invite-driven and account existence is low-value information.
```

No behavior change.

**Option B — Switch to a generic 202 response that always says "if an account exists with this email, we've sent you a login link":**

Requires actually sending that email (currently we don't — we just block the signup). Higher engineering cost, lower UX. Probably not worth it for this product.

**Option C — Add a brief note to the PR description / README / docs/solutions/security-issues/ so the design call is on the record outside the code comment.**

Recommend **Option A + Option C** — fix the inconsistent comment, and add a short note to security docs explaining the tradeoff.

## Recommended Action

(Filled during triage.)

## Technical Details

- Affected file: `includes/class-orbit-rest-signup.php`
- Optional doc: `docs/solutions/security-issues/signup-email-enumeration-tradeoff.md`

## Acceptance Criteria

- [ ] Code comment matches actual behavior.
- [ ] (Optional) Design note recorded in `docs/solutions/security-issues/`.

## Work Log

- 2026-05-14: Identified during code review of PR #23.

## Resources

- PR #23: https://github.com/makyrie/orbit/pull/23
- `includes/class-orbit-rest-signup.php:106-117`
