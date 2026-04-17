---
title: "Fix duplicate page titles, nav exposure, and raw datetime display"
slug: "virtual-page-title-nav-and-datetime-display"
date: "2026-04-16"
status: "resolved"
category: "ui-bugs"
severity: "medium"
component:
  - "includes/class-orbit-shortcodes.php"
  - "includes/class-orbit-routes.php"
  - "orbit.php"
tags:
  - virtual-pages
  - fse-theme
  - navigation
  - datetime-formatting
  - shortcodes
  - rewrite-rules
symptoms:
  - "Page title rendered twice on /@slug, /activity/{id}, and /@slug/subscribe routes"
  - "All 7 Orbit internal pages appeared in FSE theme top navigation"
  - "Activity dates displayed as raw UTC strings (e.g. 2026-04-19 09:00:00)"
root_cause: >
  Virtual page shortcodes output their own heading tags duplicating the title already
  rendered by the FSE theme from WP_Post->post_title; internal pages registered as
  published posts are auto-collected by the theme's page-list block; datetime fields
  are output directly from the database without formatting or timezone conversion.
resolution: >
  Removed redundant heading tags from profile(), activity(), and subscribe_form()
  shortcodes; added get_pages and wp_nav_menu_objects filters in orbit.php to exclude
  internal pages by slug; added format_datetime() helper to convert UTC to viewer
  timezone and render human-readable strings across all 5 datetime output sites.
---

# Fix: Duplicate Titles, Nav Exposure, and Raw Datetime Display

## Context

Found during a browser walkthrough of the live site. Three visual issues were the most impactful UX problems across the core pages (profile, activity detail, subscribe form, navigation).

## Fix 1: Duplicate Titles on Virtual Page Routes

### Problem

On `/@sarah-k`, `/activity/1`, and `/@sarah-k/subscribe`, the title appeared twice — once from the FSE theme rendering `WP_Post->post_title` as an `<h1>`, and again from the shortcode's own heading tag.

### Root Cause

`render_virtual_page()` in `class-orbit-routes.php` constructs a synthetic `WP_Post` with `post_title` set. The FSE block theme (Twenty Twenty-Five) renders this via its Post Title block. The shortcode methods `profile()`, `activity()`, and `subscribe_form()` each independently output their own `<h1>`/`<h2>` with the same text.

### Fix

Removed the redundant heading output from three shortcode methods in `includes/class-orbit-shortcodes.php`:

- `profile()` — removed `<h1>` for `$profile->display_name`
- `activity()` — removed `<h1>` for `$activity->title`
- `subscribe_form()` — removed `<h2>` for "Subscribe to {name}"

Page-based shortcodes (dashboard, manage, settings) were left unchanged — their headings add context beyond the generic page title.

**Rule:** Shortcodes own content, themes own chrome. A shortcode embedded in a virtual page should never open with an `<h1>` that restates the page title.

## Fix 2: Navigation Exposes Internal Pages

### Problem

The FSE theme's auto-generated nav showed all 7 Orbit internal pages (Dashboard, Edit Activity, Edit Profile, Manage, New Activity, Settings, Subscribers) alongside regular content pages.

### Root Cause

Two navigation sources needed filtering:

- **FSE block themes** use the `<!--wp:page-list-->` block, which calls `get_pages()` and renders every published page
- **Classic themes** use `wp_nav_menu()` with auto-populated menu items

Neither had any exclusion logic for Orbit's internal pages.

### Fix

Added to `orbit.php`:

```php
function orbit_get_internal_page_slugs() {
    return array(
        'orbit-dashboard',
        'orbit-settings',
        'orbit-manage',
        'orbit-new-activity',
        'orbit-edit-activity',
        'orbit-subscribers',
        'orbit-edit-profile',
    );
}

// FSE page-list blocks
add_filter( 'get_pages', function ( $pages ) {
    $slugs = orbit_get_internal_page_slugs();
    return array_filter( $pages, function ( $page ) use ( $slugs ) {
        return ! in_array( $page->post_name, $slugs, true );
    } );
} );

// Classic nav menus
add_filter( 'wp_nav_menu_objects', function ( $items ) {
    $slugs = orbit_get_internal_page_slugs();
    return array_filter( $items, function ( $item ) use ( $slugs ) {
        if ( 'page' === $item->object ) {
            $page = get_post( $item->object_id );
            if ( $page && in_array( $page->post_name, $slugs, true ) ) {
                return false;
            }
        }
        return true;
    } );
} );
```

