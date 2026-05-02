# wp-native-shell — Public API Contract (M5)

This document is the **authoritative contract** for the React Native app shell. Everything implemented in `packages/shell/src/` must match this spec exactly. The downstream consumers — first `extrachill-app`, eventually any consumer — code against this surface.

If you're a minion implementing M5: this file is your source of truth. Don't deviate. If something genuinely needs a different shape, surface it as a question to the orchestrator instead of inventing.

## Layering

```
┌─────────────────────────────────────────────────────────┐
│   <WPNativeApp config={...}/>                           │  M5.4
│     │                                                   │
│     ├── <ThemeProvider/>      ── consumer tokens         │  M5.2
│     ├── <AuthProvider/>       ── token lifecycle         │  M5.1
│     └── <DrawerShell/>        ── nav + browser handoff   │  M5.3
│            └── <Outlet/>      ── consumer screens / M6   │
└─────────────────────────────────────────────────────────┘
                  │
                  ▼
       wp-native-client.WPNativeClient  (M3)
                  │
                  ▼
        WordPress + wp-native-auth      (M4)
```

## Top-level config shape

This is the consumer-facing config shape. `extrachill-app` and any future consumer instantiates a `WPNativeConfig`:

```ts
export interface WPNativeConfig {
  /** WordPress REST API connection. */
  api: WPNativeApiConfig;

  /** Visual + copy identity. */
  brand: WPNativeBrandConfig;

  /** Token storage adapter (consumer plugs in their RN storage). */
  tokenStorage: TokenStorageAdapter;

  /** Drawer navigation sections. */
  navigation: WPNativeNavigationConfig;

  /** Browser handoff for web-only flows (optional). */
  browserHandoff?: WPNativeBrowserHandoffConfig;

  /** Theme tokens (optional — falls back to built-in defaults). */
  theme?: Partial<ThemeTokens>;

  /** Onboarding flow (optional). */
  onboarding?: WPNativeOnboardingConfig;
}
```

Each section below specifies one of these.

---

## M5.1 — Auth

### `WPNativeApiConfig`

```ts
export interface WPNativeApiConfig {
  /** Base URL, e.g. "https://example.com/wp-json" — no trailing slash. */
  baseUrl: string;

  /** Optional client identifier sent on register / OAuth. */
  clientId?: string;

  /** Optional default headers (e.g. { "X-MyClient": "app" }). */
  defaultHeaders?: Record<string, string>;
}
```

### `TokenStorageAdapter`

The shell does NOT depend on a specific RN storage library. Consumers plug in their preferred adapter (expo-secure-store, AsyncStorage, MMKV, etc.).

```ts
export interface TokenStorageAdapter {
  /** Read persisted tokens. Return null if no session is stored. */
  load(): Promise<StoredTokens | null>;

  /** Persist tokens after login / refresh. */
  save(tokens: StoredTokens): Promise<void>;

  /** Wipe stored tokens on logout / auth failure. */
  clear(): Promise<void>;

  /** Return the persistent device id (UUID v4). Generate + persist on first call. */
  getDeviceId(): Promise<string>;
}
```

`StoredTokens` is re-exported from `wp-native-client`.

### `<AuthProvider/>`

Top-level provider. Wraps the children with auth context. Must be inside `<ThemeProvider/>` (theme is read by the auth UI).

```ts
export interface AuthProviderProps {
  /** API config — same as config.api. */
  api: WPNativeApiConfig;

  /** Storage adapter. */
  storage: TokenStorageAdapter;

  /** Called when auth fails irrecoverably (logout requested by user, refresh fail). */
  onAuthFailure?: () => void;

  children: ReactNode;
}

export const AuthProvider: FC<AuthProviderProps>;
```

Internally:
- Constructs `AuthFetchTransport` from `wp-native-client` wired to `storage`
- Constructs `WPNativeClient` wrapping the transport
- Calls `transport.initialize()` on mount
- Calls `client.discover()` after auth establishes (post-login or post-load if tokens present)

### `useAuth()` — hook

