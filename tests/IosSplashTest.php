<?php

/**
 * Icon mode draws the app icon on a color the launch storyboard and the SwiftUI
 * splash both read from the asset catalog. These cover the color reaching that
 * catalog intact, and the icon asset staying square and centered whatever shape
 * the app icon is.
 */
beforeEach(function () {
    $this->iconMode = [
        'mode' => 'icon',
        'background' => '#0F172A',
        'background_dark' => '#020617',
        'icon_size' => 160,
    ];
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->project));
});

function storyboardIconSize(string $root): int
{
    preg_match('/constant="([0-9.]+)" id="icon-width"/', file_get_contents(iosBuildPath($root).'/NativePHP/LaunchScreen.storyboard'), $match);

    return (int) $match[1];
}

function launchImage(string $root, string $file): string
{
    return file_get_contents(assetPath($root, 'LaunchImage.imageset').'/'.$file);
}

/**
 * Margins around the icon's own pixels in the written asset, clockwise from the
 * top. The icon is drawn opaque and its shadow never is, so full opacity is
 * what separates the two.
 *
 * @return array{0: int, 1: int, 2: int, 3: int}
 */
function iconMargins(string $path): array
{
    $image = imagecreatefrompng(assetPath($path, 'LaunchIcon.imageset').'/icon.png');
    [$width, $height] = [imagesx($image), imagesy($image)];
    [$minX, $maxX, $minY, $maxY] = [$width, -1, $height, -1];

    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            if (((imagecolorat($image, $x, $y) >> 24) & 0x7F) !== 0) {
                continue;
            }

            $minX = min($minX, $x);
            $maxX = max($maxX, $x);
            $minY = min($minY, $y);
            $maxY = max($maxY, $y);
        }
    }

    imagedestroy($image);

    return [$minY, $width - 1 - $maxX, $height - 1 - $maxY, $minX];
}

describe('background color', function () {
    it('carries the alpha of an alpha-bearing color into the color set', function () {
        $this->project = iosProject();

        prepareIos($this->project, ['background' => '#0F172A80'] + $this->iconMode);

        expect(assetJson($this->project, 'LaunchBackground.colorset')['colors'][0]['color']['components'])
            ->toBe([
                'red' => '0x0F',
                'green' => '0x17',
                'blue' => '0x2A',
                'alpha' => '0.502',
            ]);
    });

    it('leaves a color without alpha fully opaque', function () {
        $this->project = iosProject();

        prepareIos($this->project, $this->iconMode);

        expect(assetJson($this->project, 'LaunchBackground.colorset')['colors'][1])
            ->toMatchArray(['appearances' => [['appearance' => 'luminosity', 'value' => 'dark']]])
            ->and(assetJson($this->project, 'LaunchBackground.colorset')['colors'][1]['color']['components']['alpha'])
            ->toBe('1.000');
    });
});

describe('icon asset', function () {
    it('masks a square icon to the squircle at its own size', function () {
        $this->project = iosProject(512, 512);

        prepareIos($this->project, $this->iconMode);

        expect(launchIconSize($this->project))->toBe([512, 512])
            ->and(storyboardIconSize($this->project))->toBe(160);
    });

    /**
     * The unmasked path is for artwork that must not be clipped, which is
     * exactly the artwork that need not be square. The shadow needs a square
     * canvas, and the template's frame has to grow by the same ratio so the
     * icon inside it still measures icon_size.
     */
    it('squares up a non-square icon when drawing a shadow', function (int $iconWidth, int $iconHeight) {
        $this->project = iosProject($iconWidth, $iconHeight);

        prepareIos($this->project, ['icon_rounded' => false, 'icon_shadow' => true] + $this->iconMode);

        [$width, $height] = launchIconSize($this->project);
        [$top, $right, $bottom, $left] = iconMargins($this->project);
        $long = max($iconWidth, $iconHeight);

        expect($width)->toBe($height)
            ->and($width)->toBeGreaterThan($long)
            ->and(abs($top - $bottom))->toBeLessThanOrEqual(1)
            ->and(abs($left - $right))->toBeLessThanOrEqual(1)
            ->and(storyboardIconSize($this->project))->toBe((int) round(160 * $width / $long));
    })->with([
        'landscape' => [600, 300],
        'portrait' => [300, 600],
        'square' => [512, 512],
    ]);

    it('copies a non-square icon untouched when it is neither masked nor shadowed', function () {
        $this->project = iosProject(600, 300);

        prepareIos($this->project, ['icon_rounded' => false] + $this->iconMode);

        expect(launchIconSize($this->project))->toBe([600, 300])
            ->and(storyboardIconSize($this->project))->toBe(160);
    });
});

/**
 * The launch screen is only ours while an app icon exists to draw on it. Giving
 * it back means putting core's own files there and clearing the assets they
 * stopped naming — in that order, since Xcode fails a build on a storyboard
 * that references an asset catalog entry which is gone.
 */
