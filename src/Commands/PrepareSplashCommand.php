<?php

namespace Unloc\NativephpEnhancedSplash\Commands;

use Illuminate\Support\Facades\File;
use Native\Mobile\Plugins\Commands\NativePluginHookCommand;

/**
 * Runs during pre_compile, after the core build has written its own splash
 * assets, theme and configuration — so anything written here wins.
 *
 * Switching a mode back restores core's own version byte for byte, always from
 * the core package itself, so a core upgrade is never undone.
 */
class PrepareSplashCommand extends NativePluginHookCommand
{
    protected $signature = 'nativephp:enhanced-splash:prepare';

    protected $description = 'Apply the enhanced-splash configuration to the native project';

    /** Marks every line this command adds to core-owned Kotlin, so it can undo them. */
    private const MARK = '// enhanced-splash';

    /** The launcher activity's manifest entry, which icon mode themes on its own. */
    private const ACTIVITY = 'android:name=".ui.MainActivity"';

    private const ACTIVITY_THEME = "\n            android:theme=\"@style/Theme.AndroidPHP.Splash\"";

    private const APP_THEME = 'android:theme="@style/Theme.AndroidPHP"';

    private const SPLASH_THEME = 'android:theme="@style/Theme.AndroidPHP.Splash"';

    /** Exponent of the superellipse iOS clips app icons to. */
    private const SQUIRCLE_EXPONENT = 5.0;

    /** Samples per axis along the mask's edge; 3 means 9 per pixel. */
    private const MASK_SAMPLES = 3;

    /**
     * Shadow geometry as fractions of the icon's width, so it scales with the
     * icon. Aesthetic values — iOS publishes no launch screen shadow spec.
     */
    private const SHADOW_BLUR = 0.022;

    private const SHADOW_OFFSET = 0.012;

    private const SHADOW_OPACITY = 10;

    /**
     * How much wider the rendered asset is than the icon inside it. A shadow
     * needs transparent margin, and the templates scale their frame by this so
     * the icon still measures icon_size on screen.
     */
    private float $assetScale = 1.0;

    public function handle(): int
    {
        if ($this->isAndroid()) {
            $this->prepareAndroid();
        }

        if ($this->isIos()) {
            $this->prepareIos();
        }

        return self::SUCCESS;
    }

    // =========================================================================
    // Android
    // =========================================================================

    protected function prepareAndroid(): void
    {
        $icon = config('enhanced-splash.android.mode') === 'icon';

        // The theme only makes sense next to the Kotlin that hands it back:
        // icon mode's parent theme needs installSplashScreen() to release it,
        // and image mode's window color needs the overlay repainted to match.
        if (! $this->patchMainActivity($icon)) {
            $this->warn('enhanced-splash: MainActivity.kt does not match the expected source, leaving the splash alone.');

            // The manifest goes back before the style it names is removed, or
            // resource linking fails on a style that is no longer defined.
            $this->unpatchManifest();
            $this->removeThemes();

            return;
        }

        $this->writeThemes($icon);
        $this->patchManifest($icon);

        $this->components->twoColumnDetail(
            '<fg=blue>System splash</>',
            $icon ? 'held until first content' : 'colored to match the overlay'
        );
    }

