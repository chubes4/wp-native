/**
 * wp-native-shell — React Native app shell for wp-native.
 *
 * M5.2: Theme — ThemeProvider, useTheme, ThemeTokens, defaultThemeTokens.
 * Other slices (auth, navigation) land in parallel PRs.
 */

export const PACKAGE_NAME = 'wp-native-shell' as const;

// ─── Theme (M5.2) ───────────────────────────────────────────────────────────

export {
	ThemeProvider,
	useTheme,
	defaultThemeTokens,
	deepMergeTokens,
} from './theme';

export type { ThemeTokens, ThemeProviderProps } from './theme';
