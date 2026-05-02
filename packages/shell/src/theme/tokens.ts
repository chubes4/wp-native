/**
 * ThemeTokens — the canonical token shape for wp-native-shell.
 *
 * Consumers can override any subset of tokens via the `tokens` prop on
 * `<ThemeProvider/>`. Unset tokens fall back to `defaultThemeTokens`.
 *
 * This package ships its own neutral defaults — it does NOT depend on
 * `@extrachill/tokens` or any site-specific token set.
 *
 * @see packages/shell/SHELL.md § M5.2
 */

// ─── ThemeTokens type ────────────────────────────────────────────────────────

export interface ThemeTokens {
	colors: {
		/** Primary brand color (used for links, primary buttons). */
		primary: string;
		/** Color used on top of `primary` (e.g. button text). */
		onPrimary: string;
		/** App background. */
		background: string;
		/** Surfaces above background (cards, sheets). */
		surface: string;
		/** Default text color. */
		text: string;
		/** Lower-emphasis text. */
		textMuted: string;
		/** Borders, dividers. */
		border: string;
		/** Error / destructive. */
		error: string;
		/** Success / confirmation. */
		success: string;
	};
	typography: {
		/** Default font family. */
		fontFamily: string;
		/** Bold variant family (defaults to fontFamily if unset). */
		fontFamilyBold?: string;
		/** Base font size in points. Other sizes scale relative to this. */
		fontSizeBase: number;
		/** { xs, sm, base, lg, xl, '2xl' } — RN points. */
		fontSizes: {
			xs: number;
			sm: number;
			base: number;
			lg: number;
			xl: number;
			'2xl': number;
		};
		/** Line-height multipliers, applied to fontSize. */
		lineHeights: {
			tight: number;
			normal: number;
			relaxed: number;
		};
	};
	spacing: {
		/** Base unit in points (e.g. 4). All spacing is a multiple. */
		unit: number;
		/** Named multiples: xs=1, sm=2, md=3, lg=4, xl=6, '2xl'=8 of unit. */
		xs: number;
		sm: number;
		md: number;
		lg: number;
		xl: number;
		'2xl': number;
	};
	radii: {
		none: 0;
		sm: number;
		md: number;
		lg: number;
		full: 9999;
	};
}

// ─── Default tokens ──────────────────────────────────────────────────────────
// Neutral, readable, modern — not branded. A consumer with no `theme` config
// still renders.

export const defaultThemeTokens: ThemeTokens = {
	colors: {
		primary: '#2563eb', // blue-600
		onPrimary: '#ffffff',
		background: '#ffffff',
		surface: '#f8fafc', // slate-50
		text: '#0f172a', // slate-900
		textMuted: '#64748b', // slate-500
		border: '#e2e8f0', // slate-200
		error: '#dc2626', // red-600
		success: '#16a34a', // green-600
	},
	typography: {
		fontFamily: 'System',
		fontSizeBase: 16,
		fontSizes: { xs: 12, sm: 14, base: 16, lg: 18, xl: 20, '2xl': 24 },
		lineHeights: { tight: 1.2, normal: 1.5, relaxed: 1.75 },
	},
	spacing: {
		unit: 4,
		xs: 4,
		sm: 8,
		md: 12,
		lg: 16,
		xl: 24,
		'2xl': 32,
	},
	radii: { none: 0, sm: 4, md: 8, lg: 16, full: 9999 },
};