    /**
     * Native mode hands the whole splash to the platform: hold the system
     * splash until the first content frame, and stop the Compose overlay from
     * ever drawing. Image mode keeps the overlay but repaints its backdrop, so
     * the system splash, the window and the overlay are one continuous color.
     *
     * Core sanctions this: MainActivity.kt documents that splash plugins patch
     * it by exact string match. Exact matching is also the weak point, so a
     * missed anchor is reported rather than left half-applied.
     *
     * @return bool Whether every anchor the mode needs was found.
     */
    private function patchMainActivity(bool $icon): bool
    {
        $path = $this->buildPath().'/app/src/main/java/com/nativephp/mobile/ui/MainActivity.kt';

        if (! File::exists($path)) {
            return false;
        }

        $content = File::get($path);

        // Undo first, so every build starts from core's own source. Rewritten
        // lines are put back by hand; purely added ones are dropped wholesale.
        $content = str_replace('visible = false, '.self::MARK, 'visible = showSplash,', $content);
        $content = preg_replace(
            '/^([ \t]*)\.background\(.*'.preg_quote(self::MARK, '/').'$/m',
            '$1.background(Color.Black),',
            $content
        );
        $content = preg_replace('/^.*'.preg_quote(self::MARK, '/').'$\n?/m', '', $content);

        $anchors = $icon ? [
            'import androidx.fragment.app.FragmentActivity' => "import androidx.fragment.app.FragmentActivity\n".
                'import androidx.core.splashscreen.SplashScreen.Companion.installSplashScreen '.self::MARK,

            // installSplashScreen() must run before super.onCreate().
            "override fun onCreate(savedInstanceState: Bundle?) {\n        super.onCreate(savedInstanceState)" => "override fun onCreate(savedInstanceState: Bundle?) {\n".
                '        val splashScreen = installSplashScreen() '.self::MARK."\n".
                "        super.onCreate(savedInstanceState)\n".
                '        splashScreen.setKeepOnScreenCondition { showSplash } '.self::MARK,

            // showSplash still flips at onFirstContent — that releases the hold.
            'visible = showSplash,' => 'visible = false, '.self::MARK,
        ] : [
            '.background(Color.Black),' => '.background('.$this->composeColor().'), '.self::MARK,
        ];

        $patched = true;

        foreach ($anchors as $search => $replace) {
            $content = str_replace($search, $replace, $content, $count);
            $patched = $patched && $count > 0;
        }

        File::put($path, $content);

        return $patched;
    }

    /**
     * The splash style is a resource file of our own rather than an edit to
     * core's themes.xml: Android merges every file under a values directory, so
     * a style only has to be declared, never injected. Removing it is removing
     * a file, which is why no mode leaves a trace in core's own resources.
     */
    private function writeThemes(bool $icon): void
    {
        $backgrounds = [
            'values' => config('enhanced-splash.android.background'),
            'values-night' => config('enhanced-splash.android.background_dark'),
        ];

        foreach ($backgrounds as $directory => $background) {
            $color = $this->androidColor((string) $background);

            $items = $icon ? [
                '<item name="windowSplashScreenBackground">'.$color.'</item>',
                '<item name="windowSplashScreenAnimatedIcon">@mipmap/ic_launcher</item>',
                '<item name="postSplashScreenTheme">@style/Theme.AndroidPHP</item>',
            ] : [
                // The platform attributes, not androidx's compat ones: nothing
                // calls installSplashScreen() in this mode, so the style has to
                // stay usable as the app's own theme.
                '<item name="android:windowSplashScreenBackground">'.$color.'</item>',
                '<item name="android:windowBackground">'.$color.'</item>',
            ];

            // Icon mode is handed back to the app theme by installSplashScreen();
            // image mode has to be the app theme, so it inherits it instead.
            $parent = $icon ? 'Theme.SplashScreen' : 'Theme.AndroidPHP';
            $body = implode("\n        ", $items);

            $path = $this->themePath($directory);
            File::ensureDirectoryExists(dirname($path));
            File::put($path, <<<XML
                <?xml version="1.0" encoding="utf-8"?>
                <resources>
                    <style name="Theme.AndroidPHP.Splash" parent="{$parent}">
                        {$body}
                    </style>
                </resources>

                XML);
        }
    }

    private function removeThemes(): void
    {
        foreach (['values', 'values-night'] as $directory) {
            if (File::exists($this->themePath($directory))) {
                File::delete($this->themePath($directory));
            }
        }
    }

    private function themePath(string $directory): string
    {
        return $this->buildPath()."/app/src/main/res/{$directory}/enhanced_splash.xml";
    }

    /**
     * Image mode's splash style is a child of the app's own theme, so the
     * application may wear it and every window starts the right color.
     *
     * Icon mode's is a child of androidx's Theme.SplashScreen, which is not a
     * Material Components theme and is only handed back by installSplashScreen()
     * — which runs in MainActivity. At application scope, any other activity a
     * plugin injects would be left wearing it, and Material widgets there throw.
     */
    private function patchManifest(bool $icon): void
    {
        $content = $this->restoredManifest();

        if ($content === null) {
            return;
        }

        File::put($this->manifestPath(), $icon
            ? str_replace(self::ACTIVITY, self::ACTIVITY.self::ACTIVITY_THEME, $content)
            : $this->themeApplication($content, self::APP_THEME, self::SPLASH_THEME));
    }

