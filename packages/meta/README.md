# wp-native

> Turn any WordPress site into a real native app. Open source. Token auth. No WebViews.

**This is a meta package** — it intentionally has no installable surface. The actual framework lives in two installable packages:

| Package | Purpose | Install when |
|---|---|---|
| **[`wp-native-client`](https://www.npmjs.com/package/wp-native-client)** | Universal WordPress client built on the Abilities API. Discovery + execution. Three transports (fetch / auth-fetch / wp-api-fetch). | Building a Gutenberg block, a React Native app, a Node script — anything that calls a WordPress site. |
| **[`wp-native-shell`](https://www.npmjs.com/package/wp-native-shell)** | React Native app shell. Drawer, auth, theme, browser handoff, ability-driven screens. | Building a real native iOS/Android app on top of `wp-native-client`. |

## Quick start

```bash
# Block / Node
npm install wp-native-client

# React Native app
npm install wp-native-shell wp-native-client
```

See the [main repo](https://github.com/chubes4/wp-native) for documentation, the schemas contract, and the roadmap.

## License

GPL-2.0-or-later
