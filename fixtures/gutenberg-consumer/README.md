# Gutenberg Autolinking Fixture

This fixture gives Expo Modules autolinking a minimal consumer dependency graph without requiring an application checkout.

From the fixture directory:

```sh
npm install --package-lock=false --ignore-scripts
npx expo-modules-autolinking resolve node_modules --platform apple --project-root . --json
npx expo-modules-autolinking resolve node_modules --platform android --project-root . --json
npx expo config --type prebuild --json
CI=1 npx expo prebuild --no-install --clean
```

Both platform results must include `wp-native-gutenberg` and `WPNativeGutenbergModule`. Prebuild must set `ios.deploymentTarget` to `17.0` in `ios/Podfile.properties.json` and write the Automattic repository to `android.extraMavenRepos` in `android/gradle.properties`.
