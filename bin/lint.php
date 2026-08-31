#!/usr/bin/env php
<?php

/**
 * Syntax-checks every PHP file in the project (php -l), so a parse error is
 * caught before it reaches a request. Referenced by `composer lint`.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$root = dirname(__DIR__);
$scan = ['app', 'bin', 'public', 'tests'];

$failed = 0;
$checked = 0;

foreach ($scan as $dir) {
    $path = $root . '/' . $dir;
    if (!is_dir($path)) {
        continue;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        /** @var SplFileInfo $file */
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }

        $checked++;
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname()) . ' 2>&1', $output, $status);

        if ($status !== 0) {
            $failed++;
            fwrite(STDERR, implode("\n", $output) . "\n");
        }

        $output = [];
    }
}

printf("%d file(s) checked, %d with syntax errors\n", $checked, $failed);

exit($failed === 0 ? 0 : 1);
