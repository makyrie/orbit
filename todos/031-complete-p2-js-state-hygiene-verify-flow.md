---
status: complete
priority: p2
issue_id: "031"
tags: [code-review, javascript, ux, races]
dependencies: ["025", "026"]
---

# JS state hygiene in phone verify flow: input wipe, toggle reset, stale-response race

## Problem Statement

Three small but real footguns in `assets/js/orbit-forms.js`:

1. **Code input wipe on every step-1 success** (`orbit-forms.js:162`):
   ```js
   codeInput.value = '';
   ```
   Wipes any user-typed code unconditionally when phone form succeeds. Hurts the case where a user re-sends a phone (e.g., to retry) but already typed a code from the first SMS.

2. **Change-phone toggle doesn't reset submit-button state** (`orbit-forms.js:344-373`):
   The handler hides/shows forms but doesn't defensively re-enable submit buttons or clear stale code input. Combined with the stuck-button issue (#025), this can leave the revealed phone form with a disabled submit.

3. **Stale-response race** when phone form is submitted twice with different numbers (only reachable via #026 double-submit). Response B can land before response A, leading to UI showing "code sent to +X" while server has stored phone +Y.

## Proposed Solution

1. Remove the `codeInput.value = ''` line. Code validity is enforced server-side; a previously-typed code that's now invalid will fail with a clear error on next verify.
2. In the change-phone toggle, re-enable submit buttons on both forms and clear the code input:
   ```js
   [ phoneForm, codeForm ].forEach( function ( f ) {
       if ( ! f ) return;
       var btn = f.querySelector( '[type="submit"]' );
       if ( btn ) btn.disabled = false;
   } );
   if ( codeForm ) {
       var codeIn = codeForm.querySelector( 'input[name="code"]' );
       if ( codeIn ) codeIn.value = '';
   }
   ```
3. Stale-response race becomes unreachable once #026 (double-submit guard) lands.

## Acceptance Criteria

- [ ] Code input no longer wiped on step-1 success
- [ ] Change-phone toggle re-enables submit buttons defensively
- [ ] Code input is cleared when toggling back to phone entry
- [ ] After #025 + #026 land, stale-response race is unreachable
