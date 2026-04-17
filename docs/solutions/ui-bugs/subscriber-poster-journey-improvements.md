---
title: "Subscriber and poster journey improvements"
slug: "subscriber-poster-journey-improvements"
date: "2026-04-16"
status: "resolved"
category: "ui-bugs"
severity: "medium"
component:
  - "class-orbit-shortcodes.php"
  - "class-orbit-rest-subscription.php"
  - "orbit-forms.js"
  - "orbit.css"
  - "class-orbit-activator.php"
  - "orbit.php"
tags:
  - subscribe
  - unsubscribe
  - profile-page
  - navigation
  - subscriptions
  - ux
symptoms:
  - "No Subscribe button on public profile page"
  - "Activity titles on profile page not clickable"
  - "No subscribe CTA on activity detail page"
  - "No way for subscribers to unsubscribe from the UI"
  - "No page listing a user's subscriptions"
  - "Subscribers page not accessible from app nav"
  - "Dashboard showed generic empty state even with pending subscriptions"
  - "Nav tabs wrapped to two lines with 7 items"
  - "Success messages disappeared too quickly"
root_cause: >
  The subscriber and poster journeys lacked discoverability and completeness.
  Subscribe/unsubscribe actions were only available via direct URLs or email
  tokens, with no in-app UI. The app nav didn't surface the Subscribers page,
  and the dashboard didn't communicate pending subscription status.
resolution: >
  Added Subscribe/Unsubscribe buttons on profile pages, subscribe CTA on
  activity detail, DELETE /subscriptions/{id} REST endpoint, My Subscriptions
  page with nav tab, Subscribers nav tab for posters, dashboard pending notice,
  and nav/message UX improvements.
---

# Subscriber and Poster Journey Improvements

## Context

Browser walkthroughs of the subscriber and poster journeys revealed that key actions (subscribe, unsubscribe, view subscriptions, manage subscribers) were either missing from the UI or only reachable via direct URLs. This document covers 10 improvements made in one session.

## Changes

### 1. Subscribe button on profile page
Visitors to `/@slug` now see a Subscribe button below the bio. Hidden from the profile owner, existing subscribers, and pending subscribers (who see "awaiting approval" instead).

### 2. Clickable activity titles on profile page
Activity card titles now link to `/activity/{id}`, matching the dashboard card behavior.

### 3. Subscribe CTA on activity detail page
Non-subscribers viewing `/activity/{id}` see: "Subscribe to {name} to get notified about activities like this."

### 4. Unsubscribe from profile page
Approved subscribers see a green "Subscribed" badge and red "Unsubscribe" button. Confirm dialog → DELETE to REST API → page reloads showing Subscribe button.

### 5. DELETE /subscriptions/{id} endpoint
New authenticated REST endpoint. Checks `user_id === get_current_user_id()` before allowing unsubscribe. Returns 403 for non-owners, 404 for missing subscriptions.

### 6. My Subscriptions page
New `[orbit_my_subscriptions]` shortcode, WordPress page at `/orbit-my-subscriptions/`, and "Subscriptions" nav tab. Shows all approved and pending subscriptions with poster links, status, dates, and unsubscribe buttons.

### 7. Subscribers tab in app nav
Poster-only nav tab linking to `/orbit-subscribers/`. A pending count badge was initially added but removed in review — it required 2 DB queries per page load across all pages.

### 8. Dashboard pending subscription notice
Empty state now detects pending subscriptions: "You have N subscription(s) awaiting approval. Activities will appear here once approved." Only queries when activities list is empty.

### 9. Manage page cleanup
Removed redundant pending subscriber notice (replaced by the Subscribers nav tab).

### 10. Nav and message UX
- Reduced tab padding/font-size so 7 tabs fit without wrapping
- Success messages persist 8 seconds (up from 5), fade out smoothly, scroll into view

## Prevention

When adding new user-facing actions, verify:
- Every action reachable via URL is also reachable via in-app UI
- Profile pages show the viewer's relationship status (subscribed/pending/none) with appropriate actions
- Empty states explain what the user can do next, not just what's missing
