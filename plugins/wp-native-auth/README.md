# wp-native-auth (WordPress plugin)

Token-based authentication for WordPress, built for native app consumers.

**Status:** scaffold only. Implementation lands in M4 (see project roadmap).

## What this plugin will provide

- `POST /wp-json/wp-native/v1/auth/login` — username/email + password → access + refresh tokens
- `POST /wp-json/wp-native/v1/auth/refresh` — sliding 30-day refresh token rotation
- `POST /wp-json/wp-native/v1/auth/logout` — per-device session revoke
- `GET  /wp-json/wp-native/v1/auth/me` — current user payload
- `POST /wp-json/wp-native/v1/auth/register` — account creation (configurable)
- `POST /wp-json/wp-native/v1/auth/oauth/{provider}` — OAuth token exchange (Google, Apple, etc.)
- `POST /wp-json/wp-native/v1/auth/browser-handoff` — one-time URL for web session establishment

## Architecture

- Per-device refresh tokens stored in custom table
- Provider-agnostic policy challenges resume through short-lived, opaque, single-use continuations
- Refresh rotation: each `/refresh` issues a new refresh token and invalidates the prior one immediately
- Access TTL: 15 minutes
- Refresh TTL: 30 days (sliding — extended on every refresh)
- `device_id` required (UUID v4) on all session-creating endpoints

The implementation is generic and exposes policy concerns through extension hooks.
