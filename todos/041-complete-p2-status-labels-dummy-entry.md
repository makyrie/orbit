---
status: complete
priority: p2
issue_id: "041"
tags: [code-review, simplicity, php]
dependencies: []
---

# `status_labels` dummy `'active' => ''` entry could be removed via null-coalesce

## Problem Statement

In `manage()` (`includes/class-orbit-shortcodes.php:514-518`):

```php
$status_labels = array(
    'active'    => '',                         // Default; show nothing.
    'cancelled' => __( 'Cancelled', 'orbit' ),
    'past'      => __( 'Past', 'orbit' ),
);
```

The `'active' => ''` entry exists only to satisfy `isset()` at the lookup site. The inline comment apologizes for a dummy entry — code smell. Saying "active has no badge" by *absence* would read better than by presence-of-emptiness.

## Proposed Solution

```php
$status_labels = array(
    'cancelled' => __( 'Cancelled', 'orbit' ),
    'past'      => __( 'Past', 'orbit' ),
);
```

Then at the lookup (line 532):

```php
$status_label = $status_labels[ $activity->status ] ?? '';
```

Two lines saved, comment removed, intent clearer.

## Acceptance Criteria

- [ ] `'active' => ''` entry removed from the array
- [ ] Lookup uses null-coalesce, returns empty string for any unknown status
- [ ] Active rows still render no badge
- [ ] Cancelled and Past rows still render their badges
