## unloc/nativephp-enhanced-splash

Controls the launch screen: seamless or single-phase splash on Android, and an app-icon or vector
launch screen on iOS.

Build-time only: no facade, no bridge functions, no runtime API. A `pre_compile` hook
(`nativephp:enhanced-splash:prepare`) rewrites the native project's splash assets, theme and
configuration after the core build has written its own. Never call it directly — it runs as part
of `native:run`.

### Configuration

Everything lives in `config/enhanced-splash.php` (publish with
`php artisan vendor:publish --tag=enhanced-splash-config`). Never patch the generated `nativephp/`
project by hand — a rebuild overwrites it.

@verbatim
<code-snippet name="config/enhanced-splash.php" lang="php">
return [
    'ios' => [
        // 'image' = full-bleed LaunchImage | 'icon' = app icon centered on the background
        'mode' => env('ENHANCED_SPLASH_IOS_MODE', 'image'),
        'background' => env('ENHANCED_SPLASH_IOS_BACKGROUND', '#FFFFFF'),
        'background_dark' => env('ENHANCED_SPLASH_IOS_BACKGROUND_DARK', '#000000'),
        'icon_size' => env('ENHANCED_SPLASH_IOS_ICON_SIZE', 160),
        'icon_rounded' => env('ENHANCED_SPLASH_IOS_ICON_ROUNDED', true),
        'icon_shadow' => env('ENHANCED_SPLASH_IOS_ICON_SHADOW', false),
    ],
    'android' => [
        // 'image' = Compose splash overlay | 'icon' = hold the system splash
        'mode' => env('ENHANCED_SPLASH_ANDROID_MODE', 'image'),
        'background' => env('ENHANCED_SPLASH_ANDROID_BACKGROUND', '#000000'),
        'background_dark' => env('ENHANCED_SPLASH_ANDROID_BACKGROUND_DARK', '#000000'),
    ],
];
</code-snippet>
@endverbatim

Defaults reproduce core's own behavior, so installing the plugin changes nothing until configured.
Android's black is core's own window color, chosen to match the splash overlay's backdrop.

- Colors take `#RRGGBB` or `#RRGGBBAA`, always in CSS channel order — the alpha byte is reordered
  for each platform at build time. Never author Android's `#AARRGGBB` here.
- `#` starts a comment in `.env` — quote hex values there: `ENHANCED_SPLASH_IOS_BACKGROUND='#FFFFFF'`.
- Changing any of this requires a rebuild (`php artisan native:run ios|android`) — the hook only
  runs at build time.

### Input files

Splash images stay where core expects them: `public/splash.png`, `public/splash-dark.png`. On iOS a
`public/splash.svg` (and optional `public/splash-dark.svg`) is preferred over the PNGs and kept as a
vector. The icon modes read the app icon core installs from `public/icon.png`; with no app icon
present the launch screen is core's own.

### Android

- `android.background` / `background_dark` apply under **both** modes. Under `image` they paint all
  three surfaces a cold start crosses — the Android 12+ system splash, the app window, and the
  Compose overlay's backdrop — so the hand-off to `splash.png` is seamless instead of a cut through
  core's black. Set them to the color the splash image sits on.
- That color is also the app window's background after the splash, so it shows through any frame the
  UI has not painted yet. Keep it close to the app's own surface color.
- `android.mode = 'icon'` hands the splash to the platform: the system splash is held until first
  content and the Compose overlay never draws. It shows the launcher icon on `background`, so
  `splash.png` goes unused — prefer `image` when the artwork matters.

### iOS

- `ios.mode = 'icon'` draws the app icon centered on the background instead of a splash image, on
  both the launch storyboard and the SwiftUI splash, so the hand-off between them is invisible.
  `splash.svg` applies only in `image` mode.
- `ios.icon_size` (default 160 points, matching Android's system splash) sizes the icon itself, not
  the generated asset — `icon_shadow` enlarges the asset but not the icon on screen.
- `ios.icon_rounded` masks the icon to the iOS squircle so it matches the home screen. Turn it off
  for artwork that is already shaped, or a logo that should not be clipped.
- `ios.icon_shadow` lifts the icon off the background. Off by default: iOS icons are flat.

### Housekeeping

- Package defaults are merged recursively, so a `config/enhanced-splash.php` published before a new
  key existed still picks that key up. Re-publish with `--force` only to see the new comments.
- To remove the plugin: `composer remove unloc/nativephp-enhanced-splash`, then
  `php artisan native:install` to regenerate the native project from core's own sources. Not needed
  to change settings — every build undoes the previous one first.
