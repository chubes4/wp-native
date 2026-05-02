# wp-native — Roadmap

## Vision

An open-source npm package + WordPress plugin pair that lets a developer turn **any** modern WordPress site into a real React Native app, with one config file.

Not a no-code SaaS. Not a WebView wrapper. Not a per-site typed client. A **framework** — the Next.js of WordPress mobile apps.

## The principle

> If `wp-native` requires a per-site TypeScript wrapper to be useful, then it isn't a generic framework — it's a templating system that pretends to be one.

A real generic framework either works against any WordPress site with zero per-site code, or admits it's site-specific and stops claiming otherwise. **wp-native chooses option 1.**

## Architecture: abilities-first

wp-native is built on the [WordPress Abilities API](https://make.wordpress.org/core/?s=abilities+api) (core in 6.9, deepening in 7.0). The Abilities API is the universal, discoverable, self-describing tool surface for any WordPress site. Mobile apps are just another consumer of it.

```
┌──────────────────────────────────────────────────────────────────┐
│                      wp-native-shell                             │
│                                                                  │
│  Generic React Native screens that consume abilities BY NAME.    │
│  Config tells the shell which ability names map to which UI      │
│  slots — but the shell never imports a site-specific client.     │
└────────────────────────┬─────────────────────────────────────────┘
                         │
                         ▼
┌──────────────────────────────────────────────────────────────────┐
│                    wp-native-client (one client)                 │
│                                                                  │
│  • discoverAbilities()  → fetches catalog from /abilities        │
│  • execute(name, args)  → invokes any ability by name            │
│  • Auth transport       → token lifecycle (only hand-built piece)│
│  • Optional codegen     → types from ability JSON schemas        │
│                                                                  │
│  ONE client. No subclasses. No per-site wrappers.                │
│  Site-specific abilities are just strings + JSON args.           │
└────────────────────────┬─────────────────────────────────────────┘
                         │
                         ▼
┌──────────────────────────────────────────────────────────────────┐
│                   WordPress site                                 │
│                                                                  │
│  Abilities API (WP 6.9+ core)                                    │
│    ├── core abilities          (wp/post.*, wp/user.*)            │
│    ├── plugin abilities        (extrachill/*, woocommerce/*)     │
│    └── theme abilities         (whatever consumers register)     │
│                                                                  │
│  wp-native-auth plugin                                           │
│    └── token lifecycle  (auth bootstrap — predates discovery)    │
└──────────────────────────────────────────────────────────────────┘
```

## What the consumer config looks like

```ts
// extrachill.config.ts
export const config: WPNativeConfig = {
  api: {
    baseUrl: 'https://extrachill.com/wp-json',
    clientId: 'extrachill-app',
  },

  brand: {
    name: 'Extra Chill',
    welcomeMessage: 'Welcome to Extra Chill!',
  },

  // UI slots are mapped to ability names — strings, not imports
  navigation: {
    sections: [
      { id: 'feed',    label: 'Feed',    ability: 'wp/post.list' },
      { id: 'events',  label: 'Events',  ability: 'extrachill/event.calendar' },
      { id: 'artists', label: 'Artists', ability: 'extrachill/artist.list' },
      { id: 'forums',  label: 'Forums',  ability: 'extrachill/forum.topics' },
    ],
  },

  browserHandoff: {
    handoffHosts: ['extrachill.com', '*.extrachill.com'],
    excludeHosts: ['*.extrachill.link'],
  },

  onboarding: {
    enabled: true,
    ability: 'extrachill/user.complete-onboarding',
    screen: ExtraChillOnboardingScreen,
  },
};
```

That's the whole consumer surface. **Data, not code.** EC-specifics are ability names as strings.

## Non-goals (explicit)

- ❌ WebView wrappers
- ❌ No-code SaaS app builder
- ❌ wp-admin inside the app
- ❌ Per-site typed API client wrappers
- ❌ Locking consumers into a specific backend / hosting
- ❌ JavaScript without types — **TypeScript is mandatory across the entire codebase**

## Language: TypeScript, strict, no exceptions

Every package and every consumer code path is TypeScript with strict mode enabled. The repo's `tsconfig.base.json` turns on the full strict family — `noImplicitAny`, `noUncheckedIndexedAccess`, `exactOptionalPropertyTypes`, `useUnknownInCatchVariables`, `noImplicitReturns`, `noFallthroughCasesInSwitch`, `noUnusedLocals`, `noUnusedParameters`. No `.js` source files in `packages/*/src`.

Rationale: a framework whose entire job is to model a self-describing API surface (abilities) and feed it into typed React Native components only earns its keep if the type system is enforced end-to-end. JS-with-JSDoc is not an acceptable shortcut.

## v0.1 scope

**Goal:** A developer can write a config file, point it at any WordPress site running `wp-native-auth` + the Abilities API, and get a running native app with login + a feed driven by the ability they specified.

| # | Feature | Source |
|---|---------|--------|
| 1 | Token auth (login, refresh, logout, /me) | Forked from `extrachill-users` |
| 2 | Device-scoped session management | Same |
| 3 | App shell with drawer navigation | Extracted from `extrachill-app` |
| 4 | Theme system with consumer-supplied tokens | Generalized from `@extrachill/tokens` |
| 5 | Browser handoff for arbitrary host allowlist | Extracted from `extrachill-app` |
| 6 | Abilities discovery + `client.execute(name, args)` | NEW |
| 7 | Generic screens that render ability results | NEW |
| 8 | Config-driven `<WPNativeApp config={...}/>` wrapper | NEW |
| 9 | `wp-native-auth` WordPress plugin | Forked from `extrachill-users` |

### Explicitly NOT in v0.1

- WooCommerce integration (consumer-side, via abilities)
- Forums (bbPress / BuddyPress) — same
- Push notifications
- Native Gutenberg authoring
- Multisite-aware routing (Extra Chill-specific, post-v0.1)
- Codegen of TypeScript types from ability schemas (post-v0.1 nice-to-have)

## Milestones

| Milestone | Description | Estimate |
|-----------|-------------|----------|
| M1 | Repo setup, monorepo scaffold | ✅ done |
| M2 | Audit Extra Chill coupling, file extraction issues | ✅ done |
| M3 | `packages/api-client` — abilities discovery + execution + auth transport | ✅ done |
| M4 | `plugins/wp-native-auth` — token auth WordPress plugin | 2–3 sessions |
| M5 | `packages/shell` core — Auth + Theme + Drawer + BrowserHandoff providers | 2–3 sessions |
| M6 | `packages/shell` screens — generic ability-driven list/detail screens | 1–2 sessions |
| M7 | Migrate `extrachill-app` to consume `wp-native` | 1–2 sessions |
| M8 | Migrate other `@extrachill/api-client` consumers, retire the package | 1–2 sessions |

**Total: ~9–14 sessions to "Extra Chill running on wp-native, `@extrachill/api-client` retired."**

## Fate of `@extrachill/api-client`

**Retires fully.** Path A.

Today it's a fat package doing two jobs (universal WP plumbing + EC-specific routes). The universal plumbing moves to `wp-native-client`. The EC-specific routes don't get re-implemented — they become **abilities on the WordPress side** that any client consumes via `client.execute('extrachill/...')`.

Migration order:
1. `extrachill-app` → `wp-native-client` (M7)
2. `@extrachill/chat` → `wp-native-client` (M8)
3. EC theme blocks → `wp-native-client` (M8)
4. Delete `@extrachill/api-client` from npm and the codebase (M8)

This keeps EC route knowledge centralized **on the server**, where it belongs, instead of duplicated across a typed client that every consumer has to depend on.

## Distribution model

For now: **none.** Internal use only.

`extrachill-app` consumes `wp-native` packages via local `file:` deps during development and `git:` deps for committed state. No npm publishing, no docs site, no public launch.

When `wp-native` has been dogfooded on Extra Chill long enough that the framework/consumer seam feels right, distribution becomes a separate decision.

## Why React Native and not [other thing]

Briefly, since this comes up:

- **NativePHP** runs PHP-on-device with WebView UI. Different category — for standalone Laravel apps, not WordPress clients.
- **Capacitor / Cordova / WebView wrappers** are what AppPresser and MobiLoud do. They're not real native apps.
- **Flutter** has no WordPress-adjacent ecosystem and no path to share code with Gutenberg blocks.
- **React Native + Expo** aligns with where WordPress core JS is heading (Gutenberg, Calypso, Site Editor are all React). Consumer knowledge transfers. The `@wordpress/*` packages are React. Native bridges are mature.

The architectural choice is continuous with WordPress's existing direction.
