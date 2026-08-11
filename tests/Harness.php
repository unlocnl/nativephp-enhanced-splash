<?php

use Illuminate\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Unloc\NativephpEnhancedSplash\Commands\PrepareSplashCommand;

/*
|--------------------------------------------------------------------------
| Build Harness
|--------------------------------------------------------------------------
|
| Loaded from bootstrap.php: Pest.php is only picked up when Pest resolves
| this package as its own root, which it does not in a host app checkout.
|
| The hook command is a console command over a native project directory. These
| give it the container the config() and File() helpers need, a throwaway
| native project per platform, and a way to run it once per configuration.
|
*/

/**
 * Copy the parts of core's Android project the command patches.
 */
function androidProject(): string
{
    $path = sys_get_temp_dir().'/enhanced-splash-'.bin2hex(random_bytes(6));

    $files = [
        'app/src/main/AndroidManifest.xml',
        'app/src/main/res/values/themes.xml',
        'app/src/main/res/values-night/themes.xml',
        'app/src/main/java/com/nativephp/mobile/ui/MainActivity.kt',
    ];

    foreach ($files as $file) {
        @mkdir(dirname($path.'/'.$file), 0777, true);
        copy(CORE_ANDROID_SOURCE.'/'.$file, $path.'/'.$file);
    }

    return $path;
}

/**
 * An app root with a native iOS project under it, carrying the app icon the
 * command reads, drawn at the given dimensions so non-square artwork can be
 * covered too. The root is the app's base path, so public/ is reachable the
 * way the vector launch image reads it.
 */
function iosProject(int $width = 512, int $height = 512): string
{
    $root = sys_get_temp_dir().'/enhanced-splash-'.bin2hex(random_bytes(6));
    $appicon = iosBuildPath($root).'/NativePHP/Assets.xcassets/AppIcon.appiconset';

    @mkdir($appicon, 0777, true);
    @mkdir($root.'/public', 0777, true);

    $icon = imagecreatetruecolor($width, $height);
    imagesavealpha($icon, true);
    imagefilledrectangle($icon, 0, 0, $width - 1, $height - 1, imagecolorallocate($icon, 220, 38, 38));
    imagepng($icon, $appicon.'/icon.png');
    imagedestroy($icon);

    return $root;
}

function iosBuildPath(string $root): string
{
    return $root.'/nativephp/ios';
}

/**
 * Stand in for core's own splash install: the bitmap launch image set it writes
 * from public/splash*.png before this hook runs, tagged so a test can tell one
 * build's output from the next.
 */
function installCoreLaunchImage(string $root, string $tag): void
{
    $imageset = iosBuildPath($root).'/NativePHP/Assets.xcassets/LaunchImage.imageset';

    is_dir($imageset) || mkdir($imageset, 0777, true);
    file_put_contents($imageset.'/splash.png', $tag);
    file_put_contents($imageset.'/Contents.json', json_encode([
        'images' => [['filename' => 'splash.png', 'idiom' => 'universal']],
        'info' => ['author' => 'xcode', 'version' => 1],
        'properties' => ['pre-rendered' => true],
    ]));
}

/**
 * Stand in for the core package's own launch screen, which is where a restore
 * reads from — not from a stash, so a core upgrade is never undone.
 */
function installCoreLaunchScreen(string $root): void
{
    $source = $root.'/vendor/nativephp/mobile/resources/xcode/NativePHP';

    is_dir($source) || mkdir($source, 0777, true);
    file_put_contents($source.'/LaunchScreen.storyboard', '<document>core launch screen</document>');
    file_put_contents($source.'/SplashView.swift', '// core splash view');
}

function buildFile(string $root, string $file): string
{
    return file_get_contents(iosBuildPath($root).'/NativePHP/'.$file);
}

/**
 * @param  array<string, mixed>  $android
 */
function prepareAndroid(string $path, array $android): string
{
    return prepare('android', $path, ['android' => $android]);
}

/**
 * @param  array<string, mixed>  $ios
 */
function prepareIos(string $root, array $ios): string
{
    return prepare('ios', iosBuildPath($root), ['ios' => $ios], $root);
}

/**
 * @param  array<string, mixed>  $config
 * @return string What the command wrote to the console.
 */
function prepare(string $platform, string $path, array $config, string $basePath = ''): string
{
    $app = new Application($basePath);
    Application::setInstance($app);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($app);

    $app['env'] = 'testing';
    $app->instance('config', new Repository(['enhanced-splash' => $config]));
    $app->instance('files', new Filesystem);

    $output = new BufferedOutput;

    $command = new PrepareSplashCommand;
    $command->setLaravel($app);
    $command->run(
        new ArrayInput([
            '--platform' => $platform,
            '--build-path' => $path,
            '--plugin-path' => dirname(__DIR__),
        ]),
        $output
    );

    return $output->fetch();
}

function projectFile(string $path, string $file): string
{
    return file_get_contents($path.'/app/src/main/'.$file);
}

function assetPath(string $root, string $name): string
{
    return iosBuildPath($root).'/NativePHP/Assets.xcassets/'.$name;
}

/**
 * @return array<string, mixed>
 */
function assetJson(string $root, string $name): array
{
    return json_decode(file_get_contents(assetPath($root, $name).'/Contents.json'), true);
}

/**
 * @return array{0: int, 1: int}
 */
function launchIconSize(string $root): array
{
    [$width, $height] = getimagesize(assetPath($root, 'LaunchIcon.imageset').'/icon.png');

    return [$width, $height];
}
