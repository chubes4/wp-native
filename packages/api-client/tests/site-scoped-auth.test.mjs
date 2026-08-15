import assert from 'node:assert/strict';
import test from 'node:test';
import {
  ApiError,
  AuthFetchTransport,
  WPNativeClient,
} from '../src/index.ts';

const ROOT = 'https://primary.test/wp-json';
const ALTERNATE = 'https://alternate.test/wp-json';
const FUTURE_EXPIRY = Math.floor(Date.now() / 1000) + 3600;

function createTransport(overrides = {}) {
  return new AuthFetchTransport({
    baseUrl: ROOT,
    allowedBaseUrls: [ALTERNATE],
    getDeviceId: () => 'device-id',
    loadTokens: () => null,
    saveTokens: () => {},
    clearTokens: () => {},
    ...overrides,
  });
}

function jsonResponse(data, status = 200) {
  return new Response(JSON.stringify(data), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });
}

function descriptor(name) {
  return {
    name,
    label: name,
    description: `${name} description`,
    category: 'test',
    input_schema: {},
    output_schema: {},
  };
}

test('derived clients execute against approved REST roots with isolated catalogs', async () => {
  const originalFetch = globalThis.fetch;
  const requests = [];
  const transport = createTransport();
  await transport.setTokens({
    accessToken: 'shared-access',
    refreshToken: 'shared-refresh',
    accessExpiresAt: FUTURE_EXPIRY,
  });
  const rootClient = new WPNativeClient(transport);
  const alternateClient = rootClient.derive(`${ALTERNATE}/`);

  globalThis.fetch = async (url, init) => {
    requests.push({ url: String(url), authorization: init.headers.Authorization });
    assert.equal(init.redirect, 'error');
    const requestUrl = String(url);
    if (requestUrl.includes('per_page=')) {
      return jsonResponse([
        descriptor(requestUrl.startsWith(ALTERNATE) ? 'alternate/read' : 'primary/read'),
      ]);
    }
    return jsonResponse({ site: requestUrl.startsWith(ALTERNATE) ? 'alternate' : 'primary' });
  };

  try {
    await Promise.all([rootClient.discover(), alternateClient.discover()]);

    assert.equal(rootClient.catalog.has('primary/read'), true);
    assert.equal(rootClient.catalog.has('alternate/read'), false);
    assert.equal(alternateClient.catalog.has('alternate/read'), true);
    assert.equal(alternateClient.catalog.has('primary/read'), false);

    assert.deepEqual(await rootClient.execute('primary/read'), { site: 'primary' });
    assert.deepEqual(await alternateClient.execute('alternate/read'), { site: 'alternate' });
    assert.equal(requests.every(({ authorization }) => authorization === 'Bearer shared-access'), true);
    assert.equal(requests.some(({ url }) => url.startsWith(ROOT)), true);
    assert.equal(requests.some(({ url }) => url.startsWith(ALTERNATE)), true);
  } finally {
    globalThis.fetch = originalFetch;
  }
});

