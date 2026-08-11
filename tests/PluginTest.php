<?php

use Unloc\NativephpEnhancedSplash\NativephpEnhancedSplashServiceProvider;

/**
 * Plugin validation tests for NativephpEnhancedSplash.
 *
 * Run with: ./vendor/bin/pest
 */
beforeEach(function () {
    $this->pluginPath = dirname(__DIR__);
    $this->manifestPath = $this->pluginPath.'/nativephp.json';
    $this->manifest = json_decode(file_get_contents($this->manifestPath), true);
});

describe('Plugin Manifest', function () {
    it('is valid JSON', function () {
        expect(file_exists($this->manifestPath))->toBeTrue()
            ->and(json_last_error())->toBe(JSON_ERROR_NONE);
    });

    it('has required fields', function () {
        expect($this->manifest)->toHaveKeys(['name', 'namespace', 'platforms'])
            ->and($this->manifest['name'])->toBe('unloc/nativephp-enhanced-splash')
            ->and($this->manifest['namespace'])->toBe('NativephpEnhancedSplash');
    });

    it('targets both platforms with a minimum version each', function () {
        expect($this->manifest['platforms'])->toEqualCanonicalizing(['android', 'ios'])
            ->and($this->manifest['android']['min_version'] ?? null)->not->toBeNull()
            ->and($this->manifest['ios']['min_version'] ?? null)->not->toBeNull();
    });

    it('declares no bridge functions', function () {
        expect($this->manifest['bridge_functions'])->toBe([]);
    });

    it('points at the plugin service provider', function () {
        expect($this->manifest['service_provider'])
            ->toBe(NativephpEnhancedSplashServiceProvider::class);
    });
});

describe('Lifecycle Hooks', function () {
    it('registers only a pre_compile hook', function () {
        expect(array_keys($this->manifest['hooks']))->toBe(['pre_compile']);
    });

    it('has a hook command matching the manifest signature', function () {
        $content = file_get_contents($this->pluginPath.'/src/Commands/PrepareSplashCommand.php');

        expect($content)->toContain('extends NativePluginHookCommand')
            ->and($content)->toContain('use Native\Mobile\Plugins\Commands\NativePluginHookCommand')
            ->and($content)->toContain("\$signature = '".$this->manifest['hooks']['pre_compile']."'");
    });

    it('handles both platforms', function () {
        $content = file_get_contents($this->pluginPath.'/src/Commands/PrepareSplashCommand.php');

        expect($content)->toContain('$this->isAndroid()')
            ->and($content)->toContain('$this->isIos()');
    });
});

describe('Configuration', function () {
    it('exposes a mode and both background colors per platform', function () {
        $config = require $this->pluginPath.'/config/enhanced-splash.php';

        expect($config)->toHaveKeys(['ios', 'android']);

        foreach (['ios', 'android'] as $platform) {
            expect($config[$platform])->toHaveKeys(['mode', 'background', 'background_dark']);
        }
    });

    it('defaults to the current core behavior on both platforms', function () {
        $config = require $this->pluginPath.'/config/enhanced-splash.php';

        expect($config['ios']['mode'])->toBe('image')
            ->and($config['android']['mode'])->toBe('image');
    });

    /**
     * Core themes the Android window @color/black on purpose — it matches the
     * splash overlay's backdrop, so a cold start has no white flash. A default
     * of anything else would repaint that away on install, before the app has
     * configured a thing.
     */
    it('defaults the Android background to the black core paints', function () {
        $config = require $this->pluginPath.'/config/enhanced-splash.php';

        expect($config['android']['background'])->toBe('#000000')
            ->and($config['android']['background_dark'])->toBe('#000000');
    });
});

describe('Composer Configuration', function () {
    it('is a nativephp plugin pointing at the manifest', function () {
        $composer = json_decode(file_get_contents($this->pluginPath.'/composer.json'), true);

        expect(json_last_error())->toBe(JSON_ERROR_NONE)
            ->and($composer['type'])->toBe('nativephp-plugin')
            ->and($composer['extra']['nativephp']['manifest'])->toBe('nativephp.json')
            ->and($composer['require']['nativephp/mobile'])->toBe('^4.0');
    });
});
