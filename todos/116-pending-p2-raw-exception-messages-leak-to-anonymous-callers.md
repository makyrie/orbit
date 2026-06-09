---
status: pending
priority: p2
issue_id: "116"
tags: [code-review, PR-26, security, error-handling]
dependencies: []
---

# Raw WP_Error messages leak from transaction catch to anonymous callers

## Problem Statement

Both signup and subscribe handlers wrap user provisioning in a transaction whose catch block re-emits the original WP_Error message verbatim to the REST response:

```php
} catch ( \RuntimeException $e ) {
    $wpdb->query( 'ROLLBACK' );
    return new \WP_Error( 'subscribe_failed', $e->getMessage(), [ 'status' => 500 ] );
}
```

The `RuntimeException` is constructed from `$wp_error->get_error_message()`, which can contain raw internal strings — `"Error while inserting user data into the database"`, MySQL fragments, plugin-injected debug strings, or anything a third-party hook on `user_register` raises. Returned with a 500 to an anonymous caller, this is both an info-disclosure smell and a UX smell (users see internal text).

Secondary problem: the catch flattens every failure into the single error code `subscribe_failed` / `signup_failed`, so the client cannot branch on the underlying cause (e.g. "email exists" vs "blog assignment failed" vs "consent stamp failed").

## Findings

- `includes/class-orbit-rest-subscription.php:227-330` — `RuntimeException( $wp_error->get_error_message() )` thrown for each rollback path; catch on ~line 325 wraps in generic `subscribe_failed`.
- `includes/class-orbit-rest-signup.php:165-256` — same pattern, generic `signup_failed`.
- Surfaced by security-sentinel and simplicity-reviewer (finding #12) during multi-agent review.

## Proposed Solutions

**Option A — Custom carrier exception (recommended).** Introduce:

```php
class Orbit_RolledBack_Exception extends \RuntimeException {
    public \WP_Error $wp_error;
    public function __construct( \WP_Error $wp_error ) {
        $this->wp_error = $wp_error;
        parent::__construct( $wp_error->get_error_code() );
    }
}
```

Throw `new Orbit_RolledBack_Exception( $wp_error )` instead of `RuntimeException( $message )`. In the catch, log the inner WP_Error server-side (`error_log` with code + message + data), then return a clean translated, generic message to the client and preserve the original code so the client can branch:

```php
return new \WP_Error(
    $e->wp_error->get_error_code(),
    __( 'We could not complete your sign-up. Please try again.', 'orbit' ),
    [ 'status' => 500 ]
);
```

Effort: low (~30 LOC across two handlers + new class). Risk: low.

**Option B — Whitelist of known-safe codes.** Map known WP_Error codes to user-facing strings inline; everything else collapses to the generic message. Simpler, but loses the structured separation between server log and client response.

## Recommended Action

Option A. The custom-exception pattern is the standard PHP idiom for carrying a structured error across a `throw`/`catch` boundary, and aligning with this also fixes the dropped-error-code problem in one stroke. Pairs well with todo 130 (provisioning service extraction) — the new exception class lives next to that service.

## Technical Details

- The catch already calls `ROLLBACK`; the exception is purely a control-flow signal, so attaching the WP_Error to it is free.
- Log payload should include `$e->wp_error->get_error_code()`, `$e->wp_error->get_error_message()`, `$e->wp_error->get_error_data()`, request ID if available, and the calling REST route.
- Translated client-facing message should be a single sentence with no internal vocabulary ("provisioning", "rollback", "transaction").
- The client-facing code MUST be a stable identifier the JS layer can branch on — keep codes lowercase, snake_case, and document them next to the controller.

## Acceptance Criteria

- [ ] `Orbit_RolledBack_Exception` exists with a public `WP_Error $wp_error` property.
- [ ] Subscribe and signup catch blocks log the inner WP_Error server-side and return a translated generic message to the client.
- [ ] Returned WP_Error code reflects the original failure code (e.g. `existing_user_email`), not always `subscribe_failed` / `signup_failed`.
- [ ] No raw MySQL or internal-WordPress strings can reach the REST response body.
- [ ] PHPUnit test simulates a failure in each transactional step and asserts the response body contains only the generic message + the expected code.

## Work Log

- 2026-06-08: Surfaced during PR #26 multi-agent code review.

## Resources

- PR #26: https://github.com/makyrie/orbit/pull/26
- `includes/class-orbit-rest-subscription.php:227-330`
- `includes/class-orbit-rest-signup.php:165-256`
