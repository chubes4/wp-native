/**
 * WPNativeClient — universal WordPress client built on the Abilities API.
 *
 * One client. No subclasses. No per-site wrappers.
 *
 * The client wraps a Transport (FetchTransport, AuthFetchTransport, or
 * WpApiFetchTransport) and exposes two surfaces:
 *
 *   1. Discovery — fetch the site's ability catalog at startup
 *   2. Execution — call abilities by name with typed input/output
 *
 * Site-specific concerns (extrachill/* abilities, woocommerce/* abilities,
 * etc.) are addressed via ability namespacing on the server side. The
 * client itself never knows the difference between a core WP ability and
 * a plugin-registered one — they're all just strings + JSON Schemas.
 *
 * Example:
 *
 *   import { WPNativeClient, AuthFetchTransport } from 'wp-native-client';
 *
 *   const client = new WPNativeClient(
 *     new AuthFetchTransport({ baseUrl: 'https://example.com/wp-json', ... })
 *   );
 *
 *   await client.discover();
 *
 *   const posts = await client.execute<Post[]>('wp/post.list', { per_page: 20 });
 *   const me    = await client.execute<User>('wp-native/user.me');
 */

import type { Transport } from './transports/types';
import { ApiError } from './transports/fetch';
import { AbilityCatalog } from './abilities/catalog';
import { discoverAbilities } from './abilities/discovery';
import type {
  AbilityDescriptor,
  AbilityExecutionResponse,
} from './abilities/types';

const RUN_PATH_PREFIX = 'wp-abilities/v1/abilities';

export interface WPNativeClientConfig {
  /**
   * Auto-fail execute() calls for abilities not present in the catalog.
   *
   * When true (default), execute() throws synchronously if the ability
   * name is not registered on the site, before making the HTTP request.
   * This catches typos and missing-plugin scenarios at the call site.
   *
   * Set to false if you want to call abilities before discover() has
   * run (auth bootstrap, for example) — but prefer executeUnchecked()
   * for that case so the intent is explicit.
   */
  validateAbilityNames?: boolean;
}

export class WPNativeClient {
  private readonly transport: Transport;
  private readonly config: Required<WPNativeClientConfig>;
  private _catalog: AbilityCatalog | null = null;

  constructor(transport: Transport, config: WPNativeClientConfig = {}) {
    this.transport = transport;
    this.config = {
      validateAbilityNames: config.validateAbilityNames ?? true,
    };
  }

  /**
   * Walk the Abilities API and populate the in-memory catalog.
   *
   * Call once at app startup, after auth is established. Subsequent
   * calls replace the catalog (cheap to re-run if the server registers
   * new abilities at runtime).
   *
   * Optionally filter to a subset by category — useful when bootstrapping
   * a minimal client that only needs auth abilities.
   */
  async discover(options: { category?: string } = {}): Promise<AbilityCatalog> {
    const filter: { category?: string } = {};
    if (options.category !== undefined) {
      filter.category = options.category;
    }
    const catalog = await discoverAbilities(this.transport, filter);
    this._catalog = catalog;
    return catalog;
  }

  /**
   * The current catalog. Throws if discover() has not been called.
   *
   * Use catalogOrNull() if you want to feature-detect without throwing.
   */
  get catalog(): AbilityCatalog {
    if (!this._catalog) {
      throw new Error(
        'WPNativeClient: catalog not loaded. Call discover() before accessing catalog.',
      );
    }
    return this._catalog;
  }

  /**
   * Non-throwing accessor for the catalog. Returns null if discover()
   * has not been called.
   */
  catalogOrNull(): AbilityCatalog | null {
    return this._catalog;
  }

  /**
   * Whether discover() has populated the catalog.
   */
  hasCatalog(): boolean {
    return this._catalog !== null;
  }

  /**
   * Execute an ability by name.
   *
   * The ability is looked up in the catalog (when validateAbilityNames is
   * enabled), then POSTed to /wp-abilities/v1/abilities/{name}/run with
   * the given input as the request body's `input` field.
   *
   * The response shape `{ result: TResult }` is unwrapped — callers receive
   * the result value directly.
   *
   * Throws:
   *   - Error    if the ability is not in the catalog (and validation enabled)
   *   - ApiError if the server returns a non-2xx response
   */
  async execute<TResult = unknown, TInput = unknown>(
    name: string,
    input?: TInput,
  ): Promise<TResult> {
    if (this.config.validateAbilityNames && this._catalog) {
      if (!this._catalog.has(name)) {
        throw new Error(
          `WPNativeClient: ability "${name}" is not registered on this site. ` +
            `Run discover() to refresh the catalog, or use executeUnchecked() ` +
            `to bypass validation.`,
        );
      }
    }

    return this.executeUnchecked<TResult, TInput>(name, input);
  }

  /**
   * Execute an ability without checking the catalog.
   *
   * Use this for the auth bootstrap path (login → discover) where the
   * client must call abilities before the catalog exists. For all other
   * call sites, prefer execute() so missing abilities fail loudly at
   * the call site.
   */
  async executeUnchecked<TResult = unknown, TInput = unknown>(
    name: string,
    input?: TInput,
  ): Promise<TResult> {
    const path = `${RUN_PATH_PREFIX}/${encodeURIComponent(name)}/run`;
    const body: { input: TInput | null } = {
      input: input === undefined ? null : input,
    };

    const response = await this.transport.request<AbilityExecutionResponse<TResult>>({
      path,
      method: 'POST',
      body: body as Record<string, unknown>,
    });

    if (!response || typeof response !== 'object' || !('result' in response)) {
      throw new ApiError(
        `WPNativeClient: malformed ability response for "${name}". ` +
          `Expected { result: ... }.`,
        'malformed_ability_response',
        500,
      );
    }

    return response.result;
  }

  /**
   * Get a single ability descriptor from the catalog.
   * Returns undefined if not registered or catalog not loaded.
   */
  describe(name: string): AbilityDescriptor | undefined {
    return this._catalog?.get(name);
  }
}
