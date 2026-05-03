# Changelog

## 0.0.1 — 2026-05-02

Initial v0.0.1 stub published to claim the npm name.

Includes the full universal client surface:
- `WPNativeClient` with abilities discovery + `execute()`/`executeUnchecked()`
- Three transports: `FetchTransport`, `AuthFetchTransport`, `WpApiFetchTransport`
- `AbilityCatalog` for indexed lookup by name/category/namespace
- Subpath export `/wordpress` for Gutenberg block consumers
