/**
 * Abilities discovery — walks /wp-abilities/v1/abilities and builds a catalog.
 *
 * The Abilities REST API is paginated. discoverAbilities() loops through
 * all pages and returns a fully-populated AbilityCatalog. For sites with
 * hundreds of abilities this is a few sequential requests at startup;
 * the catalog is then cached in memory for the app session.
 */

import type { Transport } from '../transports/types';
import { AbilityCatalog } from './catalog';
import type {
  AbilityDescriptor,
  AbilityListParams,
  AbilityCategory,
} from './types';

const DEFAULT_PER_PAGE = 100;
const ABILITIES_PATH = 'wp-abilities/v1/abilities';
const CATEGORIES_PATH = 'wp-abilities/v1/categories';

/**
 * Fetch a single page of abilities.
 *
 * Returns the items only — pagination is driven by the discovery loop.
 */
export async function fetchAbilitiesPage(
  transport: Transport,
  params: AbilityListParams = {},
): Promise<AbilityDescriptor[]> {
  const query = new URLSearchParams();
  query.set('per_page', String(params.perPage ?? DEFAULT_PER_PAGE));
  query.set('page', String(params.page ?? 1));
  if (params.category) {
    query.set('category', params.category);
  }

  return transport.request<AbilityDescriptor[]>({
    path: `${ABILITIES_PATH}?${query.toString()}`,
    method: 'GET',
  });
}

/**
 * Fetch one ability descriptor by name.
 *
 * Useful for refreshing a single ability's schema without re-discovering
 * the whole catalog.
 */
export async function fetchAbility(
  transport: Transport,
  name: string,
): Promise<AbilityDescriptor> {
  return transport.request<AbilityDescriptor>({
    path: `${ABILITIES_PATH}/${encodeURIComponent(name)}`,
    method: 'GET',
  });
}

/**
 * Fetch all available ability categories.
 */
export async function fetchAbilityCategories(
  transport: Transport,
): Promise<AbilityCategory[]> {
  return transport.request<AbilityCategory[]>({
    path: CATEGORIES_PATH,
    method: 'GET',
  });
}

/**
 * Discover all abilities on a WordPress site and return a populated catalog.
 *
 * Walks pages until an empty page is returned. The Abilities API caps
 * `per_page` at 100, so a site with N abilities resolves in ⌈N / 100⌉ requests.
 *
 * Optionally filter by category — useful when an app only cares about a
 * subset of the surface (e.g. only `wp-native/*` abilities for the auth
 * bootstrap flow).
 */
export async function discoverAbilities(
  transport: Transport,
  options: { category?: string; perPage?: number } = {},
): Promise<AbilityCatalog> {
  const perPage = options.perPage ?? DEFAULT_PER_PAGE;
  const all: AbilityDescriptor[] = [];
  let page = 1;

  while (true) {
    const params: AbilityListParams = { page, perPage };
    if (options.category !== undefined) {
      params.category = options.category;
    }
    const items = await fetchAbilitiesPage(transport, params);

    if (items.length === 0) {
      break;
    }

    all.push(...items);

    if (items.length < perPage) {
      break;
    }

    page += 1;
  }

  return new AbilityCatalog(all);
}
