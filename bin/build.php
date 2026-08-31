#!/usr/bin/env php
<?php

/**
 * Asset build CLI.
 *
 *   php bin/build.php --env=production      compile + minify (what gets committed)
 *   php bin/build.php --env=development     expanded CSS + source map
 *   php bin/build.php --watch               rebuild on change
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';

use App\Bootstrap;
use App\Services\Assets\AssetBuilder;

// Same error handling and logging a request gets, so a failed build is
// recorded in the usual place instead of only on the terminal.
$bootstrap = Bootstrap::console();

$options = getopt('', ['env::', 'watch', 'interval::']);

$env        = $options['env'] ?? ($bootstrap->isDevelopment() ? 'development' : 'production');
$production = $env === 'production';
$watching   = array_key_exists('watch', $options);

$build = static function () use ($production, $watching): bool {
    $started = microtime(true);

    try {
        (new AssetBuilder($production))->build();
    } catch (\Throwable $e) {
        fwrite(STDERR, sprintf("[%s] build failed: %s\n", date('H:i:s'), $e->getMessage()));

        return false;
    }

    printf(
        "[%s] built %s in %d ms\n",
        date('H:i:s'),
        $production ? 'production' : 'development',
        (int) ((microtime(true) - $started) * 1000)
    );

    return true;
};

if (!$watching) {
    exit($build() ? 0 : 1);
}

$interval = max(200, (int) ($options['interval'] ?? 500)) * 1000;
$last = null;

echo "watching assets/ (Ctrl+C to stop)\n";

while (true) {
    $signature = AssetBuilder::sourceSignature();

    if ($signature !== $last) {
        $last = $signature;
        // A failed build only reports; the last good output stays on disk and
        // the watcher keeps running so the next save can fix it.
        $build();
    }

    usleep($interval);
}
