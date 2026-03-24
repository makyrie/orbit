---
title: "refactor: Split REST API into resource-based controller classes"
type: refactor
status: completed
date: 2026-03-24
---

# Split REST API into Resource-Based Controllers

## Overview

Split the 1,240-line `Orbit_REST_API` monolith into 5 focused controller classes, each owning routes for a single resource domain. Pure mechanical refactor — no behavior changes, no new features.

## Problem Statement / Motivation

`class-orbit-rest-api.php` handles 28 methods across 6 unrelated resource domains. Adding a new endpoint requires navigating 1,200+ lines to find the right section. Permission callbacks are interleaved with handlers. The class violates Single Responsibility Principle and will degrade as the API grows.

## Proposed Solution

Split into 5 controller classes plus a thin coordinator:

| File | Class | Methods | Routes | Est. Lines |
|------|-------|---------|--------|------------|
| `class-orbit-rest-subscription.php` | `Orbit_REST_Subscription` | handle_subscribe, handle_unsubscribe, get_subscriptions, get_subscribers, update_subscriber, update_preferences, shape_subscription | 6 routes | ~280 |
| `class-orbit-rest-activity.php` | `Orbit_REST_Activity` | get_activities, create_activity, update_activity, cancel_activity, get_activity_responses, handle_respond, handle_remove_response | 5 routes | ~280 |
| `class-orbit-rest-profile.php` | `Orbit_REST_Profile` | admin_list_profiles, admin_create_profile, admin_update_profile, admin_delete_profile, admin_regenerate_token, get_status | 4 routes | ~200 |
| `class-orbit-rest-notification.php` | `Orbit_REST_Notification` | handle_verify_phone, handle_twilio_incoming, get_notifications | 3 routes | ~130 |
| `class-orbit-rest-api.php` | `Orbit_REST_API` | register_routes() coordinator + shared permission callbacks | 0 handlers | ~80 |

## Technical Approach

### Phase 1: Create controller files with extracted methods

For each controller class:

1. Create the new file in `includes/`
2. Move the handler methods and their associated route registrations
3. Move permission callbacks that are only used by that controller
4. Keep shared permission callbacks (`is_admin`) in the coordinator

**Method → Controller mapping:**

```
Orbit_REST_Subscription:
  Routes:  POST /subscribe, POST /unsubscribe, GET /subscriptions,
           GET /subscribers, PATCH /subscribers/{id}, PATCH /preferences
  Handlers: handle_subscribe, handle_unsubscribe, get_subscriptions,
            get_subscribers, update_subscriber, update_preferences
  Private: shape_subscription
  Perms:   can_manage_subscribers

Orbit_REST_Activity:
  Routes:  GET /activities, POST /activities, PATCH /activities/{id},
           DELETE /activities/{id}, GET /activities/{id}/responses,
           POST /respond, DELETE /respond
  Handlers: get_activities, create_activity, update_activity,
            cancel_activity, get_activity_responses,
            handle_respond, handle_remove_response
  Perms:   can_create_activity, can_manage_activity

Orbit_REST_Profile:
  Routes:  GET /profiles, POST /profiles, PATCH /profiles/{id},
           DELETE /profiles/{id}, POST /profiles/{id}/regenerate-token,
           GET /status
  Handlers: admin_list_profiles, admin_create_profile,
            admin_update_profile, admin_delete_profile,
            admin_regenerate_token, get_status
  Perms:   can_manage_profile_or_admin

Orbit_REST_Notification:
  Routes:  POST /verify-phone, POST /twilio/incoming, GET /notifications
  Handlers: handle_verify_phone, handle_twilio_incoming,
            get_notifications
  Perms:   (none unique — uses is_admin, is_user_logged_in)
```

**Tasks:**

- [x] Create `includes/class-orbit-rest-subscription.php` with subscription/subscriber/preferences routes and handlers
- [x] Create `includes/class-orbit-rest-activity.php` with activity CRUD, respond, and response routes
- [x] Create `includes/class-orbit-rest-profile.php` with admin profile CRUD and status routes
- [x] Create `includes/class-orbit-rest-notification.php` with phone verify, Twilio webhook, and notification log routes
- [x] Reduce `class-orbit-rest-api.php` to coordinator: `register_routes()` calls each controller's `register_routes()`, keep shared `is_admin()` and `NAMESPACE` constant

### Phase 2: Update bootstrap

- [x] Add `require_once` lines in `orbit.php` for the 4 new controller files
- [x] Update `register_routes()` in `Orbit_REST_API` to delegate to sub-controllers

```php
// includes/class-orbit-rest-api.php
public static function register_routes() {
    Orbit_REST_Subscription::register_routes();
    Orbit_REST_Activity::register_routes();
    Orbit_REST_Profile::register_routes();
    Orbit_REST_Notification::register_routes();
}
```

### Phase 3: Verify and clean up

- [x] Verify all 17 endpoints still respond (check route registration)
- [x] Remove the old monolithic method bodies from `class-orbit-rest-api.php`
- [x] Confirm no orphaned methods remain

## Acceptance Criteria

- [x] Each controller file is < 300 lines
- [x] All existing REST endpoints respond identically (same URLs, same methods, same responses)
- [x] No behavior changes — pure structural refactor
- [x] `class-orbit-rest-api.php` is < 100 lines (coordinator only)
- [x] Permission callbacks are co-located with the routes they protect

## Dependencies & Risks

| Risk | Mitigation |
|------|------------|
| Shared `NAMESPACE` constant | Keep in coordinator class; controllers reference `Orbit_REST_API::NAMESPACE` |
| `shape_subscription()` used across handlers | Move to `Orbit_REST_Subscription` as private; it's only called from subscription handlers |
| `is_admin()` shared by profiles and notifications | Keep in coordinator; reference as `array( 'Orbit_REST_API', 'is_admin' )` |

## Sources & References

- Todo: `todos/020-pending-p3-rest-api-split-by-resource.md`
- Current file: `includes/class-orbit-rest-api.php` (1,240 lines, 28 methods)
- CLI pattern reference: `cli/` directory — already split into 8 resource-based command files
