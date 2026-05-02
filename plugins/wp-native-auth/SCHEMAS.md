# wp-native-auth — Ability Schemas (M4 Contract)

This document is the **authoritative contract** for every ability the `wp-native-auth` WordPress plugin registers. Implementations MUST match these schemas exactly. The wp-native-client side relies on them.

Lineage: forked from the token-auth subsystem of `extrachill-users` (`inc/auth-tokens/`). Generic — no Extra Chill specifics. No `community_blog_id`, no Turnstile, no multisite-specific `ec_get_blog_id()`. Those are EC concerns, not framework concerns.

## Constants

```php
WP_NATIVE_AUTH_ACCESS_TOKEN_TTL   = 15 * MINUTE_IN_SECONDS  // 900 seconds
WP_NATIVE_AUTH_REFRESH_TOKEN_TTL  = 30 * DAY_IN_SECONDS     // sliding, extended on refresh
WP_NATIVE_AUTH_REFRESH_RATE_LIMIT_SECONDS = 5
```

All timestamps in responses are ISO-8601 strings (e.g. `2026-05-02T19:30:00+00:00`) generated via `gmdate( 'c', $unix_ts )`. Inputs that need expiry semantics use Unix seconds where present.

## Database table

Table name: `{$wpdb->base_prefix}wp_native_auth_refresh_tokens` (network-wide via `base_prefix`, **not** per-blog `prefix`).

```sql
CREATE TABLE {$table_name} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  device_id char(36) NOT NULL,
  device_name varchar(191) NULL,
  refresh_token_hash char(64) NOT NULL,
  created_at datetime NOT NULL,
  last_used_at datetime NULL,
  expires_at datetime NOT NULL,
  revoked_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY user_device (user_id, device_id),
  KEY user_id (user_id),
  KEY expires_at (expires_at)
) {$charset_collate};
```

Hash algorithm: `hash( 'sha256', $token, false )` — 64 hex chars. Plaintext refresh tokens are returned to the client exactly once (issue + rotate) and never persisted.

## Common types referenced below

### `User` object

Returned in login/refresh/me responses.

```json
{
  "id": 42,
  "username": "chubes",
  "display_name": "Chris Huber",
  "email": "chubes@example.com",
  "avatar_url": "https://gravatar.com/...",
  "roles": ["administrator"],
  "registered_at": "2024-01-15T12:00:00+00:00"
}
```

The `roles` array is **the WordPress user's roles on the current blog**. Apps that need network-wide role info should use additional abilities (out of M4 scope).

### `TokenPair` object

Returned by every ability that creates or rotates a session.

```json
{
  "access_token": "eyJhbG...",
  "access_expires_at": "2026-05-02T19:45:00+00:00",
  "refresh_token": "x7Q2pK...",
  "refresh_expires_at": "2026-06-01T19:30:00+00:00"
}
```

### Error envelope

All abilities return `WP_Error` on failure, which the Abilities API translates to a standard error response:

```json
{
  "code": "invalid_credentials",
  "message": "Invalid username or password.",
  "data": { "status": 401 }
}
```

Error codes use snake_case. HTTP status codes match the semantic intent (401 for auth failures, 400 for validation, 429 for rate limits, 500 for server errors).

---

## Abilities

### `wp-native/auth-login`

**Category:** `wp-native-auth`
**Label:** Log in with username or email + password
**Description:** Authenticate a user by credentials and issue an access token + refresh token bound to a device.

#### Input schema

```json
{
  "type": "object",
  "required": ["identifier", "password", "device_id"],
  "additionalProperties": false,
  "properties": {
    "identifier": {
      "type": "string",
      "minLength": 1,
      "description": "Username or email address."
    },
    "password": {
      "type": "string",
      "minLength": 1,
      "description": "Plaintext password. Sent over HTTPS only."
    },
    "device_id": {
      "type": "string",
      "pattern": "^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-4[0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$",
      "description": "UUID v4 generated client-side and persisted per device."
    },
    "device_name": {
      "type": "string",
      "maxLength": 191,
      "description": "Optional human-readable device name (e.g. 'Chris's iPhone')."
    },
    "remember": {
      "type": "boolean",
      "default": false,
      "description": "Whether to extend cookie session length when set_cookie is also true."
    },
    "set_cookie": {
      "type": "boolean",
      "default": false,
      "description": "Whether to also set a WordPress browsing cookie. Mobile apps pass false; web clients calling same-origin pass true."
    }
  }
}
```

