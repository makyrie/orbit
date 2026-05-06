---
status: complete
priority: p2
issue_id: "056"
tags: [code-review, consistency, accessibility]
dependencies: []
---

# Subscribe Form Has Required Marks But No Required-Note Paragraph

## Problem Statement

In `includes/class-orbit-shortcodes.php:1290-1300`, the Subscribe form renders required-field markers (`*`) on inputs but does not include the `<p class="orbit-form-required-note">Fields marked with * are required.</p>` paragraph that explains what those asterisks mean.

Other forms (New Activity, Edit Activity, Edit Profile) all emit this note above the form opening tag. Subscribers see asterisks with no explanatory legend — both an accessibility issue (screen reader users don't get the "required" semantics from a bare `*`) and a consistency issue.

## Proposed Solution

In `includes/class-orbit-shortcodes.php:1290-1300`, insert the required-note paragraph immediately above the `<form>` opening tag.

- If finding 053 (`render_required_note()` helper) lands first, call:
  ```php
  echo $this->render_required_note();
  ```
- Otherwise, copy the existing kses'd note used elsewhere:
  ```php
  echo '<p class="orbit-form-required-note">' . wp_kses(
      __( 'Fields marked with <span class="orbit-required-mark">*</span> are required.', 'orbit' ),
      array( 'span' => array( 'class' => array() ) )
  ) . '</p>';
  ```

## Acceptance Criteria

- [ ] Subscribe form renders the required-note paragraph above its form opening tag.
- [ ] Markup matches the other forms (same class, same kses-allowed tags).
- [ ] If `render_required_note()` exists (finding 053), this call site uses it.
- [ ] Visual + screen-reader test: the note appears above the form and is read aloud before the first field.
