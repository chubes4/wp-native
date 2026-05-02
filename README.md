# wp-native

**Turn any WordPress site into a real native app. Open source. Token auth. No WebViews.**

`wp-native` is a React Native app shell + WordPress plugin pair. Drop in a config file, point it at a WordPress site running the `wp-native-auth` plugin, and you get a real native iOS/Android app — drawer navigation, token-based auth, browser handoff for web-only flows, design tokens, the lot.

Not a WebView wrapper. Not a SaaS. Not a no-code builder. A **framework** for WordPress mobile apps, the way Next.js is a framework for WordPress headless sites.

## Status

**Pre-alpha.** Active development. Currently being dogfooded as the foundation of [extrachill-app](https://github.com/Extra-Chill/extrachill-app). Not yet recommended for outside use.

## Architecture

`wp-native` is built on the [WordPress Abilities API](https://make.wordpress.org/core/) (core in WP 6.9+). The Abilities API is the universal, self-describing tool surface for any WordPress site. Mobile apps are just another consumer of it.

```
┌─────────────────────────────────────────────────────────────┐
│              Consumer App (React Native)                    │
│                                                             │
│   wp-native.config.ts        ← maps UI slots to abilities   │
│   <WPNativeApp config={...}/>                               │
└────────────────────┬────────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────────┐
│                  packages/shell                             │
│   App shell, drawer, auth provider, theme provider,         │
│   browser handoff, generic ability-driven screens           │
└─────┬───────────────────────────────────────┬───────────────┘
      │                                       │
      ▼                                       ▼
┌──────────────────┐               ┌──────────────────────────┐
│ packages/        │               │   plugins/               │
│ api-client       │ ◄── talks to ─►   wp-native-auth         │
│                  │   WordPress     │   (WP plugin)          │
│ Universal client │                 │                        │
│   • discovery    │                 │   Token auth, device   │
│   • execute()    │                 │   sessions, refresh    │
│   • auth         │                 │   rotation             │
│                  │                 │                        │
│ NO per-site      │                 │                        │
│ wrappers         │                 │                        │
└──────────────────┘                 └──────────────────────────┘
```

The client doesn't know what abilities exist on a given WordPress site — **it asks.** Adding a new endpoint on the server makes it instantly available in the app, zero client changes.

## Repo layout

```
wp-native/
├── packages/
│   ├── shell/          React Native app shell
│   ├── api-client/     Universal client (abilities discovery + execution)
│   └── theme/          Design token primitives
├── plugins/
│   └── wp-native-auth/ WordPress plugin (token auth)
├── examples/           Reference consumer apps
└── docs/               Documentation, including ROADMAP.md
```

## License

GPL-2.0-or-later
