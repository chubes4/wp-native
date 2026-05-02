/**
 * BrandProvider + useBrand() — exposes WPNativeBrandConfig via React context.
 *
 * Brand is a tiny piece of cross-cutting state that screens (login,
 * loading, default empty states) read for display copy. Kept in its own
 * provider so consumers can override brand without remounting auth /
 * theme / navigation.
 */

import React, { createContext, useContext } from 'react';
import type { ReactNode } from 'react';
import type { WPNativeBrandConfig } from './types';

const BrandContext = createContext<WPNativeBrandConfig | null>(null);

export interface BrandProviderProps {
	brand: WPNativeBrandConfig;
	children: ReactNode;
}

export function BrandProvider({
	brand,
	children,
}: BrandProviderProps): React.ReactElement {
	return (
		<BrandContext.Provider value={brand}>{children}</BrandContext.Provider>
	);
}

/**
 * Read the current brand config.
 *
 * @throws if called outside a `<BrandProvider/>`.
 */
export function useBrand(): WPNativeBrandConfig {
	const ctx = useContext(BrandContext);
	if (!ctx) {
		throw new Error(
			'useBrand() must be called inside a <BrandProvider/> ' +
				'(usually provided by <WPNativeApp/>).',
		);
	}
	return ctx;
}
