/**
 * <WPNativeApp/> — top-level wrapper composing every shell provider.
 *
 * After the expo-router rebase (EXPO-ROUTER-REBASE.md Slice C) this
 * component is a **provider stack only** — it no longer mounts its own
 * navigation tree.  Consumers supply their own expo-router layout as
 * `children` (typically a `<Slot/>` or `<Stack/>`).
 *
 * Usage:
 *
 *   import { WPNativeApp } from 'wp-native-shell';
 *   import { Slot } from 'expo-router';
 *   import { config } from './my-app.config';
 *
 *   export default function RootLayout() {
 *     return (
 *       <WPNativeApp config={config} loginScreen={MyLoginScreen}>
 *         <Slot />
 *       </WPNativeApp>
 *     );
 *   }
 *
 * Composition order (outer to inner):
 *   ThemeProvider
 *     AuthProvider
 *       NavigationConfigProvider
 *         BrowserHandoffProvider
 *           AuthGate
 *             {children}
 *
 * Theme is outermost so loading / login screens (which may render
 * before navigation mounts) can read theme tokens.
 */

import React from 'react';
import { ThemeProvider } from '../theme';
import { AuthProvider } from '../auth';
import {
	NavigationConfigProvider,
	BrowserHandoffProvider,
} from '../navigation';
import { AuthGate } from './gate';
import type { WPNativeAppProps } from './types';

export function WPNativeApp({
	config,
	loading,
	loginScreen,
	children,
}: WPNativeAppProps): React.ReactElement {
	const themeProps =
		config.theme === undefined ? {} : { tokens: config.theme };

	const navigationProps = { navigation: config.navigation };

	const browserHandoffProps =
		config.browserHandoff === undefined
			? {}
			: { config: config.browserHandoff };

	const gateProps: {
		loading?: typeof loading;
		loginScreen?: typeof loginScreen;
	} = {};
	if (loading !== undefined) {
		gateProps.loading = loading;
	}
	if (loginScreen !== undefined) {
		gateProps.loginScreen = loginScreen;
	}

	return (
		<ThemeProvider {...themeProps}>
			<AuthProvider api={config.api} storage={config.tokenStorage}>
				<NavigationConfigProvider {...navigationProps}>
					<BrowserHandoffProvider {...browserHandoffProps}>
						<AuthGate {...gateProps}>{children}</AuthGate>
					</BrowserHandoffProvider>
				</NavigationConfigProvider>
			</AuthProvider>
		</ThemeProvider>
	);
}
