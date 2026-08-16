# Gutenberg Autolinking Fixture

This is an isolated Expo SDK 54 application that mounts `GutenbergEditor` and calls `requestContent()` when the editor becomes ready. Its committed lockfile prevents the fixture from resolving incompatible Expo 55 modules from the monorepo root.

From the fixture directory:

```sh
npm ci
npm run typecheck
npm run test:config
CI=1 npm run prebuild
npm run test:prebuild
```

The checks compile the app entry that mounts `GutenbergEditor` and calls `requestContent()`, require Expo to execute the package config plugin, autolink the package, set `ios.deploymentTarget` to `17.0`, and write the Automattic repository to Android's Gradle properties. Native CI then compiles the generated Android and iOS projects.