describe('losing the app icon', function () {
    beforeEach(function () {
        $this->project = iosProject();

        installCoreLaunchScreen($this->project);
        prepareIos($this->project, $this->iconMode);

        unlink(assetPath($this->project, 'AppIcon.appiconset').'/icon.png');
    });

    it('hands the launch screen back to core', function () {
        $output = prepareIos($this->project, $this->iconMode);

        expect($output)->toContain('no app icon found')
            ->and(buildFile($this->project, 'LaunchScreen.storyboard'))->toBe('<document>core launch screen</document>')
            ->and(buildFile($this->project, 'SplashView.swift'))->toBe('// core splash view')
            ->and(assetPath($this->project, 'LaunchIcon.imageset'))->not->toBeDirectory()
            ->and(assetPath($this->project, 'LaunchBackground.colorset'))->not->toBeDirectory();
    });

    it('keeps the assets when core is not there to restore from', function () {
        exec('rm -rf '.escapeshellarg($this->project.'/vendor'));

        prepareIos($this->project, $this->iconMode);

        expect(buildFile($this->project, 'LaunchScreen.storyboard'))->toContain('LaunchIcon')
            ->and(assetPath($this->project, 'LaunchIcon.imageset'))->toBeDirectory()
            ->and(assetPath($this->project, 'LaunchBackground.colorset'))->toBeDirectory();
    });
});

/**
 * Vector mode substitutes an SVG for the bitmap launch image set core installs,
 * so it has to be able to hand that set back. Core rewrites the set from
 * public/splash*.png on every build, ahead of this hook, and ships a default
 * set for apps with no artwork of their own — between them they are the only
 * two things the set is ever meant to hold, so nothing has to be kept aside.
 */
describe('handing the launch image back', function () {
    beforeEach(function () {
        $this->project = iosProject();
        $this->vectorMode = ['mode' => 'image'] + $this->iconMode;

        installCoreLaunchImageTemplate($this->project);

        file_put_contents($this->project.'/public/splash.svg', '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>');
    });

    it('substitutes the vector for what core installed', function () {
        installCoreLaunchImage($this->project, 'first build');

        prepareIos($this->project, $this->vectorMode);

        expect(launchImage($this->project, 'Contents.json'))->toContain('splash.svg')
            ->and(assetPath($this->project, 'LaunchImage.imageset').'/splash.png')->not->toBeFile();
    });

    it('keeps the splash core installed for this build', function () {
        installCoreLaunchImage($this->project, 'first build');
        prepareIos($this->project, $this->vectorMode);

        // The app swaps its splash for a bitmap: core rewrites the set, and the
        // vector this plugin wrote is no longer what the set names.
        installCoreLaunchImage($this->project, 'second build');
        unlink($this->project.'/public/splash.svg');

        prepareIos($this->project, $this->vectorMode);

        expect(launchImage($this->project, 'splash.png'))->toBe('second build')
            ->and(assetPath($this->project, 'LaunchImage.imageset').'/splash.svg')->not->toBeFile();
    });

    /**
     * With no artwork of its own the app has no set core would write, so what
     * it is owed is the one the core package ships.
     */
    it('puts the installed default back when core writes nothing', function () {
        prepareIos($this->project, $this->vectorMode);

        expect(launchImage($this->project, 'Contents.json'))->toContain('splash.svg');

        unlink($this->project.'/public/splash.svg');

        prepareIos($this->project, $this->vectorMode);

        expect(launchImage($this->project, 'splash.png'))->toBe('core default')
            ->and(launchImage($this->project, 'Contents.json'))->not->toContain('splash.svg')
            ->and(assetPath($this->project, 'LaunchImage.imageset').'/splash.svg')->not->toBeFile();
    });

    /**
     * A core upgrade that changes the default set has to reach the app, which
     * it only does while the core package is the one place a restore reads.
     */
    it('follows the core package rather than a copy of an earlier build', function () {
        prepareIos($this->project, $this->vectorMode);

        installCoreLaunchImageTemplate($this->project, 'upgraded default');
        unlink($this->project.'/public/splash.svg');

        prepareIos($this->project, $this->vectorMode);

        expect(launchImage($this->project, 'splash.png'))->toBe('upgraded default')
            ->and(iosBuildPath($this->project).'/.enhanced-splash')->not->toBeDirectory();
    });

    it('leaves the set alone when core is not there to restore from', function () {
        prepareIos($this->project, $this->vectorMode);

        exec('rm -rf '.escapeshellarg($this->project.'/vendor'));
        unlink($this->project.'/public/splash.svg');

        prepareIos($this->project, $this->vectorMode);

        expect(launchImage($this->project, 'Contents.json'))->toContain('splash.svg');
    });
});
