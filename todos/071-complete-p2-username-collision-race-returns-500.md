---
status: complete
priority: p2
issue_id: "071"
tags: [code-review, php, rest-api, race-condition, PR-23]
dependencies: []
---

# Username-collision race: wp_create_user 'existing_user_login' becomes a 500

## Problem Statement

`Orbit_REST_Signup::handle_signup()` defends against username collisions with a pre-check loop:

```php
while ( username_exists( $username ) && $tries < 5 ) {
    $username = $base . wp_rand( 100, 999 );
    ++$tries;
}
```

This handles intra-request collisions. But the actual race is between `username_exists()` and `wp_create_user()` — two parallel signups with the same `$base` (say, two people named "Alex") could both pass `username_exists` and both attempt `wp_create_user`. The loser gets a `WP_Error` with code `existing_user_login` and we surface it as:

```php
return new WP_Error( 'user_creation_failed', $user_id->get_error_message(), array( 'status' => 500 ) );
```

That's a 500 to the user, with WP core's error message bleeding through (which may be technical: "Sorry, that username already exists!").

## Findings

- `includes/class-orbit-rest-signup.php:126-139` — collision loop + wp_create_user + error handling.
- The 3-digit `wp_rand( 100, 999 )` suffix on a sanitized base makes collisions rare but not zero, especially as the user base grows.
- The race window is small but real — and worse on multisite where usernames are network-wide and `username_exists` checks against all sites' users.

## Proposed Solutions

**Option A — Retry on `existing_user_login` (smallest diff):**

```php
$attempts = 0;
do {
    $password = wp_generate_password();
    $user_id  = wp_create_user( $username, $password, $email );
    if ( ! is_wp_error( $user_id ) ) {
        break;
    }
    if ( 'existing_user_login' !== $user_id->get_error_code() ) {
        return new WP_Error( 'user_creation_failed', $user_id->get_error_message(), array( 'status' => 500 ) );
    }
    $username = $base . wp_rand( 100, 999 );
    ++$attempts;
} while ( $attempts < 5 );

if ( is_wp_error( $user_id ) ) {
    return new WP_Error( 'user_creation_failed', __( "We couldn't create your account right now. Please try again in a moment.", 'orbit' ), array( 'status' => 503 ) );
}
```

Drops the pre-check loop (the post-check covers it) and returns a friendly 503 if we exhaust retries.

**Option B — Wider random suffix:**

Switch to a 5- or 6-digit suffix (or a short random string). Reduces collision probability without changing the architecture. Combine with logging if we still see collisions.

**Option C — Username derived from email local-part + suffix.**

Two "Alex" users with different emails would get different base usernames. Bigger change, may surface PII to user-facing displays (we'd need to verify nothing renders `user_login`).

Recommend **Option A + B together** — retry-on-error AND a wider suffix.

## Recommended Action

(Filled during triage.)

## Technical Details

- Affected file: `includes/class-orbit-rest-signup.php`
- WP core error code: `existing_user_login` returned by `wp_insert_user()` inside `wp_create_user()`.

## Acceptance Criteria

- [ ] A race that causes `wp_create_user` to return `existing_user_login` is recovered by retry, not surfaced as a 500.
- [ ] Final-failure message to the user is friendly (not WP core's "Sorry, that username already exists!").
- [ ] Email collision still 409s deterministically (separate code path).

## Work Log

- 2026-05-14: Identified during code review of PR #23.

## Resources

- PR #23: https://github.com/makyrie/orbit/pull/23
- `includes/class-orbit-rest-signup.php:119-139`