test('concurrent cross-site 401s perform one refresh and share rotated tokens', async () => {
  const originalFetch = globalThis.fetch;
  let refreshRequests = 0;
  let resolveTokensSaved;
  const tokensSaved = new Promise((resolve) => {
    resolveTokensSaved = resolve;
  });
  const transport = createTransport({
    saveTokens: (tokens) => {
      if (tokens.accessToken === 'new-access') resolveTokensSaved();
    },
  });
  await transport.setTokens({
    accessToken: 'old-access',
    refreshToken: 'old-refresh',
    accessExpiresAt: FUTURE_EXPIRY,
  });
  const alternateTransport = transport.derive(ALTERNATE);

  globalThis.fetch = async (url, init) => {
    const requestUrl = String(url);
    assert.equal(init.redirect, 'error');
    if (requestUrl === `${ROOT}/wp-native/v1/auth/refresh`) {
      refreshRequests += 1;
      assert.deepEqual(JSON.parse(init.body), {
        refresh_token: 'old-refresh',
        device_id: 'device-id',
      });
      return jsonResponse({
        access_token: 'new-access',
        refresh_token: 'new-refresh',
        access_expires_at: new Date((FUTURE_EXPIRY + 3600) * 1000).toISOString(),
      });
    }

    if (init.headers.Authorization === 'Bearer old-access') {
      if (requestUrl.startsWith(ALTERNATE)) await tokensSaved;
      return jsonResponse({ code: 'unauthorized' }, 401);
    }

    assert.equal(init.headers.Authorization, 'Bearer new-access');
    return jsonResponse({ site: requestUrl.startsWith(ALTERNATE) ? 'alternate' : 'primary' });
  };

  try {
    const [primary, alternate] = await Promise.all([
      transport.request({ path: 'test/v1/read', method: 'GET' }),
      alternateTransport.request({ path: 'test/v1/read', method: 'GET' }),
    ]);

    assert.deepEqual(primary, { site: 'primary' });
    assert.deepEqual(alternate, { site: 'alternate' });
    assert.equal(refreshRequests, 1);
  } finally {
    globalThis.fetch = originalFetch;
  }
});

test('token updates and session failure propagate across derived transports', async () => {
  const originalFetch = globalThis.fetch;
  let authFailures = 0;
  let clearCalls = 0;
  const seenAuthorization = [];
  const transport = createTransport({
    clearTokens: () => {
      clearCalls += 1;
    },
    onAuthFailure: () => {
      authFailures += 1;
    },
  });
  const alternateTransport = transport.derive(ALTERNATE);
  await transport.setTokens({
    accessToken: 'updated-access',
    refreshToken: 'invalid-refresh',
    accessExpiresAt: FUTURE_EXPIRY,
  });

  globalThis.fetch = async (url, init) => {
    seenAuthorization.push(init.headers.Authorization);
    if (String(url) === `${ROOT}/wp-native/v1/auth/refresh`) {
      return jsonResponse({ code: 'invalid_refresh' }, 401);
    }
    return jsonResponse({ code: 'unauthorized' }, 401);
  };

  try {
    await assert.rejects(
      alternateTransport.request({ path: 'test/v1/read', method: 'GET' }),
      (error) => {
        assert.ok(error instanceof ApiError);
        assert.equal(error.code, 'session_expired');
        return true;
      },
    );

    assert.equal(seenAuthorization[0], 'Bearer updated-access');
    assert.equal(transport.hasTokens(), false);
    assert.equal(authFailures, 1);
    assert.equal(clearCalls, 1);

    await assert.rejects(
      transport.request({ path: 'test/v1/read', method: 'GET' }),
      (error) => error instanceof ApiError && error.code === 'unauthorized',
    );
    assert.equal(seenAuthorization.at(-1), undefined);
    assert.equal(authFailures, 1);
  } finally {
    globalThis.fetch = originalFetch;
  }
});

test('derivation rejects unapproved and invalid REST roots', () => {
  const client = new WPNativeClient(createTransport());

  assert.throws(
    () => client.derive('https://unapproved.test/wp-json'),
    /REST root is not approved/,
  );
  assert.throws(() => client.derive('https://alternate.test/other-json'), /not approved/);
  assert.throws(() => client.derive('https://alternate.test.evil/wp-json'), /not approved/);
  assert.throws(() => client.derive('https://alternate.test:8443/wp-json'), /not approved/);
  assert.throws(() => client.derive('http://alternate.test/wp-json'), /not approved/);
  assert.throws(() => client.derive('javascript:alert(1)'), /invalid REST root/);
  assert.throws(() => client.derive('/wp-json'), /invalid REST root/);
  assert.throws(() => client.derive(`${ALTERNATE}?token=leak`), /invalid REST root/);
  assert.throws(() => client.derive(`https://user:pass@alternate.test/wp-json`), /invalid REST root/);
});

test('clients report when their transport cannot derive', () => {
  const client = new WPNativeClient({ request: async () => ({}) });
  assert.throws(() => client.derive(ALTERNATE), /does not support derivation/);
});
