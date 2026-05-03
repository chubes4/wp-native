/**
 * wp-native-shell — React Native app shell for wp-native.
 *
 * M5 surface — auth (M5.1), theme (M5.2), navigation (M5.3),
 * app composition (M5.4).
 * M6 surface — ability-driven screens (M6.1, M6.2, M6.3).
 */

export const PACKAGE_NAME = 'wp-native-shell' as const;

// ─── App composition (M5.4) ─────────────────────────────────────────────────

export { WPNativeApp, AuthGate } from './app';
export type {
	WPNativeConfig,
	WPNativeAppProps,
	WPNativeOnboardingConfig,
	AuthGateProps,
} from './app';

// ─── Auth (M5.1) ────────────────────────────────────────────────────────────

export { AuthProvider, useAuth } from './auth';
export type {
	AuthState,
	AuthActions,
	AuthMeUser,
	TokenStorageAdapter,
	WPNativeApiConfig,
	AuthProviderProps,
} from './auth';

// ─── Theme (M5.2) ───────────────────────────────────────────────────────────

export {
	ThemeProvider,
	useTheme,
	defaultThemeTokens,
	deepMergeTokens,
} from './theme';

export type { ThemeTokens, ThemeProviderProps } from './theme';

// ─── Navigation (M5.3 → expo-router rebase) ────────────────────────────────

export {
	DrawerContent,
	NavigationConfigProvider,
	useNavigationConfig,
	BrowserHandoffProvider,
	useBrowserHandoffConfig,
	useBrowserHandoff,
} from './navigation';

export type {
	DrawerContentProps,
	NavigationSection,
	WPNativeNavigationConfig,
	WPNativeBrowserHandoffConfig,
	BrowserHandoffHandler,
} from './navigation';

// ─── Screens (M6) ───────────────────────────────────────────────────────────

export { AbilityList } from './screens/ability-list';
export type {
	AbilityListAdapter,
	AbilityListItemProps,
	AbilityListProps,
} from './screens/ability-list-types';

export { AbilityDetail } from './screens/ability-detail';
export type {
	AbilityDetailAdapter,
	AbilityDetailProps,
	AbilityDetailRenderProps,
} from './screens/ability-detail-types';

export { SectionScreen, SectionPlaceholder } from './screens';
export type { SectionScreenProps } from './screens';

export { SectionDetailScreen } from './screens';
export type { SectionDetailScreenProps } from './screens';
