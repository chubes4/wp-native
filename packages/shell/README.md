# wp-native-shell

React Native app shell for [wp-native](../../README.md).

**Status:** scaffold only. Implementation lands in M5–M6 (see project roadmap).

## What this package will provide

- `<WPNativeApp config={...}/>` — top-level shell wrapper
- `<AuthProvider/>` — token lifecycle, refresh rotation, device sessions
- `<ThemeProvider tokens={...}/>` — consumer-supplied design tokens
- `<DrawerShell menu={...}/>` — drawer navigation
- `useBrowserHandoff(hosts)` — web fallback for arbitrary host allowlists
- `<PostList/>` / `<PostDetail/>` — REST-driven generic screens
- `OAuthAdapter` interface — pluggable Google / Apple / etc.
