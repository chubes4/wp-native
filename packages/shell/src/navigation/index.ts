/**
 * Navigation module barrel — wp-native-shell.
 *
 * Public API (expo-router rebase, Slice B):
 *   - DrawerContent component (slot for expo-router Drawer)
 *   - NavigationConfigProvider context
 *   - BrowserHandoffProvider context
 *   - useBrowserHandoff() hook
 *   - Types: NavigationSection, WPNativeNavigationConfig,
 *            WPNativeBrowserHandoffConfig, BrowserHandoffHandler,
 *            DrawerContentProps
 */

// Types
export type {
  NavigationSection,
  WPNativeNavigationConfig,
} from './types';

// Navigation config context
export {
  NavigationConfigProvider,
  useNavigationConfig,
} from './config';

// DrawerContent (replaces DrawerShell)
export { DrawerContent, DrawerShell } from './drawer-content';
export type { DrawerContentProps, DrawerShellProps } from './drawer-content';

// Browser handoff
export { BrowserHandoffProvider, useBrowserHandoffConfig } from './browser-handoff';
export { useBrowserHandoff } from './handoff';
export type {
  WPNativeBrowserHandoffConfig,
  BrowserHandoffHandler,
} from './handoff';
