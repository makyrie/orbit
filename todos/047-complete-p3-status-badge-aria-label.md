---
status: complete
priority: p3
issue_id: "047"
tags: [code-review, accessibility, html]
dependencies: []
---

# Manage-table status badge needs `aria-label` for screen-reader clarity

## Problem Statement

In `manage()` (`includes/class-orbit-shortcodes.php:537`), the inline status badge is rendered as:

```html
<span class="orbit-status-badge orbit-status-cancelled">Cancelled</span>
```

CSS makes the badge ALL CAPS via `text-transform: uppercase`. Screen readers handle that fine, but the inline placement next to the activity title means a screen reader will announce: *"Birthday party Cancelled"* with no semantic separation. The user has to infer that "Cancelled" is metadata about the activity rather than part of its title.

## Proposed Solution

Add an `aria-label` that frames the badge as status metadata:

```php
$title_html .= sprintf(
    ' <span class="orbit-status-badge orbit-status-%s" aria-label="%s">%s</span>',
    esc_attr( $activity->status ),
    esc_attr( sprintf(
        /* translators: %s: status label e.g. Cancelled, Past */
        __( 'Status: %s', 'orbit' ),
        $status_label
    ) ),
    esc_html( $status_label )
);
```

## Acceptance Criteria

- [ ] Cancelled and Past badges have descriptive `aria-label` attributes
- [ ] Screen reader announces something like "Birthday party. Status: Cancelled."
- [ ] Visual rendering unchanged
