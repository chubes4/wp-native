/**
 * NavigationConfigProvider + useNavigationConfig — extracted from drawer.tsx
 * as part of the expo-router rebase (Slice B).
 *
 * Provides the navigation config (sections list) to descendant components
 * and hooks (DrawerContent, SectionScreen, etc.).
 */

import React, { createContext, useContext, useMemo } from 'react';
import type { WPNativeNavigationConfig } from './types';

// ─── Context ─────────────────────────────────────────────────────────────────

/**
 * Internal context shape consumed by navigation components and hooks.
 */
interface NavigationConfigContextValue {
  navigation: WPNativeNavigationConfig;
}

const NavigationConfigContext =
  createContext<NavigationConfigContextValue | null>(null);

/**
 * Read the navigation config from context. Throws if used outside a
 * NavigationConfigProvider.
 */
export function useNavigationConfig(): NavigationConfigContextValue {
  const ctx = useContext(NavigationConfigContext);
  if (!ctx) {
    throw new Error(
      'useNavigationConfig must be used within a NavigationConfigProvider',
    );
  }
  return ctx;
}

/**
 * Props for NavigationConfigProvider.
 *
 * NOTE: `browserHandoff` is accepted for backward compatibility with
 * the pre-rebase WPNativeApp (which passes it here). It is NOT stored
 * in this context — browser handoff config now lives in
 * BrowserHandoffProvider. Slice C removes this prop when it rewrites
 * WPNativeApp.
 */
export interface NavigationConfigProviderProps {
  navigation: WPNativeNavigationConfig;
  /** @deprecated Ignored — use BrowserHandoffProvider instead. Removed in Slice C. */
  browserHandoff?: unknown;
  children: React.ReactNode;
}

/**
 * Provides navigation configuration to descendant components and hooks
 * (DrawerContent, SectionScreen, etc.).
 */
export function NavigationConfigProvider({
  navigation,
  // eslint-disable-next-line @typescript-eslint/no-unused-vars -- backward compat, removed in Slice C
  browserHandoff: _browserHandoff,
  children,
}: NavigationConfigProviderProps): React.ReactElement {
  const value = useMemo(() => ({ navigation }), [navigation]);

  return (
    <NavigationConfigContext.Provider value={value}>
      {children}
    </NavigationConfigContext.Provider>
  );
}