```ts
export interface AuthState {
  /** Current authenticated user, or null if logged out. */
  user: AuthMeUser | null;

  /** True until initial token-load + (if applicable) discovery completes. */
  isLoading: boolean;

  /** True if user is non-null. */
  isAuthenticated: boolean;

  /** True if last action returned 401 / session expired. */
  sessionExpired: boolean;
}

export interface AuthActions {
  /** Authenticate with username/email + password. Throws on failure. */
  login(identifier: string, password: string): Promise<void>;

  /** Revoke the current device session and clear local tokens. */
  logout(): Promise<void>;

  /** Force-refresh the access token via the refresh chain. Throws on failure. */
  refreshSession(): Promise<void>;

  /** Clear the sessionExpired flag (e.g. after user dismisses re-login modal). */
  clearSessionExpired(): void;

  /** Direct access to the underlying client for ability calls. */
  client: WPNativeClient;
}

export function useAuth(): AuthState & AuthActions;
```

### `AuthMeUser` shape

Mirror of the User payload from `wp-native/auth-me` per `plugins/wp-native-auth/SCHEMAS.md`:

```ts
export interface AuthMeUser {
  id: number;
  username: string;
  display_name: string;
  email: string;
  avatar_url: string;
  roles: string[];
  registered_at: string;  // ISO 8601
}
```

### Behavior

- On mount: load tokens from storage. If present, call `client.discover()` then `client.execute('wp-native/auth-me')` to populate `user`. If 401 on `/me`, call `transport.refreshAccessToken()` once via the standard 401-retry path; if still 401, treat as session expired.
- On `login(identifier, password)`: call `client.executeUnchecked('wp-native/auth-login', { identifier, password, device_id })`. On success, save tokens, call `discover()`, set `user`.
- On `logout()`: call `client.execute('wp-native/auth-logout', { device_id })`. Always clear local tokens regardless of server response.
- On 401 from any non-refresh request: AuthFetchTransport already handles single-shot retry; if that fails, sets `sessionExpired = true` and calls `onAuthFailure?.()`.

### Files

```
packages/shell/src/auth/
├── index.ts                  barrel
├── context.tsx               AuthProvider, AuthContext, useAuth
├── types.ts                  AuthState, AuthActions, AuthMeUser, TokenStorageAdapter
└── transport.ts              builds AuthFetchTransport from config + storage
```

---

## M5.2 — Theme

### `ThemeTokens` shape

```ts
export interface ThemeTokens {
  colors: {
    /** Primary brand color (used for links, primary buttons). */
    primary: string;
    /** Color used on top of `primary` (e.g. button text). */
    onPrimary: string;
    /** App background. */
    background: string;
    /** Surfaces above background (cards, sheets). */
    surface: string;
    /** Default text color. */
    text: string;
    /** Lower-emphasis text. */
    textMuted: string;
    /** Borders, dividers. */
    border: string;
    /** Error / destructive. */
    error: string;
    /** Success / confirmation. */
    success: string;
  };
  typography: {
    /** Default font family. */
    fontFamily: string;
    /** Bold variant family (defaults to fontFamily if unset). */
    fontFamilyBold?: string;
    /** Base font size in points. Other sizes scale relative to this. */
    fontSizeBase: number;
    /** { xs, sm, base, lg, xl, '2xl' } — RN points. */
    fontSizes: {
      xs: number;
      sm: number;
      base: number;
      lg: number;
      xl: number;
      '2xl': number;
    };
    /** Line-height multipliers, applied to fontSize. */
    lineHeights: {
      tight: number;
      normal: number;
      relaxed: number;
    };
  };
  spacing: {
    /** Base unit in points (e.g. 4). All spacing is a multiple. */
    unit: number;
    /** Named multiples: xs=1, sm=2, md=3, lg=4, xl=6, '2xl'=8 of unit. */
    xs: number;
    sm: number;
    md: number;
    lg: number;
    xl: number;
    '2xl': number;
  };
  radii: {
    none: 0;
    sm: number;
    md: number;
    lg: number;
    full: 9999;
  };
}
```

### Default tokens

The shell ships a built-in default theme so a consumer with no `theme` config still renders. Defaults aim for "neutral, readable, modern" — not branded:

