<?php

return [

    /*
    |--------------------------------------------------------------------------
    | iOS
    |--------------------------------------------------------------------------
    |
    | mode:            'image' - full-bleed LaunchImage (core behavior).
    |                  'icon'  - the app icon centered on the background color.
    | background:      Launch screen background color (light). Hex #RRGGBB, or
    |                  #RRGGBBAA to carry an alpha channel.
    | background_dark: Launch screen background color (dark mode).
    | icon_size:       Points the centered icon is drawn at, in 'icon' mode.
    |                  160 matches Android, which draws an adaptive launcher
    |                  icon at 160dp visible on its system splash.
    | icon_rounded:    Mask the icon to the iOS squircle, the way the home
    |                  screen does. Turn off for artwork that is already
    |                  shaped, or for a logo that should not be clipped.
    | icon_shadow:     Drop a soft shadow behind the icon. Off by default:
    |                  iOS icons have been flat since iOS 7, so this is an
    |                  aesthetic choice rather than a truer match.
    |
    | Under 'image', a public/splash.svg (and optional public/splash-dark.svg)
    | is picked up automatically as a vector LaunchImage, falling back to the
    | PNG variants when absent. 'icon' mode draws the app icon instead, so it
    | never reads either.
    |
    | '#' starts a comment in .env — quote hex values there: '#FFFFFF'
    |
    */
    'ios' => [
        'mode' => env('ENHANCED_SPLASH_IOS_MODE', 'image'),
        'background' => env('ENHANCED_SPLASH_IOS_BACKGROUND', '#FFFFFF'),
        'background_dark' => env('ENHANCED_SPLASH_IOS_BACKGROUND_DARK', '#000000'),
        'icon_size' => env('ENHANCED_SPLASH_IOS_ICON_SIZE', 160),
        'icon_rounded' => env('ENHANCED_SPLASH_IOS_ICON_ROUNDED', true),
        'icon_shadow' => env('ENHANCED_SPLASH_IOS_ICON_SHADOW', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Android
    |--------------------------------------------------------------------------
    |
    | mode:            'image' - full-bleed Compose splash overlay (core
    |                            behavior); the system splash hands off to it.
    |                  'icon'  - hold the system splash until the app is ready,
    |                            so there is no second phase at all. Draws the
    |                            launcher icon, so splash.png goes unused.
    | background:      Splash window background color (light). Hex #RRGGBB, or
    |                  #RRGGBBAA to carry an alpha channel.
    | background_dark: Splash window background color (dark mode).
    |
    | Both default to black, which is what core paints, so the plugin repaints
    | nothing until you choose a color.
    |
    | The colors apply under either mode. Under 'image' they paint all three
    | surfaces a cold start crosses — system splash, app window, and the
    | overlay's backdrop — so the hand-off to the splash image is seamless
    | instead of a cut through core's black. Match them to the image's own
    | background.
    |
    */
    'android' => [
        'mode' => env('ENHANCED_SPLASH_ANDROID_MODE', 'image'),
        'background' => env('ENHANCED_SPLASH_ANDROID_BACKGROUND', '#000000'),
        'background_dark' => env('ENHANCED_SPLASH_ANDROID_BACKGROUND_DARK', '#000000'),
    ],

];
