# wp-native — Roadmap

## Vision

An open-source npm package + WordPress plugin pair that lets a developer turn any modern WordPress site into a real React Native app in a weekend, with one config file.

Not a no-code SaaS. Not a WebView wrapper. A **framework** — the Next.js of WordPress mobile apps.

## Non-goals (explicit)

- ❌ WebView wrappers
- ❌ No-code SaaS app builder
- ❌ wp-admin inside the app
- ❌ Locking consumers into a specific backend / hosting

## v0.1 scope

**Goal:** A developer can configure a consumer app, point it at any WordPress site that has `wp-native-auth` installed, and get a running native app with login + a feed of posts.

| # | Feature | Source |
|---|---------|--------|
| 1 | Token auth (login, refresh, logout, /me) | Extracted from `extrachill-users` |
| 2 | Device-scoped session management | Same |
| 3 | App shell with drawer navigation | Extracted from `extrachill-app` |
| 4 | Theme system with consumer-supplied tokens | Generalized from `@extrachill/tokens` |
| 5 | Browser handoff for arbitrary host allowlist | Extracted from `extrachill-app` |
| 6 | Generic `<PostList/>` + `<PostDetail/>` | NEW (built on `wp/v2/posts`) |
| 7 | Config-driven `WPNativeApp` wrapper | NEW |
| 8 | `wp-native-auth` WordPress plugin | Forked from `extrachill-users` |

### Explicitly NOT in v0.1

- WooCommerce integration
- Forums (bbPress / BuddyPress)
- Push notifications
- Native Gutenberg authoring
- Multisite-aware routing (Extra Chill-specific, post-v0.1)
- AI / chat features

## Milestones

| Milestone | Description | Estimate |
|-----------|-------------|----------|
| M1 | Repo setup, monorepo scaffold | ✅ done |
| M2 | Audit Extra Chill coupling, file extraction issues | 1 session |
| M3 | `packages/api-client` — fork from `@extrachill/api-client`, strip EC routes | 1–2 sessions |
| M4 | `plugins/wp-native-auth` — fork token routes from `extrachill-users` | 2–3 sessions |
| M5 | `packages/shell` core — Auth + Theme + Drawer + BrowserHandoff providers | 2–3 sessions |
| M6 | `packages/shell` screens — generic `<PostList/>` / `<PostDetail/>` | 1–2 sessions |
| M7 | Migrate `extrachill-app` to consume `wp-native` | 1–2 sessions |

**Total: ~9–13 sessions to "Extra Chill running on wp-native"**

## Distribution model

For now: **none.** Internal use only.

`extrachill-app` consumes `wp-native` packages via local `file:` deps during development and `git:` deps for committed state. No npm publishing, no docs site, no public launch.

When `wp-native` has been dogfooded on Extra Chill long enough that the framework/consumer seam feels right, distribution becomes a separate decision.
