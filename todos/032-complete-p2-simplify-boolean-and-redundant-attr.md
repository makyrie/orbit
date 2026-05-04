---
status: complete
priority: p2
issue_id: "032"
tags: [code-review, simplicity, php, javascript]
dependencies: []
---

# Simplify boolean expressions and drop redundant `data-orbit-phone-state` attribute

## Problem Statement

Two readability issues in PR #5:

1. **Obfuscated booleans** in `includes/class-orbit-shortcodes.php:308, 319`:
   ```php
   $phone_form_hidden = ( $verified && $phone ) || ( $phone && ! $verified );
   $code_form_hidden  = ! ( $phone && ! $verified );
   ```
   The first factors to `(bool) $phone`. The second is a double-negative.

2. **Redundant data attribute** at `class-orbit-shortcodes.php:296`:
   ```php
   echo '<div class="orbit-phone-verified" data-orbit-phone-state="verified">';
   ```
   The `data-orbit-phone-state` attribute is read in exactly one place (`assets/js/orbit-forms.js:358`) and the same element already carries the unique `.orbit-phone-verified` class. Dead surface area; introduces a second naming convention.

## Proposed Solution

```php
$has_phone         = (string) $phone !== '';
$phone_form_hidden = $has_phone;
$code_form_hidden  = ! $has_phone || $verified;
```

Drop the data attribute; in JS, query `.orbit-phone-verified`:
```js
var verified = section.querySelector( '.orbit-phone-verified' );
```

## Acceptance Criteria

- [ ] Boolean expressions replaced with the simplified form
- [ ] `data-orbit-phone-state` attribute removed from PHP
- [ ] JS selector updated to `.orbit-phone-verified`
- [ ] Visual behavior unchanged across all three states