    private function unpatchManifest(): void
    {
        $content = $this->restoredManifest();

        if ($content === null) {
            return;
        }

        File::put($this->manifestPath(), $content);
    }

    /**
     * Core's own manifest, whichever scope the last build themed.
     *
     * @return string|null Null when there is no manifest to read.
     */
    private function restoredManifest(): ?string
    {
        if (! File::exists($this->manifestPath())) {
            return null;
        }

        $content = str_replace(
            self::ACTIVITY.self::ACTIVITY_THEME,
            self::ACTIVITY,
            File::get($this->manifestPath())
        );

        return $this->themeApplication($content, self::SPLASH_THEME, self::APP_THEME);
    }

    /**
     * Rewrite the theme on the application's own opening tag and nowhere else.
     * Core themes nothing but the application today, so an exact-string swap
     * would work — and would follow the theme onto any element that later
     * carries it, including one another plugin injected.
     */
    private function themeApplication(string $content, string $from, string $to): string
    {
        return preg_replace_callback(
            '/<application\b[^>]*>/',
            fn (array $match): string => str_replace($from, $to, $match[0]),
            $content,
            1
        );
    }

    private function manifestPath(): string
    {
        return $this->buildPath().'/app/src/main/AndroidManifest.xml';
    }

    /**
     * Android theme colors are #AARRGGBB; the config authors plain #RRGGBB.
     */
    private function androidColor(string $value): string
    {
        return '#'.$this->androidHex($value);
    }

    /**
     * The overlay backdrop is drawn in Compose, which takes a 0xAARRGGBB literal
     * and resolves dark mode itself — the themes.xml pair can't reach it.
     */
    private function composeColor(): string
    {
        $light = $this->androidHex((string) config('enhanced-splash.android.background'));
        $dark = $this->androidHex((string) config('enhanced-splash.android.background_dark'));

        return "if (isSystemInDarkTheme()) Color(0x{$dark}) else Color(0x{$light})";
    }

    /**
     * Colors are authored in CSS order (#RRGGBBAA); Android literals are AARRGGBB.
     */
    private function androidHex(string $value): string
    {
        $hex = strtoupper(ltrim($this->validHex($value), '#'));

        if (strlen($hex) === 6) {
            return 'FF'.$hex;
        }

        return substr($hex, 6, 2).substr($hex, 0, 6);
    }

    // =========================================================================
    // iOS
    // =========================================================================

    protected function prepareIos(): void
    {
        if (config('enhanced-splash.ios.mode') === 'icon') {
            // The launch screen draws the app icon, not the LaunchImage.
            $this->restoreBitmapLaunchImage();
            $this->applyIconLaunchScreen();

            return;
        }

        $this->restoreIconLaunchScreen();
        $this->applyVectorLaunchImage();
    }

    /**
     * Draw the app icon centered on the configured background, on both the
     * storyboard and the SwiftUI splash that follows it — otherwise the
     * hand-off between them is a second, visibly different splash.
     */
    private function applyIconLaunchScreen(): void
    {
        $icon = $this->buildPath().'/NativePHP/Assets.xcassets/AppIcon.appiconset/icon.png';

        if (! File::exists($icon)) {
            $this->warn('enhanced-splash: no app icon found, leaving the launch screen alone.');

            // An icon that has gone missing would otherwise leave the previous
            // build's launch screen wired up to an icon the app no longer has.
            $this->restoreIconLaunchScreen();

            return;
        }

        $this->writeLaunchIcon($icon);
        $this->writeLaunchBackground();

        $this->renderTemplate('LaunchScreen.storyboard', $this->storyboardPath());
        $this->renderTemplate('SplashView.swift', $this->splashViewPath());

        $this->components->twoColumnDetail('<fg=blue>Launch screen</>', 'app icon on background');
    }

