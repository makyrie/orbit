---
status: complete
priority: p2
issue_id: "060"
tags: [code-review, i18n, php]
dependencies: []
---

# Status-label fallback uses `ucfirst()` on raw DB value

## Problem Statement

In `includes/class-orbit-shortcodes.php` lines 153 and 469, the status label is resolved via:

```php
$status_labels[ $sub->status ] ?? ucfirst( $sub->status )
```

If a new status enum value is introduced (or unexpected DB content appears), the fallback returns a raw, untranslated, ASCII-capitalized string. This creates a translation hole and is inconsistent with how the rest of the UI presents status labels.

## Proposed Solution

Drop the `ucfirst()` fallback. Either:

1. **Preferred:** Expand the `$status_labels` map exhaustively to cover every defined status enum value (and centralize per todo 053 so all call sites stay in sync), or
2. Fall back to `__( 'Unknown', 'orbit' )` when the status is not recognized.

## Acceptance Criteria

- Both call sites (lines 153 and 469) no longer use `ucfirst()` on a raw DB value.
- All known status enum values map to a translated label.
- Unknown/unexpected values render a translated fallback (`Unknown`) rather than raw DB content.
- No untranslated user-facing strings remain in the status-label code path.
