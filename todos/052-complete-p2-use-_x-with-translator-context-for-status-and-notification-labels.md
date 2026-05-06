---
status: complete
priority: p2
issue_id: "052"
tags: [code-review, i18n]
dependencies: []
---

# Use `_x()` with Translator Context for Status / Notification-Method Labels

## Problem Statement

Several short, ambiguous labels are wrapped with bare `__()`, leaving translators no context:

- `includes/class-orbit-shortcodes.php:281-284` — `__( 'SMS' )`, `__( 'Email' )`, `__( 'Digest' )`, `__( 'None' )` for notification methods. "None" in particular is highly ambiguous in translation (no notification, no value, no selection, etc.).
- `includes/class-orbit-shortcodes.php:500-503` — `__( 'Approved' )` / `__( 'Pending' )` for the user's own subscription status (you-are-approved-by-them).
- `includes/class-orbit-shortcodes.php:961-964` — same `__( 'Approved' )` / `__( 'Pending' )` strings used in the inverse context (they-are-approved-by-you, on the Subscribers screen).

Reusing the same bare `__()` for two distinct senses prevents translators from disambiguating, and many languages need different forms.

The tier labels at `class-orbit-activity.php:419-424` are worth considering but are typically unique enough that context is implied.

## Proposed Solution

1. `includes/class-orbit-shortcodes.php:281-284` — switch to `_x()` with a `'notification method'` context:
   ```php
   _x( 'SMS', 'notification method', 'orbit' )
   _x( 'Email', 'notification method', 'orbit' )
   _x( 'Digest', 'notification method', 'orbit' )
   _x( 'None', 'notification method', 'orbit' )
   ```
2. `includes/class-orbit-shortcodes.php:500-503` — use `_x( 'Approved', 'subscription status', 'orbit' )` and `_x( 'Pending', 'subscription status', 'orbit' )`.
3. `includes/class-orbit-shortcodes.php:961-964` — use `_x( 'Approved', 'subscriber status', 'orbit' )` and `_x( 'Pending', 'subscriber status', 'orbit' )`.
4. Optional: review tier labels in `class-orbit-activity.php:419-424` for ambiguity; leave as-is unless a specific tier is unclear.
5. Regenerate the `.pot` file so translators see the new contexts.

## Acceptance Criteria

- [ ] Notification-method labels use `_x()` with `'notification method'` context.
- [ ] Subscription-status labels at `:500-503` use `'subscription status'` context.
- [ ] Subscriber-status labels at `:961-964` use `'subscriber status'` context.
- [ ] `.pot` file regenerated and shows distinct entries per context.
- [ ] Existing translations (if any) are migrated or noted for re-translation.
