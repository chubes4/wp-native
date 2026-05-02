/**
 * <WPNativeApp/> — top-level wrapper composing every shell provider.
 *
 * Consumer apps render exactly this:
 *
 *   import { WPNativeApp } from 'wp-native-shell';
 *   import { config } from './my-app.config';
 *
 *   export default function App() {
 *     return <WPNativeApp config={config} loginScreen={MyLoginScreen} />;
 *   }
 *
 * Composition order (outer to inner):
 *   ThemeProvider
 *     BrandProvider
 *       AuthProvider
 *         NavigationConfigProvider
 *           AuthGate
 *             DrawerShell
 *
 * Theme is outermost so loading / login / onboarding screens (which
 * may render before navigation mounts) can read theme tokens.
 */

import React from 'react';
import { ThemeProvider } from '../theme';
import { AuthProvider } from '../auth';
import {
	NavigationConfigProvider,
	DrawerShell,
} from '../navigation';
import { BrandProvider } from './brand';
import { AuthGate } from './gate';
import type { WPNativeAppProps } from './types';

export function WPNativeApp({
	config,
	loading,
	loginScreen,
}: WPNativeAppProps): React.ReactElement {
	const themeProps =
		config.theme === undefined ? {} : { tokens: config.theme };

	const navigationProps =
		config.browserHandoff === undefined
			? { navigation: config.navigation }
			: {
					navigation: config.navigation,
					browserHandoff: config.browserHandoff,
				};

	const gateProps: {
		loading?: typeof loading;
		loginScreen?: typeof loginScreen;
		onboarding?: typeof config.onboarding;
	} = {};
	if (loading !== undefined) {
		gateProps.loading = loading;
	}
	if (loginScreen !== undefined) {
		gateProps.loginScreen = loginScreen;
	}
	if (config.onboarding !== undefined) {
		gateProps.onboarding = config.onboarding;
	}

	return (
		<ThemeProvider {...themeProps}>
			<BrandProvider brand={config.brand}>
				<AuthProvider api={config.api} storage={config.tokenStorage}>
					<NavigationConfigProvider {...navigationProps}>
						<AuthGate {...gateProps}>
							<DrawerShell />
						</AuthGate>
					</NavigationConfigProvider>
				</AuthProvider>
			</BrandProvider>
		</ThemeProvider>
	);
}
