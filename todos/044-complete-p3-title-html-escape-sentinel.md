---
status: complete
priority: p3
issue_id: "044"
tags: [code-review, php, security, defensive]
dependencies: []
---

# `echo '<td>' . $title_html . '</td>'` builds escaped HTML by concatenation; future-fragile

## Problem Statement

In `manage()` (`includes/class-orbit-shortcodes.php:535-541`), the title cell is built as a string variable then echoed:

```php
$title_html = '<a href="' . esc_url( ... ) . '">' . esc_html( $activity->title ) . '</a>';
if ( '' !== $status_label ) {
    $title_html .= ' <span class="orbit-status-badge orbit-status-' . esc_attr( $activity->status ) . '">' . esc_html( $status_label ) . '</span>';
}
echo '<td>' . $title_html . '</td>';
```

Currently safe — every fragment is individually escaped before concatenation. The concern is forward-fragility: a future contributor adding `.= $activity->somefield` to `$title_html` won't see an obvious red flag, and WPCS `WordPress.Security.EscapeOutput` will flag the final echo once PHPCS is wired up.

Security-sentinel verified the current code is safe. wp-php-reviewer rated this P1; downgrading to P3 since it's a defensive-style concern, not an active bug.

## Proposed Solution

Echo each piece directly (matches the rest of the file's style):

```php
echo '<td><a href="' . esc_url( home_url( '/activity/' . $activity->id ) ) . '">' . esc_html( $activity->title ) . '</a>';
if ( '' !== $status_label ) {
    echo ' <span class="orbit-status-badge orbit-status-' . esc_attr( $activity->status ) . '">' . esc_html( $status_label ) . '</span>';
}
echo '</td>';
```

## Acceptance Criteria

- [ ] No string-buffered HTML in `manage()`; each escaped fragment echoed directly
- [ ] Visual output unchanged
- [ ] PHPCS (when wired) doesn't flag the manage table