#### Output schema

```json
{
  "type": "object",
  "required": ["access_token", "access_expires_at", "refresh_token", "refresh_expires_at", "user"],
  "additionalProperties": false,
  "properties": {
    "access_token":       { "type": "string" },
    "access_expires_at":  { "type": "string", "format": "date-time" },
    "refresh_token":      { "type": "string" },
    "refresh_expires_at": { "type": "string", "format": "date-time" },
    "user":               { "$ref": "#/definitions/User" }
  }
}
```

#### Error codes

| Code | HTTP | Meaning |
|---|---|---|
| `invalid_credentials` | 401 | Bad identifier/password combination |
| `invalid_device_id` | 400 | `device_id` is not a valid UUID v4 |
| `user_blocked` | 403 | User account is suspended (extension hook — see "Extension points" below) |

---

### `wp-native/auth-refresh`

**Category:** `wp-native-auth`
**Label:** Refresh access token using a refresh token
**Description:** Validate the supplied refresh token, rotate it (issue a new one, invalidate the prior), and return a fresh access token. Sliding 30-day expiry.

#### Input schema

```json
{
  "type": "object",
  "required": ["refresh_token", "device_id"],
  "additionalProperties": false,
  "properties": {
    "refresh_token": { "type": "string", "minLength": 1 },
    "device_id":     { "type": "string", "pattern": "^[0-9a-fA-F]{8}-..." }
  }
}
```

#### Output schema

Same as `wp-native/auth-login` output (TokenPair + User).

#### Error codes

| Code | HTTP | Meaning |
|---|---|---|
| `invalid_refresh_token` | 401 | Token not found, hash mismatch, or revoked |
| `refresh_token_expired` | 401 | Token expired (past 30 days without use) |
| `invalid_device_id` | 400 | `device_id` not a valid UUID v4 |
| `rate_limited` | 429 | More than one refresh per 5 seconds for the same device |
| `invalid_user` | 500 | Underlying user record missing |

---

### `wp-native/auth-logout`

**Category:** `wp-native-auth`
**Label:** Revoke the refresh token for a device
**Description:** Mark the device's refresh token as revoked. Access tokens cannot be invalidated server-side (they're short-lived), but the refresh chain ends here.

#### Input schema

```json
{
  "type": "object",
  "required": ["device_id"],
  "additionalProperties": false,
  "properties": {
    "device_id": { "type": "string", "pattern": "^[0-9a-fA-F]{8}-..." }
  }
}
```

Authenticated via Bearer token. The `user_id` is derived from the bearer token, not the input.

#### Output schema

```json
{
  "type": "object",
  "required": ["revoked"],
  "additionalProperties": false,
  "properties": {
    "revoked": { "type": "boolean", "description": "True if a session was revoked, false if no matching device session existed." }
  }
}
```

#### Error codes

| Code | HTTP | Meaning |
|---|---|---|
| `not_authenticated` | 401 | No valid bearer token |
| `invalid_device_id` | 400 | `device_id` not a valid UUID v4 |

---

### `wp-native/auth-me`

**Category:** `wp-native-auth`
**Label:** Get the currently authenticated user
**Description:** Returns the user payload for whoever the bearer token authenticates.

#### Input schema

```json
{
  "type": "object",
  "additionalProperties": false,
  "properties": {}
}
```

(No input. Authenticated via Bearer token only.)

#### Output schema

```json
{
  "type": "object",
  "required": ["user"],
  "additionalProperties": false,
  "properties": {
    "user": { "$ref": "#/definitions/User" }
  }
}
```

#### Error codes

| Code | HTTP | Meaning |
|---|---|---|
| `not_authenticated` | 401 | No valid bearer token |

---

### `wp-native/auth-sessions`

**Category:** `wp-native-auth`
**Label:** List active device sessions for the current user
**Description:** Returns one row per device that has a non-revoked, non-expired refresh token. Useful for "manage your devices" UI.

#### Input schema

```json
{
  "type": "object",
  "additionalProperties": false,
  "properties": {}
}
```

(Authenticated via Bearer token.)

#### Output schema

