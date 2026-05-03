/**
 * AuthGate — renders different children based on auth state.
 *
 * Three states, in order:
 *   1. Loading: initial token-load + discovery in flight.
 *   2. Logged out: render the consumer-supplied loginScreen.
 *   3. Logged in: render the children (consumer-supplied navigation tree).
 *
 * Onboarding gating is consumer-side — wrap the children you pass to
 * <WPNativeApp/> in your own auth-aware component if you need it.
 */

import React from 'react';
import type { ComponentType, ReactNode } from 'react';
import { View, ActivityIndicator, Text, StyleSheet } from 'react-native';
import { useAuth } from '../auth';
import { useTheme } from '../theme';

export interface AuthGateProps {
	/** Optional fallback rendered while initial auth state loads. */
	loading?: ReactNode;
	/** Component rendered when the user is logged out. Consumer-owned. */
	loginScreen?: ComponentType;
	/** Rendered when the user is fully authenticated. */
	children: ReactNode;
}

export function AuthGate({
	loading,
	loginScreen: LoginScreen,
	children,
}: AuthGateProps): React.ReactElement {
	const { isLoading, isAuthenticated } = useAuth();

	if (isLoading) {
		return <>{loading ?? <DefaultLoadingScreen />}</>;
	}

	if (!isAuthenticated) {
		if (LoginScreen) {
			return <LoginScreen />;
		}
		return <DefaultLoginPlaceholder />;
	}

	return <>{children}</>;
}

function DefaultLoadingScreen(): React.ReactElement {
	const theme = useTheme();

	return (
		<View
			style={[
				gateStyles.fill,
				{ backgroundColor: theme.colors.background },
			]}
		>
			<ActivityIndicator color={theme.colors.primary} />
		</View>
	);
}

function DefaultLoginPlaceholder(): React.ReactElement {
	const theme = useTheme();

	return (
		<View
			style={[
				gateStyles.fill,
				{ backgroundColor: theme.colors.background },
			]}
		>
			<Text
				style={[
					gateStyles.text,
					{
						color: theme.colors.textMuted,
						fontFamily: theme.typography.fontFamily,
						fontSize: theme.typography.fontSizes.base,
					},
				]}
			>
				Please configure a loginScreen for your app.
			</Text>
		</View>
	);
}

const gateStyles = StyleSheet.create({
	fill: {
		flex: 1,
		alignItems: 'center',
		justifyContent: 'center',
		padding: 24,
	},
	heading: {
		textAlign: 'center',
	},
	text: {
		textAlign: 'center',
	},
});
