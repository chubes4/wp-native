# wp-native-api-client

Generic typed WordPress REST client.

**Status:** scaffold only. Implementation lands in M3 (see project roadmap).

## What this package will provide

- `WPClient` — typed client wrapping `wp/v2/*` and `wp-native/v1/*` routes
- `FetchTransport` — generic fetch-based transport for RN / Node / browser
- `AuthFetchTransport` — adds token lifecycle (load / save / refresh / clear)
- `WpApiFetchTransport` — adapter for `@wordpress/api-fetch` (Gutenberg blocks)

Lineage: forked from [`@extrachill/api-client`](https://www.npmjs.com/package/@extrachill/api-client) with platform-specific routes stripped out.