```json
{
  "type": "object",
  "required": ["sessions"],
  "additionalProperties": false,
  "properties": {
    "sessions": {
      "type": "array",
      "items": {
        "type": "object",
        "required": ["device_id", "device_name", "created_at", "last_used_at", "expires_at", "current"],
        "properties": {
          "device_id":    { "type": "string" },
          "device_name":  { "type": ["string", "null"] },
          "created_at":   { "type": "string", "format": "date-time" },
          "last_used_at": { "type": ["string", "null"], "format": "date-time" },
          "expires_at":   { "type": "string", "format": "date-time" },
          "current":      { "type": "boolean", "description": "True if this is the device that made the request." }
        }
      }
    }
  }
}
```

---

### `wp-native/auth-revoke-session`

**Category:** `wp-native-auth`
**Label:** Revoke a specific device session
**Description:** Like `auth.logout`, but takes a target `device_id` rather than the bearer token's own device. Used to remotely sign out other devices.

#### Input schema

```json
{
  "type": "object",
  "required": ["device_id"],
  "additionalProperties": false,
  "properties": {
    "device_id": { "type": "string", "pattern": "^[0-9a-fA-F]{8}-..." }
  }
}
```

Same output and error shape as `auth.logout`.

---

## NOT in M4 (explicit out-of-scope)

These belong to the framework but ship later:

- ❌ **OAuth abilities** (`wp-native/auth.oauth.google`, etc.) — M4.5+ once the base flow is dogfooded
- ❌ **Password reset** — web-only flow, lives outside the framework
- ❌ **Two-Factor Authentication integration** — extension hook only in M4 (see "Extension points")
- ❌ **Browser handoff** — separate ability namespace later, not part of auth
- ❌ **Registration ability** — `wp-native/user.register` is a separate concern from auth (auth abilities are for *existing* users)

## Extension points (filters and actions)

The plugin MUST register these so consumers (like extrachill-users) can layer policy:

### Filters

```php
// Block specific users from logging in (return WP_Error to block).
apply_filters( 'wp_native_auth_pre_login', null, $user, $context );

// Decorate the User object returned in responses.
apply_filters( 'wp_native_auth_user_payload', $user_array, $wp_user, $context );

// Override access token TTL per user (e.g. shorter for admins).
apply_filters( 'wp_native_auth_access_token_ttl', WP_NATIVE_AUTH_ACCESS_TOKEN_TTL, $user_id );

// Override refresh token TTL per user.
apply_filters( 'wp_native_auth_refresh_token_ttl', WP_NATIVE_AUTH_REFRESH_TOKEN_TTL, $user_id );

// Pre-validate registration / login (Turnstile, IP block, etc.).
apply_filters( 'wp_native_auth_pre_authenticate', null, $identifier, $context );
```

### Actions

```php
// Fired after a successful login.
do_action( 'wp_native_auth_after_login', $user_id, $device_id, $token_pair );

// Fired after a successful refresh.
do_action( 'wp_native_auth_after_refresh', $user_id, $device_id, $token_pair );

// Fired after a logout / revoke.
do_action( 'wp_native_auth_after_logout', $user_id, $device_id );
```

extrachill-users would consume these to enforce community-blog membership, run Turnstile, integrate Two Factor, etc. — without wp-native-auth knowing any of that exists.

## Implementation notes for minions

1. **All abilities live in PHP files under `plugins/wp-native-auth/inc/abilities/`** — one file per ability.
2. **Ability registration uses `wp_register_ability()`** (the WP 6.9+ Abilities API). Each registration includes the schemas above, the execute callback, and the permission callback.
3. **Permission callbacks**: `auth.login` and `auth.refresh` are public; everything else requires a valid bearer token (delegate to a shared helper).
4. **Token generation**: access tokens should be opaque random strings (use `wp_generate_password( 64, true, true )`) for v0.1 — JWT comes later. Refresh tokens same shape.
5. **Bearer auth**: M4 must include a small request-time hook that reads `Authorization: Bearer <token>` and resolves it to a `WP_User` via `wp_set_current_user()`. This is the gateway for `is_user_logged_in()` to work in permission callbacks.
6. **Network-wide tables**: the refresh tokens table uses `$wpdb->base_prefix` so it's shared across all blogs in a multisite install.
7. **No EC dependencies**: this plugin must run cleanly on a vanilla WP install. No `ec_get_blog_id()`, no `extrachill_*` functions. Anything site-specific is delegated to the filters above.
