/**
 * Navigation types for wp-native-shell.
 *
 * Matches SHELL.md M5.3 contract + M6.3 additive fields.
 */

import type { ComponentType } from 'react';
import type { AuthState } from '../auth';
import type { AbilityListAdapter } from '../screens/ability-list-types';
import type { AbilityDetailAdapter } from '../screens/ability-detail-types';

/**
 * A single navigation section in the drawer.
 *
 * Each section maps to either a screen component or an ability name
 * (or both). Sections with only an `ability` and no `screen` render a
 * placeholder in M5; M6 replaces them with generic ability-driven screens.
 *
 * M6 additions (all optional, additive — M5.3 sections still work):
 * - `listAdapter` — drives AbilityList rendering when `ability` is set.
 * - `detailAbility` — ability name for the detail screen.
 * - `detailAdapter` — drives AbilityDetail rendering.
 */
export interface NavigationSection {
  /** Unique identifier for this section. */
  id: string;

  /** Display label in the drawer. */
  label: string;

  /**
   * Ability name that drives this section's data.
   * Used by M6 generic screens and by the placeholder in M5.
   */
  ability?: string | undefined;

  /**
   * Custom screen component to render for this section.
   * When absent, a placeholder is shown (M5) or a generic ability
   * screen is used (M6).
   */
  screen?: ComponentType | undefined;

  /**
   * Optional visibility predicate evaluated against the current auth state.
   * When absent, the section is always visible.
   */
  visibleWhen?: ((auth: AuthState) => boolean) | undefined;

  // ── M6 additions (all optional, additive) ──────────────────────────────

  /**
   * Adapter for the generic AbilityList screen (M6.1).
   * When set alongside `ability`, SectionScreen renders a generic list
   * instead of the M5.3 placeholder.
   */
  listAdapter?: AbilityListAdapter<unknown> | undefined;

  /**
   * Ability name used to fetch detail data for a selected list item.
   * Used together with `detailAdapter` to enable list→detail navigation.
   */
  detailAbility?: string | undefined;

  /**
   * Adapter for the generic AbilityDetail screen (M6.2).
   * Used together with `detailAbility` to enable list→detail navigation.
   */
  detailAdapter?: AbilityDetailAdapter<unknown> | undefined;
}

/**
 * Top-level navigation configuration.
 */
export interface WPNativeNavigationConfig {
  /** Ordered list of drawer sections. */
  sections: ReadonlyArray<NavigationSection>;
}