```ts
export const defaultThemeTokens: ThemeTokens = {
  colors: {
    primary:    '#2563eb',  // blue-600
    onPrimary:  '#ffffff',
    background: '#ffffff',
    surface:    '#f8fafc',  // slate-50
    text:       '#0f172a',  // slate-900
    textMuted:  '#64748b',  // slate-500
    border:     '#e2e8f0',  // slate-200
    error:      '#dc2626',  // red-600
    success:    '#16a34a',  // green-600
  },
  typography: {
    fontFamily: 'System',
    fontSizeBase: 16,
    fontSizes: { xs: 12, sm: 14, base: 16, lg: 18, xl: 20, '2xl': 24 },
    lineHeights: { tight: 1.2, normal: 1.5, relaxed: 1.75 },
  },
  spacing: {
    unit: 4,
    xs: 4, sm: 8, md: 12, lg: 16, xl: 24, '2xl': 32,
  },
  radii: { none: 0, sm: 4, md: 8, lg: 16, full: 9999 },
};
```

Consumer-supplied `theme` is **deeply merged** into defaults — partial override is the common case (consumer overrides `colors.primary` only, keeps everything else).

### `<ThemeProvider/>`

```ts
export interface ThemeProviderProps {
  /** Partial override merged onto defaults. */
  tokens?: Partial<ThemeTokens>;
  children: ReactNode;
}

export const ThemeProvider: FC<ThemeProviderProps>;
```

### `useTheme()` — hook

```ts
export function useTheme(): ThemeTokens;  // fully-resolved (no Partial)
```

### Files

```
packages/shell/src/theme/
├── index.ts                  barrel
├── context.tsx               ThemeProvider, ThemeContext, useTheme
├── tokens.ts                 ThemeTokens type + defaultThemeTokens
└── merge.ts                  deepMergeTokens()
```

---

## M5.3 — DrawerShell + BrowserHandoff

### `WPNativeNavigationConfig`

```ts
export interface WPNativeNavigationConfig {
  /** Drawer sections — order is preserved. */
  sections: NavigationSection[];

  /** Optional default section id (used as initial route). Default: first section. */
  defaultSection?: string;
}

export interface NavigationSection {
  /** Stable identifier (used as route name). */
  id: string;

  /** Display label in the drawer. */
  label: string;

  /** Optional ability name. When set, the section renders a generic
   *  ability-driven list screen (M6). When unset, the section renders
   *  the consumer-supplied screen component (see `screen` below). */
  ability?: string;

  /** Consumer-supplied React component for this section. Overrides `ability`. */
  screen?: ComponentType;

  /** Optional capability check. Section is hidden if this returns false. */
  visibleWhen?: (auth: AuthState) => boolean;
}
```

For M5 the shell only needs to wire the drawer + render the section's `screen` component (or a placeholder if `ability` is set — M6 lands the generic ability screens).

### `<DrawerShell/>`

Built on `@react-navigation/drawer`. Reads `config.navigation` from context.

```ts
export interface DrawerShellProps {
  /** Children rendered above the drawer (rare — most apps use sections only). */
  header?: ReactNode;
  /** Children rendered below the drawer items (e.g. footer / logout button). */
  footer?: ReactNode;
}

export const DrawerShell: FC<DrawerShellProps>;
```

The drawer renders one item per `NavigationSection` whose `visibleWhen` returns true (or is absent). Tapping an item navigates to that section's screen.

### `WPNativeBrowserHandoffConfig`

```ts
export interface WPNativeBrowserHandoffConfig {
  /** Hosts that should be opened with a session-handoff token. Wildcards allowed (`*.example.com`). */
  handoffHosts: string[];

  /** Hosts to exclude even if they match handoffHosts (e.g. a short-link domain). */
  excludeHosts?: string[];

  /** Ability name used to mint the handoff token. Default: `wp-native/auth-browser-handoff`. */
  handoffAbility?: string;
}
```

### `useBrowserHandoff()` — hook

