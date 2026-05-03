/**
 * AbilityList types — adapter, item props, and component props.
 *
 * Matches SCREENS.md M6.1 contract. The shell never knows ability
 * shapes at compile time — adapters do all type narrowing. The shell
 * internals pass `unknown` everywhere ability results flow.
 *
 * @see packages/shell/SCREENS.md § M6.1
 */

import type { ReactElement, ReactNode } from 'react';

// ─── Adapter ─────────────────────────────────────────────────────────────────

/**
 * Props passed to `adapter.renderItem()`.
 */
export interface AbilityListItemProps<TItem> {
	/** The list item to render. */
	item: TItem;
	/** Tap handler. If detail ability is configured, this navigates. */
	onPress: () => void;
}

/**
 * Adapter type. Tells the list screen how to extract items + pagination
 * metadata from the ability's result.
 */
export interface AbilityListAdapter<TItem> {
	/**
	 * Extract list items from the ability result.
	 * Called on every page; concatenated across pages.
	 */
	extractItems: (result: unknown) => TItem[];

	/**
	 * Stable identifier for an item (used as React key + detail route param).
	 */
	itemId: (item: TItem) => string | number;

	/**
	 * Render one list item. Receives the item plus theme + navigation helpers.
	 */
	renderItem: (props: AbilityListItemProps<TItem>) => ReactElement;

	/**
	 * Build the input for `client.execute(ability, input)` for a given page.
	 * Default: `{ page, per_page }` — works for any ability following WP REST
	 * pagination conventions. Override for abilities with different param names.
	 */
	buildPageInput?: ((page: number, perPage: number) => Record<string, unknown>) | undefined;

	/**
	 * Determine whether more pages are available. Default: returns true if
	 * the page returned at least `perPage` items, false otherwise. Override
	 * for abilities that return total counts in the result.
	 */
	hasNextPage?: ((result: unknown, currentItems: TItem[]) => boolean) | undefined;

	/**
	 * Page size. Default: 20.
	 */
	perPage?: number | undefined;

	/**
	 * Build the navigation path for a detail tap. Optional.
	 *
	 * When provided, `<AbilityList/>` calls `router.push(detailHref(item))`
	 * on item tap. When absent, the default is a relative push to
	 * `./{adapter.itemId(item)}` — i.e. an `[id].tsx` sibling route.
	 *
	 * Consumer can override to navigate to a custom path:
	 *   `detailHref: (item) => \`/artists/\${item.slug}\``
	 */
	detailHref?: ((item: TItem) => string) | undefined;
}

// ─── Component props ─────────────────────────────────────────────────────────

/**
 * Props for `<AbilityList/>`.
 */
export interface AbilityListProps {
	/** Ability name to call. */
	ability: string;
	/** Adapter for this ability's result shape. */
	adapter: AbilityListAdapter<unknown>;
	/** Detail ability name. If set, items become tappable. */
	detailAbility?: string | undefined;
	/** Optional empty state. */
	emptyState?: ReactNode;
	/** Optional header rendered above the list. */
	header?: ReactNode;
}
