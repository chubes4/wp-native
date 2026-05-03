# wp-native

**Turn any WordPress site into a real native app.** An open-source React Native app shell + WordPress plugin pair built on the [Abilities API](https://make.wordpress.org/core/). Drop in a config, point it at a WordPress site running the `wp-native-auth` plugin, and get a real native iOS/Android app — drawer navigation, token-based auth, browser handoff, design tokens, ability-driven screens. Not a WebView wrapper. Not a SaaS. Not a no-code builder. A **framework** for WordPress mobile apps.

## Status

**Pre-alpha.** Single consumer: [extrachill-app](https://github.com/Extra-Chill/extrachill-app). The v0.1.0 surface (`wp-native-shell@0.1.0`, `wp-native-client@0.0.1`) is feature-complete for its scope but has not been verified on a real device yet. The API is not stable — expect breaking changes before 1.0.

## Quick install

```bash
# React Native app (the common case)
npm install wp-native-shell wp-native-client

# Gutenberg blocks or Node scripts (no React Native)
npm install wp-native-client
```

**Peer dependencies** (for `wp-native-shell`):

- `react` >= 19
- `react-native` >= 0.80
- `expo-router` >= 6
- `react-native-gesture-handler` >= 2

Your WordPress site needs the **wp-native-auth** plugin installed and the Abilities API available (WordPress 7.0+).

## Hello world

`wp-native-shell` provides context providers and an auth gate. Your consumer app owns the navigation via expo-router's filesystem routing.

```tsx
// app/_layout.tsx
import { Slot } from 'expo-router';
import { WPNativeApp } from 'wp-native-shell';
import type { WPNativeConfig } from 'wp-native-shell';

const config: WPNativeConfig = {
  api: {
    baseUrl: 'https://example.com/wp-json',
  },
  tokenStorage: {
    // Plug in your RN storage adapter (expo-secure-store, MMKV, etc.)
    load: async () => null,
    save: async () => {},
    clear: async () => {},
    getDeviceId: async () => 'your-uuid-v4',
  },
  navigation: {
    sections: [
      { id: 'feed', label: 'Feed', ability: 'wp/post.list' },
    ],
  },
};

export default function RootLayout() {
  return (
    <WPNativeApp config={config} loginScreen={LoginScreen}>
      <Slot />
    </WPNativeApp>
  );
}
```

`<WPNativeApp>` composes (outer to inner): `ThemeProvider` > `AuthProvider` > `NavigationConfigProvider` > `BrowserHandoffProvider` > `AuthGate` > `{children}`. The auth gate renders your `loginScreen` when logged out, a loading fallback during token initialization, and `children` (your expo-router `<Slot/>`) when authenticated.

The consumer mounts the drawer and section screens via expo-router's filesystem — see the [shell contract](https://github.com/chubes4/wp-native/blob/main/packages/shell/SHELL.md) for the full pattern.

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│              Consumer App (React Native + expo-router)      │
│                                                             │
│   <WPNativeApp config={...}>{<Slot/>}</WPNativeApp>         │
│   Consumer owns routes, drawer layout, screens              │
└────────────────────┬────────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────────┐
│                  wp-native-shell                            │
│   Context providers (auth, theme, navigation, handoff),     │
│   AuthGate, DrawerContent slot, generic ability-driven      │
│   screens (AbilityList, AbilityDetail, SectionScreen)       │
└─────┬───────────────────────────────────────┬───────────────┘
      │                                       │
      ▼                                       ▼
┌──────────────────┐               ┌──────────────────────────┐
│ wp-native-client │               │   wp-native-auth         │
│                  │ ◄── talks to ─►   (WordPress plugin)     │
│ Universal client │   WordPress   │                          │
│   • discover()   │               │   8 abilities:           │
│   • execute()    │               │   auth-login, register,  │
│   • auth         │               │   refresh, logout, me,   │
│                  │               │   sessions, revoke,      │
│ Three transports │               │   browser-handoff        │
│   • FetchTransport               │                          │
│   • AuthFetchTransport           │   Token lifecycle,       │
│   • WpApiFetchTransport          │   device sessions,       │
│     (Gutenberg)  │               │   refresh rotation       │
└──────────────────┘               └──────────────────────────┘
```

The client doesn't know what abilities exist on a given WordPress site — **it asks.** `client.discover()` fetches the site's ability catalog. `client.execute('ability-name', args)` invokes any ability by name. Adding a new ability on the server makes it instantly available in the app, zero client changes.

## Project layout

```
wp-native/
├── packages/
│   ├── shell/          wp-native-shell — React Native app shell
│   ├── api-client/     wp-native-client — universal abilities client
│   ├── meta/           wp-native — meta package (redirects to the real ones)
│   └── theme/          Design token primitives
├── plugins/
│   └── wp-native-auth/ WordPress plugin — token auth (8 abilities)
└── docs/               Roadmap + audit docs
```

## Documentation

The in-repo contract files are the deep-dive material for each topic:

| Document | What it covers |
|---|---|
| [SHELL.md](https://github.com/chubes4/wp-native/blob/main/packages/shell/SHELL.md) | Full shell surface — auth, theme, navigation, app composition, browser handoff |
| [SCREENS.md](https://github.com/chubes4/wp-native/blob/main/packages/shell/SCREENS.md) | Generic ability-driven screens — `AbilityList`, `AbilityDetail`, adapters |
| [EXPO-ROUTER-REBASE.md](https://github.com/chubes4/wp-native/blob/main/packages/shell/EXPO-ROUTER-REBASE.md) | Architecture decision record for the expo-router migration |
| [SCHEMAS.md](https://github.com/chubes4/wp-native/blob/main/plugins/wp-native-auth/SCHEMAS.md) | All 8 auth abilities — input/output schemas, error codes, extension hooks |
| [ROADMAP.md](https://github.com/chubes4/wp-native/blob/main/docs/ROADMAP.md) | Milestone tracking + architectural principles |
| [EC-ABILITIES-AUDIT.md](https://github.com/chubes4/wp-native/blob/main/docs/EC-ABILITIES-AUDIT.md) | Extra Chill dogfood audit — ability inventory + migration plan |

## Compatibility

| Dependency | Minimum version |
|---|---|
| WordPress | 7.0+ (Abilities API) |
| React | >= 19 |
| React Native | >= 0.80 |
| expo-router | >= 6 |
| react-native-gesture-handler | >= 2 |

## License

GPL-2.0-or-later
