<?php

/**
 * A cold start crosses three surfaces before the app's own UI: the system
 * splash window, the activity window behind it, and the Compose overlay that
 * draws splash.png. Core paints all three black. These cover the colors
 * reaching every one of them, and the mode switch leaving core's source intact.
 */
beforeEach(function () {
    $this->project = androidProject();

    $this->image = [
        'mode' => 'image',
        'background' => '#0F172A',
        'background_dark' => '#020617',
    ];

    $this->iconMode = ['mode' => 'icon'] + $this->image;
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->project));
});

/**
 * The launcher activity's opening tag, which is the only place a theme may be
 * scoped without reaching activities a plugin injected.
 */
function manifestActivity(string $path): string
{
    preg_match('/<activity\b[^>]*MainActivity[^>]*>/s', projectFile($path, 'AndroidManifest.xml'), $match);

    return $match[0] ?? '';
}

describe('image mode', function () {
    it('colors the system splash and the app window', function () {
        prepareAndroid($this->project, $this->image);

        expect(projectFile($this->project, 'res/values/themes.xml'))
            ->toContain('<item name="android:windowSplashScreenBackground">#FF0F172A</item>')
            ->toContain('<item name="android:windowBackground">#FF0F172A</item>')
            ->and(projectFile($this->project, 'res/values-night/themes.xml'))
            ->toContain('<item name="android:windowSplashScreenBackground">#FF020617</item>');
    });

    /**
     * Colors are authored in CSS order everywhere in this ecosystem; Android
     * reads its literals as AARRGGBB, so passing one straight through turns
     * the alpha byte into red.
     */
    it('reorders an alpha-bearing color into Android channel order', function () {
        prepareAndroid($this->project, [
            'mode' => 'image',
            'background' => '#0F172A80',
            'background_dark' => '#020617FF',
        ]);

        expect(projectFile($this->project, 'res/values/themes.xml'))
            ->toContain('<item name="android:windowSplashScreenBackground">#800F172A</item>')
            ->and(projectFile($this->project, 'res/values-night/themes.xml'))
            ->toContain('<item name="android:windowSplashScreenBackground">#FF020617</item>')
            ->and(projectFile($this->project, 'java/com/nativephp/mobile/ui/MainActivity.kt'))
            ->toContain('Color(0xFF020617) else Color(0x800F172A)');
    });

    /**
     * This style is a child of the app's own theme, so every window may wear it.
     */
    it('names the splash theme on the application', function () {
        prepareAndroid($this->project, $this->image);

        expect(projectFile($this->project, 'AndroidManifest.xml'))
            ->toContain("<application\n")
            ->toContain('android:theme="@style/Theme.AndroidPHP.Splash"')
            ->and(manifestActivity($this->project))
            ->not->toContain('android:theme');
    });

    /**
     * Without a postSplashScreenTheme hand-off, this theme stays the app's own,
     * so it may not inherit androidx's Theme.SplashScreen.
     */
    it('extends the app theme rather than the androidx splash theme', function () {
        prepareAndroid($this->project, $this->image);

        expect(projectFile($this->project, 'res/values/themes.xml'))
            ->toContain('<style name="Theme.AndroidPHP.Splash" parent="Theme.AndroidPHP">')
            ->not->toContain('postSplashScreenTheme');
    });

    it('repaints the overlay backdrop, which resolves dark mode in Compose', function () {
        prepareAndroid($this->project, $this->image);

        expect(projectFile($this->project, 'java/com/nativephp/mobile/ui/MainActivity.kt'))
            ->toContain('.background(if (isSystemInDarkTheme()) Color(0xFF020617) else Color(0xFF0F172A)), // enhanced-splash')
            ->not->toContain('.background(Color.Black),');
    });

    it('leaves the splash overlay drawing', function () {
        prepareAndroid($this->project, $this->image);

        expect(projectFile($this->project, 'java/com/nativephp/mobile/ui/MainActivity.kt'))
            ->toContain('visible = showSplash,')
            ->not->toContain('installSplashScreen');
    });
});

