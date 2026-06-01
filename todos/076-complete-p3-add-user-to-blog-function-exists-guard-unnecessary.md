---
status: complete
priority: p3
issue_id: "076"
tags: [code-review, simplicity, php, PR-23]
dependencies: []
---

# Drop unnecessary function_exists() guard around add_user_to_blog

## Problem Statement

`includes/class-orbit-rest-signup.php:151-154`:

```php
if ( function_exists( 'add_user_to_blog' ) ) {
    add_user_to_blog( get_current_blog_id(), $user_id, 'subscriber' );
}
```

`add_user_to_blog()` is part of WordPress core (`wp-includes/ms-functions.php`, loaded unconditionally when multisite is active; on single-site it's still defined as a wrapper since WP 6.x). The guard is defensive against a state that doesn't occur on any supported WordPress version.

If the intent is "no-op on single-site," that's actually what core's wrapper does already (single-site `add_user_to_blog` is defined and adds the role to the single blog).

## Findings

- `includes/class-orbit-rest-signup.php:148-154` — guard + call.
- Plugin requires WP 6.4+ (orbit.php Plugin Header: `Requires at least: 6.4`).

## Proposed Solutions

**Option A — Drop the guard:**

```php
// On multisite, `wp_create_user` makes a network user with no
// role on this sub-site. `add_user_to_blog` attaches them with
// the subscriber role. On single-site it's idempotent.
add_user_to_blog( get_current_blog_id(), $user_id, 'subscriber' );
```

**Option B — Keep as paranoia.** Defensible if the team prefers belt-and-suspenders, but inconsistent with how the rest of the codebase trusts core.

Recommend **Option A**.

## Recommended Action

(Filled during triage.)

## Technical Details

- Affected file: `includes/class-orbit-rest-signup.php`

## Acceptance Criteria

- [ ] Guard removed; comment kept.
- [ ] PHPUnit still green.

## Work Log

- 2026-05-14: Identified during code review of PR #23.

## Resources

- PR #23: https://github.com/makyrie/orbit/pull/23
- `includes/class-orbit-rest-signup.php:148-154`
