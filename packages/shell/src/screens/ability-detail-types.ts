/**
 * Type surface for the AbilityDetail screen (M6.2).
 *
 * Defines the adapter pattern that consumers use to bridge a generic
 * detail screen with their entity types. The adapter tells AbilityDetail
 * how to build the ability input, extract the entity from the response,
 * and render the result.
 *
 * @see SHELL.md M6.2
 */

import type { ReactElement } from 'react';

// ─── Adapter ─────────────────────────────────────────────────────────────────

/**
 * Consumer-supplied adapter that bridges the generic detail screen
 * to a specific entity type.
 *
 * @typeParam TEntity - The entity shape extracted from the ability response.
 */
export interface AbilityDetailAdapter<TEntity> {
	/**
	 * Optional custom input builder. When provided, the return value is
	 * passed as the `input` argument to `client.execute(ability, input)`.
	 * When absent, the component defaults to `{ id }`.
	 */
	buildInput?: (id: string | number) => Record<string, unknown>;

	/**
	 * Extract the entity from the raw ability result.
	 *
	 * The ability response shape varies by endpoint — some wrap in
	 * `{ post: {...} }`, others return the entity directly. This
	 * function normalizes whatever the server returns into `TEntity`.
	 */
	extractEntity: (result: unknown) => TEntity;

	/**
	 * Render the entity. Called only when the entity has been
	 * successfully loaded.
	 */
	render: (props: AbilityDetailRenderProps<TEntity>) => ReactElement;
}

// ─── Render props ────────────────────────────────────────────────────────────

/**
 * Props passed to the adapter's `render` function.
 */
export interface AbilityDetailRenderProps<TEntity> {
	/** The fully-loaded entity. */
	entity: TEntity;
}

// ─── Component props ─────────────────────────────────────────────────────────

/**
 * Props for the `<AbilityDetail />` component.
 */
export interface AbilityDetailProps<TEntity> {
	/** The entity identifier to fetch. */
	id: string | number;

	/** The ability name to execute (e.g. `"wp/post.get"`). */
	ability: string;

	/** Consumer-supplied adapter for input, extraction, and rendering. */
	adapter: AbilityDetailAdapter<TEntity>;
}
