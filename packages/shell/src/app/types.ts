/**
 * Top-level config types for <WPNativeApp/>.
 *
 * Per SHELL.md the consumer-facing surface is one config object
 * + a few optional render props.
 *
 * Brand identity (app name, tagline, welcome message) is a consumer
 * concern — not a WP-core primitive. Consumers manage their own brand
 * strings inline or in their own modules.
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
 * Optional onboarding gate.
 *
 * When `enabled` is true, the shell routes the user to `screen` after
 * authentication until the consumer-supplied screen reports completion.
 * Completion is signaled by the screen calling the configured `ability`
 * via `client.execute()` and resolving successfully — the shell does not
 * own the contract for what "complete" means.
 */
export interface WPNativeOnboardingConfig {
	/** Whether the app gates entry on onboarding completion. */
	enabled: boolean;
	/** Ability name the consumer screen calls on completion. */
	ability: string;
	/** Consumer-supplied onboarding screen. */
	screen: ComponentType;
}

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

	/** Onboarding flow (optional). */
	onboarding?: WPNativeOnboardingConfig;
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
