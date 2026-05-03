/**
 * App composition module barrel — wp-native-shell M5.4.
 *
 * Public API:
 *   - <WPNativeApp config={...}>{children}</WPNativeApp> top-level wrapper
 *   - <AuthGate/> (composable for consumers who don't use WPNativeApp)
 *   - Types: WPNativeConfig, WPNativeAppProps
 *
 * Brand identity and onboarding gating are consumer concerns —
 * not exposed by the shell.
 */

export { WPNativeApp } from './wp-native-app';
export { AuthGate } from './gate';

export type { WPNativeConfig, WPNativeAppProps } from './types';
export type { AuthGateProps } from './gate';
