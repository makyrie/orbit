---
title: "Poster self-service setup flow and security/quality review fixes"
slug: "poster-setup-flow-and-security-review"
date: "2026-04-16"
status: "resolved"
category: "security-issues"
severity: "high"
component:
  - "class-orbit-rest-profile.php"
  - "class-orbit-shortcodes.php"
  - "class-orbit-routes.php"
  - "orbit.php"
  - "orbit-forms.js"
  - "orbit.css"
  - "OrbitSubscriptionTest.php"
  - "OrbitResponseTest.php"
tags:
  - self-service
  - poster-profile
  - rest-api
  - security
  - nonce
  - sanitization
  - capability-check
  - test-fixtures
  - wp-unslash
  - navigation
symptoms:
  - "Logged-in users saw 'You don't have a profile yet' with no way to create one"
  - "No in-app navigation between Orbit pages"
  - "Unsubscribe executed destructively on GET without confirmation"
  - "PATCH route accepted unfiltered request parameters"
  - "Five $_GET reads missing wp_unslash"
  - "/profiles/me accessible to any authenticated user"
  - "Slug used sanitize_text_field instead of sanitize_title"
  - "14 PHPUnit test failures from stale fixture data"
root_cause: >
  No self-service profile creation endpoint or UI existed. Security review
  revealed GET-based destructive actions, unfiltered REST params, missing
  wp_unslash, overly permissive endpoint permissions, wrong slug sanitizer,
  duplicated config arrays, and test isolation failures from missing teardown.
resolution: >
  Added POST /profiles/me endpoint with orbit_subscribe capability gate,
  profile creation form, app navigation, and dashboard CTA. Fixed all 10
  security/quality findings and 14 pre-existing test failures.
---

# Poster Self-Service Setup Flow and Security Review

## Context

A browser walkthrough revealed that logged-in users had no way to become posters. Every poster page was a dead end ("You don't have a profile yet"). A subsequent code review identified 10 security and quality issues. Both were resolved in the same session.

## Area 1: Poster Self-Service Setup Flow

### Problem

Profile creation required WP-CLI. No in-app navigation existed between Orbit pages. The dashboard had no entry point for becoming a poster.

### Solution

**1. REST endpoint: `POST /profiles/me`** (`class-orbit-rest-profile.php`)

Self-service profile creation for authenticated Orbit subscribers. Injects `get_current_user_id()` rather than accepting user_id from the request. Upgrades to poster role on success.

```php
register_rest_route( $ns, '/profiles/me', array(
    'methods'             => 'POST',
    'callback'            => array( __CLASS__, 'create_own_profile' ),
    'permission_callback' => function() {
        return current_user_can( 'orbit_subscribe' );
    },
    'args' => array(
        'slug'         => array( 'required' => true, 'sanitize_callback' => 'sanitize_title' ),
        'display_name' => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
        'bio'          => array( 'required' => false, 'sanitize_callback' => 'sanitize_textarea_field' ),
    ),
) );
```

**2. Profile creation form** (`class-orbit-shortcodes.php`)

`edit_profile()` now detects missing profile and delegates to `create_profile_form()`, which pre-fills display name and slug from the WordPress user and submits to `profiles/me` via the existing JS form handler.

**3. App navigation** (`class-orbit-shortcodes.php`)

`app_nav()` renders a tab bar at the top of every Orbit page. All users see Dashboard + Settings. Posters additionally see Manage, New Activity, and Profile. Current page is highlighted.

**4. Dashboard CTA** (`class-orbit-shortcodes.php`)

Empty state shows "Create a profile" link for non-posters, "Manage Your Activities" for existing posters.

**5. JS redirect** (`orbit-forms.js`)

`profiles/me` POST success reloads the page, where the user now has poster capabilities and sees the edit form with share link.

## Area 2: Security and Quality Review (10 Findings)

### P1 — Critical

**Unsubscribe via GET** (`class-orbit-routes.php`)

