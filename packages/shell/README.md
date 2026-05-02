# wp-native-shell

React Native app shell for [wp-native](../../README.md).

**Status:** scaffold only. Implementation lands in M5–M6 (see [ROADMAP](../../docs/ROADMAP.md)).

## What this package will provide

- `<WPNativeApp config={...}/>` — top-level shell wrapper
- `<AuthProvider/>` — token lifecycle, refresh rotation, device sessions
- `<ThemeProvider tokens={...}/>` — consumer-supplied design tokens
- `<DrawerShell sections={...}/>` — drawer navigation built from config
- `useBrowserHandoff(hosts)` — web fallback for arbitrary host allowlists
- Generic ability-driven screens — `<AbilityList/>`, `<AbilityDetail/>` — render whatever ability the consumer specifies
- `OAuthAdapter` interface — pluggable Google / Apple / etc.

## The mental model

The shell renders generic screens that **call abilities by name**. Consumer config maps each navigation slot to an ability:

```ts
{ id: 'feed',    label: 'Feed',    ability: 'wp/post.list' }
{ id: 'events',  label: 'Events',  ability: 'extrachill/event.calendar' }
```

The shell never imports a site-specific client. It uses the universal `wp-native-client` and trusts ability namespacing to handle site-specific concerns.