    private function restoreIconLaunchScreen(): void
    {
        $storyboard = $this->restoreFromCore('LaunchScreen.storyboard', $this->storyboardPath());
        $splashView = $this->restoreFromCore('SplashView.swift', $this->splashViewPath());

        // Xcode fails the build on an asset catalog entry the storyboard still
        // names, so these only go once nothing is pointing at them.
        if (! $storyboard || ! $splashView) {
            return;
        }

        File::deleteDirectory($this->assetPath('LaunchIcon.imageset'));
        File::deleteDirectory($this->assetPath('LaunchBackground.colorset'));
    }

    /**
     * These two files are installed verbatim, so the core package is the
     * authority on them. Restoring a copy taken when icon mode was switched on
     * would reinstate the pre-upgrade version if the core package moved on.
     *
     * Only files still carrying our launch icon are ours to put back.
     *
     * @return bool Whether the target is core's own version afterwards.
     */
    private function restoreFromCore(string $name, string $target): bool
    {
        if (! File::exists($target) || ! str_contains(File::get($target), 'LaunchIcon')) {
            return true;
        }

        $source = $this->corePath($name);

        if (! File::exists($source)) {
            return false;
        }

        File::copy($source, $target);

        return true;
    }

    /**
     * A file in the core package's own iOS template tree, which is where the
     * native project is installed from.
     */
    private function corePath(string $name): string
    {
        return base_path('vendor/nativephp/mobile/resources/xcode/NativePHP/'.$name);
    }

    private function writeLaunchIcon(string $icon): void
    {
        $imageset = $this->assetPath('LaunchIcon.imageset');
        File::ensureDirectoryExists($imageset);

        $target = $imageset.'/icon.png';

        $this->assetScale = 1.0;

        if (! config('enhanced-splash.ios.icon_rounded', true) || ! $this->maskToSquircle($icon, $target)) {
            File::copy($icon, $target);
        }

        if (config('enhanced-splash.ios.icon_shadow', false)) {
            $this->addShadow($target);
        }

        File::put($imageset.'/Contents.json', $this->json([
            'images' => [['idiom' => 'universal', 'filename' => 'icon.png', 'scale' => '1x']],
            'info' => ['author' => 'xcode', 'version' => 1],
        ]));
    }