Purely code-based — no DB migration, no post meta. Works on any server with the plugin active. `orbit_get_internal_page_slugs()` is the single source of truth; adding a new internal page requires one change.

## Fix 3: Raw UTC Datetimes Displayed to Users

### Problem

Activity dates displayed as raw database strings like `2026-04-19 09:00:00` — no human-readable formatting, no timezone conversion.

### Root Cause

Shortcodes passed `$activity->date_time` directly to `esc_html()` without any processing. The spec requires UTC storage with viewer-timezone display.

### Fix

Added `Orbit_Shortcodes::format_datetime()` in `includes/class-orbit-shortcodes.php`:

```php
private static function format_datetime( $utc_datetime, $format = '' ) {
    if ( empty( $utc_datetime ) ) {
        return '';
    }
    if ( ! $format ) {
        $format = 'l, F j \a\t g:i A';
    }

    $timezone_string = '';
    if ( is_user_logged_in() ) {
        $timezone_string = get_user_meta( get_current_user_id(), 'orbit_timezone', true );
    }
    if ( ! $timezone_string ) {
        $timezone_string = wp_timezone_string();
    }

    try {
        $utc      = new DateTimeZone( 'UTC' );
        $local_tz = new DateTimeZone( $timezone_string );
        $dt       = new DateTime( $utc_datetime, $utc );
        $dt->setTimezone( $local_tz );
        return $dt->format( $format );
    } catch ( Exception $e ) {
        return $utc_datetime;
    }
}
```

**Timezone resolution:** viewer's `orbit_timezone` user meta > site's `wp_timezone_string()` > raw fallback on error.

**Formats:** Full (`"Saturday, April 19 at 9:00 AM"`) for activity cards and detail pages. Short (`"Apr 19, 2026 9:00 AM"`) for the manage table.

**5 call sites updated:** dashboard cards, manage table, profile page cards, activity detail page, activity detail "When:" line.

## Spec References

- **Timezone Handling** (`docs/refs/orbit-v1-spec.md`, lines 809–819): "All datetimes stored in UTC... Activity times displayed in the viewer's timezone"
- **Routes & Page Structure** (`docs/refs/orbit-v1-spec.md`, lines 275–308): defines `/@slug` and `/activity/{id}` as "Custom rewrite -> shortcode" without specifying title rendering responsibility
- **Authenticated Pages** (`docs/refs/orbit-v1-spec.md`, lines 288–296): lists all 7 internal pages that should be accessible via in-app links only
- **Risk Analysis** (`docs/plans/2026-03-23-feat-orbit-v1-plugin-plan.md`, line 519): "Timezone bugs | Medium | Store everything UTC, convert only at display layer"

## Prevention

### Before adding a new shortcode or virtual route

1. **Title duplication check:** Will the shortcode be embedded in a virtual page or theme template that already renders a title? If yes, the shortcode must not output an `<h1>`.
2. **Nav exposure check:** Does the new page need to appear in site navigation? If not, add its slug to `orbit_get_internal_page_slugs()`.
3. **Datetime display check:** Does the output include a datetime from the database? If yes, pass it through `format_datetime()` — never output raw.

### Test suggestions

- **DOM assertion:** Visit each virtual page route and verify `document.querySelectorAll('h1').length === 1`
- **Nav assertion:** Call `get_pages()` after plugin activation and assert no Orbit slugs appear
- **Timezone test:** Set `orbit_timezone` to a known value, call `format_datetime()` with a known UTC string, assert the output matches the expected local time
- **Edge cases:** Midnight UTC crossing a date boundary, DST transition hours, empty/malformed input
