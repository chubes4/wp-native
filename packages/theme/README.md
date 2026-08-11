# wp-native-theme

Design token primitives for [wp-native](../../README.md).

**Status:** private placeholder package. The implemented `ThemeProvider`, `useTheme()`, token types, defaults, and merge helpers currently ship from [`wp-native-shell`](../shell/README.md).

This package reserves the standalone `wp-native-theme` boundary in case the neutral token primitives need to be consumed without React Native shell dependencies. It currently exports only `PACKAGE_NAME` and should not be installed by consumers.

Use these exports from `wp-native-shell` instead:

- `ThemeTokens`
- `defaultThemeTokens`
- `deepMergeTokens()`
- `<ThemeProvider tokens={...}/>`
- `useTheme()`
