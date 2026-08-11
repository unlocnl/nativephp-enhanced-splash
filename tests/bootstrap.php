<?php

// Resolves standalone checkouts as well as a path-symlinked install inside a host app.
$candidates = [
    __DIR__.'/../vendor/autoload.php',
    __DIR__.'/../../../../vendor/autoload.php',
];

foreach ($candidates as $autoloader) {
    if (file_exists($autoloader)) {
        require $autoloader;

        // The Android patches match core's own sources by exact string, so the
        // tests read those sources rather than a stand-in that can drift.
        define('CORE_ANDROID_SOURCE', dirname($autoloader).'/nativephp/mobile/resources/androidstudio');

        require __DIR__.'/Harness.php';

        return;
    }
}

fwrite(STDERR, "No autoloader found. Run composer install in the package or its host app.\n");
exit(1);
