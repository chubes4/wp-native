/**
 * Navigation types for wp-native-shell.
 *
 * Matches SHELL.md M5.3 contract.
 */

import type { ComponentType } from 'react';
import type { AuthState } from '../auth';

/**
 * A single navigation section in the drawer.
 *
 * Each section maps to either a screen component or an ability name
 * (or both). Sections with only an `ability` and no `screen` render a
 * placeholder in M5; M6 replaces them with generic ability-driven screens.
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
}

/**
 * Top-level navigation configuration.
 */
export interface WPNativeNavigationConfig {
  /** Ordered list of drawer sections. */
  sections: ReadonlyArray<NavigationSection>;
}
