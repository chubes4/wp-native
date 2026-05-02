/**
 * Auth transport builder for wp-native-shell.
 *
 * Bridges TokenStorageAdapter (shell's storage abstraction) to
 * AuthFetchTransport (wp-native-client's transport with 401 retry
 * and token rotation). The shell never touches fetch() directly —
 * all HTTP goes through the transport → client pipeline.
 */

import {
  AuthFetchTransport,
  WPNativeClient,
} from 'wp-native-client';
import type { StoredTokens } from 'wp-native-client';
import type { WPNativeApiConfig, TokenStorageAdapter } from './types';

const STORAGE_KEYS = {
  ACCESS_TOKEN: 'wp_native_access_token',
  REFRESH_TOKEN: 'wp_native_refresh_token',
  ACCESS_EXPIRY: 'wp_native_access_expiry',
  DEVICE_ID: 'wp_native_device_id',
} as const;

/**
 * Generate a UUID v4.
 *
 * Uses crypto.randomUUID() where available (RN 0.80+, modern browsers,
 * Node 19+). Falls back to a Math.random()-based implementation.
 */
function generateUUID(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID();
  }

  // Fallback: RFC 4122 v4 via Math.random
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0;
    const v = c === 'x' ? r : (r & 0x3) | 0x8;
    return v.toString(16);
  });
}

/**
 * Get or create a persistent device ID via the storage adapter.
 */
async function getOrCreateDeviceId(storage: TokenStorageAdapter): Promise<string> {
  const existing = await storage.getItem(STORAGE_KEYS.DEVICE_ID);
  if (existing) return existing;

  const deviceId = generateUUID();
  await storage.setItem(STORAGE_KEYS.DEVICE_ID, deviceId);
  return deviceId;
}

/**
 * Load stored tokens from the adapter. Returns null if any required
 * token field is missing.
 */
async function loadTokens(storage: TokenStorageAdapter): Promise<StoredTokens | null> {
  const [accessToken, refreshToken, expiryStr] = await Promise.all([
    storage.getItem(STORAGE_KEYS.ACCESS_TOKEN),
    storage.getItem(STORAGE_KEYS.REFRESH_TOKEN),
    storage.getItem(STORAGE_KEYS.ACCESS_EXPIRY),
  ]);

  if (!accessToken || !refreshToken || !expiryStr) return null;

  const accessExpiresAt = parseInt(expiryStr, 10);
  if (Number.isNaN(accessExpiresAt)) return null;

  return { accessToken, refreshToken, accessExpiresAt };
}

/**
 * Persist tokens via the storage adapter.
 */
async function saveTokens(storage: TokenStorageAdapter, tokens: StoredTokens): Promise<void> {
  await Promise.all([
    storage.setItem(STORAGE_KEYS.ACCESS_TOKEN, tokens.accessToken),
    storage.setItem(STORAGE_KEYS.REFRESH_TOKEN, tokens.refreshToken),
    storage.setItem(STORAGE_KEYS.ACCESS_EXPIRY, tokens.accessExpiresAt.toString()),
  ]);
}

/**
 * Clear all auth tokens from storage (device ID is preserved).
 */
async function clearTokens(storage: TokenStorageAdapter): Promise<void> {
  await Promise.all([
    storage.removeItem(STORAGE_KEYS.ACCESS_TOKEN),
    storage.removeItem(STORAGE_KEYS.REFRESH_TOKEN),
    storage.removeItem(STORAGE_KEYS.ACCESS_EXPIRY),
  ]);
}

/** Return value from buildAuthStack. */
export interface AuthStack {
  /** The authenticated transport. Call initialize() once at startup. */
  transport: AuthFetchTransport;

  /** The WPNativeClient wired to the transport. */
  client: WPNativeClient;

  /** Get the persistent device ID (lazy-creates on first call). */
  getDeviceId: () => Promise<string>;
}

/**
 * Build an AuthFetchTransport + WPNativeClient pair from shell config
 * and a consumer-supplied storage adapter.
 *
 * The returned transport is NOT yet initialized — call
 * `transport.initialize()` to load stored tokens into memory.
 */
export function buildAuthStack(
  api: WPNativeApiConfig,
  storage: TokenStorageAdapter,
  onAuthFailure?: () => void,
): AuthStack {
  const getDeviceId = () => getOrCreateDeviceId(storage);

  const config = {
    baseUrl: api.baseUrl,
    getDeviceId,
    defaultHeaders: { 'WP-Native-Client': api.clientId },
    loadTokens: () => loadTokens(storage),
    saveTokens: (tokens: StoredTokens) => saveTokens(storage, tokens),
    clearTokens: () => clearTokens(storage),
    ...(onAuthFailure !== undefined ? { onAuthFailure } : {}),
  };

  const transport = new AuthFetchTransport(config);

  const client = new WPNativeClient(transport);

  return { transport, client, getDeviceId };
}
