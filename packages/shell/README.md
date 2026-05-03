# wp-native-shell

React Native app shell for [wp-native](https://github.com/chubes4/wp-native). Providers, auth gate, drawer content slot, and ability-driven screens — built for expo-router.

## Install

```bash
npm install wp-native-shell wp-native-client
```

**Peer dependencies:**

- `react` >= 19
- `react-native` >= 0.80
- `expo-router` >= 6
- `react-native-gesture-handler` >= 2
- `wp-native-client` (any version)

Your WordPress site needs the [wp-native-auth](https://github.com/chubes4/wp-native/tree/main/plugins/wp-native-auth) plugin installed.

## Quick start

Mount `<WPNativeApp>` in your root layout. It provides auth, theme, navigation config, and browser handoff contexts, then renders your expo-router `<Slot/>` inside an auth gate.

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

## Public API summary

### Components

- **`<WPNativeApp config={...}>{children}</WPNativeApp>`** — top-level provider stack + auth gate. Wraps children with theme, auth, navigation, and browser handoff contexts.
- **`<AuthGate>`** — renders loading / login / authenticated states based on auth context.
- **`<DrawerContent>`** — drop-in `drawerContent` slot for expo-router's `<Drawer>`. Reads sections from `useNavigationConfig()`.
- **`<SectionScreen sectionId="...">`** — renders a section's screen based on its config (consumer screen, ability list, or placeholder).
- **`<SectionDetailScreen sectionId="..." id="...">`** — renders a section's detail view using the configured `detailAbility` + `detailAdapter`.
- **`<AbilityList ability="..." adapter={...}>`** — generic ability-driven list with pagination, pull-to-refresh, and detail navigation.
- **`<AbilityDetail ability="..." id={...} adapter={...}>`** — generic ability-driven detail view.

### Hooks

- **`useAuth()`** — auth state (`user`, `isAuthenticated`, `isLoading`, `sessionExpired`) + actions (`login()`, `logout()`, `refreshSession()`, `client`).
- **`useTheme()`** — fully-resolved `ThemeTokens` (colors, typography, spacing, radii).
- **`useNavigationConfig()`** — the consumer's `WPNativeNavigationConfig` from context.
- **`useBrowserHandoff()`** — `{ handle(url): Promise<boolean> }` for opening URLs with a session-handoff token.

## Full contract

The authoritative API surface, types, and behavior spec lives in [SHELL.md](https://github.com/chubes4/wp-native/blob/main/packages/shell/SHELL.md). See also:

- [SCREENS.md](https://github.com/chubes4/wp-native/blob/main/packages/shell/SCREENS.md) — ability-driven screen adapters
- [EXPO-ROUTER-REBASE.md](https://github.com/chubes4/wp-native/blob/main/packages/shell/EXPO-ROUTER-REBASE.md) — architecture decision record

## License

GPL-2.0-or-later — [github.com/chubes4/wp-native](https://github.com/chubes4/wp-native)
