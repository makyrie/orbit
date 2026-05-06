---
status: complete
priority: p2
issue_id: "053"
tags: [code-review, simplicity, php]
dependencies: []
---

# Extract Status-Label Map and Required-Note Helper

## Problem Statement

Two duplications in `includes/class-orbit-shortcodes.php`:

1. **Status-label map** — the `array( 'approved' => __( 'Approved', ... ), 'pending' => __( 'Pending', ... ) )` map appears in both `my_subscriptions` (`:500-503`) and `subscribers` (`:961-964`). Any future change (new statuses, label tweaks, context fixes from finding 052) needs both updated.
2. **Required-note kses block** — the `wp_kses( __( 'Fields marked with <span class="orbit-required-mark">*</span> are required.' ), array( 'span' => array( 'class' => array() ) ) )` block is repeated verbatim three times at `:683-686`, `:802-805`, and `:1034-1037`.

Both are mechanical duplications that increase the cost of small changes and risk drift.

## Proposed Solution

1. Add a public static method on `Orbit_Subscription` (or wherever subscription statuses live):
   ```php
   public static function get_status_labels() {
       return array(
           'approved' => _x( 'Approved', 'subscription status', 'orbit' ),
           'pending'  => _x( 'Pending', 'subscription status', 'orbit' ),
       );
   }
   ```
   Call sites at `:500-503` and `:961-964` use this. Note: if finding 052 lands first, this method should respect the per-context distinction (or expose two helpers — `get_subscription_status_labels()` and `get_subscriber_status_labels()`).

2. Add a private helper on `Orbit_Shortcodes`:
   ```php
   private function render_required_note() {
       return '<p class="orbit-form-required-note">' . wp_kses(
           __( 'Fields marked with <span class="orbit-required-mark">*</span> are required.', 'orbit' ),
           array( 'span' => array( 'class' => array() ) )
       ) . '</p>';
   }
   ```
   Replace the three duplicated blocks with `echo $this->render_required_note();` (or `echo wp_kses_post( $this->render_required_note() );` if defensive escaping is preferred).

## Acceptance Criteria

- [ ] `Orbit_Subscription::get_status_labels()` exists (or per-context equivalents) and is used by both call sites.
- [ ] `Orbit_Shortcodes::render_required_note()` exists and is used by all three previous duplications.
- [ ] No duplicated status map or required-note kses block remains in `class-orbit-shortcodes.php`.
- [ ] Visual rendering of status badges and required notes is unchanged on the relevant pages.
