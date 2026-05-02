# wp-native-client

Universal WordPress client built on the Abilities API.

**Status:** scaffold only. Implementation lands in M3 (see [ROADMAP](../../docs/ROADMAP.md)).

## What this package will provide

- `WPNativeClient` — single universal client, no subclasses
- `discoverAbilities()` — fetches the site's ability catalog
- `execute(abilityName, args)` — invokes any ability by name
- `AuthTransport` — token lifecycle (load / save / refresh / clear)
- `WpApiFetchTransport` — adapter for `@wordpress/api-fetch` (Gutenberg blocks)
- *(post-v0.1)* `generateTypes()` — CLI tool to codegen TypeScript types from ability JSON schemas

## The mental model

The client doesn't know what abilities exist on a given WordPress site. **It asks.** The site is the source of truth, always current, self-describing.

```ts
import { WPNativeClient, AuthTransport } from 'wp-native-client';

const client = new WPNativeClient({
  baseUrl: 'https://extrachill.com/wp-json',
  transport: new AuthTransport({ /* ... */ }),
});

// Discovery happens once at startup
await client.discoverAbilities();

// From there, anything any plugin registered is callable
const posts    = await client.execute('wp/post.list', { per_page: 20 });
const artist   = await client.execute('extrachill/artist.get', { id: 42 });
const calendar = await client.execute('extrachill/event.calendar', { venue: 'continental-club' });
```

No hand-typed wrappers. No per-site subclasses. No drift between client and server.

## Why not a typed client per site?

See the [ROADMAP](../../docs/ROADMAP.md#the-principle). Per-site typed clients defeat the purpose of a generic framework — they'd require every consumer of every WordPress site to ship their own `@whatever/api-client`. Abilities discovery is the only pattern that delivers on "any WordPress site."
