/**
 * App composition module barrel — wp-native-shell M5.4.
 *
 * Public API (SHELL.md M5.4):
 *   - <WPNativeApp config={...}/> top-level wrapper
 *   - <BrandProvider/> + useBrand() (cross-cutting brand context)
 *   - <AuthGate/> (composable for consumers who don't use WPNativeApp)
 *   - Types: WPNativeConfig, WPNativeAppProps, WPNativeBrandConfig,
 *            WPNativeOnboardingConfig
 */

export { WPNativeApp } from './wp-native-app';
export { BrandProvider, useBrand } from './brand';
export { AuthGate } from './gate';

export type {
	WPNativeConfig,
	WPNativeAppProps,
	WPNativeBrandConfig,
	WPNativeOnboardingConfig,
	BrandContextValue,
} from './types';
export type { BrandProviderProps } from './brand';
export type { AuthGateProps } from './gate';
