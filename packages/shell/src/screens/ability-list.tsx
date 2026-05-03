/**
 * AbilityList — generic list screen for wp-native-shell.
 *
 * Renders a NavigationSection whose `ability` is set by calling
 * client.execute(ability, input) and piping results through a
 * consumer-supplied AbilityListAdapter.
 *
 * Features: FlatList rendering, pull-to-refresh, infinite scroll,
 * loading / error / empty states using theme tokens, and detail
 * navigation via expo-router's useRouter().
 *
 * expo-router rebase (Slice D): replaces @react-navigation/native
 * useNavigation() with expo-router useRouter(). Item taps push
 * to `adapter.detailHref(item)` or `./{itemId}` by default.
 */

import {
	useCallback,
	useEffect,
	useRef,
	useState,
	type FC,
	type ReactElement,
} from 'react';
import {
	ActivityIndicator,
	FlatList,
	Pressable,
	StyleSheet,
	Text,
	View,
} from 'react-native';
import { useRouter } from 'expo-router';

import { useAuth } from '../auth';
import { useTheme } from '../theme';
import type { ThemeTokens } from '../theme';
import type {
	AbilityListAdapter,
	AbilityListItemProps,
	AbilityListProps,
} from './ability-list-types';

// ─── Internal state ──────────────────────────────────────────────────────────

interface ListState {
	items: unknown[];
	page: number;
	isLoading: boolean;
	isLoadingMore: boolean;
	isRefreshing: boolean;
	error: string | null;
	hasMore: boolean;
}

const INITIAL_STATE: ListState = {
	items: [],
	page: 1,
	isLoading: true,
	isLoadingMore: false,
	isRefreshing: false,
	error: null,
	hasMore: true,
};

// ─── Helpers ─────────────────────────────────────────────────────────────────

function buildInput(
	adapter: AbilityListAdapter<unknown>,
	page: number,
	perPage: number,
): Record<string, unknown> {
	if (adapter.buildPageInput) {
		return adapter.buildPageInput(page, perPage);
	}
	return { page, per_page: perPage };
}

function checkHasNextPage(
	adapter: AbilityListAdapter<unknown>,
	result: unknown,
	pageItems: unknown[],
	perPage: number,
): boolean {
	if (adapter.hasNextPage) {
		return adapter.hasNextPage(result, pageItems);
	}
	return pageItems.length >= perPage;
}

function getErrorMessage(error: unknown): string {
	if (
		error !== null &&
		typeof error === 'object' &&
		'message' in error &&
		typeof (error as { message: unknown }).message === 'string'
	) {
		return (error as { message: string }).message;
	}
	return 'An unexpected error occurred.';
}

// ─── Noop ────────────────────────────────────────────────────────────────────

/** No-op press handler for items without detail navigation. */
function noop(): void {
	/* intentionally empty */
}

// ─── RetryButton ─────────────────────────────────────────────────────────

function RetryButton({
	theme,
	onPress,
}: {
	theme: ThemeTokens;
	onPress: () => void;
}): ReactElement {
	return (
		<Pressable
			onPress={onPress}
			style={{
				backgroundColor: theme.colors.primary,
				paddingVertical: theme.spacing.sm,
				paddingHorizontal: theme.spacing.lg,
				borderRadius: theme.radii.md,
			}}
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
	);
}

// ─── Component ───────────────────────────────────────────────────────────────

