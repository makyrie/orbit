---
status: complete
priority: p3
issue_id: "079"
tags: [code-review, simplicity, php, PR-23]
dependencies: []
---

# Use wp_insert_user with display_name in one call instead of wp_create_user + wp_update_user

## Problem Statement

`Orbit_REST_Signup::handle_signup()` creates the user in two passes:

```php
$user_id = wp_create_user( $username, $password, $email );
// ... error guard ...
wp_update_user(
    array(
        'ID'           => $user_id,
        'display_name' => $display_name,
    )
);
```

`wp_create_user` is `wp_insert_user` with a fixed shape (login/pass/email only). Switching to `wp_insert_user` lets us pass `display_name` in the same call, saving a second DB round-trip and a `do_action( 'profile_update' )` cycle.

## Findings

- `includes/class-orbit-rest-signup.php:134-146` — two-step user creation.
- `wp_insert_user( $userdata )` accepts the same array as `wp_update_user` minus the `ID`.

## Proposed Solutions

**Option A — One call:**

```php
$user_id = wp_insert_user(
    array(
        'user_login'   => $username,
        'user_pass'    => wp_generate_password(),
        'user_email'   => $email,
        'display_name' => $display_name,
        'role'         => 'subscriber',
    )
);

if ( is_wp_error( $user_id ) ) {
    return new WP_Error( 'user_creation_failed', $user_id->get_error_message(), array( 'status' => 500 ) );
}
```

Drops `wp_update_user`, drops `wp_create_user`, sets the role at creation time (still needs `add_user_to_blog` on multisite — see todo 076 for the guard).

**Option B — Leave as-is.**

`wp_create_user` is the canonical "make a user" helper and `wp_update_user` is a clear follow-up. The cost is one DB write and one action; the readability win arguably justifies it.

Recommend **Option A** if we end up touching this code for any other reason (todos 070-073). Otherwise low-value.

## Recommended Action

(Filled during triage.)

## Technical Details

- Affected file: `includes/class-orbit-rest-signup.php`
- Pairs naturally with todo 071 (race retry) — both touch the same code block.

## Acceptance Criteria

- [ ] User row created with display_name set in one insert.
- [ ] Behavior identical from the caller's perspective.

## Work Log

- 2026-05-14: Identified during code review of PR #23.

## Resources

- PR #23: https://github.com/makyrie/orbit/pull/23
- `includes/class-orbit-rest-signup.php:134-146`
