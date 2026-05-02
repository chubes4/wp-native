/**
 * Type definitions for the WordPress Abilities API REST surface.
 *
 * Mirrors the response shapes documented at:
 *   GET /wp-abilities/v1/abilities
 *   GET /wp-abilities/v1/abilities/{name}
 *   POST /wp-abilities/v1/abilities/{name}/run
 *
 * These types describe the wire format. They are intentionally permissive
 * about ability-specific input/output (modeled as `unknown`) because the
 * universal client can't know each ability's schema at compile time —
 * codegen from `input_schema` / `output_schema` is a post-v0.1 concern.
 */

/**
 * A single ability as returned by the catalog endpoints.
 */
export interface AbilityDescriptor {
  /**
   * Unique identifier, namespaced. Examples:
   *   wp/post.list
   *   wp-native/auth.login
   *   extrachill/artist.get
   */
  name: string;

  /** Human-readable display label. */
  label: string;

  /** Longer description of what the ability does. */
  description: string;

  /**
   * Category slug. Categories group related abilities for UI / discovery.
   * Validated server-side against /^[a-z0-9]+(?:-[a-z0-9]+)*$/ — no slashes.
   */
  category: string;

  /**
   * JSON Schema describing the shape of the `input` argument expected by
   * POST /abilities/{name}/run. May be an empty object for nullary abilities.
   */
  input_schema: Record<string, unknown>;

  /**
   * JSON Schema describing the shape of `result` returned by /run.
   */
  output_schema: Record<string, unknown>;

  /**
   * Meta information attached to the ability registration.
   */
  meta?: {
    annotations?: Record<string, unknown> | boolean | null;
    [key: string]: unknown;
  };
}

/**
 * An ability category as returned by GET /wp-abilities/v1/categories.
 */
export interface AbilityCategory {
  slug: string;
  label: string;
  description: string;
}

/**
 * Successful response from POST /wp-abilities/v1/abilities/{name}/run.
 *
 * The actual `result` shape is ability-specific; consumers narrow with
 * a type parameter at the `client.execute<TResult>()` call site.
 */
export interface AbilityExecutionResponse<TResult = unknown> {
  result: TResult;
}

/**
 * Pagination wrapper. The Abilities REST API uses standard WP REST
 * paginated lists — total count comes in the `X-WP-Total` header.
 */
export interface AbilityListPage {
  items: AbilityDescriptor[];
  totalItems: number;
  totalPages: number;
}

/**
 * Optional filters for catalog listing.
 */
export interface AbilityListParams {
  /** Filter by category slug. */
  category?: string;
  /** Page number (1-indexed). Default: 1. */
  page?: number;
  /** Items per page. Server max is typically 100. Default: 50. */
  perPage?: number;
}
