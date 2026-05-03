# Changelog

## 0.1.0 — 2026-05-03

First real version. Closes the v0.1.0 cleanup work.

### Added
- `useAuth().register(email, password, passwordConfirm)` — exposes the new `wp-native/auth-register` ability through the hook
- `useAuth().client` — direct access to the underlying `WPNativeClient`

### Changed
- Migrated from `@react-navigation/drawer` self-mount to `expo-router/drawer` guest pattern. `<WPNativeApp config>{children}</WPNativeApp>` now provides contexts only; consumers mount their own expo-router layout.
- `<DrawerShell/>` removed in favor of `<DrawerContent/>` slot for use inside the consumer's `app/(drawer)/_layout.tsx`.
- `<SectionScreen/>` takes `sectionId: string` (was `section: NavigationSection`); looks up the section from `NavigationConfig` context.
- Added `<SectionDetailScreen/>` for `/[id].tsx`-style detail routes.
- `<AbilityList/>` uses `expo-router`'s `useRouter()` for navigation.
- `AbilityListAdapter.detailHref?: (item) => string` for custom detail paths.

### Removed
- `BrandProvider`, `useBrand`, `WPNativeBrandConfig` — brand identity is a consumer concern, not a WP-core primitive.
- `WPNativeOnboardingConfig` — onboarding flows are platform-specific. Consumers wrap their own auth-aware component around the children passed to `<WPNativeApp/>`.
- `<DrawerShell/>`, `<SectionStack/>` — replaced by expo-router-native pattern.

### Peer dependencies
- Removed: `@react-navigation/drawer`, `@react-navigation/native`, `@react-navigation/native-stack` (now transitive via expo-router)
- Added: `react-native-gesture-handler` (required by `expo-router/drawer`)

## 0.0.2 — 2026-05-02

expo-router rebase. Public API breaking — see 0.1.0 for the consolidated changelog.

## 0.0.1 — 2026-05-02

Initial v0.0.1 stub published to claim the npm name.