export const AbilityList: FC<AbilityListProps> = ({
	ability,
	adapter,
	detailAbility,
	emptyState,
	header,
}: AbilityListProps): ReactElement => {
	const { client } = useAuth();
	const theme = useTheme();
	const router = useRouter();

	const perPage = adapter.perPage ?? 20;

	const [state, setState] = useState<ListState>(INITIAL_STATE);

	// Track mounted state to avoid setting state after unmount.
	const mountedRef = useRef(true);
	useEffect(() => {
		return () => {
			mountedRef.current = false;
		};
	}, []);

	// ── Fetch a page ──────────────────────────────────────────────────────

	const fetchPage = useCallback(
		async (page: number, replace: boolean) => {
			try {
				const input = buildInput(adapter, page, perPage);
				const result = await client.execute(ability, input);
				const pageItems = adapter.extractItems(result);
				const hasMore = checkHasNextPage(adapter, result, pageItems, perPage);

				if (!mountedRef.current) return;

				setState((prev) => ({
					items: replace ? pageItems : [...prev.items, ...pageItems],
					page,
					isLoading: false,
					isLoadingMore: false,
					isRefreshing: false,
					error: null,
					hasMore,
				}));
			} catch (err: unknown) {
				if (!mountedRef.current) return;

				setState((prev) => ({
					...prev,
					isLoading: false,
					isLoadingMore: false,
					isRefreshing: false,
					error: getErrorMessage(err),
				}));
			}
		},
		[ability, adapter, client, perPage],
	);

	// ── Initial load ──────────────────────────────────────────────────────

	useEffect(() => {
		void fetchPage(1, true);
	}, [fetchPage]);

	// ── Pull-to-refresh ───────────────────────────────────────────────────

	const handleRefresh = useCallback(() => {
		setState((prev) => ({
			...prev,
			isRefreshing: true,
			error: null,
		}));
		void fetchPage(1, true);
	}, [fetchPage]);

	// ── Infinite scroll ───────────────────────────────────────────────────

	const handleEndReached = useCallback(() => {
		if (state.isLoadingMore || !state.hasMore || state.isLoading) return;

		const nextPage = state.page + 1;
		setState((prev) => ({
			...prev,
			isLoadingMore: true,
		}));
		void fetchPage(nextPage, false);
	}, [state.isLoadingMore, state.hasMore, state.isLoading, state.page, fetchPage]);

	// ── Retry ─────────────────────────────────────────────────────────────

	const handleRetry = useCallback(() => {
		setState(INITIAL_STATE);
		void fetchPage(1, true);
	}, [fetchPage]);

	// ── Render item ───────────────────────────────────────────────────────

	const renderItem = useCallback(
		({ item }: { item: unknown }): ReactElement => {
			const onPress = adapter.detailHref
				? () => {
						router.push(adapter.detailHref!(item));
					}
				: detailAbility
					? () => {
							router.push(`./${String(adapter.itemId(item))}`);
						}
					: noop;

			return adapter.renderItem({
				item,
				onPress,
			} satisfies AbilityListItemProps<unknown>);
		},
		[adapter, detailAbility, router],
	);

	const keyExtractor = useCallback(
		(item: unknown): string => String(adapter.itemId(item)),
		[adapter],
	);

	// ── Loading state (first load) ────────────────────────────────────────

	if (state.isLoading) {
		return (
			<View style={styles.centered}>
				<ActivityIndicator size="large" color={theme.colors.primary} />
			</View>
		);
	}

	// ── Error state ───────────────────────────────────────────────────────

	if (state.error !== null && state.items.length === 0) {
		return (
			<View style={styles.centered}>
				<Text
					style={[
						styles.errorText,
						{
							color: theme.colors.error,
							fontSize: theme.typography.fontSizes.base,
							fontFamily: theme.typography.fontFamily,
							marginBottom: theme.spacing.md,
						},
					]}
				>
					{state.error}
				</Text>
				<RetryButton theme={theme} onPress={handleRetry} />
			</View>
		);
	}

	// ── Empty state ───────────────────────────────────────────────────────

	if (state.items.length === 0) {
		return (
			<View style={styles.centered}>
				{emptyState ?? (
					<Text
						style={{
							color: theme.colors.textMuted,
							fontSize: theme.typography.fontSizes.base,
							fontFamily: theme.typography.fontFamily,
						}}
					>
						No items.
					</Text>
				)}
			</View>
		);
	}

	// ── List ──────────────────────────────────────────────────────────────

	return (
		<FlatList
			data={state.items}
			keyExtractor={keyExtractor}
			renderItem={renderItem}
			ListHeaderComponent={header ? <>{header}</> : null}
			ListFooterComponent={
				state.isLoadingMore ? (
					<View style={[styles.footer, { paddingVertical: theme.spacing.md }]}>
						<ActivityIndicator
							size="small"
							color={theme.colors.primary}
						/>
					</View>
				) : null
			}
			refreshing={state.isRefreshing}
			onRefresh={handleRefresh}
			onEndReached={handleEndReached}
			onEndReachedThreshold={0.5}
			contentContainerStyle={{ padding: theme.spacing.md }}
		/>
	);
};

// ─── Styles ──────────────────────────────────────────────────────────────────

const styles = StyleSheet.create({
	centered: {
		flex: 1,
		justifyContent: 'center',
		alignItems: 'center',
	},
	errorText: {
		textAlign: 'center',
	},
	footer: {
		alignItems: 'center',
	},
});
