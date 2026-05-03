/**
 * Top-level config types for <WPNativeApp/>.
 *
 * Per SHELL.md the consumer-facing surface is one config object
 * + a few optional render props.
 *
 * Brand identity (app name, tagline, welcome message) is a consumer
 * concern — not a WP-core primitive. Consumers manage their own brand
 * strings inline or in their own modules.
 *
 * Onboarding flow is also a consumer concern — what "onboarding" means
 * (username selection, profile completeness, terms acceptance, etc.)
 * is entirely platform-specific. Consumers gate their own onboarding
 * by wrapping the children they pass to <WPNativeApp/> in their own
 * auth-aware component.
 */

import type { ComponentType, ReactNode } from 'react';
import type {
	AuthState,
	TokenStorageAdapter,
	WPNativeApiConfig,
} from '../auth';
import type { ThemeTokens } from '../theme';
import type {
	WPNativeBrowserHandoffConfig,
	WPNativeNavigationConfig,
} from '../navigation';

/**
 * Top-level config object passed to `<WPNativeApp/>`.
 */
export interface WPNativeConfig {
	/** WordPress REST API connection. */
	api: WPNativeApiConfig;

	/** Token storage adapter — consumer plugs in their RN storage. */
	tokenStorage: TokenStorageAdapter;

	/** Drawer navigation sections. */
	navigation: WPNativeNavigationConfig;

	/** Browser handoff allowlist (optional). */
	browserHandoff?: WPNativeBrowserHandoffConfig;

	/** Theme tokens (optional — falls back to built-in defaults). */
	theme?: Partial<ThemeTokens>;
}

/**
 * Props for the top-level `<WPNativeApp/>` wrapper.
 *
 * After the expo-router rebase (EXPO-ROUTER-REBASE.md Slice C),
 * `children` is required — consumers mount their own expo-router
 * `<Slot/>` / `<Stack/>` / `<Drawer/>` inside `<WPNativeApp/>`.
 */
export interface WPNativeAppProps {
	config: WPNativeConfig;
	/** Optional fallback rendered while initial auth state loads. */
	loading?: ReactNode;
	/**
	 * Component rendered when the user is logged out.
	 *
	 * Consumer-owned. Receives no props — uses `useAuth()` to call
	 * `login()` / `loginWithGoogle()` etc.
	 */
	loginScreen?: ComponentType;
	/**
	 * Consumer-supplied navigation tree.
	 *
	 * Typically an expo-router `<Slot/>` or `<Stack/>`.
	 * Rendered inside the AuthGate once the user is authenticated.
	 */
	children: ReactNode;
}

/** Re-export for callsite convenience. */
export type { AuthState };
