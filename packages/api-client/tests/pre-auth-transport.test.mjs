import assert from 'node:assert/strict';
import test from 'node:test';
import { ApiError, FetchTransport } from '../dist/transports/fetch.js';

test('pre-auth request preserves challenge error without retrying', async () => {
  const originalFetch = globalThis.fetch;
  let requests = 0;

  globalThis.fetch = async (_url, init) => {
    requests += 1;
    assert.equal(init.headers.Authorization, undefined);
    assert.equal(init.headers['WP-Native-Client'], 'test-client');

    return new Response(
      JSON.stringify({ code: 'challenge_rejected', message: 'Try again.' }),
      { status: 401, headers: { 'Content-Type': 'application/json' } },
    );
  };

  try {
    const transport = new FetchTransport({
      baseUrl: 'https://example.test/wp-json',
      defaultHeaders: { 'WP-Native-Client': 'test-client' },
    });

    await assert.rejects(
      transport.request({ path: 'wp-abilities/v1/abilities/test/run', method: 'POST' }),
      (error) => {
        assert.ok(error instanceof ApiError);
        assert.equal(error.code, 'challenge_rejected');
        assert.equal(error.message, 'Try again.');
        assert.equal(error.status, 401);
        return true;
      },
    );
    assert.equal(requests, 1);
  } finally {
    globalThis.fetch = originalFetch;
  }
});
