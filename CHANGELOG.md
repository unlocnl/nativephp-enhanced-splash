# Changelog

## 1.1.0 - 2026-10-07

### Fixed

- Android icon mode draws the icon when the app is launched from a notification or a deeplink. Android 13+ shows a solid-color splash for those launches unless the theme asks for the icon.

### Changed

- Requires `nativephp/mobile` 4.4.0 or later, which emits the iOS launch image set whole on every build. The plugin leaves restoring that set to core.

## 1.0.0 - 2026-08-11

Initial release.
