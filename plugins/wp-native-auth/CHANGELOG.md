# Changelog

## 0.0.1 — 2026-05-02

Initial release.

### Abilities (8 total)

- `wp-native/auth-login` — username/email + password authentication
- `wp-native/auth-register` — `wp_create_user()`-backed registration
- `wp-native/auth-refresh` — token rotation with sliding TTL
- `wp-native/auth-logout` — per-device session revocation
- `wp-native/auth-me` — current user payload
- `wp-native/auth-sessions` — list active device sessions for the current user
- `wp-native/auth-revoke-session` — revoke a specific device session
- `wp-native/auth-browser-handoff` — mint one-time URL for web session handoff

### Extension hooks

Filters consumer plugins (e.g. `extrachill-users`'s `wp-native-bridge.php`) can hook to layer platform-specific policy:

- `wp_native_auth_pre_authenticate` — Turnstile / IP block / pre-flight gates
- `wp_native_auth_pre_login` — community-blog membership / user blocking
- `wp_native_auth_pre_register` — username generation overrides / invite token redemption
- `wp_native_auth_user_payload` — decorate User objects with custom fields
- `wp_native_auth_access_token_ttl` — per-user TTL overrides

Actions:

- `wp_native_auth_after_login`
- `wp_native_auth_after_refresh`
- `wp_native_auth_after_logout`
- `wp_native_auth_after_register`

### Database

Network-wide `{base_prefix}wp_native_auth_refresh_tokens` table installed on activation. Per-device refresh tokens with sliding 30-day TTL.

### Bearer auth

Hooks `determine_current_user` filter to resolve `Authorization: Bearer <token>` headers to user IDs via the access-token validator.
