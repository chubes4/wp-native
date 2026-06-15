# Changelog

<!-- Managed by homeboy. Do not hand-edit. -->

## [0.1.4] - 2026-06-15

### Fixed
- guard ability category registration against double-fire _doing_it_wrong notice

## [0.1.3] - 2026-06-01

### Changed
- remove stale M4.1 transitional scaffolding in wp-native-auth

### Fixed
- refresh-token reuse detection + atomic rotation (closes #55)

## [0.1.2] - 2026-05-27

### Fixed
- fix(wp-native-auth): use site transients for access tokens so they resolve network-wide (closes #47)

## [0.1.1] - 2026-05-23

### Fixed
- fix(wp-native-auth): use base64url token alphabet, narrow Authorization header sanitizer (closes #44)

## [0.1.0] - 2026-05-10

### Added
- generic HMAC-SHA256 external-service token signer
- feat(wp-native-auth): add register ability (8th)
- feat(wp-native-auth): add browser-handoff ability (M4.5)
- feat(wp-native-auth): wire bearer auth to access-token validator
- feat(wp-native-auth): register six auth abilities
- feat(wp-native-auth): port token service from extrachill-users
- feat(wp-native-auth): plugin bootstrap, DB table, bearer auth scaffold

### Fixed
- fix(wp-native-auth): use kebab-case ability names + load all module files
