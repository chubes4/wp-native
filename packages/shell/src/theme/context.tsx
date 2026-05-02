/**
 * ThemeProvider + useTheme — React context for resolved design tokens.
 *
 * `<ThemeProvider/>` accepts an optional partial token override, deep-merges
 * it with `defaultThemeTokens`, and exposes the fully-resolved result via
 * `useTheme()`.
 *
 * @see packages/shell/SHELL.md § M5.2
 */

import { createContext, useContext, useMemo, type ReactNode } from 'react';

import type { ThemeTokens } from './tokens';
import { defaultThemeTokens } from './tokens';
import { deepMergeTokens } from './merge';

// ─── Context ─────────────────────────────────────────────────────────────────

const ThemeContext = createContext< ThemeTokens | null >( null );

// ─── Provider ────────────────────────────────────────────────────────────────

export interface ThemeProviderProps {
	/** Partial override merged onto defaults. */
	tokens?: Partial< ThemeTokens >;
	children: ReactNode;
}

/**
 * Provides resolved `ThemeTokens` to all descendants via `useTheme()`.
 *
 * If no `tokens` prop is supplied the provider falls back to
 * `defaultThemeTokens`. Partial overrides are deep-merged — the common case
 * is overriding `colors.primary` while keeping everything else.
 */
export function ThemeProvider( { tokens, children }: ThemeProviderProps ) {
	const resolved = useMemo< ThemeTokens >(
		() =>
			tokens
				? deepMergeTokens( defaultThemeTokens, tokens )
				: defaultThemeTokens,
		[ tokens ],
	);

	return (
		<ThemeContext.Provider value={ resolved }>
			{ children }
		</ThemeContext.Provider>
	);
}

// ─── Hook ────────────────────────────────────────────────────────────────────

/**
 * Returns the fully-resolved `ThemeTokens` from the nearest
 * `<ThemeProvider/>`. Throws if called outside a provider.
 */
export function useTheme(): ThemeTokens {
	const context = useContext( ThemeContext );

	if ( ! context ) {
		throw new Error( 'useTheme must be used within a ThemeProvider' );
	}

	return context;
}
