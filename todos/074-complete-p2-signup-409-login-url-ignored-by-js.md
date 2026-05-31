---
status: complete
priority: p2
issue_id: "074"
tags: [code-review, ux, javascript, PR-23]
dependencies: []
---

# JS ignores `login_url` in the signup 409 response

## Problem Statement

When a sign-up email already exists, the REST endpoint returns:

```php
return new WP_Error(
    'login_required',
    __( 'An account with this email already exists. Try logging in instead.', 'orbit' ),
    array(
        'status'    => 409,
        'login_url' => wp_login_url( home_url( '/edit-profile/' ) ),
    )
);
```

The JS catch handler shows `err.message` via `showMessage(form, err.message, 'error')` and stops. The `login_url` in the error data is never surfaced. The user sees "An account with this email already exists. Try logging in instead." with no clickable login link in the message — they have to find the "Already have an account? Log in" link in the form footer.

## Findings

- `includes/class-orbit-rest-signup.php:108-117` — server includes `login_url` in error_data.
- `assets/js/orbit-forms.js:229-231` — `.catch( function ( err ) { showMessage( form, err.message, 'error' ); } )` — only consumes message, drops data.
- `assets/js/orbit-forms.js:57-64` — `apiRequest` throws `new Error( msg )` and discards the rest of the JSON body, so the `login_url` is structurally unreachable in the current catch path.

## Proposed Solutions

**Option A — Make apiRequest throw a richer error object:**

```js
if ( ! response.ok ) {
    var msg = ( body && body.message ) ? body.message : 'An error occurred.';
    var err = new Error( msg );
    err.data = ( body && body.data ) ? body.data : null;
    throw err;
}
```

Then in the catch handler:

```js
.catch( function ( err ) {
    if ( endpoint === 'signup' && err.data && err.data.login_url ) {
        // Surface as a clickable inline link rather than a flat message.
        showMessageWithLink( form, err.message, err.data.login_url, orbitForms.strings.logIn );
        return;
    }
    showMessage( form, err.message, 'error' );
} )
```

Helper renders the message with an `<a>` appended.

**Option B — Auto-redirect on 409 with login_url:**

If the email exists, send them directly to wp-login with `?redirect_to=/edit-profile/`. Saves a click but mildly surprising (they were filling out a signup form, not asking to log in).

**Option C — Leave as-is.**

The form-footer "Log in" link is still visible; the error message guides the user there. Acceptable, lower priority.

Recommend **Option A** — clearer affordance without taking control away from the user.

## Recommended Action

(Filled during triage.)

## Technical Details

- Affected files: `assets/js/orbit-forms.js`, possibly `orbit.php` if we add a `logIn` localized string.
- The change to `apiRequest` is a general improvement — other endpoints that return structured error data could benefit too.

## Acceptance Criteria

- [ ] When the signup form is submitted with an existing email, the response message includes (or is followed by) a clickable link to the login page.
- [ ] Other endpoints' error paths are unaffected.

## Work Log

- 2026-05-14: Identified during code review of PR #23.

## Resources

- PR #23: https://github.com/makyrie/orbit/pull/23
- `assets/js/orbit-forms.js:57-64, 229-231`
- `includes/class-orbit-rest-signup.php:108-117`
