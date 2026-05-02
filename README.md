# wp-native

**Turn any WordPress site into a real native app. Open source. Token auth. No WebViews.**

`wp-native` is a React Native app shell + WordPress plugin pair. Drop in a config file, point it at a WordPress site running the `wp-native-auth` plugin, and you get a real native iOS/Android app — drawer navigation, token-based auth, browser handoff for web-only flows, design tokens, the lot.

Not a WebView wrapper. Not a SaaS. Not a no-code builder. A **framework** for WordPress mobile apps, the way Next.js is a framework for WordPress headless sites.

## Status

**Pre-alpha.** Active development. Currently being dogfooded as the foundation of [extrachill-app](https://github.com/Extra-Chill/extrachill-app). Not yet recommended for outside use.

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                    Consumer App (React Native)             │
│                                                            │
│   wp-native.config.ts                                      │
│   <WPNativeApp config={...}/>                              │
└────────────────────┬───────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────────┐
│                  packages/shell                             │
│   App shell, drawer, auth provider, theme provider,         │
│   browser handoff, generic post screens                     │
└─────┬───────────────────────────────────────┬───────────────┘
      │                                       │
      ▼                                       ▼
┌──────────────────┐               ┌──────────────────────────┐
│ packages/        │               │   plugins/               │
│ api-client       │ ◄── talks to ─►   wp-native-auth         │
│                  │   WordPress     │   (WP plugin)          │
│ Generic typed    │                 │                        │
│ WP REST client   │                 │   Token auth, device   │
│ + auth transport │                 │   sessions, refresh    │
│                  │                 │   rotation             │
└──────────────────┘                 └──────────────────────────┘
```

## Repo layout

```
wp-native/
├── packages/
│   ├── shell/          React Native app shell
│   ├── api-client/     Generic WordPress REST client
│   └── theme/          Design token primitives
├── plugins/
│   └── wp-native-auth/ WordPress plugin (token auth)
├── examples/           Reference consumer apps
└── docs/               Documentation
```

## License

GPL-2.0-or-later
