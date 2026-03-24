---
status: pending
priority: p2
issue_id: "015"
tags: [code-review, php]
dependencies: []
---

# update_log_status Format Array Mismatch

## Problem Statement

In `Orbit_Notifier::update_log_status()`, when status is `'sent'`, `$data` has 2 keys but the format array only has 1 entry. Works accidentally because both are strings, but is a latent bug.

**Affected file:** `includes/class-orbit-notifier.php:641-653`

## Proposed Solutions

Build format array dynamically alongside `$data`:
```php
$formats = array( '%s' );
if ( 'sent' === $status ) {
    $data['sent_at'] = current_time( 'mysql', true );
    $formats[] = '%s';
}
```

## Acceptance Criteria

- [ ] Format array length always matches data array length
