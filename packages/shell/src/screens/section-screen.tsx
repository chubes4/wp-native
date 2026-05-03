/**
 * SectionScreen — routing decision component for a NavigationSection.
 *
 * expo-router rebase (Slice D): takes `sectionId` string prop, looks up
 * the section from NavigationConfig context, and renders the correct view
 * inline. The old SectionStack (wrapping @react-navigation/native-stack)
 * is removed — list→detail navigation is now handled by expo-router
 * filesystem routes that the consumer controls.
 *
 * Routing decision:
 *   1. Consumer-supplied `screen` always wins.
 *   2. Generic ability-driven list (ability + listAdapter → AbilityList).
 *   3. Existing M5.3 placeholder fallback.
 */

import React from 'react';
import { View, Text, StyleSheet } from 'react-native';

import { useNavigationConfig } from '../navigation';
import { useTheme } from '../theme';

import { AbilityList } from './ability-list';

// ─── ErrorState ──────────────────────────────────────────────────────────────

/**
 * Themed error view for invalid / missing section lookups.
 */
function ErrorState({ message }: { message: string }): React.ReactElement {
	const theme = useTheme();

	return (
		<View
			style={[
				errorStyles.container,
				{ backgroundColor: theme.colors.background },
			]}
		>
			<Text
				style={[
					errorStyles.text,
					{
						color: theme.colors.error,
						fontFamily: theme.typography.fontFamily,
						fontSize: theme.typography.fontSizes.base,
					},
				]}
			>
				{message}
			</Text>
		</View>
	);
}

const errorStyles = StyleSheet.create({
	container: {
		flex: 1,
		justifyContent: 'center',
		alignItems: 'center',
	},
	text: {
		textAlign: 'center',
	},
});

// ─── SectionPlaceholder ──────────────────────────────────────────────────────

/**
 * Themed placeholder for sections that have no screen, no adapter,
 * or only an ability name. Preserves M5.3 behavior.
 */
export function SectionPlaceholder({
	label,
}: {
	label: string;
}): React.ReactElement {
	const theme = useTheme();

	return (
		<View
			style={[
				placeholderStyles.container,
				{ backgroundColor: theme.colors.background },
			]}
		>
			<Text
				style={[
					placeholderStyles.text,
					{
						color: theme.colors.textMuted,
						fontFamily: theme.typography.fontFamily,
						fontSize: theme.typography.fontSizes.base,
					},
				]}
			>
				{label}
			</Text>
		</View>
	);
}

const placeholderStyles = StyleSheet.create({
	container: {
		flex: 1,
		justifyContent: 'center',
		alignItems: 'center',
	},
	text: {
		opacity: 0.6,
	},
});

// ─── SectionScreen ───────────────────────────────────────────────────────────

/**
 * Props for SectionScreen.
 */
export interface SectionScreenProps {
	/** Section identifier — looked up from NavigationConfig context. */
	sectionId: string;
}

/**
 * Routing decision component. Looks up the section by `sectionId` from
 * NavigationConfig context and picks the right view:
 *
 * 1. Consumer-supplied `screen` always wins.
 * 2. Generic ability-driven list when `ability` + `listAdapter` are set.
 * 3. Existing M5.3 placeholder fallback.
 *
 * List→detail navigation is handled by expo-router filesystem routes
 * that the consumer controls — SectionScreen never mounts a stack.
 */
export function SectionScreen({
	sectionId,
}: SectionScreenProps): React.ReactElement {
	const { navigation } = useNavigationConfig();
	const section = navigation.sections.find((s) => s.id === sectionId);

	if (!section) {
		return <ErrorState message={`Section "${sectionId}" not registered`} />;
	}

	// 1. Consumer screen always wins.
	if (section.screen) {
		const ConsumerScreen = section.screen;
		return <ConsumerScreen />;
	}

	// 2. Generic ability-driven list (M6).
	if (section.ability && section.listAdapter) {
		return (
			<AbilityList
				ability={section.ability}
				adapter={section.listAdapter}
				detailAbility={section.detailAbility}
			/>
		);
	}

	// 3. Placeholder (existing M5.3 behavior).
	return <SectionPlaceholder label={section.label} />;
}
