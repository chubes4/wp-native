# EC Abilities Audit (M7 prep)

Audit of which Extra Chill abilities currently exist on extrachill.com vs what `extrachill-app` and `@extrachill/api-client` consume. The gap drives M7 (the EC migration to wp-native).

**Snapshot date:** 2026-05-02
**Source of truth:** `wp eval 'wp_get_abilities()'` against the live extrachill.com install.

## Headline numbers

```
Total abilities registered on extrachill.com:     348
  ├── core/*                                        3
  ├── extrachill/* + extrachill-*                  64
  ├── datamachine/*                              ~280
  └── block-format-bridge/*                         3

Distinct REST endpoints called by @extrachill/api-client: 90
Distinct REST endpoints called by extrachill-app:          9
```

The mobile app's surface is **far narrower** than the full client — only auth + onboarding for now. M7 is much smaller than the api-client size suggests.

## Critical gap: no auth abilities

There are **zero auth abilities** registered on extrachill.com today. The auth endpoints (`extrachill/v1/auth/login`, `/refresh`, `/logout`, `/me`, `/google`, `/register`, `/browser-handoff`) exist as raw REST routes only.

This is **fine** — wp-native handles this differently:

- **M4 ships `wp-native/auth.*`** as the universal auth abilities (login, refresh, logout, me, sessions, revoke-session).
- **The EC bridge plugin** (proposed M4.5, see "Recommended next steps" below) hooks `extrachill-users` into M4's filter surface (`wp_native_auth_pre_login`, `wp_native_auth_user_payload`, etc.) to layer EC-specific policy:
  - Community-blog membership check (the existing `is_user_member_of_blog` gate)
  - User blocking (`extrachill_users_is_blocked`)
  - Profile URL decoration (`extrachill_get_user_profile_url`)
  - Turnstile (still web-only via `pre_authenticate` filter)
  - Two-Factor Authentication redirect (still web-only)

The extrachill-app then calls `wp-native/auth-login` (universal name) and the EC bridge transparently enforces EC policy. No EC-namespaced auth abilities needed.

## Endpoints used by extrachill-app today

```
api.auth.login              → extrachill/v1/auth/login          → maps to wp-native/auth-login
api.auth.logout             → extrachill/v1/auth/logout         → maps to wp-native/auth-logout
api.auth.me                 → extrachill/v1/auth/me             → maps to wp-native/auth-me
api.auth.register           → extrachill/v1/auth/register       → no equivalent in wp-native (M4 scope) ⚠
api.auth.loginWithGoogle    → extrachill/v1/auth/google         → no equivalent in wp-native (M4 scope) ⚠
api.auth.getOAuthConfig     → extrachill/v1/config/oauth        → no equivalent in wp-native (M4 scope) ⚠
api.auth.createBrowserHandoff → extrachill/v1/auth/browser-handoff → handoff hook in wp-native-shell M5.3 ✓

api.users.getOnboardingStatus → extrachill/v1/users/onboarding (GET)  → extrachill/get-onboarding-status ✓ EXISTS
api.users.submitOnboarding    → extrachill/v1/users/onboarding (POST) → extrachill/complete-onboarding ✓ EXISTS
```

Plus `transport.*` methods (clearAuth, hasTokens, initialize, setOnAuthFailure, setTokens) — these are client-side state, no server calls.

### Status by mapping

