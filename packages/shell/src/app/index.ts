/**
 * App composition module barrel — wp-native-shell M5.4.
 *
 * Public API (SHELL.md M5.4):
 *   - <WPNativeApp config={...}/> top-level wrapper
 *   - <AuthGate/> (composable for consumers who don't use WPNativeApp)
 *   - Types: WPNativeConfig, WPNativeAppProps, WPNativeOnboardingConfig
 *
 * Brand identity is a consumer concern — not exposed by the shell.
 */

export { WPNativeApp } from './wp-native-app';
export { AuthGate } from './gate';

export type {
	WPNativeConfig,
	WPNativeAppProps,
	WPNativeOnboardingConfig,
} from './types';
export type { AuthGateProps } from './gate';
