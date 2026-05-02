/**
 * Browser handoff hook for wp-native-shell.
 *
 * Matches SHELL.md M5.3 contract.
 *
 * Lineage: extrachill-app/src/components/DrawerContent.tsx `openUrl()`,
 * generalized to accept configurable host allowlists and ability names.
 */

import { useCallback } from 'react';
import { Linking } from 'react-native';
import { useAuth } from '../auth';
import { useNavigationConfig } from './drawer';

// ─── Public types ────────────────────────────────────────────────────────────

/**
 * Configuration for browser handoff behavior.
 */
export interface WPNativeBrowserHandoffConfig {
  /**
   * Hosts (or wildcard patterns) eligible for authenticated handoff.
   *
   * Supports exact matches (`example.com`) and wildcard subdomains
   * (`*.example.com` matches `foo.example.com` but NOT `example.com`).
   */
  handoffHosts: ReadonlyArray<string>;

  /**
   * Hosts to exclude from handoff even if they match `handoffHosts`.
   * Same wildcard syntax as `handoffHosts`.
   */
  excludeHosts?: ReadonlyArray<string> | undefined;

  /**
   * Ability name used to mint a handoff token.
   * @default 'wp-native/auth.browser-handoff'
   */
  handoffAbility?: string | undefined;
}

/**
 * Return type of `useBrowserHandoff()`.
 */
export interface BrowserHandoffHandler {
  /**
   * Attempt to handle a URL via browser handoff.
   *
   * - If the host matches and the user is authenticated, tries to mint
   *   a handoff token via the configured ability. Falls back to direct
   *   open if the ability errors or is unavailable.
   * - If the host matches but the user is unauthenticated, opens directly.
   * - If the host does not match, returns `false` (caller handles).
   *
   * Never throws.
   */
  handle: (url: string) => Promise<boolean>;
}

// ─── Internals ───────────────────────────────────────────────────────────────

const DEFAULT_HANDOFF_ABILITY = 'wp-native/auth.browser-handoff';

/**
 * Match a hostname against a pattern that may use a leading wildcard.
 *
 * - `example.com`   matches only `example.com`
 * - `*.example.com` matches `foo.example.com`, `bar.baz.example.com`
 *                   but NOT `example.com` itself.
 */
function hostMatchesPattern(host: string, pattern: string): boolean {
  if (pattern.startsWith('*.')) {
    const suffix = pattern.slice(1); // ".example.com"
    return host.endsWith(suffix) && host.length > suffix.length;
  }
  return host === pattern;
}

/**
 * Check whether a hostname matches any pattern in a list.
 */
function hostMatchesAny(
  host: string,
  patterns: ReadonlyArray<string>,
): boolean {
  return patterns.some((p) => hostMatchesPattern(host, p));
}

/**
 * Extract the lowercase hostname from a URL string.
 * Returns an empty string if parsing fails.
 */
function extractHost(url: string): string {
  try {
    const parsed = new URL(url);
    return parsed.hostname.toLowerCase();
  } catch {
    return '';
  }
}

// ─── Hook ────────────────────────────────────────────────────────────────────

/**
 * React hook providing browser handoff capabilities.
 *
 * Reads `browserHandoff` config from the NavigationConfigProvider context
 * and auth state from the AuthProvider.
 *
 * Usage:
 * ```ts
 * const { handle } = useBrowserHandoff();
 * const handled = await handle('https://example.com/some-page');
 * if (!handled) {
 *   // URL didn't match handoff hosts — handle it yourself
 * }
 * ```
 */
export function useBrowserHandoff(): BrowserHandoffHandler {
  const { browserHandoff } = useNavigationConfig();
  const { isAuthenticated, client } = useAuth();

  const handle = useCallback(
    async (url: string): Promise<boolean> => {
      try {
        // No config → nothing to handle.
        if (!browserHandoff) {
          return false;
        }

        const host = extractHost(url);
        if (!host) {
          return false;
        }

        // Check exclusions first.
        if (
          browserHandoff.excludeHosts &&
          hostMatchesAny(host, browserHandoff.excludeHosts)
        ) {
          return false;
        }

        // Check if host is in the allowlist.
        if (!hostMatchesAny(host, browserHandoff.handoffHosts)) {
          return false;
        }

        // Host matched. If authenticated, try to mint a handoff token.
        if (isAuthenticated && client) {
          try {
            const abilityName =
              browserHandoff.handoffAbility ?? DEFAULT_HANDOFF_ABILITY;
            const result = await client.execute<{ handoff_url: string }>(
              abilityName,
              { url },
            );

            if (result.handoff_url) {
              await Linking.openURL(result.handoff_url);
              return true;
            }
          } catch {
            // Ability unavailable or errored — fall through to direct open.
          }
        }

        // Unauthenticated, or ability unavailable: open directly.
        await Linking.openURL(url);
        return true;
      } catch {
        // Never throw. If everything fails, signal that we didn't handle it.
        return false;
      }
    },
    [browserHandoff, isAuthenticated, client],
  );

  return { handle };
}
