/**
 * AbilityDetail — generic detail screen for wp-native-shell (M6.2).
 *
 * Fetches a single entity by calling `client.execute(ability, { id })`
 * (or a custom input via `adapter.buildInput`) and renders the result
 * through a consumer-supplied `AbilityDetailAdapter`.
 *
 * States:
 *   - Loading: theme-colored ActivityIndicator
 *   - Error:   theme-styled message with Retry button
 *   - Success: delegates to adapter.render({ entity })
 *
 * @see SHELL.md M6.2
 */

import { useEffect, useState, useCallback } from 'react';
import {
	View,
	Text,
	ActivityIndicator,
	Pressable,
	StyleSheet,
} from 'react-native';

import { useAuth } from '../auth';
import { useTheme } from '../theme';
import type { AbilityDetailProps } from './ability-detail-types';

// ─── Internal state ──────────────────────────────────────────────────────────

interface DetailState<TEntity> {
	entity: TEntity | null;
	isLoading: boolean;
	error: string | null;
}

// ─── Component ───────────────────────────────────────────────────────────────

/**
 * Generic detail screen that fetches a single entity via the Abilities API
 * and renders it through a consumer-supplied adapter.
 *
 * Usage:
 * ```tsx
 * <AbilityDetail
 *   id="42"
 *   ability="wp/post.get"
 *   adapter={postDetailAdapter}
 * />
 * ```
 */
export function AbilityDetail<TEntity>({
	id,
	ability,
	adapter,
}: AbilityDetailProps<TEntity>) {
	const { client } = useAuth();
	const theme = useTheme();

	const [state, setState] = useState<DetailState<TEntity>>({
		entity: null,
		isLoading: true,
		error: null,
	});

	const fetchEntity = useCallback(async () => {
		setState({ entity: null, isLoading: true, error: null });

		try {
			const input = adapter.buildInput
				? adapter.buildInput(id)
				: { id };

			const result: unknown = await client.execute(ability, input);
			const entity = adapter.extractEntity(result);

			setState({ entity, isLoading: false, error: null });
		} catch (err: unknown) {
			const message =
				err instanceof Error
					? err.message
					: 'An unexpected error occurred.';
			setState({ entity: null, isLoading: false, error: message });
		}
	}, [id, ability, adapter, client]);

	useEffect(() => {
		void fetchEntity();
	}, [fetchEntity]);

	// ── Loading ──────────────────────────────────────────────────────────

	if (state.isLoading) {
		return (
			<View
				style={[
					styles.centered,
					{ backgroundColor: theme.colors.background },
				]}
			>
				<ActivityIndicator
					size="large"
					color={theme.colors.primary}
				/>
			</View>
		);
	}

	// ── Error ────────────────────────────────────────────────────────────

	if (state.error !== null) {
		return (
			<View
				style={[
					styles.centered,
					{
						backgroundColor: theme.colors.background,
						padding: theme.spacing.lg,
					},
				]}
			>
				<Text
					style={[
						styles.errorText,
						{
							color: theme.colors.error,
							fontSize: theme.typography.fontSizes.base,
							fontFamily: theme.typography.fontFamily,
							lineHeight:
								theme.typography.fontSizes.base *
								theme.typography.lineHeights.normal,
							marginBottom: theme.spacing.md,
						},
					]}
				>
					{state.error}
				</Text>
				<Pressable
					onPress={() => void fetchEntity()}
					style={[
						styles.retryButton,
						{
							backgroundColor: theme.colors.primary,
							borderRadius: theme.radii.md,
							paddingVertical: theme.spacing.sm,
							paddingHorizontal: theme.spacing.lg,
						},
					]}
				>
					<Text
						style={{
							color: theme.colors.onPrimary,
							fontSize: theme.typography.fontSizes.base,
							fontFamily: theme.typography.fontFamily,
							fontWeight: '600',
						}}
					>
						Retry
					</Text>
				</Pressable>
			</View>
		);
	}

	// ── Success ──────────────────────────────────────────────────────────

	if (state.entity !== null) {
		return adapter.render({ entity: state.entity });
	}

	// Unreachable — but satisfies exhaustive return.
	return null;
}

// ─── Base styles (non-theme values only) ─────────────────────────────────────

const styles = StyleSheet.create({
	centered: {
		flex: 1,
		justifyContent: 'center',
		alignItems: 'center',
	},
	errorText: {
		textAlign: 'center',
	},
	retryButton: {
		alignItems: 'center',
		justifyContent: 'center',
	},
});