describe('icon mode', function () {
    it('holds the system splash and suppresses the overlay', function () {
        prepareAndroid($this->project, $this->iconMode);

        expect(projectFile($this->project, 'java/com/nativephp/mobile/ui/MainActivity.kt'))
            ->toContain('val splashScreen = installSplashScreen() // enhanced-splash')
            ->toContain('splashScreen.setKeepOnScreenCondition { showSplash } // enhanced-splash')
            ->toContain('visible = false, // enhanced-splash');
    });

    it('keeps the backdrop black, since the overlay never draws', function () {
        prepareAndroid($this->project, $this->iconMode);

        expect(projectFile($this->project, 'java/com/nativephp/mobile/ui/MainActivity.kt'))
            ->toContain('.background(Color.Black),');
    });

    /**
     * Theme.SplashScreen is not a Material Components theme, and only
     * MainActivity is handed back off it. An activity a plugin injected would
     * inherit it from the application and throw on its first Material widget.
     */
    it('scopes the splash theme to the launcher activity', function () {
        prepareAndroid($this->project, $this->iconMode);

        expect(manifestActivity($this->project))
            ->toContain('android:theme="@style/Theme.AndroidPHP.Splash"')
            ->and(projectFile($this->project, 'AndroidManifest.xml'))
            ->toContain("android:theme=\"@style/Theme.AndroidPHP\"\n        tools:targetApi");
    });

    it('hands the window off to the app theme once released', function () {
        prepareAndroid($this->project, $this->iconMode);

        expect(projectFile($this->project, 'res/values/themes.xml'))
            ->toContain('<style name="Theme.AndroidPHP.Splash" parent="Theme.SplashScreen">')
            ->toContain('<item name="windowSplashScreenBackground">#FF0F172A</item>')
            ->toContain('<item name="postSplashScreenTheme">@style/Theme.AndroidPHP</item>');
    });
});

/**
 * Every patch here is an exact string match against core's own source, so a
 * core release that reformats MainActivity.kt is the failure mode to survive.
 * The theme is the dangerous half: icon mode's parent theme is only handed back
 * to the app by the Kotlin that did not apply.
 */
describe('anchors core has moved', function () {
    beforeEach(function () {
        $this->activity = $this->project.'/app/src/main/java/com/nativephp/mobile/ui/MainActivity.kt';
    });

    it('leaves the splash alone when icon mode cannot reach onCreate', function () {
        file_put_contents($this->activity, str_replace(
            "override fun onCreate(savedInstanceState: Bundle?) {\n        super.onCreate(savedInstanceState)",
            "override fun onCreate(savedInstanceState: Bundle?) {\n\n        super.onCreate(savedInstanceState)",
            file_get_contents($this->activity)
        ));

        $output = prepareAndroid($this->project, $this->iconMode);

        expect($output)->toContain('does not match the expected source')
            ->and(projectFile($this->project, 'res/values/themes.xml'))
            ->not->toContain('Theme.AndroidPHP.Splash')
            ->and(projectFile($this->project, 'AndroidManifest.xml'))
            ->not->toContain('Theme.AndroidPHP.Splash');
    });

    it('leaves the window uncolored when image mode cannot reach the backdrop', function () {
        file_put_contents($this->activity, str_replace(
            '.background(Color.Black),',
            '.background(Color.Unspecified),',
            file_get_contents($this->activity)
        ));

        $output = prepareAndroid($this->project, $this->image);

        expect($output)->toContain('does not match the expected source')
            ->and(projectFile($this->project, 'res/values/themes.xml'))
            ->not->toContain('enhanced-splash:begin');
    });
});

describe('rebuilds', function () {
    it('is idempotent', function () {
        prepareAndroid($this->project, $this->image);
        $first = projectFile($this->project, 'res/values/themes.xml');

        prepareAndroid($this->project, $this->image);

        expect(projectFile($this->project, 'res/values/themes.xml'))->toBe($first)
            ->and(substr_count(projectFile($this->project, 'java/com/nativephp/mobile/ui/MainActivity.kt'), '// enhanced-splash'))
            ->toBe(1);
    });

    it('recolors when the configuration changes', function () {
        prepareAndroid($this->project, $this->image);
        prepareAndroid($this->project, ['mode' => 'image', 'background' => '#FFFFFF', 'background_dark' => '#101014']);

        expect(projectFile($this->project, 'java/com/nativephp/mobile/ui/MainActivity.kt'))
            ->toContain('Color(0xFF101014) else Color(0xFFFFFFFF)')
            ->not->toContain('0xFF0F172A');
    });

    /**
     * Every build starts by undoing the last one, so switching modes must leave
     * no trace of the other — core's own source is the baseline.
     */
    it('leaves no trace of the other mode when switching', function () {
        prepareAndroid($this->project, $this->iconMode);
        prepareAndroid($this->project, $this->image);

        expect(projectFile($this->project, 'java/com/nativephp/mobile/ui/MainActivity.kt'))
            ->not->toContain('installSplashScreen')
            ->toContain('visible = showSplash,')
            ->and(projectFile($this->project, 'res/values/themes.xml'))
            ->not->toContain('Theme.SplashScreen')
            ->and(manifestActivity($this->project))->not->toContain('android:theme');

        prepareAndroid($this->project, $this->iconMode);

        expect(projectFile($this->project, 'java/com/nativephp/mobile/ui/MainActivity.kt'))
            ->toContain('.background(Color.Black),')
            ->not->toContain('isSystemInDarkTheme()) Color(');
    });
});
