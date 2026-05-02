# wp-native-theme

Design token primitives for [wp-native](../../README.md).

**Status:** scaffold only. Implementation lands in M5 (see project roadmap).

## What this package will provide

- `Tokens` interface — the canonical token shape consumers fill in
- `<ThemeProvider tokens={...}/>` — RN context provider
- `useTheme()` — hook for accessing resolved tokens
- Adapter helpers for `@wordpress/*` token packages