    /**
     * Bake the iOS icon silhouette into the image as an alpha mask. It has to
     * be baked: a launch storyboard renders before the app process exists, so
     * nothing can round the image at display time.
     *
     * The silhouette is the superellipse |x|^n + |y|^n = 1, which is what gives
     * an iOS icon corners that flow into its edges instead of meeting them at
     * an arc. Edge pixels are supersampled so the curve doesn't come out jagged.
     */
    private function maskToSquircle(string $source, string $target): bool
    {
        $image = @imagecreatefrompng($source);

        if ($image === false) {
            return false;
        }

        $size = min(imagesx($image), imagesy($image));
        $canvas = imagecreatetruecolor($size, $size);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagecopy(
            $canvas, $image, 0, 0,
            intdiv(imagesx($image) - $size, 2),
            intdiv(imagesy($image) - $size, 2),
            $size, $size
        );
        imagedestroy($image);

        $radius = $size / 2;

        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                $covered = $this->squircleCoverage($x, $y, $radius);

                if ($covered === self::MASK_SAMPLES ** 2) {
                    continue;
                }

                $color = imagecolorat($canvas, $x, $y);
                // GD alpha runs 0 (opaque) to 127 (transparent).
                $opacity = (127 - (($color >> 24) & 0x7F)) * $covered / self::MASK_SAMPLES ** 2;
                $alpha = 127 - (int) round($opacity);

                imagesetpixel($canvas, $x, $y, ($color & 0xFFFFFF) | ($alpha << 24));
            }
        }

        $written = imagepng($canvas, $target);
        imagedestroy($canvas);

        return $written;
    }

    /**
     * How many of the pixel's samples fall inside the superellipse.
     */
    private function squircleCoverage(int $x, int $y, float $radius): int
    {
        $covered = 0;

        for ($sy = 0; $sy < self::MASK_SAMPLES; $sy++) {
            $dy = abs(($y + ($sy + 0.5) / self::MASK_SAMPLES - $radius) / $radius) ** self::SQUIRCLE_EXPONENT;

            for ($sx = 0; $sx < self::MASK_SAMPLES; $sx++) {
                $dx = abs(($x + ($sx + 0.5) / self::MASK_SAMPLES - $radius) / $radius) ** self::SQUIRCLE_EXPONENT;

                if ($dy + $dx <= 1.0) {
                    $covered++;
                }
            }
        }

        return $covered;
    }

    /**
     * Redraw the icon on a larger transparent canvas with a soft shadow behind
     * it. The margin is what stops the blur being clipped, and it is why the
     * templates have to scale their frame by $assetScale afterwards.
     */
    private function addShadow(string $path): bool
    {
        [$width, $height] = @getimagesize($path) ?: [0, 0];

        if ($width <= 0 || $height <= 0) {
            return false;
        }

        // An unmasked icon need not be square, so it is padded out to one. The
        // templates draw a square frame and fit the asset into it, and the
        // shadow geometry is a single fraction of the icon.
        $size = max($width, $height);

        $blur = $size * self::SHADOW_BLUR;
        $offset = (int) round($size * self::SHADOW_OFFSET);
        $margin = (int) ceil($blur * 3 + $offset);

        $drawn = extension_loaded('imagick')
            ? $this->drawShadowWithImagick($path, $size, $blur, $offset, $margin)
            : $this->drawShadowWithGd($path, $size, $offset, $margin);

        if ($drawn) {
            $this->assetScale = ($size + 2 * $margin) / $size;
        }

        return $drawn;
    }

    private function drawShadowWithImagick(string $path, int $size, float $blur, int $offset, int $margin): bool
    {
        try {
            $icon = new \Imagick($path);
            $canvasSize = $size + 2 * $margin;

            $shadow = clone $icon;
            $shadow->setImageBackgroundColor(new \ImagickPixel('black'));
            $shadow->shadowImage(self::SHADOW_OPACITY, $blur, 0, 0);

            $canvas = new \Imagick;
            $canvas->newImage($canvasSize, $canvasSize, new \ImagickPixel('transparent'), 'png');
            $canvas->compositeImage(
                $shadow, \Imagick::COMPOSITE_OVER,
                intdiv($canvasSize - $shadow->getImageWidth(), 2),
                intdiv($canvasSize - $shadow->getImageHeight(), 2) + $offset
            );
            $canvas->compositeImage(
                $icon, \Imagick::COMPOSITE_OVER,
                intdiv($canvasSize - $icon->getImageWidth(), 2),
                intdiv($canvasSize - $icon->getImageHeight(), 2)
            );
            $canvas->setImageFormat('png32');
            // A Q16 ImageMagick writes 16-bit channels by default, which GD —
            // and anything else expecting plain RGBA — reads as garbage.
            $canvas->setImageDepth(8);
            $canvas->setOption('png:color-type', '6');
            $canvas->setOption('png:bit-depth', '8');
            $canvas->writeImage($path);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * GD cannot blur an alpha channel usefully, so the silhouette is carried as
     * white-on-black luminance, blurred at reduced size, and read back as alpha.
     * Coarser than Imagick's gaussian, which is why Imagick is preferred.
     */
    private function drawShadowWithGd(string $path, int $size, int $offset, int $margin): bool
    {
        $source = @imagecreatefrompng($path);

        if ($source === false) {
            return false;
        }

        // Squaring up front is also what makes the luminance pass below safe to
        // read as truecolor alpha: an unmasked icon may still be palette PNG.
        $icon = $this->squarePad($source, $size);
        imagedestroy($source);

        $canvasSize = $size + 2 * $margin;
        $scale = 4;
        $small = max(16, (int) round($canvasSize / $scale));

        // White where the icon is solid, black where it isn't.
        $luminance = imagecreatetruecolor($size, $size);
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                $level = (int) round((127 - ((imagecolorat($icon, $x, $y) >> 24) & 0x7F)) * 255 / 127);
                imagesetpixel($luminance, $x, $y, ($level << 16) | ($level << 8) | $level);
            }
        }

        $mask = imagecreatetruecolor($small, $small);
        imagefill($mask, 0, 0, imagecolorallocate($mask, 0, 0, 0));
        $inner = max(1, (int) round($small * $size / $canvasSize));
        $inset = intdiv($small - $inner, 2);
        imagecopyresampled($mask, $luminance, $inset, $inset, 0, 0, $inner, $inner, $size, $size);
        imagedestroy($luminance);

        for ($pass = 0; $pass < 6; $pass++) {
            imagefilter($mask, IMG_FILTER_GAUSSIAN_BLUR);
        }

        $spread = imagecreatetruecolor($canvasSize, $canvasSize);
        imagecopyresampled($spread, $mask, 0, $offset, 0, 0, $canvasSize, $canvasSize, $small, $small);
        imagedestroy($mask);

        $canvas = imagecreatetruecolor($canvasSize, $canvasSize);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);

        for ($y = 0; $y < $canvasSize; $y++) {
            for ($x = 0; $x < $canvasSize; $x++) {
                $level = imagecolorat($spread, $x, $y) & 0xFF;
                $opacity = $level / 255 * 127 * self::SHADOW_OPACITY / 100;
                imagesetpixel($canvas, $x, $y, (127 - (int) round($opacity)) << 24);
            }
        }
        imagedestroy($spread);

        imagealphablending($canvas, true);
        imagecopy($canvas, $icon, $margin, $margin, 0, 0, $size, $size);
        imagealphablending($canvas, false);

        $written = imagepng($canvas, $path);

        imagedestroy($icon);
        imagedestroy($canvas);

        return $written;
    }

    /**
     * Center the image on a transparent truecolor square of the given side.
     */
    private function squarePad(\GdImage $image, int $size): \GdImage
    {
        $square = imagecreatetruecolor($size, $size);
        imagealphablending($square, false);
        imagesavealpha($square, true);
        imagefill($square, 0, 0, imagecolorallocatealpha($square, 0, 0, 0, 127));
        imagecopy(
            $square, $image,
            intdiv($size - imagesx($image), 2),
            intdiv($size - imagesy($image), 2),
            0, 0, imagesx($image), imagesy($image)
        );

        return $square;
    }

    private function writeLaunchBackground(): void
    {
        $colorset = $this->assetPath('LaunchBackground.colorset');
        File::ensureDirectoryExists($colorset);

        $light = $this->iosColor((string) config('enhanced-splash.ios.background'));
        $dark = $this->iosColor((string) config('enhanced-splash.ios.background_dark'));
        $dark['appearances'] = [['appearance' => 'luminosity', 'value' => 'dark']];

        File::put($colorset.'/Contents.json', $this->json([
            'colors' => [$light, $dark],
            'info' => ['author' => 'xcode', 'version' => 1],
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function iosColor(string $value): array
    {
        $hex = ltrim($this->validHex($value), '#');
        $alpha = strlen($hex) === 8 ? hexdec(substr($hex, 6, 2)) / 255 : 1.0;

        return [
            'idiom' => 'universal',
            'color' => [
                'color-space' => 'srgb',
                'components' => [
                    'red' => '0x'.strtoupper(substr($hex, 0, 2)),
                    'green' => '0x'.strtoupper(substr($hex, 2, 2)),
                    'blue' => '0x'.strtoupper(substr($hex, 4, 2)),
                    'alpha' => number_format($alpha, 3, '.', ''),
                ],
            ],
        ];
    }

    private function applyVectorLaunchImage(): void
    {
        $imageset = $this->launchImagePath();

        if (! $this->isValidSvg(public_path('splash.svg'))) {
            $this->restoreBitmapLaunchImage();

            return;
        }

        File::ensureDirectoryExists($imageset);

        $images = [['idiom' => 'universal', 'filename' => 'splash.svg']];
        File::copy(public_path('splash.svg'), $imageset.'/splash.svg');

        if ($this->isValidSvg(public_path('splash-dark.svg'))) {
            File::copy(public_path('splash-dark.svg'), $imageset.'/splash-dark.svg');
            $images[] = [
                'idiom' => 'universal',
                'filename' => 'splash-dark.svg',
                'appearances' => [['appearance' => 'luminosity', 'value' => 'dark']],
            ];
        }

        // An image set may not mix a vector "Any" scale with bitmap children.
        foreach (File::glob($imageset.'/*.png') as $png) {
            File::delete($png);
        }

        File::put($imageset.'/Contents.json', $this->json([
            'images' => $images,
            'info' => ['author' => 'xcode', 'version' => 1],
            'properties' => [
                'pre-rendered' => true,
                'preserves-vector-representation' => true,
            ],
        ]));

        $this->components->twoColumnDetail('<fg=blue>Vector launch image</>', 'splash.svg');
    }

    /**
     * Only a set we still own needs putting back, and the core package is the
     * authority on what to put there: it installs a default set, which is
     * exactly what an app with no splash artwork of its own is meant to have.
     * An app that does have artwork gets its set rewritten from that artwork
     * before this hook runs, so there is nothing of ours left to replace.
     */
    private function restoreBitmapLaunchImage(): void
    {
        if (! $this->ownsLaunchImage()) {
            // Core lists only what it wrote, but leaves files it did not, so
            // the vector can outlive the set that named it.
            foreach (File::glob($this->launchImagePath().'/*.svg') ?: [] as $vector) {
                File::delete($vector);
            }

            return;
        }

        $source = $this->corePath('Assets.xcassets/LaunchImage.imageset');

        if (! File::isDirectory($source)) {
            return;
        }

        File::deleteDirectory($this->launchImagePath());
        File::copyDirectory($source, $this->launchImagePath());
    }

    /**
     * Core rewrites the launch image set every build but leaves any file it did
     * not write itself, so the presence of our vector proves nothing. Its
     * Contents.json is the honest owner: core names bitmaps there, we name the
     * vector, and whoever wrote it last is who the set belongs to.
     */
    private function ownsLaunchImage(): bool
    {
        $contents = $this->launchImagePath().'/Contents.json';

        return File::exists($contents) && str_contains(File::get($contents), 'splash.svg');
    }

    private function isValidSvg(string $path): bool
    {
        if (! File::exists($path)) {
            return false;
        }

        $contents = @file_get_contents($path);

        if ($contents === false || trim($contents) === '') {
            return false;
        }

        $previous = libxml_use_internal_errors(true);
        $document = simplexml_load_string($contents);
        libxml_use_internal_errors($previous);

        return $document !== false && strtolower($document->getName()) === 'svg';
    }

    // =========================================================================
    // Paths and helpers
    // =========================================================================

    private function assetPath(string $name): string
    {
        return $this->buildPath().'/NativePHP/Assets.xcassets/'.$name;
    }

    private function launchImagePath(): string
    {
        return $this->assetPath('LaunchImage.imageset');
    }

    private function storyboardPath(): string
    {
        return $this->buildPath().'/NativePHP/LaunchScreen.storyboard';
    }

    private function splashViewPath(): string
    {
        return $this->buildPath().'/NativePHP/SplashView.swift';
    }

    private function template(string $name): string
    {
        return $this->pluginPath().'/resources/templates/'.$name;
    }

    /**
     * The storyboard's frame is design-time only — constraints drive the real
     * layout — but it is centered on the same canvas so the XIB previews true.
     */
    private function renderTemplate(string $name, string $target): void
    {
        // The drawn frame covers the whole asset, shadow margin included, so the
        // icon inside it still measures icon_size.
        $size = (int) round($this->iconSize() * $this->assetScale);

        File::put($target, strtr(File::get($this->template($name)), [
            '__ICON_SIZE__' => (string) $size,
            '__ICON_X__' => (string) round((393 - $size) / 2, 1),
            '__ICON_Y__' => (string) round((852 - $size) / 2, 1),
        ]));
    }

    /**
     * Matches Android, where an adaptive launcher icon is drawn on a 240dp
     * system splash canvas with the outer third masked — 160dp visible.
     */
    private function iconSize(): int
    {
        $size = (int) config('enhanced-splash.ios.icon_size');

        return $size > 0 ? $size : 160;
    }

    private function validHex(string $value): string
    {
        return preg_match('/^#(?:[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value) ? $value : '#FFFFFF';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function json(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
