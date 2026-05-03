/**
 * BrowserHandoffProvider — context for WPNativeBrowserHandoffConfig.
 *
 * Extracted from the deleted DrawerShell / NavigationConfigProvider
 * (Slice B, expo-router rebase).
 *
 * Consumer wraps their layout tree:
 *
 *   <BrowserHandoffProvider config={config.browserHandoff}>
 *     <Drawer .../>
 *   </BrowserHandoffProvider>
 *
 * Downstream, `useBrowserHandoff()` reads config via
 * `useBrowserHandoffConfig()` instead of the old combined context.
 */

import React, { createContext, useContext } from 'react';
import type { WPNativeBrowserHandoffConfig } from './handoff';

// ─── Context ─────────────────────────────────────────────────────────────────

const BrowserHandoffContext = createContext<
  WPNativeBrowserHandoffConfig | undefined
>(undefined);

// ─── Provider ────────────────────────────────────────────────────────────────

/**
 * Provides browser-handoff configuration to `useBrowserHandoff()` and
 * other descendants.
 */
export function BrowserHandoffProvider({
  config,
  children,
}: {
  config?: WPNativeBrowserHandoffConfig;
  children: React.ReactNode;
}): React.ReactElement {
  return (
    <BrowserHandoffContext.Provider value={config}>
      {children}
    </BrowserHandoffContext.Provider>
  );
}

// ─── Hook ────────────────────────────────────────────────────────────────────

/**
 * Read the browser-handoff config from context.
 * Returns `undefined` when no `BrowserHandoffProvider` is mounted or when
 * no config was supplied.
 */
export function useBrowserHandoffConfig():
  | WPNativeBrowserHandoffConfig
  | undefined {
  return useContext(BrowserHandoffContext);
}