| App call | Server endpoint | wp-native equivalent | Action |
|---|---|---|---|
| `api.auth.login` | `extrachill/v1/auth/login` | `wp-native/auth-login` | M4 ✅ — bridge plugin enforces EC policy |
| `api.auth.logout` | `extrachill/v1/auth/logout` | `wp-native/auth-logout` | M4 ✅ |
| `api.auth.me` | `extrachill/v1/auth/me` | `wp-native/auth-me` | M4 ✅ — bridge plugin decorates user payload |
| `api.auth.register` | `extrachill/v1/auth/register` | ❌ NOT in wp-native M4 | **Decision needed** (see below) |
| `api.auth.loginWithGoogle` | `extrachill/v1/auth/google` | ❌ NOT in wp-native | OAuth deferred to post-M4 |
| `api.auth.getOAuthConfig` | `extrachill/v1/config/oauth` | ❌ NOT in wp-native | Same — OAuth deferred |
| `api.auth.createBrowserHandoff` | `extrachill/v1/auth/browser-handoff` | M5.3 hook + handoff ability TBD | Need to register `wp-native/auth-browser-handoff` |
| `api.users.getOnboardingStatus` | `extrachill/v1/users/onboarding` (GET) | `extrachill/get-onboarding-status` | ✅ EXISTS — call as ability today |
| `api.users.submitOnboarding` | `extrachill/v1/users/onboarding` (POST) | `extrachill/complete-onboarding` | ✅ EXISTS — call as ability today |

## Decisions needed before M7

### 1. Where does `register` live?

Two paths:

- **A. Add `wp-native/auth.register` to the wp-native-auth plugin.** Generic registration that the EC bridge enhances with Turnstile etc.
- **B. Register stays EC-specific** as `extrachill/auth.register` because EC's flow has Turnstile, community-blog provisioning, invite-token redemption, etc. The wp-native framework treats register as out-of-scope.

**Recommendation: B.** Registration is genuinely platform-specific (gates, captchas, flow choices). wp-native auth abilities cover the *post-account* lifecycle (login, refresh, sessions). Account creation is a consumer concern.

### 2. OAuth (Google, Apple)

EC currently calls `wp-native-app` Google OAuth directly. Two paths:

- **A. wp-native-auth ships OAuth abilities** (`wp-native/auth.oauth-google`, etc.) post-M4 as a small extension.
- **B. OAuth stays consumer-side** — EC ships `extrachill/auth.oauth-google` as its own ability.

**Recommendation: A, but later.** OAuth is reusable across consumers. Defer to post-v0.1 (after wp-native dogfoods on EC successfully).

### 3. Browser handoff

`api.auth.createBrowserHandoff` currently exists as a REST endpoint in extrachill-users. Two paths:

- **A. Promote to `wp-native/auth-browser-handoff`** ability registered by wp-native-auth.
- **B. Keep in extrachill-users** as `extrachill/auth.browser-handoff`.

**Recommendation: A.** Browser handoff is a generic native-app pattern (open web URL with a one-time session token). The EC implementation is already platform-agnostic — just needs to move into wp-native-auth as a 7th ability post-M4.

## Already-callable as abilities today

These app calls can migrate to `client.execute()` *immediately* — the abilities exist server-side:

```ts
// Onboarding flow can migrate today (no M4/M5/M6 dependency):
client.execute('extrachill/get-onboarding-status')
client.execute('extrachill/complete-onboarding', { ... })
client.execute('extrachill/validate-username', { username })
```

The full onboarding screen in `extrachill-app/app/onboarding.tsx` could be re-wired to call abilities rather than REST routes any time. Probably wait until M5 (AuthProvider) lands so the auth integration is uniform.

## Recommended sequencing

```
Now (parallel to M5 fleet):
  ─ Draft EC bridge plugin (consumes wp-native-auth M4 filters)
    → Lives in extrachill-users as a single new file inc/wp-native-bridge.php
    → Mostly hooks: pre_authenticate, pre_login, user_payload
    → Can be drafted now, merged when wp-native-auth gets installed on EC

Post-M5, pre-M7:
  ─ Add browser-handoff ability to wp-native-auth (small, 1 file)
  ─ Decide register vs OAuth strategy

M7 (the migration):
  ─ Install wp-native-auth + EC bridge on extrachill.com
  ─ Migrate extrachill-app auth calls to wp-native/auth.* via wp-native-client
  ─ Migrate onboarding to extrachill/complete-onboarding etc.
  ─ Keep extrachill/v1/auth/register REST route alive (web blocks still use it)
  ─ App calls wp-native/auth-login (uniform), web calls /v1/auth/register (EC-specific)
```

