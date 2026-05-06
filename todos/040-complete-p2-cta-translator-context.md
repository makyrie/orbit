---
status: complete
priority: p2
issue_id: "040"
tags: [code-review, i18n, php]
dependencies: []
---

# `[orbit_cta]` shortcode strings lack translator context

## Problem Statement

`Orbit_Shortcodes::cta()` (`includes/class-orbit-shortcodes.php:60, 63`) uses bare `__()` for two strings that are functionally a sibling pair (two states of the same CTA button):

- "Set up your profile"
- "Go to your dashboard"

Translators seeing them in isolation won't know they're paired button labels and may render them in inconsistent registers, lengths, or imperative styles.

## Proposed Solution

Use `_x()` with a shared translator context:

```php
$label = _x( 'Go to your dashboard', 'orbit_cta button label', 'orbit' );
// and
$label = _x( 'Set up your profile', 'orbit_cta button label', 'orbit' );
```

## Acceptance Criteria

- [ ] Both strings use `_x()` with the same context
- [ ] No visible behavior change in English
- [ ] String extraction (POT regeneration) carries the context to translators
