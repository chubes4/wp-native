/**
 * AbilityCatalog — in-memory index of a WordPress site's ability registry.
 *
 * Built once at app startup via WPNativeClient.discover(). Provides O(1)
 * lookup by ability name plus convenience accessors for filtering by
 * category / namespace.
 *
 * The catalog is the source of truth for "what can this site do?" —
 * shell screens consult it to decide which ability to call for which
 * UI slot, consumers query it to feature-detect, and the client uses
 * it to fail fast when execute() is called with an unknown ability.
 */

import type { AbilityDescriptor } from './types';

export class AbilityCatalog {
  private readonly byName: Map<string, AbilityDescriptor>;

  constructor(abilities: readonly AbilityDescriptor[]) {
    this.byName = new Map(abilities.map((a) => [a.name, a]));
  }

  /**
   * Get a single ability descriptor by name.
   * Returns undefined if the ability is not registered on this site.
   */
  get(name: string): AbilityDescriptor | undefined {
    return this.byName.get(name);
  }

  /**
   * Whether the given ability is registered on this site.
   */
  has(name: string): boolean {
    return this.byName.has(name);
  }

  /**
   * All registered ability names, in catalog order.
   */
  names(): string[] {
    return Array.from(this.byName.keys());
  }

  /**
   * All registered abilities.
   */
  all(): AbilityDescriptor[] {
    return Array.from(this.byName.values());
  }

  /**
   * Abilities filtered by category slug.
   */
  byCategory(category: string): AbilityDescriptor[] {
    return this.all().filter((a) => a.category === category);
  }

  /**
   * Abilities whose name starts with the given namespace prefix.
   *
   * Example: catalog.byNamespace('wp-native') returns all wp-native/* abilities.
   */
  byNamespace(namespace: string): AbilityDescriptor[] {
    const prefix = `${namespace}/`;
    return this.all().filter((a) => a.name.startsWith(prefix));
  }

  /**
   * Total number of abilities in the catalog.
   */
  size(): number {
    return this.byName.size;
  }
}
