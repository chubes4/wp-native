/**
 * Theme barrel — public surface for wp-native-shell theme.
 *
 * @see packages/shell/SHELL.md § M5.2
 */

export { ThemeProvider, useTheme } from './context';
export type { ThemeProviderProps } from './context';
export { defaultThemeTokens } from './tokens';
export type { ThemeTokens } from './tokens';
export { deepMergeTokens } from './merge';
