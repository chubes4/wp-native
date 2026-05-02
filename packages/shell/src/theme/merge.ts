/**
 * Deep-merge utility for ThemeTokens.
 *
 * Consumers commonly override a single color while keeping everything else
 * from `defaultThemeTokens`. This utility produces a fully-resolved
 * `ThemeTokens` from defaults + a partial override at any depth.
 *
 * @see packages/shell/SHELL.md § M5.2
 */

import type { ThemeTokens } from './tokens';

/**
 * Type-safe check for plain objects (records) that should be recursed into
 * during the merge. Arrays, null, and non-object primitives are replaced
 * wholesale rather than merged.
 */
function isRecord( value: unknown ): value is Record< string, unknown > {
	return (
		typeof value === 'object' &&
		value !== null &&
		!Array.isArray( value )
	);
}

/**
 * Recursively merge `override` into `defaults`.
 *
 * - Missing keys in `override` fall through to `defaults`.
 * - Nested objects are merged recursively.
 * - Primitives and arrays in `override` replace their counterpart in
 *   `defaults` wholesale.
 *
 * The return value is cast to `ThemeTokens` at the call site — the recursive
 * helper operates on `Record<string, unknown>` internally so it can walk
 * arbitrary depth without per-key type assertions.
 */
function mergeRecords(
	defaults: Record< string, unknown >,
	override: Record< string, unknown >,
): Record< string, unknown > {
	const result: Record< string, unknown > = { ...defaults };

	for ( const key of Object.keys( override ) ) {
		const overrideValue = override[ key ];
		const defaultValue = defaults[ key ];

		if ( isRecord( overrideValue ) && isRecord( defaultValue ) ) {
			result[ key ] = mergeRecords( defaultValue, overrideValue );
		} else {
			result[ key ] = overrideValue;
		}
	}

	return result;
}

/**
 * Deep-merge a partial token override into `defaults`, producing a
 * fully-resolved `ThemeTokens`.
 *
 * @example
 * ```ts
 * const tokens = deepMergeTokens( defaultThemeTokens, {
 *   colors: { primary: '#e11d48' },
 * } );
 * // tokens.colors.primary === '#e11d48'
 * // tokens.colors.background === '#ffffff'  (from defaults)
 * ```
 */
export function deepMergeTokens(
	defaults: ThemeTokens,
	override: Partial< ThemeTokens >,
): ThemeTokens {
	return mergeRecords(
		defaults as unknown as Record< string, unknown >,
		override as unknown as Record< string, unknown >,
	) as unknown as ThemeTokens;
}
