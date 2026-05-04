---
status: complete
priority: p2
issue_id: "033"
tags: [code-review, php, i18n, security]
dependencies: []
---

# `printf` with HTML substitution is correct today but fragile pattern

## Problem Statement

`includes/class-orbit-shortcodes.php:322-326`:

```php
printf(
    /* translators: %s: phone number the code was sent to */
    esc_html__( 'A 6-digit code was sent to %s. Enter it below to verify.', 'orbit' ),
    '<strong class="orbit-code-target">' . esc_html( $phone ) . '</strong>'
);
```

Currently safe — the format string is escaped (no HTML in the translatable string), the substituted argument has its dynamic part (`$phone`) escaped via `esc_html()`, and the `<strong>` tag is intentional. But:

- A future maintainer adding HTML to the translatable string itself will get confused escaping behavior.
- Translators can't reposition or omit the `<strong>` tag.
- The pattern relies on the reader knowing that `printf` does not re-escape its arguments.

## Proposed Solution

Use the WP idiom that lets translators include `<strong>` in their own translations safely:

```php
printf(
    /* translators: %s: phone number the code was sent to */
    wp_kses(
        __( 'A 6-digit code was sent to %s. Enter it below to verify.', 'orbit' ),
        array( 'strong' => array( 'class' => array() ) )
    ),
    '<strong class="orbit-code-target">' . esc_html( $phone ) . '</strong>'
);
```

Or move the bold styling to CSS so the translation has no HTML at all.

## Acceptance Criteria

- [ ] Pattern updated to use `wp_kses(__(...))` OR HTML moved to CSS
- [ ] Rendering visually unchanged
- [ ] Works with the `.orbit-code-target` JS hook (still queryable)
