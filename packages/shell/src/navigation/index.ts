/**
 * Navigation module barrel — wp-native-shell.
 *
 * Public API (SHELL.md M5.3):
 *   - DrawerShell component
 *   - NavigationConfigProvider context
 *   - useBrowserHandoff() hook
 *   - Types: NavigationSection, WPNativeNavigationConfig,
 *            WPNativeBrowserHandoffConfig, BrowserHandoffHandler
 */

// Types
export type {
  NavigationSection,
  WPNativeNavigationConfig,
} from './types';

// DrawerShell + context
export {
  DrawerShell,
  NavigationConfigProvider,
  useNavigationConfig,
} from './drawer';
export type { DrawerShellProps } from './drawer';

// Browser handoff
export { useBrowserHandoff } from './handoff';
export type {
  WPNativeBrowserHandoffConfig,
  BrowserHandoffHandler,
} from './handoff';
