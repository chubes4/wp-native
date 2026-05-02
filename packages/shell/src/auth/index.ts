/**
 * Auth slice barrel — re-exports the public API for wp-native-shell auth.
 *
 * M5.1 public surface:
 *   - AuthProvider component
 *   - useAuth() hook
 *   - Type exports: AuthState, AuthActions, AuthMeUser,
 *     TokenStorageAdapter, WPNativeApiConfig, AuthProviderProps
 */

// Provider + hook
export { AuthProvider, useAuth } from './context';

// Types
export type {
  AuthState,
  AuthActions,
  AuthMeUser,
  TokenStorageAdapter,
  WPNativeApiConfig,
  AuthProviderProps,
} from './types';
