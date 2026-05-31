---
status: complete
priority: p3
issue_id: "077"
tags: [code-review, ux, javascript, polish, PR-23]
dependencies: []
---

# Success message flashes for a microsecond before the redirect

## Problem Statement

In `assets/js/orbit-forms.js`, the success handler always calls `showMessage` before checking whether to redirect:

```js
var successMessage = ( result && result.message ) || orbitForms.strings.success;
showMessage( form, successMessage, 'success' );

// Redirect on certain actions.
if ( endpoint === 'activities' && method === 'POST' ) {
    window.location.href = ...;
} else if ( endpoint === 'signup' ) {
    if ( result && result.redirect_url ) {
        window.location.href = result.redirect_url;
    } else {
        window.location.reload();
    }
}
```

For endpoints that immediately redirect (signup, activities, subscribe), the success message is rendered into the DOM and then the page navigates away ~microseconds later. The user sees a flicker but never gets to read the message. The message that would have been useful — "Account created. Check your email for a link to set your password — but you can keep going now." — disappears before they can read it.

## Findings

- `assets/js/orbit-forms.js:216-244` — showMessage runs unconditionally; redirects fire immediately after.
- Especially wasteful for signup: the message contains an important note about the password-set email.

## Proposed Solutions

**Option A — Skip showMessage when we're about to redirect:**

```js
var willRedirect = ( endpoint === 'activities' && method === 'POST' )
    || ( endpoint === 'signup' )
    || ( endpoint === 'subscribe' || endpoint === 'profiles/me' );

if ( ! willRedirect ) {
    var successMessage = ( result && result.message ) || orbitForms.strings.success;
    showMessage( form, successMessage, 'success' );
}

// ... redirects unchanged
```

**Option B — Surface the post-redirect message via the destination page:**

For the signup → /edit-profile/ path, append a one-time flash message (sessionStorage or a URL query param) so /edit-profile/ shows "Account created. Check your email..." after the redirect. More work, much better UX.

**Option C — Leave as-is.**

Smallest change but worst UX — the message is just lost. The "check your email for a password link" guidance is genuinely useful, so accepting this loss has a real cost.

Recommend **Option B** longer-term; **Option A** as a quick cleanup if Option B is out of scope.

## Recommended Action

(Filled during triage.)

## Technical Details

- Affected file: `assets/js/orbit-forms.js`
- For Option B: an additional small render in `[orbit_edit_profile]` shortcode to display the flash on first load.

## Acceptance Criteria

- [ ] No flicker on form-submit → redirect paths.
- [ ] If Option B is chosen: the signup success guidance ("check your email") is visible on /edit-profile/ after redirect.

## Work Log

- 2026-05-14: Identified during code review of PR #23.

## Resources

- PR #23: https://github.com/makyrie/orbit/pull/23
- `assets/js/orbit-forms.js:216-244`
