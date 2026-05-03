/**
 * AuthGate — renders different children based on auth state.
 *
 * Three states, in order:
 *   1. Loading: initial token-load + discovery in flight.
 *   2. Logged out: render the consumer-supplied loginScreen.
 *   3. Logged in: render the children (consumer-supplied navigation tree).
 *
 * Onboarding gating is layered on top: when config.onboarding.enabled
 * is true, a logged-in user with `onboardingCompleted=false` sees the
 * onboarding screen instead of the main shell. Onboarding completion
 * is detected by the consumer screen — when it returns, the gate
 * advances.
 */

import React from 'react';
import type { ComponentType, ReactNode } from 'react';
import { View, Text, ActivityIndicator, StyleSheet } from 'react-native';
import { useAuth } from '../auth';
import { useTheme } from '../theme';
import { useBrand } from './brand';
import type { WPNativeOnboardingConfig } from './types';

export interface AuthGateProps {
	/** Optional fallback rendered while initial auth state loads. */
	loading?: ReactNode;
	/** Component rendered when the user is logged out. Consumer-owned. */
	loginScreen?: ComponentType;
	/** Optional onboarding gate. */
	onboarding?: WPNativeOnboardingConfig;
	/** Rendered when the user is fully authenticated (and onboarded if applicable). */
	children: ReactNode;
}

export function AuthGate({
	loading,
	loginScreen: LoginScreen,
	onboarding,
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

	if (onboarding?.enabled) {
		const OnboardingScreen = onboarding.screen;
		// The shell does not track onboarding-completed state itself —
		// the consumer screen is responsible for navigating away when done
		// (typically by calling the configured ability, then re-rendering
		// when its own state updates). For v0.1 the gate is a one-shot:
		// once the user is authenticated, render the onboarding screen
		// instead of the shell. The consumer is expected to dismount /
		// re-route when complete.
		//
		// Future: add an `onboardingCompleted` flag on AuthState driven by
		// a per-consumer "completion check" callback in the config.
		return <OnboardingScreen />;
	}

	return <>{children}</>;
}

function DefaultLoadingScreen(): React.ReactElement {
	const theme = useTheme();
	const brand = useBrand();

	return (
		<View
			style={[
				gateStyles.fill,
				{ backgroundColor: theme.colors.background },
			]}
		>
			<ActivityIndicator color={theme.colors.primary} />
			<Text
				style={[
					gateStyles.text,
					{
						color: theme.colors.textMuted,
						fontFamily: theme.typography.fontFamily,
						fontSize: theme.typography.fontSizes.sm,
						marginTop: theme.spacing.md,
					},
				]}
			>
				{brand.welcomeMessage ?? `Loading ${brand.name}…`}
			</Text>
		</View>
	);
}

function DefaultLoginPlaceholder(): React.ReactElement {
	const theme = useTheme();
	const brand = useBrand();

	return (
		<View
			style={[
				gateStyles.fill,
				{ backgroundColor: theme.colors.background },
			]}
		>
			<Text
				style={[
					gateStyles.heading,
					{
						color: theme.colors.text,
						fontFamily: theme.typography.fontFamilyBold ?? theme.typography.fontFamily,
						fontSize: theme.typography.fontSizes['2xl'],
						marginBottom: theme.spacing.md,
					},
				]}
			>
				{brand.name}
			</Text>
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
				No login screen configured. Pass `loginScreen` to{' '}
				{`<WPNativeApp/>`} to render your auth UI.
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
