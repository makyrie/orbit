---
status: pending
priority: p2
issue_id: "106"
tags: [code-review, twilio, security, PR-24]
dependencies: []
---

# Twilio signature validator only reads body_params — breaks for query-string params or JSON content type

## Problem Statement

`Orbit_Twilio::validate_webhook()` builds the signature payload from `$request->get_body_params()`:

```php
$params = $request->get_body_params();
ksort( $params );
$data = $expected_url;
foreach ( $params as $key => $value ) {
    $data .= $key . $value;
}
```

Two issues found by call-chain-verifier:

1. **Query-string params are missing.** Twilio's signature scheme requires that query-string params be already present in `$expected_url`. The signature passes for the current Programmable Messaging webhook (no query string, all params in body), but the moment Twilio adds a `MessageStatus` callback with query parameters, signature verification breaks.

2. **`get_body_params()` returns only `application/x-www-form-urlencoded` params.** If a request with `Content-Type: application/json` ever hit this route, `get_body_params()` returns empty and the signature would compute over just `$expected_url`. Validation would still pass for Twilio's standard webhook (form-encoded) but the path is incorrectly permissive.

Also: `$value` from `get_body_params()` may be an array under crafted input (e.g. `Body[]=foo&Body[]=bar`); `$data .= $key . $value` triggers a notice and uses literal `"Array"`. Signature mismatches so the request is rejected — safe but emits PHP notice every time.

## Proposed Solutions

**Option A — Handle both body+query, JSON, and array inputs:**

```php
public static function validate_webhook( $request, $expected_url ) {
    if ( ! defined( 'ORBIT_TWILIO_AUTH_TOKEN' ) ) {
        return false;
    }
    $signature = $request->get_header( 'X-Twilio-Signature' );
    if ( ! $signature ) {
        return false;
    }

    $params = $request->get_body_params();
    if ( empty( $params ) ) {
        // Fallback for JSON bodies — Twilio's standard webhook is form-encoded,
        // but other tools may submit JSON.
        $params = $request->get_json_params() ?: array();
    }

    ksort( $params );
    $data = $expected_url;
    foreach ( $params as $key => $value ) {
        if ( is_array( $value ) ) { return false; }
        $data .= $key . $value;
    }

    $expected = base64_encode( hash_hmac( 'sha1', $data, ORBIT_TWILIO_AUTH_TOKEN, true ) );
    return hash_equals( $expected, $signature );
}
```

For future routes that include query strings, callers must pass the FULL URL with query params already concatenated in `$expected_url`.

## Acceptance Criteria

- [ ] Validator handles array-typed body params (rejects rather than throwing notice).
- [ ] Test: array-form body param fails validation cleanly (no notice).
- [ ] Test: JSON body params validate correctly when authentic.
- [ ] Docblock notes that callers must include query string in `$expected_url` for future webhook routes.

## Work Log

- 2026-06-01: Identified during code review of PR #24 by call-chain-verifier, security-sentinel.

## Resources

- PR #24: https://github.com/makyrie/orbit/pull/24
- `includes/class-orbit-twilio.php:88-108`
