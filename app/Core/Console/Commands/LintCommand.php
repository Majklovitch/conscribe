<?php

namespace App\Core\Console\Commands;

use App\Bootstrap;
use App\Core\Console\Command;
use App\Core\Console\Input;
use App\Core\Console\Output;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * php -l over the project, so a parse error is caught before a request finds it.
 */
final class LintCommand extends Command {
    protected string $name = 'lint';
    protected string $description = 'Syntax-checks every PHP file in the project.';
    protected string $help = "Usage: php tty lint [directory ...]\n\nWith no argument it checks app/, bin/, public/ and tests/.";

    private const DEFAULT_PATHS = ['app', 'bin', 'public', 'tests'];

    public function run(Input $input, Output $output): int {
        $root = Bootstrap::root();
        $paths = $input->arguments() !== [] ? $input->arguments() : self::DEFAULT_PATHS;

        $checked = 0;
        $failed = 0;

        foreach ($paths as $path) {
            foreach ($this->phpFilesIn($root . '/' . $path) as $file) {
                $checked++;

                $result = [];
                exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $result, $status);

                if ($status !== 0) {
                    $failed++;
                    $output->error(implode("\n", $result));
                }
            }
        }

        $message = sprintf('%d file(s) checked, %d with syntax errors', $checked, $failed);
        $failed === 0 ? $output->success($message) : $output->error($message);

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return iterable<string>
     */
    private function phpFilesIn(string $directory): iterable {
        if (!is_dir($directory)) {
            return;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            /** @var SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }

            // The console entry point has no extension, the way `artisan` and
            // `bin/console` have none; checking only *.php would skip it.
            if (strtolower($file->getExtension()) === 'php' || $this->isPhpScript($file->getPathname())) {
                yield $file->getPathname();
            }
        }
    }

    private function isPhpScript(string $file): bool {
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return false;
        }

        $firstLine = (string) fgets($handle, 64);
        fclose($handle);

        return str_starts_with($firstLine, '#!') && str_contains($firstLine, 'php');
    }
}
