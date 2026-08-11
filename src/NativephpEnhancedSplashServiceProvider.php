<?php

namespace Unloc\NativephpEnhancedSplash;

use Illuminate\Support\ServiceProvider;
use Unloc\NativephpEnhancedSplash\Commands\PrepareSplashCommand;

class NativephpEnhancedSplashServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // mergeConfigFrom() only merges the top level, so a config published
        // before this package gained a key would mask every default under it.
        $config = $this->app['config'];

        $config->set('enhanced-splash', $this->mergeDeep(
            require $this->configPath(),
            $config->get('enhanced-splash', [])
        ));
    }

    /**
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $published
     * @return array<string, mixed>
     */
    private function mergeDeep(array $defaults, array $published): array
    {
        foreach ($published as $key => $value) {
            $defaults[$key] = is_array($value) && is_array($defaults[$key] ?? null)
                ? $this->mergeDeep($defaults[$key], $value)
                : $value;
        }

        return $defaults;
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            $this->configPath() => config_path('enhanced-splash.php'),
        ], 'enhanced-splash-config');

        $this->commands([
            PrepareSplashCommand::class,
        ]);
    }

    private function configPath(): string
    {
        return __DIR__.'/../config/enhanced-splash.php';
    }
}