## Full inventory (reference)

### EC abilities registered today (64)

```
extrachill/analyze-url
extrachill/approve-artist-access
extrachill/change-user-email
extrachill/change-user-password
extrachill/clear-user-moderation
extrachill/complete-onboarding
extrachill/create-user
extrachill/delete-campaign
extrachill/drill-404-category
extrachill/generate-qr-code
extrachill/get-404-patterns
extrachill/get-404-summary
extrachill/get-404-top-ips
extrachill/get-404-top-urls
extrachill/get-analytics-summary
extrachill/get-attack-summary
extrachill/get-campaign
extrachill/get-event-attendance
extrachill/get-newsletter-settings
extrachill/get-onboarding-status
extrachill/get-seo-config
extrachill/get-seo-results
extrachill/get-subscriptions
extrachill/get-user-concert-stats
extrachill/get-user-moderation-status
extrachill/get-user-profile
extrachill/get-user-settings
extrachill/get-user-shows
extrachill/grant-lifetime-membership
extrachill/list-404-events
extrachill/list-artist-access-requests
extrachill/list-campaigns
extrachill/manage-team-member
extrachill/moderate-user
extrachill/multisite-search
extrachill/network-media-list
extrachill/network-media-upload
extrachill/ping-indexnow
extrachill/progress-story
extrachill/purge-404-events
extrachill/push-campaign
extrachill/reject-artist-access
extrachill/request-artist-access
extrachill/revoke-lifetime-membership
extrachill/run-seo-audit
extrachill-seo/add-redirect
extrachill-seo/delete-redirect
extrachill-seo/list-redirects
extrachill/send-welcome-email
extrachill/subscribe
extrachill/subscriber-status
extrachill/sync-subscribers
extrachill/sync-taxonomies
extrachill/sync-team-members
extrachill/taxonomy-post-counts
extrachill/toggle-event-mark
extrachill/track-analytics-event
extrachill/update-newsletter-settings
extrachill/update-seo-config
extrachill/update-subscriptions
extrachill/update-user-links
extrachill/update-user-profile
extrachill/update-user-settings
extrachill/validate-username
```

### Distinct REST endpoints called by @extrachill/api-client (90)

See `git log` of `extrachill-api-client/src/resources/`. Categories:

- **admin** (8): artist-access, artist-relationships, lifetime-membership, taxonomies sync, team-members
- **analytics** (5): click, events, link-page, meta, view
- **artists** (10): listing, profile, links, permissions, roster, socials, subscribe, subscribers, analytics
- **auth** (7): login, register, logout, me, refresh, google, browser-handoff
- **blog** (5): ai-adventure, band-name, image-voting, rapper-name, taxonomy-counts
- **community** (3): drafts, taxonomy-counts, upvote
- **events** (8): calendar, filters, geocode, submissions, upcoming-counts, venues
- **media + network-media** (3)
- **seo** (5): audit, audit/continue, audit/details, audit/status, config
- **shop** (12): orders, products, shipping, stripe-connect, taxonomy-counts
- **users** (12): users, artists, leaderboard, me/*, onboarding, search

Most of these correspond 1:1 to existing abilities (e.g. `seo/audit` REST → `extrachill/run-seo-audit` ability), but several have **no ability equivalent** and would need registration if/when ported. None of them are app-critical for v0.1.

### Endpoints used by extrachill-app today (9)

```
api.auth.login
api.auth.logout
api.auth.me
api.auth.register
api.auth.loginWithGoogle
api.auth.getOAuthConfig
api.auth.createBrowserHandoff
api.users.getOnboardingStatus
api.users.submitOnboarding
```

Plus client-side `transport.*` state methods (no server calls).
