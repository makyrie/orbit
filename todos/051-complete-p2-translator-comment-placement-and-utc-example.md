---
status: complete
priority: p2
issue_id: "051"
tags: [code-review, i18n]
dependencies: []
---

# Translator Comment Placement on Subscribe Heading + UTC Example

## Problem Statement

Two issues in `includes/class-orbit-shortcodes.php`:

1. **Subscribe heading translator comment placement** — at `:1278`, the `sprintf( __( 'Subscribe to %s', 'orbit' ), ... )` heading has its translator comment placed two lines above the `sprintf` call, outside it. WPCS rule `WordPress.WP.I18n.MissingTranslatorsComment` requires the comment to immediately precede the `__()` call.
2. **Misleading UTC example in translator comment** — at `:285-288`, the translator comment uses example `"UTC+1"`, but `wp_timezone_string()` actually returns `+01:00`-style output. Translators following the example will produce incorrect strings.

## Proposed Solution

1. In `includes/class-orbit-shortcodes.php:1278`, move the translator comment inside `sprintf` so it directly precedes the `__()` call:
   ```php
   sprintf(
       /* translators: %s: profile display name */
       __( 'Subscribe to %s', 'orbit' ),
       esc_html( $display_name )
   )
   ```
2. In `includes/class-orbit-shortcodes.php:285-288`, update the translator-comment example from `"UTC+1"` to `"+01:00"` (or whatever `wp_timezone_string()` actually emits) so the example matches reality.

## Acceptance Criteria

- [ ] PHPCS with WPCS no longer flags `MissingTranslatorsComment` on `:1278`.
- [ ] Translator comment for the timezone string at `:285-288` shows a `+01:00`-style example.
- [ ] No translator-comment regressions elsewhere in the file (run PHPCS to confirm).
