# wp-native-auth (WordPress plugin)

Generic token-based authentication for native and headless WordPress consumers, exposed through the WordPress Abilities API.

**Current version:** `0.2.0`. Requires WordPress 6.9+ and PHP 8.1+.

## Abilities

The plugin registers nine abilities:

| Ability | Purpose |
|---|---|
| `wp-native/auth-login` | Authenticate credentials and create a device session |
| `wp-native/auth-continue-login` | Resume a policy challenge without resubmitting credentials |
| `wp-native/auth-refresh` | Atomically rotate a refresh token and issue a new access token |
| `wp-native/auth-logout` | Revoke the current device session |
| `wp-native/auth-me` | Return the authenticated user |
| `wp-native/auth-sessions` | List active device sessions |
| `wp-native/auth-revoke-session` | Revoke another device session |
| `wp-native/auth-browser-handoff` | Mint a one-time URL that establishes a browser session |
| `wp-native/auth-register` | Create an account and device session |

Clients discover and execute these through the standard Abilities REST surface:

```text
GET  /wp-json/wp-abilities/v1/abilities
POST /wp-json/wp-abilities/v1/abilities/wp-native/auth-login/run
```

Use [`wp-native-client`](../../packages/api-client/README.md) to select the correct HTTP method from each ability's annotations and manage authentication transport.

## Architecture

- Per-device refresh tokens stored in custom table
- Provider-agnostic policy challenges resume through short-lived, opaque, single-use continuations
- Refresh rotation is atomic: each refresh issues a new token and invalidates the prior one immediately
- Refresh-token replay revokes the complete token family
- Access TTL: 15 minutes
- Refresh TTL: 30 days (sliding — extended on every refresh)
- `device_id` is required (UUID v4) for all session-creating operations
- Password changes revoke every refresh session for the affected user
- Bearer tokens resolve the current WordPress user before protected abilities run

## Extension policy

The implementation contains no product-specific account policy. Consumer plugins can add membership checks, blocked-user policy, profile decoration, registration policy, and authentication challenges through the documented filters and challenge-policy registry.

See [`SCHEMAS.md`](SCHEMAS.md) for the authoritative input/output schemas, error codes, database shape, security behavior, and extension hooks.

## Verification

Run the dependency-free decision-logic test from this directory:

```bash
php tests/test-reuse-logic-standalone.php
```

The remaining tests extend `WP_UnitTestCase` and run through a WordPress plugin test harness. The repository does not currently ship its own PHPUnit bootstrap.