Email scanners and browser prefetchers could silently unsubscribe users. Converted to two-step: GET renders confirmation form with nonce → POST verifies nonce then processes.

```php
// GET step: render confirmation
wp_nonce_field( 'orbit_unsubscribe', 'orbit_unsubscribe_nonce' );

// POST step: verify before acting
if ( ! wp_verify_nonce( $nonce, 'orbit_unsubscribe' ) ) {
    // render error, return
}
```

**Unfiltered PATCH params** (`class-orbit-rest-profile.php`)

Added `args` schema to PATCH route with `sanitize_callback` per field. Handler whitelists keys:

```php
$allowed = array( 'display_name', 'slug', 'bio', 'require_approval' );
$args    = array_intersect_key( $request->get_params(), array_flip( $allowed ) );
```

### P2 — Important

| Finding | Fix |
|---------|-----|
| Missing `wp_unslash()` on `$_GET` reads | Added at all 5 locations across shortcodes and routes |
| `/profiles/me` open to any user | Gated with `current_user_can( 'orbit_subscribe' )` |
| Slug `sanitize_text_field` → `sanitize_title` | Changed on all 3 profile routes (POST, POST /me, PATCH) |
| Duplicated page slug arrays (3 copies) | Consolidated to `orbit_get_internal_page_slugs()` |

### P3 — Nice-to-have

| Finding | Fix |
|---------|-----|
| Virtual page missing `post_date`/`post_author` | Added all required WP_Post properties |
| `get_page_by_path` DB query in enqueue | Replaced with direct `home_url()` |
| `get_pages` filter affected wp-admin | Added `is_admin()` early return |
| Dead `$_GET['id']` fallback in activity shortcode | Removed |

### Pre-existing: Test Failures

14 test failures caused by stale fixture data persisting between runs. `wpSetUpBeforeClass` in both `OrbitSubscriptionTest` and `OrbitResponseTest` created profiles with fixed slugs, but no teardown existed. If a prior run left data behind, `create()` returned `WP_Error`, cascading through all dependent tests.

**Fix:** Added stale data cleanup before fixture creation and `wpTearDownAfterClass` with `is_int()` guards:

```php
public static function wpSetUpBeforeClass( $factory ) {
    global $wpdb;
    // Clean stale data from prior runs.
    $wpdb->query( "DELETE FROM {$wpdb->prefix}" . ORBIT_TABLE_PROFILES . " WHERE slug = 'test-poster'" );
    // ... create fixtures ...
}

public static function wpTearDownAfterClass() {
    global $wpdb;
    if ( is_int( self::$profile_id ) ) {
        $wpdb->query( "DELETE FROM ..." );
    }
}
```

## Related Documentation

- `docs/solutions/ui-bugs/virtual-page-title-nav-and-datetime-display.md` — earlier UI fixes from the same session
- `docs/refs/orbit-v1-spec.md`, Route Implementation Notes (lines 299–303) — spec intent for dynamic nav
- `docs/plans/2026-03-23-feat-orbit-v1-plugin-plan.md`, Phase 7 — unchecked template redirect item

## Prevention

**Dead-end pages:** Every new role-gated page must have a reachable entry point. Add E2E smoke tests that log in as each role and assert the primary CTA is present.

**GET-based destructive actions:** Any action that writes or deletes must use POST/PATCH/DELETE. Require a confirmation step with nonce for user-facing unsubscribes and cancellations.

**Unfiltered REST params:** Declare `sanitize_callback` and `validate_callback` for every accepted parameter in route `args`. Use an allowlist pattern in the handler.

**Test fixture isolation:** Each test class that creates fixtures in `wpSetUpBeforeClass` must have a corresponding `wpTearDownAfterClass`. Guard teardown with type checks in case setup failed. Clean stale data before creating fixtures.

**Self-service permission escalation:** Self-service endpoints must check both authentication and a specific capability. Never use `is_user_logged_in` alone as a permission callback for endpoints that grant elevated roles.
