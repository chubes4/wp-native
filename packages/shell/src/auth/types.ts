/**
 * Auth types for wp-native-shell.
 *
 * Defines the public type surface consumed by AuthProvider and useAuth.
 * All shapes match the SHELL.md M5.1 contract.
 */

import type { WPNativeClient } from 'wp-native-client';
import type { ReactNode } from 'react';

// ─── User ────────────────────────────────────────────────────────────────────

/**
 * The user payload returned by `wp-native/auth-me`.
 *
 * Matches the `User` object from wp-native-auth SCHEMAS.md.
 * No EC-specific fields — those are layered via the
 * `wp_native_auth_user_payload` filter on the server side.
 */
export interface AuthMeUser {
  id: number;
  username: string;
  display_name: string;
  email: string;
  avatar_url: string;
  roles: string[];
  registered_at: string;
}

// ─── Storage ─────────────────────────────────────────────────────────────────

/**
 * Platform-agnostic token storage adapter.
 *
 * The shell never imports expo-secure-store or any concrete storage.
 * Consumers wire in their preferred implementation (expo-secure-store,
 * AsyncStorage, Keychain, etc.) by satisfying this interface.
 */
export interface TokenStorageAdapter {
  /** Read a value by key. Return null if not found. */
  getItem: (key: string) => Promise<string | null>;

  /** Write a value by key. */
  setItem: (key: string, value: string) => Promise<void>;

  /** Delete a value by key. */
  removeItem: (key: string) => Promise<void>;
}

// ─── Config ──────────────────────────────────────────────────────────────────

/**
 * API connection config passed to AuthProvider.
 *
 * Matches the `api` shape from the consumer config in ROADMAP.md.
 */
export interface WPNativeApiConfig {
  /** Base URL for the REST API, e.g. "https://example.com/wp-json" */
  baseUrl: string;

  /**
   * Client identifier sent as a default header.
   * Maps to e.g. "extrachill-app", "my-app", etc.
   */
  clientId: string;
}

// ─── State + Actions ─────────────────────────────────────────────────────────

/** Auth state exposed via useAuth(). */
export interface AuthState {
  /** The authenticated user, or null if not logged in. */
  user: AuthMeUser | null;

  /** True while the initial token load + /me check is in progress. */
  isLoading: boolean;

  /** True when a valid user session exists. */
  isAuthenticated: boolean;

  /**
   * True when a 401 could not be recovered by the transport's retry.
   * The consumer should show a "session expired" UI and prompt re-login.
   */
  sessionExpired: boolean;
}

/** Auth actions exposed via useAuth(). */
export interface AuthActions {
  /**
   * Log in with email/username and password.
   * Calls `wp-native/auth-login`, stores tokens, runs discover(), sets user.
   */
  login: (identifier: string, password: string) => Promise<void>;

  /**
   * Register a new user account with email and password.
   * Calls `wp-native/auth-register`, stores tokens, runs discover(), sets user.
   * Same return contract as login — Promise<void>, throws on failure.
   */
  register: (email: string, password: string, passwordConfirm: string) => Promise<void>;

  /**
   * Log out. Calls `wp-native/auth-logout`, then always clears local tokens
   * regardless of server response.
   */
  logout: () => Promise<void>;

  /**
   * Force a token refresh via the transport.
   * Useful when the app returns from background and wants a fresh session.
   */
  refreshSession: () => Promise<void>;

  /**
   * Clear the sessionExpired flag without re-authenticating.
   * Call this after showing a "session expired" dialog and navigating to login.
   */
  clearSessionExpired: () => void;

  /** The underlying WPNativeClient instance for direct ability calls. */
  client: WPNativeClient;
}

// ─── Provider Props ──────────────────────────────────────────────────────────

/** Props for <AuthProvider>. */
export interface AuthProviderProps {
  /** API connection config. */
  api: WPNativeApiConfig;

  /** Platform-specific token storage. */
  storage: TokenStorageAdapter;

  /**
   * Called when authentication fails irrecoverably (refresh exhausted).
   * Optional — consumers can also react via the sessionExpired flag.
   */
  onAuthFailure?: () => void;

  children: ReactNode;
}
