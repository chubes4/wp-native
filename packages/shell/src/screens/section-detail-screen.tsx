/**
 * SectionDetailScreen — detail route twin of SectionScreen.
 *
 * expo-router rebase (Slice D): looks up the section by `sectionId`
 * from NavigationConfig context and renders AbilityDetail with the
 * section's `detailAbility` + `detailAdapter`.
 *
 * Consumers mount this at their `[id].tsx` route file, reading the
 * `id` from `useLocalSearchParams()` and passing it as a prop.
 */

import React from 'react';
import { View, Text, StyleSheet } from 'react-native';

import { useNavigationConfig } from '../navigation';
import { useTheme } from '../theme';

import { AbilityDetail } from './ability-detail';

// ─── ErrorState ──────────────────────────────────────────────────────────────

/**
 * Themed error view for invalid / missing section detail config.
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

// ─── SectionDetailScreen ─────────────────────────────────────────────────────

/**
 * Props for SectionDetailScreen.
 */
export interface SectionDetailScreenProps {
	/** Section identifier — looked up from NavigationConfig context. */
	sectionId: string;
	/** Entity identifier to fetch (from route params). */
	id: string | number;
}

/**
 * Detail route component for a NavigationSection. Looks up the section
 * by `sectionId`, validates that `detailAbility` and `detailAdapter`
 * are configured, and renders `<AbilityDetail/>`.
 *
 * Usage (consumer's `[id].tsx`):
 * ```tsx
 * import { useLocalSearchParams } from 'expo-router';
 * import { SectionDetailScreen } from 'wp-native-shell';
 *
 * export default function PostDetail() {
 *   const { id } = useLocalSearchParams<{ id: string }>();
 *   return <SectionDetailScreen sectionId="posts" id={id} />;
 * }
 * ```
 */
export function SectionDetailScreen({
	sectionId,
	id,
}: SectionDetailScreenProps): React.ReactElement {
	const { navigation } = useNavigationConfig();
	const section = navigation.sections.find((s) => s.id === sectionId);

	if (!section?.detailAbility || !section.detailAdapter) {
		return (
			<ErrorState
				message={`Section "${sectionId}" has no detail config`}
			/>
		);
	}

	return (
		<AbilityDetail
			ability={section.detailAbility}
			id={id}
			adapter={section.detailAdapter}
		/>
	);
}
