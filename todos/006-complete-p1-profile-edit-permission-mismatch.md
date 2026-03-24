---
status: pending
priority: p1
issue_id: "006"
tags: [code-review, php, security]
dependencies: []
---

# Profile Edit Permission Mismatch — Posters Cannot Update Own Profile

## Problem Statement

The `[orbit_edit_profile]` shortcode renders a form that targets `PATCH /profiles/{id}`, but this REST endpoint has `permission_callback => is_admin`. Regular poster users will get a 403 Forbidden when submitting the form. The shortcode's access check (`orbit_manage_profile` capability) passes, but the API rejects the request.

## Findings

- **PHP reviewer (#12):** Confirmed the shortcode at `class-orbit-shortcodes.php:563` targets `profiles/{id}` with PATCH, which is admin-only at `class-orbit-rest-api.php:1037`.

## Proposed Solutions

### Option A: Add a self-service profile update endpoint
Add `PATCH /profile` (singular, no ID) that allows the current user to update their own profile.
- **Effort:** Small
- **Risk:** Low

### Option B: Modify the admin endpoint permission to allow profile owners
Change `is_admin` to a callback that checks ownership OR admin.
- **Effort:** Small
- **Risk:** Low

## Acceptance Criteria

- [ ] A poster user can update their own profile via the edit profile form
- [ ] Non-owners cannot update other users' profiles
- [ ] Admin can still update any profile
