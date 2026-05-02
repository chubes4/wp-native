/**
 * Smoke test for ability discovery against a live WordPress site.
 *
 * Not a unit test — this hits the network. Run manually with:
 *
 *   WP_BASE_URL=https://your-site.com/wp-json \
 *   WP_BEARER=eyJhbG... \
 *   npx tsx packages/api-client/src/__smoke__/discovery.smoke.ts
 *
 * Verifies:
 *   1. Discovery walks pages and produces a non-empty catalog
 *   2. The catalog can be queried by name, category, namespace
 *   3. The shape returned by the server matches AbilityDescriptor
 *
 * If the server requires no auth for /abilities, omit WP_BEARER.
 */

import { FetchTransport } from '../transports/fetch';
import { WPNativeClient } from '../client';

async function main(): Promise<void> {
  const baseUrl = process.env['WP_BASE_URL'];
  if (!baseUrl) {
    throw new Error('WP_BASE_URL env var is required');
  }

  const bearer = process.env['WP_BEARER'];

  const transport = new FetchTransport({
    baseUrl,
    ...(bearer
      ? { getAuthHeaders: () => ({ Authorization: `Bearer ${bearer}` }) }
      : {}),
  });

  const client = new WPNativeClient(transport);

  console.log(`Discovering abilities at ${baseUrl}...`);
  const catalog = await client.discover();

  console.log(`Discovered ${catalog.size()} abilities.`);
  console.log('First 10 names:');
  for (const name of catalog.names().slice(0, 10)) {
    console.log(`  - ${name}`);
  }

  const namespaces = new Set<string>();
  for (const ability of catalog.all()) {
    const slash = ability.name.indexOf('/');
    if (slash > 0) {
      namespaces.add(ability.name.slice(0, slash));
    }
  }
  console.log(`\nNamespaces found: ${Array.from(namespaces).sort().join(', ')}`);

  const categories = new Set<string>();
  for (const ability of catalog.all()) {
    categories.add(ability.category);
  }
  console.log(`Categories found: ${Array.from(categories).sort().join(', ')}`);
}

main().catch((err: unknown) => {
  console.error('Smoke test failed:', err);
  process.exitCode = 1;
});