```ts
export interface BrowserHandoffHandler {
  /** Returns true if the URL was handled (opened in browser, possibly with handoff token).
   *  Returns false if the URL doesn't match any handoff host (caller should let RN handle it). */
  handle(url: string): Promise<boolean>;
}

export function useBrowserHandoff(): BrowserHandoffHandler;
```

For M5 the handoff ability is **optional** — if it's not registered on the server (or if `handoffAbility` resolution fails), fall back to opening the URL in the browser directly without a token. The hook should never throw.

### Files

```
packages/shell/src/navigation/
├── index.ts                  barrel
├── drawer.tsx                DrawerShell
├── types.ts                  NavigationSection, WPNativeNavigationConfig
└── handoff.ts                useBrowserHandoff hook + WPNativeBrowserHandoffConfig
```

---

## M5.4 — `<WPNativeApp/>` (orchestrator-owned, NOT in fleet)

This is the top-level wrapper that composes everything. The orchestrator builds it after the M5 fleet lands.

```ts
export interface WPNativeAppProps {
  config: WPNativeConfig;
  /** Optional fallback rendered while auth is loading. */
  loading?: ReactNode;
  /** Optional screen rendered when the user is logged out. */
  loginScreen?: ComponentType;
}

export const WPNativeApp: FC<WPNativeAppProps>;
```

Internally:
```tsx
<ThemeProvider tokens={config.theme}>
  <AuthProvider api={config.api} storage={config.tokenStorage} onAuthFailure={...}>
    <NavigationConfigProvider config={config.navigation}>
      <BrowserHandoffProvider config={config.browserHandoff}>
        <AuthGate
          loading={loading}
          loginScreen={loginScreen}
        >
          <DrawerShell />
        </AuthGate>
      </BrowserHandoffProvider>
    </NavigationConfigProvider>
  </AuthProvider>
</ThemeProvider>
```

---

## Brand config (cross-cutting)

```ts
export interface WPNativeBrandConfig {
  /** App display name (e.g. "Extra Chill"). */
  name: string;

  /** Optional tagline. */
  tagline?: string;

  /** Optional welcome message used by default screens. */
  welcomeMessage?: string;
}
```

`useBrand()` hook returns this; it's a simple value provided alongside theme.

---

## Onboarding config (cross-cutting, optional)

```ts
export interface WPNativeOnboardingConfig {
  /** Whether the app gates entry on onboarding completion. */
  enabled: boolean;

  /** Ability name to call on completion (e.g. consumer-defined). */
  ability: string;

  /** Consumer-supplied onboarding screen component. */
  screen: ComponentType;
}
```

For M5 we register the onboarding hook but the gate logic is deferred to M5.4. Minions need only expose the type.

---

## Implementation rules for minions

1. **TypeScript strict mode**. The repo's `tsconfig.base.json` enforces full strict — `exactOptionalPropertyTypes`, `noUncheckedIndexedAccess`, `useUnknownInCatchVariables`. Do not relax it.
2. **No platform-specific imports outside the right slice**. M5.1 may import from `expo-secure-store`-style adapters — but only via the `TokenStorageAdapter` interface, not directly. M5.3 imports from `@react-navigation/drawer`. M5.2 imports from nothing platform-specific.
3. **All public types exported from `src/index.ts`** at the package level. Each subdirectory has its own `index.ts` barrel.
4. **No `any`**. Use `unknown` + narrowing, or proper types. Catch variables typed `unknown`.
5. **`react`, `react-native`, `expo-router`, and `wp-native-client`** are peer deps already declared in `package.json`. Add `@react-navigation/drawer` as a peer dep when M5.3 lands.
6. **Verify**: `npx tsc -b` from repo root must exit 0 after your changes.

## Definition of done (per slice)

Each minion's PR is done when:
- [ ] All files listed in their slice's "Files" section exist
- [ ] All exported types match this contract verbatim (names, shapes, optionality)
- [ ] All exported hook/component signatures match this contract
- [ ] `npx tsc -b` exits 0
- [ ] No `any` in the slice's source files
- [ ] PR opened on `chubes4/wp-native`, NOT merged
- [ ] `<@1493317298151489577>` mentioned in the final message
